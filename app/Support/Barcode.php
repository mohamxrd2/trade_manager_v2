<?php

namespace App\Support;

/**
 * Codes-barres acceptés : EAN-13, EAN-8, UPC-A, UPC-E (clé de contrôle
 * vérifiée) ou Code 128 (lettres, chiffres, - . / — 4 à 32 caractères).
 * Un code tout en chiffres de 8, 12 ou 13 caractères est forcément lu comme
 * un EAN/UPC : une clé fausse est une faute de frappe, pas un Code 128.
 */
class Barcode
{
    public const EAN13 = 'ean13';
    public const EAN8 = 'ean8';
    public const UPCA = 'upca';
    public const UPCE = 'upce';
    public const CODE128 = 'code128';

    /** Erreurs de validate() (clés de lang/{fr,en}/barcode.php). */
    public const ERROR_EMPTY = 'empty';
    public const ERROR_CHECK_DIGIT = 'check_digit';
    public const ERROR_FORMAT = 'format';

    /** Supprime tous les espaces (début, fin, et au milieu : « 3 017620 422003 »). */
    public static function normalize(?string $code): string
    {
        return preg_replace('/\s+/u', '', (string) $code) ?? '';
    }

    /**
     * Format du code déjà normalisé, ou erreur.
     *
     * @return array{format: ?string, error: ?string}
     */
    public static function validate(string $code): array
    {
        if ($code === '') {
            return ['format' => null, 'error' => self::ERROR_EMPTY];
        }

        if (ctype_digit($code) && in_array(strlen($code), [8, 12, 13], true)) {
            $format = match (strlen($code)) {
                13 => self::hasValidCheckDigit($code) ? self::EAN13 : null,
                12 => self::hasValidCheckDigit($code) ? self::UPCA : null,
                8 => self::hasValidCheckDigit($code) ? self::EAN8 : (self::isValidUpcE($code) ? self::UPCE : null),
            };

            return $format
                ? ['format' => $format, 'error' => null]
                : ['format' => null, 'error' => self::ERROR_CHECK_DIGIT];
        }

        if (preg_match('/^[A-Za-z0-9.\/-]{4,32}$/', $code)) {
            return ['format' => self::CODE128, 'error' => null];
        }

        return ['format' => null, 'error' => self::ERROR_FORMAT];
    }

    /** Clé de contrôle GS1 (EAN-13, EAN-8, UPC-A) des chiffres SANS la clé. */
    public static function checkDigit(string $digits): int
    {
        $sum = 0;
        $weight = 3;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $sum += (int) $digits[$i] * $weight;
            $weight = $weight === 3 ? 1 : 3;
        }

        return (10 - $sum % 10) % 10;
    }

    public static function hasValidCheckDigit(string $code): bool
    {
        return ctype_digit($code)
            && strlen($code) >= 8
            && self::checkDigit(substr($code, 0, -1)) === (int) substr($code, -1);
    }

    /** UPC-E (8 chiffres, système 0 ou 1) : la clé se calcule sur le UPC-A développé. */
    public static function isValidUpcE(string $code): bool
    {
        if (!ctype_digit($code) || strlen($code) !== 8 || !in_array($code[0], ['0', '1'], true)) {
            return false;
        }

        $upcA = self::expandUpcE($code);

        return $upcA !== null && self::hasValidCheckDigit($upcA);
    }

    public static function expandUpcE(string $code): ?string
    {
        if (strlen($code) !== 8) {
            return null;
        }

        [$system, $d, $check] = [$code[0], substr($code, 1, 6), $code[7]];
        $body = match ($d[5]) {
            '0', '1', '2' => $d[0] . $d[1] . $d[5] . '0000' . $d[2] . $d[3] . $d[4],
            '3' => $d[0] . $d[1] . $d[2] . '00000' . $d[3] . $d[4],
            '4' => $d[0] . $d[1] . $d[2] . $d[3] . '00000' . $d[4],
            default => $d[0] . $d[1] . $d[2] . $d[3] . $d[4] . '0000' . $d[5],
        };

        return $system . $body . $check;
    }

    /** EAN-13 complet (avec sa clé) à partir de 12 chiffres. */
    public static function ean13(string $twelveDigits): string
    {
        return $twelveDigits . self::checkDigit($twelveDigits);
    }
}
