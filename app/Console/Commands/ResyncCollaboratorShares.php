<?php

namespace App\Console\Commands;

use App\Models\Collaborator;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Remet users.company_share = 100 - SUM(parts des collaborateurs) pour
 * chaque utilisateur en écart (voir collaborators:audit).
 *
 * Par défaut --dry-run (rapport uniquement, rien n'est écrit). --force
 * applique réellement. Ne touche jamais aux parts des collaborateurs
 * eux-mêmes, seulement au cache company_share de l'utilisateur.
 */
class ResyncCollaboratorShares extends Command
{
    protected $signature = 'collaborators:resync {--force : Actually persist the changes (default is dry-run)}';

    protected $description = 'Remet company_share = 100 - SUM(parts) pour les utilisateurs en écart';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $this->info($force ? '=== Resync company_share (APPLICATION RÉELLE) ===' : '=== Resync company_share (--dry-run, rien ne sera écrit) ===');
        $this->newLine();

        $userIds = Collaborator::select('user_id')->distinct()->pluck('user_id');
        $users = User::whereIn('id', $userIds)->get();

        $fixed = 0;

        DB::transaction(function () use ($users, $force, &$fixed) {
            foreach ($users as $user) {
                $sumParts = (string) Collaborator::where('user_id', $user->id)->sum('part');
                $expected = bcsub('100', $sumParts, 2);
                $stored = (string) $user->company_share;

                if (bccomp($stored, $expected, 2) === 0) {
                    continue;
                }

                $this->line("- {$user->id} ({$user->email}) : company_share {$stored} → {$expected}");

                if ($force) {
                    $user->company_share = $expected;
                    $user->save();
                }

                $fixed++;
            }
        });

        $this->newLine();
        $this->line("Utilisateurs corrigés : {$fixed}");

        if (!$force) {
            $this->newLine();
            $this->warn('Dry-run : aucune donnée n\'a été modifiée. Relancez avec --force pour appliquer.');
        }

        return self::SUCCESS;
    }
}
