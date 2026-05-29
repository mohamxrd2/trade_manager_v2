<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Table des factures.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            // Clé primaire UUID
            $table->uuid('id')->primary();
            
            // Relations
            $table->foreignUuid('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            
            $table->foreignUuid('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();
            
            $table->foreignUuid('client_id')
                ->constrained('clients')
                ->cascadeOnDelete();
            
            // Numéro de facture (unique par utilisateur, format: INV-YYYY-XXXXX)
            $table->string('invoice_number')->unique();
            
            // Statut de la facture
            $table->enum('status', [
                'draft',      // Brouillon
                'unpaid',     // Non payée
                'paid',       // Payée
                'cancelled',  // Annulée
                'overdue'     // En retard
            ])->default('draft');
            
            // Montants calculés
            $table->decimal('subtotal', 15, 2)->default(0);        // Sous-total avant remise
            $table->decimal('discount_amount', 15, 2)->default(0); // Montant remise
            $table->decimal('discount_percent', 5, 2)->default(0); // Pourcentage remise
            $table->decimal('tax_amount', 15, 2)->default(0);      // Montant TVA
            $table->decimal('tax_percent', 5, 2)->default(0);      // Pourcentage TVA
            $table->decimal('shipping_fee', 15, 2)->default(0);    // Frais de livraison
            $table->decimal('total', 15, 2)->default(0);           // Total final
            
            // Adresses (snapshot au moment de la création)
            $table->text('billing_address')->nullable();
            $table->text('shipping_address')->nullable();
            
            // Thème de facture pour le rendu
            $table->string('theme')->default('classic');
            
            // Dates
            $table->date('issued_at');              // Date d'émission
            $table->date('due_date');               // Date d'échéance
            $table->timestamp('paid_at')->nullable(); // Date de paiement
            
            // Notes et conditions
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();      // Conditions de paiement
            
            // Préparation pour futur export PDF et envoi email
            $table->string('pdf_path')->nullable();
            $table->timestamp('sent_at')->nullable();
            
            $table->timestamps();
            
            // Index pour optimisation
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'client_id']);
            $table->index(['user_id', 'due_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
