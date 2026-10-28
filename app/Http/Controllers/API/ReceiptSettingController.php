<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ReceiptSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * Préférences d'affichage du ticket de caisse (chantier ticket-caisse) —
 * même structure que UserSettingController : une ligne par compte, créée
 * avec ses valeurs par défaut au premier GET si elle n'existe pas encore.
 */
class ReceiptSettingController extends Controller
{
    private const DEFAULTS = [
        'width' => '58mm',
        'show_logo' => true,
        'show_address' => true,
        'show_phone' => true,
        'show_tax_id' => true,
        'footer_message' => null,
        'show_qr' => true,
        'show_waelly_mention' => true,
        'auto_print' => false,
    ];

    public function index(): JsonResponse
    {
        $user = Auth::user();

        // Toujours une requête fraîche (jamais `$user->receiptSetting`) :
        // Auth::user() peut être le MÊME objet PHP réutilisé sur plusieurs
        // requêtes (ex. tests avec actingAs()) — un premier accès à la
        // relation la mettrait en cache à `null` sur cet objet, et un GET
        // suivant la croirait encore absente alors qu'elle existe déjà en
        // base, provoquant un doublon (violation de la contrainte unique
        // sur user_id). firstOrCreate() interroge la base à chaque appel.
        $settings = ReceiptSetting::firstOrCreate(['user_id' => $user->id], self::DEFAULTS);

        return response()->json([
            'success' => true,
            'data' => $settings,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'width' => 'nullable|in:58mm,80mm',
            'show_logo' => 'nullable|boolean',
            'show_address' => 'nullable|boolean',
            'show_phone' => 'nullable|boolean',
            'show_tax_id' => 'nullable|boolean',
            'footer_message' => 'nullable|string|max:120',
            'show_qr' => 'nullable|boolean',
            'show_waelly_mention' => 'nullable|boolean',
            'auto_print' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Requête fraîche — voir le commentaire équivalent dans index().
        $existing = ReceiptSetting::where('user_id', $user->id)->first();
        $booleanFields = ['show_logo', 'show_address', 'show_phone', 'show_tax_id', 'show_qr', 'show_waelly_mention', 'auto_print'];

        $payload = ['user_id' => $user->id];
        foreach (self::DEFAULTS as $field => $default) {
            if ($request->has($field)) {
                $payload[$field] = in_array($field, $booleanFields, true) ? $request->boolean($field) : $request->input($field);
            } else {
                $payload[$field] = $existing?->$field ?? $default;
            }
        }

        $settings = ReceiptSetting::updateOrCreate(['user_id' => $user->id], $payload);

        return response()->json([
            'success' => true,
            'message' => 'Réglages du ticket mis à jour',
            'data' => $settings,
        ]);
    }
}
