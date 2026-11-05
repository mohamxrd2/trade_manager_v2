<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::dropIfExists('web_vitals');
    Schema::create('web_vitals', function (Blueprint $table) {
        $table->id();
        $table->string('name', 8);
        $table->double('value');
        $table->string('rating', 20)->nullable();
        $table->string('page', 191);
        $table->string('device', 10)->nullable();
        $table->timestamp('created_at')->useCurrent();
    });
});

function sendVital(array $body)
{
    // Comme navigator.sendBeacon : JSON brut en text/plain, sans jeton CSRF.
    return test()->call('POST', '/api/web-vitals', [], [], [], [
        'CONTENT_TYPE' => 'text/plain;charset=UTF-8',
        'HTTP_ORIGIN' => config('app.frontend_url', 'http://localhost:3000'),
    ], json_encode($body));
}

it('enregistre une mesure et regroupe les identifiants de page', function () {
    sendVital(['name' => 'LCP', 'value' => 1834.5, 'rating' => 'good', 'page' => '/invoices/0199a2b3-1c2d-7e8f-9a0b-1c2d3e4f5a6b', 'device' => 'mobile'])
        ->assertNoContent();

    $row = DB::table('web_vitals')->first();
    expect($row->name)->toBe('LCP')
        ->and((float) $row->value)->toBe(1834.5)
        ->and($row->page)->toBe('/invoices/:id')
        ->and($row->device)->toBe('mobile');
});

it('refuse une métrique inconnue ou un corps invalide', function () {
    sendVital(['name' => 'FOO', 'value' => 1, 'page' => '/'])->assertStatus(422);
    test()->call('POST', '/api/web-vitals', [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'pas du json')->assertStatus(422);

    expect(DB::table('web_vitals')->count())->toBe(0);
});

it('affiche le p75 par page et par métrique', function () {
    foreach ([1000, 2000, 3000, 4000] as $value) {
        DB::table('web_vitals')->insert(['name' => 'LCP', 'value' => $value, 'page' => '/dashboard', 'created_at' => now()]);
    }

    test()->artisan('web-vitals:report')
        ->expectsTable(['Page', 'Métrique', 'p75', 'Mesures', 'Verdict'], [['/dashboard', 'LCP', '3000 ms', 4, 'à améliorer']])
        ->assertSuccessful();
});
