<?php

use App\Models\Article;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Variation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Schéma minimal, compatible SQLite (même principe que
| TransactionIdempotencyTest.php / CollaboratorShareTest.php). Ajoute
| `deleted_at` sur transactions (migration
| 2026_10_24_190511_add_deleted_at_to_transactions_table) et une table
| `invoices` minimale (seuls id/user_id/invoice_number/status sont lus par
| TransactionController::destroy()).
|--------------------------------------------------------------------------
*/
function createTransactionDeletionTestSchema(): void
{
    foreach (['activity_logs', 'notifications', 'transactions', 'operations', 'operation_counters', 'invoices', 'variations', 'articles', 'user_settings', 'users'] as $table) {
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
        $table->string('language')->default('fr');
        $table->timestamps();
    });

    Schema::create('articles', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->string('name');
        $table->decimal('sale_price', 15, 2);
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
        $table->string('image')->nullable();
        $table->timestamps();
    });

    Schema::create('invoices', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->string('invoice_number')->unique();
        $table->string('status')->default('draft');
        $table->timestamps();
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
        $table->string('funding_source')->nullable();
        $table->uuid('collaborator_id')->nullable();
        $table->string('payment_method')->nullable();
        $table->text('note')->nullable();
        $table->uuid('operation_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
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

    // ActivityLogger::log() (chantier "Équipe") est appelé par
    // TransactionController::destroy()/restore() — table nécessaire dès
    // qu'un test exerce ces deux actions, pas seulement les tests dédiés au
    // journal d'activité.
    Schema::create('activity_logs', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('account_id');
        $table->uuid('user_id');
        $table->string('action');
        $table->string('subject_type')->nullable();
        $table->uuid('subject_id')->nullable();
        $table->json('details')->nullable();
        $table->timestamp('created_at')->useCurrent();
    });
}

beforeEach(function () {
    config(['database.connections.sqlite.database' => ':memory:']);
    DB::purge('sqlite');
    createTransactionDeletionTestSchema();
});

function makeDeletionTestUser(): User
{
    return User::create([
        'first_name' => 'Test',
        'last_name' => 'User',
        'username' => 'user_' . Str::random(8),
        'email' => Str::random(8) . '@example.test',
        'password' => 'irrelevant-for-this-test',
    ]);
}

function makeDeletionTestArticle(User $user, int $quantity = 100): Article
{
    return Article::create([
        'user_id' => $user->id,
        'name' => 'Robe wax',
        'sale_price' => 5000,
        'quantity' => $quantity,
        'type' => 'simple',
    ]);
}

// =============================================================================
// Suppression d'une vente → le stock remonte
// =============================================================================

test('supprimer une vente d\'article simple remonte le stock restant', function () {
    $user = makeDeletionTestUser();
    $article = makeDeletionTestArticle($user, quantity: 100);

    $sale = Transaction::create([
        'user_id' => $user->id,
        'article_id' => $article->id,
        'type' => 'sale',
        'name' => 'Vente de 10 Robe wax',
        'quantity' => 10,
        'amount' => 50000,
        'sale_price' => 5000,
    ]);

    expect($article->fresh()->remaining_quantity)->toBe(90);

    $response = $this->actingAs($user)->deleteJson("/api/transactions/{$sale->id}");

    $response->assertStatus(200);
    expect($article->fresh()->remaining_quantity)->toBe(100);
    expect(Transaction::withTrashed()->find($sale->id)->deleted_at)->not->toBeNull();
    expect(Transaction::find($sale->id))->toBeNull(); // Exclue de l'index par le global scope SoftDeletes.
});

test('supprimer une vente de variation remonte le stock restant de CETTE variation', function () {
    $user = makeDeletionTestUser();
    $article = makeDeletionTestArticle($user, quantity: 0);
    $variation = Variation::create(['article_id' => $article->id, 'name' => 'Taille M', 'quantity' => 20, 'price' => 6000]);

    $sale = Transaction::create([
        'user_id' => $user->id,
        'article_id' => $article->id,
        'variable_id' => $variation->id,
        'type' => 'sale',
        'name' => 'Vente de 2 Robe wax Taille M',
        'quantity' => 2,
        'amount' => 12000,
        'sale_price' => 6000,
    ]);

    expect($variation->fresh()->remaining_quantity)->toBe(18);

    $this->actingAs($user)->deleteJson("/api/transactions/{$sale->id}")->assertStatus(200);

    expect($variation->fresh()->remaining_quantity)->toBe(20);
});

// =============================================================================
// Suppression d'une dépense → le solde remonte
// =============================================================================

test('supprimer une dépense remonte le solde (calculated_wallet)', function () {
    $user = makeDeletionTestUser();

    $expense = Transaction::create([
        'user_id' => $user->id,
        'type' => 'expense',
        'name' => 'Loyer',
        'amount' => 30000,
    ]);

    expect((float) $user->fresh()->calculated_wallet)->toBe(-30000.0);

    $this->actingAs($user)->deleteJson("/api/transactions/{$expense->id}")->assertStatus(200);

    expect((float) $user->fresh()->calculated_wallet)->toBe(0.0);
});

// =============================================================================
// Vente liée à une facture
// =============================================================================

test('supprimer une vente liée à une facture PAYÉE est refusé (422), rien n\'est supprimé', function () {
    $user = makeDeletionTestUser();
    $article = makeDeletionTestArticle($user);
    $invoice = \App\Models\Invoice::create([
        'user_id' => $user->id,
        'invoice_number' => 'INV-2026-00001',
        'status' => 'paid',
    ]);
    $sale = Transaction::create([
        'user_id' => $user->id,
        'article_id' => $article->id,
        'invoice_id' => $invoice->id,
        'type' => 'sale',
        'name' => 'Vente de 1 Robe wax',
        'quantity' => 1,
        'amount' => 5000,
        'sale_price' => 5000,
    ]);

    $response = $this->actingAs($user)->deleteJson("/api/transactions/{$sale->id}");

    $response->assertStatus(422);
    expect($response->json('message'))->toBe('Cette vente est liée à la facture INV-2026-00001. Annulez ou modifiez la facture.');
    expect(Transaction::find($sale->id))->not->toBeNull();
});

test('supprimer une vente liée à une facture NON payée (brouillon) reste autorisé', function () {
    $user = makeDeletionTestUser();
    $article = makeDeletionTestArticle($user);
    $invoice = \App\Models\Invoice::create([
        'user_id' => $user->id,
        'invoice_number' => 'INV-2026-00002',
        'status' => 'draft',
    ]);
    $sale = Transaction::create([
        'user_id' => $user->id,
        'article_id' => $article->id,
        'invoice_id' => $invoice->id,
        'type' => 'sale',
        'name' => 'Vente de 1 Robe wax',
        'quantity' => 1,
        'amount' => 5000,
        'sale_price' => 5000,
    ]);

    $this->actingAs($user)->deleteJson("/api/transactions/{$sale->id}")->assertStatus(200);

    expect(Transaction::find($sale->id))->toBeNull();
});

// =============================================================================
// Permissions
// =============================================================================

test('supprimer la transaction d\'un autre utilisateur est refusé (403), rien n\'est supprimé', function () {
    $owner = makeDeletionTestUser();
    $intruder = makeDeletionTestUser();
    $article = makeDeletionTestArticle($owner);
    $sale = Transaction::create([
        'user_id' => $owner->id,
        'article_id' => $article->id,
        'type' => 'sale',
        'name' => 'Vente de 1 Robe wax',
        'quantity' => 1,
        'amount' => 5000,
        'sale_price' => 5000,
    ]);

    $response = $this->actingAs($intruder)->deleteJson("/api/transactions/{$sale->id}");

    $response->assertStatus(403);
    expect(Transaction::find($sale->id))->not->toBeNull();
});

test('supprimer une transaction inexistante renvoie 404', function () {
    $user = makeDeletionTestUser();

    $this->actingAs($user)->deleteJson('/api/transactions/' . (string) Str::uuid())->assertStatus(404);
});

// =============================================================================
// Restauration
// =============================================================================

test('restaurer une vente supprimée redescend le stock restant et réapparaît dans l\'index', function () {
    $user = makeDeletionTestUser();
    $article = makeDeletionTestArticle($user, quantity: 100);
    $sale = Transaction::create([
        'user_id' => $user->id,
        'article_id' => $article->id,
        'type' => 'sale',
        'name' => 'Vente de 10 Robe wax',
        'quantity' => 10,
        'amount' => 50000,
        'sale_price' => 5000,
    ]);

    $this->actingAs($user)->deleteJson("/api/transactions/{$sale->id}")->assertStatus(200);
    expect($article->fresh()->remaining_quantity)->toBe(100);

    $response = $this->actingAs($user)->postJson("/api/transactions/{$sale->id}/restore");

    $response->assertStatus(200);
    expect($article->fresh()->remaining_quantity)->toBe(90);
    expect(Transaction::find($sale->id))->not->toBeNull();
    expect(Transaction::find($sale->id)->deleted_at)->toBeNull();
});

test('restaurer la transaction supprimée d\'un autre utilisateur est refusé (403)', function () {
    $owner = makeDeletionTestUser();
    $intruder = makeDeletionTestUser();
    $article = makeDeletionTestArticle($owner);
    $sale = Transaction::create([
        'user_id' => $owner->id,
        'article_id' => $article->id,
        'type' => 'sale',
        'name' => 'Vente de 1 Robe wax',
        'quantity' => 1,
        'amount' => 5000,
        'sale_price' => 5000,
    ]);
    $this->actingAs($owner)->deleteJson("/api/transactions/{$sale->id}")->assertStatus(200);

    $response = $this->actingAs($intruder)->postJson("/api/transactions/{$sale->id}/restore");

    $response->assertStatus(403);
    expect(Transaction::withTrashed()->find($sale->id)->deleted_at)->not->toBeNull();
});
