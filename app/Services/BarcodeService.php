<?php

namespace App\Services;

use App\Exceptions\BarcodeException;
use App\Models\Article;
use App\Models\User;
use App\Models\Variation;
use App\Support\Barcode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Codes-barres d'un compte : un code par article simple, un par variation ;
 * un même code ne sert qu'une fois dans un compte (article OU variation),
 * deux comptes différents peuvent avoir le même.
 *
 * Unicité : variations n'a pas de colonne de compte, aucun index ne couvre
 * « article ou variation du même compte ». Toute écriture d'un code passe
 * donc par lockAccount() (ligne users du compte verrouillée jusqu'à la fin
 * de la transaction), puis vérifie, puis écrit : deux enregistrements
 * simultanés du même compte s'attendent au lieu de se croiser. L'index
 * unique partiel sur articles (user_id, barcode) reste un filet de sécurité.
 */
class BarcodeService
{
    /** Codes internes : EAN-13 commençant par 2 (plage réservée à l'usage interne des magasins). */
    public const INTERNAL_PREFIX = '2';

    private const MAX_ATTEMPTS = 50;

    /** À appeler dans DB::transaction(), avant de vérifier puis d'écrire un code. */
    public function lockAccount(string $accountId): void
    {
        User::whereKey($accountId)->lockForUpdate()->first();
    }

    /**
     * Code saisi → code nettoyé et validé (null si vide : pas de code).
     *
     * @throws BarcodeException
     */
    public function clean(?string $raw, string $field = 'barcode'): ?string
    {
        if ($raw === null) {
            return null;
        }
        $code = Barcode::normalize($raw);
        if ($code === '') {
            return null;
        }

        $error = Barcode::validate($code)['error'];
        if ($error !== null) {
            throw new BarcodeException($field, 'barcode_' . $error, __('barcode.' . $error));
        }

        return $code;
    }

    /**
     * Refuse un code déjà porté par un autre produit du compte, en le nommant.
     *
     * @throws BarcodeException
     */
    public function assertAvailable(string $accountId, string $code, ?string $exceptArticleId = null, ?string $exceptVariationId = null, string $field = 'barcode'): void
    {
        $owner = $this->find($accountId, $code);
        if (!$owner) {
            return;
        }
        if ($owner['variation'] === null && $owner['article']->id === $exceptArticleId) {
            return;
        }
        if ($owner['variation'] !== null && $owner['variation']->id === $exceptVariationId) {
            return;
        }

        $product = $this->displayName($owner['article'], $owner['variation']);
        throw new BarcodeException($field, 'barcode_taken', __('barcode.taken', ['product' => $product]), ['product' => $product]);
    }

    /**
     * Le produit (et la variation) du compte qui porte ce code.
     *
     * @return array{article: Article, variation: ?Variation}|null
     */
    public function find(string $accountId, string $code): ?array
    {
        foreach (self::equivalents($code) as $candidate) {
            $article = Article::where('user_id', $accountId)->where('barcode', $candidate)->first();
            if ($article) {
                return ['article' => $article, 'variation' => null];
            }

            // Variations archivées exclues (SoftDeletes) : leur code peut resservir.
            $variation = Variation::where('barcode', $candidate)
                ->whereHas('article', fn ($query) => $query->where('user_id', $accountId))
                ->with('article')
                ->first();
            if ($variation) {
                return ['article' => $variation->article, 'variation' => $variation];
            }
        }

        return null;
    }

    /**
     * Un UPC-A (12 chiffres) est un EAN-13 commençant par 0 : selon le
     * lecteur, le même produit arrive avec 12 ou 13 chiffres. Les deux
     * écritures désignent le même code.
     *
     * @return array<int, string>
     */
    public static function equivalents(string $code): array
    {
        if (ctype_digit($code) && strlen($code) === 13 && $code[0] === '0') {
            return [$code, substr($code, 1)];
        }
        if (ctype_digit($code) && strlen($code) === 12) {
            return [$code, '0' . $code];
        }

        return [$code];
    }

    public function displayName(Article $article, ?Variation $variation): string
    {
        return $variation ? "{$article->name} — {$variation->name}" : $article->name;
    }

    /**
     * Prochain EAN-13 interne libre du compte : 2 + numéro sur 11 chiffres
     * (suite croissante par compte) + clé de contrôle. Appeler sous lockAccount().
     *
     * @param array<string, true> $reserved codes déjà attribués dans la même opération
     * @throws BarcodeException
     */
    public function nextInternalCode(string $accountId, array $reserved = []): string
    {
        $counter = $this->highestInternalCounter($accountId);
        foreach (array_keys($reserved) as $code) {
            $counter = max($counter, $this->internalCounter((string) $code) ?? 0);
        }

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $counter++;
            if ($counter > 99_999_999_999) {
                break;
            }
            $code = Barcode::ean13(self::INTERNAL_PREFIX . str_pad((string) $counter, 11, '0', STR_PAD_LEFT));
            // Collision possible avec un code saisi à la main (balance, ancien système…) : on passe au suivant.
            if (!isset($reserved[$code]) && $this->find($accountId, $code) === null) {
                return $code;
            }
        }

        throw new BarcodeException('barcode', 'barcode_exhausted', __('barcode.exhausted'));
    }

    /**
     * Génère le code d'un article simple ou d'une variation qui n'en a pas.
     * Ne remplace jamais un code existant.
     *
     * @throws BarcodeException
     */
    public function generateFor(string $accountId, Article|Variation $item): string
    {
        return DB::transaction(function () use ($accountId, $item) {
            $this->lockAccount($accountId);
            $item->refresh();

            if ($item->barcode !== null) {
                throw new BarcodeException('barcode', 'barcode_already_set', __('barcode.already_set'), ['barcode' => $item->barcode]);
            }
            if ($item instanceof Article && $item->type === 'variable') {
                throw new BarcodeException('barcode', 'barcode_variable_article', __('barcode.variable_article'));
            }

            $code = $this->nextInternalCode($accountId);
            $item->forceFill(['barcode' => $code])->save();

            return $code;
        });
    }

    /**
     * Ce qui recevrait un code : articles simples et variations (non
     * archivées) du compte sans code — tous, ou ceux des articles choisis
     * (un article à variantes choisi = toutes ses variations) et des
     * variations choisies.
     *
     * @param array<int, string>|null $articleIds
     * @param array<int, string>|null $variationIds
     * @return Collection<int, Article|Variation>
     */
    public function missing(string $accountId, ?array $articleIds = null, ?array $variationIds = null): Collection
    {
        $all = $articleIds === null && $variationIds === null;

        $articles = Article::where('user_id', $accountId)
            ->where('type', 'simple')
            ->whereNull('barcode')
            ->when(!$all, fn ($query) => $query->whereIn('id', $articleIds ?? []))
            ->orderBy('created_at')
            ->get();

        $variations = Variation::whereNull('barcode')
            ->whereHas('article', fn ($query) => $query->where('user_id', $accountId)->where('type', 'variable'))
            ->when(!$all, fn ($query) => $query->where(function ($query) use ($articleIds, $variationIds) {
                $query->whereIn('article_id', $articleIds ?? [])->orWhereIn('id', $variationIds ?? []);
            }))
            ->orderBy('created_at')
            ->get();

        return $articles->concat($variations)->values();
    }

    /**
     * Génère les codes manquants (voir missing()). Renvoie le nombre créé.
     *
     * @throws BarcodeException
     */
    public function generateMissing(string $accountId, ?array $articleIds = null, ?array $variationIds = null): int
    {
        return DB::transaction(function () use ($accountId, $articleIds, $variationIds) {
            $this->lockAccount($accountId);

            $reserved = [];
            foreach ($this->missing($accountId, $articleIds, $variationIds) as $item) {
                $code = $this->nextInternalCode($accountId, $reserved);
                $reserved[$code] = true;
                $item->forceFill(['barcode' => $code])->save();
            }

            return count($reserved);
        });
    }

    private function highestInternalCounter(string $accountId): int
    {
        $isInternal = fn ($query) => $query->where('barcode', 'like', self::INTERNAL_PREFIX . '%')->whereRaw('length(barcode) = 13');

        // Même longueur, que des chiffres : le plus grand texte est le plus grand nombre.
        $highest = collect([
            Article::where('user_id', $accountId)->where($isInternal)->max('barcode'),
            Variation::withTrashed()->where($isInternal)
                ->whereHas('article', fn ($query) => $query->where('user_id', $accountId))
                ->max('barcode'),
        ])->filter()->max();

        return $highest ? ($this->internalCounter($highest) ?? 0) : 0;
    }

    private function internalCounter(string $code): ?int
    {
        return ctype_digit($code) && strlen($code) === 13 && str_starts_with($code, self::INTERNAL_PREFIX)
            ? (int) substr($code, 1, 11)
            : null;
    }
}
