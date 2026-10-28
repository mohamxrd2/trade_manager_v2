<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Compteur journalier interne (user_id, prefix, day) → last_value — jamais
 * exposé par l'API. Voir App\Services\OperationCodeGenerator, seul endroit
 * qui lit/écrit cette table.
 */
class OperationCounter extends Model
{
    protected $fillable = [
        'user_id',
        'prefix',
        'day',
        'last_value',
    ];

    protected $casts = [
        // 'date:Y-m-d' (pas juste 'date') : un cast 'date' nu SÉRIALISE
        // quand même en "AAAA-MM-JJ 00:00:00" lors de l'écriture, qui ne
        // correspond alors plus à la chaîne "AAAA-MM-JJ" nue utilisée par
        // OperationCodeGenerator::next() pour RETROUVER la ligne du jour —
        // la recherche échouait silencieusement, provoquant une tentative
        // de doublon (violation de la contrainte unique) à chaque second
        // appel pour le même jour/préfixe. Avec ce format explicite, stockage
        // et comparaison utilisent la même chaîne "AAAA-MM-JJ".
        'day' => 'date:Y-m-d',
        'last_value' => 'integer',
    ];
}
