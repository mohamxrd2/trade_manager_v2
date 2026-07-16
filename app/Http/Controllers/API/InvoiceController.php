<?php

namespace App\Http\Controllers\API;

use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Transaction;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * Controller pour la gestion des factures.
 */
class InvoiceController extends Controller
{
    /**
     * Statuts qui impactent le stock (une transaction de vente existe).
     * Le stock n'est décrémenté qu'au passage à "paid" (paiement confirmé) —
     * une facture "unpaid"/"overdue" n'a aucun impact tant qu'elle n'est pas payée.
     */
    private const STOCK_IMPACTING_STATUSES = ['paid'];

    /**
     * Textes du PDF de facture, selon user_settings.language (fr/en).
     */
    private const PDF_TRANSLATIONS = [
        'fr' => [
            'invoice' => 'FACTURE',
            'billed_to' => 'Facturé à',
            'shipped_to' => 'Livré à',
            'invoice_number' => 'N° de facture',
            'issued_date' => 'Date d\'émission',
            'due_date' => 'Date d\'échéance',
            'issued_short' => 'Émise',
            'due_short' => 'Échéance',
            'designation' => 'Désignation',
            'article' => 'Article',
            'qty' => 'Qté',
            'unit_price' => 'Prix unitaire',
            'price_short' => 'Prix',
            'discount' => 'Remise',
            'total' => 'Total',
            'subtotal' => 'Sous-total',
            'tax' => 'TVA',
            'shipping' => 'Livraison',
            'client' => 'Client',
            'dates' => 'Dates',
            'status' => 'Statut',
            'details' => 'Détails',
            'notes' => 'Notes',
            'terms' => 'Conditions',
            'bank_details' => 'Coordonnées bancaires',
            'bank' => 'Banque',
            'company_stamp' => 'Cachet de l\'entreprise',
            'company_stamp_signature' => 'Cachet et signature',
            'client_signature' => 'Signature du client',
            'agreed' => 'Bon pour accord',
            'status_draft' => 'Brouillon',
            'status_unpaid' => 'Impayée',
            'status_paid' => 'Payée',
            'status_cancelled' => 'Annulée',
            'status_overdue' => 'En retard',
        ],
        'en' => [
            'invoice' => 'INVOICE',
            'billed_to' => 'Billed to',
            'shipped_to' => 'Shipped to',
            'invoice_number' => 'Invoice No.',
            'issued_date' => 'Issue date',
            'due_date' => 'Due date',
            'issued_short' => 'Issued',
            'due_short' => 'Due',
            'designation' => 'Description',
            'article' => 'Item',
            'qty' => 'Qty',
            'unit_price' => 'Unit price',
            'price_short' => 'Price',
            'discount' => 'Discount',
            'total' => 'Total',
            'subtotal' => 'Subtotal',
            'tax' => 'Tax',
            'shipping' => 'Shipping',
            'client' => 'Client',
            'dates' => 'Dates',
            'status' => 'Status',
            'details' => 'Details',
            'notes' => 'Notes',
            'terms' => 'Terms',
            'bank_details' => 'Bank details',
            'bank' => 'Bank',
            'company_stamp' => 'Company stamp',
            'company_stamp_signature' => 'Stamp and signature',
            'client_signature' => 'Client signature',
            'agreed' => 'Approved',
            'status_draft' => 'Draft',
            'status_unpaid' => 'Unpaid',
            'status_paid' => 'Paid',
            'status_cancelled' => 'Cancelled',
            'status_overdue' => 'Overdue',
        ],
    ];

    /**
     * Nombre de tentatives en cas de collision sur le numéro de facture
     * (deux créations quasi simultanées pour le même utilisateur).
     */
    private const INVOICE_NUMBER_MAX_ATTEMPTS = 3;

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
            Log::error('Invoice dashboard failed', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du dashboard',
                'error' => config('app.debug') ? $e->getMessage() : 'Une erreur interne est survenue',
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
            // Note: 'ilike' est spécifique à PostgreSQL (driver de production de cette app).
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
            Log::error('Invoice list failed', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des factures',
                'error' => config('app.debug') ? $e->getMessage() : 'Une erreur interne est survenue',
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
        // ÉTAPE 3: VÉRIFICATION RAPIDE DES ARTICLES (existence + appartenance)
        // =====================================================================
        // La vérification AUTORITATIVE du stock disponible (avec verrou DB) se
        // fait plus bas, à l'intérieur de la transaction, pour éviter qu'une
        // requête concurrente ne survende le même article entre-temps.
        $articleIds = collect($request->items)->pluck('article_id')->unique();
        $ownedArticleIds = Article::where('user_id', $user->id)
            ->whereIn('id', $articleIds)
            ->pluck('id')
            ->all();

        $unknownArticles = array_diff($articleIds->all(), $ownedArticleIds);

        if (!empty($unknownArticles)) {
            Log::warning('Invoice creation failed: Unknown/unauthorized articles', [
                'user_id' => $user->id,
                'article_ids' => $unknownArticles,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur de stock',
                'errors' => ['Un ou plusieurs articles sont introuvables ou ne vous appartiennent pas'],
            ], 422);
        }

        // =====================================================================
        // ÉTAPE 4: CRÉATION EN TRANSACTION DB (avec retry sur collision de numéro)
        // =====================================================================
        $invoice = null;

        for ($attempt = 1; $attempt <= self::INVOICE_NUMBER_MAX_ATTEMPTS; $attempt++) {
            try {
                $invoice = DB::transaction(function () use ($request, $user, $company, $client) {
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

                    // Créer les lignes de facture (snapshot des articles)
                    $articlesById = Article::whereIn('id', collect($request->items)->pluck('article_id'))
                        ->get()
                        ->keyBy('id');

                    foreach ($request->items as $item) {
                        $article = $articlesById->get($item['article_id']);
                        $quantity = (int) $item['quantity'];
                        $discountPercent = (float) ($item['discount_percent'] ?? 0);
                        $unitPrice = (float) ($item['unit_price'] ?? $article->sale_price);

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
                    }

                    // Vérification (avec verrou) ET décrément du stock, source de
                    // vérité unique — seulement si le statut de création impacte déjà
                    // le stock (en pratique jamais à la création, "paid" n'étant pas un
                    // statut autorisé par le validateur ci-dessus ; gardé par cohérence).
                    if (in_array($invoice->status, self::STOCK_IMPACTING_STATUSES, true)) {
                        $this->createSaleTransactionsForInvoice($invoice);
                    }

                    // Calcul des totaux (logique centralisée dans le modèle)
                    $invoice->calculateTotals();

                    return $invoice;
                });

                break; // succès, sortir de la boucle de retry
            } catch (InsufficientStockException $e) {
                Log::warning('Invoice creation failed: Stock errors', [
                    'user_id' => $user->id,
                    'errors' => $e->errors,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de stock',
                    'errors' => $e->errors,
                ], 422);
            } catch (QueryException $e) {
                if ($this->isUniqueConstraintViolation($e, 'invoice_number') && $attempt < self::INVOICE_NUMBER_MAX_ATTEMPTS) {
                    // Collision sur le numéro de facture (création concurrente) : on retente.
                    continue;
                }

                Log::error('Invoice creation failed', [
                    'user_id' => $user->id,
                    'client_id' => $request->client_id,
                    'items_count' => count($request->items ?? []),
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Erreur lors de la création de la facture',
                    'error' => config('app.debug') ? $e->getMessage() : 'Une erreur interne est survenue',
                ], 500);
            } catch (\Throwable $e) {
                Log::error('Invoice creation failed', [
                    'user_id' => $user->id,
                    'client_id' => $request->client_id,
                    'items_count' => count($request->items ?? []),
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Erreur lors de la création de la facture',
                    'error' => config('app.debug') ? $e->getMessage() : 'Une erreur interne est survenue',
                ], 500);
            }
        }

        Log::info('Invoice created successfully', [
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'total' => $invoice->total,
        ]);

        // Charger les relations pour la réponse
        $invoice->load(['client', 'items.article:id,name,image', 'company']);

        return response()->json([
            'success' => true,
            'message' => 'Facture créée avec succès',
            'data' => $invoice
        ], 201);
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
     * Preview the invoice PDF inline in the browser (no forced download).
     *
     * GET /api/invoices/{id}/preview
     */
    public function preview(string $id): Response|JsonResponse
    {
        try {
            $invoice = $this->findInvoiceForPdf($id);

            if (!$invoice) {
                return response()->json([
                    'success' => false,
                    'message' => 'Facture non trouvée'
                ], 404);
            }

            return Pdf::loadView($this->pdfViewName($invoice), $this->pdfViewData($invoice))
                ->stream("facture-{$invoice->invoice_number}.pdf");
        } catch (\Throwable $e) {
            Log::error('Invoice PDF preview failed', [
                'user_id' => Auth::id(),
                'invoice_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la génération de l\'aperçu',
                'error' => config('app.debug') ? $e->getMessage() : 'Une erreur interne est survenue',
            ], 500);
        }
    }

    /**
     * Download the invoice PDF (forces a file download).
     *
     * GET /api/invoices/{id}/download
     */
    public function download(string $id): Response|JsonResponse
    {
        try {
            $invoice = $this->findInvoiceForPdf($id);

            if (!$invoice) {
                return response()->json([
                    'success' => false,
                    'message' => 'Facture non trouvée'
                ], 404);
            }

            return Pdf::loadView($this->pdfViewName($invoice), $this->pdfViewData($invoice))
                ->download("facture-{$invoice->invoice_number}.pdf");
        } catch (\Throwable $e) {
            Log::error('Invoice PDF download failed', [
                'user_id' => Auth::id(),
                'invoice_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la génération du PDF',
                'error' => config('app.debug') ? $e->getMessage() : 'Une erreur interne est survenue',
            ], 500);
        }
    }

    /**
     * Update invoice status.
     *
     * PATCH /api/invoices/{id}/status
     *
     * Le passage vers/depuis un statut impactant le stock (unpaid, paid,
     * overdue) crée ou supprime les transactions de vente correspondantes,
     * avec vérification de stock sous verrou en cas de nouvel impact.
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

            $wasImpacting = in_array($oldStatus, self::STOCK_IMPACTING_STATUSES, true);
            $willBeImpacting = in_array($newStatus, self::STOCK_IMPACTING_STATUSES, true);

            try {
                DB::transaction(function () use ($invoice, $newStatus, $oldStatus, $wasImpacting, $willBeImpacting) {
                    if (!$wasImpacting && $willBeImpacting) {
                        // ex: draft -> unpaid : le stock n'a jamais été décrémenté, on le fait maintenant.
                        $this->createSaleTransactionsForInvoice($invoice);
                    } elseif ($wasImpacting && !$willBeImpacting) {
                        // ex: unpaid -> cancelled : on restaure le stock déduit.
                        $this->reverseSaleTransactionsForInvoice($invoice);
                    }

                    $updateData = ['status' => $newStatus];

                    if ($newStatus === 'paid' && $oldStatus !== 'paid') {
                        $updateData['paid_at'] = now();
                    }

                    $invoice->update($updateData);
                });
            } catch (InsufficientStockException $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stock insuffisant pour ce changement de statut',
                    'errors' => $e->errors,
                ], 422);
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
            Log::error('Invoice status update failed', [
                'user_id' => Auth::id(),
                'invoice_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour du statut',
                'error' => config('app.debug') ? $e->getMessage() : 'Une erreur interne est survenue',
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
     * - unpaid: peut être supprimé (le stock déduit est restauré)
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
            $warning = null;

            DB::transaction(function () use ($invoice, $invoiceStatus, &$warning) {
                // Si la facture avait décrémenté le stock, on le restaure avant suppression.
                if (in_array($invoiceStatus, self::STOCK_IMPACTING_STATUSES, true)) {
                    $this->reverseSaleTransactionsForInvoice($invoice);
                    $warning = 'Le stock déduit par cette facture a été restauré.';
                }

                // Supprimer les items de la facture d'abord (cascade devrait le faire, mais on s'assure)
                $invoice->items()->delete();

                // Supprimer la facture
                $invoice->delete();
            });

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
                    'warning' => $warning,
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
     *
     * La copie est toujours créée en "draft" : aucun impact stock à ce stade
     * (cohérent avec la règle store()/updateStatus()).
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
            Log::error('Invoice duplication failed', [
                'user_id' => Auth::id(),
                'invoice_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la duplication de la facture',
                'error' => config('app.debug') ? $e->getMessage() : 'Une erreur interne est survenue',
            ], 500);
        }
    }

    /**
     * Charge une facture (appartenant à l'utilisateur connecté) avec toutes
     * les relations nécessaires au rendu du PDF.
     */
    private function findInvoiceForPdf(string $id): ?Invoice
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
            return null;
        }

        return Invoice::where('id', $id)
            ->where('user_id', Auth::id())
            ->with(['client', 'company', 'items', 'user.settings'])
            ->first();
    }

    /**
     * Nom de la vue Blade correspondant au thème de la facture, avec repli
     * sur "classic" si la valeur stockée ne correspond à aucun thème connu.
     */
    private function pdfViewName(Invoice $invoice): string
    {
        $knownThemes = ['classic', 'modern', 'minimal', 'professional'];
        $theme = in_array($invoice->theme, $knownThemes, true) ? $invoice->theme : 'classic';

        return "invoices.themes.{$theme}";
    }

    /**
     * Données communes passées à n'importe quel template de facture PDF.
     */
    private function pdfViewData(Invoice $invoice): array
    {
        $currency = $invoice->user?->settings?->currency ?? 'FCFA';
        $language = $invoice->user?->settings?->language ?? 'fr';
        $t = self::PDF_TRANSLATIONS[$language] ?? self::PDF_TRANSLATIONS['fr'];

        return [
            'invoice' => $invoice,
            'company' => $invoice->company,
            'logoDataUri' => $this->logoDataUri($invoice->company->logo),
            'money' => fn (float $amount) => Invoice::formatMoney($amount, $currency),
            't' => $t,
            'statusLabel' => $t['status_' . $invoice->status] ?? ucfirst($invoice->status),
        ];
    }

    /**
     * Encode le logo de l'entreprise en data URI base64, lu directement
     * depuis le disque (pas via une requête HTTP — voir config/dompdf.php).
     */
    private function logoDataUri(?string $logoPath): ?string
    {
        if (!$logoPath || !Storage::disk('public')->exists($logoPath)) {
            return null;
        }

        $mimeType = Storage::disk('public')->mimeType($logoPath) ?: 'image/png';
        $contents = Storage::disk('public')->get($logoPath);

        return "data:{$mimeType};base64," . base64_encode($contents);
    }

    /**
     * Vérifie (avec verrou pessimiste) et décrémente le stock pour les lignes
     * d'une facture, en une seule transaction de vente par ligne, liée à la
     * facture via invoice_id.
     *
     * Les quantités sont d'abord agrégées par article pour correctement
     * détecter une survente même si le même article apparaît sur plusieurs
     * lignes de la facture. Le verrou (lockForUpdate) empêche deux requêtes
     * concurrentes de survendre le même article.
     *
     * @throws InsufficientStockException si le stock est insuffisant pour une ligne
     */
    private function createSaleTransactionsForInvoice(Invoice $invoice): void
    {
        $items = $invoice->items()->whereNotNull('article_id')->get();

        if ($items->isEmpty()) {
            return;
        }

        $qtyByArticle = [];
        foreach ($items as $item) {
            $qtyByArticle[$item->article_id] = ($qtyByArticle[$item->article_id] ?? 0) + $item->quantity;
        }

        // Verrouille les lignes articles concernées jusqu'à la fin de la transaction
        // englobante, bloquant toute requête concurrente sur les mêmes articles.
        $articles = Article::whereIn('id', array_keys($qtyByArticle))
            ->where('user_id', $invoice->user_id)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $errors = [];
        foreach ($qtyByArticle as $articleId => $requestedQty) {
            $article = $articles->get($articleId);

            if (!$article) {
                $errors[] = "Un article de la facture est introuvable ou ne vous appartient plus.";
                continue;
            }

            if ($article->remaining_quantity < $requestedQty) {
                $errors[] = "Stock insuffisant pour \"{$article->name}\". Disponible: {$article->remaining_quantity}, Demandé: {$requestedQty}";
            }
        }

        if (!empty($errors)) {
            throw new InsufficientStockException($errors);
        }

        foreach ($items as $item) {
            Transaction::create([
                'user_id' => $invoice->user_id,
                'article_id' => $item->article_id,
                'invoice_id' => $invoice->id,
                'name' => "Vente: {$item->name_snapshot} (Facture {$invoice->invoice_number})",
                'type' => 'sale',
                'quantity' => $item->quantity,
                'amount' => $item->total_line,
                'sale_price' => $item->unit_price,
            ]);
        }
    }

    /**
     * Supprime les transactions de vente liées à une facture, restaurant le
     * stock correspondant (le stock restant est toujours dérivé de la somme
     * des transactions de vente, donc les supprimer le restaure automatiquement).
     *
     * Itère et appelle delete() par instance (plutôt qu'un delete() de requête)
     * pour déclencher le hook `deleting` du modèle Transaction (recalcul wallet).
     */
    private function reverseSaleTransactionsForInvoice(Invoice $invoice): void
    {
        $invoice->transactions()
            ->where('type', 'sale')
            ->get()
            ->each(fn (Transaction $transaction) => $transaction->delete());
    }

    /**
     * Détecte une violation de contrainte unique PostgreSQL sur une colonne donnée.
     */
    private function isUniqueConstraintViolation(QueryException $e, string $column): bool
    {
        return $e->getCode() === '23505' && str_contains($e->getMessage(), $column);
    }
}
