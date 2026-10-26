<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Interprète le texte libre `q` de GET /api/transactions (voir
 * TransactionController::index()) — pure, sans accès réseau/DB, pour rester
 * testable indépendamment de la requête HTTP.
 *
 * Priorité (un seul type reconnu à la fois, sauf montant qui s'ajoute au
 * texte) :
 *   1. Date (jour précis ou mois entier)
 *   2. Montant exact (FCFA, sans décimales) — complété par la recherche texte
 *   3. Texte libre (tous les mots doivent correspondre, voir
 *      TransactionController::applyTextSearch())
 *
 * N'interprète PAS de "code de transaction" : `transactions` n'a pas de
 * colonne `code`/`reference` (vérifié en base, voir le diagnostic de
 * livraison) — seul l'UUID `id` existe, jamais saisi par un utilisateur.
 */
class TransactionSearchParser
{
    private const MONTHS = [
        // Français (complet et abrégé) → numéro du mois.
        'janvier' => 1, 'janv' => 1, 'jan' => 1,
        'février' => 2, 'fevrier' => 2, 'févr' => 2, 'fevr' => 2, 'fev' => 2, 'feb' => 2,
        'mars' => 3, 'mar' => 3,
        'avril' => 4, 'avr' => 4, 'apr' => 4,
        'mai' => 5, 'may' => 5,
        'juin' => 6, 'jun' => 6,
        'juillet' => 7, 'juil' => 7, 'jul' => 7,
        'août' => 8, 'aout' => 8, 'aug' => 8,
        'septembre' => 9, 'sept' => 9, 'sep' => 9,
        'octobre' => 10, 'oct' => 10,
        'novembre' => 11, 'nov' => 11,
        'décembre' => 12, 'decembre' => 12, 'déc' => 12, 'dec' => 12,
        // Anglais complet (les abréviations ci-dessus suffisent déjà pour "Oct", "Sep"...).
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'june' => 6,
        'july' => 7, 'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
    ];

    /**
     * @return array{kind: 'day'|'month'|'amount'|'text', ...}
     *   - day: ['kind' => 'day', 'date' => Carbon] (fuseau Africa/Abidjan, début de journée)
     *   - month: ['kind' => 'month', 'start' => Carbon, 'end' => Carbon]
     *   - amount: ['kind' => 'amount', 'amount' => float, 'text' => string] (texte = q original, pour cumuler les deux recherches)
     *   - text: ['kind' => 'text', 'words' => string[]]
     */
    public static function parse(string $q, string $timezone = 'Africa/Abidjan'): array
    {
        $trimmed = trim($q);

        $day = self::parseDay($trimmed, $timezone);
        if ($day !== null) {
            return ['kind' => 'day', 'date' => $day];
        }

        $month = self::parseMonth($trimmed, $timezone);
        if ($month !== null) {
            return ['kind' => 'month', 'start' => $month[0], 'end' => $month[1]];
        }

        $amount = self::parseAmount($trimmed);
        if ($amount !== null) {
            return ['kind' => 'amount', 'amount' => $amount, 'text' => $trimmed];
        }

        $words = preg_split('/\s+/', $trimmed, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return ['kind' => 'text', 'words' => $words];
    }

    /** "24/10/2026", "24/10/26", "24/10" (année en cours), "24-10-2026", "2026-10-24". */
    private static function parseDay(string $q, string $timezone): ?Carbon
    {
        $now = Carbon::now($timezone);

        // ISO : "2026-10-24"
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $q, $m)) {
            return self::safeDate((int) $m[1], (int) $m[2], (int) $m[3], $timezone);
        }

        // "24/10/2026", "24-10-2026", "24/10/26"
        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})$/', $q, $m)) {
            $year = (int) $m[3];
            if ($year < 100) {
                $year += 2000;
            }
            return self::safeDate($year, (int) $m[2], (int) $m[1], $timezone);
        }

        // "24/10", "24-10" — jour/mois, année en cours.
        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})$/', $q, $m)) {
            return self::safeDate($now->year, (int) $m[2], (int) $m[1], $timezone);
        }

        // "24 oct", "24 octobre 2026", "24 oct." — jour PUIS nom de mois (ordre français).
        if (preg_match('/^(\d{1,2})\s+([a-zà-ÿ]+)\.?(?:\s+(\d{4}))?$/ui', $q, $m)) {
            $monthNum = self::MONTHS[self::normalizeWord($m[2])] ?? null;
            if ($monthNum !== null) {
                $year = isset($m[3]) ? (int) $m[3] : $now->year;
                return self::safeDate($year, $monthNum, (int) $m[1], $timezone);
            }
        }

        // "Oct 24", "October 24, 2026", "Oct 24 2026" — nom de mois PUIS jour (ordre anglais).
        if (preg_match('/^([a-zà-ÿ]+)\.?\s+(\d{1,2}),?(?:\s+(\d{4}))?$/ui', $q, $m)) {
            $monthNum = self::MONTHS[self::normalizeWord($m[1])] ?? null;
            if ($monthNum !== null) {
                $year = isset($m[3]) ? (int) $m[3] : $now->year;
                return self::safeDate($year, $monthNum, (int) $m[2], $timezone);
            }
        }

        return null;
    }

    /** "octobre 2026", "10/2026" — mois entier. */
    private static function parseMonth(string $q, string $timezone): ?array
    {
        // "10/2026", "10-2026"
        if (preg_match('/^(\d{1,2})[\/\-](\d{4})$/', $q, $m)) {
            $month = (int) $m[1];
            $year = (int) $m[2];
            if ($month >= 1 && $month <= 12) {
                return self::monthRange($year, $month, $timezone);
            }
            return null;
        }

        // "octobre 2026", "October 2026"
        if (preg_match('/^([a-zà-ÿ]+)\.?\s+(\d{4})$/ui', $q, $m)) {
            $monthNum = self::MONTHS[self::normalizeWord($m[1])] ?? null;
            if ($monthNum !== null) {
                return self::monthRange((int) $m[2], $monthNum, $timezone);
            }
        }

        return null;
    }

    private static function monthRange(int $year, int $month, string $timezone): ?array
    {
        $start = self::safeDate($year, $month, 1, $timezone);
        if ($start === null) {
            return null;
        }
        return [$start, $start->copy()->endOfMonth()];
    }

    /**
     * "25000", "25 000", "25.000" — un nombre "rond" (pas de décimales, les
     * espaces/points ne sont que des séparateurs de milliers en usage FCFA).
     * Un seul chiffre ou deux ("5", "12") n'est volontairement PAS traité
     * comme montant : ça ressemble trop à un fragment de texte/date pour
     * être une recherche de montant utile.
     */
    private static function parseAmount(string $q): ?float
    {
        if (!preg_match('/^\d{1,3}([\s.]?\d{3})*$/', $q)) {
            return null;
        }
        $digits = preg_replace('/[\s.]/', '', $q);
        if ($digits === '' || strlen($digits) < 3) {
            return null;
        }
        return (float) $digits;
    }

    private static function safeDate(int $year, int $month, int $day, string $timezone): ?Carbon
    {
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31 || $year < 2000 || $year > 2100) {
            return null;
        }
        if (!checkdate($month, $day, $year)) {
            return null;
        }
        return Carbon::create($year, $month, $day, 0, 0, 0, $timezone);
    }

    private static function normalizeWord(string $word): string
    {
        return rtrim(mb_strtolower(self::stripAccents($word)), '.');
    }

    /**
     * Retire les diacritiques latins courants (accents français), CASSE
     * CONSERVÉE — exactement le comportement de l'extension PostgreSQL
     * `unaccent()` (vérifié : `unaccent('Doré')` → `'Dore'`, le D majuscule
     * reste). Utilisée ICI pour reconnaître les noms de mois accentués, et
     * réutilisée telle quelle par TransactionController pour émuler
     * `unaccent()` sur SQLite (tests) — une seule table de correspondance,
     * jamais dupliquée.
     */
    public static function stripAccents(string $value): string
    {
        return strtr($value, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'À' => 'A', 'Â' => 'A', 'Ä' => 'A',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'î' => 'i', 'ï' => 'i', 'Î' => 'I', 'Ï' => 'I',
            'ô' => 'o', 'ö' => 'o', 'Ô' => 'O', 'Ö' => 'O',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'ç' => 'c', 'Ç' => 'C',
            'œ' => 'oe', 'Œ' => 'OE',
        ]);
    }
}
