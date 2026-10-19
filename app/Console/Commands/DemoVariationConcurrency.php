<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserSetting;
use App\Models\Variation;
use App\Services\ArticleStockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Démontre que lockForUpdate() empêche la survente : deux VRAIES requêtes
 * HTTP concurrentes (deux processus curl distincts, lancés en parallèle)
 * tentent de vendre la dernière unité d'une variation. Exige le serveur de
 * dev (`php artisan serve`, PHP_CLI_SERVER_WORKERS >= 2 dans .env, sinon le
 * serveur traite les requêtes en série et le test ne prouverait rien) et
 * frappe la base de données RÉELLEMENT configurée (jamais une base de test
 * isolée) — à ne lancer qu'en local.
 */
class DemoVariationConcurrency extends Command
{
    protected $signature = 'variations:demo-concurrency {--base-url=http://127.0.0.1:8000}';

    protected $description = 'Démo : deux ventes HTTP concurrentes sur le dernier exemplaire d\'une variation — une seule doit réussir';

    public function handle(): int
    {
        if (!app()->environment('local')) {
            $this->error('Cette démonstration crée des données réelles via de vraies requêtes HTTP — réservée à APP_ENV=local.');
            return self::FAILURE;
        }

        $baseUrl = rtrim($this->option('base-url'), '/');

        $this->info("=== Démo concurrence : vente simultanée du dernier exemplaire ===");
        $this->newLine();

        $user = User::create([
            'first_name' => 'Demo',
            'last_name' => 'Concurrency',
            'username' => 'demo_concurrency_' . Str::random(8),
            'email' => 'demo_concurrency_' . uniqid() . '@example.test',
            'password' => bcrypt('irrelevant'),
        ]);
        UserSetting::create(['user_id' => $user->id]);
        $token = $user->createToken('demo-concurrency')->plainTextToken;

        $article = Article::create([
            'user_id' => $user->id,
            'name' => 'DEMO_CONCURRENCY',
            'type' => 'variable',
            'sale_price' => 0,
            'quantity' => 0,
        ]);
        $variation = Variation::create([
            'article_id' => $article->id,
            'name' => 'Unique',
            'quantity' => 1,
            'price' => 1000,
        ]);
        ArticleStockService::syncVariableTotals($article);

        $this->line("Variation {$variation->id} créée : quantity=1, restant=1.");
        $this->line("Deux requêtes HTTP POST /api/transactions (quantity=1 chacune) lancées EN PARALLÈLE...");
        $this->newLine();

        $payload = json_encode([
            'type' => 'sale',
            'article_id' => $article->id,
            'variable_id' => $variation->id,
            'quantity' => 1,
        ]);

        $makeCurl = fn () => [
            'curl', '-s', '-w', '\n%{http_code}',
            '-X', 'POST', "{$baseUrl}/api/transactions",
            '-H', "Authorization: Bearer {$token}",
            '-H', 'Accept: application/json',
            '-H', 'Content-Type: application/json',
            '-d', $payload,
        ];

        // Process::start() est non bloquant : les deux curl démarrent côté OS
        // avant que PHP n'attende quoi que ce soit — c'est ce qui crée la
        // vraie concurrence (deux connexions DB distinctes, côté serveur).
        $procA = Process::start($makeCurl());
        $procB = Process::start($makeCurl());

        while ($procA->running() || $procB->running()) {
            usleep(5000);
        }

        $resultA = $procA->wait();
        $resultB = $procB->wait();

        [$bodyA, $codeA] = $this->splitCurlOutput($resultA->output());
        [$bodyB, $codeB] = $this->splitCurlOutput($resultB->output());

        $this->line("Requête A → HTTP {$codeA} : {$bodyA}");
        $this->line("Requête B → HTTP {$codeB} : {$bodyB}");
        $this->newLine();

        $successCount = ((int) ($codeA === '201')) + ((int) ($codeB === '201'));
        $variation->refresh();

        $this->line("Ventes réussies : {$successCount} / 2");
        $this->line("Restant final de la variation : {$variation->remaining_quantity}");
        $this->newLine();

        $ok = $successCount === 1 && $variation->remaining_quantity === 0;

        if ($ok) {
            $this->info('✅ Une seule vente a réussi : lockForUpdate() a bien empêché la survente concurrente.');
        } else {
            $this->error('❌ Résultat inattendu : ' . ($successCount !== 1
                ? "{$successCount} vente(s) ont réussi au lieu d'exactement 1 (survente possible, ou serveur traitant les requêtes en série — vérifiez PHP_CLI_SERVER_WORKERS dans .env)."
                : 'le restant final ne vaut pas 0.'));
        }

        // Nettoyage : aucune trace laissée.
        Transaction::where('article_id', $article->id)->delete();
        Variation::where('article_id', $article->id)->forceDelete();
        $article->delete();
        $user->tokens()->delete();
        UserSetting::where('user_id', $user->id)->delete();
        $user->delete();

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{0: string, 1: string} [corps JSON, code HTTP]
     */
    private function splitCurlOutput(string $raw): array
    {
        $parts = explode("\n", trim($raw));
        $code = array_pop($parts);
        return [implode("\n", $parts), trim($code)];
    }
}
