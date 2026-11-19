<?php

use App\Models\Article;
use App\Models\MemberPermission;
use App\Models\User;
use App\Models\Variation;
use App\Support\Barcode;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
| Codes-barres — schéma minimal SANS la colonne barcode, puis la VRAIE
| migration (up) par-dessus : compatible SQLite ET PostgreSQL
| (phpunit.pgsql.xml, base waelly_test).
*/

function createBarcodeTestSchema(): void
{
    foreach (['transactions', 'member_permissions', 'variations', 'articles', 'user_settings', 'users'] as $table) {
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
        $table->string('password');
        $table->decimal('company_share', 5, 2)->default(100.00);
        $table->string('profile_image')->nullable();
        $table->string('provider')->default('local');
        $table->boolean('must_change_password')->default(false);
        $table->string('status')->default('active');
        $table->timestamp('last_login_at')->nullable();
        $table->rememberToken();
        $table->timestamps();
    });
    Schema::create('user_settings', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id')->unique();
        $table->string('language')->default('fr');
        $table->integer('low_stock_threshold')->default(80);
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
        $table->uuid('image_media_id')->nullable();
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
        $table->uuid('image_media_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('transactions', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->uuid('article_id')->nullable();
        $table->uuid('variation_id')->nullable();
        $table->uuid('variable_id')->nullable();
        $table->string('type');
        $table->integer('quantity')->nullable();
        $table->decimal('amount', 15, 2)->default(0);
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('member_permissions', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->string('permission');
        $table->boolean('granted');
        $table->timestamps();
    });

    barcodeMigration()->up();
}

function barcodeMigration(): object
{
    return require database_path('migrations/2026_11_19_090000_add_barcode_to_articles_and_variations.php');
}

beforeEach(function () {
    createBarcodeTestSchema();
    $this->withHeader('Accept-Language', 'fr');
});

afterEach(function () {
    Schema::dropIfExists('member_permissions');
});

function barcodeOwner(): User
{
    return User::create([
        'first_name' => 'Awa',
        'last_name' => 'Diallo',
        'username' => 'owner_' . Str::random(8),
        'email' => 'owner_' . Str::random(8) . '@example.test',
        'password' => Hash::make('Password123!'),
    ]);
}

function barcodeMember(User $owner, string $role, array $permissions = []): User
{
    $member = User::create([
        'account_id' => $owner->id,
        'first_name' => 'Koffi',
        'last_name' => ucfirst($role),
        'username' => 'member_' . Str::random(8),
        'password' => Hash::make('123456'),
        'role' => $role,
        'company_share' => 0,
    ]);
    foreach ($permissions as $permission => $granted) {
        MemberPermission::create(['user_id' => $member->id, 'permission' => $permission, 'granted' => $granted]);
    }

    return $member;
}

function barcodeSimple(User $owner, string $name = 'Savon', ?string $barcode = null): Article
{
    return Article::create(['user_id' => $owner->id, 'name' => $name, 'type' => 'simple', 'sale_price' => 500, 'quantity' => 10, 'barcode' => $barcode]);
}

/** @return array{0: Article, 1: Variation, 2: Variation} */
function barcodeVariable(User $owner, string $name = 'Pagne'): array
{
    $article = Article::create(['user_id' => $owner->id, 'name' => $name, 'type' => 'variable', 'sale_price' => 0, 'quantity' => 0]);
    $red = Variation::create(['article_id' => $article->id, 'name' => 'Rouge', 'quantity' => 4, 'price' => 3000]);
    $blue = Variation::create(['article_id' => $article->id, 'name' => 'Bleu', 'quantity' => 2, 'price' => 3500]);

    return [$article, $red, $blue];
}

/*
| Formats et clés de contrôle
*/

it('reconnaît EAN-13, EAN-8, UPC-A, UPC-E et Code 128', function (string $code, string $format) {
    expect(Barcode::validate($code))->toBe(['format' => $format, 'error' => null]);
})->with([
    'EAN-13 (Nutella)' => ['3017620422003', Barcode::EAN13],
    'EAN-13 interne' => ['2000000000015', Barcode::EAN13],
    'EAN-8' => ['96385074', Barcode::EAN8],
    'UPC-A' => ['036000291452', Barcode::UPCA],
    'UPC-E' => ['04252614', Barcode::UPCE],
    'Code 128' => ['SAV-01/B.2', Barcode::CODE128],
    'Code 128, 10 chiffres' => ['1234567890', Barcode::CODE128],
]);

it('refuse une clé de contrôle fausse et un format inconnu', function (string $code, string $error) {
    expect(Barcode::validate($code)['error'])->toBe($error);
})->with([
    'EAN-13 clé fausse' => ['3017620422004', Barcode::ERROR_CHECK_DIGIT],
    'EAN-8 clé fausse' => ['96385075', Barcode::ERROR_CHECK_DIGIT],
    'UPC-A clé fausse' => ['036000291453', Barcode::ERROR_CHECK_DIGIT],
    'trop court' => ['AB1', Barcode::ERROR_FORMAT],
    'trop long' => [str_repeat('A', 33), Barcode::ERROR_FORMAT],
    'caractère interdit' => ['SAV_01', Barcode::ERROR_FORMAT],
    'accent' => ['ÉTÉ-2026', Barcode::ERROR_FORMAT],
]);

it('nettoie les espaces avant de valider', function () {
    expect(Barcode::normalize("  3 017620 422003\t"))->toBe('3017620422003');
});

it('calcule la clé EAN-13', function () {
    expect(Barcode::ean13('301762042200'))->toBe('3017620422003')
        ->and(Barcode::ean13('200000000001'))->toBe('2000000000015');
});

/*
| Enregistrement (création / modification)
*/

it("enregistre le code d'un article simple, nettoyé, et le renvoie dans l'API", function () {
    $owner = barcodeOwner();

    $this->actingAs($owner)->postJson('/api/articles', [
        'name' => 'Nutella', 'type' => 'simple', 'sale_price' => 2500, 'quantity' => 6, 'barcode' => ' 3017620 422003 ',
    ])->assertCreated()->assertJsonPath('data.barcode', '3017620422003');

    $this->actingAs($owner)->getJson('/api/articles')->assertJsonPath('data.0.barcode', '3017620422003');
});

it('enregistre un code par variation à la création et à la modification', function () {
    $owner = barcodeOwner();

    $response = $this->actingAs($owner)->postJson('/api/articles', [
        'name' => 'Pagne', 'type' => 'variable',
        'variations' => [
            ['name' => 'Rouge', 'quantity' => 2, 'price' => 3000, 'barcode' => '96385074'],
            ['name' => 'Bleu', 'quantity' => 2, 'price' => 3000],
        ],
    ])->assertCreated();
    $blue = collect($response->json('data.variations'))->firstWhere('name', 'Bleu');
    expect(collect($response->json('data.variations'))->firstWhere('name', 'Rouge')['barcode'])->toBe('96385074');

    $this->actingAs($owner)->putJson("/api/variations/{$blue['id']}", ['name' => 'Bleu', 'quantity' => 2, 'price' => 3000, 'barcode' => 'PAGNE-BLEU'])
        ->assertOk()->assertJsonPath('data.barcode', 'PAGNE-BLEU');
});

it('refuse un code sur un produit à variantes (il va sur chaque variante)', function () {
    $owner = barcodeOwner();
    [$article] = barcodeVariable($owner);

    $this->actingAs($owner)->putJson("/api/articles/{$article->id}", ['name' => 'Pagne', 'barcode' => '3017620422003'])
        ->assertStatus(422)->assertJsonPath('code', 'barcode_variable_article');
});

it('refuse une clé fausse avec un message clair, en français et en anglais', function () {
    $owner = barcodeOwner();
    $payload = ['name' => 'Nutella', 'type' => 'simple', 'sale_price' => 2500, 'quantity' => 6, 'barcode' => '3017620422004'];

    $this->actingAs($owner)->postJson('/api/articles', $payload)
        ->assertStatus(422)
        ->assertJsonPath('code', 'barcode_check_digit')
        ->assertJsonPath('errors.barcode.0', 'Ce code-barre est invalide : le dernier chiffre (clé de contrôle) ne correspond pas. Vérifiez la saisie.');

    $this->actingAs($owner)->withHeader('Accept-Language', 'en')->postJson('/api/articles', $payload)
        ->assertStatus(422)
        ->assertJsonPath('errors.barcode.0', 'This barcode is invalid: the last digit (check digit) does not match. Check what you typed.');
    expect(Article::count())->toBe(0);
});

it('refuse un code déjà utilisé dans le compte, en nommant le produit (article ou variante)', function () {
    $owner = barcodeOwner();
    barcodeSimple($owner, 'Savon', '3017620422003');
    [, $red] = barcodeVariable($owner);
    $red->update(['barcode' => 'PAGNE-R']);

    $this->actingAs($owner)->postJson('/api/articles', ['name' => 'Autre', 'type' => 'simple', 'sale_price' => 1, 'quantity' => 1, 'barcode' => '3017620422003'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'barcode_taken')
        ->assertJsonPath('details.product', 'Savon')
        ->assertJsonPath('errors.barcode.0', 'Ce code-barre est déjà utilisé par « Savon ».');

    $other = barcodeSimple($owner, 'Lait');
    $this->actingAs($owner)->withHeader('Accept-Language', 'en')
        ->putJson("/api/articles/{$other->id}", ['name' => 'Lait', 'sale_price' => 500, 'quantity' => 10, 'barcode' => 'PAGNE-R'])
        ->assertStatus(422)
        ->assertJsonPath('errors.barcode.0', 'This barcode is already used by “Pagne — Rouge”.');
    expect($other->fresh()->barcode)->toBeNull();
});

it('garde son propre code en réenregistrant, et retire le code quand on envoie vide', function () {
    $owner = barcodeOwner();
    $article = barcodeSimple($owner, 'Savon', '3017620422003');

    $this->actingAs($owner)->putJson("/api/articles/{$article->id}", ['name' => 'Savon', 'sale_price' => 500, 'quantity' => 10, 'barcode' => '3017620422003'])->assertOk();
    $this->actingAs($owner)->putJson("/api/articles/{$article->id}", ['name' => 'Savon', 'sale_price' => 500, 'quantity' => 10])->assertOk();
    expect($article->fresh()->barcode)->toBe('3017620422003');

    $this->actingAs($owner)->putJson("/api/articles/{$article->id}", ['name' => 'Savon', 'sale_price' => 500, 'quantity' => 10, 'barcode' => ''])->assertOk();
    expect($article->fresh()->barcode)->toBeNull();
});

it('refuse deux variations avec le même code dans le même envoi', function () {
    $owner = barcodeOwner();

    $this->actingAs($owner)->postJson('/api/articles', [
        'name' => 'Pagne', 'type' => 'variable',
        'variations' => [
            ['name' => 'Rouge', 'quantity' => 2, 'price' => 3000, 'barcode' => 'PAGNE-1'],
            ['name' => 'Bleu', 'quantity' => 2, 'price' => 3000, 'barcode' => 'PAGNE-1'],
        ],
    ])->assertStatus(422)->assertJsonPath('code', 'barcode_duplicate')->assertJsonPath('errors', ['variations.1.barcode' => ['Le code-barre PAGNE-1 est saisi deux fois.']]);
    expect(Article::count())->toBe(0);
});

it('autorise le même code dans deux comptes différents', function () {
    $shopA = barcodeOwner();
    $shopB = barcodeOwner();
    barcodeSimple($shopA, 'Nutella', '3017620422003');

    $this->actingAs($shopB)->postJson('/api/articles', ['name' => 'Nutella', 'type' => 'simple', 'sale_price' => 2600, 'quantity' => 3, 'barcode' => '3017620422003'])
        ->assertCreated();
});

it("l'index unique partiel empêche deux articles du compte d'avoir le même code, pas deux articles sans code", function () {
    $owner = barcodeOwner();
    barcodeSimple($owner, 'A');
    barcodeSimple($owner, 'B');
    barcodeSimple($owner, 'C', 'CODE-1');

    expect(fn () => barcodeSimple($owner, 'D', 'CODE-1'))->toThrow(QueryException::class);
});

it('la migration se défait proprement (down) puis se rejoue', function () {
    $migration = barcodeMigration();
    $migration->down();
    expect(Schema::hasColumn('articles', 'barcode'))->toBeFalse()
        ->and(Schema::hasColumn('variations', 'barcode'))->toBeFalse();

    $migration->up();
    expect(Schema::hasColumn('articles', 'barcode'))->toBeTrue()
        ->and(Schema::hasColumn('variations', 'barcode'))->toBeTrue();
});

/*
| Génération
*/

it('génère un EAN-13 interne (préfixe 2, clé correcte), puis le suivant', function () {
    $owner = barcodeOwner();
    $first = barcodeSimple($owner, 'Savon');
    $second = barcodeSimple($owner, 'Lait');

    $code = $this->actingAs($owner)->postJson("/api/articles/{$first->id}/barcode")->assertCreated()->json('data.barcode');
    expect($code)->toBe('2000000000015')
        ->and(Barcode::validate($code)['format'])->toBe(Barcode::EAN13)
        ->and($first->fresh()->barcode)->toBe($code);

    $next = $this->actingAs($owner)->postJson("/api/articles/{$second->id}/barcode")->json('data.barcode');
    expect($next)->toBe('2000000000022');
});

it('ne remplace jamais un code existant', function () {
    $owner = barcodeOwner();
    $article = barcodeSimple($owner, 'Nutella', '3017620422003');

    $this->actingAs($owner)->postJson("/api/articles/{$article->id}/barcode")
        ->assertStatus(409)->assertJsonPath('code', 'barcode_already_set');
    expect($article->fresh()->barcode)->toBe('3017620422003');
});

it('saute un code interne déjà saisi à la main (collision) et suit la suite du compte', function () {
    $owner = barcodeOwner();
    barcodeSimple($owner, 'Balance', '2000000000053'); // numéro 5, saisi à la main
    $article = barcodeSimple($owner, 'Savon');

    // La suite reprend après le plus grand code interne du compte.
    expect($this->actingAs($owner)->postJson("/api/articles/{$article->id}/barcode")->json('data.barcode'))->toBe('2000000000060');

    // Un autre compte a sa propre suite.
    $other = barcodeOwner();
    $otherArticle = barcodeSimple($other, 'Savon');
    expect($this->actingAs($other)->postJson("/api/articles/{$otherArticle->id}/barcode")->json('data.barcode'))->toBe('2000000000015');
});

it('génère le code d’une variation, et refuse un article à variantes', function () {
    $owner = barcodeOwner();
    [$article, $red] = barcodeVariable($owner);

    $this->actingAs($owner)->postJson("/api/variations/{$red->id}/barcode")->assertCreated()->assertJsonPath('data.barcode', '2000000000015');
    $this->actingAs($owner)->postJson("/api/articles/{$article->id}/barcode")->assertStatus(422)->assertJsonPath('code', 'barcode_variable_article');
});

it('génère en lot tous les codes manquants du compte, sans toucher aux existants ni aux autres comptes', function () {
    $owner = barcodeOwner();
    $nutella = barcodeSimple($owner, 'Nutella', '3017620422003');
    $soap = barcodeSimple($owner, 'Savon');
    [$pagne, $red, $blue] = barcodeVariable($owner);
    $blue->delete(); // archivée : ignorée
    $other = barcodeOwner();
    $foreign = barcodeSimple($other, 'Étranger');

    $this->actingAs($owner)->postJson('/api/barcodes/generate-missing', ['dry_run' => true])
        ->assertOk()->assertJsonPath('data.count', 2);
    expect($soap->fresh()->barcode)->toBeNull();

    $this->actingAs($owner)->postJson('/api/barcodes/generate-missing')
        ->assertOk()->assertJsonPath('data.count', 2)->assertJsonPath('message', '2 codes-barres créés.');

    $codes = [$soap->fresh()->barcode, $red->fresh()->barcode];
    expect($codes)->each->toMatch('/^2\d{12}$/')
        ->and(array_unique($codes))->toHaveCount(2)
        ->and($nutella->fresh()->barcode)->toBe('3017620422003')
        ->and($pagne->fresh()->barcode)->toBeNull()
        ->and(Variation::withTrashed()->find($blue->id)->barcode)->toBeNull()
        ->and($foreign->fresh()->barcode)->toBeNull();

    $this->actingAs($owner)->postJson('/api/barcodes/generate-missing')->assertJsonPath('data.count', 0);
});

it('génère en lot seulement pour les ids choisis (article à variantes = toutes ses variantes)', function () {
    $owner = barcodeOwner();
    $soap = barcodeSimple($owner, 'Savon');
    $milk = barcodeSimple($owner, 'Lait');
    [$pagne, $red, $blue] = barcodeVariable($owner);
    $other = barcodeOwner();
    $foreign = barcodeSimple($other, 'Étranger');

    $this->actingAs($owner)->postJson('/api/barcodes/generate-missing', ['article_ids' => [$pagne->id, $milk->id, $foreign->id]])
        ->assertOk()->assertJsonPath('data.count', 3);

    expect($soap->fresh()->barcode)->toBeNull()
        ->and($milk->fresh()->barcode)->not->toBeNull()
        ->and($red->fresh()->barcode)->not->toBeNull()
        ->and($blue->fresh()->barcode)->not->toBeNull()
        ->and($foreign->fresh()->barcode)->toBeNull();
});

/*
| Recherche par code
*/

it('trouve un article simple par son code, avec prix, stock et image', function () {
    $owner = barcodeOwner();
    $article = barcodeSimple($owner, 'Nutella', '3017620422003');
    $article->update(['image' => 'https://media.example/x.webp']);

    $this->actingAs($owner)->getJson('/api/barcodes/3017620422003')
        ->assertOk()
        ->assertJsonPath('data.article.id', $article->id)
        ->assertJsonPath('data.article.sale_price', '500.00')
        ->assertJsonPath('data.article.remaining_quantity', 10)
        ->assertJsonPath('data.article.image', 'https://media.example/x.webp')
        ->assertJsonPath('data.variation', null)
        ->assertJsonMissingPath('data.article.user');
});

it('trouve la variante par son code (avec son article)', function () {
    $owner = barcodeOwner();
    [$article, $red] = barcodeVariable($owner);
    $red->update(['barcode' => 'PAGNE-R/01']);

    $this->actingAs($owner)->getJson('/api/barcodes/' . rawurlencode('PAGNE-R/01'))
        ->assertOk()
        ->assertJsonPath('data.article.id', $article->id)
        ->assertJsonPath('data.variation.id', $red->id)
        ->assertJsonPath('data.variation.price', '3000.00')
        ->assertJsonPath('data.variation.remaining_quantity', 4);
});

it('trouve un UPC-A lu avec 12 ou 13 chiffres, et refuse le doublon sous l’autre écriture', function () {
    $owner = barcodeOwner();
    $article = barcodeSimple($owner, 'Coca', '036000291452');

    $this->actingAs($owner)->getJson('/api/barcodes/0036000291452')->assertOk()->assertJsonPath('data.article.id', $article->id);
    $this->actingAs($owner)->postJson('/api/articles', ['name' => 'Autre', 'type' => 'simple', 'sale_price' => 1, 'quantity' => 1, 'barcode' => '0036000291452'])
        ->assertStatus(422)->assertJsonPath('code', 'barcode_taken');
});

it('renvoie un 404 propre pour un code inconnu, une variante archivée ou un code d’un autre compte', function () {
    $owner = barcodeOwner();
    [, $red] = barcodeVariable($owner);
    $red->update(['barcode' => 'PAGNE-R']);
    $red->delete();
    $other = barcodeOwner();
    barcodeSimple($other, 'Nutella', '3017620422003');

    foreach (['0000000000000', 'PAGNE-R', '3017620422003'] as $code) {
        $this->actingAs($owner)->getJson("/api/barcodes/{$code}")
            ->assertNotFound()
            ->assertJsonPath('code', 'barcode_not_found')
            ->assertJsonPath('message', 'Aucun produit ne porte ce code-barre.');
    }
});

/*
| Droits
*/

it('un caissier peut chercher un code, mais ni le modifier ni en générer', function () {
    $owner = barcodeOwner();
    $article = barcodeSimple($owner, 'Nutella', '3017620422003');
    $other = barcodeSimple($owner, 'Lait');
    $cashier = barcodeMember($owner, 'cashier');

    $this->actingAs($cashier)->getJson('/api/barcodes/3017620422003')->assertOk()->assertJsonPath('data.article.id', $article->id);
    $this->actingAs($cashier)->postJson("/api/articles/{$other->id}/barcode")->assertForbidden();
    $this->actingAs($cashier)->postJson('/api/barcodes/generate-missing')->assertForbidden();
    $this->actingAs($cashier)->putJson("/api/articles/{$other->id}", ['name' => 'Lait', 'sale_price' => 500, 'quantity' => 10, 'barcode' => 'LAIT-01'])->assertForbidden();
    expect($other->fresh()->barcode)->toBeNull();
});

it('un gestionnaire de stock peut chercher et générer ; un comptable ne peut rien', function () {
    $owner = barcodeOwner();
    $article = barcodeSimple($owner, 'Savon');
    $stock = barcodeMember($owner, 'stock_manager');
    $accountant = barcodeMember($owner, 'accountant');

    $this->actingAs($stock)->postJson("/api/articles/{$article->id}/barcode")->assertCreated();
    $this->actingAs($stock)->getJson('/api/barcodes/' . $article->fresh()->barcode)->assertOk();

    $this->actingAs($accountant)->getJson('/api/barcodes/' . $article->fresh()->barcode)->assertForbidden();
});

it('un caissier à qui on a retiré la vente et le stock ne peut plus chercher', function () {
    $owner = barcodeOwner();
    barcodeSimple($owner, 'Nutella', '3017620422003');
    $cashier = barcodeMember($owner, 'cashier', ['sales.create' => false, 'stock.view' => false]);

    $this->actingAs($cashier)->getJson('/api/barcodes/3017620422003')->assertForbidden();
});

it("un membre travaille sur les codes de SON compte : il ne génère pas pour l'article d'un autre compte", function () {
    $owner = barcodeOwner();
    $member = barcodeMember($owner, 'manager');
    $other = barcodeOwner();
    $foreign = barcodeSimple($other, 'Étranger');
    [, $foreignVariation] = barcodeVariable($other);

    $this->actingAs($member)->postJson("/api/articles/{$foreign->id}/barcode")->assertNotFound();
    $this->actingAs($member)->postJson("/api/variations/{$foreignVariation->id}/barcode")->assertNotFound();
    expect($foreign->fresh()->barcode)->toBeNull();

    // Code saisi par le membre : rangé dans le compte du propriétaire.
    $this->actingAs($member)->postJson('/api/articles', ['name' => 'Savon', 'type' => 'simple', 'sale_price' => 500, 'quantity' => 1, 'barcode' => 'SAVON-01'])->assertCreated();
    $this->actingAs($owner)->getJson('/api/barcodes/SAVON-01')->assertOk();
});

/*
| Codes mal formés ou inconnus : jamais de 500
*/

it('« 005264 » (6 chiffres, Code 128) inconnu → 404 « introuvable », pas 500', function () {
    $owner = barcodeOwner();

    $this->actingAs($owner)->getJson('/api/barcodes/005264')
        ->assertNotFound()
        ->assertJsonPath('code', 'barcode_not_found')
        ->assertJsonPath('barcode', '005264');
});

it('un code avec des zéros au début est trouvé, zéros conservés partout', function () {
    $owner = barcodeOwner();

    $created = $this->actingAs($owner)->postJson('/api/articles', ['name' => 'Bonbon', 'type' => 'simple', 'sale_price' => 25, 'quantity' => 100, 'barcode' => '005264'])
        ->assertCreated()->assertJsonPath('data.barcode', '005264');

    $this->actingAs($owner)->getJson('/api/barcodes/005264')
        ->assertOk()
        ->assertJsonPath('barcode', null)
        ->assertJsonPath('data.barcode', '005264')
        ->assertJsonPath('data.article.id', $created->json('data.id'))
        ->assertJsonPath('data.article.barcode', '005264');
    expect(Article::first()->barcode)->toBe('005264');
});

it('un code avec des caractères interdits ou une clé fausse → 422 avec un message clair', function () {
    $owner = barcodeOwner();

    $this->actingAs($owner)->getJson('/api/barcodes/' . rawurlencode('SAV_01#'))
        ->assertStatus(422)
        ->assertJsonPath('code', 'barcode_format')
        ->assertJsonPath('message', 'Code-barre non reconnu. Utilisez un EAN-13, EAN-8, UPC-A, ou un code de 4 à 32 caractères (lettres, chiffres, - . /).');
    $this->actingAs($owner)->getJson('/api/barcodes/3017620422004')
        ->assertStatus(422)->assertJsonPath('code', 'barcode_check_digit');
});

it('génération en lot : article simple, article à variantes, article qui a déjà un code', function () {
    $owner = barcodeOwner();
    $simple = barcodeSimple($owner, 'Savon');
    [$pagne, $red, $blue] = barcodeVariable($owner);
    $existing = barcodeSimple($owner, 'Nutella', '3017620422003');

    $this->actingAs($owner)->postJson('/api/barcodes/generate-missing', ['article_ids' => [$simple->id, $pagne->id, $existing->id]])
        ->assertOk()
        ->assertJsonPath('data.count', 3)
        ->assertJsonPath('data.failed_count', 0)
        ->assertJsonPath('message', '3 codes-barres créés.');

    expect([$simple->fresh()->barcode, $red->fresh()->barcode, $blue->fresh()->barcode])->each->toMatch('/^2\d{12}$/')
        ->and($existing->fresh()->barcode)->toBe('3017620422003')
        ->and($pagne->fresh()->barcode)->toBeNull();
});

it('génération en lot : un produit en échec n’empêche pas les autres, et l’échec est expliqué', function () {
    $owner = barcodeOwner();
    $simple = barcodeSimple($owner, 'Savon');
    [, $red, $blue] = barcodeVariable($owner);
    $blue->update(['name' => 'Bleu-panne']);
    Variation::saving(function (Variation $variation) {
        if ($variation->name === 'Bleu-panne' && $variation->isDirty('barcode')) {
            throw new RuntimeException('panne simulée');
        }
    });

    $response = $this->actingAs($owner)->postJson('/api/barcodes/generate-missing')
        ->assertOk()
        ->assertJsonPath('data.count', 2)
        ->assertJsonPath('data.failed_count', 1)
        ->assertJsonPath('data.failed.0.product', 'Pagne — Bleu-panne')
        ->assertJsonPath('data.failed.0.reason', "Erreur inattendue lors de l'enregistrement du code.");
    expect($response->json('message'))->toBe("2 codes-barres créés. 1 produit n'a pas pu recevoir de code.")
        ->and($simple->fresh()->barcode)->not->toBeNull()
        ->and($red->fresh()->barcode)->not->toBeNull()
        ->and($blue->fresh()->barcode)->toBeNull();
});
