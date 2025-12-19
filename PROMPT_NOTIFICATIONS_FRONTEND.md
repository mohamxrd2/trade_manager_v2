# 📋 PROMPT POUR IMPLÉMENTER LES NOTIFICATIONS AVEC PUSH

## 🚀 Copiez ce prompt dans Cursor :

```
Je veux connecter ma page Notifications au backend et implémenter les notifications push. La page existe déjà mais utilise des données simulées. Je veux :

1. Récupérer les notifications depuis l'API backend
2. Implémenter les notifications push du navigateur
3. Détecter automatiquement les nouvelles notifications (notamment pour le stock faible)
4. Marquer les notifications comme lues
5. Supprimer les notifications

## 🎯 OBJECTIFS

1. **Récupération des notifications** : Remplacer les données simulées par des appels API
2. **Notifications push** : Afficher des notifications push quand un article passe en stock faible
3. **Temps réel** : Vérifier périodiquement les nouvelles notifications
4. **Actions** : Marquer comme lue, supprimer, voir les détails

## 🔧 IMPLÉMENTATION

### 1. Créer un service pour les notifications

Créez `lib/services/notifications.ts` :

```typescript
import api from '@/lib/api';

export interface Notification {
  id: string;
  type: 'info' | 'success' | 'warning' | 'error';
  title: string;
  message: string;
  read: boolean;
  article_id?: string;
  action_url?: string;
  created_at: string;
  article?: {
    id: string;
    name: string;
    type: string;
  };
}

export interface NotificationsResponse {
  success: boolean;
  message: string;
  data: {
    notifications: Notification[];
    pagination: {
      current_page: number;
      per_page: number;
      total: number;
      last_page: number;
    };
    unread_count: number;
  };
}

/**
 * Récupère les notifications de l'utilisateur
 */
export async function getNotifications(
  page: number = 1,
  perPage: number = 20,
  unreadOnly: boolean = false
): Promise<NotificationsResponse> {
  const response = await api.get<NotificationsResponse>('/api/notifications', {
    params: {
      page,
      per_page: perPage,
      unread_only: unreadOnly,
    },
  });
  return response.data;
}

/**
 * Récupère le nombre de notifications non lues
 */
export async function getUnreadCount(): Promise<number> {
  try {
    const response = await api.get<{ success: boolean; data: { unread_count: number } }>(
      '/api/notifications/unread-count'
    );
    return response.data.data.unread_count;
  } catch (error) {
    console.error('Erreur lors de la récupération du nombre de notifications non lues:', error);
    return 0;
  }
}

/**
 * Marque une notification comme lue
 */
export async function markAsRead(notificationId: string): Promise<void> {
  await api.put(`/api/notifications/${notificationId}/read`);
}

/**
 * Marque toutes les notifications comme lues
 */
export async function markAllAsRead(): Promise<void> {
  await api.put('/api/notifications/read-all');
}

/**
 * Supprime une notification
 */
export async function deleteNotification(notificationId: string): Promise<void> {
  await api.delete(`/api/notifications/${notificationId}`);
}
```

### 2. Créer un hook pour gérer les notifications push

Créez `hooks/useNotifications.ts` :

```typescript
'use client';

import { useEffect, useState, useCallback } from 'react';
import { getUnreadCount, getNotifications, Notification } from '@/lib/services/notifications';
import { useToast } from '@/hooks/use-toast';

export function useNotifications() {
  const [unreadCount, setUnreadCount] = useState(0);
  const [notifications, setNotifications] = useState<Notification[]>([]);
  const [loading, setLoading] = useState(true);
  const { toast } = useToast();

  const refreshNotifications = useCallback(async () => {
    try {
      const response = await getNotifications(1, 20);
      setNotifications(response.data.notifications);
      setUnreadCount(response.data.unread_count);
    } catch (error) {
      console.error('Erreur lors de la récupération des notifications:', error);
    } finally {
      setLoading(false);
    }
  }, []);

  const refreshUnreadCount = useCallback(async () => {
    try {
      const count = await getUnreadCount();
      setUnreadCount(count);
    } catch (error) {
      console.error('Erreur lors de la récupération du nombre de notifications non lues:', error);
    }
  }, []);

  // Vérifier les nouvelles notifications toutes les 30 secondes
  useEffect(() => {
    refreshNotifications();
    refreshUnreadCount();

    const interval = setInterval(() => {
      refreshUnreadCount();
    }, 30000); // Vérifier toutes les 30 secondes

    return () => clearInterval(interval);
  }, [refreshNotifications, refreshUnreadCount]);

  return {
    notifications,
    unreadCount,
    loading,
    refreshNotifications,
    refreshUnreadCount,
  };
}
```

### 3. Créer un hook pour les notifications push du navigateur

Créez `hooks/usePushNotifications.ts` :

```typescript
'use client';

import { useEffect, useState, useCallback } from 'react';
import { getUnreadCount, Notification } from '@/lib/services/notifications';
import { useToast } from '@/hooks/use-toast';

export function usePushNotifications() {
  const [permission, setPermission] = useState<NotificationPermission>('default');
  const [previousUnreadCount, setPreviousUnreadCount] = useState(0);
  const { toast } = useToast();

  // Demander la permission pour les notifications push
  const requestPermission = useCallback(async () => {
    if (!('Notification' in window)) {
      console.warn('Ce navigateur ne supporte pas les notifications');
      return false;
    }

    if (Notification.permission === 'granted') {
      setPermission('granted');
      return true;
    }

    if (Notification.permission !== 'denied') {
      const result = await Notification.requestPermission();
      setPermission(result);
      return result === 'granted';
    }

    return false;
  }, []);

  // Afficher une notification push
  const showNotification = useCallback((notification: Notification) => {
    if (permission !== 'granted') {
      return;
    }

    const notificationOptions: NotificationOptions = {
      body: notification.message,
      icon: '/icon-192x192.png', // Remplacez par votre icône
      badge: '/badge-72x72.png', // Remplacez par votre badge
      tag: notification.id,
      requireInteraction: false,
      data: {
        url: notification.action_url || '/notifications',
        notificationId: notification.id,
      },
    };

    const browserNotification = new Notification(notification.title, notificationOptions);

    browserNotification.onclick = () => {
      window.focus();
      if (notification.action_url) {
        window.location.href = notification.action_url;
      }
      browserNotification.close();
    };

    // Fermer automatiquement après 5 secondes
    setTimeout(() => {
      browserNotification.close();
    }, 5000);
  }, [permission]);

  // Vérifier les nouvelles notifications et afficher des push
  const checkNewNotifications = useCallback(async () => {
    try {
      const currentUnreadCount = await getUnreadCount();

      // Si le nombre de notifications non lues a augmenté, récupérer les nouvelles
      if (currentUnreadCount > previousUnreadCount) {
        const { getNotifications } = await import('@/lib/services/notifications');
        const response = await getNotifications(1, 5, true); // Récupérer les 5 dernières non lues

        // Afficher une notification push pour chaque nouvelle notification
        const newNotifications = response.data.notifications.slice(
          0,
          currentUnreadCount - previousUnreadCount
        );

        newNotifications.forEach((notification) => {
          // Afficher une notification push uniquement pour les warnings (stock faible)
          if (notification.type === 'warning') {
            showNotification(notification);
          }
        });
      }

      setPreviousUnreadCount(currentUnreadCount);
    } catch (error) {
      console.error('Erreur lors de la vérification des nouvelles notifications:', error);
    }
  }, [previousUnreadCount, showNotification]);

  // Initialiser les notifications push
  useEffect(() => {
    if ('Notification' in window) {
      setPermission(Notification.permission);

      // Demander la permission au chargement si elle n'est pas déjà demandée
      if (Notification.permission === 'default') {
        // Optionnel : demander automatiquement ou via un bouton
        // requestPermission();
      }
    }
  }, []);

  // Vérifier les nouvelles notifications toutes les 30 secondes
  useEffect(() => {
    if (permission === 'granted') {
      checkNewNotifications();

      const interval = setInterval(() => {
        checkNewNotifications();
      }, 30000); // Vérifier toutes les 30 secondes

      return () => clearInterval(interval);
    }
  }, [permission, checkNewNotifications]);

  return {
    permission,
    requestPermission,
    showNotification,
  };
}
```

### 4. Modifier la page Notifications pour utiliser l'API

Dans `app/notifications/page.tsx` :

```typescript
'use client';

import { useState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { useNotifications } from '@/hooks/useNotifications';
import { usePushNotifications } from '@/hooks/usePushNotifications';
import { markAsRead, markAllAsRead, deleteNotification } from '@/lib/services/notifications';
import { useToast } from '@/hooks/use-toast';
import { useTranslation } from '@/lib/i18n/hooks/useTranslation';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Separator } from '@/components/ui/separator';
import {
  Bell,
  Check,
  Trash2,
  CheckCircle2,
  AlertTriangle,
  Info,
  XCircle,
  ArrowRight,
  MoreVertical,
  Sparkles,
  Loader2,
} from 'lucide-react';
import { formatDistanceToNow } from 'date-fns';
import { fr } from 'date-fns/locale';
import { cn } from '@/lib/utils';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { Notification } from '@/lib/services/notifications';

export default function NotificationsPage() {
  const { t } = useTranslation();
  const router = useRouter();
  const { toast } = useToast();
  const { notifications, unreadCount, loading, refreshNotifications } = useNotifications();
  const { permission, requestPermission } = usePushNotifications();

  const [isMarkingAsRead, setIsMarkingAsRead] = useState<string | null>(null);
  const [isDeleting, setIsDeleting] = useState<string | null>(null);
  const [isMarkingAllAsRead, setIsMarkingAllAsRead] = useState(false);

  const handleMarkAsRead = async (id: string) => {
    setIsMarkingAsRead(id);
    try {
      await markAsRead(id);
      await refreshNotifications();
      toast({
        title: 'Notification marquée comme lue',
      });
    } catch (error: any) {
      toast({
        title: 'Erreur',
        description: error.response?.data?.message || 'Impossible de marquer la notification comme lue',
        variant: 'destructive',
      });
    } finally {
      setIsMarkingAsRead(null);
    }
  };

  const handleMarkAllAsRead = async () => {
    setIsMarkingAllAsRead(true);
    try {
      await markAllAsRead();
      await refreshNotifications();
      toast({
        title: 'Toutes les notifications ont été marquées comme lues',
      });
    } catch (error: any) {
      toast({
        title: 'Erreur',
        description: error.response?.data?.message || 'Impossible de marquer toutes les notifications comme lues',
        variant: 'destructive',
      });
    } finally {
      setIsMarkingAllAsRead(false);
    }
  };

  const handleDelete = async (id: string) => {
    setIsDeleting(id);
    try {
      await deleteNotification(id);
      await refreshNotifications();
      toast({
        title: 'Notification supprimée',
      });
    } catch (error: any) {
      toast({
        title: 'Erreur',
        description: error.response?.data?.message || 'Impossible de supprimer la notification',
        variant: 'destructive',
      });
    } finally {
      setIsDeleting(null);
    }
  };

  const handleViewDetails = (notification: Notification) => {
    if (notification.action_url) {
      router.push(notification.action_url);
    }
  };

  const getTypeConfig = (type: Notification['type']) => {
    switch (type) {
      case 'success':
        return {
          icon: CheckCircle2,
          iconBg: 'bg-gradient-to-br from-green-50 to-emerald-50 dark:from-green-950/50 dark:to-emerald-950/50',
          iconColor: 'text-green-600 dark:text-green-400',
          borderColor: 'border-green-200/50 dark:border-green-800/50',
          glowColor: 'shadow-green-500/20',
          badge: (
            <Badge variant="outline" className="border-green-500/50 text-green-700 dark:text-green-400 bg-green-50/80 dark:bg-green-950/50 backdrop-blur-sm">
              {t('common.success')}
            </Badge>
          ),
        };
      case 'warning':
        return {
          icon: AlertTriangle,
          iconBg: 'bg-gradient-to-br from-orange-50 to-amber-50 dark:from-orange-950/50 dark:to-amber-950/50',
          iconColor: 'text-orange-600 dark:text-orange-400',
          borderColor: 'border-orange-200/50 dark:border-orange-800/50',
          glowColor: 'shadow-orange-500/20',
          badge: (
            <Badge variant="outline" className="border-orange-500/50 text-orange-700 dark:text-orange-400 bg-orange-50/80 dark:bg-orange-950/50 backdrop-blur-sm">
              {t('notifications.warning')}
            </Badge>
          ),
        };
      case 'error':
        return {
          icon: XCircle,
          iconBg: 'bg-gradient-to-br from-red-50 to-rose-50 dark:from-red-950/50 dark:to-rose-950/50',
          iconColor: 'text-red-600 dark:text-red-400',
          borderColor: 'border-red-200/50 dark:border-red-800/50',
          glowColor: 'shadow-red-500/20',
          badge: (
            <Badge variant="outline" className="border-red-500/50 text-red-700 dark:text-red-400 bg-red-50/80 dark:bg-red-950/50 backdrop-blur-sm">
              {t('common.error')}
            </Badge>
          ),
        };
      default:
        return {
          icon: Info,
          iconBg: 'bg-gradient-to-br from-blue-50 to-cyan-50 dark:from-blue-950/50 dark:to-cyan-950/50',
          iconColor: 'text-blue-600 dark:text-blue-400',
          borderColor: 'border-blue-200/50 dark:border-blue-800/50',
          glowColor: 'shadow-blue-500/20',
          badge: (
            <Badge variant="outline" className="border-blue-500/50 text-blue-700 dark:text-blue-400 bg-blue-50/80 dark:bg-blue-950/50 backdrop-blur-sm">
              {t('notifications.info')}
            </Badge>
          ),
        };
    }
  };

  if (loading) {
    return (
      <div className="container mx-auto px-4 lg:px-6 py-6 space-y-4">
        <div className="flex items-center justify-between mb-6">
          <div>
            <Skeleton className="h-8 w-48 mb-2" />
            <Skeleton className="h-4 w-64" />
          </div>
        </div>
        <div className="space-y-3">
          {[1, 2, 3, 4].map((i) => (
            <Card key={i} className="rounded-xl">
              <CardContent className="p-5">
                <div className="flex items-start gap-4">
                  <Skeleton className="h-12 w-12 rounded-full" />
                  <div className="flex-1 space-y-2">
                    <Skeleton className="h-4 w-3/4" />
                    <Skeleton className="h-3 w-full" />
                    <Skeleton className="h-3 w-1/2" />
                  </div>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gradient-to-br from-background via-background to-muted/20">
      <div className="container mx-auto px-4 lg:px-6 py-8 space-y-8">
        {/* En-tête moderne */}
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-6">
          <div className="space-y-2">
            <div className="flex items-center gap-4">
              <div className="relative">
                <div className="absolute inset-0 bg-primary/20 blur-xl rounded-full" />
                <div className="relative p-3 rounded-2xl bg-gradient-to-br from-primary/10 to-primary/5 border border-primary/20 backdrop-blur-sm">
                  <Bell className="h-6 w-6 text-primary" />
                </div>
              </div>
              <div className="flex items-center gap-3">
                <h1 className="text-4xl font-bold bg-gradient-to-r from-foreground to-foreground/70 bg-clip-text text-transparent">
                  {t('nav.notifications')}
                </h1>
                {unreadCount > 0 && (
                  <Badge
                    variant="default"
                    className="ml-2 px-3 py-1 text-sm font-semibold bg-primary text-primary-foreground shadow-lg shadow-primary/20 animate-pulse"
                  >
                    {unreadCount}
                  </Badge>
                )}
              </div>
            </div>
            <div className="flex items-center gap-2 ml-16">
              {unreadCount > 0 ? (
                <>
                  <Sparkles className="h-4 w-4 text-primary animate-pulse" />
                  <p className="text-muted-foreground">
                    {unreadCount} notification{unreadCount > 1 ? 's' : ''} non lue{unreadCount > 1 ? 's' : ''}
                  </p>
                </>
              ) : (
                <p className="text-muted-foreground flex items-center gap-2">
                  <CheckCircle2 className="h-4 w-4 text-green-500" />
                  {t('notifications.allRead')}
                </p>
              )}
            </div>
          </div>
          <div className="flex gap-2">
            {permission !== 'granted' && (
              <Button
                variant="outline"
                onClick={requestPermission}
                className="border-2 hover:bg-primary hover:text-primary-foreground transition-all duration-200 shadow-sm hover:shadow-md"
              >
                <Bell className="h-4 w-4 mr-2" />
                Activer les notifications push
              </Button>
            )}
            {unreadCount > 0 && (
              <Button
                variant="outline"
                onClick={handleMarkAllAsRead}
                disabled={isMarkingAllAsRead}
                className="border-2 hover:bg-primary hover:text-primary-foreground transition-all duration-200 shadow-sm hover:shadow-md"
              >
                {isMarkingAllAsRead ? (
                  <>
                    <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                    En cours...
                  </>
                ) : (
                  <>
                    <Check className="h-4 w-4 mr-2" />
                    {t('notifications.markAllAsRead')}
                  </>
                )}
              </Button>
            )}
          </div>
        </div>

        {/* Liste des notifications */}
        {notifications.length === 0 ? (
          <Card className="rounded-2xl border-2 border-dashed bg-gradient-to-br from-muted/50 to-muted/30 backdrop-blur-sm">
            <CardContent className="p-16 text-center">
              <div className="relative inline-block mb-6">
                <div className="absolute inset-0 bg-primary/10 blur-2xl rounded-full" />
                <div className="relative p-6 rounded-full bg-muted/80 backdrop-blur-sm border-2 border-border">
                  <Bell className="h-12 w-12 text-muted-foreground" />
                </div>
              </div>
              <h3 className="text-xl font-bold mb-2">{t('notifications.noNotifications')}</h3>
              <p className="text-muted-foreground max-w-md mx-auto">
                {t('notifications.noNotificationsDescription')}
              </p>
            </CardContent>
          </Card>
        ) : (
          <div className="space-y-4">
            {notifications.map((notification, index) => {
              const typeConfig = getTypeConfig(notification.type);
              const Icon = typeConfig.icon;

              return (
                <Card
                  key={notification.id}
                  className={cn(
                    'group rounded-2xl transition-all duration-300 border-2 backdrop-blur-sm',
                    'hover:shadow-xl hover:scale-[1.01]',
                    !notification.read
                      ? cn(
                          'border-l-4 border-l-primary bg-gradient-to-r from-primary/5 via-primary/5 to-transparent dark:from-primary/10 dark:via-primary/10',
                          'shadow-lg shadow-primary/10',
                          typeConfig.glowColor
                        )
                      : 'border-border/50 bg-card/50 hover:bg-card',
                    typeConfig.borderColor
                  )}
                  style={{
                    animationDelay: `${index * 50}ms`,
                  }}
                >
                  <CardContent className="p-6">
                    <div className="flex items-start gap-5">
                      {/* Icône de type avec effet moderne */}
                      <div
                        className={cn(
                          'relative flex-shrink-0',
                          'transition-transform duration-300 group-hover:scale-110'
                        )}
                      >
                        <div
                          className={cn(
                            'absolute inset-0 rounded-2xl blur-md opacity-50',
                            typeConfig.iconBg
                          )}
                        />
                        <div
                          className={cn(
                            'relative p-4 rounded-2xl border-2 border-border/50 backdrop-blur-sm',
                            typeConfig.iconBg,
                            'group-hover:border-primary/50 transition-colors'
                          )}
                        >
                          <Icon className={cn('h-6 w-6', typeConfig.iconColor)} />
                        </div>
                        {!notification.read && (
                          <div className="absolute -top-1 -right-1 h-3 w-3 bg-primary rounded-full border-2 border-background animate-pulse" />
                        )}
                      </div>

                      {/* Contenu */}
                      <div className="flex-1 min-w-0 space-y-3">
                        <div className="flex items-start justify-between gap-4">
                          <div className="flex-1 min-w-0 space-y-2">
                            <div className="flex items-center gap-3 flex-wrap">
                              <h3
                                className={cn(
                                  'font-bold text-lg leading-tight',
                                  !notification.read ? 'text-foreground' : 'text-muted-foreground'
                                )}
                              >
                                {notification.title}
                              </h3>
                              {typeConfig.badge}
                            </div>
                            <p
                              className={cn(
                                'text-sm leading-relaxed',
                                !notification.read
                                  ? 'text-foreground/80'
                                  : 'text-muted-foreground'
                              )}
                            >
                              {notification.message}
                            </p>
                          </div>

                          {/* Menu d'actions */}
                          <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                              <Button
                                variant="ghost"
                                size="icon"
                                className="h-9 w-9 flex-shrink-0 rounded-lg hover:bg-muted/80 transition-colors"
                              >
                                <MoreVertical className="h-4 w-4" />
                              </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="rounded-xl">
                              {!notification.read && (
                                <DropdownMenuItem
                                  onClick={() => handleMarkAsRead(notification.id)}
                                  disabled={isMarkingAsRead === notification.id}
                                  className="rounded-lg"
                                >
                                  {isMarkingAsRead === notification.id ? (
                                    <>
                                      <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                                      En cours...
                                    </>
                                  ) : (
                                    <>
                                      <Check className="h-4 w-4 mr-2" />
                                      {t('notifications.markAsRead')}
                                    </>
                                  )}
                                </DropdownMenuItem>
                              )}
                              {notification.action_url && (
                                <DropdownMenuItem
                                  onClick={() => handleViewDetails(notification)}
                                  className="rounded-lg"
                                >
                                  <ArrowRight className="h-4 w-4 mr-2" />
                                  {t('notifications.viewDetails')}
                                </DropdownMenuItem>
                              )}
                              <DropdownMenuItem
                                onClick={() => handleDelete(notification.id)}
                                disabled={isDeleting === notification.id}
                                className="text-destructive focus:text-destructive rounded-lg"
                              >
                                {isDeleting === notification.id ? (
                                  <>
                                    <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                                    Suppression...
                                  </>
                                ) : (
                                  <>
                                    <Trash2 className="h-4 w-4 mr-2" />
                                    {t('common.delete')}
                                  </>
                                )}
                              </DropdownMenuItem>
                            </DropdownMenuContent>
                          </DropdownMenu>
                        </div>

                        {/* Date et actions rapides */}
                        <Separator className="opacity-50" />
                        <div className="flex items-center justify-between">
                          <div className="flex items-center gap-2">
                            <span className="text-xs text-muted-foreground font-medium">
                              {formatDistanceToNow(new Date(notification.created_at), {
                                addSuffix: true,
                                locale: fr,
                              })}
                            </span>
                          </div>
                          {notification.action_url && (
                            <Button
                              variant="ghost"
                              size="sm"
                              onClick={() => handleViewDetails(notification)}
                              className="h-8 text-xs rounded-lg hover:bg-primary/10 hover:text-primary transition-colors"
                            >
                              {t('notifications.view')}
                              <ArrowRight className="h-3 w-3 ml-2" />
                            </Button>
                          )}
                        </div>
                      </div>
                    </div>
                  </CardContent>
                </Card>
              );
            })}
          </div>
        )}
      </div>
    </div>
  );
}
```

### 5. Ajouter les traductions manquantes

Dans `lib/i18n/translations/fr.json` et `en.json`, ajoutez :

```json
{
  "notifications": {
    "warning": "Avertissement",
    "info": "Information",
    "unreadCount": "{{count}} notification{{plural}} non lue{{plural}}",
    "allRead": "Toutes les notifications sont lues",
    "markAllAsRead": "Tout marquer comme lu",
    "markAsRead": "Marquer comme lu",
    "viewDetails": "Voir les détails",
    "view": "Voir",
    "noNotifications": "Aucune notification",
    "noNotificationsDescription": "Vous n'avez pas encore de notifications. Elles apparaîtront ici lorsqu'un article passe en stock faible ou lors d'autres événements importants."
  }
}
```

### 6. Intégrer les notifications push dans le layout principal (optionnel)

Dans `app/layout.tsx` ou un composant global :

```typescript
'use client';

import { usePushNotifications } from '@/hooks/usePushNotifications';
import { useEffect } from 'react';

export function PushNotificationsProvider({ children }: { children: React.ReactNode }) {
  const { requestPermission } = usePushNotifications();

  // Optionnel : demander la permission automatiquement au chargement
  useEffect(() => {
    // Vous pouvez aussi demander la permission via un bouton dans Settings
    // requestPermission();
  }, [requestPermission]);

  return <>{children}</>;
}
```

## ✅ CHECKLIST D'IMPLÉMENTATION

- [ ] Créer le service `lib/services/notifications.ts`
- [ ] Créer le hook `hooks/useNotifications.ts`
- [ ] Créer le hook `hooks/usePushNotifications.ts`
- [ ] Modifier la page Notifications pour utiliser l'API
- [ ] Ajouter les traductions manquantes
- [ ] Tester la récupération des notifications
- [ ] Tester les notifications push
- [ ] Tester le marquage comme lu
- [ ] Tester la suppression
- [ ] Tester la détection automatique des nouvelles notifications

## 📝 NOTES IMPORTANTES

1. **Notifications push** : Les notifications push ne fonctionnent que si l'utilisateur a accordé la permission
2. **Vérification périodique** : Les nouvelles notifications sont vérifiées toutes les 30 secondes
3. **Stock faible** : Les notifications de stock faible sont créées automatiquement côté backend quand un article passe en stock faible
4. **Permissions** : Demander la permission pour les notifications push via un bouton dans Settings ou automatiquement
5. **Icônes** : Remplacez les chemins d'icônes par vos propres icônes (PWA)

Implémentez ces fonctionnalités pour connecter la page Notifications au backend et activer les notifications push.
```

---

## 📝 NOTES TECHNIQUES

1. **Backend** : Les notifications de stock faible sont créées automatiquement dans `TransactionController` après chaque vente
2. **Vérification** : Le hook `usePushNotifications` vérifie les nouvelles notifications toutes les 30 secondes
3. **Permissions** : Les notifications push nécessitent la permission de l'utilisateur
4. **PWA** : Pour les notifications push en arrière-plan, vous devrez configurer un Service Worker

