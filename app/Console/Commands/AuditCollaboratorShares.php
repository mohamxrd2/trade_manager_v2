<?php

namespace App\Console\Commands;

use App\Models\Collaborator;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * READ-ONLY. Ne modifie rien. Pour chaque utilisateur ayant au moins un
 * collaborateur, compare users.company_share (stocké) à 100 − SUM(parts des
 * collaborateurs) (la vérité calculée) et signale l'écart le cas échéant.
 */
class AuditCollaboratorShares extends Command
{
    protected $signature = 'collaborators:audit';

    protected $description = 'Audit en lecture seule : company_share stocké vs 100 - somme des parts des collaborateurs';

    public function handle(): int
    {
        $this->info('=== Audit des parts collaborateurs (lecture seule) ===');
        $this->newLine();

        $userIds = Collaborator::select('user_id')->distinct()->pluck('user_id');
        $users = User::whereIn('id', $userIds)->get();

        $this->line("Utilisateurs ayant au moins un collaborateur : {$users->count()}");
        $this->newLine();

        if ($users->isEmpty()) {
            $this->info('Rien à auditer.');
            return self::SUCCESS;
        }

        $this->table(
            ['user_id', 'company_share (base)', '100 - SUM(parts)', 'écart'],
            $users->map(function (User $user) {
                $sumParts = (string) Collaborator::where('user_id', $user->id)->sum('part');
                $expected = bcsub('100', $sumParts, 2);
                $stored = (string) $user->company_share;
                $ecart = bcsub($stored, $expected, 2);

                return [$user->id, $stored, $expected, $ecart];
            })
        );

        $withGap = $users->filter(function (User $user) {
            $sumParts = (string) Collaborator::where('user_id', $user->id)->sum('part');
            $expected = bcsub('100', $sumParts, 2);
            return bccomp((string) $user->company_share, $expected, 2) !== 0;
        });

        $this->newLine();
        if ($withGap->isEmpty()) {
            $this->info('✅ Aucun écart : company_share est cohérent pour tous les utilisateurs audités.');
        } else {
            $this->warn("⚠️  {$withGap->count()} utilisateur(s) avec un écart — voir collaborators:resync (--dry-run par défaut).");
            foreach ($withGap as $user) {
                $this->line("    - {$user->id} ({$user->email})");
            }
        }

        return self::SUCCESS;
    }
}
