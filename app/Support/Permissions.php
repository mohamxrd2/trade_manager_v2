<?php

namespace App\Support;

use App\Models\MemberPermission;
use App\Models\User;

/**
 * Liste CENTRALISÉE des permissions + rôles par défaut — point de vérité
 * unique, voir Gate::before dans AppServiceProvider::boot() pour le
 * branchement sur `$user->can('xxx')`.
 *
 * "Associé" n'est PAS un rôle : avoir une part (table collaborators) est une
 * propriété indépendante du rôle d'accès. `partners.view` est la seule
 * permission liée aux associés — il n'existe VOLONTAIREMENT aucune
 * permission "partners.manage" : la gestion des parts (création/
 * modification d'un associé) reste réservée au propriétaire quel que soit
 * le rôle, y compris manager (voir `$user->isOwner()` dans
 * CollaboratorController plutôt qu'une permission).
 */
class Permissions
{
    public const SALES_CREATE = 'sales.create';
    public const SALES_VIEW_OWN = 'sales.view_own';
    public const SALES_VIEW_ALL = 'sales.view_all';
    public const SALES_DELETE_OWN_RECENT = 'sales.delete_own_recent';
    public const SALES_DELETE_ANY = 'sales.delete_any';
    public const EXPENSES_CREATE = 'expenses.create';
    public const EXPENSES_VIEW = 'expenses.view';
    public const DEPOSITS_MANAGE = 'deposits.manage';
    public const WALLET_VIEW_BALANCE = 'wallet.view_balance';
    public const ANALYTICS_VIEW = 'analytics.view';
    public const DASHBOARD_FULL = 'dashboard.full';
    public const STOCK_VIEW = 'stock.view';
    public const STOCK_EDIT = 'stock.edit';
    public const STOCK_RESTOCK = 'stock.restock';
    public const INVOICES_VIEW = 'invoices.view';
    public const INVOICES_MANAGE = 'invoices.manage';
    public const CLIENTS_VIEW = 'clients.view';
    public const CLIENTS_MANAGE = 'clients.manage';
    public const TEAM_MANAGE = 'team.manage';
    public const PARTNERS_VIEW = 'partners.view';
    public const SETTINGS_MANAGE = 'settings.manage';
    public const EXPORTS_DOWNLOAD = 'exports.download';

    public const ALL = [
        self::SALES_CREATE,
        self::SALES_VIEW_OWN,
        self::SALES_VIEW_ALL,
        self::SALES_DELETE_OWN_RECENT,
        self::SALES_DELETE_ANY,
        self::EXPENSES_CREATE,
        self::EXPENSES_VIEW,
        self::DEPOSITS_MANAGE,
        self::WALLET_VIEW_BALANCE,
        self::ANALYTICS_VIEW,
        self::DASHBOARD_FULL,
        self::STOCK_VIEW,
        self::STOCK_EDIT,
        self::STOCK_RESTOCK,
        self::INVOICES_VIEW,
        self::INVOICES_MANAGE,
        self::CLIENTS_VIEW,
        self::CLIENTS_MANAGE,
        self::TEAM_MANAGE,
        self::PARTNERS_VIEW,
        self::SETTINGS_MANAGE,
        self::EXPORTS_DOWNLOAD,
    ];

    public const ROLES = ['owner', 'manager', 'cashier', 'stock_manager', 'accountant'];

    /** Permissions par défaut d'un RÔLE (avant ajustements par membre) — le propriétaire n'est pas ici, il a TOUJOURS tout via Gate::before. */
    public static function defaultsForRole(?string $role): array
    {
        return match ($role) {
            'manager' => array_values(array_diff(self::ALL, [self::TEAM_MANAGE, self::SETTINGS_MANAGE])),
            // Un caissier note aussi les dépenses qu'il paie depuis la caisse
            // (retirable membre par membre via member_permissions).
            'cashier' => [self::SALES_CREATE, self::SALES_VIEW_OWN, self::STOCK_VIEW, self::CLIENTS_VIEW, self::EXPENSES_CREATE],
            'stock_manager' => [self::STOCK_VIEW, self::STOCK_EDIT, self::STOCK_RESTOCK],
            'accountant' => [
                self::WALLET_VIEW_BALANCE,
                self::ANALYTICS_VIEW,
                self::EXPENSES_VIEW,
                self::INVOICES_VIEW,
                self::SALES_VIEW_ALL,
                self::EXPORTS_DOWNLOAD,
            ],
            default => [],
        };
    }

    /**
     * Permissions EFFECTIVES d'un user = permissions du rôle, puis les
     * ajustements member_permissions appliqués par-dessus (granted=true
     * ajoute, granted=false retire). Le propriétaire a toujours ALL (jamais
     * d'ajustement à lui appliquer — il n'est jamais restreint).
     */
    public static function effectivePermissions(User $user): array
    {
        if ($user->isOwner()) {
            return self::ALL;
        }

        $permissions = self::defaultsForRole($user->role);

        $adjustments = MemberPermission::where('user_id', $user->id)->get(['permission', 'granted']);
        foreach ($adjustments as $adjustment) {
            if ($adjustment->granted) {
                if (!in_array($adjustment->permission, $permissions, true)) {
                    $permissions[] = $adjustment->permission;
                }
            } else {
                $permissions = array_values(array_diff($permissions, [$adjustment->permission]));
            }
        }

        return $permissions;
    }

    public static function userHas(User $user, string $permission): bool
    {
        if ($user->isOwner()) {
            return true;
        }

        return in_array($permission, self::effectivePermissions($user), true);
    }
}
