<?php

use App\Models\Company;
use App\Models\Operation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Schéma minimal, compatible SQLite ET Postgres — voir la même note dans
| tests/Feature/OperationsBasketTest.php.
|--------------------------------------------------------------------------
*/
function createReceiptSettingsTestSchema(): void
{
    Schema::dropIfExists('transactions');
    Schema::dropIfExists('operations');
    Schema::dropIfExists('receipt_settings');
    Schema::dropIfExists('companies');
    Schema::dropIfExists('variations');
    Schema::dropIfExists('articles');
    Schema::dropIfExists('clients');
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

    Schema::create('companies', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id')->unique();
        $table->string('name');
        $table->string('sector')->nullable();
        $table->text('headquarters')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->string('legal_status')->nullable();
        $table->string('bank_account_number')->nullable();
        $table->string('tax_id')->nullable();
        $table->string('logo')->nullable();
        $table->timestamps();
    });

    Schema::create('receipt_settings', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id')->unique();
        $table->string('width')->default('58mm');
        $table->boolean('show_logo')->default(true);
        $table->boolean('show_address')->default(true);
        $table->boolean('show_phone')->default(true);
        $table->boolean('show_tax_id')->default(true);
        $table->string('footer_message', 120)->nullable();
        $table->boolean('show_qr')->default(true);
        $table->boolean('show_waelly_mention')->default(true);
        $table->boolean('auto_print')->default(false);
        $table->timestamps();
    });

    Schema::create('clients', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->string('name');
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

    createReceiptSettingsTestSchema();
});

function receiptUser(): User
{
    return User::create([
        'first_name' => 'Awa',
        'last_name' => 'Koné',
        'username' => 'user_' . Str::random(8),
        'email' => Str::random(8) . '@example.test',
        'password' => 'irrelevant-for-this-test',
    ]);
}

/*
|--------------------------------------------------------------------------
| GET /api/receipt-settings — valeurs par défaut au premier appel
|--------------------------------------------------------------------------
*/

test('GET crée et renvoie les réglages par défaut si aucun n’existe encore', function () {
    $user = receiptUser();

    $response = $this->actingAs($user)->getJson('/api/receipt-settings');

    $response->assertOk();
    $response->assertJsonPath('data.width', '58mm');
    $response->assertJsonPath('data.show_logo', true);
    $response->assertJsonPath('data.show_qr', true);
    $response->assertJsonPath('data.show_waelly_mention', true);
    $response->assertJsonPath('data.auto_print', false);
    $response->assertJsonPath('data.footer_message', null);

    expect(DB::table('receipt_settings')->where('user_id', $user->id)->count())->toBe(1);
});

test('GET ne crée pas une deuxième ligne au second appel', function () {
    $user = receiptUser();

    $this->actingAs($user)->getJson('/api/receipt-settings');
    $this->actingAs($user)->getJson('/api/receipt-settings');

    expect(DB::table('receipt_settings')->where('user_id', $user->id)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| PUT /api/receipt-settings
|--------------------------------------------------------------------------
*/

test('PUT met à jour uniquement les champs envoyés, garde le reste', function () {
    $user = receiptUser();
    $this->actingAs($user)->getJson('/api/receipt-settings'); // crée la ligne par défaut

    $response = $this->actingAs($user)->putJson('/api/receipt-settings', [
        'width' => '80mm',
        'show_qr' => false,
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.width', '80mm');
    $response->assertJsonPath('data.show_qr', false);
    // Champs non envoyés : inchangés (toujours leurs valeurs par défaut).
    $response->assertJsonPath('data.show_logo', true);
    $response->assertJsonPath('data.show_waelly_mention', true);
});

test('PUT accepte un message de bas de page personnalisé (120 caractères max)', function () {
    $user = receiptUser();

    $response = $this->actingAs($user)->putJson('/api/receipt-settings', [
        'footer_message' => 'À bientôt chez nous !',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.footer_message', 'À bientôt chez nous !');
});

test('PUT refuse un message de bas de page de plus de 120 caractères', function () {
    $user = receiptUser();

    $response = $this->actingAs($user)->putJson('/api/receipt-settings', [
        'footer_message' => str_repeat('a', 121),
    ]);

    $response->assertStatus(422);
});

test('PUT refuse une largeur inconnue', function () {
    $user = receiptUser();

    $response = $this->actingAs($user)->putJson('/api/receipt-settings', [
        'width' => '112mm',
    ]);

    $response->assertStatus(422);
});

test('les réglages sont bien séparés par compte', function () {
    $userA = receiptUser();
    $userB = receiptUser();

    $this->actingAs($userA)->putJson('/api/receipt-settings', ['width' => '80mm']);
    $this->actingAs($userB)->putJson('/api/receipt-settings', ['width' => '58mm']);

    $responseA = $this->actingAs($userA)->getJson('/api/receipt-settings');
    $responseB = $this->actingAs($userB)->getJson('/api/receipt-settings');

    $responseA->assertJsonPath('data.width', '80mm');
    $responseB->assertJsonPath('data.width', '58mm');
});

/*
|--------------------------------------------------------------------------
| Entreprise : téléphone + RCCM/contribuable (nouveaux champs)
|--------------------------------------------------------------------------
*/

test('PUT /api/company accepte et renvoie phone + tax_id', function () {
    $user = receiptUser();

    $response = $this->actingAs($user)->putJson('/api/company', [
        'name' => 'Boutique Awa',
        'phone' => '+225 07 00 00 00 00',
        'tax_id' => 'CI-ABJ-2024-B-12345',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.phone', '+225 07 00 00 00 00');
    $response->assertJsonPath('data.tax_id', 'CI-ABJ-2024-B-12345');

    // Ré-authentifie avec une instance User fraîchement récupérée : sinon
    // actingAs() réutilise le même objet PHP qu'au PUT ci-dessus, dont la
    // relation `company` a été mise en cache à `null` (ligne pas encore
    // créée à ce moment-là) — un comportement propre à actingAs() dans les
    // tests, jamais au vrai cycle de requête HTTP (toujours un `Auth::user()`
    // frais en production).
    $show = $this->actingAs($user->fresh())->getJson('/api/company');
    $show->assertJsonPath('data.phone', '+225 07 00 00 00 00');
    $show->assertJsonPath('data.tax_id', 'CI-ABJ-2024-B-12345');
});

/*
|--------------------------------------------------------------------------
| GET /api/operations/{id} : "Caissier : {nom}" (author_name)
|--------------------------------------------------------------------------
*/

test('GET /api/operations/{id} expose author_name d’après created_by_user_id', function () {
    $owner = receiptUser();
    $cashier = User::create([
        'first_name' => 'Jean',
        'last_name' => 'Yao',
        'username' => 'user_' . Str::random(8),
        'email' => Str::random(8) . '@example.test',
        'password' => 'irrelevant-for-this-test',
    ]);

    $operation = Operation::create([
        'user_id' => $owner->id,
        'created_by_user_id' => $cashier->id,
        'type' => 'sale',
        'code' => 'V-261028-0001',
        'payment_method' => 'especes',
        'total_amount' => 12000,
        'occurred_at' => now(),
    ]);

    $response = $this->actingAs($owner)->getJson("/api/operations/{$operation->id}");

    $response->assertOk();
    $response->assertJsonPath('data.author_name', 'Jean Yao');
});

test('GET /api/operations/{id} retombe sur le compte propriétaire si aucun auteur distinct', function () {
    $owner = receiptUser();

    $operation = Operation::create([
        'user_id' => $owner->id,
        'created_by_user_id' => null,
        'type' => 'sale',
        'code' => 'V-261028-0002',
        'payment_method' => 'especes',
        'total_amount' => 5000,
        'occurred_at' => now(),
    ]);

    $response = $this->actingAs($owner)->getJson("/api/operations/{$operation->id}");

    $response->assertOk();
    $response->assertJsonPath('data.author_name', 'Awa Koné');
});
