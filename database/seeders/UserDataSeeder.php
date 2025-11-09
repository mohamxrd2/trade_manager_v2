<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Variation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Créer ou récupérer l'utilisateur
        $user = User::firstOrCreate(
            ['email' => 'zoranstro@gmail.com'],
            [
                'first_name' => 'Zoran',
                'last_name' => 'Stro',
                'username' => 'zoranstro',
                'password' => Hash::make('Mohamed10@'),
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
        $variableArticles = [
            [
                'name' => 'T-Shirt Premium',
                'sale_price' => 24.99,
                'quantity' => 0, // Pour les articles variables, la quantité est gérée par les variations
                'type' => 'variable',
                'variations' => [
                    ['name' => 'T-Shirt Premium - S', 'quantity' => 25],
                    ['name' => 'T-Shirt Premium - M', 'quantity' => 30],
                    ['name' => 'T-Shirt Premium - L', 'quantity' => 20],
                    ['name' => 'T-Shirt Premium - XL', 'quantity' => 15],
                ],
            ],
            [
                'name' => 'Chaussures de Sport',
                'sale_price' => 89.99,
                'quantity' => 0,
                'type' => 'variable',
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
                'sale_price' => 59.99,
                'quantity' => 0,
                'type' => 'variable',
                'variations' => [
                    ['name' => 'Sac à Dos - Noir', 'quantity' => 20],
                    ['name' => 'Sac à Dos - Gris', 'quantity' => 18],
                    ['name' => 'Sac à Dos - Bleu', 'quantity' => 15],
                ],
            ],
            [
                'name' => 'Montre Connectée',
                'sale_price' => 199.99,
                'quantity' => 0,
                'type' => 'variable',
                'variations' => [
                    ['name' => 'Montre Connectée - Noire', 'quantity' => 12],
                    ['name' => 'Montre Connectée - Blanche', 'quantity' => 10],
                    ['name' => 'Montre Connectée - Rose', 'quantity' => 8],
                ],
            ],
        ];

        foreach ($variableArticles as $articleData) {
            $variations = $articleData['variations'];
            unset($articleData['variations']);

            $article = Article::create(array_merge($articleData, ['user_id' => $user->id]));

            // Créer les variations pour cet article
            foreach ($variations as $variationData) {
                Variation::create(array_merge($variationData, ['article_id' => $article->id]));
            }
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

