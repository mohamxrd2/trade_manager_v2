<?php

use App\Models\Article;
use App\Models\Client;
use App\Models\Collaborator;
use App\Models\Company;
use App\Models\MemberPermission;
use App\Models\Notification;
use App\Models\Operation;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserSetting;
use App\Models\Variation;
use App\Support\CurrentAccount;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Chantier "Équipe" — schéma minimal, compatible SQLite ET Postgres (même
| principe que les autres fichiers de test Feature). Union des tables
| nécessaires pour exercer : isolation par compte sur les 10 ressources,
| permissions par rôle, connexion des membres, journal d'activité.
|--------------------------------------------------------------------------
*/
function createTeamEquipeTestSchema(): void
{
    foreach ([
        'activity_logs', 'member_permissions', 'sessions', 'notifications', 'receipt_settings',
        'invoice_items', 'invoices', 'stock_replenishments', 'transactions',
        'operations', 'operation_counters', 'clients', 'collaborators',
        'variations', 'articles', 'user_settings', 'companies', 'users',
    ] as $table) {
        Schema::dropIfExists($table);
    }

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

    Schema::create('users', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('account_id')->nullable();
        $table->string('role')->nullable();
        $table->string('first_name');
        $table->string('last_name');
        $table->string('username')->unique();
        $table->string('email')->nullable()->unique();
        $table->string('phone')->nullable();
        $table->string('password');
        $table->decimal('company_share', 5, 2)->default(100.00);
        $table->string('profile_image')->nullable();
        $table->string('provider')->default('local');
        $table->string('provider_id')->nullable();
        $table->boolean('must_change_password')->default(false);
        $table->string('status')->default('active');
        $table->timestamp('last_login_at')->nullable();
        $table->timestamp('email_verified_at')->nullable();
        $table->rememberToken();
        $table->timestamps();
    });

    Schema::create('sessions', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->uuid('user_id')->nullable()->index();
        $table->string('ip_address', 45)->nullable();
        $table->text('user_agent')->nullable();
        $table->longText('payload');
        $table->integer('last_activity')->index();
    });

    Schema::create('companies', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id')->unique();
        $table->string('name');
        $table->string('code')->nullable()->unique();
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

    Schema::create('user_settings', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id')->unique();
        $table->string('currency')->default('FCFA');
        $table->integer('low_stock_threshold')->default(80);
        $table->string('language')->default('fr');
        $table->boolean('notify_stock_low')->default(true);
        $table->boolean('notify_invoice_paid')->default(true);
        $table->boolean('notify_invoice_overdue')->default(true);
        $table->boolean('notify_team_joined')->default(true);
        $table->timestamps();
    });

    Schema::create('articles', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->string('name');
        $table->decimal('sale_price', 15, 2);
        $table->decimal('price_max', 15, 2)->nullable();
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
        $table->decimal('cost_price', 15, 2)->nullable();
        $table->unsignedInteger('low_stock_threshold')->nullable();
        $table->string('image')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('collaborators', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->uuid('user_id_member')->nullable();
        $table->string('name');
        $table->string('phone', 20);
        $table->decimal('part', 5, 2);
        $table->decimal('wallet', 15, 2)->default(0);
        $table->string('image')->nullable();
        $table->timestamps();
    });

    Schema::create('clients', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->string('name');
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->string('payment_method')->default('cash');
        $table->text('billing_address')->nullable();
        $table->text('shipping_address')->nullable();
        $table->text('notes')->nullable();
        $table->timestamps();
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

    Schema::create('stock_replenishments', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->uuid('created_by_user_id')->nullable();
        $table->uuid('article_id');
        $table->uuid('variation_id')->nullable();
        $table->unsignedInteger('quantity_added');
        $table->string('note')->nullable();
        $table->timestamps();
    });

    Schema::create('invoices', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->uuid('created_by_user_id')->nullable();
        $table->uuid('company_id');
        $table->uuid('client_id');
        $table->string('invoice_number')->unique();
        $table->string('status')->default('draft');
        $table->decimal('subtotal', 15, 2)->default(0);
        $table->decimal('discount_amount', 15, 2)->default(0);
        $table->decimal('discount_percent', 5, 2)->default(0);
        $table->decimal('tax_amount', 15, 2)->default(0);
        $table->decimal('tax_percent', 5, 2)->default(0);
        $table->decimal('shipping_fee', 15, 2)->default(0);
        $table->decimal('total', 15, 2)->default(0);
        $table->text('billing_address')->nullable();
        $table->text('shipping_address')->nullable();
        $table->string('theme')->default('classic');
        $table->date('issued_at');
        $table->date('due_date');
        $table->timestamp('paid_at')->nullable();
        $table->text('notes')->nullable();
        $table->text('terms')->nullable();
        $table->string('pdf_path')->nullable();
        $table->timestamp('sent_at')->nullable();
        $table->timestamps();
    });

    Schema::create('invoice_items', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('invoice_id');
        $table->uuid('article_id')->nullable();
        $table->uuid('variation_id')->nullable();
        $table->string('name_snapshot');
        $table->text('description_snapshot')->nullable();
        $table->decimal('unit_price', 15, 2);
        $table->integer('quantity');
        $table->decimal('discount_percent', 5, 2)->default(0);
        $table->decimal('discount_amount', 15, 2)->default(0);
        $table->decimal('total_line', 15, 2);
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

    Schema::create('member_permissions', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->string('permission');
        $table->boolean('granted');
        $table->timestamps();
        $table->unique(['user_id', 'permission']);
    });

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

    createTeamEquipeTestSchema();
});

/*
|--------------------------------------------------------------------------
| Fabriques
|--------------------------------------------------------------------------
*/
function equipeOwner(string $tag): User
{
    $owner = User::create([
        'first_name' => 'Propriétaire',
        'last_name' => $tag,
        'username' => 'owner_' . $tag . '_' . Str::random(6),
        'email' => 'owner_' . $tag . '_' . Str::random(6) . '@example.test',
        'password' => Hash::make('Password123!'),
    ]);

    Company::create([
        'user_id' => $owner->id,
        'name' => 'Entreprise ' . $tag,
        'code' => 'CODE' . strtoupper($tag) . Str::upper(Str::random(4)),
        'email' => 'contact_' . $tag . '@example.test',
        'logo' => 'logos/' . $tag . '.png',
    ]);

    return $owner;
}

function equipeMember(User $owner, string $role, array $permissionAdjustments = [], array $overrides = []): User
{
    $member = User::create(array_merge([
        'account_id' => $owner->id,
        'first_name' => 'Membre',
        'last_name' => ucfirst($role),
        'username' => 'member_' . Str::random(8),
        'password' => Hash::make('123456'),
        'role' => $role,
        'must_change_password' => false,
        'status' => 'active',
        'company_share' => 0,
    ], $overrides));

    foreach ($permissionAdjustments as $permission => $granted) {
        MemberPermission::create([
            'user_id' => $member->id,
            'permission' => $permission,
            'granted' => $granted,
        ]);
    }

    return $member;
}

/*
|--------------------------------------------------------------------------
| A. ISOLATION — un membre (ou propriétaire) du compte A ne voit JAMAIS les
| données du compte B, et réciproquement (10 ressources).
|--------------------------------------------------------------------------
*/

test('isolation — articles', function () {
    $ownerA = equipeOwner('A');
    $ownerB = equipeOwner('B');
    $memberA = equipeMember($ownerA, 'stock_manager');

    $articleA = Article::create(['user_id' => $ownerA->id, 'name' => 'Robe A', 'sale_price' => 10000, 'quantity' => 5, 'type' => 'simple']);
    $articleB = Article::create(['user_id' => $ownerB->id, 'name' => 'Robe B', 'sale_price' => 20000, 'quantity' => 5, 'type' => 'simple']);

    $index = $this->actingAs($memberA)->getJson('/api/articles');
    $index->assertOk();
    expect($index->getContent())->toContain($articleA->id)->not->toContain($articleB->id);

    $this->actingAs($memberA)->getJson("/api/articles/{$articleB->id}")->assertStatus(404);
    $this->actingAs($ownerB)->getJson("/api/articles/{$articleA->id}")->assertStatus(404);
});

test('isolation — variations', function () {
    $ownerA = equipeOwner('A');
    $ownerB = equipeOwner('B');
    $memberA = equipeMember($ownerA, 'stock_manager');

    $articleA = Article::create(['user_id' => $ownerA->id, 'name' => 'Chaussure', 'type' => 'variable', 'sale_price' => 0, 'quantity' => 0]);
    $articleB = Article::create(['user_id' => $ownerB->id, 'name' => 'Chaussure', 'type' => 'variable', 'sale_price' => 0, 'quantity' => 0]);
    $variationA = Variation::create(['article_id' => $articleA->id, 'name' => '41', 'quantity' => 5, 'price' => 30000]);
    $variationB = Variation::create(['article_id' => $articleB->id, 'name' => '42', 'quantity' => 5, 'price' => 30000]);

    $index = $this->actingAs($memberA)->getJson('/api/variations');
    $index->assertOk();
    expect($index->getContent())->toContain($variationA->id)->not->toContain($variationB->id);

    $this->actingAs($ownerB)->getJson("/api/variations/{$variationA->id}")->assertStatus(404);
});

test('isolation — operations', function () {
    $ownerA = equipeOwner('A');
    $ownerB = equipeOwner('B');
    $memberA = equipeMember($ownerA, 'cashier');

    $opA = Operation::create([
        'user_id' => $ownerA->id, 'created_by_user_id' => $ownerA->id, 'type' => 'sale',
        'code' => 'V-TEST-A1', 'payment_method' => 'especes', 'total_amount' => 5000, 'occurred_at' => now(),
    ]);
    $opB = Operation::create([
        'user_id' => $ownerB->id, 'created_by_user_id' => $ownerB->id, 'type' => 'sale',
        'code' => 'V-TEST-B1', 'payment_method' => 'especes', 'total_amount' => 7000, 'occurred_at' => now(),
    ]);

    $this->actingAs($ownerB)->getJson("/api/operations/{$opA->id}")->assertStatus(404);
    $this->actingAs($memberA)->getJson("/api/operations/{$opB->id}")->assertStatus(404);
});

test('isolation — transactions', function () {
    $ownerA = equipeOwner('A');
    $ownerB = equipeOwner('B');

    $txA = Transaction::create(['user_id' => $ownerA->id, 'name' => 'Vente A', 'amount' => 1000, 'type' => 'sale']);
    $txB = Transaction::create(['user_id' => $ownerB->id, 'name' => 'Vente B', 'amount' => 2000, 'type' => 'sale']);

    $this->actingAs($ownerB)->getJson("/api/transactions/{$txA->id}")->assertStatus(404);
    $this->actingAs($ownerA)->getJson("/api/transactions/{$txB->id}")->assertStatus(404);
});

test('isolation — invoices', function () {
    $ownerA = equipeOwner('A');
    $ownerB = equipeOwner('B');
    $companyA = Company::where('user_id', $ownerA->id)->first();
    $clientA = Client::create(['user_id' => $ownerA->id, 'name' => 'Client A']);

    $invoiceA = \App\Models\Invoice::create([
        'user_id' => $ownerA->id,
        'company_id' => $companyA->id,
        'client_id' => $clientA->id,
        'invoice_number' => 'INV-TEST-A1',
        'status' => 'draft',
        'issued_at' => now()->toDateString(),
        'due_date' => now()->addDays(10)->toDateString(),
    ]);

    $this->actingAs($ownerB)->getJson("/api/invoices/{$invoiceA->id}")->assertStatus(404);
});

test('isolation — clients', function () {
    $ownerA = equipeOwner('A');
    $ownerB = equipeOwner('B');
    $memberA = equipeMember($ownerA, 'cashier');

    $clientA = Client::create(['user_id' => $ownerA->id, 'name' => 'Client A']);
    $clientB = Client::create(['user_id' => $ownerB->id, 'name' => 'Client B']);

    $index = $this->actingAs($memberA)->getJson('/api/clients');
    $index->assertOk();
    expect($index->getContent())->toContain($clientA->id)->not->toContain($clientB->id);

    $this->actingAs($ownerB)->getJson("/api/clients/{$clientA->id}")->assertStatus(404);
});

test('isolation — associés (collaborators)', function () {
    $ownerA = equipeOwner('A');
    $ownerB = equipeOwner('B');

    $collabA = Collaborator::create(['id' => Str::uuid()->toString(), 'user_id' => $ownerA->id, 'name' => 'Associé A', 'phone' => '0700000000', 'part' => 10]);
    $collabB = Collaborator::create(['id' => Str::uuid()->toString(), 'user_id' => $ownerB->id, 'name' => 'Associé B', 'phone' => '0700000001', 'part' => 10]);

    $index = $this->actingAs($ownerA)->getJson('/api/collaborators');
    $index->assertOk();
    expect($index->getContent())->toContain($collabA->id)->not->toContain($collabB->id);

    $this->actingAs($ownerB)->getJson("/api/collaborators/{$collabA->id}")->assertStatus(404);
});

test('isolation — paramètres (user_settings)', function () {
    $ownerA = equipeOwner('A');
    $ownerB = equipeOwner('B');

    $this->actingAs($ownerA)->putJson('/api/user/settings', ['currency' => 'EUR']);
    $this->actingAs($ownerB)->putJson('/api/user/settings', ['currency' => 'USD']);

    $this->actingAs($ownerA)->getJson('/api/user/settings')->assertJsonPath('data.currency', 'EUR');
    $this->actingAs($ownerB)->getJson('/api/user/settings')->assertJsonPath('data.currency', 'USD');
});

test('isolation — notifications', function () {
    $ownerA = equipeOwner('A');
    $ownerB = equipeOwner('B');

    $notifA = Notification::create(['id' => Str::uuid()->toString(), 'user_id' => $ownerA->id, 'title' => 'Pour A', 'message' => 'm']);
    $notifB = Notification::create(['id' => Str::uuid()->toString(), 'user_id' => $ownerB->id, 'title' => 'Pour B', 'message' => 'm']);

    $index = $this->actingAs($ownerA)->getJson('/api/notifications');
    $index->assertOk();
    expect($index->getContent())->toContain($notifA->id)->not->toContain($notifB->id);

    // Marquer comme lue une notification d'un AUTRE compte : introuvable, pas 200.
    $this->actingAs($ownerB)->putJson("/api/notifications/{$notifA->id}/read")->assertStatus(404);
});

test('isolation — analytics (summary)', function () {
    $ownerA = equipeOwner('A');
    $ownerB = equipeOwner('B');

    Transaction::create(['user_id' => $ownerA->id, 'name' => 'Vente A', 'amount' => 100000, 'type' => 'sale']);
    Transaction::create(['user_id' => $ownerB->id, 'name' => 'Vente B', 'amount' => 777777, 'type' => 'sale']);

    $summaryA = $this->actingAs($ownerA)->getJson('/api/analytics/summary?period=12m');
    $summaryA->assertOk();

    // Le total ne doit refléter QUE les ventes du compte A — jamais la
    // somme des deux comptes, ni le montant de B seul.
    $summaryA->assertJsonPath('data.kpis.revenue.value', 100000);
});

/*
|--------------------------------------------------------------------------
| B. PERMISSIONS — rôle "cashier" : autorisé sur sales.create/view_own/
| stock.view/clients.view, 403 ailleurs.
|--------------------------------------------------------------------------
*/

test('cashier : peut créer une vente (sales.create)', function () {
    $owner = equipeOwner('C');
    $cashier = equipeMember($owner, 'cashier');
    $article = Article::create(['user_id' => $owner->id, 'name' => 'Savon', 'sale_price' => 1000, 'quantity' => 50, 'type' => 'simple']);

    $response = $this->actingAs($cashier)->withHeader('Idempotency-Key', Str::uuid()->toString())->postJson('/api/operations', [
        'type' => 'sale',
        'payment_method' => 'especes',
        'lines' => [['article_id' => $article->id, 'quantity' => 2, 'unit_price' => 1000]],
    ]);

    $response->assertStatus(201);

    $operation = Operation::where('user_id', $owner->id)->first();
    expect($operation)->not->toBeNull();
    expect($operation->created_by_user_id)->toBe($cashier->id);
});

test('cashier : 403 sur analytics.view', function () {
    $owner = equipeOwner('C');
    $cashier = equipeMember($owner, 'cashier');

    $this->actingAs($cashier)->getJson('/api/analytics/summary')->assertStatus(403);
});

test('cashier : 403 sur la modification de prix (stock.edit absent)', function () {
    $owner = equipeOwner('C');
    $cashier = equipeMember($owner, 'cashier');
    $article = Article::create(['user_id' => $owner->id, 'name' => 'Savon', 'sale_price' => 1000, 'quantity' => 50, 'type' => 'simple']);

    $this->actingAs($cashier)->putJson("/api/articles/{$article->id}", [
        'name' => 'Savon', 'sale_price' => 2000, 'quantity' => 50,
    ])->assertStatus(403);
});

test('cashier : 403 sur deposits.manage (apport/retrait)', function () {
    $owner = equipeOwner('C');
    $cashier = equipeMember($owner, 'cashier');

    $this->actingAs($cashier)->withHeader('Idempotency-Key', Str::uuid()->toString())->postJson('/api/operations', [
        'type' => 'apport',
        'payment_method' => 'especes',
        'lines' => [['amount' => 50000, 'funding_source' => 'apport_personnel']],
    ])->assertStatus(403);
});

test('cashier : 403 sur team.manage et settings.manage', function () {
    $owner = equipeOwner('C');
    $cashier = equipeMember($owner, 'cashier');

    $this->actingAs($cashier)->getJson('/api/team')->assertStatus(403);
    $this->actingAs($cashier)->putJson('/api/user/settings', ['currency' => 'EUR'])->assertStatus(403);
    $this->actingAs($cashier)->putJson('/api/company', ['name' => 'Piratée'])->assertStatus(403);
});

test('cashier : LIT les paramètres et l’entreprise du compte (devise, langue, nom, logo), sans pouvoir les modifier', function () {
    $owner = equipeOwner('C');
    $this->actingAs($owner)->putJson('/api/user/settings', ['currency' => 'EUR'])->assertOk();
    $cashier = equipeMember($owner, 'cashier');

    $this->actingAs($cashier)->getJson('/api/user/settings')
        ->assertOk()
        ->assertJsonPath('data.currency', 'EUR');
    $this->actingAs($cashier)->getJson('/api/company')
        ->assertOk()
        ->assertJsonPath('data.user_id', $owner->id);
});

test('cashier : lit les réglages du ticket (pour imprimer), sans pouvoir les modifier', function () {
    $owner = equipeOwner('C');
    $cashier = equipeMember($owner, 'cashier');

    $this->actingAs($cashier)->getJson('/api/receipt-settings')->assertOk();
    $this->actingAs($cashier)->putJson('/api/receipt-settings', ['width' => '80mm'])->assertStatus(403);
});

test('cashier : peut supprimer SA PROPRE vente dans les 10 minutes, pas celle d’un autre caissier', function () {
    $owner = equipeOwner('C');
    // sales.delete_own_recent n'est PAS dans les permissions par défaut du
    // rôle cashier (voir Permissions::defaultsForRole()) — c'est un
    // ajustement OPTIONNEL par membre, explicitement accordé ici.
    $cashierA = equipeMember($owner, 'cashier', ['sales.delete_own_recent' => true]);
    $cashierB = equipeMember($owner, 'cashier');

    $ownSale = Operation::create([
        'user_id' => $owner->id, 'created_by_user_id' => $cashierA->id, 'type' => 'sale',
        'code' => 'V-TEST-OWN', 'payment_method' => 'especes', 'total_amount' => 5000,
        'occurred_at' => now(), 'created_at' => now(),
    ]);
    $othersSale = Operation::create([
        'user_id' => $owner->id, 'created_by_user_id' => $cashierB->id, 'type' => 'sale',
        'code' => 'V-TEST-OTHER', 'payment_method' => 'especes', 'total_amount' => 5000,
        'occurred_at' => now(), 'created_at' => now(),
    ]);

    $this->actingAs($cashierA)->deleteJson("/api/operations/{$othersSale->id}")->assertStatus(403);
    $this->actingAs($cashierA)->deleteJson("/api/operations/{$ownSale->id}")->assertStatus(200);
});

test('cashier : ne voit que ses propres opérations (sales.view_own)', function () {
    $owner = equipeOwner('C');
    $cashierA = equipeMember($owner, 'cashier');
    $cashierB = equipeMember($owner, 'cashier');

    $saleA = Operation::create([
        'user_id' => $owner->id, 'created_by_user_id' => $cashierA->id, 'type' => 'sale',
        'code' => 'V-TEST-CA', 'payment_method' => 'especes', 'total_amount' => 5000, 'occurred_at' => now(),
    ]);
    $saleB = Operation::create([
        'user_id' => $owner->id, 'created_by_user_id' => $cashierB->id, 'type' => 'sale',
        'code' => 'V-TEST-CB', 'payment_method' => 'especes', 'total_amount' => 5000, 'occurred_at' => now(),
    ]);

    $index = $this->actingAs($cashierA)->getJson('/api/operations');
    $index->assertOk();
    expect($index->getContent())->toContain($saleA->id)->not->toContain($saleB->id);
});

test('GET /api/user masque les champs financiers globaux pour un caissier', function () {
    $owner = equipeOwner('C');
    $cashier = equipeMember($owner, 'cashier');

    $response = $this->actingAs($cashier)->getJson('/api/user');

    $response->assertOk();
    foreach (['balance', 'total_sale', 'wallet', 'company_share'] as $field) {
        expect($response->json($field))->toBeNull();
    }
});

test('GET /api/user conserve les champs financiers pour le propriétaire', function () {
    $owner = equipeOwner('C');

    $response = $this->actingAs($owner)->getJson('/api/user');

    $response->assertOk();
    expect($response->json('balance'))->not->toBeNull();
});

test('caissier : peut noter une dépense par défaut ; un ajustement par membre peut le lui retirer', function () {
    $owner = equipeOwner('C');
    $cashier = equipeMember($owner, 'cashier');

    $this->actingAs($cashier)->withHeader('Idempotency-Key', Str::uuid()->toString())->postJson('/api/operations', [
        'type' => 'expense', 'payment_method' => 'especes', 'lines' => [['amount' => 3000, 'name' => 'Transport']],
    ])->assertStatus(201);

    MemberPermission::create(['user_id' => $cashier->id, 'permission' => 'expenses.create', 'granted' => false]);

    $this->actingAs($cashier->fresh())->withHeader('Idempotency-Key', Str::uuid()->toString())->postJson('/api/operations', [
        'type' => 'expense', 'payment_method' => 'especes', 'lines' => [['amount' => 3000, 'name' => 'Transport']],
    ])->assertStatus(403);
});

test('GET /api/user d’un membre : totaux du COMPTE (valeur du stock, articles, ventes), jamais 0 ni ceux d’un autre compte', function () {
    $owner = equipeOwner('S');
    $otherOwner = equipeOwner('T');
    Article::create(['user_id' => $owner->id, 'name' => 'Robe', 'sale_price' => 10000, 'quantity' => 5, 'type' => 'simple']);
    Article::create(['user_id' => $otherOwner->id, 'name' => 'Autre', 'sale_price' => 99999, 'quantity' => 9, 'type' => 'simple']);
    Transaction::create(['user_id' => $owner->id, 'name' => 'Vente', 'amount' => 7000, 'type' => 'sale']);

    config(['auth.defaults.guard' => 'web']); app('auth')->forgetGuards();
    $ownerPayload = $this->actingAs($owner)->getJson('/api/user')->assertOk()->json();
    expect((float) $ownerPayload['total_stock_value'])->toBe(50000.0);

    config(['auth.defaults.guard' => 'web']); app('auth')->forgetGuards();
    $stock = $this->actingAs(equipeMember($owner, 'stock_manager'))->getJson('/api/user')->assertOk()->json();
    expect((float) $stock['total_stock_value'])->toBe(50000.0);
    expect($stock['total_articles'])->toBe(1);
    expect($stock)->not->toHaveKey('total_sale'); // pas de wallet.view_balance : champs financiers masqués

    config(['auth.defaults.guard' => 'web']); app('auth')->forgetGuards();
    $manager = $this->actingAs(equipeMember($owner, 'manager'))->getJson('/api/user')->assertOk()->json();
    expect((float) $manager['total_sale'])->toBe((float) $ownerPayload['total_sale']);
    expect((float) $manager['total_sale'])->toBe(7000.0);
});

test('caissier : une dépense saisie via POST /api/transactions apparaît dans SES transactions et son solde du jour', function () {
    $owner = equipeOwner('C');
    $cashier = equipeMember($owner, 'cashier');

    $created = $this->actingAs($cashier)->withHeader('Idempotency-Key', Str::uuid()->toString())->postJson('/api/transactions', [
        'type' => 'expense', 'name' => 'Transport', 'amount' => 2500, 'payment_method' => 'especes',
    ])->assertStatus(201);

    $operation = Operation::find($created->json('data.operation_id'));
    expect($operation->created_by_user_id)->toBe($cashier->id);
    expect($operation->user_id)->toBe($owner->id);

    $ids = collect($this->actingAs($cashier)->getJson('/api/transactions')->assertOk()->json('data'))->pluck('id');
    expect($ids)->toContain($created->json('data.id'));

    $myDay = $this->actingAs($cashier)->getJson('/api/user')->json('my_day');
    expect((float) $myDay['expenses_total'])->toBe(2500.0);
    expect((float) $myDay['balance'])->toBe(-2500.0);
});

test('my_day du caissier : ventes, dépenses et solde du jour (les siens uniquement)', function () {
    $owner = equipeOwner('C');
    $cashier = equipeMember($owner, 'cashier');
    $other = equipeMember($owner, 'cashier');

    foreach ([[$cashier, 'sale', 10000], [$cashier, 'sale', 5000], [$cashier, 'expense', 2000], [$other, 'sale', 99000]] as $i => [$author, $type, $amount]) {
        Operation::create([
            'user_id' => $owner->id, 'created_by_user_id' => $author->id, 'type' => $type,
            'code' => 'MD-' . $i, 'payment_method' => 'especes', 'total_amount' => $amount, 'occurred_at' => now(),
        ]);
    }

    $myDay = $this->actingAs($cashier)->getJson('/api/user')->assertOk()->json('my_day');

    expect($myDay['sales_count'])->toBe(2);
    expect((float) $myDay['total'])->toBe(15000.0);
    expect($myDay['expenses_count'])->toBe(1);
    expect((float) $myDay['expenses_total'])->toBe(2000.0);
    expect((float) $myDay['balance'])->toBe(13000.0);
});

/*
|--------------------------------------------------------------------------
| C. CONNEXION DES MEMBRES — identifiant+code, must_change_password,
| suspension, rate limiting, révocation de session.
|--------------------------------------------------------------------------
*/

test('un membre se connecte avec son identifiant + son code à 6 chiffres', function () {
    $owner = equipeOwner('D');
    $member = equipeMember($owner, 'cashier', [], ['password' => Hash::make('654321')]);

    // POST /api/login passe par le guard 'web' (session) — Sanctum
    // (EnsureFrontendRequestsAreStateful) n'attache la session que si la
    // requête se présente comme venant du frontend (Referer/Origin dans
    // sanctum.stateful, "localhost" en fait partie par défaut).
    $response = $this->withHeader('Referer', 'http://localhost')->postJson('/api/login', [
        'login' => $member->username,
        'password' => '654321',
    ]);

    $response->assertOk();
    expect($response->json('id'))->toBe($member->id);

    // Même représentation que GET /api/user : permissions et caisse du jour,
    // jamais les champs financiers globaux (le front s'appuie dessus dès la
    // connexion, sans rechargement).
    expect($response->json('is_owner'))->toBeFalse();
    expect($response->json('permissions'))->toContain('sales.create')->not->toContain('wallet.view_balance');
    expect($response->json('my_day'))->toBeArray();
    foreach (['balance', 'total_sale', 'wallet', 'company_share', 'calculated_wallet'] as $field) {
        expect($response->json())->not->toHaveKey($field);
    }
});

test('un membre must_change_password=true est bloqué partout sauf changer son code', function () {
    $owner = equipeOwner('D');
    $member = equipeMember($owner, 'cashier', [], ['password' => Hash::make('111111'), 'must_change_password' => true]);

    $this->actingAs($member)->getJson('/api/articles')
        ->assertStatus(403)
        ->assertJsonPath('code', 'MUST_CHANGE_PASSWORD');

    $this->actingAs($member)->postJson('/api/me/change-code', [
        'current_password' => '111111',
        'new_password' => 'un-mot-de-passe-plus-long',
        'new_password_confirmation' => 'un-mot-de-passe-plus-long',
    ])->assertOk();

    expect($member->fresh()->must_change_password)->toBeFalse();
});

test('un membre suspendu est refusé à la connexion avec le message dédié', function () {
    $owner = equipeOwner('D');
    $member = equipeMember($owner, 'cashier', [], ['password' => Hash::make('222222'), 'status' => 'suspended']);

    $response = $this->withHeader('Referer', 'http://localhost')->postJson('/api/login', [
        'login' => $member->username,
        'password' => '222222',
    ]);

    $response->assertStatus(403);
    expect($response->json('message'))->toBe('Votre accès a été suspendu. Contactez le responsable.');
});

test('rate limiting : 5 tentatives échouées sur le même identifiant+IP bloquent la 6e', function () {
    $owner = equipeOwner('D');
    $member = equipeMember($owner, 'cashier', [], ['password' => Hash::make('333333')]);
    $this->withHeader('Referer', 'http://localhost');

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/login', ['login' => $member->username, 'password' => 'mauvais-code'])
            ->assertStatus(401);
    }

    $response = $this->postJson('/api/login', ['login' => $member->username, 'password' => 'mauvais-code']);
    $response->assertStatus(429);

    // Même après le verrou, le BON code ne passe pas tant que la fenêtre n'est pas écoulée.
    $this->postJson('/api/login', ['login' => $member->username, 'password' => '333333'])->assertStatus(429);
});

test('suspendre un membre révoque immédiatement ses sessions actives', function () {
    $owner = equipeOwner('D');
    $member = equipeMember($owner, 'cashier');

    DB::table('sessions')->insert([
        'id' => Str::random(40),
        'user_id' => $member->id,
        'payload' => base64_encode('irrelevant'),
        'last_activity' => time(),
    ]);

    expect(DB::table('sessions')->where('user_id', $member->id)->count())->toBe(1);

    $this->actingAs($owner)->postJson("/api/team/{$member->id}/suspend")->assertOk();

    expect(DB::table('sessions')->where('user_id', $member->id)->count())->toBe(0);
    expect($member->fresh()->status)->toBe('suspended');
});

test('réinitialiser le code d’un membre révoque ses sessions et impose must_change_password', function () {
    $owner = equipeOwner('D');
    $member = equipeMember($owner, 'cashier');

    DB::table('sessions')->insert([
        'id' => Str::random(40),
        'user_id' => $member->id,
        'payload' => base64_encode('irrelevant'),
        'last_activity' => time(),
    ]);

    $response = $this->actingAs($owner)->postJson("/api/team/{$member->id}/reset-code");

    $response->assertOk();
    expect($response->json('data.login_code'))->toMatch('/^\d{6}$/');
    expect(DB::table('sessions')->where('user_id', $member->id)->count())->toBe(0);
    expect($member->fresh()->must_change_password)->toBeTrue();
});

test('changer le rôle d’un membre révoque ses sessions', function () {
    $owner = equipeOwner('D');
    $member = equipeMember($owner, 'cashier');

    DB::table('sessions')->insert([
        'id' => Str::random(40),
        'user_id' => $member->id,
        'payload' => base64_encode('irrelevant'),
        'last_activity' => time(),
    ]);

    $this->actingAs($owner)->patchJson("/api/team/{$member->id}", ['role' => 'stock_manager'])->assertOk();

    expect(DB::table('sessions')->where('user_id', $member->id)->count())->toBe(0);
    expect($member->fresh()->role)->toBe('stock_manager');
});

test('création de membre : username {CODE}-{PRENOM} unique, code à 6 chiffres, must_change_password=true', function () {
    $owner = equipeOwner('D');

    $response = $this->actingAs($owner)->postJson('/api/team', [
        'name' => 'Koffi Yao',
        'has_access' => true,
        'role' => 'cashier',
    ]);

    $response->assertStatus(201);
    $username = $response->json('data.credentials.username');
    $code = $response->json('data.credentials.code');

    expect($username)->toContain('-KOFFI');
    expect($code)->toMatch('/^\d{6}$/');

    $member = User::where('username', $username)->first();
    expect($member->must_change_password)->toBeTrue();
    expect($member->account_id)->toBe($owner->id);
});

/*
|--------------------------------------------------------------------------
| D. AUTEUR DES ACTIONS — une vente faite par un membre reste rattachée au
| compte du PROPRIÉTAIRE, avec created_by_user_id = le membre.
|--------------------------------------------------------------------------
*/

test('une vente créée par un membre est rattachée au compte du propriétaire', function () {
    $owner = equipeOwner('E');
    $cashier = equipeMember($owner, 'cashier');
    $article = Article::create(['user_id' => $owner->id, 'name' => 'Bracelet', 'sale_price' => 2500, 'quantity' => 20, 'type' => 'simple']);

    $this->actingAs($cashier)->withHeader('Idempotency-Key', Str::uuid()->toString())->postJson('/api/operations', [
        'type' => 'sale',
        'payment_method' => 'especes',
        'lines' => [['article_id' => $article->id, 'quantity' => 1, 'unit_price' => 2500]],
    ])->assertStatus(201);

    $operation = Operation::first();
    expect($operation->user_id)->toBe($owner->id);
    expect($operation->created_by_user_id)->toBe($cashier->id);

    $transaction = Transaction::first();
    expect($transaction->user_id)->toBe($owner->id);
});
