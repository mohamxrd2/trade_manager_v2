<?php

namespace App\Exceptions;

/**
 * Stock insuffisant sur une ou plusieurs lignes d'un panier (POST
 * /api/operations) — distincte de InsufficientStockException (facture) : ici
 * chaque ligne insuffisante est rapportée précisément (index, nom, restant,
 * demandé), pas juste un message texte, pour que le frontend puisse pointer
 * vers la ligne fautive.
 */
class BasketStockException extends \RuntimeException
{
    /**
     * @param array<int, array{index: int, name: string, available: int, requested: int}> $lines
     */
    public function __construct(public readonly array $lines)
    {
        parent::__construct('Stock insuffisant');
    }
}
