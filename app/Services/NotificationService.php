<?php

namespace App\Services;

use App\Models\Article;
use App\Models\Notification;

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

