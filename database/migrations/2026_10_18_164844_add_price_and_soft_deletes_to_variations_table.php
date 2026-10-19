<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Additive only: new nullable columns, soft deletes, and a partial unique
     * index on (article_id, lower(trim(name))) restricted to non-archived rows
     * so an archived variation's name can be reused. No existing column is
     * dropped, renamed, or made non-nullable here.
     */
    public function up(): void
    {
        Schema::table('variations', function (Blueprint $table) {
            $table->decimal('price', 12, 2)->nullable()->after('quantity');
            $table->decimal('cost_price', 12, 2)->nullable()->after('price');
            $table->unsignedInteger('low_stock_threshold')->nullable()->after('cost_price');
            $table->softDeletes();
        });

        DB::statement(
            'CREATE UNIQUE INDEX variations_article_id_norm_name_unique
             ON variations (article_id, lower(trim(name)))
             WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS variations_article_id_norm_name_unique');

        Schema::table('variations', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['price', 'cost_price', 'low_stock_threshold']);
        });
    }
};
