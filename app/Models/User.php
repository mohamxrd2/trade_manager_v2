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
        'password',
        'company_share',
        'profile_image',
        'provider',
        'provider_id',
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
        ];
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
     * Get the total articles attribute.
     */
    public function getTotalArticlesAttribute(): int
    {
        return $this->articles()->count();
    }

    /**
     * Get the total low stock attribute.
     */
    public function getTotalLowStockAttribute(): int
    {
        return $this->articles()
            ->whereRaw('(
                SELECT COALESCE(SUM(quantity), 0) 
                FROM transactions 
                WHERE transactions.article_id = articles.id 
                AND transactions.type = \'sale\'
            ) * 100 / articles.quantity > 80')
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
     * Get the wallet attribute (calculated_wallet * company_share / 100).
     */
    public function getWalletAttribute(): float
    {
        $calculatedWallet = $this->calculated_wallet;
        return (float) bcmul($calculatedWallet, bcdiv($this->company_share, 100, 4), 2);
    }
}
