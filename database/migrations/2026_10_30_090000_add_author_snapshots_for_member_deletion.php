<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Suppression DÉFINITIVE d'un membre (DELETE /api/team/{id}?permanent=1) :
     * ses ventes, factures et réapprovisionnements restent (created_by_user_id
     * passe déjà à NULL, clés en nullOnDelete), mais son NOM disparaîtrait —
     * et l'auteur affiché retomberait sur le propriétaire. On fige donc le
     * nom (et le rôle, pour « par Koffi (Caissier) ») dans ces lignes juste
     * avant la suppression (TeamService::deleteMemberPermanently()).
     *
     * activity_logs.user_id était en CASCADE : supprimer un membre aurait
     * effacé tout son journal (ventes supprimées, changements de prix…). Il
     * passe en SET NULL, avec le nom figé dans activity_logs.user_name.
     *
     * Pas de reprise : ces colonnes ne servent qu'aux auteurs supprimés, il
     * n'y en a aucun avant cette migration.
     */
    public function up(): void
    {
        Schema::table('operations', function (Blueprint $table) {
            $table->string('created_by_name')->nullable()->after('created_by_user_id');
            $table->string('created_by_role', 32)->nullable()->after('created_by_name');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('created_by_name')->nullable()->after('created_by_user_id');
        });

        Schema::table('stock_replenishments', function (Blueprint $table) {
            $table->string('created_by_name')->nullable()->after('created_by_user_id');
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->uuid('user_id')->nullable()->change();
            $table->string('user_name')->nullable()->after('user_id');
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Les entrées dont l'auteur a été supprimé (user_id NULL) ne peuvent
        // pas revenir sous une colonne NOT NULL : elles sont retirées.
        DB::table('activity_logs')->whereNull('user_id')->delete();

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropColumn('user_name');
            $table->uuid('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('stock_replenishments', function (Blueprint $table) {
            $table->dropColumn('created_by_name');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('created_by_name');
        });

        Schema::table('operations', function (Blueprint $table) {
            $table->dropColumn(['created_by_name', 'created_by_role']);
        });
    }
};
