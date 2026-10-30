<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\UserSetting;
use App\Support\CurrentAccount;
use App\Support\Permissions;
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
        // Lecture ouverte à tout utilisateur du compte (membres compris) : la
        // devise, la langue et le seuil de stock du compte s'appliquent à toute
        // l'équipe — l'app les charge sur chaque page. Seule la MODIFICATION
        // (update) exige settings.manage.

        try {
            $settings = UserSetting::where('user_id', CurrentAccount::id())->first();

            // Si pas de paramètres, créer avec les valeurs par défaut
            if (!$settings) {
                $settings = UserSetting::create([
                    'user_id' => CurrentAccount::id(),
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
        if (Auth::user()->cannot(Permissions::SETTINGS_MANAGE)) {
            return response()->json(['success' => false, 'message' => 'Non autorisé'], 403);
        }

        try {
            $accountId = CurrentAccount::id();
            $existing = UserSetting::where('user_id', $accountId)->first();

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
                ['user_id' => $accountId],
                [
                    'currency' => $request->currency ?? $existing?->currency ?? 'FCFA',
                    'low_stock_threshold' => $request->low_stock_threshold ?? $existing?->low_stock_threshold ?? 80,
                    'language' => $request->language ?? $existing?->language ?? 'fr',
                    'notify_stock_low' => $request->has('notify_stock_low') ? $request->boolean('notify_stock_low') : ($existing?->notify_stock_low ?? true),
                    'notify_invoice_paid' => $request->has('notify_invoice_paid') ? $request->boolean('notify_invoice_paid') : ($existing?->notify_invoice_paid ?? true),
                    'notify_invoice_overdue' => $request->has('notify_invoice_overdue') ? $request->boolean('notify_invoice_overdue') : ($existing?->notify_invoice_overdue ?? true),
                    'notify_team_joined' => $request->has('notify_team_joined') ? $request->boolean('notify_team_joined') : ($existing?->notify_team_joined ?? true),
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
