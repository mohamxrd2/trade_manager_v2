<?php

use App\Models\Operation;
use App\Models\Transaction;
use App\Models\User;
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

/*
|--------------------------------------------------------------------------
| 1. Répartition par moyen de paiement exacte
|--------------------------------------------------------------------------
*/
test('la répartition par moyen de paiement est exacte et somme au chiffre d\'affaires', function () {
    $user = summaryUser();
    $now = Carbon::now();

    $op1 = makeSaleOperation($user, ['payment_method' => 'especes', 'total_amount' => 10000, 'occurred_at' => $now]);
    makeLine($op1, 10000);

    $op2 = makeSaleOperation($user, ['payment_method' => 'wave', 'total_amount' => 5000, 'occurred_at' => $now]);
    makeLine($op2, 5000);

    $op3 = makeSaleOperation($user, ['payment_method' => 'wave', 'total_amount' => 7000, 'occurred_at' => $now]);
    makeLine($op3, 7000);

    $response = $this->actingAs($user)->getJson('/api/analytics/summary?period=7d');
    $response->assertStatus(200);

    $rows = collect($response->json('data.payment_methods.rows'));
    $especes = $rows->firstWhere('payment_method', 'especes');
    $wave = $rows->firstWhere('payment_method', 'wave');

    expect($especes['amount'])->toEqual(10000.0);
    expect($especes['sales_count'])->toBe(1);
    expect($wave['amount'])->toEqual(12000.0);
    expect($wave['sales_count'])->toBe(2);

    $totalRevenue = $response->json('data.kpis.revenue.value');
    expect($totalRevenue)->toEqual(22000.0);
    expect($rows->sum('amount'))->toEqual($totalRevenue);
    expect(round($especes['percentage'] + $wave['percentage'], 2))->toEqual(100.0);
});

/*
|--------------------------------------------------------------------------
| 2. Apports/retraits jamais comptés dans le CA ni la répartition
|--------------------------------------------------------------------------
*/
test('les apports et retraits sont exclus du chiffre d\'affaires et de la répartition par moyen de paiement', function () {
    $user = summaryUser();
    $now = Carbon::now();

    $sale = makeSaleOperation($user, ['total_amount' => 9000, 'occurred_at' => $now]);
    makeLine($sale, 9000);

    makeTreasuryOperation($user, 'apport', 50000, $now);
    makeTreasuryOperation($user, 'retrait', 20000, $now);

    $response = $this->actingAs($user)->getJson('/api/analytics/summary?period=7d');
    $response->assertStatus(200);

    expect($response->json('data.kpis.revenue.value'))->toEqual(9000.0);
    expect($response->json('data.kpis.sales_count.value'))->toBe(1);
    expect(collect($response->json('data.payment_methods.rows'))->pluck('payment_method')->all())->toBe(['especes']);

    expect($response->json('data.treasury.deposits'))->toEqual(50000.0);
    expect($response->json('data.treasury.withdrawals'))->toEqual(20000.0);
});

/*
|--------------------------------------------------------------------------
| 3. Anciennes ventes sans moyen de paiement -> "non_precise"
|--------------------------------------------------------------------------
*/
test('une vente sans moyen de paiement est classée non_precise', function () {
    $user = summaryUser();
    $now = Carbon::now();

    $op = makeSaleOperation($user, ['payment_method' => null, 'total_amount' => 3000, 'occurred_at' => $now]);
    makeLine($op, 3000);

    $response = $this->actingAs($user)->getJson('/api/analytics/summary?period=7d');
    $response->assertStatus(200);

    $rows = collect($response->json('data.payment_methods.rows'));
    expect($rows)->toHaveCount(1);
    expect($rows->first()['payment_method'])->toBe('non_precise');
    expect($rows->first()['amount'])->toEqual(3000.0);
});

/*
|--------------------------------------------------------------------------
| 4 + 5. Comptage par OPÉRATIONS, panier moyen, articles par panier
|--------------------------------------------------------------------------
*/
test('un panier de 3 lignes compte comme 1 vente ; panier moyen et articles/panier sont corrects', function () {
    $user = summaryUser();
    $now = Carbon::now();

    $basket = makeSaleOperation($user, ['total_amount' => 24000, 'occurred_at' => $now]);
    makeLine($basket, 10000, quantity: 2);
    makeLine($basket, 9000, quantity: 1);
    makeLine($basket, 5000, quantity: 3);

    $single = makeSaleOperation($user, ['total_amount' => 6000, 'occurred_at' => $now]);
    makeLine($single, 6000, quantity: 1);

    $response = $this->actingAs($user)->getJson('/api/analytics/summary?period=7d');
    $response->assertStatus(200);

    expect($response->json('data.kpis.sales_count.value'))->toBe(2);
    expect($response->json('data.kpis.revenue.value'))->toEqual(30000.0);
    // panier moyen = 30000 / 2
    expect($response->json('data.kpis.average_basket.value'))->toEqual(15000.0);
    // articles/panier = (2+1+3+1) / 2
    expect($response->json('data.kpis.items_per_basket.value'))->toEqual(3.5);
});

/*
|--------------------------------------------------------------------------
| 6. Heatmap en heure d'Abidjan
|--------------------------------------------------------------------------
*/
test('une vente à 23h30 tombe dans le bon jour du heatmap (heure Abidjan)', function () {
    $user = summaryUser();

    // Mardi 20/10/2026 23:30 Abidjan — jour de semaine attendu : 2 (0=dimanche).
    $saleAt = Carbon::create(2026, 10, 20, 23, 30, 0, 'Africa/Abidjan');
    $op = makeSaleOperation($user, ['total_amount' => 4000, 'occurred_at' => $saleAt]);
    makeLine($op, 4000);

    $response = $this->actingAs($user)->getJson('/api/analytics/summary?period=7d');
    $response->assertStatus(200);

    $cells = collect($response->json('data.heatmap.cells'));
    $cell = $cells->first(fn ($c) => $c['hour'] === 23);

    expect($cell)->not->toBeNull();
    expect($cell['day_of_week'])->toBe(2); // mardi
    expect($cell['sales_count'])->toBe(1);
    expect($cell['revenue'])->toEqual(4000.0);
});

/*
|--------------------------------------------------------------------------
| 7. Comparaison vs période précédente
|--------------------------------------------------------------------------
*/
test('la variation vs la période précédente est calculée correctement', function () {
    $user = summaryUser();
    $now = Carbon::now();

    // Période courante (7d) : 10000 F.
    $current = makeSaleOperation($user, ['total_amount' => 10000, 'occurred_at' => $now]);
    makeLine($current, 10000);

    // Période précédente (les 7 jours juste avant) : 8000 F.
    $previousAt = $now->copy()->subDays(8);
    $previous = makeSaleOperation($user, ['total_amount' => 8000, 'occurred_at' => $previousAt]);
    makeLine($previous, 8000);

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
test('un utilisateur ne voit jamais les données analytiques d\'un autre', function () {
    $userA = summaryUser();
    $userB = summaryUser();
    $now = Carbon::now();

    $opA = makeSaleOperation($userA, ['total_amount' => 10000, 'occurred_at' => $now]);
    makeLine($opA, 10000);

    $opB = makeSaleOperation($userB, ['total_amount' => 99000, 'occurred_at' => $now]);
    makeLine($opB, 99000);

    $response = $this->actingAs($userA)->getJson('/api/analytics/summary?period=7d');
    $response->assertStatus(200);

    expect($response->json('data.kpis.revenue.value'))->toEqual(10000.0);
});
