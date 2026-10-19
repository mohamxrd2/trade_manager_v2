<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Variation;
use App\Services\ArticleStockService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Identifiants pilotés par DEFAULT_ADMIN_EMAIL / DEFAULT_ADMIN_PASSWORD
     * (voir .env.example) — jamais de credential en dur dans ce fichier
     * versionné. Si DEFAULT_ADMIN_PASSWORD est absent, un mot de passe
     * aléatoire est généré et affiché en console pour cette exécution.
     */
    public function run(): void
    {
        $email = env('DEFAULT_ADMIN_EMAIL', 'admin@example.test');
        $password = env('DEFAULT_ADMIN_PASSWORD');

        if (!$password) {
            $password = Str::random(16);
            $this->command->warn("⚠️ DEFAULT_ADMIN_PASSWORD non défini — mot de passe généré : {$password}");
        }

        // Créer ou récupérer l'utilisateur
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'first_name' => 'Zoran',
                'last_name' => 'Stro',
                'username' => 'zoranstro',
                'password' => Hash::make($password),
                'company_share' => 100.00,
            ]
        );

        $this->command->info("✅ Utilisateur créé/récupéré : {$user->email}");

        // Créer des articles simples (non variables)
        $this->createSimpleArticles($user);

        // Créer des articles variables
        $this->createVariableArticles($user);

        // Créer des transactions de vente
        $this->createSaleTransactions($user);

        // Créer des dépenses
        $this->createExpenses($user);

        $this->command->info("✅ Seeding terminé avec succès !");
    }

    /**
     * Créer des articles simples (non variables)
     */
    private function createSimpleArticles(User $user): void
    {
        $simpleArticles = [
            [
                'name' => 'Ordinateur Portable Dell',
                'sale_price' => 899.99,
                'quantity' => 15,
                'type' => 'simple',
            ],
            [
                'name' => 'Souris Sans Fil Logitech',
                'sale_price' => 29.99,
                'quantity' => 50,
                'type' => 'simple',
            ],
            [
                'name' => 'Clavier Mécanique RGB',
                'sale_price' => 79.99,
                'quantity' => 30,
                'type' => 'simple',
            ],
            [
                'name' => 'Écran 27 pouces 4K',
                'sale_price' => 349.99,
                'quantity' => 20,
                'type' => 'simple',
            ],
            [
                'name' => 'Webcam HD 1080p',
                'sale_price' => 49.99,
                'quantity' => 40,
                'type' => 'simple',
            ],
        ];

        foreach ($simpleArticles as $articleData) {
            Article::create(array_merge($articleData, ['user_id' => $user->id]));
        }

        $this->command->info("✅ " . count($simpleArticles) . " articles simples créés");
    }

    /**
     * Créer des articles variables
     */
    private function createVariableArticles(User $user): void
    {
        // 'default_price' n'est jamais stocké sur l'article : c'est juste le prix
        // appliqué à chaque variation ci-dessous (règle : un article variable n'a
        // ni quantité ni prix propres, seulement des variations).
        $variableArticles = [
            [
                'name' => 'T-Shirt Premium',
                'default_price' => 24.99,
                'variations' => [
                    ['name' => 'T-Shirt Premium - S', 'quantity' => 25],
                    ['name' => 'T-Shirt Premium - M', 'quantity' => 30],
                    ['name' => 'T-Shirt Premium - L', 'quantity' => 20],
                    ['name' => 'T-Shirt Premium - XL', 'quantity' => 15],
                ],
            ],
            [
                'name' => 'Chaussures de Sport',
                'default_price' => 89.99,
                'variations' => [
                    ['name' => 'Chaussures de Sport - 40', 'quantity' => 10],
                    ['name' => 'Chaussures de Sport - 41', 'quantity' => 12],
                    ['name' => 'Chaussures de Sport - 42', 'quantity' => 15],
                    ['name' => 'Chaussures de Sport - 43', 'quantity' => 10],
                    ['name' => 'Chaussures de Sport - 44', 'quantity' => 8],
                ],
            ],
            [
                'name' => 'Sac à Dos Professionnel',
                'default_price' => 59.99,
                'variations' => [
                    ['name' => 'Sac à Dos - Noir', 'quantity' => 20],
                    ['name' => 'Sac à Dos - Gris', 'quantity' => 18],
                    ['name' => 'Sac à Dos - Bleu', 'quantity' => 15],
                ],
            ],
            [
                'name' => 'Montre Connectée',
                'default_price' => 199.99,
                'variations' => [
                    ['name' => 'Montre Connectée - Noire', 'quantity' => 12],
                    ['name' => 'Montre Connectée - Blanche', 'quantity' => 10],
                    ['name' => 'Montre Connectée - Rose', 'quantity' => 8],
                ],
            ],
        ];

        foreach ($variableArticles as $articleData) {
            $variations = $articleData['variations'];
            $defaultPrice = $articleData['default_price'];

            $article = Article::create([
                'name' => $articleData['name'],
                'type' => 'variable',
                'sale_price' => 0,
                'quantity' => 0,
                'user_id' => $user->id,
            ]);

            foreach ($variations as $variationData) {
                Variation::create([
                    'article_id' => $article->id,
                    'name' => $variationData['name'],
                    'quantity' => $variationData['quantity'],
                    'price' => $defaultPrice,
                ]);
            }

            // quantity/sale_price/price_max de l'article sont calculés à partir
            // des variations qu'on vient de créer — jamais saisis directement.
            ArticleStockService::syncVariableTotals($article);
        }

        $this->command->info("✅ " . count($variableArticles) . " articles variables créés avec leurs variations");
    }

    /**
     * Créer des transactions de vente
     */
    private function createSaleTransactions(User $user): void
    {
        // Ventes d'articles simples
        $simpleArticles = Article::where('user_id', $user->id)
            ->where('type', 'simple')
            ->get();

        foreach ($simpleArticles as $article) {
            // Créer 2-4 ventes par article
            $numberOfSales = rand(2, 4);
            
            for ($i = 0; $i < $numberOfSales; $i++) {
                $quantity = rand(1, min(5, $article->quantity));
                $salePrice = $article->sale_price * (1 + (rand(-10, 10) / 100)); // Prix avec variation de ±10%
                $amount = $salePrice * $quantity;

                Transaction::create([
                    'user_id' => $user->id,
                    'article_id' => $article->id,
                    'variable_id' => null,
                    'name' => "Vente de {$quantity} {$article->name}",
                    'quantity' => $quantity,
                    'amount' => round($amount, 2),
                    'sale_price' => round($salePrice, 2),
                    'type' => 'sale',
                    'created_at' => now()->subDays(rand(1, 30)),
                ]);
            }
        }

        // Ventes d'articles variables
        $variableArticles = Article::where('user_id', $user->id)
            ->where('type', 'variable')
            ->with('variations')
            ->get();

        foreach ($variableArticles as $article) {
            foreach ($article->variations as $variation) {
                // Créer 1-3 ventes par variation
                $numberOfSales = rand(1, 3);
                
                for ($i = 0; $i < $numberOfSales; $i++) {
                    $quantity = rand(1, min(3, $variation->quantity));
                    $salePrice = $article->sale_price * (1 + (rand(-10, 10) / 100));
                    $amount = $salePrice * $quantity;

                    Transaction::create([
                        'user_id' => $user->id,
                        'article_id' => $article->id,
                        'variable_id' => $variation->id,
                        'name' => "Vente de {$quantity} {$article->name} {$variation->name}",
                        'quantity' => $quantity,
                        'amount' => round($amount, 2),
                        'sale_price' => round($salePrice, 2),
                        'type' => 'sale',
                        'created_at' => now()->subDays(rand(1, 30)),
                    ]);
                }
            }
        }

        $totalSales = Transaction::where('user_id', $user->id)
            ->where('type', 'sale')
            ->count();

        $this->command->info("✅ {$totalSales} transactions de vente créées");
    }

    /**
     * Créer des dépenses
     */
    private function createExpenses(User $user): void
    {
        $expenses = [
            ['name' => 'Loyer du local commercial', 'amount' => 1200.00],
            ['name' => 'Électricité et eau', 'amount' => 150.00],
            ['name' => 'Internet et téléphonie', 'amount' => 89.99],
            ['name' => 'Assurance entreprise', 'amount' => 250.00],
            ['name' => 'Publicité Facebook Ads', 'amount' => 300.00],
            ['name' => 'Frais de transport', 'amount' => 75.50],
            ['name' => 'Fournitures de bureau', 'amount' => 45.99],
            ['name' => 'Maintenance équipements', 'amount' => 180.00],
            ['name' => 'Formation employés', 'amount' => 500.00],
            ['name' => 'Services comptables', 'amount' => 350.00],
            ['name' => 'Marketing et communication', 'amount' => 200.00],
            ['name' => 'Réparation matériel', 'amount' => 125.00],
        ];

        foreach ($expenses as $expense) {
            Transaction::create([
                'user_id' => $user->id,
                'article_id' => null,
                'variable_id' => null,
                'name' => $expense['name'],
                'quantity' => null,
                'amount' => $expense['amount'],
                'sale_price' => null,
                'type' => 'expense',
                'created_at' => now()->subDays(rand(1, 60)),
            ]);
        }

        $this->command->info("✅ " . count($expenses) . " dépenses créées");
    }
}

