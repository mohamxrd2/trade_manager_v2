<?php

namespace App\Services;

use App\Models\Article;
use App\Models\Collaborator;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Variation;

class NotificationService
{
    /**
     * Vérifie si un article est passé en stock faible et crée une notification si nécessaire
     */
    public static function checkLowStock(Article $article): void
    {
        // Charger les relations nécessaires
        if (!$article->relationLoaded('user')) {
            $article->load('user.settings');
        }

        // Préférence « Me prévenir quand… un article passe en stock faible » (Paramètres > Notifications)
        if ($article->user?->settings && !$article->user->settings->notify_stock_low) {
            return;
        }

        // Calculer l'état actuel du stock faible
        $currentLowStock = $article->low_stock;

        // Vérifier s'il existe déjà une notification non lue pour cet article en stock faible
        $existingNotification = Notification::where('user_id', $article->user_id)
            ->where('article_id', $article->id)
            ->where('type', 'warning')
            ->where('read', false)
            ->where('title', 'like', '%Stock faible%')
            ->first();

        // Si l'article est maintenant en stock faible et qu'il n'y a pas déjà de notification
        if ($currentLowStock && !$existingNotification) {
            // Récupérer le seuil pour le message
            $lowStockThreshold = $article->user?->settings?->low_stock_threshold ?? 80;
            $remainingQuantity = $article->remaining_quantity;
            $salesPercentage = $article->sales_percentage;

            // Créer la notification
            Notification::create([
                'user_id' => $article->user_id,
                'type' => 'warning',
                'title' => 'Stock faible',
                'message' => "Le produit \"{$article->name}\" est en stock faible ({$remainingQuantity} unité(s) restante(s), {$salesPercentage}% vendu).",
                'read' => false,
                'article_id' => $article->id,
                'action_url' => "/products/{$article->id}",
            ]);
        }
    }

    /**
     * Vérifie si UNE VARIATION précise est passée en stock faible (son propre
     * seuil s'il est défini, sinon le seuil global de l'utilisateur) et crée
     * une notification dédiée si nécessaire. Le total de l'article reste
     * affiché ailleurs, mais n'est plus l'unique critère d'alerte pour un
     * article variable — chaque variation est surveillée indépendamment.
     */
    public static function checkLowStockVariation(Variation $variation): void
    {
        if (!$variation->relationLoaded('article')) {
            $variation->load('article.user.settings');
        }

        $article = $variation->article;

        if (!$article) {
            return;
        }

        if ($article->user?->settings && !$article->user->settings->notify_stock_low) {
            return;
        }

        $currentLowStock = $variation->low_stock;

        // Le nom de la variation dans le message permet de distinguer les
        // notifications déjà envoyées pour CETTE variation de celles d'une
        // autre variation du même article (qui doit pouvoir déclencher sa
        // propre alerte indépendamment).
        $existingNotification = Notification::where('user_id', $article->user_id)
            ->where('article_id', $article->id)
            ->where('type', 'warning')
            ->where('read', false)
            ->where('title', 'like', '%Stock faible%')
            ->where('message', 'like', '%' . $variation->name . '%')
            ->first();

        if ($currentLowStock && !$existingNotification) {
            $remainingQuantity = $variation->remaining_quantity;
            $salesPercentage = $variation->sales_percentage;

            Notification::create([
                'user_id' => $article->user_id,
                'type' => 'warning',
                'title' => 'Stock faible',
                'message' => "{$article->name} · {$variation->name} : {$remainingQuantity} restant(s) ({$salesPercentage}% vendu).",
                'read' => false,
                'article_id' => $article->id,
                'action_url' => "/products/{$article->id}",
            ]);
        }
    }

    /**
     * Notifie le propriétaire qu'une facture vient d'être marquée payée.
     * Respecte la préférence « Me prévenir quand… une facture est payée ».
     */
    public static function notifyInvoicePaid(Invoice $invoice): void
    {
        if (!$invoice->relationLoaded('user')) {
            $invoice->load('user.settings', 'client');
        } elseif (!$invoice->relationLoaded('client')) {
            $invoice->load('client');
        }

        if ($invoice->user?->settings && !$invoice->user->settings->notify_invoice_paid) {
            return;
        }

        $clientName = $invoice->client?->name ?? 'Le client';
        $amount = number_format((float) $invoice->total, 0, ',', ' ');

        Notification::create([
            'user_id' => $invoice->user_id,
            'type' => 'success',
            'title' => "Facture {$invoice->invoice_number} payée",
            'message' => "{$clientName} a réglé {$amount} FCFA.",
            'read' => false,
            'action_url' => '/invoices',
        ]);
    }

    /**
     * Notifie le propriétaire qu'une facture est passée en retard.
     * Respecte la préférence « Me prévenir quand… une facture est en retard ».
     */
    public static function notifyInvoiceOverdue(Invoice $invoice): void
    {
        if (!$invoice->relationLoaded('user')) {
            $invoice->load('user.settings', 'client');
        } elseif (!$invoice->relationLoaded('client')) {
            $invoice->load('client');
        }

        if ($invoice->user?->settings && !$invoice->user->settings->notify_invoice_overdue) {
            return;
        }

        $clientName = $invoice->client?->name ?? 'Le client';
        $amount = number_format((float) $invoice->total, 0, ',', ' ');

        Notification::create([
            'user_id' => $invoice->user_id,
            'type' => 'warning',
            'title' => "Facture {$invoice->invoice_number} en attente",
            'message' => "{$clientName} n'a pas encore réglé {$amount} FCFA.",
            'read' => false,
            'action_url' => '/invoices',
        ]);
    }

    /**
     * Notifie le propriétaire qu'un collaborateur vient de rejoindre l'équipe.
     * Respecte la préférence « Me prévenir quand… un collaborateur rejoint l'équipe ».
     */
    public static function notifyTeamJoined(Collaborator $collaborator): void
    {
        if (!$collaborator->relationLoaded('user')) {
            $collaborator->load('user.settings');
        }

        if ($collaborator->user?->settings && !$collaborator->user->settings->notify_team_joined) {
            return;
        }

        $firstName = explode(' ', trim($collaborator->name))[0] ?? $collaborator->name;

        Notification::create([
            'user_id' => $collaborator->user_id,
            'type' => 'info',
            'title' => "{$firstName} a rejoint l'équipe",
            'message' => 'Votre collaborateur peut maintenant enregistrer des ventes.',
            'read' => false,
            'action_url' => '/collaborators',
        ]);
    }

    /**
     * Créer une notification personnalisée
     */
    public static function createNotification(
        string $userId,
        string $type,
        string $title,
        string $message,
        ?string $articleId = null,
        ?string $actionUrl = null
    ): Notification {
        return Notification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'read' => false,
            'article_id' => $articleId,
            'action_url' => $actionUrl,
        ]);
    }
}

