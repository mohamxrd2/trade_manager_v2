<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * Controller pour la gestion des clients.
 */
class ClientController extends Controller
{
    /**
     * Display a listing of clients.
     * 
     * GET /api/clients
     * Query params: search, payment_method, per_page, page
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Client::where('user_id', Auth::id())
                ->withCount('invoices');

            // Recherche par nom ou email
            if ($request->has('search') && !empty($request->search)) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'ilike', "%{$search}%")
                      ->orWhere('email', 'ilike', "%{$search}%")
                      ->orWhere('phone', 'ilike', "%{$search}%");
                });
            }

            // Filtre par méthode de paiement
            if ($request->has('payment_method') && !empty($request->payment_method)) {
                $query->where('payment_method', $request->payment_method);
            }

            // Pagination
            $perPage = $request->get('per_page', 20);
            $clients = $query->orderBy('name', 'asc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Clients récupérés avec succès',
                'data' => [
                    'clients' => $clients->items(),
                    'pagination' => [
                        'current_page' => $clients->currentPage(),
                        'per_page' => $clients->perPage(),
                        'total' => $clients->total(),
                        'last_page' => $clients->lastPage(),
                    ],
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des clients',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created client.
     * 
     * POST /api/clients
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'email' => 'nullable|email|max:255',
                'phone' => 'nullable|string|max:50',
                'payment_method' => 'nullable|in:cash,credit_card,bank_transfer,cheque,mobile_money,other',
                'billing_address' => 'nullable|string|max:1000',
                'shipping_address' => 'nullable|string|max:1000',
                'notes' => 'nullable|string|max:2000',
            ], [
                'name.required' => 'Le nom du client est obligatoire',
                'name.max' => 'Le nom ne peut pas dépasser 255 caractères',
                'email.email' => 'L\'adresse email n\'est pas valide',
                'payment_method.in' => 'La méthode de paiement n\'est pas valide',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            $client = Client::create([
                'user_id' => Auth::id(),
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'payment_method' => $request->payment_method ?? 'cash',
                'billing_address' => $request->billing_address,
                'shipping_address' => $request->shipping_address,
                'notes' => $request->notes,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Client créé avec succès',
                'data' => $client
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création du client',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified client.
     * 
     * GET /api/clients/{id}
     */
    public function show(string $id): JsonResponse
    {
        try {
            $client = Client::where('id', $id)
                ->where('user_id', Auth::id())
                ->with(['invoices' => function ($query) {
                    $query->orderBy('created_at', 'desc')->limit(10);
                }])
                ->first();

            if (!$client) {
                return response()->json([
                    'success' => false,
                    'message' => 'Client non trouvé'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Client récupéré avec succès',
                'data' => $client
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du client',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified client.
     * 
     * PUT /api/clients/{id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $client = Client::where('id', $id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$client) {
                return response()->json([
                    'success' => false,
                    'message' => 'Client non trouvé'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'email' => 'nullable|email|max:255',
                'phone' => 'nullable|string|max:50',
                'payment_method' => 'nullable|in:cash,credit_card,bank_transfer,cheque,mobile_money,other',
                'billing_address' => 'nullable|string|max:1000',
                'shipping_address' => 'nullable|string|max:1000',
                'notes' => 'nullable|string|max:2000',
            ], [
                'name.required' => 'Le nom du client est obligatoire',
                'name.max' => 'Le nom ne peut pas dépasser 255 caractères',
                'email.email' => 'L\'adresse email n\'est pas valide',
                'payment_method.in' => 'La méthode de paiement n\'est pas valide',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            $client->update([
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'payment_method' => $request->payment_method ?? $client->payment_method,
                'billing_address' => $request->billing_address,
                'shipping_address' => $request->shipping_address,
                'notes' => $request->notes,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Client modifié avec succès',
                'data' => $client->fresh()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la modification du client',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified client.
     * 
     * DELETE /api/clients/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $client = Client::where('id', $id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$client) {
                return response()->json([
                    'success' => false,
                    'message' => 'Client non trouvé'
                ], 404);
            }

            // Vérifier s'il y a des factures liées
            $invoicesCount = $client->invoices()->count();
            if ($invoicesCount > 0) {
                return response()->json([
                    'success' => false,
                    'message' => "Impossible de supprimer ce client car il a {$invoicesCount} facture(s) associée(s)"
                ], 400);
            }

            $client->delete();

            return response()->json([
                'success' => true,
                'message' => 'Client supprimé avec succès'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression du client',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all clients for dropdown selection.
     * 
     * GET /api/clients/dropdown
     */
    public function dropdown(): JsonResponse
    {
        try {
            $clients = Client::where('user_id', Auth::id())
                ->select('id', 'name', 'email', 'phone')
                ->orderBy('name', 'asc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $clients
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des clients',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

