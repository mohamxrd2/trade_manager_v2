<?php

use App\Models\Operation;
use App\Models\OperationCounter;
use App\Models\Transaction;
use App\Models\User;
use App\Services\OperationCodeGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Schéma minimal, compatible SQLite ET Postgres (voir la même note dans
| TransactionIdempotencyTest.php) — ajoute ici clients/invoices (pour le
| test de regroupement par facture), en plus du socle users/transactions/
| operations/operation_counters déjà repris des autres fichiers de test.
|--------------------------------------------------------------------------
*/
function createOperationsBackfillTestSchema(): void
{
    Schema::dropIfExists('invoices');
    Schema::dropIfExists('clients');
    Schema::dropIfExists('transactions');
    Schema::dropIfExists('operations');
    Schema::dropIfExists('operation_counters');
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
        $table->timestamps();
    });

    Schema::create('clients', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->string('name');
        $table->timestamps();
    });

    Schema::create('invoices', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->uuid('client_id');
        $table->string('invoice_number')->unique();
        $table->string('status')->default('draft');
        $table->decimal('total', 15, 2)->default(0);
        $table->date('issued_at');
        $table->date('due_date');
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

    createOperationsBackfillTestSchema();
});

function makeBackfillUser(): User
{
    return User::create([
        'first_name' => 'Test',
        'last_name' => 'User',
        'username' => 'user_' . Str::random(8),
        'email' => Str::random(8) . '@example.test',
        'password' => 'irrelevant-for-this-test',
    ]);
}

/*
|--------------------------------------------------------------------------
| OperationCodeGenerator
|--------------------------------------------------------------------------
*/

test('génère un code au format {Préfixe}-{AAMMJJ}-{compteur 4 chiffres}', function () {
    $user = makeBackfillUser();

    $code = OperationCodeGenerator::next($user->id, 'sale', '2026-10-26');

    expect($code)->toBe('V-261026-0001');
});

test('chaque type d\'opération a son propre préfixe', function () {
    $user = makeBackfillUser();

    expect(OperationCodeGenerator::next($user->id, 'sale', '2026-10-26'))->toStartWith('V-');
    expect(OperationCodeGenerator::next($user->id, 'expense', '2026-10-26'))->toStartWith('D-');
    expect(OperationCodeGenerator::next($user->id, 'apport', '2026-10-26'))->toStartWith('A-');
    expect(OperationCodeGenerator::next($user->id, 'retrait', '2026-10-26'))->toStartWith('R-');
});

test('le compteur s\'incrémente séquentiellement, par compte et par préfixe, sans doublon', function () {
    $user = makeBackfillUser();

    $first = OperationCodeGenerator::next($user->id, 'sale', '2026-10-26');
    $second = OperationCodeGenerator::next($user->id, 'sale', '2026-10-26');
    $third = OperationCodeGenerator::next($user->id, 'sale', '2026-10-26');

    expect([$first, $second, $third])->toBe(['V-261026-0001', 'V-261026-0002', 'V-261026-0003']);

    // Un autre préfixe le même jour, pour le même compte, a sa PROPRE séquence.
    $expense = OperationCodeGenerator::next($user->id, 'expense', '2026-10-26');
    expect($expense)->toBe('D-261026-0001');
});

test('le compteur repart à 0001 le jour suivant, par compte et par préfixe', function () {
    $user = makeBackfillUser();

    OperationCodeGenerator::next($user->id, 'sale', '2026-10-26');
    OperationCodeGenerator::next($user->id, 'sale', '2026-10-26');

    $nextDay = OperationCodeGenerator::next($user->id, 'sale', '2026-10-27');

    expect($nextDay)->toBe('V-261027-0001');
});

test('deux comptes différents ont chacun leur propre séquence le même jour', function () {
    $userA = makeBackfillUser();
    $userB = makeBackfillUser();

    $codeA = OperationCodeGenerator::next($userA->id, 'sale', '2026-10-26');
    $codeB = OperationCodeGenerator::next($userB->id, 'sale', '2026-10-26');

    expect($codeA)->toBe('V-261026-0001');
    expect($codeB)->toBe('V-261026-0001');
});

test('utilise et incrémente un compteur déjà existant sans créer de doublon', function () {
    $user = makeBackfillUser();

    OperationCounter::create([
        'user_id' => $user->id,
        'prefix' => 'V',
        'day' => '2026-10-26',
        'last_value' => 5,
    ]);

    $code = OperationCodeGenerator::next($user->id, 'sale', '2026-10-26');

    expect($code)->toBe('V-261026-0006');
    expect(OperationCounter::where('user_id', $user->id)->where('prefix', 'V')->where('day', '2026-10-26')->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Chemin existant (Transaction::create) — chaque transaction créée reçoit
| automatiquement sa propre opération à une seule ligne.
|--------------------------------------------------------------------------
*/

test('créer une transaction via le chemin existant lui donne automatiquement une opération à une seule ligne avec son code', function () {
    $user = makeBackfillUser();

    $transaction = Transaction::create([
        'user_id' => $user->id,
        'name' => 'Vente test',
        'amount' => 1500,
        'type' => 'sale',
    ]);

    expect($transaction->operation_id)->not->toBeNull();

    $operation = Operation::find($transaction->operation_id);

    expect($operation)->not->toBeNull();
    expect($operation->type)->toBe('sale');
    expect((float) $operation->total_amount)->toBe(1500.0);
    expect($operation->code)->toMatch('/^V-\d{6}-\d{4}$/');
    expect(Transaction::where('operation_id', $operation->id)->count())->toBe(1);
});

test('fixer operation_id avant la création n\'est jamais écrasé par le hook', function () {
    $user = makeBackfillUser();

    $existingOperation = Operation::create([
        'user_id' => $user->id,
        'type' => 'sale',
        'code' => OperationCodeGenerator::next($user->id, 'sale', now()),
        'total_amount' => 999,
        'occurred_at' => now(),
    ]);

    $transaction = Transaction::create([
        'user_id' => $user->id,
        'name' => 'Ligne de panier',
        'amount' => 500,
        'type' => 'sale',
        'operation_id' => $existingOperation->id,
    ]);

    expect($transaction->operation_id)->toBe($existingOperation->id);
    expect(Operation::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| operations:backfill
|--------------------------------------------------------------------------
*/

test('le backfill donne une opération à chaque transaction existante sans changer les totaux', function () {
    $user = makeBackfillUser();

    // Transactions créées directement en base (whereNull('operation_id') —
    // simule des données historiques antérieures à la fonctionnalité).
    DB::table('transactions')->insert([
        [
            'id' => Str::orderedUuid()->toString(),
            'user_id' => $user->id,
            'name' => 'Vente 1',
            'amount' => 1000,
            'type' => 'sale',
            'created_at' => '2026-10-20 10:00:00',
            'updated_at' => '2026-10-20 10:00:00',
        ],
        [
            'id' => Str::orderedUuid()->toString(),
            'user_id' => $user->id,
            'name' => 'Dépense 1',
            'amount' => 300,
            'type' => 'expense',
            'created_at' => '2026-10-20 11:00:00',
            'updated_at' => '2026-10-20 11:00:00',
        ],
    ]);

    $revenueBefore = (float) Transaction::where('type', 'sale')->sum('amount');
    $expenseBefore = (float) Transaction::where('type', 'expense')->sum('amount');
    $countBefore = Transaction::count();

    $this->artisan('operations:backfill', ['--force' => true])->assertSuccessful();

    expect(Transaction::count())->toBe($countBefore);
    expect(Transaction::whereNull('operation_id')->count())->toBe(0);
    expect((float) Transaction::where('type', 'sale')->sum('amount'))->toBe($revenueBefore);
    expect((float) Transaction::where('type', 'expense')->sum('amount'))->toBe($expenseBefore);
    expect(Operation::count())->toBe(2);

    foreach (Operation::all() as $operation) {
        $linesTotal = (float) Transaction::where('operation_id', $operation->id)->sum('amount');
        expect((float) $operation->total_amount)->toBe($linesTotal);
    }
});

test('le backfill regroupe les transactions d\'une même facture en une seule opération', function () {
    $user = makeBackfillUser();

    $clientId = Str::orderedUuid()->toString();
    DB::table('clients')->insert([
        'id' => $clientId,
        'user_id' => $user->id,
        'name' => 'Client test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $invoiceId = Str::orderedUuid()->toString();
    DB::table('invoices')->insert([
        'id' => $invoiceId,
        'user_id' => $user->id,
        'client_id' => $clientId,
        'invoice_number' => 'INV-0001',
        'status' => 'paid',
        'total' => 1800,
        'issued_at' => '2026-10-20',
        'due_date' => '2026-10-27',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('transactions')->insert([
        [
            'id' => Str::orderedUuid()->toString(),
            'user_id' => $user->id,
            'invoice_id' => $invoiceId,
            'name' => 'Article A',
            'amount' => 1200,
            'type' => 'sale',
            'created_at' => '2026-10-20 10:00:00',
            'updated_at' => '2026-10-20 10:00:00',
        ],
        [
            'id' => Str::orderedUuid()->toString(),
            'user_id' => $user->id,
            'invoice_id' => $invoiceId,
            'name' => 'Article B',
            'amount' => 600,
            'type' => 'sale',
            'created_at' => '2026-10-20 10:00:01',
            'updated_at' => '2026-10-20 10:00:01',
        ],
    ]);

    $this->artisan('operations:backfill', ['--force' => true])->assertSuccessful();

    expect(Operation::count())->toBe(1);

    $operation = Operation::first();
    expect((float) $operation->total_amount)->toBe(1800.0);
    expect($operation->invoice_id)->toBe($invoiceId);
    expect($operation->client_id)->toBe($clientId);
    expect(Transaction::where('operation_id', $operation->id)->count())->toBe(2);
});

test('le backfill est idempotent : relancer --force une seconde fois ne crée aucune opération supplémentaire', function () {
    $user = makeBackfillUser();

    DB::table('transactions')->insert([
        'id' => Str::orderedUuid()->toString(),
        'user_id' => $user->id,
        'name' => 'Vente 1',
        'amount' => 1000,
        'type' => 'sale',
        'created_at' => '2026-10-20 10:00:00',
        'updated_at' => '2026-10-20 10:00:00',
    ]);

    $this->artisan('operations:backfill', ['--force' => true])->assertSuccessful();
    expect(Operation::count())->toBe(1);

    $this->artisan('operations:backfill', ['--force' => true])->assertSuccessful();
    expect(Operation::count())->toBe(1);
});

test('le backfill en --dry-run (par défaut) ne modifie rien', function () {
    $user = makeBackfillUser();

    DB::table('transactions')->insert([
        'id' => Str::orderedUuid()->toString(),
        'user_id' => $user->id,
        'name' => 'Vente 1',
        'amount' => 1000,
        'type' => 'sale',
        'created_at' => '2026-10-20 10:00:00',
        'updated_at' => '2026-10-20 10:00:00',
    ]);

    $this->artisan('operations:backfill')->assertSuccessful();

    expect(Operation::count())->toBe(0);
    expect(Transaction::whereNull('operation_id')->count())->toBe(1);
});

test('le backfill n\'oublie pas une transaction supprimée (soft delete)', function () {
    $user = makeBackfillUser();

    $transactionId = Str::orderedUuid()->toString();
    DB::table('transactions')->insert([
        'id' => $transactionId,
        'user_id' => $user->id,
        'name' => 'Vente annulée',
        'amount' => 750,
        'type' => 'sale',
        'created_at' => '2026-10-20 10:00:00',
        'updated_at' => '2026-10-20 10:00:00',
        'deleted_at' => '2026-10-20 12:00:00',
    ]);

    $this->artisan('operations:backfill', ['--force' => true])->assertSuccessful();

    $transaction = Transaction::withTrashed()->find($transactionId);
    expect($transaction->operation_id)->not->toBeNull();
    expect(Transaction::withTrashed()->whereNull('operation_id')->count())->toBe(0);
});
