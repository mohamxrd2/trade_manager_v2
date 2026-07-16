<?php

namespace App\Exceptions;

class InsufficientStockException extends \RuntimeException
{
    /**
     * @param array<int, string> $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Stock insuffisant : ' . implode(' ', $errors));
    }
}
