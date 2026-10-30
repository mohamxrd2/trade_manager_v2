<?php

namespace App\Console\Commands;

use App\Models\Collaborator;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Corrige les incohérences listées par `team:audit` : remet
 * users.account_id = collaborators.user_id pour chaque membre dont la
 * fiche associé liée (user_id_member) pointe sur un AUTRE compte.
 *
 * Par défaut --dry-run (aucune écriture, même convention que
 * operations:backfill / team:backfill) : relancer avec --force pour
 * appliquer réellement. Ne JAMAIS lancer --force sans avoir d'abord
 * relu la sortie de team:audit et de --dry-run.
 */
class TeamRepair extends Command
{
    protected $signature = 'team:repair {--force : Actually persist the changes (default is dry-run)}';

    protected $description = "Corrige users.account_id pour qu'il égale collaborators.user_id de la fiche associé liée";

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $this->info($force ? '=== Réparation équipe (APPLICATION RÉELLE) ===' : '=== Réparation équipe (--dry-run, rien ne sera écrit) ===');
        $this->newLine();

        if ($force && app()->environment('production')) {
            if (!$this->confirm('APP_ENV=production : confirmer l\'exécution réelle de team:repair --force ?')) {
                $this->warn('Annulé.');
                return self::FAILURE;
            }
        }

        $fixed = 0;

        DB::transaction(function () use ($force, &$fixed) {
            $members = User::whereNotNull('account_id')->get();

            foreach ($members as $member) {
                $collaborator = Collaborator::where('user_id_member', $member->id)->first();

                if (!$collaborator || $collaborator->user_id === $member->account_id) {
                    continue;
                }

                $this->line(
                    "    - membre={$member->id} account_id={$member->account_id} -> {$collaborator->user_id} " .
                    "(collaborator={$collaborator->id})"
                );

                if ($force) {
                    $member->account_id = $collaborator->user_id;
                    $member->save();
                }

                $fixed++;
            }

            if (!$force) {
                // Toujours exécuté dans une transaction réelle (pour que les
                // comptages reflètent un état cohérent), annulée en
                // dry-run — même convention que operations:backfill.
                DB::rollBack();
            }
        });

        $this->newLine();
        if ($fixed === 0) {
            $this->info('Aucune incohérence trouvée, rien à corriger.');
            return self::SUCCESS;
        }

        $this->info("{$fixed} incohérence(s) " . ($force ? 'corrigée(s).' : 'détectée(s).'));
        if (!$force) {
            $this->warn('Dry-run : aucune donnée n\'a été modifiée. Relancez avec --force pour appliquer.');
        }

        return self::SUCCESS;
    }
}
