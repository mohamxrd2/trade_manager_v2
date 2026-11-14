<?php

use App\Models\Article;
use App\Models\Collaborator;
use App\Models\Company;
use App\Models\Media;
use App\Models\MemberPermission;
use App\Models\User;
use App\Models\Variation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
| Images (MediaService / POST /api/media) — schéma minimal, compatible
| SQLite ET Postgres (DB_CONNECTION=pgsql DB_DATABASE=waelly_test).
*/

function createMediaTestSchema(): void
{
    foreach (['media', 'transactions', 'member_permissions', 'collaborators', 'variations', 'articles', 'user_settings', 'companies', 'users'] as $table) {
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
        $table->uuid('avatar_media_id')->nullable();
        $table->string('provider')->default('local');
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
        $table->string('code')->nullable();
        $table->string('email')->nullable();
        $table->string('logo')->nullable();
        $table->uuid('logo_media_id')->nullable();
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
    Schema::create('collaborators', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->uuid('user_id_member')->nullable();
        $table->string('name');
        $table->string('phone', 20);
        $table->decimal('part', 5, 2)->default(0);
        $table->decimal('wallet', 15, 2)->default(0);
        $table->string('image')->nullable();
        $table->uuid('image_media_id')->nullable();
        $table->timestamps();
    });
    // Totaux renvoyés avec l'article créé.
    Schema::create('transactions', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->uuid('article_id')->nullable();
        $table->uuid('variation_id')->nullable();
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
    // Même définition que la migration (sans les colonnes ajoutées aux autres tables, déjà là).
    Schema::create('media', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('account_id');
        $table->string('owner_type', 32)->nullable();
        $table->uuid('owner_id')->nullable();
        $table->string('kind', 16);
        $table->string('disk', 32)->default('media');
        $table->string('path', 255);
        $table->string('url', 1024);
        $table->unsignedInteger('width');
        $table->unsignedInteger('height');
        $table->unsignedInteger('size_bytes');
        $table->string('mime', 32);
        $table->uuid('created_by_user_id')->nullable();
        $table->timestamps();
    });
}

beforeEach(function () {
    createMediaTestSchema();
    Storage::fake('media');
    $this->withHeader('Accept-Language', 'fr');
});

// Les autres fichiers de test recréent leur propre schéma sans ces tables :
// laissées là, elles feraient passer le code des images et des droits des
// membres sur leurs données (PostgreSQL, base partagée entre les fichiers).
afterEach(function () {
    foreach (['media', 'member_permissions', 'collaborators'] as $table) {
        Schema::dropIfExists($table);
    }
});

function mediaOwner(string $tag = 'A'): User
{
    $owner = User::create([
        'first_name' => 'Awa',
        'last_name' => $tag,
        'username' => 'owner_' . Str::random(8),
        'email' => 'owner_' . Str::random(8) . '@example.test',
        'password' => Hash::make('Password123!'),
    ]);
    Company::create(['user_id' => $owner->id, 'name' => 'Boutique ' . $tag]);

    return $owner;
}

function mediaMember(User $owner, string $role, array $permissions = []): User
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

function mediaArticle(User $owner): Article
{
    return Article::create(['user_id' => $owner->id, 'name' => 'Pagne', 'type' => 'simple', 'sale_price' => 5000, 'quantity' => 3]);
}

/** Vraie image JPEG (pixels aléatoires : se compresse mal, comme une photo). */
function mediaJpeg(int $width = 3000, int $height = 2000, string $name = 'IMG_0042.jpg'): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    for ($i = 0; $i < 400; $i++) {
        imagefilledrectangle($image, random_int(0, $width), random_int(0, $height), random_int(0, $width), random_int(0, $height), random_int(0, 0xFFFFFF));
    }
    $path = tempnam(sys_get_temp_dir(), 'media') . '.jpg';
    imagejpeg($image, $path, 92);

    return new UploadedFile($path, $name, 'image/jpeg', null, true);
}

function mediaUpload($test, User $actor, UploadedFile $file, string $kind, ?string $ownerId = null)
{
    return $test->actingAs($actor)->post('/api/media', array_filter([
        'file' => $file,
        'kind' => $kind,
        'owner_id' => $ownerId,
    ]), ['Accept' => 'application/json']);
}

it('convertit une photo en WebP, limite ses dimensions et la range sous {compte}/{sorte}/{uuid}.webp', function () {
    $owner = mediaOwner();
    $article = mediaArticle($owner);

    $response = mediaUpload($this, $owner, mediaJpeg(3000, 2000), 'product', $article->id)->assertCreated();

    $media = Media::firstOrFail();
    expect($media->mime)->toBe('image/webp')
        ->and($media->width)->toBe(1200)
        ->and($media->height)->toBe(800)
        ->and($media->path)->toMatch('#^' . $owner->id . '/product/[0-9a-f-]{36}\.webp$#')
        ->and($media->path)->not->toContain('IMG_0042')
        ->and($media->owner_type)->toBe('article')
        ->and($media->owner_id)->toBe($article->id);

    $stored = Storage::disk('media')->get($media->path);
    expect(substr($stored, 0, 4))->toBe('RIFF')->and(substr($stored, 8, 4))->toBe('WEBP');
    expect($media->size_bytes)->toBe(strlen($stored));

    $article->refresh();
    expect($article->image_media_id)->toBe($media->id)->and($article->image)->toBe($media->url);
    $response->assertJsonPath('data.id', $media->id)->assertJsonMissingPath('data.path');
});

it('limite les logos et les photos de profil à 512 px', function () {
    $owner = mediaOwner();

    mediaUpload($this, $owner, mediaJpeg(2000, 1000), 'logo')->assertCreated();
    mediaUpload($this, $owner, mediaJpeg(800, 1600), 'avatar')->assertCreated();

    $logo = Media::where('kind', 'logo')->firstOrFail();
    $avatar = Media::where('kind', 'avatar')->firstOrFail();
    expect([$logo->width, $logo->height])->toBe([512, 256]);
    expect([$avatar->width, $avatar->height])->toBe([256, 512]);
    expect(Company::first()->logo_url)->toBe($logo->url);
    expect($owner->fresh()->profile_image)->toBe($avatar->url);
});

it("refuse un fichier qui n'est pas une image, même renommé en .jpg", function () {
    $owner = mediaOwner();
    $fake = UploadedFile::fake()->createWithContent('photo.jpg', "<?php echo 'pas une image'; ?>");

    mediaUpload($this, $owner, $fake, 'avatar')
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');

    expect(Media::count())->toBe(0);
    expect(Storage::disk('media')->allFiles())->toBe([]);
});

it('refuse un SVG', function () {
    $owner = mediaOwner();
    $svg = UploadedFile::fake()->createWithContent('logo.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

    mediaUpload($this, $owner, $svg, 'logo')->assertStatus(422);
    expect(Media::count())->toBe(0);
});

it('refuse un fichier de plus de 5 Mo', function () {
    $owner = mediaOwner();
    $big = UploadedFile::fake()->create('grosse.jpg', 5121, 'image/jpeg');

    mediaUpload($this, $owner, $big, 'avatar')
        ->assertStatus(422)
        ->assertJsonValidationErrors('file')
        ->assertJsonPath('errors.file.0', "L'image ne doit pas dépasser 5 Mo.");
});

it('refuse un fichier écarté par PHP (upload_max_filesize) avec le même message et les en-têtes CORS', function () {
    $owner = mediaOwner();
    config(['cors.allowed_origins' => ['https://waelly.com']]);
    // Fichier de 6 à 8 Mo : Nginx le laisse passer, PHP le rejette (UPLOAD_ERR_INI_SIZE).
    $rejected = new UploadedFile(mediaJpeg()->getPathname(), 'grosse.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE, true);

    $this->actingAs($owner)
        ->post('/api/media', ['file' => $rejected, 'kind' => 'avatar'], ['Accept' => 'application/json', 'Origin' => 'https://waelly.com'])
        ->assertStatus(422)
        ->assertJsonPath('errors.file.0', "L'image ne doit pas dépasser 5 Mo.")
        ->assertHeader('Access-Control-Allow-Origin', 'https://waelly.com');

    expect(Media::count())->toBe(0);
});

it("refuse la photo d'un article à un caissier sans stock.edit, sans rien enregistrer", function () {
    $owner = mediaOwner();
    $article = mediaArticle($owner);
    $cashier = mediaMember($owner, 'cashier', ['stock.edit' => false]);

    mediaUpload($this, $cashier, mediaJpeg(400, 400), 'product', $article->id)->assertForbidden();
    mediaUpload($this, $cashier, mediaJpeg(400, 400), 'product')->assertForbidden();

    expect(Media::count())->toBe(0)->and(Storage::disk('media')->allFiles())->toBe([]);
    expect($article->fresh()->image_media_id)->toBeNull();
});

it('laisse un membre avec stock.edit changer la photo, et chacun sa propre photo de profil', function () {
    $owner = mediaOwner();
    $article = mediaArticle($owner);
    $manager = mediaMember($owner, 'stock_manager', ['stock.edit' => true]);
    $cashier = mediaMember($owner, 'cashier', ['stock.edit' => false]);

    mediaUpload($this, $manager, mediaJpeg(400, 400), 'product', $article->id)->assertCreated();
    mediaUpload($this, $cashier, mediaJpeg(400, 400), 'avatar', 'me')->assertCreated();

    expect($article->fresh()->image_media_id)->not->toBeNull();
    expect($cashier->fresh()->avatar_media_id)->not->toBeNull();
    // Les fichiers d'un membre sont rangés sous le compte, pas sous le membre.
    expect(Media::pluck('account_id')->unique()->all())->toBe([$owner->id]);
});

it("refuse le logo à qui n'a pas settings.manage, et la photo d'un collègue sans team.manage", function () {
    $owner = mediaOwner();
    $cashier = mediaMember($owner, 'cashier', ['settings.manage' => false, 'team.manage' => false]);
    $colleague = mediaMember($owner, 'cashier');

    mediaUpload($this, $cashier, mediaJpeg(300, 300), 'logo')->assertForbidden();
    mediaUpload($this, $cashier, mediaJpeg(300, 300), 'avatar', $colleague->id)->assertForbidden();
    expect(Media::count())->toBe(0);
});

it("refuse de rattacher une image à l'article d'un autre compte", function () {
    $ownerA = mediaOwner('A');
    $ownerB = mediaOwner('B');
    $articleB = mediaArticle($ownerB);

    mediaUpload($this, $ownerA, mediaJpeg(300, 300), 'product', $articleB->id)->assertNotFound();
    expect($articleB->fresh()->image_media_id)->toBeNull()->and(Media::count())->toBe(0);

    // Ni en passant par la création d'article avec l'image d'un autre compte.
    $mediaB = mediaUpload($this, $ownerB, mediaJpeg(300, 300), 'product')->json('data.id');
    $this->actingAs($ownerA)->postJson('/api/articles', [
        'name' => 'Sac', 'type' => 'simple', 'sale_price' => 1000, 'quantity' => 1, 'image_media_id' => $mediaB,
    ])->assertCreated();
    expect(Article::where('name', 'Sac')->first()->image_media_id)->toBeNull();
    expect(Media::find($mediaB)->owner_id)->toBeNull();
});

it("rattache à la création d'un article la photo envoyée avant", function () {
    $owner = mediaOwner();
    $mediaId = mediaUpload($this, $owner, mediaJpeg(600, 600), 'product')->assertCreated()->json('data.id');

    $this->actingAs($owner)->postJson('/api/articles', [
        'name' => 'Sac', 'type' => 'simple', 'sale_price' => 1000, 'quantity' => 1, 'image_media_id' => $mediaId,
    ])->assertCreated();

    $article = Article::where('name', 'Sac')->firstOrFail();
    expect($article->image_media_id)->toBe($mediaId)->and(Media::find($mediaId)->owner_id)->toBe($article->id);
});

it("remplacer une image supprime l'ancien fichier", function () {
    $owner = mediaOwner();
    $article = mediaArticle($owner);

    mediaUpload($this, $owner, mediaJpeg(500, 500), 'product', $article->id)->assertCreated();
    $first = Media::firstOrFail();
    mediaUpload($this, $owner, mediaJpeg(500, 500), 'product', $article->id)->assertCreated();

    Storage::disk('media')->assertMissing($first->path);
    expect(Media::find($first->id))->toBeNull();
    expect(Media::count())->toBe(1)->and(Storage::disk('media')->allFiles())->toHaveCount(1);
    expect($article->fresh()->image_media_id)->toBe(Media::first()->id);
});

it("retirer une image supprime le fichier et vide l'élément", function () {
    $owner = mediaOwner();
    mediaUpload($this, $owner, mediaJpeg(300, 300), 'avatar')->assertCreated();
    $path = Media::firstOrFail()->path;

    $this->actingAs($owner)->deleteJson('/api/media/avatar/me')->assertOk();

    Storage::disk('media')->assertMissing($path);
    expect($owner->fresh()->profile_image)->toBeNull()->and($owner->fresh()->avatar_media_id)->toBeNull();
});

it("supprimer un article supprime ses images ; archiver une variation garde la sienne", function () {
    $owner = mediaOwner();
    $article = mediaArticle($owner);
    $variation = Variation::create(['article_id' => $article->id, 'name' => 'Rouge', 'quantity' => 0, 'price' => 1000]);
    mediaUpload($this, $owner, mediaJpeg(300, 300), 'product', $article->id)->assertCreated();
    mediaUpload($this, $owner, mediaJpeg(300, 300), 'variation', $variation->id)->assertCreated();
    $variationPath = Media::where('kind', 'variation')->value('path');

    $variation->delete();
    Storage::disk('media')->assertExists($variationPath);

    $article->delete();
    expect(Media::count())->toBe(0)->and(Storage::disk('media')->allFiles())->toBe([]);
});

it("synchronise la photo d'un membre sur son accès et sa fiche associé", function () {
    $owner = mediaOwner();
    $member = mediaMember($owner, 'cashier');
    $collaborator = Collaborator::create(['user_id' => $owner->id, 'user_id_member' => $member->id, 'name' => 'Koffi', 'phone' => '0700000000']);

    mediaUpload($this, $owner, mediaJpeg(300, 300), 'avatar', $collaborator->id)->assertCreated();

    $media = Media::firstOrFail();
    expect($member->fresh()->profile_image)->toBe($media->url);
    expect($collaborator->fresh()->image)->toBe($media->url);
});

it('bloque les envois au-delà de la limite du compte', function () {
    config(['media.max_per_account' => 1]);
    $owner = mediaOwner();
    $article = mediaArticle($owner);

    mediaUpload($this, $owner, mediaJpeg(300, 300), 'product', $article->id)->assertCreated();
    mediaUpload($this, $owner, mediaJpeg(300, 300), 'avatar')
        ->assertStatus(422)
        ->assertJsonPath('errors.file.0', "Limite d'images atteinte pour ce compte. Supprimez-en ou contactez-nous.");
});

it('supprime une photo jamais rattachée après 24 h', function () {
    $owner = mediaOwner();
    mediaUpload($this, $owner, mediaJpeg(300, 300), 'product')->assertCreated();
    $orphan = Media::firstOrFail();

    $this->travel(25)->hours();
    mediaUpload($this, $owner, mediaJpeg(300, 300), 'avatar')->assertCreated();

    expect(Media::find($orphan->id))->toBeNull();
    Storage::disk('media')->assertMissing($orphan->path);
});
