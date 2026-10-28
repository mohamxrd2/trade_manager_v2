<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Préférences d'affichage du ticket de caisse, par compte (chantier
 * ticket-caisse, étape 1 — l'impression ESC/POS en Bluetooth viendra plus
 * tard dans une table séparée si besoin, sans toucher celle-ci). Une ligne
 * par utilisateur ; absente -> valeurs par défaut appliquées en code
 * (ReceiptSettingController), pas de ligne créée tant que l'utilisateur n'a
 * rien réglé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_settings', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('user_id')->unique();
            $table->enum('width', ['58mm', '80mm'])->default('58mm');
            $table->boolean('show_logo')->default(true);
            $table->boolean('show_address')->default(true);
            $table->boolean('show_phone')->default(true);
            $table->boolean('show_tax_id')->default(true);
            $table->string('footer_message', 120)->nullable();
            $table->boolean('show_qr')->default(true);
            $table->boolean('show_waelly_mention')->default(true);
            $table->boolean('auto_print')->default(false);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_settings');
    }
};
