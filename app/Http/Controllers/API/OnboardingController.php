<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\UserSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class OnboardingController extends Controller
{
    /**
     * Complete onboarding - Save company info and user settings
     */
    public function complete(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }

            // Validation des données
            $validator = Validator::make($request->all(), [
                // Informations de l'entreprise
                'company_name' => 'required|string|max:255',
                'company_sector' => 'nullable|string|max:255',
                'company_headquarters' => 'nullable|string',
                'company_email' => 'nullable|email|max:255',
                'company_legal_status' => 'nullable|string|max:255',
                'company_bank_account_number' => 'nullable|string|max:255',
                'company_logo' => 'nullable|string|max:255',
                
                // Paramètres utilisateur
                'currency' => 'required|string|in:FCFA,EUR,USD,XOF',
                'low_stock_threshold' => 'nullable|integer|min:0|max:100',
                'language' => 'nullable|string|in:fr,en',
            ], [
                'company_name.required' => 'Le nom de l\'entreprise est obligatoire',
                'currency.required' => 'La devise est obligatoire',
                'currency.in' => 'La devise doit être FCFA, EUR, USD ou XOF',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Créer ou mettre à jour les informations de l'entreprise
            $company = Company::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'name' => $request->company_name,
                    'sector' => $request->company_sector,
                    'headquarters' => $request->company_headquarters,
                    'email' => $request->company_email,
                    'legal_status' => $request->company_legal_status,
                    'bank_account_number' => $request->company_bank_account_number,
                    'logo' => $request->company_logo,
                ]
            );

            // Créer ou mettre à jour les paramètres utilisateur
            $settings = UserSetting::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'currency' => $request->currency,
                    'low_stock_threshold' => $request->low_stock_threshold ?? 80,
                    'language' => $request->language ?? 'fr',
                ]
            );

            // Recharger les relations
            $user->load(['company', 'settings']);

            return response()->json([
                'success' => true,
                'message' => 'Onboarding complété avec succès',
                'data' => [
                    'company' => $company,
                    'settings' => $settings,
                    'user' => $user,
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la complétion de l\'onboarding',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check if user has completed onboarding
     */
    public function check(): JsonResponse
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }

            $user->load(['company', 'settings']);

            $hasCompany = $user->company !== null;
            $hasSettings = $user->settings !== null;
            $isComplete = $hasCompany && $hasSettings;

            return response()->json([
                'success' => true,
                'data' => [
                    'is_complete' => $isComplete,
                    'has_company' => $hasCompany,
                    'has_settings' => $hasSettings,
                    'company' => $user->company,
                    'settings' => $user->settings,
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la vérification de l\'onboarding',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
