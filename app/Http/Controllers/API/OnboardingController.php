<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
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

            $user->load(['company', 'settings']);

            $hasCompany = $user->company !== null;
            $hasSettings = $user->settings !== null;
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
                    'company' => $user->company,
                    'settings' => $user->settings,
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
