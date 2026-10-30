<?php

namespace App\Services;

use App\Exceptions\BasketStockException;
use App\Exceptions\BasketValidationException;
use App\Models\Article;
use App\Models\Operation;
use App\Models\Transaction;
use App\Models\Variation;
use Illuminate\Support\Carbon;

/**
 * Étape 2 du chantier "paniers multi-articles" : seul point d'entrée pour
 * créer une Operation (panier) avec ses lignes (Transaction), que ce soit
 * depuis POST /api/operations (panier multi-articles), l'ancien
 * POST /api/transactions (une ligne), ou le paiement d'une facture (une
 * ligne par article facturé) — aucune des trois n'a sa propre copie de la
 * logique de verrouillage/stock/calcul de total ci-dessous.
 *
 * N'est PAS responsable de la détection de rejeu d'idempotence : chaque
 * appelant connaît sa propre convention (table/clé, code HTTP de rejeu) et
 * vérifie l'existence d'une opération AVANT d'appeler create(). create()
 * se contente de stocker l'idempotency_key fournie sur l'Operation créée.
 */
class OperationService
{
    /**
     * @param array{
     *   user_id: string,
     *   created_by_user_id?: ?string,
     *   type: string,
     *   payment_method?: ?string,
     *   occurred_at?: mixed,
     *   client_id?: ?string,
     *   invoice_id?: ?string,
     *   note?: ?string,
     *   amount_received?: mixed,
     *   idempotency_key?: ?string,
     *   lines: array<int, array<string, mixed>>,
     * } $data
     *
     * @throws BasketValidationException|BasketStockException
     */
    public static function create(array $data): Operation
    {
        $userId = $data['user_id'];
        $type = $data['type'];
        $lines = $data['lines'] ?? [];

        if (empty($lines)) {
            throw new BasketValidationException('Au moins une ligne est requise.');
        }

        if (count($lines) > 100) {
            throw new BasketValidationException('100 lignes maximum par opération.');
        }

        $occurredAt = isset($data['occurred_at']) && $data['occurred_at'] !== null
            ? self::parseOccurredAt($data['occurred_at'])
            : Carbon::now(OperationCodeGenerator::TIMEZONE);

        // payment_method obligatoire pour une vente : imposé par le VALIDATEUR
        // de POST /api/operations (le panier multi-articles), pas ici — le
        // service reste appelable par l'ancien POST /api/transactions, qui
        // n'a jamais exigé de moyen de paiement pour une vente simple et doit
        // continuer de fonctionner à l'identique.
        $normalizedLines = $type === 'sale'
            ? self::normalizeSaleLines($lines, $userId)
            : self::normalizeFlatLines($lines);

        if ($type === 'sale') {
            self::lockAndVerifyStock($normalizedLines);
        }

        $totalAmount = round(array_sum(array_column($normalizedLines, 'amount')), 2);

        [$amountReceived, $changeGiven] = self::resolveCashAmounts(
            $type,
            $data['payment_method'] ?? null,
            $data['amount_received'] ?? null,
            $totalAmount
        );

        $operation = Operation::create([
            'user_id' => $userId,
            // Auteur réel (propriétaire ou membre) : par défaut l'utilisateur
            // connecté. Sans ce défaut, POST /api/transactions (vente,
            // dépense, apport/retrait) créait des opérations SANS auteur —
            // invisibles dans « Mes ventes » et le solde du jour d'un caissier.
            'created_by_user_id' => $data['created_by_user_id'] ?? \App\Support\CurrentAccount::actorId(),
            'type' => $type,
            'code' => OperationCodeGenerator::next($userId, $type, $occurredAt),
            'payment_method' => $data['payment_method'] ?? null,
            'total_amount' => $totalAmount,
            'amount_received' => $amountReceived,
            'change_given' => $changeGiven,
            'client_id' => $data['client_id'] ?? null,
            'invoice_id' => $data['invoice_id'] ?? null,
            'note' => $data['note'] ?? null,
            'occurred_at' => $occurredAt,
            'idempotency_key' => $data['idempotency_key'] ?? null,
        ]);

        $createdTransactions = collect();
        $touchedArticleIds = [];
        $touchedVariationIds = [];

        foreach ($normalizedLines as $line) {
            $transaction = Transaction::create([
                'user_id' => $userId,
                'operation_id' => $operation->id,
                'article_id' => $line['article_id'] ?? null,
                'variable_id' => $line['variation_id'] ?? null,
                'invoice_id' => $data['invoice_id'] ?? null,
                'name' => $line['name'],
                'quantity' => $line['quantity'] ?? null,
                'amount' => $line['amount'],
                'sale_price' => $line['unit_price'] ?? null,
                'type' => $type,
                'funding_source' => $line['funding_source'] ?? null,
                'collaborator_id' => $line['collaborator_id'] ?? null,
                'payment_method' => $data['payment_method'] ?? null,
                'note' => $line['note'] ?? ($data['note'] ?? null),
                // Seul l'ancien POST /api/transactions (toujours une seule
                // ligne) fournit ceci — reproduit SA PROPRE détection de
                // rejeu (transactions.idempotency_key), gardée inchangée
                // pour compatibilité. Un panier multi-lignes ne doit JAMAIS
                // le faire : la même clé sur plusieurs lignes violerait la
                // contrainte unique (user_id, idempotency_key) dès la 2e.
                'idempotency_key' => $line['idempotency_key'] ?? null,
            ]);

            $createdTransactions->push($transaction);

            if (!empty($line['article_id'])) {
                $touchedArticleIds[$line['article_id']] = true;
            }
            if (!empty($line['variation_id'])) {
                $touchedVariationIds[$line['variation_id']] = true;
            }
        }

        self::resyncStockAndNotify(array_keys($touchedArticleIds), array_keys($touchedVariationIds));

        return $operation->setRelation('transactions', $createdTransactions);
    }

    /**
     * Supprime (soft delete) l'opération ET toutes ses lignes, puis
     * resynchronise les articles variables concernés. Le stock remonte
     * automatiquement : remaining_quantity est un SUM() sur les transactions
     * non supprimées, jamais un compteur stocké à remettre à jour à la main.
     */
    public static function delete(Operation $operation): void
    {
        $transactions = Transaction::where('operation_id', $operation->id)->get();

        $variationIds = $transactions->pluck('variable_id')->filter()->unique()->values()->all();

        foreach ($transactions as $transaction) {
            $transaction->delete();
        }

        $operation->delete();

        self::resyncVariableArticles($variationIds);
    }

    /**
     * Restaure l'opération ET toutes ses lignes (miroir exact de delete()).
     */
    public static function restore(Operation $operation): void
    {
        $transactions = Transaction::onlyTrashed()->where('operation_id', $operation->id)->get();

        $variationIds = $transactions->pluck('variable_id')->filter()->unique()->values()->all();

        foreach ($transactions as $transaction) {
            $transaction->restore();
        }

        $operation->restore();

        self::resyncVariableArticles($variationIds);
    }

    private static function resyncVariableArticles(array $variationIds): void
    {
        if (empty($variationIds)) {
            return;
        }

        $articleIds = Variation::withTrashed()
            ->whereIn('id', $variationIds)
            ->pluck('article_id')
            ->unique();

        foreach ($articleIds as $articleId) {
            $article = Article::find($articleId);
            if ($article) {
                ArticleStockService::syncVariableTotals($article);
            }
        }
    }

    private static function parseOccurredAt(mixed $value): Carbon
    {
        return $value instanceof \DateTimeInterface
            ? Carbon::instance($value)
            : Carbon::parse((string) $value, OperationCodeGenerator::TIMEZONE);
    }

    /**
     * Lignes non liées au stock (dépense/apport/retrait) : pas de fusion, pas
     * de verrouillage — chaque ligne devient une Transaction telle quelle.
     */
    private static function normalizeFlatLines(array $lines): array
    {
        $normalized = [];

        foreach (array_values($lines) as $i => $line) {
            $amount = (float) ($line['amount'] ?? 0);
            if ($amount <= 0) {
                throw new BasketValidationException(sprintf('Montant invalide (ligne %d).', $i + 1));
            }

            $normalized[] = [
                'index' => $i,
                'article_id' => null,
                'variation_id' => null,
                'quantity' => null,
                'unit_price' => null,
                'amount' => round($amount, 2),
                'name' => (string) ($line['name'] ?? ''),
                'funding_source' => $line['funding_source'] ?? null,
                'collaborator_id' => $line['collaborator_id'] ?? null,
                'note' => $line['note'] ?? null,
                'idempotency_key' => $line['idempotency_key'] ?? null,
            ];
        }

        return $normalized;
    }

    /**
     * Lignes de vente : vérifie que chaque article/variation existe et
     * appartient au compte, qu'un article variable a bien une variation
     * (et un article simple n'en a pas), PUIS fusionne les lignes qui
     * partagent le même (article_id, variation_id) — quantités additionnées,
     * prix unitaire recalculé comme une MOYENNE PONDÉRÉE du montant total
     * (amount = somme exacte des lignes d'origine, jamais recalculée depuis
     * le prix moyen arrondi) : un panier scanné deux fois le même article au
     * même prix se fusionne proprement, et même deux prix négociés
     * différents sur le même article ne perdent pas un centime au total —
     * seul le prix unitaire affiché sur la ligne fusionnée devient une
     * moyenne, jamais le total facturé.
     */
    private static function normalizeSaleLines(array $lines, string $userId): array
    {
        $articleIds = collect($lines)->pluck('article_id')->filter()->unique()->values();
        $articles = Article::where('user_id', $userId)->whereIn('id', $articleIds)->get()->keyBy('id');

        $variationIds = collect($lines)->pluck('variation_id')->filter()->unique()->values();
        $variations = $variationIds->isEmpty()
            ? collect()
            : Variation::whereIn('id', $variationIds)->get()->keyBy('id');

        $groups = [];

        foreach (array_values($lines) as $i => $line) {
            $articleId = $line['article_id'] ?? null;
            $variationId = $line['variation_id'] ?? null;
            $quantity = (int) ($line['quantity'] ?? 0);
            $unitPrice = (float) ($line['unit_price'] ?? 0);

            if (!$articleId) {
                throw new BasketValidationException(sprintf('article_id est obligatoire pour une vente (ligne %d).', $i + 1));
            }

            $article = $articles->get($articleId);
            if (!$article) {
                throw new BasketValidationException(sprintf("Article introuvable ou n'appartenant pas à ce compte (ligne %d).", $i + 1));
            }

            if ($quantity < 1) {
                throw new BasketValidationException(sprintf('Quantité invalide pour %s (ligne %d).', $article->name, $i + 1));
            }

            $variation = null;
            if ($article->type === 'variable') {
                if (!$variationId) {
                    throw new BasketValidationException(sprintf('Variation obligatoire pour l\'article variable "%s" (ligne %d).', $article->name, $i + 1));
                }
                $variation = $variations->get($variationId);
                if (!$variation || $variation->article_id !== $article->id) {
                    throw new BasketValidationException(sprintf('Variation introuvable ou n\'appartenant pas à "%s" (ligne %d).', $article->name, $i + 1));
                }
            } elseif ($variationId) {
                throw new BasketValidationException(sprintf('L\'article "%s" est simple : pas de variation_id (ligne %d).', $article->name, $i + 1));
            }

            $key = $articleId . '|' . ($variationId ?? '');
            // Un appelant qui connaît déjà le montant exact d'une ligne (ex:
            // le paiement d'une facture, où total_line peut différer de
            // quantity*unit_price à cause d'une remise ligne) le fournit
            // directement ; sinon, calculé normalement.
            $amount = isset($line['amount']) ? round((float) $line['amount'], 2) : round($quantity * $unitPrice, 2);
            // Un appelant (ex: l'ancien POST /api/transactions, qui doit
            // continuer à afficher "Vente de N X Y") peut fournir SON PROPRE
            // nom de ligne — sinon, nom par défaut du panier multi-articles.
            $name = $line['name'] ?? ($variation ? "{$article->name} · {$variation->name}" : $article->name);

            if (isset($groups[$key])) {
                $groups[$key]['quantity'] += $quantity;
                $groups[$key]['amount'] = round($groups[$key]['amount'] + $amount, 2);
                $groups[$key]['merged_count']++;
            } else {
                $groups[$key] = [
                    'index' => $i,
                    'article_id' => $articleId,
                    'variation_id' => $variationId,
                    'quantity' => $quantity,
                    'amount' => $amount,
                    'name' => $name,
                    // Conservé TEL QUEL dans le cas usuel (une seule ligne
                    // pour ce couple article/variation) — seule une VRAIE
                    // fusion recalcule une moyenne pondérée ci-dessous, pour
                    // ne jamais altérer le prix unitaire d'une ligne isolée
                    // (ex: sale_price affiché d'une facture avec remise).
                    'unit_price' => $unitPrice,
                    'merged_count' => 1,
                    'idempotency_key' => $line['idempotency_key'] ?? null,
                ];
            }
        }

        foreach ($groups as &$group) {
            if ($group['merged_count'] > 1) {
                $group['unit_price'] = $group['quantity'] > 0 ? round($group['amount'] / $group['quantity'], 2) : 0.0;
            }
            unset($group['merged_count']);
        }
        unset($group);

        return array_values($groups);
    }

    /**
     * Verrouille (lockForUpdate) toutes les variations puis tous les
     * articles simples concernés, chaque ensemble TRIÉ PAR ID — ordre
     * déterministe identique quel que soit l'ordre des lignes du panier,
     * condition nécessaire pour que deux paniers concurrents touchant les
     * mêmes articles ne puissent jamais s'attendre en cercle (deadlock).
     * Puis vérifie, APRÈS verrouillage, que le stock restant de chaque
     * cible couvre la quantité totale demandée (déjà sommée par
     * normalizeSaleLines() pour deux lignes sur la même variation).
     *
     * @throws BasketStockException
     */
    private static function lockAndVerifyStock(array $normalizedLines): void
    {
        $variationLines = array_values(array_filter($normalizedLines, fn ($l) => !empty($l['variation_id'])));
        $simpleLines = array_values(array_filter($normalizedLines, fn ($l) => empty($l['variation_id']) && !empty($l['article_id'])));

        $variationIds = collect($variationLines)->pluck('variation_id')->unique()->sort()->values();
        $lockedVariations = $variationIds->isEmpty()
            ? collect()
            : Variation::whereIn('id', $variationIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $articleIds = collect($simpleLines)->pluck('article_id')->unique()->sort()->values();
        $lockedArticles = $articleIds->isEmpty()
            ? collect()
            : Article::whereIn('id', $articleIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $errors = [];

        foreach ($variationLines as $line) {
            $variation = $lockedVariations->get($line['variation_id']);
            $sold = (int) Transaction::where('variable_id', $line['variation_id'])->where('type', 'sale')->sum('quantity');
            $available = (int) $variation->quantity - $sold;

            if ($line['quantity'] > $available) {
                $errors[] = [
                    'index' => $line['index'],
                    'name' => $line['name'],
                    'available' => $available,
                    'requested' => $line['quantity'],
                ];
            }
        }

        foreach ($simpleLines as $line) {
            $article = $lockedArticles->get($line['article_id']);
            $sold = (int) Transaction::where('article_id', $line['article_id'])->where('type', 'sale')->sum('quantity');
            $available = (int) $article->quantity - $sold;

            if ($line['quantity'] > $available) {
                $errors[] = [
                    'index' => $line['index'],
                    'name' => $line['name'],
                    'available' => $available,
                    'requested' => $line['quantity'],
                ];
            }
        }

        if (!empty($errors)) {
            throw new BasketStockException($errors);
        }
    }

    /**
     * Espèces sans amount_received -> payé au montant exact (change_given=0).
     * Espèces avec amount_received -> doit couvrir le total, monnaie
     * calculée SERVEUR (jamais confiée au client). Tout autre moyen de
     * paiement, ou tout type != sale : les deux colonnes restent NULL.
     *
     * @return array{0: ?float, 1: ?float}
     */
    private static function resolveCashAmounts(string $type, ?string $paymentMethod, mixed $amountReceivedInput, float $total): array
    {
        if ($type !== 'sale' || $paymentMethod !== 'especes') {
            return [null, null];
        }

        if ($amountReceivedInput === null) {
            return [$total, 0.0];
        }

        $received = round((float) $amountReceivedInput, 2);

        if ($received < $total) {
            throw new BasketValidationException("Montant reçu ({$received}) inférieur au total ({$total}).");
        }

        return [$received, round($received - $total, 2)];
    }

    private static function resyncStockAndNotify(array $articleIds, array $variationIds): void
    {
        foreach ($variationIds as $variationId) {
            $variation = Variation::find($variationId);
            if (!$variation) {
                continue;
            }

            $variation->load('article');
            if ($variation->article) {
                ArticleStockService::syncVariableTotals($variation->article);
            }

            $variation->refresh();
            $variation->load('article.user.settings');
            $variation->loadSum(['transactions' => function ($q) {
                $q->where('type', 'sale');
            }], 'quantity');

            NotificationService::checkLowStockVariation($variation);
        }

        foreach ($articleIds as $articleId) {
            $article = Article::find($articleId);
            if (!$article || $article->type !== 'simple') {
                continue;
            }

            $article->load('user.settings');
            $article->loadSum(['transactions' => function ($q) {
                $q->where('type', 'sale');
            }], 'quantity');

            NotificationService::checkLowStock($article);
        }
    }
}
