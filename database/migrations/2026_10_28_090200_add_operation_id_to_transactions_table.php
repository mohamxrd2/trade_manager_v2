<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rattache chaque ligne (transaction) à son en-tête (opération). Nullable
 * pendant toute la transition : la reprise (operations:backfill) la peuple
 * progressivement pour les transactions existantes, et les nouvelles
 * transactions la reçoivent automatiquement dès ce déploiement (voir
 * Transaction::booted(), hook `creating`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'operation_id')) {
                $table->uuid('operation_id')->nullable()->after('collaborator_id');
            }
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreign('operation_id')->references('id')->on('operations')->onDelete('set null');
            $table->index('operation_id');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['operation_id']);
            $table->dropIndex(['operation_id']);
            $table->dropColumn('operation_id');
        });
    }
};
