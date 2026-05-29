<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * Controller pour la gestion de l'entreprise et l'onboarding.
 */
class CompanyController extends Controller
{
    /**
     * Get company onboarding status.
     * 
     * Vérifie si l'entreprise a besoin de compléter l'onboarding
     * et retourne les champs manquants.
     * 
     * GET /api/company/onboarding-status
     */
    public function onboardingStatus(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $company = $user->company;

            if (!$company) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune entreprise associée à votre compte',
                    'needs_onboarding' => true,
                    'missing_fields' => ['company'],
                ], 404);
            }

            // Déterminer les champs manquants
            $missing = [];
            
            if (empty($company->logo)) {
                $missing[] = 'logo';
            }
            
            if (empty($company->email)) {
                $missing[] = 'email';
            }

            return response()->json([
                'success' => true,
                'needs_onboarding' => count($missing) > 0,
                'missing_fields' => $missing,
                'company' => [
                    'id' => $company->id,
                    'name' => $company->name,
                    'email' => $company->email,
                    'logo' => $company->logo,
                    'logo_url' => $company->logo_url,
                    'sector' => $company->sector,
                    'headquarters' => $company->headquarters,
                    'legal_status' => $company->legal_status,
                    'is_invoice_ready' => $company->is_invoice_ready,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la vérification du statut',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Complete company onboarding.
     * 
     * Permet de compléter les champs manquants (logo et/ou email).
     * La validation est dynamique selon les champs déjà remplis.
     * 
     * POST /api/company/complete-onboarding
     */
    public function completeOnboarding(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $company = $user->company;

            if (!$company) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune entreprise associée à votre compte'
                ], 404);
            }

            // Construire les règles de validation dynamiquement
            $rules = [];
            $messages = [];

            // Email requis seulement s'il n'existe pas déjà
            if (empty($company->email)) {
                $rules['email'] = ['required', 'email', 'max:255'];
                $messages['email.required'] = 'L\'adresse email de l\'entreprise est obligatoire';
                $messages['email.email'] = 'L\'adresse email n\'est pas valide';
            }

            // Logo requis seulement s'il n'existe pas déjà
            if (empty($company->logo)) {
                $rules['logo'] = ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'];
                $messages['logo.required'] = 'Le logo de l\'entreprise est obligatoire';
                $messages['logo.image'] = 'Le fichier doit être une image';
                $messages['logo.mimes'] = 'Le logo doit être au format JPG, JPEG, PNG ou WebP';
                $messages['logo.max'] = 'Le logo ne doit pas dépasser 2 Mo';
            }

            // Si aucun champ n'est requis, l'onboarding est déjà complet
            if (empty($rules)) {
                return response()->json([
                    'success' => true,
                    'message' => 'L\'onboarding est déjà complet',
                    'company' => [
                        'id' => $company->id,
                        'name' => $company->name,
                        'email' => $company->email,
                        'logo' => $company->logo,
                        'logo_url' => $company->logo_url,
                        'is_invoice_ready' => $company->is_invoice_ready,
                    ],
                ]);
            }

            // Valider les données
            $validator = Validator::make($request->all(), $rules, $messages);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Traiter l'upload du logo
            if ($request->hasFile('logo')) {
                // Supprimer l'ancien logo s'il existe
                if ($company->logo) {
                    Storage::disk('public')->delete($company->logo);
                }

                // Générer un nom unique pour le fichier
                $file = $request->file('logo');
                $filename = 'company_' . $company->id . '_' . time() . '.' . $file->getClientOriginalExtension();
                
                // Stocker le nouveau logo
                $path = $file->storeAs('company-logos', $filename, 'public');
                $company->logo = $path;
            }

            // Mettre à jour l'email si fourni
            if ($request->has('email') && empty($company->email)) {
                $company->email = $request->email;
            }

            $company->save();

            return response()->json([
                'success' => true,
                'message' => 'Onboarding entreprise complété avec succès',
                'company' => [
                    'id' => $company->id,
                    'name' => $company->name,
                    'email' => $company->email,
                    'logo' => $company->logo,
                    'logo_url' => $company->logo_url,
                    'sector' => $company->sector,
                    'headquarters' => $company->headquarters,
                    'legal_status' => $company->legal_status,
                    'is_invoice_ready' => $company->is_invoice_ready,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la complétion de l\'onboarding',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get company details.
     * 
     * GET /api/company
     */
    public function show(): JsonResponse
    {
        try {
            $user = Auth::user();
            $company = $user->company;

            if (!$company) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune entreprise associée à votre compte'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $company,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de l\'entreprise',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update company details.
     * 
     * PUT /api/company
     */
    public function update(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $company = $user->company;

            if (!$company) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune entreprise associée à votre compte'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|required|string|max:255',
                'email' => 'sometimes|nullable|email|max:255',
                'sector' => 'sometimes|nullable|string|max:255',
                'headquarters' => 'sometimes|nullable|string|max:500',
                'legal_status' => 'sometimes|nullable|string|max:255',
                'bank_account_number' => 'sometimes|nullable|string|max:50',
                'logo' => 'sometimes|nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            ], [
                'name.required' => 'Le nom de l\'entreprise est obligatoire',
                'email.email' => 'L\'adresse email n\'est pas valide',
                'logo.image' => 'Le fichier doit être une image',
                'logo.mimes' => 'Le logo doit être au format JPG, JPEG, PNG ou WebP',
                'logo.max' => 'Le logo ne doit pas dépasser 2 Mo',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Traiter l'upload du logo si présent
            if ($request->hasFile('logo')) {
                // Supprimer l'ancien logo s'il existe
                if ($company->logo) {
                    Storage::disk('public')->delete($company->logo);
                }

                $file = $request->file('logo');
                $filename = 'company_' . $company->id . '_' . time() . '.' . $file->getClientOriginalExtension();
                $path = $file->storeAs('company-logos', $filename, 'public');
                $company->logo = $path;
            }

            // Mettre à jour les autres champs
            $fields = ['name', 'email', 'sector', 'headquarters', 'legal_status', 'bank_account_number'];
            foreach ($fields as $field) {
                if ($request->has($field)) {
                    $company->$field = $request->$field;
                }
            }

            $company->save();

            return response()->json([
                'success' => true,
                'message' => 'Entreprise mise à jour avec succès',
                'data' => $company,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour de l\'entreprise',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload or update company logo.
     * 
     * POST /api/company/logo
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $company = $user->company;

            if (!$company) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune entreprise associée à votre compte'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'logo' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048',
            ], [
                'logo.required' => 'Le logo est obligatoire',
                'logo.image' => 'Le fichier doit être une image',
                'logo.mimes' => 'Le logo doit être au format JPG, JPEG, PNG ou WebP',
                'logo.max' => 'Le logo ne doit pas dépasser 2 Mo',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Supprimer l'ancien logo s'il existe
            if ($company->logo) {
                Storage::disk('public')->delete($company->logo);
            }

            // Stocker le nouveau logo
            $file = $request->file('logo');
            $filename = 'company_' . $company->id . '_' . time() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('company-logos', $filename, 'public');
            
            $company->logo = $path;
            $company->save();

            return response()->json([
                'success' => true,
                'message' => 'Logo mis à jour avec succès',
                'data' => [
                    'logo' => $company->logo,
                    'logo_url' => $company->logo_url,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'upload du logo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete company logo.
     * 
     * DELETE /api/company/logo
     */
    public function deleteLogo(): JsonResponse
    {
        try {
            $user = Auth::user();
            $company = $user->company;

            if (!$company) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune entreprise associée à votre compte'
                ], 404);
            }

            if (!$company->logo) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun logo à supprimer'
                ], 404);
            }

            // Supprimer le fichier
            Storage::disk('public')->delete($company->logo);
            
            $company->logo = null;
            $company->save();

            return response()->json([
                'success' => true,
                'message' => 'Logo supprimé avec succès',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression du logo',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

