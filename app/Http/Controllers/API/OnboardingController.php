<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\UserSetting;
use App\Support\CurrentAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class OnboardingController extends Controller
{
    /**
     * Marque l'onboarding comme terminé.
     *
     * N'accepte plus company_name/currency en un seul appel monolithique :
     * l'étape 2 (PUT /api/company) et l'étape 3 (PUT /api/user/settings)
     * enregistrent déjà chacune leurs propres données via leurs vrais
     * endpoints avant que le frontend n'appelle celui-ci — il ne fait plus
     * que basculer le statut une fois les deux étapes réellement passées.
     */
    public function complete(): JsonResponse
    {
        $user = Auth::user();

        // L'onboarding n'existe que pour le propriétaire du compte — un
        // membre utilise l'entreprise/les paramètres déjà en place (voir
        // User::isMember()), il n'a jamais de parcours d'onboarding à lui.
        if ($user->isMember()) {
            return response()->json(['success' => false, 'message' => 'Non autorisé'], 403);
        }

        // onboarding_status n'est volontairement pas mass-assignable (même
        // logique que email_verified_at sur User) : seul ce point du code,
        // après vérification explicite, est autorisé à le poser.
        $user->forceFill(['onboarding_status' => 'done'])->save();

        return response()->json([
            'success' => true,
            'message' => 'Onboarding complété avec succès',
        ], 200);
    }

    /**
     * « Passer pour l'instant » : l'utilisateur peut accéder au tableau de
     * bord sans compléter son profil entreprise — distinct de 'pending' pour
     * ne pas le renvoyer de force vers /onboarding à la prochaine page.
     */
    public function skip(): JsonResponse
    {
        $user = Auth::user();

        if ($user->isMember()) {
            return response()->json(['success' => false, 'message' => 'Non autorisé'], 403);
        }

        $user->forceFill(['onboarding_status' => 'skipped'])->save();

        return response()->json([
            'success' => true,
            'message' => 'Onboarding passé pour l\'instant',
        ], 200);
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

            // L'onboarding n'existe que pour le propriétaire du compte — un
            // membre utilise l'entreprise/les paramètres déjà en place.
            if ($user->isMember()) {
                return response()->json(['success' => false, 'message' => 'Non autorisé'], 403);
            }

            $company = Company::where('user_id', CurrentAccount::id())->first();
            $settings = UserSetting::where('user_id', CurrentAccount::id())->first();

            $hasCompany = $company !== null;
            $hasSettings = $settings !== null;
            $status = $user->onboarding_status;
            $isComplete = $status === 'done';

            // Étape à reprendre : aucune company → 2, company sans settings
            // → 3. Dérivé des données réelles plutôt que stocké à part, pour
            // ne jamais pouvoir se désynchroniser de ce qui existe vraiment.
            $nextStep = !$hasCompany ? 2 : 3;

            return response()->json([
                'success' => true,
                'data' => [
                    'is_complete' => $isComplete,
                    'onboarding_status' => $status,
                    'next_step' => $nextStep,
                    'has_company' => $hasCompany,
                    'has_settings' => $hasSettings,
                    'company' => $company,
                    'settings' => $settings,
                ]
            ], 200);

        } catch (\Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la vérification de l\'onboarding',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
