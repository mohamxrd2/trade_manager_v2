<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Chantier "Équipe" étape 2 — endpoints en libre-service pour l'utilisateur
 * CONNECTÉ (propriétaire ou membre), sans permission team.manage :
 * changer son propre code/mot de passe (voir routes/api.php, délègue à
 * UserProfileController::updatePassword()) et consulter ses propres
 * permissions effectives.
 */
class MeController extends Controller
{
    public function permissions(): JsonResponse
    {
        $user = Auth::user();

        return response()->json([
            'success' => true,
            'data' => [
                'permissions' => Permissions::effectivePermissions($user),
            ],
        ]);
    }
}
