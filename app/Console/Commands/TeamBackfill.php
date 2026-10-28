<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Support\AccountCode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Chantier "Équipe", étape 1 — reprise des données existantes, additive et
 * idempotente (même convention que operations:backfill / BackfillVariations) :
 *
 *   (a) génère companies.code pour les entreprises qui n'en ont pas encore ;
 *   (b) remplit created_by_user_id = user_id (le propriétaire) pour les
 *       operations / stock_replenishments / invoices existantes qui n'ont
 *       pas encore d'auteur — tous les comptes existants n'ayant aucun
 *       membre avant ce chantier, le propriétaire est forcément l'auteur
 *       réel de ces lignes historiques.
 *
 * Par défaut --dry-run (aucune écriture) : relancer avec --force pour
 * appliquer réellement.
 */
class TeamBackfill extends Command
{
    protected $signature = 'team:backfill {--force : Actually persist the changes (default is dry-run)}';

    protected $description = "Reprise \"Équipe\" : companies.code + created_by_user_id (operations/stock_replenishments/invoices)";

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $this->info($force ? '=== Reprise Équipe (APPLICATION RÉELLE) ===' : '=== Reprise Équipe (--dry-run, rien ne sera écrit) ===');
        $this->newLine();

        if ($force && app()->environment('production')) {
            if (!$this->confirm('APP_ENV=production : confirmer l\'exécution réelle de team:backfill --force ?')) {
                $this->warn('Annulé.');
                return self::FAILURE;
            }
        }

        DB::transaction(function () use ($force) {
            $this->backfillCompanyCodes($force);
            $this->backfillCreatedBy('operations', $force);
            $this->backfillCreatedBy('stock_replenishments', $force);
            $this->backfillCreatedBy('invoices', $force);

            if (!$force) {
                // Toujours exécuté dans une transaction réelle (pour que les
                // comptages ci-dessus reflètent un état cohérent), annulée en
                // dry-run — même convention que operations:backfill.
                DB::rollBack();
            }
        });

        $this->newLine();
        $this->info($force ? '=== Reprise appliquée ===' : 'Dry-run : aucune donnée n\'a été modifiée. Relancez avec --force pour appliquer.');

        return self::SUCCESS;
    }

    private function backfillCompanyCodes(bool $force): void
    {
        $companies = Company::whereNull('code')->get();
        $this->line("(a) Entreprises sans code : {$companies->count()}");

        // Suivi en mémoire des codes déjà attribués DANS cette même passe —
        // en dry-run, rien n'est encore en base donc deux entreprises au nom
        // identique ("Blanka"/"Blanka") verraient sinon toutes les deux la
        // même proposition de code (pas de suffixe) : l'unicité contre la
        // base seule ne suffit pas tant que la première n'est pas persistée.
        $assignedInThisRun = [];

        foreach ($companies as $company) {
            $base = AccountCode::slug($company->name);
            if ($base === '') {
                $base = 'ENTREPRISE';
            }

            $code = AccountCode::withUniqueSuffix(
                $base,
                fn (string $candidate) => in_array($candidate, $assignedInThisRun, true)
                    || Company::where('code', $candidate)->where('id', '!=', $company->id)->exists()
            );
            $assignedInThisRun[] = $code;

            $this->line("    - company={$company->id} name=\"{$company->name}\" -> code={$code}");

            if ($force) {
                $company->update(['code' => $code]);
            }
        }
    }

    private function backfillCreatedBy(string $table, bool $force): void
    {
        $count = DB::table($table)->whereNull('created_by_user_id')->count();
        $this->line("(b) {$table} sans created_by_user_id : {$count} (seront mises à created_by_user_id = user_id, le propriétaire)");

        if ($force && $count > 0) {
            DB::table($table)->whereNull('created_by_user_id')->update([
                'created_by_user_id' => DB::raw('user_id'),
            ]);
        }
    }
}
