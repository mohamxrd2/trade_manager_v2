<?php

use App\Models\Article;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Schéma minimal, compatible SQLite (la suite de tests tourne en sqlite
| :memory:, cf. phpunit.xml). La chaîne complète de migrations Laravel ne
| peut PAS être utilisée ici : la toute première (0001_01_01_...) exécute
| `CREATE EXTENSION IF NOT EXISTS "uuid-ossp"`, une instruction Postgres qui
| casse sur SQLite (vérifié : `RefreshDatabase` est d'ailleurs déjà
| commenté dans tests/Pest.php pour cette raison). On recrée donc ici
| uniquement les tables nécessaires à ce test, avec les mêmes colonnes que
| les migrations réelles (y compris idempotency_key + sa contrainte unique
| ajoutée par 2026_10_12_000000_add_idempotency_key_to_transactions_table).
|--------------------------------------------------------------------------
*/
function createTestSchema(): void
{
    Schema::dropIfExists('notifications');
    Schema::dropIfExists('transactions');
    Schema::dropIfExists('operations');
    Schema::dropIfExists('operation_counters');
    Schema::dropIfExists('variations');
    Schema::dropIfExists('articles');
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
        $table->string('payment_method')->nullable();
        $table->text('note')->nullable();
        $table->uuid('operation_id')->nullable();
        $table->timestamps();
        $table->softDeletes();

        // Reproduit exactement la contrainte de
        // 2026_10_12_000000_add_idempotency_key_to_transactions_table.php
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
    // Toujours repartir de la connexion sqlite :memory: par défaut (cf.
    // phpunit.xml), même si le test précédent (course concurrente) a
    // reconfiguré temporairement la connexion vers un fichier partagé entre
    // deux connexions — fait ici (plutôt qu'en fin de ce test précédent)
    // pour que le nettoyage s'applique aussi si ce test précédent échoue.
    config(['database.connections.sqlite.database' => ':memory:']);
    DB::purge('sqlite');

    createTestSchema();
});

function makeUser(): User
{
    return User::create([
        'first_name' => 'Test',
        'last_name' => 'User',
        'username' => 'user_' . Str::random(8),
        'email' => Str::random(8) . '@example.test',
        'password' => 'irrelevant-for-this-test',
    ]);
}

function makeArticle(User $user, int $quantity = 100): Article
{
    return Article::create([
        'user_id' => $user->id,
        'name' => 'Article de test',
        'sale_price' => 10,
        'quantity' => $quantity,
        'type' => 'simple',
    ]);
}

test('une vente avec une clé d\'idempotence valide est créée normalement', function () {
    $user = makeUser();
    $article = makeArticle($user);

    $response = $this->actingAs($user)
        ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
        ->postJson('/api/transactions', [
            'type' => 'sale',
            'article_id' => $article->id,
            'quantity' => 2,
        ]);

    $response->assertStatus(201);
    expect($response->json('success'))->toBeTrue();
    expect(Transaction::count())->toBe(1);
});

test('rejouer la même clé d\'idempotence renvoie la transaction existante sans dupliquer ni re-décrémenter le stock', function () {
    $user = makeUser();
    $article = makeArticle($user, quantity: 100);
    $key = (string) Str::uuid();

    $payload = ['type' => 'sale', 'article_id' => $article->id, 'quantity' => 3];

    $first = $this->actingAs($user)
        ->withHeaders(['Idempotency-Key' => $key])
        ->postJson('/api/transactions', $payload);

    $second = $this->actingAs($user)
        ->withHeaders(['Idempotency-Key' => $key])
        ->postJson('/api/transactions', $payload);

    $first->assertStatus(201);
    $second->assertStatus(201);

    // Même transaction renvoyée les deux fois (même id), une seule ligne en base.
    expect($first->json('data.id'))->toBe($second->json('data.id'));
    expect(Transaction::count())->toBe(1);

    // Stock décrémenté une seule fois (sold_quantity = 3, pas 6).
    expect($article->fresh()->sold_quantity)->toBe(3);
});

test('deux clés différentes avec des champs identiques créent deux transactions distinctes (pas de faux positif)', function () {
    $user = makeUser();
    $article = makeArticle($user, quantity: 100);

    $payload = ['type' => 'sale', 'article_id' => $article->id, 'quantity' => 1];

    $first = $this->actingAs($user)
        ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
        ->postJson('/api/transactions', $payload);

    $second = $this->actingAs($user)
        ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
        ->postJson('/api/transactions', $payload);

    $first->assertStatus(201);
    $second->assertStatus(201);

    expect($first->json('data.id'))->not->toBe($second->json('data.id'));
    expect(Transaction::count())->toBe(2);
    expect($article->fresh()->sold_quantity)->toBe(2);
});

test('deux requêtes identiques SANS clé d\'idempotence restent toutes les deux créées (comportement inchangé, pas de déduplication par ressemblance)', function () {
    $user = makeUser();
    $article = makeArticle($user, quantity: 100);

    $payload = ['type' => 'sale', 'article_id' => $article->id, 'quantity' => 1];

    $first = $this->actingAs($user)->postJson('/api/transactions', $payload);
    $second = $this->actingAs($user)->postJson('/api/transactions', $payload);

    $first->assertStatus(201);
    $second->assertStatus(201);
    expect(Transaction::count())->toBe(2);
});

test('un en-tête Idempotency-Key mal formé est rejeté avec 422', function () {
    $user = makeUser();
    $article = makeArticle($user);

    $response = $this->actingAs($user)
        ->withHeaders(['Idempotency-Key' => 'not-a-uuid'])
        ->postJson('/api/transactions', [
            'type' => 'sale',
            'article_id' => $article->id,
            'quantity' => 1,
        ]);

    $response->assertStatus(422);
    expect(Transaction::count())->toBe(0);
});

test('une dépense rejouée avec la même clé n\'est créée qu\'une seule fois', function () {
    $user = makeUser();
    $key = (string) Str::uuid();
    $payload = ['type' => 'expense', 'name' => 'Loyer', 'amount' => 500];

    $first = $this->actingAs($user)
        ->withHeaders(['Idempotency-Key' => $key])
        ->postJson('/api/transactions', $payload);

    $second = $this->actingAs($user)
        ->withHeaders(['Idempotency-Key' => $key])
        ->postJson('/api/transactions', $payload);

    $first->assertStatus(201);
    $second->assertStatus(201);
    expect($first->json('data.id'))->toBe($second->json('data.id'));
    expect(Transaction::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Note sur le test de course concurrente (voir limites dans le rapport) :
| une tentative avec une VRAIE deuxième connexion SQLite (fichier partagé,
| busy_timeout) a été faite ici pour reproduire fidèlement la course au
| niveau base de données, mais SQLite verrouille tout le fichier dès qu'une
| transaction a lu une ligne : la connexion "concurrente" reste bloquée tant
| que la transaction du contrôleur n'est pas terminée, qui elle-même attend
| le retour du hook qui attend la connexion concurrente — un auto-blocage
| propre à l'exécution single-thread d'un test, pas à la logique applicative
| (en production, deux connexions Postgres distinctes n'ont pas ce problème :
| le verrouillage est au niveau ligne, pas au niveau fichier). Reproduire la
| course exacte demanderait un vrai second processus OS, hors de portée
| raisonnable ici. On teste donc séparément, de façon déterministe :
| 1) la logique de décision qui reconnaît une violation de contrainte
|    d'idempotence (ci-dessous, isolée dans isIdempotencyKeyConflict()) ;
| 2) que la requête de récupération renvoie bien la bonne ligne existante
|    (déjà couvert par les tests de rejeu ci-dessus, qui exercent le même
|    findTransactionByIdempotencyKey() utilisé dans le chemin de course).
|--------------------------------------------------------------------------
*/
test('isIdempotencyKeyConflict reconnaît précisément une violation de la contrainte (user_id, idempotency_key)', function () {
    $controller = new \App\Http\Controllers\API\TransactionController();
    $method = new ReflectionMethod($controller, 'isIdempotencyKeyConflict');
    $method->setAccessible(true);

    $makeException = function (string $code, string $message) {
        $pdoException = new \PDOException($message, 0);
        // QueryException attend un $code ; on simule aussi bien le cas
        // "string" (observé sous Postgres : '23505') que le cas où seul le
        // message porte l'information (observé sous SQLite : code '23000'
        // générique, le détail est dans le message).
        return new \Illuminate\Database\QueryException('sqlite', 'insert into "transactions" ...', [], new \Exception($message, (int) $code));
    };

    $conflictMessage = 'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: transactions.user_id, transactions.idempotency_key';
    $unrelatedMessage = 'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: transactions.some_other_column';

    expect($method->invoke($controller, $makeException('23000', $conflictMessage), 'a-key'))->toBeTrue();
    expect($method->invoke($controller, $makeException('23505', $conflictMessage), 'a-key'))->toBeTrue();
    // Même code, mais message qui ne concerne pas idempotency_key : ne doit
    // PAS être absorbé (doit continuer à remonter en 500).
    expect($method->invoke($controller, $makeException('23000', $unrelatedMessage), 'a-key'))->toBeFalse();
    // Code sans rapport avec une violation d'intégrité (ex: perte de
    // connexion) : ne doit jamais être traité comme un conflit d'idempotence.
    expect($method->invoke($controller, $makeException('08006', $conflictMessage), 'a-key'))->toBeFalse();
    // Aucune clé fournie sur cette requête : la notion de conflit
    // d'idempotence ne s'applique pas, quel que soit le message.
    expect($method->invoke($controller, $makeException('23000', $conflictMessage), null))->toBeFalse();
});
