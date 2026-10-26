<?php

namespace App\Services;

use App\Models\OperationCounter;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Génère un code d'opération lisible, court, imprimable sur un reçu et
 * facile à retaper dans la recherche :
 *
 *   {Préfixe}-{AAMMJJ}-{compteur sur 4 chiffres}
 *   ex. V-261026-0042 = 42e vente du compte le 26/10/2026 (Africa/Abidjan)
 *
 * Préfixe : V = vente, D = dépense, A = apport, R = retrait. Le compteur
 * repart à 0001 chaque jour, par compte ET par préfixe (donc par type) —
 * table operation_counters (user_id, prefix, day, last_value), verrouillée
 * avec lockForUpdate() pour ne jamais produire de doublon, même avec deux
 * caissiers qui encaissent au même instant.
 *
 * Alternatives envisagées et écartées :
 * - Un UUID seul : illisible, impossible à retaper sur un reçu papier.
 * - Un simple compteur global (sans date) : moins lisible sur un reçu (pas
 *   de repère temporel immédiat), et le "retour à zéro chaque jour" demandé
 *   par la spec serait perdu.
 * - AAAAMMJJ (année sur 4 chiffres) : rallonge le code de 2 caractères
 *   sans vrai gain de lisibilité pour un commerce qui imprime/tape ce code
 *   au jour le jour (l'année est examinée au dixième de seconde si jamais
 *   besoin, mais quasi jamais utile en pratique) — AAMMJJ reste le choix
 *   le plus court à usage quotidien.
 */
class OperationCodeGenerator
{
    private const PREFIXES = [
        'sale' => 'V',
        'expense' => 'D',
        'apport' => 'A',
        'retrait' => 'R',
    ];

    public const TIMEZONE = 'Africa/Abidjan';

    /**
     * @throws \InvalidArgumentException si $type n'est pas un type d'opération connu
     */
    public static function next(string $userId, string $type, DateTimeInterface|string $date): string
    {
        $prefix = self::PREFIXES[$type] ?? null;
        if ($prefix === null) {
            throw new \InvalidArgumentException("Type d'opération inconnu : {$type}");
        }

        $reference = $date instanceof DateTimeInterface ? Carbon::instance($date) : Carbon::parse($date);
        $reference = $reference->copy()->setTimezone(self::TIMEZONE);
        $day = $reference->format('Y-m-d');
        $dateCode = $reference->format('ymd');

        $sequence = self::withTransactionIfNeeded(function () use ($userId, $prefix, $day) {
            $counter = OperationCounter::where('user_id', $userId)
                ->where('prefix', $prefix)
                ->where('day', $day)
                ->lockForUpdate()
                ->first();

            if (!$counter) {
                try {
                    $counter = OperationCounter::create([
                        'user_id' => $userId,
                        'prefix' => $prefix,
                        'day' => $day,
                        'last_value' => 0,
                    ]);
                } catch (\Illuminate\Database\QueryException $e) {
                    // Course entre deux générations simultanées pour le même
                    // compte/préfixe/jour : la contrainte unique
                    // (user_id, prefix, day) vient de refuser notre INSERT
                    // parce qu'une autre requête a créé la ligne entre notre
                    // SELECT ci-dessus et notre tentative de création. On la
                    // relit avec verrou — elle existe forcément maintenant.
                    $counter = OperationCounter::where('user_id', $userId)
                        ->where('prefix', $prefix)
                        ->where('day', $day)
                        ->lockForUpdate()
                        ->first();

                    if (!$counter) {
                        throw $e;
                    }
                }
            }

            $counter->increment('last_value');

            return $counter->last_value;
        });

        return sprintf('%s-%s-%04d', $prefix, $dateCode, $sequence);
    }

    /**
     * N'ouvre une NOUVELLE transaction que si on n'est pas déjà dans une —
     * self::next() est presque toujours appelée depuis le hook `creating`
     * de Transaction, lui-même toujours déjà à l'intérieur du
     * DB::transaction() de l'appelant (TransactionController, reprise...).
     * Une transaction imbriquée (savepoint) reste correcte dans ce cas,
     * mais l'éviter quand elle est inutile réduit la profondeur
     * d'imbriguation — plus sobre, et évite d'empiler des savepoints sans
     * raison à chaque transaction créée.
     */
    private static function withTransactionIfNeeded(\Closure $callback): mixed
    {
        if (DB::transactionLevel() > 0) {
            return $callback();
        }

        return DB::transaction($callback);
    }
}
