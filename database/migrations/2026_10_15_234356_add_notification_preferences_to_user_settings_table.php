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
        Schema::table('user_settings', function (Blueprint $table) {
            $table->boolean('notify_stock_low')->default(true);
            $table->boolean('notify_invoice_paid')->default(true);
            $table->boolean('notify_invoice_overdue')->default(true);
            $table->boolean('notify_team_joined')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropColumn([
                'notify_stock_low',
                'notify_invoice_paid',
                'notify_invoice_overdue',
                'notify_team_joined',
            ]);
        });
    }
};
