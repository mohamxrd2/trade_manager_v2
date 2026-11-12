<?php

use App\Mail\PasswordResetCode;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

const NEW_PASSWORD = 'Nouveau#Pass9';

beforeEach(function () {
    Schema::dropIfExists('personal_access_tokens');
    Schema::dropIfExists('users');
    Schema::create('users', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('account_id')->nullable();
        $table->string('role')->nullable();
        $table->string('first_name');
        $table->string('last_name');
        $table->string('username')->unique();
        $table->string('email')->nullable()->unique();
        $table->string('password');
        $table->string('provider')->default('local');
        $table->boolean('must_change_password')->default(false);
        $table->string('status')->default('active');
        $table->timestamp('email_verified_at')->nullable();
        $table->rememberToken();
        $table->timestamps();
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
    Cache::flush();
    Mail::fake();
});

function resetUser(array $attrs = []): string
{
    $id = (string) Str::uuid();
    DB::table('users')->insert(array_merge([
        'id' => $id,
        'first_name' => 'Awa',
        'last_name' => 'Koné',
        'username' => 'awa' . substr($id, 0, 6),
        'email' => 'awa@example.com',
        'password' => Hash::make('Ancien#Pass1'),
        'email_verified_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ], $attrs));

    return $id;
}

function sentCode(): string
{
    $code = null;
    Mail::assertSent(PasswordResetCode::class, function ($mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    return $code;
}

it('envoie un code à un propriétaire et change le mot de passe', function () {
    $id = resetUser();
    User::find($id)->createToken('ancien');

    $this->postJson('/api/password/forgot', ['email' => 'AWA@example.com'])->assertOk();

    $this->postJson('/api/password/reset', [
        'email' => 'awa@example.com',
        'code' => sentCode(),
        'password' => NEW_PASSWORD,
        'password_confirmation' => NEW_PASSWORD,
    ])->assertOk();

    expect(Hash::check(NEW_PASSWORD, User::find($id)->password))->toBeTrue();
    expect(DB::table('personal_access_tokens')->count())->toBe(0);
});

it('répond pareil pour un email inconnu, sans envoyer de mail', function () {
    $this->postJson('/api/password/forgot', ['email' => 'personne@example.com'])
        ->assertOk()
        ->assertJsonPath('success', true);

    Mail::assertNothingSent();
});

it("n'envoie rien à un membre d'équipe", function () {
    $owner = resetUser();
    resetUser(['account_id' => $owner, 'email' => 'membre@example.com', 'role' => 'seller']);

    $this->postJson('/api/password/forgot', ['email' => 'membre@example.com'])->assertOk();

    Mail::assertNothingSent();
});

it('refuse un mauvais code et bloque après 5 essais', function () {
    resetUser();
    $this->postJson('/api/password/forgot', ['email' => 'awa@example.com']);
    $code = sentCode();
    $wrong = $code === '000000' ? '111111' : '000000';
    $body = ['email' => 'awa@example.com', 'password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD];

    foreach (range(1, 5) as $i) {
        $this->postJson('/api/password/reset', $body + ['code' => $wrong])->assertStatus(422);
    }

    $this->postJson('/api/password/reset', $body + ['code' => $code])
        ->assertStatus(429)
        ->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');
});

it('refuse un mot de passe trop faible ou non confirmé', function () {
    resetUser();
    $this->postJson('/api/password/forgot', ['email' => 'awa@example.com']);

    $this->postJson('/api/password/reset', [
        'email' => 'awa@example.com',
        'code' => sentCode(),
        'password' => 'faible',
        'password_confirmation' => 'autre',
    ])->assertStatus(422)->assertJsonValidationErrors('password');
});

it('refuse un code sans demande', function () {
    resetUser();

    $this->postJson('/api/password/reset', [
        'email' => 'awa@example.com',
        'code' => '123456',
        'password' => NEW_PASSWORD,
        'password_confirmation' => NEW_PASSWORD,
    ])->assertStatus(410)->assertJsonPath('code', 'CODE_EXPIRED');
});
