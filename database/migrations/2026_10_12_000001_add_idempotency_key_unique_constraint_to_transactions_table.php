<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Séparée de l'ajout de colonne (migration précédente, 2026_10_12_000000)
     * dans sa propre transaction Laravel : deux déploiements ont échoué sur
     * Neon avec SQLSTATE[25P02] portant précisément sur cette contrainte,
     * alors que la migration entière rejoue sans erreur sur PostgreSQL 18.6
     * identique en local. Isoler chaque ALTER TABLE dans sa propre
     * transaction (transaction Laravel toujours active, non désactivée)
     * évite qu'un incident transitoire sur l'une fasse échouer l'autre en
     * cascade, et rend visible dans migrate:status laquelle échoue
     * réellement si le problème se reproduit.
     *
     * Défensive (ajouté après un nouvel échec) : un déploiement a laissé
     * public.migrations avec 2026_10_12_000000 marquée Ran sur Neon alors
     * que la colonne qu'elle est censée avoir créée n'existe pas réellement
     * (constaté directement en lecture seule — cause exacte non établie).
     * Laravel ne réexécute jamais une migration déjà enregistrée comme Ran,
     * et public.migrations ne doit pas être modifiée à la main : cette
     * migration (encore Pending) vérifie donc l'état réel du schéma — pas
     * l'historique Laravel — avant d'agir, et répare la colonne manquante
     * si besoin avant de poser la contrainte. Idempotente dans les deux
     * sens : sans effet si la colonne et/ou la contrainte existent déjà
     * (nouvelle base saine, ou nouvelle tentative après un échec partiel),
     * agit uniquement sur ce qui manque réellement.
     *
     * TEMPORAIRE — instrumentation ciblée ajoutée après un troisième échec,
     * cette fois SQLSTATE[25P02] sur ADD COLUMN lui-même (la toute première
     * instruction DDL de cette migration), précédée uniquement de la lecture
     * Schema::hasColumn() — aucune autre requête n'intervient avant.
     * Chaque étape est exécutée via guarded(), qui journalise son résultat
     * (succès + durée, ou échec avec classe d'exception + SQLSTATE réel +
     * message expurgé) puis relance l'exception sans jamais l'absorber —
     * l'exécution de up() s'arrête donc immédiatement à la première étape
     * en échec, aucune étape suivante n'est tentée. Rien n'est journalisé
     * en dehors de ces quatre étapes explicites (pas de DB::listen() global)
     * pour ne jamais capturer de requête étrangère à cette migration, ni
     * bindings, ni données métier, ni secret. À retirer une fois la cause
     * confirmée.
     */
    public function up(): void
    {
        $attemptId = (string) Str::uuid();
        $tag = "[MIGRATION-DIAG {$attemptId}]";

        Log::info("{$tag} début de la migration");
        $this->logBackendPid($tag, 'au début');

        $hasColumn = $this->guarded($tag, 'Schema::hasColumn(idempotency_key)', function () {
            return Schema::hasColumn('transactions', 'idempotency_key');
        });

        if (!$hasColumn) {
            $this->guarded($tag, 'ADD COLUMN idempotency_key', function () {
                Schema::table('transactions', function (Blueprint $table) {
                    // Même définition que dans 2026_10_12_000000 : clé fournie par
                    // le client (UUID généré une fois par soumission) pour
                    // détecter les retries automatiques sans dépendre de la
                    // ressemblance des champs métier.
                    $table->string('idempotency_key')->nullable()->after('type');
                });
            });
        } else {
            Log::info("{$tag} colonne déjà présente, ADD COLUMN ignoré");
        }

        $this->logBackendPid($tag, "après l'étape colonne");

        $hasIndex = $this->guarded($tag, 'Schema::hasIndex(unique)', function () {
            return Schema::hasIndex('transactions', 'transactions_user_id_idempotency_key_unique', 'unique');
        });

        if (!$hasIndex) {
            $this->guarded($tag, 'ADD CONSTRAINT unique', function () {
                Schema::table('transactions', function (Blueprint $table) {
                    // Unique par utilisateur (et non globalement) : deux utilisateurs
                    // différents pourraient en théorie générer la même valeur côté
                    // client sans que ça pose problème. NULL est traité comme distinct
                    // de toute autre valeur par Postgres (et SQLite) pour une
                    // contrainte UNIQUE : un nombre arbitraire de lignes avec
                    // idempotency_key NULL est donc autorisé pour un même utilisateur.
                    $table->unique(['user_id', 'idempotency_key'], 'transactions_user_id_idempotency_key_unique');
                });
            });
        } else {
            Log::info("{$tag} contrainte déjà présente, ADD CONSTRAINT ignoré");
        }

        Log::info("{$tag} migration terminée avec succès");
    }

    /**
     * Exécute $operation et journalise son résultat :
     * - succès : une ligne info avec la durée ;
     * - échec : une ligne error avec la classe d'exception, le SQLSTATE réel
     *   (voir sqlState()) et un message expurgé, PUIS relance l'exception
     *   d'origine sans la modifier. Aucune exception n'est jamais absorbée
     *   ici — l'appelant (up()) n'a pas de try/catch autour de ces appels,
     *   donc toute exception interrompt immédiatement l'exécution de la
     *   migration, sans tenter les étapes suivantes.
     */
    private function guarded(string $tag, string $label, \Closure $operation): mixed
    {
        $start = microtime(true);

        try {
            $result = $operation();
            Log::info("{$tag} {$label} — OK en " . $this->elapsedMs($start) . 'ms');

            return $result;
        } catch (\Throwable $e) {
            Log::error("{$tag} {$label} — ÉCHEC après " . $this->elapsedMs($start) . 'ms — '
                . get_class($e) . ' / SQLSTATE=' . $this->sqlState($e) . ' / ' . $this->redact($e->getMessage()));

            throw $e;
        }
    }

    /**
     * Journalise le pid backend PostgreSQL courant (SELECT pg_backend_pid()),
     * sur la même connexion que les opérations ci-dessus. Sert uniquement à
     * détecter si le backend change entre deux étapes — un indice parmi
     * d'autres, pas une preuve à lui seul. Si cette lecture échoue, elle
     * journalise SA PROPRE erreur et la relance telle quelle — elle ne peut
     * pas masquer une exception antérieure : up() n'a aucun try/catch autour
     * de la séquence de ses appels, donc si une étape précédente avait déjà
     * échoué, l'exécution ne serait jamais arrivée jusqu'ici.
     */
    private function logBackendPid(string $tag, string $label): void
    {
        try {
            $pid = DB::selectOne('SELECT pg_backend_pid() as pid')->pid;
            Log::info("{$tag} pid backend {$label}: {$pid}");
        } catch (\Throwable $e) {
            Log::error("{$tag} échec de la lecture du pid {$label} — "
                . get_class($e) . ' / SQLSTATE=' . $this->sqlState($e) . ' / ' . $this->redact($e->getMessage()));

            throw $e;
        }
    }

    /**
     * Extrait le SQLSTATE réel d'une exception liée à PDO.
     *
     * $e->getCode() N'EST PAS FIABLE : vérifié empiriquement (local, PG18.6)
     * qu'une erreur de connexion (SQLSTATE 08006 réel) renvoie getCode() = 7
     * (un code interne au pilote), alors que errorInfo[0] renvoie bien
     * "08006". errorInfo est la propriété documentée par PHP lui-même comme
     * porteuse du SQLSTATE réel sur PDOException ; Illuminate\Database\
     * QueryException la copie explicitement depuis l'exception PDO
     * d'origine (vérifié dans son constructeur, vendor/laravel/framework).
     * On la préfère donc systématiquement à getCode(), avec repli sur
     * l'exception précédente si besoin, et sur getCode() uniquement pour une
     * exception totalement étrangère à PDO (ex: erreur applicative).
     */
    private function sqlState(\Throwable $e): string
    {
        if (isset($e->errorInfo[0]) && $e->errorInfo[0] !== null) {
            return (string) $e->errorInfo[0];
        }

        $previous = $e->getPrevious();
        if ($previous instanceof \Throwable && isset($previous->errorInfo[0]) && $previous->errorInfo[0] !== null) {
            return (string) $previous->errorInfo[0];
        }

        $code = $e->getCode();

        return ($code !== 0 && $code !== '') ? (string) $code : 'inconnu';
    }

    /**
     * Retire tout motif ressemblant à une chaîne de connexion
     * (schéma://utilisateur:motdepasse@hôte) et tronque le message. Défense
     * en profondeur : ces quatre opérations n'ont jamais de bindings
     * utilisateur (introspection de schéma + DDL statique sur des noms déjà
     * présents dans ce fichier), mais une exception de bas niveau (ex: échec
     * de connexion) pourrait en théorie en exposer une.
     */
    private function redact(string $message): string
    {
        $message = preg_replace('/\w+:\/\/[^\s@]+@[^\s)]+/', '[connexion expurgée]', $message) ?? $message;

        return Str::limit($message, 500, '… (tronqué)');
    }

    private function elapsedMs(float $start): string
    {
        return (string) round((microtime(true) - $start) * 1000, 2);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasIndex('transactions', 'transactions_user_id_idempotency_key_unique', 'unique')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->dropUnique('transactions_user_id_idempotency_key_unique');
            });
        }
    }
};
