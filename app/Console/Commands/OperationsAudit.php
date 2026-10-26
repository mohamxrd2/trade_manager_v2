<?php

namespace App\Console\Commands;

use App\Models\Operation;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * READ-ONLY audit. Does not write anything to the database.
 *
 * Signale : transactions sans opération, opérations dont le total ne
 * correspond pas à la somme de leurs lignes, codes dupliqués par compte.
 */
class OperationsAudit extends Command
{
    protected $signature = 'operations:audit';

    protected $description = 'Audit en lecture seule des opérations (transactions orphelines, totaux incohérents, codes dupliqués)';

    public function handle(): int
    {
        $this->info('=== Audit des opérations (lecture seule) ===');
        $this->newLine();

        $orphans = Transaction::withTrashed()->whereNull('operation_id')->get(['id', 'user_id', 'type', 'amount', 'created_at']);
        $this->line("(a) Transactions SANS operation_id : {$orphans->count()}");
        foreach ($orphans->take(20) as $tx) {
            $this->line("    - tx={$tx->id} user_id={$tx->user_id} type={$tx->type} amount={$tx->amount} created_at={$tx->created_at}");
        }
        if ($orphans->count() > 20) {
            $this->line('    ... (' . ($orphans->count() - 20) . ' de plus, non affichées)');
        }
        $this->newLine();

        $mismatched = Operation::query()
            ->select('operations.id', 'operations.user_id', 'operations.code', 'operations.total_amount')
            ->selectRaw('COALESCE(SUM(transactions.amount), 0) as lines_total')
            ->leftJoin('transactions', 'transactions.operation_id', '=', 'operations.id')
            ->groupBy('operations.id', 'operations.user_id', 'operations.code', 'operations.total_amount')
            ->havingRaw('operations.total_amount != COALESCE(SUM(transactions.amount), 0)')
            ->get();
        $this->line("(b) Opérations dont total_amount != somme des lignes : {$mismatched->count()}");
        foreach ($mismatched as $op) {
            $this->line("    - operation={$op->id} code={$op->code} user_id={$op->user_id} total_amount={$op->total_amount} somme_lignes={$op->lines_total}");
        }
        $this->newLine();

        $duplicateCodes = DB::table('operations')
            ->select('user_id', 'code')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('user_id', 'code')
            ->havingRaw('COUNT(*) > 1')
            ->get();
        $this->line("(c) Codes d'opération dupliqués pour un même compte : {$duplicateCodes->count()}");
        foreach ($duplicateCodes as $row) {
            $this->line("    - user_id={$row->user_id} code={$row->code} : {$row->total} occurrences");
        }
        $this->newLine();

        $this->info('=== Fin de l\'audit (lecture seule, aucune donnée modifiée) ===');

        $hasIssues = $orphans->isNotEmpty() || $mismatched->isNotEmpty() || $duplicateCodes->isNotEmpty();

        return $hasIssues ? self::FAILURE : self::SUCCESS;
    }
}
