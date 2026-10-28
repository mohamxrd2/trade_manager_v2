<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Auteur de l'action (propriétaire ou membre) — même convention que
     * operations.created_by_user_id (déjà en place). `user_id` reste le
     * compte propriétaire des données, inchangé. La reprise (lignes
     * existantes : created_by_user_id = user_id, donc le propriétaire) se
     * fait via `php artisan team:backfill`, pas ici.
     */
    public function up(): void
    {
        Schema::table('stock_replenishments', function (Blueprint $table) {
            $table->uuid('created_by_user_id')->nullable()->after('user_id');
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->uuid('created_by_user_id')->nullable()->after('user_id');
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_replenishments', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);
            $table->dropColumn('created_by_user_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);
            $table->dropColumn('created_by_user_id');
        });
    }
};
