<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Images hébergées (Cloudflare R2 en production). Additive et réversible :
 * une table media, et une colonne *_media_id nullable sur chaque table qui
 * a une image. Les anciennes colonnes texte (articles.image,
 * companies.logo, users.profile_image…) restent : elles portent l'URL
 * affichée, et les URL externes déjà présentes (Google…) continuent de
 * marcher.
 */
return new class extends Migration
{
    /** table => colonne */
    private const OWNER_COLUMNS = [
        'articles' => 'image_media_id',
        'variations' => 'image_media_id',
        'companies' => 'logo_media_id',
        'users' => 'avatar_media_id',
        'collaborators' => 'image_media_id',
    ];

    public function up(): void
    {
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

            $table->index('account_id');
            $table->index(['owner_type', 'owner_id']);
            $table->foreign('account_id')->references('id')->on('users')->cascadeOnDelete();
        });

        foreach (self::OWNER_COLUMNS as $tableName => $column) {
            Schema::table($tableName, function (Blueprint $table) use ($column) {
                $table->uuid($column)->nullable();
                $table->foreign($column)->references('id')->on('media')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::OWNER_COLUMNS as $tableName => $column) {
            Schema::table($tableName, function (Blueprint $table) use ($column) {
                $table->dropForeign([$column]);
                $table->dropColumn($column);
            });
        }

        Schema::dropIfExists('media');
    }
};
