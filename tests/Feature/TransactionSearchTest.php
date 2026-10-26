<?php

use App\Models\Article;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Variation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Schéma minimal, compatible SQLite ET Postgres réel (même principe que
| DepositWithdrawalTest.php) — RefreshDatabase désactivé partout, voir
| tests/Pest.php.
|--------------------------------------------------------------------------
*/
function createTransactionSearchTestSchema(): void
{
    // Sur Postgres réel (waelly_test), ce fichier teste SA PROPRE table —
    // RefreshDatabase désactivé, aucune migration réelle n'est rejouée (voir
    // tests/Pest.php), donc la migration 2026_10_27_090000 (appliquée sur
    // waelly_local/waelly_staging/Neon) n'a jamais touché cette base de
    // test : l'extension doit être activée ICI pour que unaccent() existe.
    // SQLite émule unaccent() via TransactionController::registerSqliteUnaccentIfNeeded().
    if (DB::connection()->getDriverName() === 'pgsql') {
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
    }

    foreach ([
        'notifications', 'stock_replenishments', 'invoice_items', 'invoices',
        'clients', 'companies', 'variations', 'articles', 'collaborators',
        'transactions', 'user_settings', 'users',
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

    Schema::create('articles', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->string('name');
        $table->decimal('sale_price', 15, 2);
        $table->integer('quantity');
        $table->string('type');
        $table->string('image')->nullable();
        $table->decimal('price_max', 12, 2)->nullable();
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
        $table->timestamp('deleted_at')->nullable();
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

    Schema::create('clients', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->string('name');
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->string('payment_method')->default('cash');
        $table->timestamps();
    });

    Schema::create('invoices', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->uuid('company_id')->nullable();
        $table->uuid('client_id');
        $table->string('invoice_number')->unique();
        $table->string('status')->default('draft');
        $table->decimal('total', 15, 2)->default(0);
        $table->date('issued_at');
        $table->date('due_date');
        $table->timestamp('paid_at')->nullable();
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
        $table->timestamps();
        $table->softDeletes();
    });
}

beforeEach(function () {
    config(['database.connections.sqlite.database' => ':memory:']);
    DB::purge('sqlite');
    createTransactionSearchTestSchema();
});

function makeSearchTestUser(array $overrides = []): User
{
    return User::create(array_merge([
        'first_name' => 'Test',
        'last_name' => 'User',
        'username' => 'user_' . Str::random(8),
        'email' => Str::random(8) . '@example.test',
        'password' => 'irrelevant-for-this-test',
    ], $overrides));
}

function makeSearchTestArticle(User $user, array $overrides = []): Article
{
    return Article::create(array_merge([
        'user_id' => $user->id,
        'name' => 'Article',
        'sale_price' => 1000,
        'quantity' => 10,
        'type' => 'simple',
    ], $overrides));
}

test('"robe" trouve les ventes de l\'article "Robe wax"', function () {
    $user = makeSearchTestUser();
    $article = makeSearchTestArticle($user, ['name' => 'Robe wax']);
    $other = makeSearchTestArticle($user, ['name' => 'Sac à main']);

    Transaction::create(['user_id' => $user->id, 'article_id' => $article->id, 'type' => 'sale', 'name' => 'Vente', 'amount' => 15000]);
    Transaction::create(['user_id' => $user->id, 'article_id' => $other->id, 'type' => 'sale', 'name' => 'Vente', 'amount' => 20000]);

    $response = $this->actingAs($user)->getJson('/api/transactions?q=robe');

    $response->assertStatus(200);
    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.article_id'))->toBe($article->id);
});

test('la recherche fonctionne sur TOUS les types de transaction, pas seulement les ventes', function () {
    $user = makeSearchTestUser();
    $expense = Transaction::create(['user_id' => $user->id, 'type' => 'expense', 'name' => 'Loyer boutique', 'amount' => 50000]);
    $deposit = Transaction::create(['user_id' => $user->id, 'type' => 'apport', 'name' => 'Apport de fonds', 'amount' => 100000, 'funding_source' => 'apport_personnel']);
    $withdrawal = Transaction::create(['user_id' => $user->id, 'type' => 'retrait', 'name' => 'Retrait', 'amount' => 20000]);
    Transaction::create(['user_id' => $user->id, 'type' => 'expense', 'name' => 'Électricité', 'amount' => 15000]);

    $byLoyer = $this->actingAs($user)->getJson('/api/transactions?q=loyer');
    $byLoyer->assertStatus(200);
    expect($byLoyer->json('meta.total'))->toBe(1);
    expect($byLoyer->json('data.0.id'))->toBe($expense->id);

    $byApport = $this->actingAs($user)->getJson('/api/transactions?q=apport');
    $byApport->assertStatus(200);
    expect($byApport->json('meta.total'))->toBe(1);
    expect($byApport->json('data.0.id'))->toBe($deposit->id);

    $byRetrait = $this->actingAs($user)->getJson('/api/transactions?q=retrait');
    $byRetrait->assertStatus(200);
    expect($byRetrait->json('meta.total'))->toBe(1);
    expect($byRetrait->json('data.0.id'))->toBe($withdrawal->id);
});

test('"dore" (sans accent) trouve la variation "Doré"', function () {
    $user = makeSearchTestUser();
    $article = makeSearchTestArticle($user, ['name' => 'Robe wax', 'type' => 'variable']);
    $variation = Variation::create(['id' => (string) Str::uuid(), 'article_id' => $article->id, 'name' => 'Doré', 'quantity' => 5, 'price' => 15000]);
    $otherVariation = Variation::create(['id' => (string) Str::uuid(), 'article_id' => $article->id, 'name' => 'Bleu', 'quantity' => 5, 'price' => 15000]);

    Transaction::create(['user_id' => $user->id, 'article_id' => $article->id, 'variable_id' => $variation->id, 'type' => 'sale', 'name' => 'Vente', 'amount' => 15000]);
    Transaction::create(['user_id' => $user->id, 'article_id' => $article->id, 'variable_id' => $otherVariation->id, 'type' => 'sale', 'name' => 'Vente', 'amount' => 15000]);

    $response = $this->actingAs($user)->getJson('/api/transactions?q=dore');

    $response->assertStatus(200);
    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.variable_id'))->toBe($variation->id);
});

test('"24/10/2026" renvoie seulement les transactions de ce jour (fuseau Africa/Abidjan)', function () {
    $user = makeSearchTestUser();
    $inDay = Transaction::create(['user_id' => $user->id, 'type' => 'expense', 'name' => 'Dans le jour', 'amount' => 1000]);
    $inDay->created_at = '2026-10-24 10:00:00';
    $inDay->timestamps = false;
    $inDay->save();

    $dayBefore = Transaction::create(['user_id' => $user->id, 'type' => 'expense', 'name' => 'Veille', 'amount' => 2000]);
    $dayBefore->created_at = '2026-10-23 23:59:00';
    $dayBefore->timestamps = false;
    $dayBefore->save();

    $dayAfter = Transaction::create(['user_id' => $user->id, 'type' => 'expense', 'name' => 'Lendemain', 'amount' => 3000]);
    $dayAfter->created_at = '2026-10-25 00:01:00';
    $dayAfter->timestamps = false;
    $dayAfter->save();

    $response = $this->actingAs($user)->getJson('/api/transactions?q=' . urlencode('24/10/2026'));

    $response->assertStatus(200);
    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.id'))->toBe($inDay->id);
});

test('"octobre 2026" renvoie tout le mois', function () {
    $user = makeSearchTestUser();
    $inMonth1 = Transaction::create(['user_id' => $user->id, 'type' => 'expense', 'name' => 'Début de mois', 'amount' => 1000]);
    $inMonth1->created_at = '2026-10-01 00:00:00';
    $inMonth1->timestamps = false;
    $inMonth1->save();

    $inMonth2 = Transaction::create(['user_id' => $user->id, 'type' => 'expense', 'name' => 'Fin de mois', 'amount' => 2000]);
    $inMonth2->created_at = '2026-10-31 23:00:00';
    $inMonth2->timestamps = false;
    $inMonth2->save();

    $outsideMonth = Transaction::create(['user_id' => $user->id, 'type' => 'expense', 'name' => 'Mois suivant', 'amount' => 3000]);
    $outsideMonth->created_at = '2026-11-01 00:00:00';
    $outsideMonth->timestamps = false;
    $outsideMonth->save();

    $response = $this->actingAs($user)->getJson('/api/transactions?q=' . urlencode('octobre 2026'));

    $response->assertStatus(200);
    expect($response->json('meta.total'))->toBe(2);
    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toContain($inMonth1->id, $inMonth2->id);
    expect($ids)->not->toContain($outsideMonth->id);
});

test('"25 000" trouve les transactions de 25 000 F exactement', function () {
    $user = makeSearchTestUser();
    $match = Transaction::create(['user_id' => $user->id, 'type' => 'expense', 'name' => 'Loyer', 'amount' => 25000]);
    Transaction::create(['user_id' => $user->id, 'type' => 'expense', 'name' => 'Autre', 'amount' => 25500]);

    $response = $this->actingAs($user)->getJson('/api/transactions?q=' . urlencode('25 000'));

    $response->assertStatus(200);
    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.id'))->toBe($match->id);
});

test('"robe M" : les deux mots doivent correspondre', function () {
    $user = makeSearchTestUser();
    $article = makeSearchTestArticle($user, ['name' => 'Robe wax', 'type' => 'variable']);
    $variationM = Variation::create(['id' => (string) Str::uuid(), 'article_id' => $article->id, 'name' => 'Taille M', 'quantity' => 5, 'price' => 15000]);
    $variationL = Variation::create(['id' => (string) Str::uuid(), 'article_id' => $article->id, 'name' => 'Taille L', 'quantity' => 5, 'price' => 15000]);

    $otherArticle = makeSearchTestArticle($user, ['name' => 'Sac M', 'type' => 'simple']);

    $match = Transaction::create(['user_id' => $user->id, 'article_id' => $article->id, 'variable_id' => $variationM->id, 'type' => 'sale', 'name' => 'Vente', 'amount' => 15000]);
    Transaction::create(['user_id' => $user->id, 'article_id' => $article->id, 'variable_id' => $variationL->id, 'type' => 'sale', 'name' => 'Vente', 'amount' => 15000]);
    Transaction::create(['user_id' => $user->id, 'article_id' => $otherArticle->id, 'type' => 'sale', 'name' => 'Vente', 'amount' => 5000]);

    $response = $this->actingAs($user)->getJson('/api/transactions?q=' . urlencode('robe M'));

    $response->assertStatus(200);
    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.id'))->toBe($match->id);
});

test('recherche + type=sale + plage de dates combinés', function () {
    $user = makeSearchTestUser();
    $article = makeSearchTestArticle($user, ['name' => 'Robe wax']);

    $matchSale = Transaction::create(['user_id' => $user->id, 'article_id' => $article->id, 'type' => 'sale', 'name' => 'Vente', 'amount' => 15000]);
    $matchSale->created_at = '2026-10-15 10:00:00';
    $matchSale->timestamps = false;
    $matchSale->save();

    // Même article, même type, mais HORS plage de dates.
    $outsideRange = Transaction::create(['user_id' => $user->id, 'article_id' => $article->id, 'type' => 'sale', 'name' => 'Vente', 'amount' => 15000]);
    $outsideRange->created_at = '2026-09-15 10:00:00';
    $outsideRange->timestamps = false;
    $outsideRange->save();

    // Dans la plage, même article, mais type expense (filtré par type=sale).
    $wrongType = Transaction::create(['user_id' => $user->id, 'article_id' => $article->id, 'type' => 'expense', 'name' => 'Robe retournée', 'amount' => 15000]);
    $wrongType->created_at = '2026-10-16 10:00:00';
    $wrongType->timestamps = false;
    $wrongType->save();

    $response = $this->actingAs($user)->getJson('/api/transactions?q=robe&type=sale&date_from=2026-10-01&date_to=2026-10-31');

    $response->assertStatus(200);
    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.id'))->toBe($matchSale->id);
});

test('la recherche trouve aussi par nom de client et numéro de facture (via la facture liée)', function () {
    $user = makeSearchTestUser();
    $client = Client::create(['user_id' => $user->id, 'name' => 'Awa Koné']);
    $invoice = Invoice::create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'invoice_number' => 'INV-2026-00042',
        'status' => 'paid',
        'issued_at' => '2026-10-20',
        'due_date' => '2026-10-27',
    ]);
    $match = Transaction::create(['user_id' => $user->id, 'invoice_id' => $invoice->id, 'type' => 'sale', 'name' => 'Vente', 'amount' => 15000]);
    $unrelated = Transaction::create(['user_id' => $user->id, 'type' => 'sale', 'name' => 'Vente', 'amount' => 5000]);

    $byClient = $this->actingAs($user)->getJson('/api/transactions?q=' . urlencode('Awa'));
    $byClient->assertStatus(200);
    expect($byClient->json('meta.total'))->toBe(1);
    expect($byClient->json('data.0.id'))->toBe($match->id);

    $byInvoiceNumber = $this->actingAs($user)->getJson('/api/transactions?q=' . urlencode('00042'));
    $byInvoiceNumber->assertStatus(200);
    expect($byInvoiceNumber->json('meta.total'))->toBe(1);
    expect($byInvoiceNumber->json('data.0.id'))->toBe($match->id);
    expect(collect($byInvoiceNumber->json('data'))->pluck('id')->all())->not->toContain($unrelated->id);
});

test('un "%" dans q ne casse pas la requête', function () {
    $user = makeSearchTestUser();
    Transaction::create(['user_id' => $user->id, 'type' => 'expense', 'name' => 'Loyer', 'amount' => 1000, 'note' => 'remise 10%']);

    $response = $this->actingAs($user)->getJson('/api/transactions?q=' . urlencode('10%'));

    $response->assertStatus(200);
    // Ne doit pas planter (500) — qu'il trouve 0 ou 1 résultat n'est pas le
    // point testé ici, seulement que "%" soit traité comme un caractère
    // littéral échappé, pas comme un joker LIKE incontrôlé.
});

test('les transactions d\'un autre utilisateur ne sont jamais renvoyées', function () {
    $user = makeSearchTestUser();
    $otherUser = makeSearchTestUser();
    $article = makeSearchTestArticle($otherUser, ['name' => 'Robe wax']);

    Transaction::create(['user_id' => $otherUser->id, 'article_id' => $article->id, 'type' => 'sale', 'name' => 'Vente', 'amount' => 15000]);

    $response = $this->actingAs($user)->getJson('/api/transactions?q=robe');

    $response->assertStatus(200);
    expect($response->json('meta.total'))->toBe(0);
    expect($response->json('data'))->toHaveCount(0);
});

test('sans aucun paramètre, le comportement historique est préservé (liste complète, pas de pagination)', function () {
    $user = makeSearchTestUser();
    Transaction::create(['user_id' => $user->id, 'type' => 'expense', 'name' => 'Loyer', 'amount' => 1000]);
    Transaction::create(['user_id' => $user->id, 'type' => 'expense', 'name' => 'Transport', 'amount' => 2000]);

    $response = $this->actingAs($user)->getJson('/api/transactions');

    $response->assertStatus(200);
    expect($response->json('data'))->toHaveCount(2);
    expect($response->json('meta.total'))->toBe(2);
    expect($response->json('meta.page'))->toBe(1);
});

test('la pagination fonctionne (page/per_page) et total reste le compte réel', function () {
    $user = makeSearchTestUser();
    foreach (range(1, 5) as $i) {
        Transaction::create(['user_id' => $user->id, 'type' => 'expense', 'name' => "Dépense {$i}", 'amount' => 1000 * $i]);
    }

    $response = $this->actingAs($user)->getJson('/api/transactions?page=1&per_page=2');

    $response->assertStatus(200);
    expect($response->json('data'))->toHaveCount(2);
    expect($response->json('meta.total'))->toBe(5);
    expect($response->json('meta.total_pages'))->toBe(3);
});

test('q de plus de 100 caractères est refusé (422)', function () {
    $user = makeSearchTestUser();

    $response = $this->actingAs($user)->getJson('/api/transactions?q=' . str_repeat('a', 101));

    $response->assertStatus(422);
});
