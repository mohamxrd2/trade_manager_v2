<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Core Web Vitals mesurés chez les vrais utilisateurs (LCP, INP, CLS,
     * FCP, TTFB), envoyés par le front (POST /api/web-vitals). Lus par
     * `php artisan web-vitals:report`. Table indépendante : additive.
     */
    public function up(): void
    {
        Schema::create('web_vitals', function (Blueprint $table) {
            $table->id();
            $table->string('name', 8);
            $table->double('value');
            $table->string('rating', 20)->nullable();
            $table->string('page', 191);
            $table->string('device', 10)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['name', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_vitals');
    }
};
