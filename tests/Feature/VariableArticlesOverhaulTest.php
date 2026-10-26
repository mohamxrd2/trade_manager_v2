<?php

use App\Models\Article;
use App\Models\Company;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\StockReplenishment;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Variation;
use App\Services\ArticleStockService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Schéma minimal, compatible SQLite (la suite tourne en sqlite :memory:,
| cf. phpunit.xml — RefreshDatabase est désactivé car la 1ère migration
| réelle utilise une extension Postgres). Nom de fonction distinct de celui
| de TransactionIdempotencyTest.php pour éviter toute redéclaration.
|--------------------------------------------------------------------------
*/
function createVariableArticlesSchema(): void
{
    foreach ([
        'notifications', 'stock_replenishments', 'invoice_items', 'invoices',
        'clients', 'companies', 'transactions', 'operations', 'operation_counters', 'variations', 'articles',
        'user_settings', 'users',
    ] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('users', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('first_name');
        $table->string('last_name');
        $table->string('username')->unique();
        $table->string('email')->unique();
        $table->string('password');
        $table->decimal('company_share', 5, 2)->default(100.00);
        $table->string('profile_image')->nullable();
        $table->string('provider')->default('local');
        $table->string('provider_id')->nullable();
        $table->timestamp('email_verified_at')->nullable();
        $table->rememberToken();
        $table->timestamps();
    });

    Schema::create('user_settings', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id')->unique();
        $table->string('currency')->default('FCFA');
        $table->integer('low_stock_threshold')->default(80);
        $table->boolean('notify_stock_low')->default(true);
        $table->string('language')->default('fr');
        $table->timestamps();
    });

    Schema::create('articles', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->string('name');
        $table->decimal('sale_price', 15, 2);
        $table->decimal('price_max', 15, 2)->nullable();
        $table->integer('quantity');
        $table->string('type');
        $table->string('image')->nullable();
        $table->timestamps();
    });

    Schema::create('variations', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('article_id');
        $table->string('name');
        $table->integer('quantity');
        $table->decimal('price', 15, 2)->nullable();
        $table->decimal('cost_price', 15, 2)->nullable();
        $table->unsignedInteger('low_stock_threshold')->nullable();
        $table->string('image')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });

    Schema::create('transactions', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->uuid('article_id')->nullable();
        $table->uuid('variable_id')->nullable();
        $table->uuid('invoice_id')->nullable();
        $table->string('name');
        $table->integer('quantity')->nullable();
        $table->decimal('amount', 15, 2);
        $table->decimal('sale_price', 15, 2)->nullable();
        $table->string('type');
        $table->string('idempotency_key')->nullable();
        $table->uuid('operation_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
        $table->unique(['user_id', 'idempotency_key'], 'transactions_user_id_idempotency_key_unique');
    });

    // operations/operation_counters : Transaction::booted() crée
    // automatiquement une opération à une ligne pour CHAQUE transaction
    // créée (étape 1 du chantier "paniers multi-articles", voir
    // Transaction::creating()) — ces deux tables doivent donc exister dans
    // le schéma minimal de TOUT fichier de test qui crée une Transaction,
    // pas seulement ceux qui testent les opérations elles-mêmes.
    Schema::create('operations', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->uuid('created_by_user_id')->nullable();
        $table->string('type');
        $table->string('code');
        $table->string('payment_method')->nullable();
        $table->decimal('total_amount', 12, 2);
        $table->decimal('amount_received', 12, 2)->nullable();
        $table->decimal('change_given', 12, 2)->nullable();
        $table->uuid('client_id')->nullable();
        $table->uuid('invoice_id')->nullable();
        $table->text('note')->nullable();
        $table->timestamp('occurred_at');
        $table->string('idempotency_key')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('operation_counters', function (Blueprint $table) {
        $table->id();
        $table->uuid('user_id');
        $table->string('prefix', 1);
        $table->date('day');
        $table->unsignedInteger('last_value')->default(0);
        $table->timestamps();
        $table->unique(['user_id', 'prefix', 'day']);
    });

    Schema::create('companies', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id')->unique();
        $table->string('name');
        $table->string('sector')->nullable();
        $table->text('headquarters')->nullable();
        $table->string('email')->nullable();
        $table->string('legal_status')->nullable();
        $table->string('bank_account_number')->nullable();
        $table->string('logo')->nullable();
        $table->timestamps();
    });

    Schema::create('clients', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->string('name');
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->string('payment_method')->default('cash');
        $table->text('billing_address')->nullable();
        $table->text('shipping_address')->nullable();
        $table->text('notes')->nullable();
        $table->timestamps();
    });

    Schema::create('invoices', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->uuid('company_id');
        $table->uuid('client_id');
        $table->string('invoice_number')->unique();
        $table->string('status')->default('draft');
        $table->decimal('subtotal', 15, 2)->default(0);
        $table->decimal('discount_amount', 15, 2)->default(0);
        $table->decimal('discount_percent', 5, 2)->default(0);
        $table->decimal('tax_amount', 15, 2)->default(0);
        $table->decimal('tax_percent', 5, 2)->default(0);
        $table->decimal('shipping_fee', 15, 2)->default(0);
        $table->decimal('total', 15, 2)->default(0);
        $table->text('billing_address')->nullable();
        $table->text('shipping_address')->nullable();
        $table->string('theme')->default('classic');
        $table->date('issued_at');
        $table->date('due_date');
        $table->timestamp('paid_at')->nullable();
        $table->text('notes')->nullable();
        $table->text('terms')->nullable();
        $table->string('pdf_path')->nullable();
        $table->timestamp('sent_at')->nullable();
        $table->timestamps();
    });

    Schema::create('invoice_items', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('invoice_id');
        $table->uuid('article_id')->nullable();
        $table->uuid('variation_id')->nullable();
        $table->string('name_snapshot');
        $table->text('description_snapshot')->nullable();
        $table->decimal('unit_price', 15, 2);
        $table->integer('quantity');
        $table->decimal('discount_percent', 5, 2)->default(0);
        $table->decimal('discount_amount', 15, 2)->default(0);
        $table->decimal('total_line', 15, 2);
        $table->timestamps();
    });

    Schema::create('stock_replenishments', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->uuid('article_id');
        $table->uuid('variation_id')->nullable();
        $table->unsignedInteger('quantity_added');
        $table->string('note')->nullable();
        $table->timestamps();
    });

    Schema::create('notifications', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->string('type')->default('info');
        $table->string('title');
        $table->text('message');
        $table->boolean('read')->default(false);
        $table->uuid('article_id')->nullable();
        $table->string('action_url')->nullable();
        $table->timestamps();
    });
}

beforeEach(function () {
    config(['database.connections.sqlite.database' => ':memory:']);
    DB::purge('sqlite');
    createVariableArticlesSchema();
});

function makeVariationsTestUser(): User
{
    $user = User::create([
        'first_name' => 'Test',
        'last_name' => 'User',
        'username' => 'user_' . Str::random(8),
        'email' => Str::random(8) . '@example.test',
        'password' => 'irrelevant-for-this-test',
    ]);

    DB::table('user_settings')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'currency' => 'FCFA',
        'low_stock_threshold' => 80,
        'notify_stock_low' => true,
        'language' => 'fr',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $user;
}

function makeInvoiceReadyCompanyAndClient(User $user): Client
{
    Company::create([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'name' => 'Ma Société',
        'email' => 'societe@example.test',
        'logo' => 'logo.png',
    ]);

    return Client::create([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'name' => 'Client Test',
        'payment_method' => 'cash',
    ]);
}

// =============================================================================
// A. Création atomique d'un article variable avec variations
// =============================================================================

test('créer un article variable avec 3 variations en une seule requête donne le bon total et la bonne plage de prix', function () {
    $user = makeVariationsTestUser();

    $response = $this->actingAs($user)->postJson('/api/articles', [
        'name' => 'T-shirt',
        'type' => 'variable',
        'variations' => [
            ['name' => 'S', 'quantity' => 10, 'price' => 5000],
            ['name' => 'M', 'quantity' => 15, 'price' => 5000],
            ['name' => 'L', 'quantity' => 5, 'price' => 6000],
        ],
    ]);

    $response->assertStatus(201);
    $article = Article::where('name', 'T-shirt')->first();

    expect($article)->not->toBeNull();
    expect($article->quantity)->toBe(30);
    expect((float) $article->sale_price)->toBe(5000.0);
    expect((float) $article->price_max)->toBe(6000.0);
    expect(Variation::where('article_id', $article->id)->count())->toBe(3);
});

test('un nom de variation en double dans la requête ne crée RIEN (rollback complet)', function () {
    $user = makeVariationsTestUser();

    $response = $this->actingAs($user)->postJson('/api/articles', [
        'name' => 'Chaussures',
        'type' => 'variable',
        'variations' => [
            ['name' => '42', 'quantity' => 10, 'price' => 20000],
            ['name' => '42', 'quantity' => 5, 'price' => 20000],
        ],
    ]);

    $response->assertStatus(422);
    expect(Article::where('name', 'Chaussures')->count())->toBe(0);
    expect(Variation::count())->toBe(0);
});

test('ancienne API sans variations[] fonctionne toujours (compatibilité ascendante)', function () {
    $user = makeVariationsTestUser();

    $response = $this->actingAs($user)->postJson('/api/articles', [
        'name' => 'Sac',
        'type' => 'variable',
    ]);

    $response->assertStatus(201);
    $article = Article::where('name', 'Sac')->first();
    expect($article->quantity)->toBe(0);

    // La boucle historique POST /api/variations continue de fonctionner.
    $variationResponse = $this->actingAs($user)->postJson('/api/variations', [
        'article_id' => $article->id,
        'name' => 'Unique',
        'quantity' => 12,
        'price' => 15000,
    ]);

    $variationResponse->assertStatus(201);
    expect($article->fresh()->quantity)->toBe(12);
});

test('créer un article simple fonctionne normalement (sale_price/quantity au niveau article)', function () {
    $user = makeVariationsTestUser();

    $response = $this->actingAs($user)->postJson('/api/articles', [
        'name' => 'Souris',
        'type' => 'simple',
        'sale_price' => 9000,
        'quantity' => 40,
    ]);

    $response->assertStatus(201);
    $article = Article::where('name', 'Souris')->first();
    expect((float) $article->sale_price)->toBe(9000.0);
    expect($article->quantity)->toBe(40);
});

test('modifier un article simple avec sale_price/quantity fonctionne normalement', function () {
    $user = makeVariationsTestUser();
    $article = Article::create(['user_id' => $user->id, 'name' => 'Souris', 'type' => 'simple', 'sale_price' => 9000, 'quantity' => 40]);

    $updateResponse = $this->actingAs($user)->putJson("/api/articles/{$article->id}", [
        'name' => 'Souris Pro',
        'sale_price' => 9500,
        'quantity' => 38,
    ]);

    $updateResponse->assertStatus(200);
    expect($article->fresh()->sale_price)->toEqualWithDelta(9500, 0.01);
    expect($article->fresh()->quantity)->toBe(38);
});

test('modifier un article simple sans sale_price/quantity échoue (toujours obligatoires)', function () {
    $user = makeVariationsTestUser();
    $article = Article::create(['user_id' => $user->id, 'name' => 'Souris', 'type' => 'simple', 'sale_price' => 9000, 'quantity' => 40]);

    $badUpdate = $this->actingAs($user)->putJson("/api/articles/{$article->id}", [
        'name' => 'Souris Pro 2',
    ]);

    $badUpdate->assertStatus(422);
});

test('modifier puis supprimer une vente sur un article simple : quantity inchangée, restant recalculé correctement', function () {
    $user = makeVariationsTestUser();
    $article = Article::create(['user_id' => $user->id, 'name' => 'Clavier', 'type' => 'simple', 'sale_price' => 20000, 'quantity' => 50]);

    $saleResponse = $this->actingAs($user)->postJson('/api/transactions', [
        'type' => 'sale', 'article_id' => $article->id, 'quantity' => 5,
    ]);
    $saleResponse->assertStatus(201);
    $transactionId = $saleResponse->json('data.id');

    expect($article->fresh()->quantity)->toBe(50);
    expect($article->fresh()->remaining_quantity)->toBe(45);

    // Modifier la quantité vendue (5 -> 8) : quantity (cumul stocké) ne doit
    // JAMAIS bouger — seul le restant, dérivé des ventes, doit changer.
    $updateResponse = $this->actingAs($user)->putJson("/api/transactions/{$transactionId}", [
        'name' => 'Vente modifiée', 'quantity' => 8,
    ]);
    $updateResponse->assertStatus(200);
    expect($article->fresh()->quantity)->toBe(50);
    expect($article->fresh()->remaining_quantity)->toBe(42);

    // Supprimer la vente : quantity reste à 50 (jamais incrémentée en plus),
    // le restant revient exactement à l'état initial (aucune vente).
    $deleteResponse = $this->actingAs($user)->deleteJson("/api/transactions/{$transactionId}");
    $deleteResponse->assertStatus(200);
    expect($article->fresh()->quantity)->toBe(50);
    expect($article->fresh()->remaining_quantity)->toBe(50);
});

// =============================================================================
// B. Ajout d'une variation plus tard — plus de plafond
// =============================================================================

test('ajouter une variation plus tard augmente le total sans erreur de plafond', function () {
    $user = makeVariationsTestUser();
    $article = Article::create(['user_id' => $user->id, 'name' => 'Montre', 'type' => 'variable', 'sale_price' => 0, 'quantity' => 0]);
    Variation::create(['article_id' => $article->id, 'name' => 'Noire', 'quantity' => 5, 'price' => 20000]);
    ArticleStockService::syncVariableTotals($article);

    expect($article->fresh()->quantity)->toBe(5);

    $response = $this->actingAs($user)->postJson('/api/variations', [
        'article_id' => $article->id,
        'name' => 'Blanche',
        // Volontairement une quantité qui aurait dépassé l'ancien plafond
        // (article.quantity valait 5 avant cet ajout).
        'quantity' => 50,
        'price' => 20000,
    ]);

    $response->assertStatus(201);
    expect($article->fresh()->quantity)->toBe(55);
});

// =============================================================================
// C. Ventes
// =============================================================================

test('vendre une variation ne décrémente que celle-ci et le total se resynchronise', function () {
    $user = makeVariationsTestUser();
    $article = Article::create(['user_id' => $user->id, 'name' => 'Pagne', 'type' => 'variable', 'sale_price' => 0, 'quantity' => 0]);
    $v1 = Variation::create(['article_id' => $article->id, 'name' => 'Taille M', 'quantity' => 10, 'price' => 15000]);
    $v2 = Variation::create(['article_id' => $article->id, 'name' => 'Taille L', 'quantity' => 8, 'price' => 15000]);
    ArticleStockService::syncVariableTotals($article);

    $response = $this->actingAs($user)->postJson('/api/transactions', [
        'type' => 'sale',
        'article_id' => $article->id,
        'variable_id' => $v1->id,
        'quantity' => 3,
    ]);

    $response->assertStatus(201);
    expect($v1->fresh()->sold_quantity)->toBe(3);
    expect($v1->fresh()->remaining_quantity)->toBe(7);
    expect($v2->fresh()->sold_quantity)->toBe(0);
    expect($v2->fresh()->remaining_quantity)->toBe(8);
    // Le cache article.quantity (cumul stocké) n'est jamais touché par une vente.
    expect($article->fresh()->quantity)->toBe(18);
});

test('vendre un article variable sans variable_id est refusé (422)', function () {
    $user = makeVariationsTestUser();
    $article = Article::create(['user_id' => $user->id, 'name' => 'Casquette', 'type' => 'variable', 'sale_price' => 0, 'quantity' => 0]);
    Variation::create(['article_id' => $article->id, 'name' => 'Unique', 'quantity' => 10, 'price' => 5000]);
    ArticleStockService::syncVariableTotals($article);

    $response = $this->actingAs($user)->postJson('/api/transactions', [
        'type' => 'sale',
        'article_id' => $article->id,
        'quantity' => 1,
    ]);

    $response->assertStatus(422);
    // Code exploité par le frontend (lib/api.ts) pour afficher globalement
    // "Mettez à jour l'application" + bouton Recharger à un client obsolète.
    expect($response->json('code'))->toBe('STALE_CLIENT_MISSING_VARIATION');
    expect(Transaction::count())->toBe(0);
});

test('vendre exactement le stock disponible d\'une variation la vide complètement', function () {
    $user = makeVariationsTestUser();
    $article = Article::create(['user_id' => $user->id, 'name' => 'Bijou', 'type' => 'variable', 'sale_price' => 0, 'quantity' => 0]);
    $variation = Variation::create(['article_id' => $article->id, 'name' => 'Or', 'quantity' => 2, 'price' => 30000]);
    ArticleStockService::syncVariableTotals($article);

    $response = $this->actingAs($user)->postJson('/api/transactions', [
        'type' => 'sale', 'article_id' => $article->id, 'variable_id' => $variation->id, 'quantity' => 2,
    ]);

    $response->assertStatus(201);
    expect($variation->fresh()->remaining_quantity)->toBe(0);
});

test('vendre une unité de plus que le restant d\'une variation échoue proprement, sans planter', function () {
    $user = makeVariationsTestUser();
    $article = Article::create(['user_id' => $user->id, 'name' => 'Bijou', 'type' => 'variable', 'sale_price' => 0, 'quantity' => 0]);
    $variation = Variation::create(['article_id' => $article->id, 'name' => 'Or', 'quantity' => 2, 'price' => 30000]);
    ArticleStockService::syncVariableTotals($article);

    $this->actingAs($user)->postJson('/api/transactions', [
        'type' => 'sale', 'article_id' => $article->id, 'variable_id' => $variation->id, 'quantity' => 2,
    ])->assertStatus(201);

    // Une unité de plus doit être refusée avec un message clair (pas un 500).
    $second = $this->actingAs($user)->postJson('/api/transactions', [
        'type' => 'sale', 'article_id' => $article->id, 'variable_id' => $variation->id, 'quantity' => 1,
    ]);
    $second->assertStatus(400);
    expect($second->json('message'))->toContain('Stock insuffisant');
    expect(Transaction::where('type', 'sale')->count())->toBe(1);
});

// =============================================================================
// D. Suppression / archivage d'une variation
// =============================================================================

test('archiver une variation avec du stock restant est refusé sans confirmation', function () {
    $user = makeVariationsTestUser();
    $article = Article::create(['user_id' => $user->id, 'name' => 'Robe', 'type' => 'variable', 'sale_price' => 0, 'quantity' => 0]);
    $v1 = Variation::create(['article_id' => $article->id, 'name' => 'Rouge', 'quantity' => 5, 'price' => 25000]);
    Variation::create(['article_id' => $article->id, 'name' => 'Bleue', 'quantity' => 5, 'price' => 25000]);
    ArticleStockService::syncVariableTotals($article);
    // Aucune vente : remaining_quantity = 5 > 0.

    $response = $this->actingAs($user)->deleteJson("/api/variations/{$v1->id}");

    $response->assertStatus(422);
    expect($response->json('message'))->toBe("Cette variation contient encore 5 unité(s). Videz le stock ou confirmez l'archivage.");
    expect(Variation::find($v1->id))->not->toBeNull();
});

test('archiver une variation avec du stock restant réussit avec confirm=true', function () {
    $user = makeVariationsTestUser();
    $article = Article::create(['user_id' => $user->id, 'name' => 'Robe', 'type' => 'variable', 'sale_price' => 0, 'quantity' => 0]);
    $v1 = Variation::create(['article_id' => $article->id, 'name' => 'Rouge', 'quantity' => 5, 'price' => 25000]);
    Variation::create(['article_id' => $article->id, 'name' => 'Bleue', 'quantity' => 5, 'price' => 25000]);
    ArticleStockService::syncVariableTotals($article);

    $response = $this->actingAs($user)->deleteJson("/api/variations/{$v1->id}", ['confirm' => true]);

    $response->assertStatus(200);
    expect(Variation::find($v1->id))->toBeNull();
});

test('archiver (soft delete) une variation qui a des ventes liées, jamais une suppression réelle', function () {
    $user = makeVariationsTestUser();
    $article = Article::create(['user_id' => $user->id, 'name' => 'Robe', 'type' => 'variable', 'sale_price' => 0, 'quantity' => 0]);
    $v1 = Variation::create(['article_id' => $article->id, 'name' => 'Rouge', 'quantity' => 5, 'price' => 25000]);
    Variation::create(['article_id' => $article->id, 'name' => 'Bleue', 'quantity' => 5, 'price' => 25000]);
    ArticleStockService::syncVariableTotals($article);

    Transaction::create([
        'user_id' => $user->id, 'article_id' => $article->id, 'variable_id' => $v1->id,
        'name' => 'vente', 'quantity' => 2, 'amount' => 50000, 'sale_price' => 25000, 'type' => 'sale',
    ]);
    // remaining_quantity = 5 - 2 = 3 > 0 : confirmation requise.

    $response = $this->actingAs($user)->deleteJson("/api/variations/{$v1->id}", ['confirm' => true]);

    $response->assertStatus(200);
    expect(Variation::find($v1->id))->toBeNull(); // exclue des requêtes normales
    expect(Variation::withTrashed()->find($v1->id))->not->toBeNull(); // mais pas supprimée
    expect(Variation::withTrashed()->find($v1->id)->deleted_at)->not->toBeNull();
    // La vente historique reste intacte.
    expect(Transaction::where('variable_id', $v1->id)->count())->toBe(1);
});

test('une variation sans vente ni ligne de facture liée est réellement supprimée (hard delete)', function () {
    $user = makeVariationsTestUser();
    $article = Article::create(['user_id' => $user->id, 'name' => 'Ceinture', 'type' => 'variable', 'sale_price' => 0, 'quantity' => 0]);
    $v1 = Variation::create(['article_id' => $article->id, 'name' => 'Noire', 'quantity' => 5, 'price' => 8000]);
    Variation::create(['article_id' => $article->id, 'name' => 'Marron', 'quantity' => 5, 'price' => 8000]);
    ArticleStockService::syncVariableTotals($article);
    // Aucune vente ni ligne de facture sur $v1 : suppression réelle attendue.

    $response = $this->actingAs($user)->deleteJson("/api/variations/{$v1->id}", ['confirm' => true]);

    $response->assertStatus(200);
    expect(Variation::withTrashed()->find($v1->id))->toBeNull(); // vraiment partie, pas juste archivée
});

test('impossible de supprimer la dernière variation active d\'un article', function () {
    $user = makeVariationsTestUser();
    $article = Article::create(['user_id' => $user->id, 'name' => 'Collier', 'type' => 'variable', 'sale_price' => 0, 'quantity' => 0]);
    $onlyVariation = Variation::create(['article_id' => $article->id, 'name' => 'Unique', 'quantity' => 5, 'price' => 10000]);
    ArticleStockService::syncVariableTotals($article);

    $response = $this->actingAs($user)->deleteJson("/api/variations/{$onlyVariation->id}", ['confirm' => true]);

    $response->assertStatus(422);
    expect(Variation::find($onlyVariation->id))->not->toBeNull();
});

// =============================================================================
// E. Réapprovisionnement par variation
// =============================================================================

test('réapprovisionner un article variable sans variation_id est refusé (422)', function () {
    $user = makeVariationsTestUser();
    $article = Article::create(['user_id' => $user->id, 'name' => 'Sneakers', 'type' => 'variable', 'sale_price' => 0, 'quantity' => 0]);
    Variation::create(['article_id' => $article->id, 'name' => '41', 'quantity' => 5, 'price' => 30000]);
    ArticleStockService::syncVariableTotals($article);

    $response = $this->actingAs($user)->postJson("/api/articles/{$article->id}/add-stock", ['quantity' => 5]);

    $response->assertStatus(422);
});

test('réapprovisionner une variation précise incrémente seulement celle-ci', function () {
    $user = makeVariationsTestUser();
    $article = Article::create(['user_id' => $user->id, 'name' => 'Sneakers', 'type' => 'variable', 'sale_price' => 0, 'quantity' => 0]);
    $v1 = Variation::create(['article_id' => $article->id, 'name' => '41', 'quantity' => 5, 'price' => 30000]);
    $v2 = Variation::create(['article_id' => $article->id, 'name' => '42', 'quantity' => 5, 'price' => 30000]);
    ArticleStockService::syncVariableTotals($article);

    $response = $this->actingAs($user)->postJson("/api/articles/{$article->id}/add-stock", [
        'quantity' => 5,
        'variation_id' => $v1->id,
    ]);

    $response->assertStatus(200);
    expect($v1->fresh()->quantity)->toBe(10);
    expect($v2->fresh()->quantity)->toBe(5);
    expect($article->fresh()->quantity)->toBe(15);
    expect(StockReplenishment::where('variation_id', $v1->id)->count())->toBe(1);
});

// =============================================================================
// F. Facture avec variation
// =============================================================================

test('créer une ligne de facture sur un article variable sans variation_id est refusé (422)', function () {
    $user = makeVariationsTestUser();
    $client = makeInvoiceReadyCompanyAndClient($user);
    $article = Article::create(['user_id' => $user->id, 'name' => 'Veste', 'type' => 'variable', 'sale_price' => 0, 'quantity' => 0]);
    Variation::create(['article_id' => $article->id, 'name' => 'M', 'quantity' => 10, 'price' => 45000]);
    ArticleStockService::syncVariableTotals($article);

    $response = $this->actingAs($user)->postJson('/api/invoices', [
        'client_id' => $client->id,
        'items' => [['article_id' => $article->id, 'quantity' => 2]],
        'theme' => 'classic',
        'due_date' => now()->addDays(10)->toDateString(),
        'status' => 'unpaid',
    ]);

    $response->assertStatus(422);
});

test('créer puis payer une facture avec une ligne sur une variation décrémente la bonne variation', function () {
    $user = makeVariationsTestUser();
    $client = makeInvoiceReadyCompanyAndClient($user);
    $article = Article::create(['user_id' => $user->id, 'name' => 'Veste', 'type' => 'variable', 'sale_price' => 0, 'quantity' => 0]);
    $variation = Variation::create(['article_id' => $article->id, 'name' => 'M', 'quantity' => 10, 'price' => 45000]);
    ArticleStockService::syncVariableTotals($article);

    $response = $this->actingAs($user)->postJson('/api/invoices', [
        'client_id' => $client->id,
        'items' => [['article_id' => $article->id, 'variation_id' => $variation->id, 'quantity' => 2]],
        'theme' => 'classic',
        'due_date' => now()->addDays(10)->toDateString(),
        'status' => 'unpaid',
    ]);

    $response->assertStatus(201);
    $item = InvoiceItem::where('article_id', $article->id)->first();
    expect($item->variation_id)->toBe($variation->id);
    // Pas encore d'impact stock à la création (statut "unpaid", pas "paid").
    expect($variation->fresh()->sold_quantity)->toBe(0);

    $invoiceId = $response->json('data.id');
    $payResponse = $this->actingAs($user)->patchJson("/api/invoices/{$invoiceId}/status", ['status' => 'paid']);
    $payResponse->assertStatus(200);

    expect($variation->fresh()->sold_quantity)->toBe(2);
    $transaction = Transaction::where('invoice_id', $invoiceId)->first();
    expect($transaction->variable_id)->toBe($variation->id);
});

// =============================================================================
// G. Backfill — idempotence
// =============================================================================

test('le backfill est idempotent : relancer --force une seconde fois ne change plus rien', function () {
    $user = makeVariationsTestUser();

    // Simule une donnée héritée : variation sans prix, écart non assigné.
    // Insertion via le query builder brut (pas Eloquent::create) pour ne PAS
    // déclencher l'observer de Variation, qui resynchroniserait article.quantity
    // immédiatement et effacerait l'écart qu'on veut précisément faire tester
    // au backfill (cet écart n'existe en pratique que sur des données
    // créées AVANT l'observer, jamais via l'API actuelle).
    $articleId = (string) Str::uuid();
    DB::table('articles')->insert([
        'id' => $articleId, 'user_id' => $user->id, 'name' => 'Legacy', 'type' => 'variable',
        'sale_price' => 7000, 'quantity' => 20, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('variations')->insert([
        'id' => (string) Str::uuid(), 'article_id' => $articleId, 'name' => 'Std', 'quantity' => 15,
        'price' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $article = Article::find($articleId);

    $this->artisan('variations:backfill', ['--force' => true])->assertSuccessful();

    $afterFirst = [
        'article_quantity' => $article->fresh()->quantity,
        'variation_count' => Variation::where('article_id', $article->id)->count(),
        'variation_price' => Variation::where('article_id', $article->id)->pluck('price')->sort()->values()->all(),
    ];

    expect($afterFirst['article_quantity'])->toBe(20);
    expect($afterFirst['variation_count'])->toBe(2); // Std + "Sans précision" (écart de 5)

    $this->artisan('variations:backfill', ['--force' => true])->assertSuccessful();

    $afterSecond = [
        'article_quantity' => $article->fresh()->quantity,
        'variation_count' => Variation::where('article_id', $article->id)->count(),
        'variation_price' => Variation::where('article_id', $article->id)->pluck('price')->sort()->values()->all(),
    ];

    expect($afterSecond)->toBe($afterFirst);
});

// =============================================================================
// H. stock:fix-negative ne casse plus la synchronisation
// =============================================================================

test('stock:fix-negative corrige une variation en négatif sans désynchroniser l\'article', function () {
    $user = makeVariationsTestUser();
    $article = Article::create(['user_id' => $user->id, 'name' => 'Oversold', 'type' => 'variable', 'sale_price' => 0, 'quantity' => 0]);
    $v1 = Variation::create(['article_id' => $article->id, 'name' => 'V1', 'quantity' => 5, 'price' => 1000]);
    $v2 = Variation::create(['article_id' => $article->id, 'name' => 'V2', 'quantity' => 5, 'price' => 1000]);
    ArticleStockService::syncVariableTotals($article);

    Transaction::create([
        'user_id' => $user->id, 'article_id' => $article->id, 'variable_id' => $v1->id,
        'name' => 'oversell', 'quantity' => 8, 'amount' => 8000, 'sale_price' => 1000, 'type' => 'sale',
    ]);

    expect($v1->fresh()->remaining_quantity)->toBe(-3);

    $this->artisan('stock:fix-negative', ['--force' => true])->assertSuccessful();

    expect($v1->fresh()->quantity)->toBe(8);
    expect($v1->fresh()->remaining_quantity)->toBe(0);
    expect($v2->fresh()->quantity)->toBe(5); // non touchée
    expect($article->fresh()->quantity)->toBe($v1->fresh()->quantity + $v2->fresh()->quantity);
});
