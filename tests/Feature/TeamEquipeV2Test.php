<?php

use App\Models\Collaborator;
use App\Models\Company;
use App\Models\MemberPermission;
use App\Models\Operation;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Chantier "Équipe" étape 2 — schéma minimal, compatible SQLite ET
| Postgres (même principe que tests/Feature/TeamEquipeTest.php, nom de
| fonction/fabriques distincts pour éviter toute redéclaration).
|--------------------------------------------------------------------------
*/
function createTeamEquipeV2TestSchema(): void
{
    foreach ([
        'activity_logs', 'member_permissions', 'sessions', 'notifications', 'transactions',
        'operations', 'operation_counters', 'collaborators', 'variations', 'articles',
        'user_settings', 'companies', 'users',
    ] as $table) {
        Schema::dropIfExists($table);
    }

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
        $table->string('phone', 20)->nullable();
        $table->string('email')->nullable();
        $table->decimal('part', 5, 2);
        $table->decimal('wallet', 15, 2)->default(0);
        $table->string('image')->nullable();
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

    createTeamEquipeV2TestSchema();
});

/*
|--------------------------------------------------------------------------
| Fabriques
|--------------------------------------------------------------------------
*/
function v2Owner(string $tag): User
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

/**
 * Même effet que $this->actingAs($user), mais sûr à utiliser PLUSIEURS FOIS
 * dans le MÊME test avec des utilisateurs DIFFÉRENTS pour simuler des
 * requêtes HTTP indépendantes (chantier équipe, bug account_id — tests
 * 9+ ci-dessous).
 *
 * Pourquoi : auth:sanctum, une fois une 1ʳᵉ requête authentifiée, appelle
 * Auth::shouldUse('sanctum') qui RÉÉCRIT config('auth.defaults.guard')
 * pour le reste du test (AuthManager::setDefaultDriver() mute directement
 * la config, un singleton PARTAGÉ entre tous les appels HTTP d'un même
 * test — contrairement à une vraie requête PHP-FPM, qui reconstruit tout
 * le conteneur à chaque fois). $this->actingAs($user) sans guard explicite
 * résout alors le guard 'sanctum' (plus 'web'), dont
 * Illuminate\Auth\RequestGuard mémorise l'utilisateur résolu POUR TOUJOURS
 * (RequestGuard::user() : "we do not want to fetch the user data on every
 * call"). Résultat observé en écrivant les tests de cette section :
 * AuthController::user() (qui lit explicitement Auth::guard('web')) reste
 * bloqué sur le TOUT PREMIER utilisateur du test, alors que
 * CurrentAccount::id() (qui lit Auth::user(), le guard par défaut) suit
 * correctement chaque actingAs() — une divergence invisible en production
 * (une requête réelle ne partage jamais ce cache), mais qui aurait rendu
 * les tests d'isolation ci-dessous faussement verts.
 *
 * Remettre explicitement le défaut à 'web' + vider le cache de guards
 * avant CHAQUE actingAs() élimine cette divergence : les deux guards sont
 * reconstruits à neuf, cohérents entre eux, comme au tout premier appel.
 */
function v2ActingAs($test, User $user)
{
    config(['auth.defaults.guard' => 'web']);
    app('auth')->forgetGuards();

    return $test->actingAs($user);
}

function v2Member(User $owner, string $role, array $overrides = []): User
{
    return User::create(array_merge([
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
}

/*
|--------------------------------------------------------------------------
| 1. Ajout d'un caissier — identifiants renvoyés UNE SEULE FOIS
|--------------------------------------------------------------------------
*/

test('ajouter un caissier renvoie les identifiants une seule fois, jamais relus ensuite', function () {
    $owner = v2Owner('A');

    $response = $this->actingAs($owner)->postJson('/api/team', [
        'name' => 'Aya Touré',
        'has_access' => true,
        'role' => 'cashier',
    ]);

    $response->assertStatus(201);
    $username = $response->json('data.credentials.username');
    $code = $response->json('data.credentials.code');

    expect($username)->not->toBeNull();
    expect($code)->toMatch('/^\d{6}$/');
    expect($response->json('data.credentials.login_url'))->toContain('/login');

    $memberId = $response->json('data.team_member.id');

    // Une deuxième lecture (liste de l'équipe) ne renvoie JAMAIS le code en clair.
    $list = $this->actingAs($owner)->getJson('/api/team');
    $list->assertOk();
    expect($list->getContent())->not->toContain($code);

    $member = User::find($memberId);
    expect(Hash::check($code, $member->password))->toBeTrue();
    expect($member->must_change_password)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| 2. Associé SANS accès, puis accès octroyé plus tard
|--------------------------------------------------------------------------
*/

test('ajouter un associé sans accès, puis lui donner un accès plus tard', function () {
    $owner = v2Owner('B');

    $response = $this->actingAs($owner)->postJson('/api/team', [
        'name' => 'Boua Kouassi',
        'phone' => '0700000000',
        'is_partner' => true,
        'share' => 15,
    ]);

    $response->assertStatus(201);
    expect($response->json('data'))->not->toHaveKey('credentials');
    $teamMemberId = $response->json('data.team_member.id');

    $collaborator = Collaborator::first();
    expect($collaborator->part)->toEqual(15);
    expect($teamMemberId)->toBe($collaborator->id);
    expect($collaborator->user_id_member)->toBeNull();

    $accessResponse = $this->actingAs($owner)->postJson("/api/team/{$collaborator->id}/access", [
        'role' => 'accountant',
    ]);

    $accessResponse->assertStatus(201);
    expect($accessResponse->json('data.credentials.code'))->toMatch('/^\d{6}$/');
    // L'id unifié BASCULE vers l'id du compte d'accès une fois qu'il existe.
    $newId = $accessResponse->json('data.team_member.id');
    expect($newId)->not->toBe($collaborator->id);

    $collaborator->refresh();
    expect($collaborator->user_id_member)->toBe($newId);

    $member = User::find($newId);
    expect($member->role)->toBe('accountant');
    expect($member->account_id)->toBe($owner->id);
});

/*
|--------------------------------------------------------------------------
| 3. Personne à la fois associée ET gérante
|--------------------------------------------------------------------------
*/

test('ajouter une personne à la fois associée et gérante', function () {
    $owner = v2Owner('C');

    $response = $this->actingAs($owner)->postJson('/api/team', [
        'name' => 'Chantal Diabaté',
        'is_partner' => true,
        'share' => 20,
        'has_access' => true,
        'role' => 'manager',
    ]);

    $response->assertStatus(201);
    expect($response->json('data.credentials.code'))->toMatch('/^\d{6}$/');

    $teamMember = $response->json('data.team_member');
    expect($teamMember['is_partner'])->toBeTrue();
    expect($teamMember['part'])->toEqual(20);
    expect($teamMember['role'])->toBe('manager');
    expect($teamMember['access'])->toBe('active');

    $collaborator = Collaborator::first();
    expect($collaborator->user_id_member)->not->toBeNull();
    expect($collaborator->user_id_member)->toBe($teamMember['id']);
});

/*
|--------------------------------------------------------------------------
| 4. Suspension / réactivation
|--------------------------------------------------------------------------
*/

test('suspension : jetons révoqués et connexion refusée ; réactivation : connexion fonctionne', function () {
    $owner = v2Owner('D');
    $member = v2Member($owner, 'cashier', ['password' => Hash::make('654321')]);

    DB::table('sessions')->insert([
        'id' => Str::random(40),
        'user_id' => $member->id,
        'payload' => base64_encode('irrelevant'),
        'last_activity' => time(),
    ]);

    $this->actingAs($owner)->postJson("/api/team/{$member->id}/suspend")->assertOk();

    expect(DB::table('sessions')->where('user_id', $member->id)->count())->toBe(0);

    $this->withHeader('Referer', 'http://localhost')
        ->postJson('/api/login', ['login' => $member->username, 'password' => '654321'])
        ->assertStatus(403);

    $this->actingAs($owner)->postJson("/api/team/{$member->id}/reactivate")->assertOk();

    $this->withHeader('Referer', 'http://localhost')
        ->postJson('/api/login', ['login' => $member->username, 'password' => '654321'])
        ->assertOk();
});

/*
|--------------------------------------------------------------------------
| 5. Réinitialisation du code
|--------------------------------------------------------------------------
*/

test('réinitialiser le code : l’ancien ne marche plus, must_change_password=true', function () {
    $owner = v2Owner('E');
    $member = v2Member($owner, 'cashier', ['password' => Hash::make('111111')]);

    $response = $this->actingAs($owner)->postJson("/api/team/{$member->id}/reset-code");
    $response->assertOk();
    $newCode = $response->json('data.login_code');

    expect($newCode)->toMatch('/^\d{6}$/');
    expect($newCode)->not->toBe('111111');

    $this->withHeader('Referer', 'http://localhost')
        ->postJson('/api/login', ['login' => $member->username, 'password' => '111111'])
        ->assertStatus(401);

    $this->withHeader('Referer', 'http://localhost')
        ->postJson('/api/login', ['login' => $member->username, 'password' => $newCode])
        ->assertOk();

    expect($member->fresh()->must_change_password)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| 6. Changement de rôle caissier -> gérant
|--------------------------------------------------------------------------
*/

test('changement de rôle caissier -> gérant : nouvelles permissions appliquées, sessions révoquées', function () {
    $owner = v2Owner('F');
    $member = v2Member($owner, 'cashier');

    // Avant : un caissier n'a pas accès aux analyses.
    $this->actingAs($member)->getJson('/api/analytics/summary')->assertStatus(403);

    DB::table('sessions')->insert([
        'id' => Str::random(40),
        'user_id' => $member->id,
        'payload' => base64_encode('irrelevant'),
        'last_activity' => time(),
    ]);

    $response = $this->actingAs($owner)->patchJson("/api/team/{$member->id}", ['role' => 'manager']);
    $response->assertOk();

    expect(DB::table('sessions')->where('user_id', $member->id)->count())->toBe(0);
    expect($member->fresh()->role)->toBe('manager');

    // Après : un gérant a accès aux analyses (nouvelles permissions effectives).
    $this->actingAs($member->fresh())->getJson('/api/analytics/summary')->assertOk();
});

/*
|--------------------------------------------------------------------------
| 7. Suppression d'un membre avec des ventes — l'historique reste
|--------------------------------------------------------------------------
*/

test('supprimer un membre qui a des ventes : les ventes restent, avec son nom comme auteur', function () {
    $owner = v2Owner('G');
    $member = v2Member($owner, 'cashier');

    $sale = Operation::create([
        'user_id' => $owner->id,
        'created_by_user_id' => $member->id,
        'type' => 'sale',
        'code' => 'V-TEST-G1',
        'payment_method' => 'especes',
        'total_amount' => 7500,
        'occurred_at' => now(),
    ]);

    $this->actingAs($owner)->deleteJson("/api/team/{$member->id}")->assertOk();

    expect($member->fresh()->status)->toBe('suspended');
    expect(User::find($member->id))->not->toBeNull(); // compte DÉSACTIVÉ, pas supprimé

    $saleResponse = $this->actingAs($owner)->getJson("/api/operations/{$sale->id}");
    $saleResponse->assertOk();
    expect($saleResponse->json('data.created_by.id'))->toBe($member->id);
    expect($saleResponse->json('data.author_name'))->toContain('Membre');
});

test('GET /api/transactions expose created_by (auteur) sur chaque ligne, sans casser apport/retrait', function () {
    $owner = v2Owner('H');
    $member = v2Member($owner, 'cashier');

    $this->actingAs($member);
    $tx = Transaction::create([
        'user_id' => $owner->id,
        'name' => 'Vente H',
        'amount' => 5000,
        'type' => 'sale',
    ]);

    $response = $this->actingAs($owner)->getJson('/api/transactions');
    $response->assertOk();

    $row = collect($response->json('data'))->firstWhere('id', $tx->id);
    expect($row['created_by']['id'])->toBe($member->id);
    expect($row['created_by']['role'])->toBe('cashier');

    // Non-régression du bug corrigé : created_by sur Transaction ne doit
    // jamais lazy-charger un User complet (coûteux, et casse en pratique
    // sur un schéma de test minimal sans table articles) — un apport doit
    // continuer à répondre 201 normalement.
    $depositResponse = $this->actingAs($owner)->postJson('/api/transactions', [
        'type' => 'apport',
        'funding_source' => 'apport_personnel',
        'amount' => 10000,
        'payment_method' => 'especes',
    ]);
    $depositResponse->assertStatus(201);
});

test('supprimer un associé rend sa part au propriétaire', function () {
    $owner = v2Owner('G2');

    $response = $this->actingAs($owner)->postJson('/api/team', [
        'name' => 'Associé G2',
        'is_partner' => true,
        'share' => 30,
    ]);
    $collaboratorId = $response->json('data.team_member.id');

    $shareBefore = (string) $owner->fresh()->company_share;

    $this->actingAs($owner)->deleteJson("/api/team/{$collaboratorId}")->assertOk();

    expect(Collaborator::find($collaboratorId))->toBeNull();
    expect((string) $owner->fresh()->company_share)->toBe(bcadd($shareBefore, '30', 2));
});

/*
|--------------------------------------------------------------------------
| 8. Le propriétaire est protégé partout
|--------------------------------------------------------------------------
*/

test('le propriétaire ne peut jamais être supprimé, suspendu, ni changer de rôle', function () {
    $owner = v2Owner('H');

    $this->actingAs($owner)->deleteJson("/api/team/{$owner->id}")->assertStatus(403);
    $this->actingAs($owner)->postJson("/api/team/{$owner->id}/suspend")->assertStatus(403);
    $this->actingAs($owner)->patchJson("/api/team/{$owner->id}", ['role' => 'manager'])->assertStatus(403);

    expect($owner->fresh()->status)->toBe('active');
});

/*
|--------------------------------------------------------------------------
| 9. Caissier : 403 sur /api/team, my_day sur GET /api/user
|--------------------------------------------------------------------------
*/

test('caissier : GET /api/team renvoie 403', function () {
    $owner = v2Owner('I');
    $cashier = v2Member($owner, 'cashier');

    $this->actingAs($cashier)->getJson('/api/team')->assertStatus(403);
});

test('caissier : GET /api/user n’a aucun champ financier global, mais contient my_day', function () {
    $owner = v2Owner('I');
    $cashier = v2Member($owner, 'cashier');
    $article = \App\Models\Article::create(['user_id' => $owner->id, 'name' => 'Stylo', 'sale_price' => 500, 'quantity' => 100, 'type' => 'simple']);

    Operation::create([
        'user_id' => $owner->id,
        'created_by_user_id' => $cashier->id,
        'type' => 'sale',
        'code' => 'V-TEST-I1',
        'payment_method' => 'especes',
        'total_amount' => 500,
        'occurred_at' => now(),
    ]);

    $response = $this->actingAs($cashier)->getJson('/api/user');
    $response->assertOk();

    foreach (['balance', 'total_sale', 'wallet', 'company_share', 'total_expense', 'calculated_wallet', 'total_deposit', 'total_withdrawal'] as $field) {
        expect($response->json($field))->toBeNull();
    }

    expect($response->json('my_day.sales_count'))->toBe(1);
    expect((float) $response->json('my_day.total'))->toBe(500.0);
    expect($response->json('is_owner'))->toBeFalse();
    expect($response->json('permissions'))->toContain('sales.create');
});

/*
|--------------------------------------------------------------------------
| 10. Limite du nombre de membres
|--------------------------------------------------------------------------
*/

test('la limite du nombre de membres est respectée (422 au-delà)', function () {
    config(['team.max_members' => 2]);
    $owner = v2Owner('J');

    $this->actingAs($owner)->postJson('/api/team', ['name' => 'Membre 1', 'has_access' => true, 'role' => 'cashier'])->assertStatus(201);
    $this->actingAs($owner)->postJson('/api/team', ['name' => 'Membre 2', 'has_access' => true, 'role' => 'cashier'])->assertStatus(201);

    $response = $this->actingAs($owner)->postJson('/api/team', ['name' => 'Membre 3', 'has_access' => true, 'role' => 'cashier']);
    $response->assertStatus(422);
    expect(User::where('account_id', $owner->id)->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| 11. Somme des parts <= 100 % (inchangée)
|--------------------------------------------------------------------------
*/

test('la somme des parts ne peut pas dépasser 100 %, via /api/team', function () {
    $owner = v2Owner('K');

    $this->actingAs($owner)->postJson('/api/team', ['name' => 'Associé 1', 'is_partner' => true, 'share' => 60])->assertStatus(201);

    $response = $this->actingAs($owner)->postJson('/api/team', ['name' => 'Associé 2', 'is_partner' => true, 'share' => 50]);
    $response->assertStatus(422);
    expect($response->json('errors.share.0'))->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| 12. Les anciens endpoints /api/collaborators fonctionnent toujours
|--------------------------------------------------------------------------
*/

test('les anciens endpoints /api/collaborators fonctionnent toujours (compatibilité)', function () {
    $owner = v2Owner('L');

    $create = $this->actingAs($owner)->postJson('/api/collaborators', [
        'name' => 'Associé Legacy',
        'phone' => '0700000099',
        'part' => 25,
    ]);
    $create->assertStatus(201);
    $collaboratorId = $create->json('data.id');

    $this->actingAs($owner)->getJson('/api/collaborators')->assertOk();
    $this->actingAs($owner)->getJson("/api/collaborators/{$collaboratorId}")->assertOk();

    $this->actingAs($owner)->putJson("/api/collaborators/{$collaboratorId}", ['part' => 40])
        ->assertOk()
        ->assertJsonPath('data.part', '40.00');

    // La même règle de somme des parts (même service que /api/team) s'applique.
    $this->actingAs($owner)->postJson('/api/collaborators', ['name' => 'Autre', 'phone' => '0700000098', 'part' => 65])
        ->assertStatus(422);

    $this->actingAs($owner)->deleteJson("/api/collaborators/{$collaboratorId}")->assertOk();
    expect(Collaborator::find($collaboratorId))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Associés existants migrés : is_partner=true, has_access=false
|--------------------------------------------------------------------------
*/

test('un associé existant (créé avant ce chantier) apparaît dans /api/team avec has_access=false', function () {
    $owner = v2Owner('M');

    $legacyCollaborator = Collaborator::create([
        'id' => Str::uuid()->toString(),
        'user_id' => $owner->id,
        'name' => 'Associé historique',
        'phone' => '0700000077',
        'part' => 12,
    ]);

    $response = $this->actingAs($owner)->getJson('/api/team');
    $response->assertOk();

    $people = collect($response->json('data'));
    $person = $people->firstWhere('id', $legacyCollaborator->id);

    expect($person)->not->toBeNull();
    expect($person['is_partner'])->toBeTrue();
    expect($person['access'])->toBe('none');
    expect((float) $person['part'])->toBe(12.0);
});

/*
|--------------------------------------------------------------------------
| GET /api/team/roles et GET /api/team/activity
|--------------------------------------------------------------------------
*/

test('GET /api/team/roles renvoie les rôles avec permissions par défaut et libellés fr/en', function () {
    $owner = v2Owner('N');

    $response = $this->actingAs($owner)->getJson('/api/team/roles');
    $response->assertOk();

    $roles = collect($response->json('data'))->keyBy('role');
    expect($roles->has('cashier'))->toBeTrue();
    expect($roles->has('owner'))->toBeFalse();
    expect($roles['cashier']['label']['fr'])->toBe('Caissier');
    expect($roles['cashier']['label']['en'])->toBe('Cashier');
    expect($roles['cashier']['permissions'])->toContain('sales.create');
});

test('GET /api/team/activity liste le journal, filtrable par membre', function () {
    $owner = v2Owner('O');
    $member = v2Member($owner, 'cashier');

    $this->actingAs($owner)->postJson("/api/team/{$member->id}/suspend")->assertOk();
    $this->actingAs($owner)->postJson("/api/team/{$member->id}/reactivate")->assertOk();

    $all = $this->actingAs($owner)->getJson('/api/team/activity');
    $all->assertOk();
    expect(count($all->json('data')))->toBeGreaterThanOrEqual(2);

    $filtered = $this->actingAs($owner)->getJson('/api/team/activity?member=' . $member->id);
    $filtered->assertOk();
    foreach ($filtered->json('data') as $entry) {
        expect($entry['user_id'])->toBe($member->id);
    }
});

/*
|--------------------------------------------------------------------------
| created_by= sur GET /api/operations (sales.view_all requis)
|--------------------------------------------------------------------------
*/

test('created_by= filtre les opérations par membre pour qui a sales.view_all', function () {
    $owner = v2Owner('P');
    $cashierA = v2Member($owner, 'cashier');
    $cashierB = v2Member($owner, 'cashier');

    MemberPermission::create(['user_id' => $cashierA->id, 'permission' => 'sales.view_all', 'granted' => true]);

    Operation::create([
        'user_id' => $owner->id, 'created_by_user_id' => $cashierA->id, 'type' => 'sale',
        'code' => 'V-TEST-PA', 'payment_method' => 'especes', 'total_amount' => 1000, 'occurred_at' => now(),
    ]);
    Operation::create([
        'user_id' => $owner->id, 'created_by_user_id' => $cashierB->id, 'type' => 'sale',
        'code' => 'V-TEST-PB', 'payment_method' => 'especes', 'total_amount' => 2000, 'occurred_at' => now(),
    ]);

    $response = $this->actingAs($cashierA)->getJson('/api/operations?created_by=' . $cashierB->id);
    $response->assertOk();
    expect($response->getContent())->toContain('V-TEST-PB')->not->toContain('V-TEST-PA');
});

/*
|--------------------------------------------------------------------------
| 8. Email déjà utilisé — 422 propre, jamais un 500 (violation SQL brute)
|--------------------------------------------------------------------------
*/

test('POST /api/team avec un email déjà utilisé renvoie 422, pas un 500', function () {
    $owner = v2Owner('Q');
    $other = v2Owner('Q2'); // un email qui existe déjà, sur un AUTRE compte

    $response = $this->actingAs($owner)->postJson('/api/team', [
        'name' => 'Koffi Doublon',
        'email' => $other->email,
        'has_access' => true,
        'role' => 'cashier',
    ]);

    $response->assertStatus(422);
    expect($response->json('errors.email.0'))->toBe('Cet email est déjà utilisé par un autre compte.');
    expect(User::where('email', $other->email)->count())->toBe(1); // aucune ligne créée en double
});

test('PATCH /api/team/{id} avec l\'email d\'un AUTRE compte renvoie 422 ; garder son propre email ne casse rien', function () {
    $owner = v2Owner('R');
    $taken = v2Owner('R2');
    $member = v2Member($owner, 'cashier');

    $this->actingAs($owner)->patchJson("/api/team/{$member->id}", ['email' => $taken->email])
        ->assertStatus(422);

    $this->actingAs($owner)->patchJson("/api/team/{$member->id}", ['email' => $member->email])
        ->assertOk();
});

/*
|--------------------------------------------------------------------------
| 9. Bug critique sécurité — account_id mal rattaché à un AUTRE compte
|--------------------------------------------------------------------------
| Un membre créé par le compte A doit TOUJOURS avoir account_id = A, même
| si deux comptes créent des membres en alternance dans la même suite (pour
| détecter un éventuel état conservé entre deux requêtes - CurrentAccount
| ou autre singleton), et même si le frontend envoie un account_id/user_id/
| owner_id falsifié : ces champs ne sont jamais lus, ils doivent être
| totalement ignorés par le serveur.
*/

test('deux comptes qui créent des membres en alternance : account_id ne se mélange jamais', function () {
    $ownerA = v2Owner('ALT_A');
    $ownerB = v2Owner('ALT_B');

    $resp1 = v2ActingAs($this, $ownerA)->postJson('/api/team', [
        'name' => 'Membre A1', 'has_access' => true, 'role' => 'cashier',
    ])->assertStatus(201);
    $member1 = User::find($resp1->json('data.team_member.id'));
    expect($member1->account_id)->toBe($ownerA->id);

    $resp2 = v2ActingAs($this, $ownerB)->postJson('/api/team', [
        'name' => 'Membre B1', 'has_access' => true, 'role' => 'cashier',
    ])->assertStatus(201);
    $member2 = User::find($resp2->json('data.team_member.id'));
    expect($member2->account_id)->toBe($ownerB->id);

    $resp3 = v2ActingAs($this, $ownerA)->postJson('/api/team', [
        'name' => 'Membre A2', 'has_access' => true, 'role' => 'stock_manager',
    ])->assertStatus(201);
    $member3 = User::find($resp3->json('data.team_member.id'));
    expect($member3->account_id)->toBe($ownerA->id);

    // Aucun membre de A ne doit jamais porter l'account_id de B, et inversement.
    expect(User::where('account_id', $ownerA->id)->pluck('id'))
        ->toContain($member1->id, $member3->id)
        ->not->toContain($member2->id);
    expect(User::where('account_id', $ownerB->id)->pluck('id'))
        ->toContain($member2->id)
        ->not->toContain($member1->id, $member3->id);
});

test('un account_id/user_id/owner_id falsifié envoyé par le frontend est ignoré — le membre est créé pour le compte connecté', function () {
    $ownerA = v2Owner('SPOOF_A');
    $ownerB = v2Owner('SPOOF_B');

    $response = v2ActingAs($this, $ownerA)->postJson('/api/team', [
        'name' => 'Membre Usurpé',
        'has_access' => true,
        'role' => 'cashier',
        // Champs falsifiés : jamais lus par TeamController::store(), donc
        // ignorés — le membre doit être créé pour A (le compte connecté),
        // jamais pour B.
        'account_id' => $ownerB->id,
        'user_id' => $ownerB->id,
        'owner_id' => $ownerB->id,
    ])->assertStatus(201);

    $member = User::find($response->json('data.team_member.id'));
    expect($member->account_id)->toBe($ownerA->id);
    expect($member->account_id)->not->toBe($ownerB->id);
});

test('grantAccess : collaborator et compte d\'accès créés dans la même transaction partagent le même account_id', function () {
    $owner = v2Owner('CONSIST');

    $partnerResponse = $this->actingAs($owner)->postJson('/api/team', [
        'name' => 'Associé Cohérent', 'is_partner' => true, 'share' => 20,
    ])->assertStatus(201);
    $collaboratorId = $partnerResponse->json('data.team_member.id');

    $this->actingAs($owner)->postJson("/api/team/{$collaboratorId}/access", [
        'role' => 'manager',
    ])->assertStatus(201);

    $collaborator = Collaborator::find($collaboratorId);
    $member = User::find($collaborator->user_id_member);

    expect($member->account_id)->toBe($collaborator->user_id);
    expect($member->account_id)->toBe($owner->id);
});

test('member de A ne voit aucune personne ni donnée de B via /api/team et /api/user', function () {
    $ownerA = v2Owner('ISO_A');
    $ownerB = v2Owner('ISO_B');
    $memberOfA = v2Member($ownerA, 'manager');
    $memberOfB = v2Member($ownerB, 'cashier');

    // GET /api/team nécessite team.manage (le propriétaire l'a toujours,
    // voir Gate::before) : A ne doit jamais voir B dans sa liste, ni
    // inversement, même avec deux comptes/membres actifs en parallèle.
    $teamAsOwnerA = v2ActingAs($this, $ownerA)->getJson('/api/team');
    $teamAsOwnerA->assertOk();
    $idsA = collect($teamAsOwnerA->json('data'))->pluck('id')->all();
    expect($idsA)->toContain($ownerA->id, $memberOfA->id);
    expect($idsA)->not->toContain($ownerB->id, $memberOfB->id);

    $teamAsOwnerB = v2ActingAs($this, $ownerB)->getJson('/api/team');
    $teamAsOwnerB->assertOk();
    $idsB = collect($teamAsOwnerB->json('data'))->pluck('id')->all();
    expect($idsB)->toContain($ownerB->id, $memberOfB->id);
    expect($idsB)->not->toContain($ownerA->id, $memberOfA->id);

    // GET /api/user : le membre de A ne doit voir que SON propre compte,
    // rattaché à A, jamais à B.
    $userAsMemberA = v2ActingAs($this, $memberOfA)->getJson('/api/user');
    $userAsMemberA->assertOk();
    expect($userAsMemberA->json('id'))->toBe($memberOfA->id);
    expect($userAsMemberA->json('account_id'))->toBe($ownerA->id);
    expect($userAsMemberA->json('account_id'))->not->toBe($ownerB->id);
});

test('team:audit (lecture seule) ne signale aucune incohérence sur des données saines', function () {
    $owner = v2Owner('AUDIT_OK');
    $partnerResponse = $this->actingAs($owner)->postJson('/api/team', [
        'name' => 'Associé Audité', 'is_partner' => true, 'share' => 10,
    ])->assertStatus(201);
    $this->actingAs($owner)->postJson("/api/team/{$partnerResponse->json('data.team_member.id')}/access", [
        'role' => 'accountant',
    ])->assertStatus(201);

    $this->artisan('team:audit')->assertExitCode(0);
});

test('team:audit détecte une incohérence introduite directement en base (hors API)', function () {
    $owner = v2Owner('AUDIT_BAD');
    $other = v2Owner('AUDIT_OTHER');

    $partnerResponse = $this->actingAs($owner)->postJson('/api/team', [
        'name' => 'Associé Corrompu', 'is_partner' => true, 'share' => 10,
    ])->assertStatus(201);
    $collaboratorId = $partnerResponse->json('data.team_member.id');
    $this->actingAs($owner)->postJson("/api/team/{$collaboratorId}/access", [
        'role' => 'accountant',
    ])->assertStatus(201);

    // Corruption directe en base (hors API), pour simuler la situation
    // signalée : account_id d'un membre pointant sur un AUTRE compte.
    $collaborator = Collaborator::find($collaboratorId);
    User::where('id', $collaborator->user_id_member)->update(['account_id' => $other->id]);

    $this->artisan('team:audit')->assertExitCode(1);
});

test('team:repair en dry-run ne modifie rien ; avec --force corrige account_id', function () {
    $owner = v2Owner('REPAIR');
    $other = v2Owner('REPAIR_OTHER');

    $partnerResponse = $this->actingAs($owner)->postJson('/api/team', [
        'name' => 'Associé À Réparer', 'is_partner' => true, 'share' => 10,
    ])->assertStatus(201);
    $collaboratorId = $partnerResponse->json('data.team_member.id');
    $this->actingAs($owner)->postJson("/api/team/{$collaboratorId}/access", [
        'role' => 'accountant',
    ])->assertStatus(201);

    $collaborator = Collaborator::find($collaboratorId);
    $memberId = $collaborator->user_id_member;
    User::where('id', $memberId)->update(['account_id' => $other->id]);

    $this->artisan('team:repair')->assertExitCode(0);
    expect(User::find($memberId)->account_id)->toBe($other->id); // dry-run : rien n'a changé

    $this->artisan('team:repair', ['--force' => true])->assertExitCode(0);
    expect(User::find($memberId)->account_id)->toBe($owner->id); // corrigé
});
