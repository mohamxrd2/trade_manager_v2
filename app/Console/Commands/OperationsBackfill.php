<?php

namespace App\Console\Commands;

use App\Models\Operation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\OperationCodeGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Étape 1 du chantier "paniers multi-articles" : donne une Operation (en-tête)
 * à chaque Transaction historique qui n'en a pas encore, sans toucher à aucun
 * autre champ (amount/type/quantité... inchangés).
 *
 * Par transaction NON liée à une facture : une Operation à une seule ligne.
 * Par facture (plusieurs Transaction partageant le même invoice_id) : UNE
 * Operation groupant toutes ses lignes (occurred_at = la plus ancienne des
 * lignes, total_amount = somme des lignes).
 *
 * Les codes sont générés dans l'ordre chronologique des transactions
 * d'origine (par compte, par type) pour que les compteurs quotidiens restent
 * cohérents avec l'historique réel.
 *
 * Idempotent : ne traite que `operation_id IS NULL`, donc un second run
 * (--force ou --dry-run) sans nouvelle transaction orpheline ne fait rien.
 *
 * Default --dry-run : tout tourne dans une transaction DB réellement
 * exécutée (pour permettre les vérifications ci-dessous sur l'état après
 * écriture), puis rollback complet si --force n'est pas passé.
 */
class OperationsBackfill extends Command
{
    protected $signature = 'operations:backfill {--force : Actually persist the changes (default is dry-run)}';

    protected $description = 'Backfill: donne une Operation à chaque Transaction existante sans operation_id';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $this->info($force ? '=== Backfill operations (APPLICATION RÉELLE) ===' : '=== Backfill operations (--dry-run, rien ne sera écrit) ===');
        $this->newLine();

        if ($force && app()->environment('production')) {
            if (!$this->confirm('APP_ENV=production : confirmer l\'exécution réelle de operations:backfill --force ?')) {
                $this->warn('Annulé.');
                return self::FAILURE;
            }
        }

        // withTrashed() : une transaction annulée (soft delete) ne doit jamais
        // rester orpheline pour autant — elle reçoit aussi son opération.
        // Le chiffre d'affaires/dépenses, eux, restent scopés aux transactions
        // NON supprimées (c'est ainsi que l'app les calcule déjà ailleurs).
        $countBefore = Transaction::withTrashed()->count();
        $revenueBefore = (float) Transaction::where('type', 'sale')->sum('amount');
        $expenseBefore = (float) Transaction::where('type', 'expense')->sum('amount');
        $depositBefore = (float) Transaction::where('type', 'apport')->sum('amount');
        $withdrawalBefore = (float) Transaction::where('type', 'retrait')->sum('amount');

        $summary = [
            'operations_created_by_type' => ['sale' => 0, 'expense' => 0, 'apport' => 0, 'retrait' => 0],
            'single_line_operations' => 0,
            'invoice_grouped_operations' => 0,
            'invoice_groupings' => [],
            'example_codes' => [],
        ];

        try {
            DB::transaction(function () use (&$summary, $countBefore, $revenueBefore, $expenseBefore, $depositBefore, $withdrawalBefore) {
                User::whereHas('transactions', function ($q) {
                    $q->withTrashed()->whereNull('operation_id');
                })->chunkById(100, function ($users) use (&$summary) {
                    foreach ($users as $user) {
                        $this->processUser($user, $summary);
                    }
                });

                $this->runPreValidation($countBefore, $revenueBefore, $expenseBefore, $depositBefore, $withdrawalBefore);

                if (!$this->option('force')) {
                    throw new BackfillDryRunAbort();
                }
            });
        } catch (BackfillDryRunAbort) {
            // Attendu en dry-run — la transaction a été annulée volontairement.
        } catch (\Throwable $e) {
            $this->error('ÉCHEC — rollback complet effectué. Raison : ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('=== Résumé ===');
        foreach ($summary['operations_created_by_type'] as $type => $count) {
            $this->line("Operations créées ({$type}) : {$count}");
        }
        $this->line("Operations à une seule ligne : {$summary['single_line_operations']}");
        $this->line("Operations groupées depuis une facture multi-lignes : {$summary['invoice_grouped_operations']}");

        if (!empty($summary['invoice_groupings'])) {
            $this->newLine();
            $this->line('Regroupements par facture :');
            foreach ($summary['invoice_groupings'] as $row) {
                $this->line("    - invoice_id={$row['invoice_id']} : {$row['lines']} ligne(s) -> code {$row['code']}");
            }
        }

        if (!empty($summary['example_codes'])) {
            $this->newLine();
            $this->line('Exemples de codes générés :');
            foreach ($summary['example_codes'] as $code) {
                $this->line("    - {$code}");
            }
        }

        if (!$force) {
            $this->newLine();
            $this->warn('Dry-run : aucune donnée n\'a été modifiée. Relancez avec --force pour appliquer.');
        }

        return self::SUCCESS;
    }

    private function processUser(User $user, array &$summary): void
    {
        $transactions = Transaction::withTrashed()
            ->where('user_id', $user->id)
            ->whereNull('operation_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($transactions->isEmpty()) {
            return;
        }

        // Regroupe les lignes d'une même facture (même invoice_id) ensemble ;
        // chaque transaction sans facture reste seule dans son groupe. L'ordre
        // des groupes suit l'ordre chronologique de leur PREMIÈRE ligne, pour
        // générer les codes dans l'ordre réel d'origine.
        $groups = [];
        $invoiceGroupIndex = [];

        foreach ($transactions as $transaction) {
            if ($transaction->invoice_id) {
                $key = $transaction->invoice_id;
                if (!isset($invoiceGroupIndex[$key])) {
                    $invoiceGroupIndex[$key] = count($groups);
                    $groups[] = [];
                }
                $groups[$invoiceGroupIndex[$key]][] = $transaction;
            } else {
                $groups[] = [$transaction];
            }
        }

        foreach ($groups as $lines) {
            $this->createOperationForGroup($user, $lines, $summary);
        }
    }

    private function createOperationForGroup(User $user, array $lines, array &$summary): void
    {
        /** @var Transaction $first */
        $first = $lines[0];

        $occurredAt = collect($lines)->min('created_at') ?? $first->created_at ?? now();
        $totalAmount = collect($lines)->sum(fn (Transaction $t) => (float) $t->amount);
        $paymentMethod = collect($lines)->pluck('payment_method')->filter()->first();
        $note = collect($lines)->pluck('note')->filter()->first();

        $operation = Operation::create([
            'user_id' => $user->id,
            'type' => $first->type,
            'code' => OperationCodeGenerator::next($user->id, $first->type, $occurredAt),
            'payment_method' => $paymentMethod,
            'total_amount' => $totalAmount,
            'client_id' => $first->invoice?->client_id,
            'invoice_id' => $first->invoice_id,
            'note' => $note,
            'occurred_at' => $occurredAt,
        ]);

        Transaction::withTrashed()->whereIn('id', collect($lines)->pluck('id'))->update(['operation_id' => $operation->id]);

        $summary['operations_created_by_type'][$first->type]++;

        if (count($lines) > 1) {
            $summary['invoice_grouped_operations']++;
            $summary['invoice_groupings'][] = [
                'invoice_id' => $first->invoice_id,
                'lines' => count($lines),
                'code' => $operation->code,
            ];
        } else {
            $summary['single_line_operations']++;
        }

        if (count($summary['example_codes']) < 10) {
            $summary['example_codes'][] = $operation->code;
        }
    }

    /**
     * Vérifications automatiques avant de valider le run (rollback complet de
     * TOUT le backfill si l'une d'elles échoue) — voir le cahier des charges,
     * section 4 : aucune transaction perdue/créée, totaux par opération
     * cohérents, chiffre d'affaires/dépenses/solde identiques avant/après,
     * aucune transaction orpheline, codes uniques par compte.
     */
    private function runPreValidation(
        int $countBefore,
        float $revenueBefore,
        float $expenseBefore,
        float $depositBefore,
        float $withdrawalBefore
    ): void {
        $countAfter = Transaction::withTrashed()->count();
        if ($countAfter !== $countBefore) {
            throw new \RuntimeException("Invariant violé : nombre de transactions avant ({$countBefore}) != après ({$countAfter}).");
        }

        $orphans = Transaction::withTrashed()->whereNull('operation_id')->count();
        if ($orphans > 0) {
            throw new \RuntimeException("Invariant violé : {$orphans} transaction(s) encore sans operation_id après le backfill.");
        }

        $mismatched = Operation::query()
            ->select('operations.id', 'operations.total_amount')
            ->selectRaw('COALESCE(SUM(transactions.amount), 0) as lines_total')
            ->leftJoin('transactions', 'transactions.operation_id', '=', 'operations.id')
            ->groupBy('operations.id', 'operations.total_amount')
            ->havingRaw('operations.total_amount != COALESCE(SUM(transactions.amount), 0)')
            ->count();
        if ($mismatched > 0) {
            throw new \RuntimeException("Invariant violé : {$mismatched} opération(s) dont total_amount != somme des lignes.");
        }

        $revenueAfter = (float) Transaction::where('type', 'sale')->sum('amount');
        $expenseAfter = (float) Transaction::where('type', 'expense')->sum('amount');
        $depositAfter = (float) Transaction::where('type', 'apport')->sum('amount');
        $withdrawalAfter = (float) Transaction::where('type', 'retrait')->sum('amount');

        if (
            abs($revenueAfter - $revenueBefore) > 0.001 ||
            abs($expenseAfter - $expenseBefore) > 0.001 ||
            abs($depositAfter - $depositBefore) > 0.001 ||
            abs($withdrawalAfter - $withdrawalBefore) > 0.001
        ) {
            throw new \RuntimeException('Invariant violé : chiffre d\'affaires, dépenses, apports ou retraits différents avant/après.');
        }

        $duplicateCodes = DB::table('operations')
            ->select('user_id', 'code')
            ->groupBy('user_id', 'code')
            ->havingRaw('COUNT(*) > 1')
            ->count();
        if ($duplicateCodes > 0) {
            throw new \RuntimeException("Invariant violé : {$duplicateCodes} code(s) d'opération dupliqué(s) pour un même compte.");
        }
    }
}

class BackfillDryRunAbort extends \Exception
{
}
