<?php

use App\Models\Article;
use App\Models\Operation;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Variation;
use App\Services\ArticleStockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Schéma minimal, compatible SQLite ET Postgres (voir la même note dans les
| autres fichiers de test de ce projet).
|--------------------------------------------------------------------------
*/
function createOperationsBasketTestSchema(): void
{
    Schema::dropIfExists('notifications');
    Schema::dropIfExists('transactions');
    Schema::dropIfExists('operations');
    Schema::dropIfExists('operation_counters');
    Schema::dropIfExists('variations');
    Schema::dropIfExists('articles');
    Schema::dropIfExists('clients');
    Schema::dropIfExists('user_settings');
    Schema::dropIfExists('users');

    Schema::create('users', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('first_name');
        $table->string('last_name');
        $table->string('username')->unique();
        $table->string('email')->unique();
        $table->string('password');
        $table->decimal('company_share', 5, 2)->default(100.00);
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
        $table->boolean('notify_stock_low')->default(true);
        $table->timestamps();
    });

    Schema::create('articles', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->string('name');
        $table->decimal('sale_price', 15, 2);
        $table->decimal('price_max', 12, 2)->nullable();
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
        $table->decimal('cost_price', 12, 2)->nullable();
        $table->integer('low_stock_threshold')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('clients', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->string('name');
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

        $table->unique(['user_id', 'idempotency_key'], 'transactions_user_id_idempotency_key_unique');
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
}

beforeEach(function () {
    config(['database.connections.sqlite.database' => ':memory:']);
    DB::purge('sqlite');

    createOperationsBasketTestSchema();
});

function basketUser(): User
{
    return User::create([
        'first_name' => 'Test',
        'last_name' => 'User',
        'username' => 'user_' . Str::random(8),
        'email' => Str::random(8) . '@example.test',
        'password' => 'irrelevant-for-this-test',
    ]);
}

function basketSimpleArticle(User $user, int $quantity, float $price = 5000): Article
{
    return Article::create([
        'user_id' => $user->id,
        'name' => 'Article simple ' . Str::random(4),
        'sale_price' => $price,
        'quantity' => $quantity,
        'type' => 'simple',
    ]);
}

/** @return array{0: Article, 1: Variation} */
function basketVariableArticle(User $user, int $quantity, float $price = 12000): array
{
    $article = Article::create([
        'user_id' => $user->id,
        'name' => 'Robe wax',
        'sale_price' => 0,
        'quantity' => 0,
        'type' => 'variable',
    ]);

    $variation = Variation::create([
        'article_id' => $article->id,
        'name' => 'Taille M',
        'quantity' => $quantity,
        'price' => $price,
    ]);

    ArticleStockService::syncVariableTotals($article);

    return [$article, $variation];
}

function basketHeaders(?string $key = null): array
{
    return ['Idempotency-Key' => $key ?? (string) Str::uuid()];
}

/*
|--------------------------------------------------------------------------
| 1. Encaisser un panier
|--------------------------------------------------------------------------
*/

test('un panier de 3 lignes crée 1 opération et 3 transactions, total exact, stock diminué', function () {
    $user = basketUser();
    $simple = basketSimpleArticle($user, 20, price: 5000);
    [$article, $variation] = basketVariableArticle($user, 10, price: 12000);
    $simple2 = basketSimpleArticle($user, 5, price: 6500);

    $response = $this->actingAs($user)
        ->withHeaders(basketHeaders())
        ->postJson('/api/operations', [
            'type' => 'sale',
            'payment_method' => 'wave',
            'lines' => [
                ['article_id' => $article->id, 'variation_id' => $variation->id, 'quantity' => 2, 'unit_price' => 12000],
                ['article_id' => $simple->id, 'quantity' => 1, 'unit_price' => 5000],
                ['article_id' => $simple2->id, 'quantity' => 3, 'unit_price' => 6500],
            ],
        ]);

    $response->assertStatus(201);
    $data = $response->json('data');

    expect($data['type'])->toBe('sale');
    expect($data['payment_method'])->toBe('wave');
    expect((float) $data['total_amount'])->toBe(24000.0 + 5000.0 + 19500.0);
    expect($data['code'])->toMatch('/^V-\d{6}-\d{4}$/');
    expect($data['transactions'])->toHaveCount(3);

    expect(Operation::count())->toBe(1);
    expect(Transaction::count())->toBe(3);
    expect(Transaction::where('operation_id', $data['id'])->count())->toBe(3);

    expect($variation->fresh()->remaining_quantity)->toBe(8);
    expect($simple->fresh()->remaining_quantity)->toBe(19);
    expect($simple2->fresh()->remaining_quantity)->toBe(2);
});

test('une ligne sans stock suffisant refuse TOUT le panier (422) et indique la bonne ligne', function () {
    $user = basketUser();
    [$article, $variation] = basketVariableArticle($user, 1, price: 12000);
    $simple = basketSimpleArticle($user, 50, price: 1000);

    $response = $this->actingAs($user)
        ->withHeaders(basketHeaders())
        ->postJson('/api/operations', [
            'type' => 'sale',
            'payment_method' => 'especes',
            'lines' => [
                ['article_id' => $simple->id, 'quantity' => 2, 'unit_price' => 1000],
                ['article_id' => $article->id, 'variation_id' => $variation->id, 'quantity' => 2, 'unit_price' => 12000],
            ],
        ]);

    $response->assertStatus(422);
    expect($response->json('message'))->toBe('Stock insuffisant');
    $lines = $response->json('lines');
    expect($lines)->toHaveCount(1);
    expect($lines[0]['index'])->toBe(1);
    expect($lines[0]['available'])->toBe(1);
    expect($lines[0]['requested'])->toBe(2);

    // Tout ou rien : aucune transaction/opération créée, le stock n'a pas bougé.
    expect(Operation::count())->toBe(0);
    expect(Transaction::count())->toBe(0);
    expect($variation->fresh()->remaining_quantity)->toBe(1);
    expect($simple->fresh()->remaining_quantity)->toBe(50);
});

test('deux lignes sur la même variation vérifient le stock sur la quantité totale demandée', function () {
    $user = basketUser();
    [$article, $variation] = basketVariableArticle($user, 3, price: 12000);

    // 2 + 2 = 4 > 3 disponibles : doit échouer même si chaque ligne prise
    // isolément (2 <= 3) semblerait correcte.
    $response = $this->actingAs($user)
        ->withHeaders(basketHeaders())
        ->postJson('/api/operations', [
            'type' => 'sale',
            'payment_method' => 'especes',
            'lines' => [
                ['article_id' => $article->id, 'variation_id' => $variation->id, 'quantity' => 2, 'unit_price' => 12000],
                ['article_id' => $article->id, 'variation_id' => $variation->id, 'quantity' => 2, 'unit_price' => 12000],
            ],
        ]);

    $response->assertStatus(422);
    $lines = $response->json('lines');
    expect($lines[0]['available'])->toBe(3);
    expect($lines[0]['requested'])->toBe(4);
    expect(Operation::count())->toBe(0);

    // Fusionnées (3 <= 3) : doit réussir, une seule ligne en base.
    $response2 = $this->actingAs($user)
        ->withHeaders(basketHeaders())
        ->postJson('/api/operations', [
            'type' => 'sale',
            'payment_method' => 'especes',
            'lines' => [
                ['article_id' => $article->id, 'variation_id' => $variation->id, 'quantity' => 1, 'unit_price' => 12000],
                ['article_id' => $article->id, 'variation_id' => $variation->id, 'quantity' => 2, 'unit_price' => 12000],
            ],
        ]);

    $response2->assertStatus(201);
    expect($response2->json('data.transactions'))->toHaveCount(1);
    expect($response2->json('data.transactions.0.quantity'))->toBe(3);
    expect($variation->fresh()->remaining_quantity)->toBe(0);
});

test('un article variable sans variation_id est refusé (422)', function () {
    $user = basketUser();
    [$article, $variation] = basketVariableArticle($user, 10);

    $response = $this->actingAs($user)
        ->withHeaders(basketHeaders())
        ->postJson('/api/operations', [
            'type' => 'sale',
            'payment_method' => 'especes',
            'lines' => [
                ['article_id' => $article->id, 'quantity' => 1, 'unit_price' => 12000],
            ],
        ]);

    $response->assertStatus(422);
    expect(Operation::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Espèces
|--------------------------------------------------------------------------
*/

test('espèces SANS amount_received : montant reçu = total, monnaie = 0', function () {
    $user = basketUser();
    $simple = basketSimpleArticle($user, 10, price: 5000);

    $response = $this->actingAs($user)
        ->withHeaders(basketHeaders())
        ->postJson('/api/operations', [
            'type' => 'sale',
            'payment_method' => 'especes',
            'lines' => [['article_id' => $simple->id, 'quantity' => 2, 'unit_price' => 5000]],
        ]);

    $response->assertStatus(201);
    expect((float) $response->json('data.amount_received'))->toBe(10000.0);
    expect((float) $response->json('data.change_given'))->toBe(0.0);
});

test('espèces, 20 000 reçus pour 18 500 : monnaie rendue 1 500', function () {
    $user = basketUser();
    $simple = basketSimpleArticle($user, 10, price: 18500);

    $response = $this->actingAs($user)
        ->withHeaders(basketHeaders())
        ->postJson('/api/operations', [
            'type' => 'sale',
            'payment_method' => 'especes',
            'amount_received' => 20000,
            'lines' => [['article_id' => $simple->id, 'quantity' => 1, 'unit_price' => 18500]],
        ]);

    $response->assertStatus(201);
    expect((float) $response->json('data.amount_received'))->toBe(20000.0);
    expect((float) $response->json('data.change_given'))->toBe(1500.0);
});

test('espèces, montant reçu inférieur au total est refusé (422)', function () {
    $user = basketUser();
    $simple = basketSimpleArticle($user, 10, price: 18500);

    $response = $this->actingAs($user)
        ->withHeaders(basketHeaders())
        ->postJson('/api/operations', [
            'type' => 'sale',
            'payment_method' => 'especes',
            'amount_received' => 10000,
            'lines' => [['article_id' => $simple->id, 'quantity' => 1, 'unit_price' => 18500]],
        ]);

    $response->assertStatus(422);
    expect(Operation::count())->toBe(0);
});

test('Wave/Orange Money : amount_received et change_given restent NULL', function () {
    $user = basketUser();
    $simple = basketSimpleArticle($user, 10, price: 5000);

    foreach (['wave', 'orange_money'] as $method) {
        $response = $this->actingAs($user)
            ->withHeaders(basketHeaders())
            ->postJson('/api/operations', [
                'type' => 'sale',
                'payment_method' => $method,
                'lines' => [['article_id' => $simple->id, 'quantity' => 1, 'unit_price' => 5000]],
            ]);

        $response->assertStatus(201);
        expect($response->json('data.amount_received'))->toBeNull();
        expect($response->json('data.change_given'))->toBeNull();
    }
});

/*
|--------------------------------------------------------------------------
| Concurrence (voir TransactionIdempotencyTest.php pour la même limite déjà
| documentée dans ce projet : SQLite verrouille tout le fichier dès qu'une
| transaction a lu une ligne, donc une vraie course à 2 connexions se
| bloquerait elle-même dans un test mono-processus. On teste donc,
| déterministe : le stock est RECONTRÔLÉ APRÈS verrouillage, avec la valeur
| que laisserait un panier concurrent ayant déjà gagné la course — la
| propriété qui garantit qu'un seul des deux paniers peut réussir.
|--------------------------------------------------------------------------
*/

test('un panier qui arrive après qu\'un autre a pris la dernière unité échoue (stock recontrôlé après verrouillage)', function () {
    $user = basketUser();
    [$article, $variation] = basketVariableArticle($user, 1, price: 12000);

    // Simule le panier CONCURRENT gagnant : a déjà pris l'unique unité.
    $winner = $this->actingAs($user)
        ->withHeaders(basketHeaders())
        ->postJson('/api/operations', [
            'type' => 'sale',
            'payment_method' => 'especes',
            'lines' => [['article_id' => $article->id, 'variation_id' => $variation->id, 'quantity' => 1, 'unit_price' => 12000]],
        ]);
    $winner->assertStatus(201);

    // Le second panier, arrivé juste après, ne doit JAMAIS passer.
    $loser = $this->actingAs($user)
        ->withHeaders(basketHeaders())
        ->postJson('/api/operations', [
            'type' => 'sale',
            'payment_method' => 'especes',
            'lines' => [['article_id' => $article->id, 'variation_id' => $variation->id, 'quantity' => 1, 'unit_price' => 12000]],
        ]);

    $loser->assertStatus(422);
    expect(Operation::count())->toBe(1);
    expect(Transaction::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Idempotence
|--------------------------------------------------------------------------
*/

test('l\'en-tête Idempotency-Key est obligatoire pour POST /api/operations', function () {
    $user = basketUser();
    $simple = basketSimpleArticle($user, 10);

    $response = $this->actingAs($user)->postJson('/api/operations', [
        'type' => 'sale',
        'payment_method' => 'especes',
        'lines' => [['article_id' => $simple->id, 'quantity' => 1, 'unit_price' => 5000]],
    ]);

    $response->assertStatus(422);
    expect(Operation::count())->toBe(0);
});

test('la même Idempotency-Key renvoie la MÊME opération (200), sans doublon', function () {
    $user = basketUser();
    $simple = basketSimpleArticle($user, 10, price: 5000);
    $key = (string) Str::uuid();
    $payload = [
        'type' => 'sale',
        'payment_method' => 'especes',
        'lines' => [['article_id' => $simple->id, 'quantity' => 1, 'unit_price' => 5000]],
    ];

    $first = $this->actingAs($user)->withHeaders(basketHeaders($key))->postJson('/api/operations', $payload);
    $second = $this->actingAs($user)->withHeaders(basketHeaders($key))->postJson('/api/operations', $payload);

    $first->assertStatus(201);
    $second->assertStatus(200);
    expect($first->json('data.id'))->toBe($second->json('data.id'));
    expect(Operation::count())->toBe(1);
    expect(Transaction::count())->toBe(1);
    expect($simple->fresh()->sold_quantity)->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Dépenses / apports / retraits via POST /api/operations
|--------------------------------------------------------------------------
*/

test('une dépense via POST /api/operations crée une opération à une seule ligne', function () {
    $user = basketUser();

    $response = $this->actingAs($user)
        ->withHeaders(basketHeaders())
        ->postJson('/api/operations', [
            'type' => 'expense',
            'lines' => [['amount' => 15000, 'name' => 'Loyer']],
        ]);

    $response->assertStatus(201);
    expect($response->json('data.type'))->toBe('expense');
    expect((float) $response->json('data.total_amount'))->toBe(15000.0);
    expect(Transaction::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Suppression / restauration
|--------------------------------------------------------------------------
*/

test('supprimer puis restaurer une opération restaure le stock et le solde', function () {
    $user = basketUser();
    [$article, $variation] = basketVariableArticle($user, 10, price: 12000);
    $simple = basketSimpleArticle($user, 10, price: 5000);

    $response = $this->actingAs($user)
        ->withHeaders(basketHeaders())
        ->postJson('/api/operations', [
            'type' => 'sale',
            'payment_method' => 'especes',
            'lines' => [
                ['article_id' => $article->id, 'variation_id' => $variation->id, 'quantity' => 3, 'unit_price' => 12000],
                ['article_id' => $simple->id, 'quantity' => 2, 'unit_price' => 5000],
            ],
        ]);
    $response->assertStatus(201);
    $operationId = $response->json('data.id');

    expect($variation->fresh()->remaining_quantity)->toBe(7);
    expect($simple->fresh()->remaining_quantity)->toBe(8);
    expect($user->fresh()->calculated_wallet)->toEqual(46000.0);

    $delete = $this->actingAs($user)->deleteJson("/api/operations/{$operationId}");
    $delete->assertStatus(200);

    expect($variation->fresh()->remaining_quantity)->toBe(10);
    expect($simple->fresh()->remaining_quantity)->toBe(10);
    expect((float) $user->fresh()->calculated_wallet)->toBe(0.0);
    expect(Operation::count())->toBe(0);
    expect(Operation::withTrashed()->count())->toBe(1);
    expect(Transaction::count())->toBe(0);

    $restore = $this->actingAs($user)->postJson("/api/operations/{$operationId}/restore");
    $restore->assertStatus(200);

    expect($variation->fresh()->remaining_quantity)->toBe(7);
    expect($simple->fresh()->remaining_quantity)->toBe(8);
    expect((float) $user->fresh()->calculated_wallet)->toBe(46000.0);
    expect(Operation::count())->toBe(1);
    expect(Transaction::count())->toBe(2);
});

test('supprimer une seule transaction d\'une opération à plusieurs lignes est refusé (422)', function () {
    $user = basketUser();
    $simple = basketSimpleArticle($user, 10, price: 5000);
    $simple2 = basketSimpleArticle($user, 10, price: 3000);

    $response = $this->actingAs($user)
        ->withHeaders(basketHeaders())
        ->postJson('/api/operations', [
            'type' => 'sale',
            'payment_method' => 'especes',
            'lines' => [
                ['article_id' => $simple->id, 'quantity' => 1, 'unit_price' => 5000],
                ['article_id' => $simple2->id, 'quantity' => 1, 'unit_price' => 3000],
            ],
        ]);
    $response->assertStatus(201);

    $transactionId = $response->json('data.transactions.0.id');

    $delete = $this->actingAs($user)->deleteJson("/api/transactions/{$transactionId}");
    $delete->assertStatus(422);
    expect($delete->json('message'))->toBe('Supprimez l\'opération complète');
    expect(Transaction::count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Recherche par code
|--------------------------------------------------------------------------
*/

test('recherche par code exact (avec tirets) trouve l\'opération', function () {
    $user = basketUser();
    $simple = basketSimpleArticle($user, 10, price: 5000);

    $response = $this->actingAs($user)
        ->withHeaders(basketHeaders())
        ->postJson('/api/operations', [
            'type' => 'sale',
            'payment_method' => 'especes',
            'lines' => [['article_id' => $simple->id, 'quantity' => 1, 'unit_price' => 5000]],
        ]);
    $code = $response->json('data.code');

    $search = $this->actingAs($user)->getJson('/api/operations?q=' . urlencode($code));
    $search->assertStatus(200);
    expect($search->json('data'))->toHaveCount(1);
    expect($search->json('data.0.code'))->toBe($code);
});

test('recherche par code SANS tirets, insensible à la casse, trouve l\'opération', function () {
    $user = basketUser();
    $simple = basketSimpleArticle($user, 10, price: 5000);

    $response = $this->actingAs($user)
        ->withHeaders(basketHeaders())
        ->postJson('/api/operations', [
            'type' => 'sale',
            'payment_method' => 'especes',
            'lines' => [['article_id' => $simple->id, 'quantity' => 1, 'unit_price' => 5000]],
        ]);
    $code = $response->json('data.code');
    $noDashesLower = strtolower(str_replace('-', '', $code));

    $search = $this->actingAs($user)->getJson('/api/operations?q=' . urlencode($noDashesLower));
    $search->assertStatus(200);
    expect($search->json('data'))->toHaveCount(1);
    expect($search->json('data.0.code'))->toBe($code);
});

test('recherche par les 4 derniers chiffres du code trouve l\'opération, classée en premier', function () {
    $user = basketUser();
    $simple = basketSimpleArticle($user, 10, price: 5000);

    $response = $this->actingAs($user)
        ->withHeaders(basketHeaders())
        ->postJson('/api/operations', [
            'type' => 'sale',
            'payment_method' => 'especes',
            'lines' => [['article_id' => $simple->id, 'quantity' => 1, 'unit_price' => 5000]],
        ]);
    $code = $response->json('data.code');
    $suffix = substr($code, -4);

    $search = $this->actingAs($user)->getJson('/api/operations?q=' . $suffix);
    $search->assertStatus(200);
    expect($search->json('data.0.code'))->toBe($code);
});

/*
|--------------------------------------------------------------------------
| Statistiques
|--------------------------------------------------------------------------
*/

test('le nombre de ventes (KPI) compte les opérations, pas les lignes', function () {
    $user = basketUser();
    $simple = basketSimpleArticle($user, 20, price: 5000);
    $simple2 = basketSimpleArticle($user, 20, price: 3000);

    // Un panier de 2 lignes = 1 SEULE vente.
    $this->actingAs($user)
        ->withHeaders(basketHeaders())
        ->postJson('/api/operations', [
            'type' => 'sale',
            'payment_method' => 'especes',
            'lines' => [
                ['article_id' => $simple->id, 'quantity' => 1, 'unit_price' => 5000],
                ['article_id' => $simple2->id, 'quantity' => 1, 'unit_price' => 3000],
            ],
        ])->assertStatus(201);

    // Une vente classique à une ligne = 1 vente de plus.
    $this->actingAs($user)
        ->withHeaders(basketHeaders())
        ->postJson('/api/transactions', [
            'type' => 'sale',
            'article_id' => $simple->id,
            'quantity' => 1,
        ])->assertStatus(201);

    expect(Transaction::where('type', 'sale')->count())->toBe(3);

    $kpis = $this->actingAs($user)->getJson('/api/analytics/kpis?period=30');
    $kpis->assertStatus(200);
    expect($kpis->json('data.sales_count'))->toBe(2);
});
