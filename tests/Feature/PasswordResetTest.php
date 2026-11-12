<?php

use App\Http\Controllers\API\PasswordResetController;
use App\Mail\PasswordChanged;
use App\Mail\PasswordResetCode as PasswordResetCodeMail;
use App\Models\PasswordResetCode;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
| Mot de passe oublié — schéma minimal, compatible SQLite ET Postgres
| (DB_CONNECTION=pgsql DB_DATABASE=waelly_test pour le second).
*/

const PR_NEW_PASSWORD = 'Nouveau#Pass9';
const PR_OLD_PASSWORD = 'Ancien#Pass1';

function createPasswordResetTestSchema(): void
{
    foreach (['password_reset_codes', 'transactions', 'articles', 'activity_logs', 'sessions', 'personal_access_tokens', 'user_settings', 'companies', 'users'] as $table) {
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
    Schema::create('companies', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id')->unique();
        $table->string('name');
        $table->timestamps();
    });
    Schema::create('user_settings', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id')->unique();
        $table->string('currency')->default('FCFA');
        $table->string('language')->default('fr');
        $table->integer('low_stock_threshold')->default(80);
        $table->timestamps();
    });
    // Pour les totaux renvoyés avec l'utilisateur après la connexion automatique.
    Schema::create('articles', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->integer('quantity')->default(0);
        $table->decimal('sale_price', 15, 2)->default(0);
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('transactions', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->uuid('article_id')->nullable();
        $table->string('type');
        $table->integer('quantity')->nullable();
        $table->decimal('amount', 15, 2)->default(0);
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('personal_access_tokens', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuidMorphs('tokenable');
        $table->text('name');
        $table->string('token', 64)->unique();
        $table->text('abilities')->nullable();
        $table->timestamp('last_used_at')->nullable();
        $table->timestamp('expires_at')->nullable();
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
    Schema::create('activity_logs', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('account_id');
        $table->uuid('user_id')->nullable();
        $table->string('action');
        $table->string('subject_type')->nullable();
        $table->uuid('subject_id')->nullable();
        $table->json('details')->nullable();
        $table->timestamps();
    });
    // Même définition que la migration 2026_11_12_090000.
    (require database_path('migrations/2026_11_12_090000_create_password_reset_codes_table.php'))->up();
}

beforeEach(function () {
    createPasswordResetTestSchema();
    // Comme le front : langue de l'appli (lib/api.ts) et requête « stateful »
    // pour Sanctum (sinon pas de session, donc pas de connexion automatique).
    $this->withHeaders(['Accept-Language' => 'fr', 'Referer' => 'http://localhost']);
    RateLimiter::clear('password-forgot:ip:127.0.0.1');
    foreach (['awa@example.com', 'social@example.com', 'membre@example.com', 'suspendu@example.com', 'personne@example.com'] as $email) {
        RateLimiter::clear('password-forgot:email:' . sha1($email));
    }
    Mail::fake();
});

function prUser(array $attrs = []): User
{
    $id = (string) Str::uuid();
    DB::table('users')->insert(array_merge([
        'id' => $id,
        'first_name' => 'Awa',
        'last_name' => 'Koné',
        'username' => 'awa' . substr($id, 0, 8),
        'email' => 'awa@example.com',
        'password' => Hash::make(PR_OLD_PASSWORD),
        'email_verified_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ], $attrs));

    return User::find($id);
}

/** Dernier code envoyé par e-mail (le seul endroit où il existe en clair). */
function prSentCode(): string
{
    $mails = Mail::sent(PasswordResetCodeMail::class);
    expect($mails)->not->toBeEmpty();

    return $mails->last()->code;
}

function prForgot($test, string $email = 'awa@example.com')
{
    return $test->postJson('/api/password/forgot', ['email' => $email]);
}

function prVerify($test, string $code, string $email = 'awa@example.com')
{
    return $test->postJson('/api/password/verify', ['email' => $email, 'code' => $code]);
}

function prReset($test, string $token, array $overrides = [])
{
    return $test->postJson('/api/password/reset', array_merge([
        'email' => 'awa@example.com',
        'reset_token' => $token,
        'password' => PR_NEW_PASSWORD,
        'password_confirmation' => PR_NEW_PASSWORD,
    ], $overrides));
}

function prWrongCode(string $code): string
{
    return $code === '000000' ? '111111' : '000000';
}

it("envoie un code à 6 chiffres à un propriétaire, avec le code dans l'objet", function () {
    prUser();

    prForgot($this)->assertOk()->assertJsonPath('success', true);

    Mail::assertSent(PasswordResetCodeMail::class, function (PasswordResetCodeMail $mail) {
        app()->setLocale($mail->locale);
        expect($mail->code)->toMatch('/^\d{6}$/');
        expect($mail->socialProvider)->toBeNull();
        $mail->assertHasSubject('Votre code Waelly : ' . $mail->code);
        $mail->assertSeeInHtml($mail->code);
        $mail->assertSeeInText('15 minutes');

        return $mail->hasTo('awa@example.com');
    });
});

it("répond exactement pareil pour un e-mail inconnu, sans rien envoyer", function () {
    prUser();
    $known = prForgot($this)->json();
    $unknown = prForgot($this, 'personne@example.com')->assertOk()->json();

    expect($unknown)->toBe($known);
    Mail::assertSent(PasswordResetCodeMail::class, 1);
});

it('explique la connexion Google à un compte créé avec Google, et joint quand même un code', function () {
    prUser(['email' => 'social@example.com', 'provider' => 'google', 'provider_id' => 'g-1']);

    prForgot($this, 'social@example.com')->assertOk();

    Mail::assertSent(PasswordResetCodeMail::class, function (PasswordResetCodeMail $mail) {
        app()->setLocale($mail->locale);
        expect($mail->socialProvider)->toBe('Google');
        $mail->assertSeeInHtml('Continuer avec Google', false);
        $mail->assertSeeInHtml($mail->code);

        return true;
    });
});

it("n'envoie rien à un membre d'équipe ni à un compte suspendu, avec la même réponse", function () {
    $owner = prUser();
    prUser(['account_id' => $owner->id, 'role' => 'seller', 'email' => 'membre@example.com']);
    prUser(['email' => 'suspendu@example.com', 'status' => 'suspended']);
    $expected = prForgot($this, 'personne@example.com')->json();

    expect(prForgot($this, 'membre@example.com')->assertOk()->json())->toBe($expected);
    expect(prForgot($this, 'suspendu@example.com')->assertOk()->json())->toBe($expected);
    Mail::assertNothingSent();
});

it("écrit l'e-mail dans la langue du compte, sinon dans celle de la requête", function () {
    $user = prUser();
    DB::table('user_settings')->insert(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'language' => 'en']);

    $this->postJson('/api/password/forgot', ['email' => 'awa@example.com'], ['Accept-Language' => 'fr'])->assertOk();

    Mail::assertSent(PasswordResetCodeMail::class, fn ($mail) => $mail->locale === 'en');
});

it('ne stocke jamais le code en clair', function () {
    prUser();
    prForgot($this);
    $code = prSentCode();

    $row = DB::table('password_reset_codes')->first();
    expect(json_encode($row))->not->toContain($code);
    expect($row->code_hash)->toBe(PasswordResetCode::hashSecret($code));
});

it('compte les mauvais codes puis invalide le code au 6e essai', function () {
    prUser();
    prForgot($this);
    $code = prSentCode();

    foreach ([4, 3, 2, 1, 0] as $remaining) {
        prVerify($this, prWrongCode($code))
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_CODE')
            ->assertJsonPath('remaining', $remaining);
    }

    prVerify($this, $code)->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');
    expect(DB::table('password_reset_codes')->count())->toBe(0);
    prVerify($this, $code)->assertStatus(410);
});

it('refuse un code expiré (plus de 15 minutes)', function () {
    prUser();
    prForgot($this);
    $code = prSentCode();

    $this->travel(PasswordResetController::CODE_TTL_MINUTES + 1)->minutes();

    prVerify($this, $code)->assertStatus(410)->assertJsonPath('code', 'CODE_EXPIRED');
});

it("annule l'ancien code quand un nouveau est envoyé", function () {
    prUser();
    prForgot($this);
    $first = prSentCode();
    prForgot($this);
    $second = prSentCode();
    if ($first === $second) {
        $this->markTestSkipped('Deux tirages identiques (1 chance sur un million).');
    }

    prVerify($this, $first)->assertStatus(422);
    prVerify($this, $second)->assertOk();
    expect(DB::table('password_reset_codes')->count())->toBe(1);
});

it("limite à 3 envois par e-mail et par heure, avec un message générique", function () {
    prUser();
    foreach (range(1, 3) as $i) {
        prForgot($this)->assertOk();
    }

    prForgot($this)
        ->assertStatus(429)
        ->assertJsonPath('code', 'TOO_MANY_REQUESTS')
        ->assertJsonPath('message', 'Trop de demandes. Réessayez dans quelques minutes.');
    // Un e-mail inconnu est limité de la même façon : la limite ne révèle rien.
    foreach (range(1, 3) as $i) {
        prForgot($this, 'personne@example.com')->assertOk();
    }
    prForgot($this, 'personne@example.com')->assertStatus(429);

    Mail::assertSent(PasswordResetCodeMail::class, 3);
});

it('limite aussi le nombre de demandes par adresse IP', function () {
    foreach (range(1, PasswordResetController::MAX_SENDS_PER_IP) as $i) {
        RateLimiter::hit('password-forgot:ip:127.0.0.1', 3600);
    }

    prForgot($this, 'personne@example.com')->assertStatus(429);
});

it('change le mot de passe, ferme les autres sessions, connecte et prévient par e-mail', function () {
    $user = prUser(['email_verified_at' => null]);
    $user->createToken('ancien-appareil');
    DB::table('sessions')->insert(['id' => 'ancienne', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

    prForgot($this);
    $token = prVerify($this, prSentCode())->assertOk()->json('reset_token');

    prReset($this, $token)->assertOk()->assertJsonPath('id', $user->id)->assertJsonPath('is_owner', true);

    $user->refresh();
    expect(Hash::check(PR_NEW_PASSWORD, $user->password))->toBeTrue();
    expect(Hash::check(PR_OLD_PASSWORD, $user->password))->toBeFalse();
    expect($user->email_verified_at)->not->toBeNull();
    expect(DB::table('personal_access_tokens')->count())->toBe(0);
    expect(DB::table('sessions')->where('id', 'ancienne')->exists())->toBeFalse();
    expect(DB::table('password_reset_codes')->count())->toBe(0);
    expect(DB::table('activity_logs')->where('action', 'auth.password_reset')->where('user_id', $user->id)->exists())->toBeTrue();
    $this->assertAuthenticatedAs($user, 'web');
    Mail::assertSent(PasswordChanged::class, function (PasswordChanged $mail) {
        app()->setLocale($mail->locale);
        $mail->assertSeeInText('a été modifié le ' . now()->format('d/m/Y'));

        return $mail->hasTo('awa@example.com');
    });

    // L'ancien mot de passe ne marche plus, le nouveau oui.
    Auth::guard('web')->logout();
    $this->postJson('/api/login', ['email' => 'awa@example.com', 'password' => PR_OLD_PASSWORD])->assertStatus(401);
    $this->postJson('/api/login', ['email' => 'awa@example.com', 'password' => PR_NEW_PASSWORD])->assertOk();
});

it('refuse un jeton de réinitialisation déjà utilisé, faux ou expiré', function () {
    prUser();
    prForgot($this);
    $token = prVerify($this, prSentCode())->json('reset_token');

    prReset($this, 'faux-jeton')->assertStatus(410)->assertJsonPath('code', 'TOKEN_INVALID');
    prReset($this, $token)->assertOk();
    prReset($this, $token, ['password' => 'Autre#Pass2', 'password_confirmation' => 'Autre#Pass2'])
        ->assertStatus(410)
        ->assertJsonPath('code', 'TOKEN_INVALID');

    RateLimiter::clear('password-forgot:email:' . sha1('awa@example.com'));
    prForgot($this);
    $late = prVerify($this, prSentCode())->json('reset_token');
    $this->travel(PasswordResetController::TOKEN_TTL_MINUTES + 1)->minutes();
    prReset($this, $late)->assertStatus(410);
});

it('refuse un code réutilisé après avoir obtenu le jeton', function () {
    prUser();
    prForgot($this);
    $code = prSentCode();

    prVerify($this, $code)->assertOk();
    prVerify($this, $code)->assertStatus(410);
});

it('refuse un mot de passe trop faible ou non confirmé, sans consommer le jeton', function () {
    prUser();
    prForgot($this);
    $token = prVerify($this, prSentCode())->json('reset_token');

    prReset($this, $token, ['password' => 'faible', 'password_confirmation' => 'faible'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');
    prReset($this, $token, ['password_confirmation' => 'Different#9'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');
    prReset($this, $token)->assertOk();
});

it('renvoie les messages en anglais si la requête le demande', function () {
    $this->postJson('/api/password/verify', ['email' => 'personne@example.com', 'code' => '123456'], ['Accept-Language' => 'en'])
        ->assertStatus(410)
        ->assertJsonPath('message', 'This code has expired. Request a new one.');
});
