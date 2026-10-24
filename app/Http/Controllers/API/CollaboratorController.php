<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Collaborator;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CollaboratorController extends Controller
{
    /**
     * Display a listing of the collaborators for the authenticated user.
     */
    public function index(): JsonResponse
    {
        try {
            $collaborators = Collaborator::where('user_id', Auth::id())
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($collaborator) {
                    $collaborator->wallet = $collaborator->wallet; // Force le calcul de l'accesseur
                    return $collaborator;
                });

            return response()->json([
                'success' => true,
                'message' => 'Collaborateurs récupérés avec succès',
                'data' => $collaborators
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des collaborateurs',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created collaborator.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'phone' => 'required|string|max:20',
                'part' => 'required|numeric|min:0.01|max:99.99',
                'image' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Désactiver la transaction pour éviter les problèmes avec PostgreSQL
            // return DB::transaction(function () use ($request) {
                // Verrouiller la ligne utilisateur pour éviter les races conditions
                $user = User::where('id', Auth::id())->lockForUpdate()->first();

                if (!$user) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Utilisateur non trouvé'
                    ], 404);
                }

                // Validation sur la SOMME RÉELLE des parts des collaborateurs
                // existants (jamais sur company_share, qui peut être décalé
                // si une opération précédente ne l'a pas correctement
                // ajusté — voir variations:resync pour corriger un tel
                // décalage existant). Ici il n'y a pas d'"autre" à exclure
                // puisque c'est une création.
                $sumOtherParts = (string) Collaborator::where('user_id', $user->id)->sum('part');
                $projectedTotal = bcadd($sumOtherParts, (string) $request->part, 2);

                if (bccomp($projectedTotal, '100', 2) > 0) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Le total des parts dépasse 100 %',
                        'errors' => ['part' => ['Le total des parts dépasse 100 %']],
                    ], 422);
                }

                // Créer le collaborateur avec UUID généré automatiquement
                $collaborator = Collaborator::create([
                    'id' => Str::uuid()->toString(),
                    'user_id' => $user->id,
                    'name' => $request->name,
                    'phone' => $request->phone,
                    'part' => $request->part,
                    'image' => $request->image ?? null,
                ]);

                // Décrémenter la part de l'utilisateur
                $user->company_share = bcsub($user->company_share, $request->part, 2);
                $user->save();

                // Recharger le collaborateur avec la relation user pour calculer le wallet
                $collaborator->load('user');

                // Forcer le calcul du wallet en accédant à l'accesseur
                $collaborator->wallet = $collaborator->wallet;

                NotificationService::notifyTeamJoined($collaborator);

                return response()->json([
                    'success' => true,
                    'message' => 'Collaborator created',
                    'data' => $collaborator
                ], 201);
            // });

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création du collaborateur',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified collaborator.
     */
    public function show(string $id): JsonResponse
    {
        try {
            $collaborator = Collaborator::where('id', $id)
                ->where('user_id', Auth::id())
                ->with('user')
                ->first();

            if (!$collaborator) {
                return response()->json([
                    'success' => false,
                    'message' => 'Collaborateur non trouvé'
                ], 404);
            }

            // Forcer le calcul du wallet en accédant à l'accesseur
            $collaborator->wallet = $collaborator->wallet;

            return response()->json([
                'success' => true,
                'message' => 'Collaborateur récupéré avec succès',
                'data' => $collaborator
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du collaborateur',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified collaborator.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            if ($request->has('wallet')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le champ wallet est calculé automatiquement',
                    'errors' => ['wallet' => ['Le champ wallet est calculé automatiquement']]
                ], 422);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255',
                'phone' => 'sometimes|string|max:20',
                'image' => 'nullable|string|max:255',
                'part' => 'sometimes|numeric|min:0.01|max:99.99',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            return DB::transaction(function () use ($request, $id) {
                $collaborator = Collaborator::where('id', $id)
                    ->where('user_id', Auth::id())
                    ->first();

                if (!$collaborator) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Collaborateur non trouvé'
                    ], 404);
                }

                if ($request->has('part')) {
                    // Verrouiller la ligne utilisateur (ajustement de
                    // company_share) — même précaution que store()/destroy().
                    $user = User::where('id', Auth::id())->lockForUpdate()->first();

                    if (!$user) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Utilisateur non trouvé'
                        ], 404);
                    }

                    // Validation sur la SOMME RÉELLE des parts des AUTRES
                    // collaborateurs (celui qu'on modifie est exclu de la
                    // somme, sa nouvelle valeur est ajoutée ensuite) — jamais
                    // sur company_share, qui peut être décalé.
                    $sumOtherParts = (string) Collaborator::where('user_id', Auth::id())
                        ->where('id', '!=', $id)
                        ->sum('part');
                    $projectedTotal = bcadd($sumOtherParts, (string) $request->part, 2);

                    if (bccomp($projectedTotal, '100', 2) > 0) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Le total des parts dépasse 100 %',
                            'errors' => ['part' => ['Le total des parts dépasse 100 %']],
                        ], 422);
                    }

                    // Ajuste company_share de la DIFFÉRENCE (ancienne part −
                    // nouvelle part) : si la part augmente, company_share
                    // diminue d'autant, et inversement.
                    $delta = bcsub((string) $collaborator->part, (string) $request->part, 2);
                    $user->company_share = bcadd($user->company_share, $delta, 2);
                    $user->save();
                }

                // Mettre à jour les champs autorisés (part comprise si fournie)
                $collaborator->update($request->only([
                    'name', 'phone', 'image', 'part',
                ]));

                // Recharger le collaborateur avec la relation user pour calculer le wallet
                $collaborator->load('user');

                // Forcer le calcul du wallet en accédant à l'accesseur
                $collaborator->wallet = $collaborator->wallet;

                return response()->json([
                    'success' => true,
                    'message' => 'Collaborateur mis à jour avec succès',
                    'data' => $collaborator
                ]);
            });

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour du collaborateur',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified collaborator.
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            return DB::transaction(function () use ($id) {
                // Récupérer le collaborateur
                $collaborator = Collaborator::where('id', $id)
                    ->where('user_id', Auth::id())
                    ->first();

                if (!$collaborator) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Collaborateur non trouvé'
                    ], 404);
                }

                // Verrouiller la ligne utilisateur
                $user = User::where('id', Auth::id())->lockForUpdate()->first();

                if (!$user) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Utilisateur non trouvé'
                    ], 404);
                }

                // Récupérer la part avant suppression
                $partToReturn = $collaborator->part;

                // Supprimer le collaborateur
                $collaborator->delete();

                // Ajouter la part à l'utilisateur
                $user->company_share = bcadd($user->company_share, $partToReturn, 2);
                $user->save();

                return response()->json([
                    'success' => true,
                    'message' => 'Collaborateur supprimé avec succès',
                    'data' => [
                        'returned_part' => $partToReturn
                    ]
                ]);
            });

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression du collaborateur',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}