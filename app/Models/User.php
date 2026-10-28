<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens, HasUuids;
    

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'username',
        'email',
        'phone',
        'password',
        'company_share',
        'profile_image',
        'provider',
        'provider_id',
        'account_id',
        'role',
        'must_change_password',
        'status',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'total_articles',
        'total_low_stock',
        'total_stock_value',
        'total_remaining_quantity',
        'total_sale',
        'total_expense',
        'calculated_wallet',
        'wallet',
        'total_deposit',
        'total_withdrawal',
        'balance',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'company_share' => 'decimal:2',
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Chantier "Équipe" — id du COMPTE auquel ce user appartient : le sien
     * propre s'il est propriétaire (account_id NULL), sinon celui du
     * propriétaire auquel il est rattaché. Toutes les données métier
     * (articles, opérations, transactions, factures, clients, paramètres,
     * associés) sont scopées sur CETTE valeur, jamais sur `$this->id`
     * directement — voir App\Support\CurrentAccount, utilisé dans les
     * contrôleurs à la place de Auth::id().
     */
    public function accountId(): string
    {
        return $this->account_id ?? $this->id;
    }

    public function isOwner(): bool
    {
        return $this->account_id === null;
    }

    public function isMember(): bool
    {
        return $this->account_id !== null;
    }

    /**
     * Le compte propriétaire (uniquement pertinent pour un membre — null
     * pour un propriétaire, qui EST son propre compte).
     */
    public function account(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'account_id');
    }

    /**
     * Les membres rattachés à ce compte (vide pour un membre : seul un
     * propriétaire a des membres).
     */
    public function members(): HasMany
    {
        return $this->hasMany(User::class, 'account_id');
    }

    /**
     * Get the articles for the user.
     */
    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }

    /**
     * Get the transactions for the user.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Get the collaborators for the user.
     */
    public function collaborators(): HasMany
    {
        return $this->hasMany(Collaborator::class);
    }

    /**
     * Get the company for the user.
     */
    public function company(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Company::class);
    }

    /**
     * Get the settings for the user.
     */
    public function settings(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(UserSetting::class);
    }

    /**
     * Get the receipt (cash register ticket) display preferences for the user.
     */
    public function receiptSetting(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ReceiptSetting::class);
    }

    /**
     * Get the notifications for the user.
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /**
     * Get the stock replenishments made by the user.
     */
    public function stockReplenishments(): HasMany
    {
        return $this->hasMany(StockReplenishment::class);
    }

    /**
     * Get the clients for the user.
     */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /**
     * Get the invoices for the user.
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Get the total articles attribute.
     */
    public function getTotalArticlesAttribute(): int
    {
        return $this->articles()->count();
    }

    /**
     * Get the total low stock attribute.
     * 
     * Retourne le nombre d'articles avec sales_percentage entre le seuil personnalisé et 100 (inclus).
     * 
     * Un article est considéré en stock faible si entre le seuil personnalisé (par défaut 80%) et 100% de sa quantité
     * a été vendue (sales_percentage = (sold_quantity * 100) / quantity entre [seuil;100]).
     * 
     * Cette méthode utilise la même formule que getSalesPercentageAttribute() 
     * dans le modèle Article pour garantir la cohérence.
     * Le seuil est récupéré depuis les paramètres utilisateur (user_settings.low_stock_threshold).
     */
    public function getTotalLowStockAttribute(): int
    {
        // Récupérer le seuil personnalisé depuis les paramètres utilisateur (défaut: 80)
        $lowStockThreshold = $this->settings?->low_stock_threshold ?? 80;

        // Utiliser une requête SQL optimisée qui reproduit exactement la logique
        // de sales_percentage du modèle Article : (sold_quantity * 100) / quantity entre [seuil;100]
        return $this->articles()
            ->where('quantity', '>', 0) // Exclure les articles avec quantity = 0 pour éviter la division par zéro
            ->whereRaw('(
                COALESCE((
                    SELECT SUM(quantity) 
                FROM transactions 
                WHERE transactions.article_id = articles.id 
                AND transactions.type = \'sale\'
                ), 0) * 100.0 / articles.quantity
            ) BETWEEN ? AND 100', [$lowStockThreshold])
            ->count();
    }

    /**
     * Get the total stock value attribute.
     */
    public function getTotalStockValueAttribute(): float
    {
        // Requête SQL optimisée pour calculer la valeur totale du stock
        $result = DB::select("
            SELECT SUM(
                (articles.quantity - COALESCE(sales.sold_quantity, 0)) * articles.sale_price
            ) as total_value
            FROM articles 
            LEFT JOIN (
                SELECT article_id, SUM(quantity) as sold_quantity
                FROM transactions 
                WHERE type = 'sale'
                GROUP BY article_id
            ) sales ON articles.id = sales.article_id
            WHERE articles.user_id = ?
        ", [$this->id]);

        return $result[0]->total_value ?? 0;
    }

    /**
     * Get the total remaining quantity attribute.
     */
    public function getTotalRemainingQuantityAttribute(): int
    {
        // Requête SQL optimisée pour calculer la quantité totale restante
        $result = DB::select("
            SELECT SUM(
                articles.quantity - COALESCE(sales.sold_quantity, 0)
            ) as total_remaining
            FROM articles 
            LEFT JOIN (
                SELECT article_id, SUM(quantity) as sold_quantity
                FROM transactions 
                WHERE type = 'sale'
                GROUP BY article_id
            ) sales ON articles.id = sales.article_id
            WHERE articles.user_id = ?
        ", [$this->id]);

        return $result[0]->total_remaining ?? 0;
    }

    /**
     * Get the total sale attribute.
     */
    public function getTotalSaleAttribute(): float
    {
        return $this->transactions()
            ->where('type', 'sale')
            ->sum('amount') ?? 0;
    }

    /**
     * Get the total expense attribute.
     */
    public function getTotalExpenseAttribute(): float
    {
        return $this->transactions()
            ->where('type', 'expense')
            ->sum('amount') ?? 0;
    }

    /**
     * Get the calculated wallet attribute (from transactions).
     */
    public function getCalculatedWalletAttribute(): float
    {
        return $this->total_sale - $this->total_expense;
    }

    /**
     * Get the wallet attribute (balance * company_share / 100).
     *
     * Base = `balance` (ventes − dépenses + apports − retraits), pas
     * `calculated_wallet` — décision métier du 26/10/2026 : les apports et
     * retraits du gérant font désormais partie de l'argent partagé entre
     * lui et ses collaborateurs (un apport augmente la part de tous selon
     * leur %, un retrait la diminue). `calculated_wallet` reste inchangé et
     * continue de servir de base au chiffre d'affaires/bénéfice (voir
     * getCalculatedWalletAttribute()), qui n'inclut toujours pas les
     * apports/retraits.
     */
    public function getWalletAttribute(): float
    {
        return (float) bcmul($this->balance, bcdiv($this->company_share, 100, 4), 2);
    }

    /**
     * Get the total deposit attribute (somme des apports de fonds).
     */
    public function getTotalDepositAttribute(): float
    {
        return $this->transactions()
            ->where('type', 'apport')
            ->sum('amount') ?? 0;
    }

    /**
     * Get the total withdrawal attribute (somme des retraits du gérant).
     */
    public function getTotalWithdrawalAttribute(): float
    {
        return $this->transactions()
            ->where('type', 'retrait')
            ->sum('amount') ?? 0;
    }

    /**
     * Get the balance attribute (trésorerie réelle, apports/retraits inclus).
     *
     * Distinct de `calculated_wallet` (ventes − dépenses SEULEMENT, base du
     * chiffre d'affaires/bénéfice — inchangé). `balance` est la trésorerie
     * réelle affichée sur le Portefeuille/l'Accueil, ET — décision métier du
     * 26/10/2026 — la base de répartition des parts collaborateurs (gérant
     * compris, voir getWalletAttribute() ci-dessus et
     * Collaborator::getWalletAttribute()) : un apport augmente donc les
     * montants de tous les membres selon leur part, un retrait les diminue.
     */
    public function getBalanceAttribute(): float
    {
        return $this->total_sale - $this->total_expense + $this->total_deposit - $this->total_withdrawal;
    }
}
