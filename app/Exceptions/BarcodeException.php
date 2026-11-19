<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Code-barre refusé : invalide, déjà utilisé, déjà présent… Rendu en 422
 * (409 pour « déjà présent ») au format habituel de l'API, avec un `code`
 * stable que le front traduit lui-même, et le message traduit dans
 * errors.<champ> pour les formulaires.
 */
class BarcodeException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly string $field,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function toResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
            'errors' => [$this->field => [$this->getMessage()]],
            'details' => $this->details ?: null,
        ], $this->errorCode === 'barcode_already_set' ? 409 : 422);
    }
}
