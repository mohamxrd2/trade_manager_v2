<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('user_id')->unique();
            $table->string('name'); // Nom de l'entreprise
            $table->string('sector')->nullable(); // Secteur d'activité
            $table->text('headquarters')->nullable(); // Siège social
            $table->string('email')->nullable(); // Email de l'entreprise
            $table->string('legal_status')->nullable(); // Statut juridique
            $table->string('bank_account_number')->nullable(); // N° Compte bancaire
            $table->string('logo')->nullable(); // Logo de l'entreprise (facultatif)
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
