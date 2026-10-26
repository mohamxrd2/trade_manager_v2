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
| Schéma minimal, compatible SQLite (voir tests/Feature/VariableArticlesOverhaulTest.php
| pour le même principe et pourquoi RefreshDatabase ne peut pas être utilisé
| ici). Nom de fonction distinct pour éviter toute redéclaration.
|--------------------------------------------------------------------------
*/
function createCollaboratorShareTestSchema(): void
{
    foreach (['notifications', 'user_settings', 'collaborators', 'transactions', 'articles', 'users'] as $table) {
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

    Schema::create('collaborators', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->string('name');
        $table->string('phone', 20);
        $table->decimal('part', 5, 2);
        $table->string('image')->nullable();
        $table->timestamps();
    });

    // articles/transactions restent vides dans ces tests, mais doivent
    // exister : sérialiser un Collaborator inclut son `user` imbriqué, et
    // User::$appends (total_articles, total_stock_value, calculated_wallet...)
    // interroge ces deux tables à chaque sérialisation, pas seulement quand
    // on accède explicitement à l'accesseur.
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

    Schema::create('user_settings', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id')->unique();
        $table->string('currency')->default('FCFA');
        $table->integer('low_stock_threshold')->default(80);
        $table->boolean('notify_team_joined')->default(true);
        $table->string('language')->default('fr');
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
        $table->timestamps();
        $table->softDeletes();
    });
}

beforeEach(function () {
    config(['database.connections.sqlite.database' => ':memory:']);
    DB::purge('sqlite');
    createCollaboratorShareTestSchema();
});

function makeShareTestUser(float $companyShare = 100.00): User
{
    return User::create([
        'first_name' => 'Test',
        'last_name' => 'User',
        'username' => 'user_' . Str::random(8),
        'email' => Str::random(8) . '@example.test',
        'password' => 'irrelevant-for-this-test',
        'company_share' => $companyShare,
    ]);
}

// =============================================================================
// store() — validation sur la somme réelle des parts
// =============================================================================

test('créer un collaborateur à 25 % est accepté et décrémente company_share de 25', function () {
    $user = makeShareTestUser();

    $response = $this->actingAs($user)->postJson('/api/collaborators', [
        'name' => 'Awa', 'phone' => '+2250700000001', 'part' => 25,
    ]);

    $response->assertStatus(201);
    expect((float) $user->fresh()->company_share)->toBe(75.0);
});

test('créer un collaborateur qui dépasse 100 % au total est refusé (422)', function () {
    $user = makeShareTestUser();
    Collaborator::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'name' => 'Awa', 'phone' => '+2250700000001', 'part' => 70]);

    $response = $this->actingAs($user)->postJson('/api/collaborators', [
        'name' => 'Koffi', 'phone' => '+2250700000002', 'part' => 40,
    ]);

    $response->assertStatus(422);
    expect($response->json('message'))->toBe('Le total des parts dépasse 100 %');
});

test('la validation de store() se base sur la somme réelle des parts, pas sur company_share désynchronisé', function () {
    // company_share volontairement désynchronisé (devrait valoir 70, vaut 100)
    // — simule exactement la dérive que collaborators:audit doit détecter.
    $user = makeShareTestUser(100.00);
    Collaborator::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'name' => 'Awa', 'phone' => '+2250700000001', 'part' => 30]);

    // Avec l'ancienne validation (basée sur company_share=100), 75 serait
    // accepté à tort (30+75=105 > 100). La validation basée sur la somme
    // réelle doit le refuser.
    $response = $this->actingAs($user)->postJson('/api/collaborators', [
        'name' => 'Koffi', 'phone' => '+2250700000002', 'part' => 75,
    ]);

    $response->assertStatus(422);
});

// =============================================================================
// update() — modification de part autorisée, validée sur la somme réelle,
// company_share ajusté de la différence.
// =============================================================================

test('modifier la part de 25 % à 30 % est accepté si le reste le permet, et ajuste company_share de la différence', function () {
    $user = makeShareTestUser(75.00); // 25 % déjà pris par le collaborateur ci-dessous
    $collaborator = Collaborator::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'name' => 'Awa', 'phone' => '+2250700000001', 'part' => 25]);

    $response = $this->actingAs($user)->putJson("/api/collaborators/{$collaborator->id}", [
        'part' => 30,
    ]);

    $response->assertStatus(200);
    expect((float) $collaborator->fresh()->part)->toBe(30.0);
    // company_share était 75 (100-25) ; la part augmente de 5 ⇒ company_share diminue de 5.
    expect((float) $user->fresh()->company_share)->toBe(70.0);
});

test('modifier la part de 25 % à 80 % est refusé (422) quand ça dépasserait 100 % avec les autres', function () {
    $user = makeShareTestUser(50.00); // 25 (ce collaborateur) + 25 (un autre) = 50 pris, 50 restant
    $collaborator = Collaborator::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'name' => 'Awa', 'phone' => '+2250700000001', 'part' => 25]);
    Collaborator::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'name' => 'Koffi', 'phone' => '+2250700000002', 'part' => 25]);

    // 80 (Awa) + 25 (Koffi) = 105 > 100 ⇒ doit être refusé.
    $response = $this->actingAs($user)->putJson("/api/collaborators/{$collaborator->id}", [
        'part' => 80,
    ]);

    $response->assertStatus(422);
    expect($response->json('message'))->toBe('Le total des parts dépasse 100 %');
    // Rien n'a changé : ni la part, ni company_share.
    expect((float) $collaborator->fresh()->part)->toBe(25.0);
    expect((float) $user->fresh()->company_share)->toBe(50.0);
});

test('modifier seulement le nom (sans part) fonctionne toujours normalement, company_share inchangé', function () {
    $user = makeShareTestUser(75.00);
    $collaborator = Collaborator::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'name' => 'Awa', 'phone' => '+2250700000001', 'part' => 25]);

    $response = $this->actingAs($user)->putJson("/api/collaborators/{$collaborator->id}", [
        'name' => 'Awa Koné',
    ]);

    $response->assertStatus(200);
    expect($collaborator->fresh()->name)->toBe('Awa Koné');
    expect((float) $collaborator->fresh()->part)->toBe(25.0);
    expect((float) $user->fresh()->company_share)->toBe(75.0);
});

test('company_share reste cohérent (100 − somme des parts) après create, update et delete enchaînés', function () {
    $user = makeShareTestUser(100.00);

    $c1 = $this->actingAs($user)->postJson('/api/collaborators', ['name' => 'Awa', 'phone' => '+2250700000001', 'part' => 20])->json('data');
    $c2 = $this->actingAs($user)->postJson('/api/collaborators', ['name' => 'Koffi', 'phone' => '+2250700000002', 'part' => 15])->json('data');

    $sumParts = fn () => (float) Collaborator::where('user_id', $user->id)->sum('part');
    expect((float) $user->fresh()->company_share)->toBe(100 - $sumParts());

    $this->actingAs($user)->putJson("/api/collaborators/{$c1['id']}", ['part' => 35])->assertStatus(200);
    expect((float) $user->fresh()->company_share)->toBe(100 - $sumParts());

    $this->actingAs($user)->deleteJson("/api/collaborators/{$c2['id']}")->assertStatus(200);
    expect((float) $user->fresh()->company_share)->toBe(100 - $sumParts());
});

// =============================================================================
// Montants basés sur le SOLDE, pas sur calculated_wallet — décision métier
// du 26/10/2026 : un apport augmente le montant de TOUS les membres (gérant
// compris) selon leur part, un retrait les diminue. Le chiffre d'affaires et
// le bénéfice (total_sale, calculated_wallet) restent, eux, inchangés.
// =============================================================================

test('un apport de fonds augmente les montants du gérant ET du collaborateur selon leur part', function () {
    $user = makeShareTestUser(75.00); // 25 % déjà pris par le collaborateur ci-dessous
    $collaborator = Collaborator::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'name' => 'Awa', 'phone' => '+2250700000001', 'part' => 25]);

    Transaction::create(['user_id' => $user->id, 'type' => 'sale', 'name' => 'Vente', 'amount' => 300000]);
    Transaction::create(['user_id' => $user->id, 'type' => 'expense', 'name' => 'Loyer', 'amount' => 50000]);

    $user->refresh();
    expect((float) $user->balance)->toBe(250000.0);
    expect((float) $user->calculated_wallet)->toBe(250000.0);
    expect((float) $collaborator->fresh()->wallet)->toBe(62500.0); // 250 000 × 25 %
    expect((float) $user->wallet)->toBe(187500.0); // 250 000 × 75 %

    Transaction::create(['user_id' => $user->id, 'type' => 'apport', 'name' => 'Apport de fonds', 'amount' => 100000]);

    $user->refresh();
    expect((float) $user->balance)->toBe(350000.0);
    // Le chiffre d'affaires/bénéfice n'inclut toujours pas l'apport.
    expect((float) $user->total_sale)->toBe(300000.0);
    expect((float) $user->calculated_wallet)->toBe(250000.0);
    // Mais les montants partagés, oui : base = solde = 350 000.
    expect((float) $collaborator->fresh()->wallet)->toBe(87500.0); // 350 000 × 25 %
    expect((float) $user->wallet)->toBe(262500.0); // 350 000 × 75 %
});

test('un retrait diminue ensuite les montants du gérant ET du collaborateur', function () {
    $user = makeShareTestUser(75.00);
    $collaborator = Collaborator::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'name' => 'Awa', 'phone' => '+2250700000001', 'part' => 25]);

    Transaction::create(['user_id' => $user->id, 'type' => 'sale', 'name' => 'Vente', 'amount' => 300000]);
    Transaction::create(['user_id' => $user->id, 'type' => 'expense', 'name' => 'Loyer', 'amount' => 50000]);
    Transaction::create(['user_id' => $user->id, 'type' => 'apport', 'name' => 'Apport de fonds', 'amount' => 100000]);

    $user->refresh();
    expect((float) $user->balance)->toBe(350000.0);

    Transaction::create(['user_id' => $user->id, 'type' => 'retrait', 'name' => 'Retrait', 'amount' => 50000]);

    $user->refresh();
    expect((float) $user->balance)->toBe(300000.0);
    expect((float) $collaborator->fresh()->wallet)->toBe(75000.0); // 300 000 × 25 %
    expect((float) $user->wallet)->toBe(225000.0); // 300 000 × 75 %
    // Toujours inchangé : un retrait n'est pas une dépense.
    expect((float) $user->calculated_wallet)->toBe(250000.0);
});

test('supprimer l\'apport ramène les montants calculés sur la base sans apport (ventes − dépenses)', function () {
    $user = makeShareTestUser(75.00);
    $collaborator = Collaborator::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'name' => 'Awa', 'phone' => '+2250700000001', 'part' => 25]);

    Transaction::create(['user_id' => $user->id, 'type' => 'sale', 'name' => 'Vente', 'amount' => 300000]);
    Transaction::create(['user_id' => $user->id, 'type' => 'expense', 'name' => 'Loyer', 'amount' => 50000]);
    $deposit = Transaction::create(['user_id' => $user->id, 'type' => 'apport', 'name' => 'Apport de fonds', 'amount' => 100000]);

    $user->refresh();
    expect((float) $user->balance)->toBe(350000.0);
    expect((float) $collaborator->fresh()->wallet)->toBe(87500.0);

    $deposit->delete(); // soft delete — mêmes règles que n'importe quelle transaction

    $user->refresh();
    expect((float) $user->balance)->toBe(250000.0);
    expect((float) $collaborator->fresh()->wallet)->toBe(62500.0); // retour à 250 000 × 25 %
    expect((float) $user->wallet)->toBe(187500.0); // retour à 250 000 × 75 %
});

test('la somme des montants (gérant + collaborateurs) est toujours EXACTEMENT égale au solde, arrondis absorbés par le gérant', function () {
    // Pourcentages volontairement non ronds pour forcer un vrai arrondi :
    // 33,33 % + 33,33 % = 66,66 % pris par les collaborateurs, 33,34 %
    // restant au gérant (jamais 1/3 pile — la part du gérant absorbe déjà le
    // reste au niveau du POURCENTAGE, voir CollaboratorController).
    $user = makeShareTestUser(33.34);
    $c1 = Collaborator::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'name' => 'Awa', 'phone' => '+2250700000001', 'part' => 33.33]);
    $c2 = Collaborator::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'name' => 'Koffi', 'phone' => '+2250700000002', 'part' => 33.33]);

    Transaction::create(['user_id' => $user->id, 'type' => 'sale', 'name' => 'Vente', 'amount' => 100]);

    $user->refresh();
    expect((float) $user->balance)->toBe(100.0);

    $sum = (float) $user->wallet + (float) $c1->fresh()->wallet + (float) $c2->fresh()->wallet;
    expect(round($sum, 2))->toBe(100.0);
});

test('backend : User::wallet = balance × company_share / 100', function () {
    $user = makeShareTestUser(40.00);
    Transaction::create(['user_id' => $user->id, 'type' => 'sale', 'name' => 'Vente', 'amount' => 500000]);
    Transaction::create(['user_id' => $user->id, 'type' => 'apport', 'name' => 'Apport de fonds', 'amount' => 50000]);
    Transaction::create(['user_id' => $user->id, 'type' => 'retrait', 'name' => 'Retrait', 'amount' => 20000]);

    $user->refresh();
    $expectedBalance = 500000.0 + 50000.0 - 20000.0;
    expect((float) $user->balance)->toBe($expectedBalance);
    expect((float) $user->wallet)->toBe(round($expectedBalance * 0.40, 2));
});

test('le chiffre d\'affaires et le bénéfice ne changent pas après un apport', function () {
    $user = makeShareTestUser(100.00);
    Transaction::create(['user_id' => $user->id, 'type' => 'sale', 'name' => 'Vente', 'amount' => 120000]);
    Transaction::create(['user_id' => $user->id, 'type' => 'expense', 'name' => 'Loyer', 'amount' => 20000]);

    $user->refresh();
    $totalSaleBefore = (float) $user->total_sale;
    $calculatedWalletBefore = (float) $user->calculated_wallet;

    Transaction::create(['user_id' => $user->id, 'type' => 'apport', 'name' => 'Apport de fonds', 'amount' => 75000]);

    $user->refresh();
    expect((float) $user->total_sale)->toBe($totalSaleBefore);
    expect((float) $user->total_expense)->toBe(20000.0);
    expect((float) $user->calculated_wallet)->toBe($calculatedWalletBefore);
});
