<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\UserSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class UserSettingController extends Controller
{
    /**
     * Get user settings
     */
    public function index(): JsonResponse
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }

            $settings = $user->settings;

            // Si pas de paramètres, créer avec les valeurs par défaut
            if (!$settings) {
                $settings = UserSetting::create([
                    'user_id' => $user->id,
                    'currency' => 'FCFA',
                    'low_stock_threshold' => 80,
                    'language' => 'fr',
                    'notify_stock_low' => true,
                    'notify_invoice_paid' => true,
                    'notify_invoice_overdue' => true,
                    'notify_team_joined' => true,
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => $settings
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des paramètres',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update user settings
     */
    public function update(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }

            // Validation
            $validator = Validator::make($request->all(), [
                'currency' => 'nullable|string|in:FCFA,EUR,USD,XOF',
                'low_stock_threshold' => 'nullable|integer|min:0|max:100',
                'language' => 'nullable|string|in:fr,en',
                'notify_stock_low' => 'nullable|boolean',
                'notify_invoice_paid' => 'nullable|boolean',
                'notify_invoice_overdue' => 'nullable|boolean',
                'notify_team_joined' => 'nullable|boolean',
            ], [
                'currency.in' => 'La devise doit être FCFA, EUR, USD ou XOF',
                'low_stock_threshold.integer' => 'Le seuil de stock faible doit être un nombre entier',
                'low_stock_threshold.min' => 'Le seuil de stock faible doit être entre 0 et 100',
                'low_stock_threshold.max' => 'Le seuil de stock faible doit être entre 0 et 100',
                'language.in' => 'La langue doit être fr ou en',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Créer ou mettre à jour les paramètres
            $settings = UserSetting::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'currency' => $request->currency ?? $user->settings?->currency ?? 'FCFA',
                    'low_stock_threshold' => $request->low_stock_threshold ?? $user->settings?->low_stock_threshold ?? 80,
                    'language' => $request->language ?? $user->settings?->language ?? 'fr',
                    'notify_stock_low' => $request->has('notify_stock_low') ? $request->boolean('notify_stock_low') : ($user->settings?->notify_stock_low ?? true),
                    'notify_invoice_paid' => $request->has('notify_invoice_paid') ? $request->boolean('notify_invoice_paid') : ($user->settings?->notify_invoice_paid ?? true),
                    'notify_invoice_overdue' => $request->has('notify_invoice_overdue') ? $request->boolean('notify_invoice_overdue') : ($user->settings?->notify_invoice_overdue ?? true),
                    'notify_team_joined' => $request->has('notify_team_joined') ? $request->boolean('notify_team_joined') : ($user->settings?->notify_team_joined ?? true),
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Paramètres mis à jour avec succès',
                'data' => $settings
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour des paramètres',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
