<?php

use App\Models\Article;
use App\Models\Operation;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Variation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Schéma minimal, compatible SQLite ET Postgres (même approche que les
| autres fichiers de test de ce projet, ex. OperationsBasketTest).
|--------------------------------------------------------------------------
*/
function createAnalyticsSummaryTestSchema(): void
{
    Schema::dropIfExists('transactions');
    Schema::dropIfExists('operations');
    Schema::dropIfExists('variations');
    Schema::dropIfExists('articles');
    Schema::dropIfExists('users');

    Schema::create('users', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('first_name');
        $table->string('last_name');
        $table->string('username')->unique();
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    Schema::create('articles', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->string('name');
        $table->decimal('sale_price', 15, 2);
        $table->integer('quantity');
        $table->string('type');
        $table->timestamps();
    });

    Schema::create('variations', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('article_id');
        $table->string('name');
        $table->integer('quantity');
        $table->decimal('price', 12, 2)->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('operations', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->uuid('created_by_user_id')->nullable();
        $table->string('created_by_name')->nullable();
        $table->string('created_by_role', 32)->nullable();
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

        $table->unique(['user_id', 'code']);
        $table->unique(['user_id', 'idempotency_key']);
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
}

beforeEach(function () {
    config(['database.connections.sqlite.database' => ':memory:']);
    DB::purge('sqlite');

    createAnalyticsSummaryTestSchema();

    // Figer "maintenant" à un mardi 12h Abidjan — bornes de période
    // déterministes pour tous les tests de ce fichier.
    Carbon::setTestNow(Carbon::create(2026, 10, 20, 12, 0, 0, 'Africa/Abidjan'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function summaryUser(): User
{
    return User::create([
        'first_name' => 'Test',
        'last_name' => 'User',
        'username' => 'user_' . Str::random(8),
        'email' => Str::random(8) . '@example.test',
        'password' => 'irrelevant-for-this-test',
    ]);
}

function makeSaleOperation(User $user, array $overrides = []): Operation
{
    return Operation::create(array_merge([
        'user_id' => $user->id,
        'type' => 'sale',
        'code' => 'V-' . Str::random(10),
        'payment_method' => 'especes',
        'total_amount' => 1000,
        'occurred_at' => Carbon::now(),
    ], $overrides));
}

function makeTreasuryOperation(User $user, string $type, float $amount, Carbon $occurredAt): Operation
{
    return Operation::create([
        'user_id' => $user->id,
        'type' => $type,
        'code' => strtoupper($type[0]) . '-' . Str::random(10),
        'total_amount' => $amount,
        'occurred_at' => $occurredAt,
    ]);
}

function makeLine(Operation $operation, float $amount, int $quantity = 1, array $overrides = []): Transaction
{
    return Transaction::create(array_merge([
        'user_id' => $operation->user_id,
        'operation_id' => $operation->id,
        'name' => 'Article test',
        'quantity' => $quantity,
        'amount' => $amount,
        'type' => $operation->type,
        'payment_method' => $operation->payment_method,
    ], $overrides));
}

/**
 * Transaction historique "pré-operations" : AUCUN operation_id — exactement
 * le cas réel constaté en production/local (operations:backfill jamais
 * lancé). C'est le scénario central de ce fichier de test.
 *
 * INSERT BRUT (pas Transaction::create()) : le modèle Transaction a un hook
 * `creating` (voir Transaction::booted(), chantier "paniers multi-articles"
 * étape 1) qui crée AUTOMATIQUEMENT une Operation dès qu'on sauvegarde une
 * transaction sans operation_id — ce qui est le comportement voulu pour les
 * nouvelles ventes, mais empêche de simuler ici une vraie ligne orpheline
 * historique (celles qui existent déjà en base, créées avant ce hook).
 */
function makeOrphanTransaction(User $user, string $type, float $amount, Carbon $createdAt, array $overrides = []): Transaction
{
    $id = (string) Str::orderedUuid();

    DB::table('transactions')->insert(array_merge([
        'id' => $id,
        'user_id' => $user->id,
        'operation_id' => null,
        'name' => 'Vente historique',
        'quantity' => 1,
        'amount' => $amount,
        'type' => $type,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ], $overrides));

    return Transaction::find($id);
}

/*
|--------------------------------------------------------------------------
| 1. BUG CENTRAL : ventes sans operation_id (backfill jamais lancé)
|--------------------------------------------------------------------------
*/
test('des ventes SANS operation_id sont comptées dans le CA, le nombre de ventes et la répartition par moyen de paiement', function () {
    $user = summaryUser();
    $now = Carbon::now();

    // Trois ventes historiques, jamais migrées vers `operations` — le cas
    // réel qui provoquait un chiffre d'affaires à zéro.
    makeOrphanTransaction($user, 'sale', 10000, $now, ['payment_method' => 'wave']);
    makeOrphanTransaction($user, 'sale', 5000, $now, ['payment_method' => null]);
    makeOrphanTransaction($user, 'sale', 3000, $now->copy()->subHour(), ['payment_method' => 'especes']);

    $response = $this->actingAs($user)->getJson('/api/analytics/summary?period=7d');
    $response->assertStatus(200);

    expect($response->json('data.kpis.revenue.value'))->toEqual(18000.0);
    expect($response->json('data.kpis.sales_count.value'))->toBe(3);

    $rows = collect($response->json('data.payment_methods.rows'));
    expect($rows->firstWhere('payment_method', 'wave')['amount'])->toEqual(10000.0);
    expect($rows->firstWhere('payment_method', 'especes')['amount'])->toEqual(3000.0);
    expect($rows->firstWhere('payment_method', 'non_precise')['amount'])->toEqual(5000.0);
});

test('un panier de 3 lignes (opération) compte comme 1 seule vente, même mélangé à des ventes orphelines', function () {
    $user = summaryUser();
    $now = Carbon::now();

    $basket = makeSaleOperation($user, ['total_amount' => 24000, 'occurred_at' => $now]);
    makeLine($basket, 10000, quantity: 2);
    makeLine($basket, 9000, quantity: 1);
    makeLine($basket, 5000, quantity: 3);

    makeOrphanTransaction($user, 'sale', 6000, $now, ['quantity' => 1]);

    $response = $this->actingAs($user)->getJson('/api/analytics/summary?period=7d');
    $response->assertStatus(200);

    expect($response->json('data.kpis.sales_count.value'))->toBe(2); // 1 panier + 1 vente orpheline
    expect($response->json('data.kpis.revenue.value'))->toEqual(30000.0);
    expect($response->json('data.kpis.items_sold.value'))->toEqual(7.0); // 2+1+3+1
    // panier moyen = 30000 / 2, articles/panier = 7 / 2
    expect($response->json('data.kpis.average_basket.value'))->toEqual(15000.0);
    expect($response->json('data.kpis.items_per_basket.value'))->toEqual(3.5);
});

/*
|--------------------------------------------------------------------------
| 2. Période : alias period=30 et period=30d identiques ; valeur inconnue -> 422
|--------------------------------------------------------------------------
*/
test('period=30 et period=30d renvoient exactement les mêmes bornes de dates', function () {
    $user = summaryUser();

    $r30 = $this->actingAs($user)->getJson('/api/analytics/summary?period=30');
    $r30d = $this->actingAs($user)->getJson('/api/analytics/summary?period=30d');

    $r30->assertStatus(200);
    $r30d->assertStatus(200);
    expect($r30->json('data.start_date'))->toBe($r30d->json('data.start_date'));
    expect($r30->json('data.end_date'))->toBe($r30d->json('data.end_date'));
    expect($r30->json('data.period'))->toBe('30d');
});

test('une période inconnue renvoie 422, jamais un repli silencieux', function () {
    $user = summaryUser();

    $response = $this->actingAs($user)->getJson('/api/analytics/summary?period=quinze-jours');
    $response->assertStatus(422);
});

test('period=custom sans from/to renvoie 422', function () {
    $user = summaryUser();

    $response = $this->actingAs($user)->getJson('/api/analytics/summary?period=custom');
    $response->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| 3. Heatmap en heure d'Abidjan
|--------------------------------------------------------------------------
*/
test('une vente du 29/09 23h30 (heure Abidjan) tombe dans le bon jour du heatmap', function () {
    $user = summaryUser();

    // 29/09/2026 est un mardi.
    $saleAt = Carbon::create(2026, 9, 29, 23, 30, 0, 'Africa/Abidjan');
    makeOrphanTransaction($user, 'sale', 4000, $saleAt);

    $response = $this->actingAs($user)->getJson('/api/analytics/summary?period=12m');
    $response->assertStatus(200);

    $cells = collect($response->json('data.heatmap.cells'));
    $cell = $cells->first(fn ($c) => $c['hour'] === 23);

    expect($cell)->not->toBeNull();
    expect($cell['day_of_week'])->toBe(2); // mardi
    expect($cell['revenue'])->toEqual(4000.0);
});

/*
|--------------------------------------------------------------------------
| 4. Cohérence : net = CA - dépenses, somme méthodes = CA
|--------------------------------------------------------------------------
*/
test('bénéfice net = CA - dépenses et somme des moyens de paiement = CA, sur le même jeu de données', function () {
    $user = summaryUser();
    $now = Carbon::now();

    makeOrphanTransaction($user, 'sale', 10000, $now, ['payment_method' => 'wave']);
    makeOrphanTransaction($user, 'sale', 7000, $now, ['payment_method' => 'especes']);
    makeOrphanTransaction($user, 'expense', 4000, $now);

    $response = $this->actingAs($user)->getJson('/api/analytics/summary?period=7d');
    $response->assertStatus(200);

    expect($response->json('data.kpis.revenue.value'))->toEqual(17000.0);
    expect($response->json('data.kpis.expenses.value'))->toEqual(4000.0);
    expect($response->json('data.kpis.net_profit.value'))->toEqual(13000.0);

    $methodsTotal = collect($response->json('data.payment_methods.rows'))->sum('amount');
    expect($methodsTotal)->toEqual(17000.0);

    expect($response->json('data.consistency.net_profit_matches'))->toBeTrue();
    expect($response->json('data.consistency.payment_methods_total_matches'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| 5. Apports/retraits exclus du CA
|--------------------------------------------------------------------------
*/
test('les apports et retraits restent exclus du chiffre d\'affaires', function () {
    $user = summaryUser();
    $now = Carbon::now();

    makeOrphanTransaction($user, 'sale', 9000, $now);
    makeOrphanTransaction($user, 'apport', 50000, $now);
    makeOrphanTransaction($user, 'retrait', 20000, $now);

    $response = $this->actingAs($user)->getJson('/api/analytics/summary?period=7d');
    $response->assertStatus(200);

    expect($response->json('data.kpis.revenue.value'))->toEqual(9000.0);
    expect($response->json('data.treasury.deposits'))->toEqual(50000.0);
    expect($response->json('data.treasury.withdrawals'))->toEqual(20000.0);
});

/*
|--------------------------------------------------------------------------
| 6. Top variations (article_id exposé, simple vs variation)
|--------------------------------------------------------------------------
*/
test('le top variations fonctionne pour des ventes orphelines (sans opération)', function () {
    $user = summaryUser();
    $now = Carbon::now();

    $simple = Article::create(['user_id' => $user->id, 'name' => 'Savon', 'sale_price' => 1000, 'quantity' => 50, 'type' => 'simple']);
    makeOrphanTransaction($user, 'sale', 2000, $now, ['quantity' => 2, 'article_id' => $simple->id, 'name' => 'Savon']);

    $response = $this->actingAs($user)->getJson('/api/analytics/summary?period=7d');
    $response->assertStatus(200);

    $rows = collect($response->json('data.top_variations'));
    $row = $rows->firstWhere('article_name', 'Savon');
    expect($row['article_id'])->toBe($simple->id);
    expect($row['variation_name'])->toBeNull();
    expect($row['quantity'])->toBe(2);
});

/*
|--------------------------------------------------------------------------
| 7. Comparaison vs période précédente
|--------------------------------------------------------------------------
*/
test('la variation vs la période précédente est calculée correctement (ventes orphelines)', function () {
    $user = summaryUser();
    $now = Carbon::now();

    makeOrphanTransaction($user, 'sale', 10000, $now);
    makeOrphanTransaction($user, 'sale', 8000, $now->copy()->subDays(8));

    $response = $this->actingAs($user)->getJson('/api/analytics/summary?period=7d');
    $response->assertStatus(200);

    expect($response->json('data.kpis.revenue.value'))->toEqual(10000.0);
    expect($response->json('data.kpis.revenue.previous'))->toEqual(8000.0);
    expect($response->json('data.kpis.revenue.change_percent'))->toEqual(25.0);
});

/*
|--------------------------------------------------------------------------
| 8. Isolation entre utilisateurs
|--------------------------------------------------------------------------
*/
test('un utilisateur ne voit jamais les données analytiques d\'un autre (ventes orphelines)', function () {
    $userA = summaryUser();
    $userB = summaryUser();
    $now = Carbon::now();

    makeOrphanTransaction($userA, 'sale', 10000, $now);
    makeOrphanTransaction($userB, 'sale', 99000, $now);

    $response = $this->actingAs($userA)->getJson('/api/analytics/summary?period=7d');
    $response->assertStatus(200);

    expect($response->json('data.kpis.revenue.value'))->toEqual(10000.0);
});

/*
|--------------------------------------------------------------------------
| 9. Une opération liée mais soft-deleted (vente annulée) reste exclue
|--------------------------------------------------------------------------
*/
test('une vente liée à une opération annulée (soft-deleted) est exclue', function () {
    $user = summaryUser();
    $now = Carbon::now();

    $cancelled = makeSaleOperation($user, ['total_amount' => 5000, 'occurred_at' => $now]);
    makeLine($cancelled, 5000);
    $cancelled->delete();

    makeOrphanTransaction($user, 'sale', 3000, $now);

    $response = $this->actingAs($user)->getJson('/api/analytics/summary?period=7d');
    $response->assertStatus(200);

    expect($response->json('data.kpis.revenue.value'))->toEqual(3000.0);
    expect($response->json('data.kpis.sales_count.value'))->toBe(1);
});
