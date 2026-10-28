<?php

namespace App\Exceptions;

/**
 * Erreur métier sur un panier (POST /api/operations) qui n'est pas un manque
 * de stock — article/variation introuvable ou d'un autre compte, variation_id
 * manquant sur un article variable, lignes dupliquées à prix différents...
 * Toujours un 422, message déjà prêt à afficher.
 */
class BasketValidationException extends \RuntimeException
{
    public function __construct(string $message, public readonly ?int $lineIndex = null)
    {
        parent::__construct($message);
    }
}
