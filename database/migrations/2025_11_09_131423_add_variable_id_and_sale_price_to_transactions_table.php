<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->uuid('variable_id')->nullable()->after('article_id');
            $table->foreign('variable_id')->references('id')->on('variations')->onDelete('cascade');
            $table->decimal('sale_price', 15, 2)->nullable()->after('amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['variable_id']);
            $table->dropColumn(['variable_id', 'sale_price']);
        });
    }
};
