# 📋 PROMPT POUR IMPLÉMENTER LA RÉINITIALISATION DES RÉGLAGES ET DES DONNÉES

## 🚀 Copiez ce prompt dans Cursor :

```
Je veux implémenter les fonctionnalités de réinitialisation dans la page Settings :
1. Réinitialiser les réglages : remettre le `low_stock_threshold` à 80
2. Réinitialiser les données : supprimer toutes les transactions et tous les produits

## 🎯 OBJECTIFS

1. Créer des dialogs de confirmation pour les actions destructives
2. Implémenter la réinitialisation des réglages
3. Implémenter la réinitialisation des données
4. Gérer les états de chargement et les erreurs
5. Afficher des toasts de confirmation

## 🔧 IMPLÉMENTATION

### 1. Créer les dialogs de confirmation

Dans votre composant Settings, ajoutez les dialogs :

```typescript
'use client';

import { useState } from 'react';
import { useSettings } from '@/contexts/SettingsContext';
import { useAuth } from '@/contexts/AuthContext';
import { useToast } from '@/hooks/use-toast';
import { useTranslation } from '@/lib/i18n/hooks/useTranslation';
import { useRouter } from 'next/navigation';
import api from '@/lib/api';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { AlertCircle, RotateCcw, Trash2, Loader2 } from 'lucide-react';

export default function SettingsPage() {
  const { t } = useTranslation();
  const { settings, updateSettings, refreshSettings } = useSettings();
  const { logout } = useAuth();
  const { toast } = useToast();
  const router = useRouter();
  
  const [showResetSettingsDialog, setShowResetSettingsDialog] = useState(false);
  const [showResetDataDialog, setShowResetDataDialog] = useState(false);
  const [isResettingSettings, setIsResettingSettings] = useState(false);
  const [isResettingData, setIsResettingData] = useState(false);

  /**
   * Réinitialiser les réglages (remettre low_stock_threshold à 80)
   */
  const handleResetSettings = async () => {
    setIsResettingSettings(true);
    try {
      // Mettre à jour uniquement le low_stock_threshold à 80
      await updateSettings({
        low_stock_threshold: 80,
      });

      toast({
        title: 'Réglages réinitialisés',
        description: 'Le seuil de stock faible a été remis à 80%',
      });

      setShowResetSettingsDialog(false);
    } catch (error: any) {
      toast({
        title: 'Erreur',
        description: error.response?.data?.message || 'Impossible de réinitialiser les réglages',
        variant: 'destructive',
      });
    } finally {
      setIsResettingSettings(false);
    }
  };

  /**
   * Réinitialiser toutes les données (supprimer transactions et produits)
   */
  const handleResetData = async () => {
    setIsResettingData(true);
    try {
      toast({
        title: 'Suppression en cours...',
        description: 'Suppression des transactions et produits',
      });

      // Supprimer toutes les transactions
      const transactionsResponse = await api.delete('/api/transactions/all');
      
      // Supprimer tous les produits
      const articlesResponse = await api.delete('/api/articles/all');

      if (transactionsResponse.data.success && articlesResponse.data.success) {
        const transactionsCount = transactionsResponse.data.count || 0;
        const articlesCount = articlesResponse.data.count || 0;

        toast({
          title: 'Données réinitialisées',
          description: `${transactionsCount} transaction(s) et ${articlesCount} produit(s) supprimé(s)`,
        });

        setShowResetDataDialog(false);
        
        // Rafraîchir la page pour mettre à jour les données
        router.refresh();
      } else {
        throw new Error('Erreur lors de la suppression des données');
      }
    } catch (error: any) {
      toast({
        title: 'Erreur',
        description: error.response?.data?.message || 'Impossible de réinitialiser les données',
        variant: 'destructive',
      });
    } finally {
      setIsResettingData(false);
    }
  };

  return (
    <div className="container mx-auto py-6 space-y-6">
      {/* ... autres sections ... */}

      {/* Section Réinitialisation */}
      <Card>
        <CardHeader>
          <CardTitle>{t('settings.reset.title')}</CardTitle>
          <CardDescription>
            {t('settings.reset.description')}
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <Alert>
            <AlertCircle className="h-4 w-4" />
            <AlertTitle>{t('settings.reset.warning')}</AlertTitle>
            <AlertDescription>
              {t('settings.reset.warningDescription')}
            </AlertDescription>
          </Alert>

          <div className="space-y-2">
            <Label>{t('settings.reset.resetSettings')}</Label>
            <p className="text-sm text-muted-foreground">
              {t('settings.reset.resetSettingsDescription')}
            </p>
            <Button
              variant="outline"
              onClick={() => setShowResetSettingsDialog(true)}
            >
              <RotateCcw className="mr-2 h-4 w-4" />
              {t('settings.reset.resetSettings')}
            </Button>
          </div>

          <Separator />

          <div className="space-y-2">
            <Label className="text-destructive">
              {t('settings.reset.resetData')}
            </Label>
            <p className="text-sm text-muted-foreground">
              {t('settings.reset.resetDataDescription')}
            </p>
            <Button
              variant="destructive"
              onClick={() => setShowResetDataDialog(true)}
            >
              <Trash2 className="mr-2 h-4 w-4" />
              {t('settings.reset.resetData')}
            </Button>
          </div>
        </CardContent>
      </Card>

      {/* Dialog de confirmation pour réinitialiser les réglages */}
      <Dialog open={showResetSettingsDialog} onOpenChange={setShowResetSettingsDialog}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t('settings.reset.resetSettings')}</DialogTitle>
            <DialogDescription>
              Êtes-vous sûr de vouloir réinitialiser les réglages ? Le seuil de stock faible sera remis à 80%.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => setShowResetSettingsDialog(false)}
              disabled={isResettingSettings}
            >
              Annuler
            </Button>
            <Button
              variant="default"
              onClick={handleResetSettings}
              disabled={isResettingSettings}
            >
              {isResettingSettings ? (
                <>
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  Réinitialisation...
                </>
              ) : (
                <>
                  <RotateCcw className="mr-2 h-4 w-4" />
                  Confirmer
                </>
              )}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Dialog de confirmation pour réinitialiser les données */}
      <Dialog open={showResetDataDialog} onOpenChange={setShowResetDataDialog}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle className="text-destructive">
              {t('settings.reset.resetData')}
            </DialogTitle>
            <DialogDescription className="space-y-2">
              <p className="font-semibold text-destructive">
                ⚠️ ATTENTION : Cette action est irréversible !
              </p>
              <p>
                Vous êtes sur le point de supprimer :
              </p>
              <ul className="list-disc list-inside space-y-1 ml-4">
                <li>Toutes vos transactions (ventes et dépenses)</li>
                <li>Tous vos produits (articles simples et variables)</li>
                <li>Toutes les variations des articles variables</li>
              </ul>
              <p className="mt-2 font-semibold">
                Cette action ne peut pas être annulée. Êtes-vous absolument sûr ?
              </p>
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => setShowResetDataDialog(false)}
              disabled={isResettingData}
            >
              Annuler
            </Button>
            <Button
              variant="destructive"
              onClick={handleResetData}
              disabled={isResettingData}
            >
              {isResettingData ? (
                <>
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  Suppression...
                </>
              ) : (
                <>
                  <Trash2 className="mr-2 h-4 w-4" />
                  Supprimer définitivement
                </>
              )}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
```

### 2. Note sur les endpoints backend

Les endpoints `/api/transactions/all` et `/api/articles/all` ont été créés côté backend pour optimiser la suppression en masse. Si vous préférez une approche alternative (suppression une par une), voici le code :

```typescript
/**
 * Réinitialiser toutes les données (supprimer transactions et produits)
 */
const handleResetData = async () => {
  setIsResettingData(true);
  try {
    // Récupérer toutes les transactions
    const transactionsResponse = await api.get('/api/transactions');
    const transactions = transactionsResponse.data.data || [];

    // Récupérer tous les produits
    const articlesResponse = await api.get('/api/articles');
    const articles = articlesResponse.data.data || [];

    // Supprimer toutes les transactions une par une
    for (const transaction of transactions) {
      await api.delete(`/api/transactions/${transaction.id}`);
    }

    // Supprimer tous les produits une par une
    for (const article of articles) {
      await api.delete(`/api/articles/${article.id}`);
    }

    toast({
      title: 'Données réinitialisées',
      description: `${transactions.length} transaction(s) et ${articles.length} produit(s) supprimé(s)`,
    });

    setShowResetDataDialog(false);
    
    // Rafraîchir la page pour mettre à jour les données
    router.refresh();
  } catch (error: any) {
    toast({
      title: 'Erreur',
      description: error.response?.data?.message || 'Impossible de réinitialiser les données',
      variant: 'destructive',
    });
  } finally {
    setIsResettingData(false);
  }
};
```

### 3. Version optimisée avec suppression en parallèle (si beaucoup de données)

```typescript
/**
 * Réinitialiser toutes les données (version optimisée)
 */
const handleResetData = async () => {
  setIsResettingData(true);
  try {
    toast({
      title: 'Suppression en cours...',
      description: 'Récupération des données',
    });

    // Récupérer toutes les transactions
    const transactionsResponse = await api.get('/api/transactions');
    const transactions = transactionsResponse.data.data || [];

    // Récupérer tous les produits
    const articlesResponse = await api.get('/api/articles');
    const articles = articlesResponse.data.data || [];

    toast({
      title: 'Suppression en cours...',
      description: `Suppression de ${transactions.length} transaction(s) et ${articles.length} produit(s)`,
    });

    // Supprimer toutes les transactions en parallèle (par lots de 10)
    const transactionBatches = [];
    for (let i = 0; i < transactions.length; i += 10) {
      transactionBatches.push(
        Promise.all(
          transactions.slice(i, i + 10).map(transaction =>
            api.delete(`/api/transactions/${transaction.id}`)
          )
        )
      );
    }
    await Promise.all(transactionBatches);

    // Supprimer tous les produits en parallèle (par lots de 10)
    const articleBatches = [];
    for (let i = 0; i < articles.length; i += 10) {
      articleBatches.push(
        Promise.all(
          articles.slice(i, i + 10).map(article =>
            api.delete(`/api/articles/${article.id}`)
          )
        )
      );
    }
    await Promise.all(articleBatches);

    toast({
      title: 'Données réinitialisées',
      description: `${transactions.length} transaction(s) et ${articles.length} produit(s) supprimé(s)`,
    });

    setShowResetDataDialog(false);
    
    // Rafraîchir la page pour mettre à jour les données
    router.refresh();
  } catch (error: any) {
    toast({
      title: 'Erreur',
      description: error.response?.data?.message || 'Impossible de réinitialiser les données',
      variant: 'destructive',
    });
  } finally {
    setIsResettingData(false);
  }
};
```

## ✅ CHECKLIST D'IMPLÉMENTATION

- [ ] Créer les dialogs de confirmation pour les deux actions
- [ ] Implémenter `handleResetSettings` pour réinitialiser le seuil à 80
- [ ] Implémenter `handleResetData` pour supprimer toutes les données
- [ ] Ajouter les états de chargement pour chaque action
- [ ] Ajouter les toasts de succès et d'erreur
- [ ] Vérifier que les endpoints backend existent ou utiliser l'approche alternative
- [ ] Tester la réinitialisation des réglages
- [ ] Tester la réinitialisation des données

## 📝 NOTES IMPORTANTES

1. **Réinitialisation des réglages** : Seul le `low_stock_threshold` est remis à 80, la devise et la langue restent inchangées
2. **Réinitialisation des données** : Supprime toutes les transactions et tous les produits (irréversible)
3. **Confirmation** : Les dialogs de confirmation sont essentiels pour éviter les suppressions accidentelles
4. **Rafraîchissement** : Utiliser `router.refresh()` pour mettre à jour les données après suppression
5. **Performance** : Pour beaucoup de données, utiliser la suppression en parallèle par lots

## ✅ ENDPOINTS BACKEND CRÉÉS

Les endpoints backend suivants ont été créés et sont disponibles :

- `DELETE /api/transactions/all` - Supprime toutes les transactions de l'utilisateur
- `DELETE /api/articles/all` - Supprime tous les produits de l'utilisateur

Ces endpoints sont optimisés pour supprimer toutes les données en une seule requête, ce qui est beaucoup plus rapide que de supprimer les éléments un par un.

Implémentez ces fonctionnalités pour rendre les réinitialisations fonctionnelles.
```

---

## 📝 NOTES TECHNIQUES

1. **Réinitialisation des réglages** : Utilise `updateSettings({ low_stock_threshold: 80 })`
2. **Réinitialisation des données** : Supprime toutes les transactions puis tous les produits
3. **Dialogs** : Utilisez `Dialog` de shadcn/ui pour les confirmations
4. **Performance** : Pour beaucoup de données, supprimez en parallèle par lots
5. **Backend** : Les endpoints dédiés sont optionnels mais recommandés pour de meilleures performances

