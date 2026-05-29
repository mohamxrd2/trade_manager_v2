<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Controller pour la gestion des factures.
 */
class InvoiceController extends Controller
{
    /**
     * Display the invoicing dashboard.
     * 
     * GET /api/invoices/dashboard
     */
    public function dashboard(): JsonResponse
    {
        try {
            $userId = Auth::id();

            // Factures non payées
            $unpaidInvoices = Invoice::where('user_id', $userId)
                ->where('status', 'unpaid')
                ->get();

            // Factures payées
            $paidTotal = Invoice::where('user_id', $userId)
                ->where('status', 'paid')
                ->sum('total');

            // Total des factures
            $totalInvoices = Invoice::where('user_id', $userId)
                ->whereNotIn('status', ['draft', 'cancelled'])
                ->count();

            // Dernières factures
            $lastInvoices = Invoice::where('user_id', $userId)
                ->with('client:id,name,email')
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get();

            // Factures en retard
            $overdueCount = Invoice::where('user_id', $userId)
                ->where('status', 'unpaid')
                ->where('due_date', '<', now())
                ->count();

            // Ce mois-ci
            $thisMonthTotal = Invoice::where('user_id', $userId)
                ->where('status', 'paid')
                ->whereMonth('paid_at', now()->month)
                ->whereYear('paid_at', now()->year)
                ->sum('total');

            return response()->json([
                'success' => true,
                'message' => 'Dashboard facturation récupéré avec succès',
                'data' => [
                    'unpaid_invoices_count' => $unpaidInvoices->count(),
                    'unpaid_amount_total' => (float) $unpaidInvoices->sum('total'),
                    'total_invoices' => $totalInvoices,
                    'paid_amount_total' => (float) $paidTotal,
                    'overdue_count' => $overdueCount,
                    'this_month_total' => (float) $thisMonthTotal,
                    'last_invoices' => $lastInvoices,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du dashboard',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a listing of invoices.
     * 
     * GET /api/invoices
     * Query params: status, client_id, date_from, date_to, per_page, page
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Invoice::where('user_id', Auth::id())
                ->with(['client:id,name,email', 'items']);

            // Filtre par statut
            if ($request->has('status') && !empty($request->status)) {
                $query->where('status', $request->status);
            }

            // Filtre par client
            if ($request->has('client_id') && !empty($request->client_id)) {
                $query->where('client_id', $request->client_id);
            }

            // Filtre par date
            if ($request->has('date_from') && !empty($request->date_from)) {
                $query->whereDate('issued_at', '>=', $request->date_from);
            }

            if ($request->has('date_to') && !empty($request->date_to)) {
                $query->whereDate('issued_at', '<=', $request->date_to);
            }

            // Recherche par numéro de facture
            if ($request->has('search') && !empty($request->search)) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('invoice_number', 'ilike', "%{$search}%")
                      ->orWhereHas('client', function ($cq) use ($search) {
                          $cq->where('name', 'ilike', "%{$search}%");
                      });
                });
            }

            // Pagination
            $perPage = $request->get('per_page', 20);
            $invoices = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Factures récupérées avec succès',
                'data' => [
                    'invoices' => $invoices->items(),
                    'pagination' => [
                        'current_page' => $invoices->currentPage(),
                        'per_page' => $invoices->perPage(),
                        'total' => $invoices->total(),
                        'last_page' => $invoices->lastPage(),
                    ],
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des factures',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created invoice.
     * 
     * POST /api/invoices
     */
    public function store(Request $request): JsonResponse
    {
        // =====================================================================
        // ÉTAPE 1: VALIDATION STRICTE
        // =====================================================================
        $validator = Validator::make($request->all(), [
            'client_id' => ['required', 'uuid', 'exists:clients,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.article_id' => ['required', 'uuid', 'exists:articles,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'shipping_fee' => ['nullable', 'numeric', 'min:0'],
            'theme' => ['required', 'string', 'in:classic,modern,minimal,professional'],
            'due_date' => ['required', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'terms' => ['nullable', 'string', 'max:2000'],
            'billing_address' => ['nullable', 'string', 'max:1000'],
            'shipping_address' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', 'in:draft,unpaid'],
        ], [
            'client_id.required' => 'Le client est obligatoire',
            'client_id.uuid' => 'L\'identifiant client n\'est pas valide',
            'client_id.exists' => 'Le client sélectionné n\'existe pas',
            'items.required' => 'Au moins un article est obligatoire',
            'items.array' => 'Les articles doivent être un tableau',
            'items.min' => 'Au moins un article est obligatoire',
            'items.*.article_id.required' => 'L\'article est obligatoire pour chaque ligne',
            'items.*.article_id.uuid' => 'L\'identifiant article n\'est pas valide',
            'items.*.article_id.exists' => 'L\'article sélectionné n\'existe pas',
            'items.*.quantity.required' => 'La quantité est obligatoire pour chaque ligne',
            'items.*.quantity.integer' => 'La quantité doit être un nombre entier',
            'items.*.quantity.min' => 'La quantité doit être au minimum 1',
            'theme.required' => 'Le thème est obligatoire',
            'theme.in' => 'Le thème sélectionné n\'est pas valide (classic, modern, minimal, professional)',
            'due_date.required' => 'La date d\'échéance est obligatoire',
            'due_date.date' => 'La date d\'échéance n\'est pas valide',
            'due_date.after_or_equal' => 'La date d\'échéance doit être aujourd\'hui ou après',
        ]);

        if ($validator->fails()) {
            Log::warning('Invoice validation failed', [
                'user_id' => Auth::id(),
                'errors' => $validator->errors()->toArray(),
                'payload' => $request->except(['items']), // Ne pas logger tous les items
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors()
            ], 422);
        }

        // =====================================================================
        // ÉTAPE 2: VÉRIFICATIONS PRÉLIMINAIRES
        // =====================================================================
        $user = Auth::user();
        $company = $user->company;

        // Vérifier que l'utilisateur a une entreprise
        if (!$company) {
            Log::error('Invoice creation failed: No company', ['user_id' => $user->id]);
            return response()->json([
                'success' => false,
                'code' => 'COMPANY_NOT_FOUND',
                'message' => 'Aucune entreprise associée à votre compte'
            ], 403);
        }

        // Vérifier que l'entreprise est complète
        if (!$company->is_invoice_ready) {
            $missing = $company->getMissingInvoiceFields();
            Log::warning('Invoice creation blocked: Company incomplete', [
                'user_id' => $user->id,
                'company_id' => $company->id,
                'missing' => $missing,
            ]);
            return response()->json([
                'success' => false,
                'code' => 'COMPANY_INCOMPLETE',
                'message' => 'Votre entreprise doit être complète pour créer des factures',
                'missing' => $missing,
            ], 403);
        }

        // Vérifier que le client appartient à l'utilisateur
        $client = Client::where('id', $request->client_id)
            ->where('user_id', $user->id)
            ->first();

        if (!$client) {
            Log::warning('Invoice creation failed: Client not found or unauthorized', [
                'user_id' => $user->id,
                'client_id' => $request->client_id,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Client non trouvé ou non autorisé'
            ], 404);
        }

        // =====================================================================
        // ÉTAPE 3: VÉRIFICATION DES ARTICLES ET DU STOCK
        // =====================================================================
        $stockErrors = [];
        $validatedItems = [];

        foreach ($request->items as $index => $item) {
            $article = Article::where('id', $item['article_id'])
                ->where('user_id', $user->id)
                ->first();

            if (!$article) {
                $stockErrors[] = "L'article à la ligne " . ($index + 1) . " n'existe pas ou n'est pas autorisé";
                continue;
            }

            // Vérifier le stock disponible
            $remainingQty = $article->remaining_quantity;
            if ($remainingQty < $item['quantity']) {
                $stockErrors[] = "Stock insuffisant pour \"{$article->name}\". Disponible: {$remainingQty}, Demandé: {$item['quantity']}";
                continue;
            }

            // Stocker les articles validés pour la création
            $validatedItems[] = [
                'article' => $article,
                'quantity' => (int) $item['quantity'],
                'discount_percent' => (float) ($item['discount_percent'] ?? 0),
                'unit_price' => (float) ($item['unit_price'] ?? $article->sale_price),
            ];
        }

        if (!empty($stockErrors)) {
            Log::warning('Invoice creation failed: Stock errors', [
                'user_id' => $user->id,
                'errors' => $stockErrors,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur de stock',
                'errors' => $stockErrors
            ], 422);
        }

        // =====================================================================
        // ÉTAPE 4: CRÉATION EN TRANSACTION DB
        // =====================================================================
        DB::beginTransaction();

        try {
            // Générer le numéro de facture
            $invoiceNumber = Invoice::generateInvoiceNumber($user->id);

            // Créer la facture avec valeurs par défaut explicites
            $invoice = Invoice::create([
                'user_id' => $user->id,
                'company_id' => $company->id,
                'client_id' => $client->id,
                'invoice_number' => $invoiceNumber,
                'status' => $request->status ?? 'unpaid',
                'subtotal' => 0,
                'discount_percent' => (float) ($request->discount_percent ?? 0),
                'discount_amount' => 0,
                'tax_percent' => (float) ($request->tax_percent ?? 0),
                'tax_amount' => 0,
                'shipping_fee' => (float) ($request->shipping_fee ?? 0),
                'total' => 0,
                'billing_address' => $request->billing_address ?? $client->billing_address ?? '',
                'shipping_address' => $request->shipping_address ?? $client->shipping_address ?? '',
                'theme' => $request->theme,
                'issued_at' => now()->toDateString(),
                'due_date' => $request->due_date,
                'notes' => $request->notes ?? '',
                'terms' => $request->terms ?? '',
            ]);

            $subtotal = 0;

            // Créer les lignes de facture
            foreach ($validatedItems as $validatedItem) {
                $article = $validatedItem['article'];
                $quantity = $validatedItem['quantity'];
                $discountPercent = $validatedItem['discount_percent'];
                $unitPrice = $validatedItem['unit_price'];

                // Calculer les montants de la ligne
                $lineCalc = InvoiceItem::calculateLineTotal($unitPrice, $quantity, $discountPercent);

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'article_id' => $article->id,
                    'name_snapshot' => $article->name,
                    'description_snapshot' => null,
                    'unit_price' => $unitPrice,
                    'quantity' => $quantity,
                    'discount_percent' => $discountPercent,
                    'discount_amount' => $lineCalc['discount_amount'],
                    'total_line' => $lineCalc['total_line'],
                ]);

                $subtotal += $lineCalc['total_line'];

                // Créer une transaction de vente si la facture n'est pas un brouillon
                if ($invoice->status !== 'draft') {
                    Transaction::create([
                        'user_id' => $user->id,
                        'article_id' => $article->id,
                        'name' => "Vente: {$article->name} (Facture {$invoiceNumber})",
                        'type' => 'sale',
                        'quantity' => $quantity,
                        'amount' => $lineCalc['total_line'],
                        'sale_price' => $unitPrice,
                    ]);
                }
            }

            // Calculer les totaux finaux
            $discountPercent = (float) ($request->discount_percent ?? 0);
            $taxPercent = (float) ($request->tax_percent ?? 0);
            $shippingFee = (float) ($request->shipping_fee ?? 0);

            $discountAmount = $discountPercent > 0 
                ? round($subtotal * ($discountPercent / 100), 2) 
                : 0;
            
            $afterDiscount = $subtotal - $discountAmount;
            
            $taxAmount = $taxPercent > 0 
                ? round($afterDiscount * ($taxPercent / 100), 2) 
                : 0;
            
            $total = round($afterDiscount + $taxAmount + $shippingFee, 2);

            // Mettre à jour les totaux
            $invoice->update([
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'tax_amount' => $taxAmount,
                'total' => $total,
            ]);

            DB::commit();

            // Log success
            Log::info('Invoice created successfully', [
                'user_id' => $user->id,
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoiceNumber,
                'total' => $total,
                'items_count' => count($validatedItems),
            ]);

            // Charger les relations pour la réponse
            $invoice->load(['client', 'items.article:id,name,image', 'company']);

            return response()->json([
                'success' => true,
                'message' => 'Facture créée avec succès',
                'data' => $invoice
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();

            // Log détaillé de l'erreur
            Log::error('Invoice creation failed', [
                'user_id' => Auth::id(),
                'client_id' => $request->client_id,
                'items_count' => count($request->items ?? []),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de la facture',
                'error' => config('app.debug') ? $e->getMessage() : 'Une erreur interne est survenue',
            ], 500);
        }
    }

    /**
     * Display the specified invoice.
     * 
     * GET /api/invoices/{id}
     */
    public function show(string $id): JsonResponse
    {
        try {
            // Valider le format UUID
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Format d\'identifiant invalide'
                ], 400);
            }

            $invoice = Invoice::where('id', $id)
                ->where('user_id', Auth::id())
                ->with([
                    'client',
                    'company',
                    'items' => function ($query) {
                        $query->with(['article:id,name,image']);
                    }
                ])
                ->first();

            if (!$invoice) {
                return response()->json([
                    'success' => false,
                    'message' => 'Facture non trouvée'
                ], 404);
            }

            Log::info('Invoice retrieved', [
                'user_id' => Auth::id(),
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Facture récupérée avec succès',
                'data' => $invoice
            ]);
        } catch (\Exception $e) {
            Log::error('Invoice show failed', [
                'user_id' => Auth::id(),
                'invoice_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de la facture',
                'error' => config('app.debug') ? $e->getMessage() : 'Une erreur interne est survenue'
            ], 500);
        }
    }

    /**
     * Update invoice status.
     * 
     * PATCH /api/invoices/{id}/status
     */
    public function updateStatus(Request $request, string $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'status' => 'required|in:draft,unpaid,paid,cancelled,overdue',
            ], [
                'status.required' => 'Le statut est obligatoire',
                'status.in' => 'Le statut n\'est pas valide',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            $invoice = Invoice::where('id', $id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$invoice) {
                return response()->json([
                    'success' => false,
                    'message' => 'Facture non trouvée'
                ], 404);
            }

            $oldStatus = $invoice->status;
            $newStatus = $request->status;

            // Logique spéciale pour le passage à "paid"
            if ($newStatus === 'paid' && $oldStatus !== 'paid') {
                $invoice->update([
                    'status' => $newStatus,
                    'paid_at' => now(),
                ]);
            } else {
                $invoice->update([
                    'status' => $newStatus,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Statut de la facture mis à jour avec succès',
                'data' => [
                    'invoice' => $invoice->fresh(['client', 'items']),
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour du statut',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete an invoice.
     * 
     * DELETE /api/invoices/{id}
     * 
     * Règles de suppression:
     * - draft: peut être supprimé
     * - cancelled: peut être supprimé
     * - unpaid: peut être supprimé (avec avertissement)
     * - paid: NE PEUT PAS être supprimé (pour raisons comptables)
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            // Valider le format UUID
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Format d\'identifiant invalide'
                ], 400);
            }

            $invoice = Invoice::where('id', $id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$invoice) {
                return response()->json([
                    'success' => false,
                    'message' => 'Facture non trouvée'
                ], 404);
            }

            // Les factures payées ne peuvent pas être supprimées (raisons comptables)
            if ($invoice->status === 'paid') {
                return response()->json([
                    'success' => false,
                    'code' => 'INVOICE_PAID',
                    'message' => 'Les factures payées ne peuvent pas être supprimées pour des raisons comptables. Vous pouvez l\'annuler à la place.'
                ], 403);
            }

            $invoiceNumber = $invoice->invoice_number;
            $invoiceStatus = $invoice->status;

            // Supprimer les items de la facture d'abord (cascade devrait le faire, mais on s'assure)
            $invoice->items()->delete();
            
            // Supprimer la facture
            $invoice->delete();

            Log::info('Invoice deleted', [
                'user_id' => Auth::id(),
                'invoice_id' => $id,
                'invoice_number' => $invoiceNumber,
                'previous_status' => $invoiceStatus,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Facture supprimée avec succès',
                'data' => [
                    'deleted_invoice_number' => $invoiceNumber,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Invoice deletion failed', [
                'user_id' => Auth::id(),
                'invoice_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de la facture',
                'error' => config('app.debug') ? $e->getMessage() : 'Une erreur interne est survenue'
            ], 500);
        }
    }

    /**
     * Get available invoice themes.
     * 
     * GET /api/invoices/themes
     */
    public function themes(): JsonResponse
    {
        $themes = [
            [
                'id' => 'classic',
                'name' => 'Classique',
                'description' => 'Design sobre et professionnel',
            ],
            [
                'id' => 'modern',
                'name' => 'Moderne',
                'description' => 'Design contemporain avec couleurs vives',
            ],
            [
                'id' => 'minimal',
                'name' => 'Minimaliste',
                'description' => 'Design épuré et simple',
            ],
            [
                'id' => 'professional',
                'name' => 'Professionnel',
                'description' => 'Design formel pour les entreprises',
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $themes
        ]);
    }

    /**
     * Duplicate an invoice.
     * 
     * POST /api/invoices/{id}/duplicate
     */
    public function duplicate(string $id): JsonResponse
    {
        try {
            $original = Invoice::where('id', $id)
                ->where('user_id', Auth::id())
                ->with('items')
                ->first();

            if (!$original) {
                return response()->json([
                    'success' => false,
                    'message' => 'Facture non trouvée'
                ], 404);
            }

            $user = Auth::user();

            $newInvoice = DB::transaction(function () use ($original, $user) {
                // Créer la nouvelle facture
                $newInvoice = Invoice::create([
                    'user_id' => $user->id,
                    'company_id' => $original->company_id,
                    'client_id' => $original->client_id,
                    'invoice_number' => Invoice::generateInvoiceNumber($user->id),
                    'status' => 'draft',
                    'subtotal' => $original->subtotal,
                    'discount_amount' => $original->discount_amount,
                    'discount_percent' => $original->discount_percent,
                    'tax_amount' => $original->tax_amount,
                    'tax_percent' => $original->tax_percent,
                    'shipping_fee' => $original->shipping_fee,
                    'total' => $original->total,
                    'billing_address' => $original->billing_address,
                    'shipping_address' => $original->shipping_address,
                    'theme' => $original->theme,
                    'issued_at' => now(),
                    'due_date' => now()->addDays(30),
                    'notes' => $original->notes,
                    'terms' => $original->terms,
                ]);

                // Dupliquer les lignes
                foreach ($original->items as $item) {
                    InvoiceItem::create([
                        'invoice_id' => $newInvoice->id,
                        'article_id' => $item->article_id,
                        'name_snapshot' => $item->name_snapshot,
                        'description_snapshot' => $item->description_snapshot,
                        'unit_price' => $item->unit_price,
                        'quantity' => $item->quantity,
                        'discount_percent' => $item->discount_percent,
                        'discount_amount' => $item->discount_amount,
                        'total_line' => $item->total_line,
                    ]);
                }

                return $newInvoice;
            });

            $newInvoice->load(['client', 'items', 'company']);

            return response()->json([
                'success' => true,
                'message' => 'Facture dupliquée avec succès',
                'data' => $newInvoice
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la duplication de la facture',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

