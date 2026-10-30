<?php

namespace App\Console\Commands;

use App\Models\Collaborator;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * READ-ONLY audit. Does not write anything to the database.
 *
 * Bug critique (sécurité) : un membre (users.account_id non NULL) doit
 * TOUJOURS être rattaché au même compte que la fiche associé qui le lie
 * (collaborators.user_id_member -> cette fiche), c'est-à-dire
 * users.account_id === collaborators.user_id. Une divergence signifie
 * qu'un membre pourrait voir les données d'un AUTRE compte.
 *
 * Deux familles de membres sont auditées séparément :
 *   (a) membres liés à une fiche associé (collaborators.user_id_member) ;
 *   (b) membres SANS fiche associé (recrutés avec has_access=true,
 *       is_partner=false) : rien à comparer, juste listés pour information.
 */
class TeamAudit extends Command
{
    protected $signature = 'team:audit';

    protected $description = "Audit en lecture seule : cohérence users.account_id vs collaborators.user_id pour chaque membre";

    public function handle(): int
    {
        $this->info('=== Audit équipe (lecture seule) ===');
        $this->newLine();

        $members = User::whereNotNull('account_id')->orderBy('created_at')->get();
        $this->line("Membres (users.account_id non NULL) : {$members->count()}");
        $this->newLine();

        $mismatches = [];
        $linkedCount = 0;
        $unlinkedCount = 0;

        foreach ($members as $member) {
            $collaborator = Collaborator::where('user_id_member', $member->id)->first();

            if (!$collaborator) {
                $unlinkedCount++;
                continue;
            }

            $linkedCount++;

            if ($collaborator->user_id !== $member->account_id) {
                $mismatches[] = [
                    'member_id' => $member->id,
                    'member_email' => $member->email,
                    'member_account_id' => $member->account_id,
                    'collaborator_id' => $collaborator->id,
                    'collaborator_user_id' => $collaborator->user_id,
                ];
            }
        }

        $this->line("    - liés à une fiche associé : {$linkedCount}");
        $this->line("    - sans fiche associé (accès seul) : {$unlinkedCount}");
        $this->newLine();

        if (empty($mismatches)) {
            $this->info('Aucune incohérence : chaque membre lié a le même account_id que le user_id de sa fiche associé.');
            return self::SUCCESS;
        }

        $this->error('Incohérences détectées : ' . count($mismatches));
        foreach ($mismatches as $row) {
            $this->line(
                "    - membre={$row['member_id']} email=" . ($row['member_email'] ?? '(aucun)') .
                " account_id={$row['member_account_id']}" .
                " != collaborator={$row['collaborator_id']} user_id={$row['collaborator_user_id']}"
            );
        }
        $this->newLine();
        $this->warn("Pour corriger : php artisan team:repair --dry-run (puis --force après vérification).");

        return self::FAILURE;
    }
}
