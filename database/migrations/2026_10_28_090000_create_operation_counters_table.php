<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compteur journalier par compte et par préfixe (V/D/A/R) — garantit
 * l'unicité des codes d'opération même avec deux caissiers simultanés (voir
 * App\Services\OperationCodeGenerator, qui verrouille la ligne concernée
 * avec lockForUpdate() avant d'incrémenter).
 *
 * PK entière classique (pas uuid) : table purement interne, jamais exposée
 * par l'API, un compteur n'a pas besoin d'être non-devinable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operation_counters', function (Blueprint $table) {
            $table->id();
            $table->uuid('user_id');
            $table->string('prefix', 1);
            $table->date('day');
            $table->unsignedInteger('last_value')->default(0);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['user_id', 'prefix', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_counters');
    }
};
