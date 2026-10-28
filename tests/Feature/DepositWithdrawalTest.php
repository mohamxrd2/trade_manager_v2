<?php

use App\Models\Collaborator;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Schéma minimal, compatible SQLite ET Postgres réel (même principe que
| TransactionDeletionTest.php / TransactionIdempotencyTest.php) — colonnes
| `string`/`text` nues, SANS contrainte CHECK : la contrainte Postgres réelle
| (transactions_type_check) est un concern de déploiement, pas de ce test
| (RefreshDatabase désactivé partout, voir tests/Pest.php — aucun test ne
| rejoue jamais les vraies migrations).
|--------------------------------------------------------------------------
*/
function createDepositWithdrawalTestSchema(): void
{
    // Liste volontairement plus large que les seules tables utilisées par
    // CE fichier : sur Postgres réel (phpunit.pgsql.xml), la base
    // "waelly_test" est partagée et PERSISTANTE entre tous les fichiers de
    // test d'une même exécution (contrairement à SQLite ":memory:", qui
    // repart à vide à chaque beforeEach) — sans ça, une table laissée par un
    // fichier voisin exécuté juste avant (ex. CollaboratorShareTest crée
    // aussi `articles`/`notifications`) fait échouer le dropIfExists('users')
    // ci-dessous (clé étrangère pointant encore dessus). Reprend l'union des
    // tables de tous les tests Feature existants (voir
    // VariableArticlesOverhaulTest.php, le plus complet) + `collaborators`.
    foreach ([
        'activity_logs', 'notifications', 'stock_replenishments', 'invoice_items', 'invoices',
        'clients', 'companies', 'variations', 'articles', 'collaborators',
        'transactions', 'operations', 'operation_counters', 'user_settings', 'users',
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
        $table->string('language')->default('fr');
        $table->timestamps();
    });

    Schema::create('collaborators', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->string('name');
        $table->string('phone', 20);
        $table->decimal('part', 5, 2);
        $table->decimal('wallet', 15, 2)->default(0);
        $table->string('image')->nullable();
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
        $table->text('note')->nullable();
        $table->string('payment_method')->nullable();
        $table->uuid('operation_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    // ActivityLogger::log() (chantier "Équipe") est appelé par
    // TransactionController::destroy()/restore().
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
}

beforeEach(function () {
    config(['database.connections.sqlite.database' => ':memory:']);
    DB::purge('sqlite');
    createDepositWithdrawalTestSchema();
});

function makeDepositTestUser(array $overrides = []): User
{
    return User::create(array_merge([
        'first_name' => 'Test',
        'last_name' => 'User',
        'username' => 'user_' . Str::random(8),
        'email' => Str::random(8) . '@example.test',
        'password' => 'irrelevant-for-this-test',
    ], $overrides));
}

function makeDepositTestCollaborator(User $user, array $overrides = []): Collaborator
{
    return Collaborator::create(array_merge([
        'user_id' => $user->id,
        'name' => 'Awa',
        'phone' => '0700000000',
        'part' => 20,
    ], $overrides));
}

// =============================================================================
// Règles de calcul (section 3 de la spécification)
// =============================================================================

test('un apport augmente balance ET wallet (décision du 26/10/2026), mais ne touche ni total_sale ni calculated_wallet', function () {
    $user = makeDepositTestUser(); // company_share par défaut = 100 %

    Transaction::create(['user_id' => $user->id, 'type' => 'sale', 'name' => 'Vente', 'amount' => 50000]);
    Transaction::create(['user_id' => $user->id, 'type' => 'expense', 'name' => 'Loyer', 'amount' => 20000]);

    $user->refresh();
    $balanceBefore = (float) $user->balance;
    $calculatedWalletBefore = (float) $user->calculated_wallet;
    $totalSaleBefore = (float) $user->total_sale;

    expect($balanceBefore)->toBe(30000.0);
    // company_share = 100 % par défaut : wallet == balance exactement.
    expect((float) $user->wallet)->toBe($balanceBefore);

    $response = $this->actingAs($user)->postJson('/api/transactions', [
        'type' => 'apport',
        'amount' => 100000,
        'funding_source' => 'apport_personnel',
        'payment_method' => 'especes',
    ]);

    $response->assertStatus(201);
    expect($response->json('message'))->toBe('Apport enregistré avec succès');

    $user->refresh();
    expect((float) $user->balance)->toBe($balanceBefore + 100000);
    expect((float) $user->total_deposit)->toBe(100000.0);
    expect((float) $user->calculated_wallet)->toBe($calculatedWalletBefore);
    expect((float) $user->total_sale)->toBe($totalSaleBefore);
    // wallet suit désormais balance (pas calculated_wallet) : un apport
    // augmente donc aussi le montant du gérant, selon sa part.
    expect((float) $user->wallet)->toBe($balanceBefore + 100000);
});

test('un retrait diminue balance mais ne touche pas calculated_wallet (le bénéfice)', function () {
    $user = makeDepositTestUser();
    Transaction::create(['user_id' => $user->id, 'type' => 'sale', 'name' => 'Vente', 'amount' => 50000]);

    $user->refresh();
    $calculatedWalletBefore = (float) $user->calculated_wallet;

    $response = $this->actingAs($user)->postJson('/api/transactions', [
        'type' => 'retrait',
        'amount' => 30000,
        'payment_method' => 'especes',
    ]);

    $response->assertStatus(201);
    expect($response->json('message'))->toBe('Retrait enregistré avec succès');

    $user->refresh();
    expect((float) $user->balance)->toBe(50000.0 - 30000.0);
    expect((float) $user->total_withdrawal)->toBe(30000.0);
    expect((float) $user->calculated_wallet)->toBe($calculatedWalletBefore);
});

test('les analyses (chiffre d\'affaires, bénéfice) ignorent apports et retraits', function () {
    $user = makeDepositTestUser();
    Transaction::create(['user_id' => $user->id, 'type' => 'sale', 'name' => 'Vente', 'amount' => 80000]);
    Transaction::create(['user_id' => $user->id, 'type' => 'expense', 'name' => 'Loyer', 'amount' => 10000]);
    Transaction::create(['user_id' => $user->id, 'type' => 'apport', 'name' => 'Apport de fonds', 'amount' => 500000, 'funding_source' => 'apport_personnel']);
    Transaction::create(['user_id' => $user->id, 'type' => 'retrait', 'name' => 'Retrait', 'amount' => 200000]);

    $user->refresh();

    // Mêmes requêtes que AnalyticsController (where('type','sale'/'expense')) :
    // si un apport/retrait y fuitait, ces totaux seraient faux.
    expect((float) $user->total_sale)->toBe(80000.0);
    expect((float) $user->total_expense)->toBe(10000.0);
    expect((float) $user->calculated_wallet)->toBe(70000.0);
    expect((float) $user->balance)->toBe(80000.0 - 10000.0 + 500000.0 - 200000.0);
});

// =============================================================================
// Validation (section 4)
// =============================================================================

test('origine "collaborateur" sans collaborator_id renvoie 422', function () {
    $user = makeDepositTestUser();

    $response = $this->actingAs($user)->postJson('/api/transactions', [
        'type' => 'apport',
        'amount' => 50000,
        'funding_source' => 'collaborateur',
        'payment_method' => 'virement',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['collaborator_id']);
    expect(Transaction::count())->toBe(0);
});

test('un collaborateur appartenant à un AUTRE compte renvoie 422', function () {
    $owner = makeDepositTestUser();
    $otherUser = makeDepositTestUser();
    $otherCollaborator = makeDepositTestCollaborator($otherUser);

    $response = $this->actingAs($owner)->postJson('/api/transactions', [
        'type' => 'apport',
        'amount' => 50000,
        'funding_source' => 'collaborateur',
        'collaborator_id' => $otherCollaborator->id,
        'payment_method' => 'virement',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['collaborator_id']);
    expect(Transaction::count())->toBe(0);
});

test('un apport avec origine "collaborateur" et le bon collaborateur est accepté', function () {
    $user = makeDepositTestUser();
    $collaborator = makeDepositTestCollaborator($user);

    $response = $this->actingAs($user)->postJson('/api/transactions', [
        'type' => 'apport',
        'amount' => 75000,
        'funding_source' => 'collaborateur',
        'collaborator_id' => $collaborator->id,
        'payment_method' => 'wave',
    ]);

    $response->assertStatus(201);
    expect(Transaction::first()->collaborator_id)->toBe($collaborator->id);
});

test('montant manquant ou négatif renvoie 422', function () {
    $user = makeDepositTestUser();

    $this->actingAs($user)->postJson('/api/transactions', [
        'type' => 'apport',
        'funding_source' => 'apport_personnel',
        'payment_method' => 'especes',
    ])->assertStatus(422);

    $this->actingAs($user)->postJson('/api/transactions', [
        'type' => 'apport',
        'amount' => -100,
        'funding_source' => 'apport_personnel',
        'payment_method' => 'especes',
    ])->assertStatus(422);

    expect(Transaction::count())->toBe(0);
});

// =============================================================================
// Idempotence (réutilise la clé existante, section 4)
// =============================================================================

test('un double envoi avec la même Idempotency-Key ne crée qu\'un seul apport', function () {
    $user = makeDepositTestUser();
    $key = (string) Str::uuid();

    $payload = [
        'type' => 'apport',
        'amount' => 100000,
        'funding_source' => 'apport_personnel',
        'payment_method' => 'especes',
    ];

    $first = $this->actingAs($user)
        ->withHeaders(['Idempotency-Key' => $key])
        ->postJson('/api/transactions', $payload);
    $second = $this->actingAs($user)
        ->withHeaders(['Idempotency-Key' => $key])
        ->postJson('/api/transactions', $payload);

    $first->assertStatus(201);
    $second->assertStatus(201);
    expect($first->json('data.id'))->toBe($second->json('data.id'));
    expect(Transaction::where('type', 'apport')->count())->toBe(1);

    $user->refresh();
    expect((float) $user->total_deposit)->toBe(100000.0);
});

// =============================================================================
// Suppression (mêmes règles que les autres transactions)
// =============================================================================

test('supprimer un apport ramène balance à sa valeur d\'avant', function () {
    $user = makeDepositTestUser();
    $user->refresh();
    $balanceBefore = (float) $user->balance;

    $response = $this->actingAs($user)->postJson('/api/transactions', [
        'type' => 'apport',
        'amount' => 120000,
        'funding_source' => 'autre',
        'payment_method' => 'moov_money',
    ]);
    $depositId = $response->json('data.id');

    $user->refresh();
    expect((float) $user->balance)->toBe($balanceBefore + 120000);

    $this->actingAs($user)->deleteJson("/api/transactions/{$depositId}")->assertStatus(200);

    $user->refresh();
    expect((float) $user->balance)->toBe($balanceBefore);
    expect(Transaction::find($depositId))->toBeNull();
    expect(Transaction::withTrashed()->find($depositId))->not->toBeNull();
});

test('supprimer le retrait d\'un autre utilisateur est refusé (403)', function () {
    $owner = makeDepositTestUser();
    $intruder = makeDepositTestUser();

    $withdrawal = Transaction::create([
        'user_id' => $owner->id,
        'type' => 'retrait',
        'name' => 'Retrait',
        'amount' => 40000,
    ]);

    $response = $this->actingAs($intruder)->deleteJson("/api/transactions/{$withdrawal->id}");

    $response->assertStatus(403);
    expect(Transaction::find($withdrawal->id))->not->toBeNull();
});

// =============================================================================
// Garde-fou : un apport/retrait ne se modifie pas
// =============================================================================

test('modifier un apport est refusé (422), il ne peut être que supprimé', function () {
    $user = makeDepositTestUser();
    $deposit = Transaction::create([
        'user_id' => $user->id,
        'type' => 'apport',
        'name' => 'Apport de fonds',
        'amount' => 60000,
        'funding_source' => 'apport_personnel',
    ]);

    $response = $this->actingAs($user)->putJson("/api/transactions/{$deposit->id}", [
        'amount' => 99999,
    ]);

    $response->assertStatus(422);
    expect((float) $deposit->fresh()->amount)->toBe(60000.0);
});
