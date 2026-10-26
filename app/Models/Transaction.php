<?php

namespace App\Models;

use App\Services\OperationCodeGenerator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Transaction extends Model
{
    use HasUuids; // Utiliser UUIDs pour la clé primaire
    use SoftDeletes; // delete() marque deleted_at au lieu de retirer la ligne — restore() possible (voir TransactionController::restore())


    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'article_id',
        'variable_id',
        'invoice_id',
        'name',
        'quantity',
        'amount',
        'sale_price',
        'type',
        'idempotency_key',
        'funding_source',
        'collaborator_id',
        'note',
        'payment_method',
        'operation_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'amount' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'quantity' => 'integer',
    ];

    /**
     * `operation_code` — étape 2 du chantier "paniers multi-articles" :
     * affiché par GET /api/transactions pour relier une ligne à son reçu
     * (ex: "V-261026-0042"). Charger la relation `operation` avant de
     * sérialiser (voir TransactionController::index()/show()) pour éviter un
     * N+1 — cet accessor se contente de lire ce qui est déjà en mémoire.
     */
    protected $appends = [
        'operation_code',
    ];

    public function getOperationCodeAttribute(): ?string
    {
        return $this->operation?->code;
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        // Étape 1 du chantier "paniers multi-articles" : chaque transaction
        // créée par l'ANCIEN chemin (une transaction = une vente/dépense/
        // apport/retrait) reçoit automatiquement sa propre opération à une
        // seule ligne, avec son code — pour qu'il n'y ait jamais de
        // transaction orpheline pendant la transition. Un futur appelant
        // (étape 2/3, panier multi-lignes) qui fixe déjà `operation_id`
        // avant la création n'est jamais écrasé ici.
        static::creating(function (Transaction $transaction) {
            if ($transaction->operation_id) {
                return;
            }

            $occurredAt = $transaction->created_at ?? Carbon::now(OperationCodeGenerator::TIMEZONE);

            $operation = Operation::create([
                'user_id' => $transaction->user_id,
                'type' => $transaction->type,
                'code' => OperationCodeGenerator::next($transaction->user_id, $transaction->type, $occurredAt),
                'payment_method' => $transaction->payment_method,
                'total_amount' => $transaction->amount ?? 0,
                'invoice_id' => $transaction->invoice_id,
                'note' => $transaction->note,
                'occurred_at' => $occurredAt,
            ]);

            $transaction->operation_id = $operation->id;
        });

        // Événement après création d'une transaction
        static::created(function (Transaction $transaction) {
            $transaction->updateUserWallet();
        });

        // Événement après mise à jour d'une transaction
        static::updated(function (Transaction $transaction) {
            $transaction->updateUserWallet();
        });

        // Événement avant suppression d'une transaction
        static::deleting(function (Transaction $transaction) {
            $transaction->updateUserWallet();
        });

        // Événement après restauration (POST /api/transactions/{id}/restore) :
        // la transaction redevient visible aux SUM() de stock et au solde,
        // il faut donc recalculer au même titre qu'une création/suppression.
        static::restored(function (Transaction $transaction) {
            $transaction->updateUserWallet();
        });
    }

    /**
     * Update the user's wallet after transaction changes.
     */
    public function updateUserWallet(): void
    {
        if ($this->user_id) {
            $user = User::find($this->user_id);
            if ($user) {
                // Forcer le rechargement des attributs calculés
                $user->unsetRelation('transactions');
                $user->refresh();
            }
        }
    }

    /**
     * Get the user that owns the transaction.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the article that owns the transaction.
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'article_id');
    }

    /**
     * Get the variation that owns the transaction.
     */
    public function variation(): BelongsTo
    {
        return $this->belongsTo(Variation::class, 'variable_id');
    }

    /**
     * Get the invoice that generated this transaction, if any.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Get the collaborator that is the source of this transaction, if any
     * (apport de fonds avec funding_source = "collaborateur").
     */
    public function collaborator(): BelongsTo
    {
        return $this->belongsTo(Collaborator::class);
    }

    /**
     * L'opération (panier) à laquelle cette ligne appartient — voir
     * Operation::transactions() pour la relation inverse.
     */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    /**
     * Get the remaining quantity of the variation.
     */
    public function getRemainingVariationQuantityAttribute(): int
    {
        if (!$this->variable_id) {
            return 0;
        }

        $variation = $this->variation;
        if (!$variation) {
            return 0;
        }

        // Calculer la quantité déjà vendue pour cette variation
        $soldQuantity = self::where('variable_id', $this->variable_id)
            ->where('type', 'sale')
            ->sum('quantity');

        return $variation->quantity - $soldQuantity;
    }
}
