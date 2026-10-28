<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ajustements par membre par rapport à son rôle — `granted=true` ajoute
     * une permission non couverte par le rôle (ex. cashier + expenses.create),
     * `granted=false` en retire une du rôle. La permission EFFECTIVE d'un
     * membre = permissions du rôle, puis appliquer ces ajustements (voir
     * app/Support/Permissions.php). Une seule ligne par (user_id,
     * permission) : une nouvelle demande sur la même permission REMPLACE
     * l'ancienne plutôt que d'empiler un historique.
     */
    public function up(): void
    {
        Schema::create('member_permissions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('user_id');
            $table->string('permission');
            $table->boolean('granted');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['user_id', 'permission']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_permissions');
    }
};
