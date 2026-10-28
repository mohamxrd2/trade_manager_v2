<?php

namespace App\Support;

/**
 * Dérivation de codes courts (code entreprise, préfixe d'identifiant de
 * connexion membre) à partir d'un texte libre — majuscules, sans accents,
 * lettres/chiffres uniquement. Pure (aucune dépendance DB) : l'unicité
 * (ajout d'un suffixe numérique en cas de doublon) est gérée par l'appelant,
 * qui seul connaît la table/colonne à vérifier.
 */
class AccountCode
{
    public static function slug(string $text): string
    {
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT', $text);
        $ascii = $transliterated !== false ? $transliterated : $text;
        $upper = strtoupper($ascii);

        return preg_replace('/[^A-Z0-9]/', '', $upper) ?? '';
    }

    /**
     * Essaie `$base`, puis `$base2`, `$base3`, ... jusqu'à ce que
     * `$isTaken` renvoie false. Évite une boucle infinie avec un plafond
     * large (1000) — un compte ayant 1000 collisions sur le même préfixe
     * est en pratique impossible.
     */
    public static function withUniqueSuffix(string $base, callable $isTaken): string
    {
        if (!$isTaken($base)) {
            return $base;
        }

        for ($suffix = 2; $suffix <= 1000; $suffix++) {
            $candidate = $base . $suffix;
            if (!$isTaken($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException("Impossible de générer un code unique pour « {$base} » après 1000 tentatives.");
    }
}
