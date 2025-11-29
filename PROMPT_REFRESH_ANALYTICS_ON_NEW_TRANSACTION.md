# 📋 PROMPT POUR ACTUALISER AUTOMATIQUEMENT LES ANALYTICS APRÈS UNE NOUVELLE VENTE

## 🚀 Copiez ce prompt dans Cursor :

```
Quand j'ajoute une nouvelle vente, les données Analytics de la journée ne se mettent pas à jour automatiquement. Je dois actualiser la page manuellement. Je veux que les données se rafraîchissent automatiquement après chaque ajout de transaction.

## 🔍 PROBLÈME IDENTIFIÉ

**Symptôme** :
- Après avoir ajouté une nouvelle vente ou dépense
- Les statistiques Analytics (revenu net, ventes, dépenses) ne se mettent pas à jour
- Je dois actualiser la page manuellement pour voir les nouvelles données

**Cause** :
- L'événement de rafraîchissement n'est pas émis après l'ajout d'une transaction
- La page Analytics n'écoute pas les événements de rafraîchissement
- Les données ne sont pas rechargées automatiquement

## 🔧 SOLUTION COMPLÈTE

### Étape 1 : Créer un hook pour rafraîchir les Analytics

Créer un fichier `hooks/useAnalyticsRefresh.ts` :

```typescript
'use client';

import { useCallback } from 'react';

/**
 * Hook pour rafraîchir les données Analytics
 * Émet un événement personnalisé que la page Analytics écoute
 */
export function useAnalyticsRefresh() {
  const refreshAnalytics = useCallback(() => {
    // Émettre un événement personnalisé pour déclencher le rafraîchissement
    window.dispatchEvent(new CustomEvent('analytics:refresh'));
  }, []);

  return { refreshAnalytics };
}
```

### Étape 2 : Utiliser le hook dans AddTransactionDialog

Dans votre composant `AddTransactionDialog` (ou similaire), ajouter le rafraîchissement après succès :

```typescript
'use client';

import { useAnalyticsRefresh } from '@/hooks/useAnalyticsRefresh';
import { toast } from '@/hooks/use-toast';

export function AddTransactionDialog({ open, onOpenChange, onSuccess }) {
  const { refreshAnalytics } = useAnalyticsRefresh();
  const [loading, setLoading] = useState(false);

  const handleSubmit = async (data: TransactionFormData) => {
    setLoading(true);
    try {
      const response = await api.post('/api/transactions', data);
      
      toast({
        title: "Succès",
        description: "Transaction ajoutée avec succès",
      });

      // IMPORTANT : Rafraîchir les Analytics immédiatement
      refreshAnalytics();
      
      // Émettre aussi un événement spécifique pour les transactions
      window.dispatchEvent(new CustomEvent('transaction:added', {
        detail: { transaction: response.data.data }
      }));

      // Callback parent si nécessaire
      onSuccess?.(response.data.data);
      
      // Fermer le dialog
      onOpenChange(false);
    } catch (error) {
      toast({
        title: "Erreur",
        description: "Erreur lors de l'ajout de la transaction",
        variant: "destructive",
      });
    } finally {
      setLoading(false);
    }
  };

  // ... reste du composant
}
```

### Étape 3 : Utiliser dans EditTransactionDialog

Dans votre composant `EditTransactionDialog` :

```typescript
import { useAnalyticsRefresh } from '@/hooks/useAnalyticsRefresh';

export function EditTransactionDialog({ transaction, open, onOpenChange }) {
  const { refreshAnalytics } = useAnalyticsRefresh();

  const handleUpdate = async (data: TransactionFormData) => {
    try {
      await api.put(`/api/transactions/${transaction.id}`, data);
      
      toast({
        title: "Succès",
        description: "Transaction modifiée avec succès",
      });

      // Rafraîchir les Analytics
      refreshAnalytics();

      onOpenChange(false);
    } catch (error) {
      // Gestion d'erreur
    }
  };

  // ... reste du composant
}
```

### Étape 4 : Utiliser dans DeleteTransactionDialog

Dans votre composant `DeleteTransactionDialog` :

```typescript
import { useAnalyticsRefresh } from '@/hooks/useAnalyticsRefresh';

export function DeleteTransactionDialog({ transaction, open, onOpenChange }) {
  const { refreshAnalytics } = useAnalyticsRefresh();

  const handleDelete = async () => {
    try {
      await api.delete(`/api/transactions/${transaction.id}`);
      
      toast({
        title: "Succès",
        description: "Transaction supprimée avec succès",
      });

      // Rafraîchir les Analytics
      refreshAnalytics();

      onOpenChange(false);
    } catch (error) {
      // Gestion d'erreur
    }
  };

  // ... reste du composant
}
```

### Étape 5 : Écouter l'événement dans la page Analytics

Dans votre page Analytics (`app/analytics/page.tsx` ou similaire), écouter l'événement :

```typescript
'use client';

import { useEffect, useState, useCallback } from 'react';

export default function AnalyticsPage() {
  const [period, setPeriod] = useState<Period>('today');
  const [overview, setOverview] = useState(null);
  const [trends, setTrends] = useState(null);
  // ... autres états

  // Fonction pour charger toutes les données
  const fetchAllData = useCallback(async () => {
    setLoading(true);
    try {
      const params = {
        period,
        ...(period === 'custom' && startDate && endDate ? {
          start_date: dayjs(startDate).format('YYYY-MM-DD'),
          end_date: dayjs(endDate).format('YYYY-MM-DD')
        } : {})
      };

      const [overviewRes, trendsRes, categoryRes, comparisonsRes, kpisRes, transactionsRes, predictionsRes] = await Promise.all([
        api.get('/api/analytics/overview', { params }),
        api.get('/api/analytics/trends', { params: { ...params, type: 'both' } }),
        api.get('/api/analytics/category-analysis', { params }),
        period !== 'all' ? api.get('/api/analytics/comparisons', { params }) : Promise.resolve({ data: { data: null } }),
        api.get('/api/analytics/kpis', { params }),
        api.get('/api/analytics/transactions', { params: { ...params, page: 1 } }),
        period !== 'all' ? api.get('/api/analytics/predictions') : Promise.resolve({ data: { data: [] } }),
      ]);

      setOverview(overviewRes.data.data);
      setTrends(trendsRes.data.data);
      setCategoryAnalysis(categoryRes.data.data);
      if (comparisonsRes.data.data) setComparisons(comparisonsRes.data.data);
      setKpis(kpisRes.data.data);
      setTransactions(transactionsRes.data.data.transactions);
      if (predictionsRes.data.data) setPredictions(predictionsRes.data.data);
    } catch (error) {
      toast.error('Erreur lors du chargement des statistiques');
    } finally {
      setLoading(false);
    }
  }, [period, startDate, endDate]);

  // Charger les données au montage et quand la période change
  useEffect(() => {
    fetchAllData();
  }, [fetchAllData]);

  // IMPORTANT : Écouter l'événement de rafraîchissement
  useEffect(() => {
    const handleRefresh = () => {
      console.log('🔄 Rafraîchissement des Analytics demandé');
      // Recharger les données immédiatement, surtout si on est sur "Aujourd'hui"
      fetchAllData();
    };

    // Écouter l'événement personnalisé
    window.addEventListener('analytics:refresh', handleRefresh);

    // Nettoyer l'écouteur au démontage
    return () => {
      window.removeEventListener('analytics:refresh', handleRefresh);
    };
  }, [fetchAllData]);

  // BONUS : Recharger automatiquement si on est sur "Aujourd'hui" et qu'une transaction est ajoutée
  // Cela garantit que les données de la journée sont toujours à jour
  useEffect(() => {
    if (period === 'today') {
      // Écouter aussi les événements de transaction pour recharger immédiatement
      const handleTransactionAdded = () => {
        console.log('📊 Transaction ajoutée, rechargement des données "Aujourd\'hui"');
        fetchAllData();
      };

      window.addEventListener('transaction:added', handleTransactionAdded);
      window.addEventListener('transaction:updated', handleTransactionAdded);
      window.addEventListener('transaction:deleted', handleTransactionAdded);

      return () => {
        window.removeEventListener('transaction:added', handleTransactionAdded);
        window.removeEventListener('transaction:updated', handleTransactionAdded);
        window.removeEventListener('transaction:deleted', handleTransactionAdded);
      };
    }
  }, [period, fetchAllData]);

  // ... reste du composant
}
```

### Étape 6 : Vérifier que tous les endroits où on ajoute une transaction utilisent le hook

Rechercher tous les endroits où vous créez/modifiez/supprimez une transaction et s'assurer qu'ils utilisent `refreshAnalytics()` :

```typescript
// Exemples d'endroits à vérifier :
// 1. AddTransactionDialog (déjà fait ci-dessus)
// 2. EditTransactionDialog (déjà fait ci-dessus)
// 3. DeleteTransactionDialog (déjà fait ci-dessus)
// 4. Toute autre modale ou composant qui crée/modifie/supprime une transaction
```

## 📋 IMPLÉMENTATION COMPLÈTE

### Fichier 1 : `hooks/useAnalyticsRefresh.ts`

```typescript
'use client';

import { useCallback } from 'react';

/**
 * Hook pour rafraîchir les données Analytics
 * Émet un événement personnalisé que la page Analytics écoute
 */
export function useAnalyticsRefresh() {
  const refreshAnalytics = useCallback(() => {
    // Émettre un événement personnalisé pour déclencher le rafraîchissement
    window.dispatchEvent(new CustomEvent('analytics:refresh'));
  }, []);

  return { refreshAnalytics };
}
```

### Fichier 2 : Modifier `AddTransactionDialog`

```typescript
import { useAnalyticsRefresh } from '@/hooks/useAnalyticsRefresh';

// Dans handleSubmit, après le succès :
const { refreshAnalytics } = useAnalyticsRefresh();

// Après api.post('/api/transactions', payload)
refreshAnalytics(); // Ajouter cette ligne
```

### Fichier 3 : Modifier la page Analytics

```typescript
// Ajouter l'écouteur d'événement
useEffect(() => {
  const handleRefresh = () => {
    fetchAllData();
  };

  window.addEventListener('analytics:refresh', handleRefresh);
  return () => window.removeEventListener('analytics:refresh', handleRefresh);
}, [fetchAllData]);
```

## ✅ CHECKLIST

- [ ] Créer le hook `useAnalyticsRefresh`
- [ ] Importer et utiliser `useAnalyticsRefresh` dans `AddTransactionDialog`
- [ ] Appeler `refreshAnalytics()` après le succès de l'ajout
- [ ] Faire de même dans `EditTransactionDialog`
- [ ] Faire de même dans `DeleteTransactionDialog`
- [ ] Ajouter l'écouteur d'événement dans la page Analytics
- [ ] Tester : ajouter une vente et vérifier que les données se mettent à jour
- [ ] Tester : modifier une transaction et vérifier la mise à jour
- [ ] Tester : supprimer une transaction et vérifier la mise à jour
- [ ] Vérifier que ça fonctionne même si la page Analytics n'est pas ouverte (les données seront à jour quand on y reviendra)

## 🎯 RÉSULTAT ATTENDU

- Après avoir ajouté une nouvelle vente, les statistiques Analytics se mettent à jour automatiquement
- Plus besoin d'actualiser la page manuellement
- Les données sont toujours à jour en temps réel
- Fonctionne pour les ajouts, modifications et suppressions de transactions

## 📝 NOTES IMPORTANTES

1. **Événement personnalisé** : L'événement `analytics:refresh` est émis et écouté via `window`, ce qui fonctionne même si les composants ne sont pas directement liés.

2. **Performance** : Le rafraîchissement ne se fait que quand nécessaire (après une action utilisateur), pas en continu.

3. **Dépendances** : S'assurer que `fetchAllData` est dans les dépendances de `useEffect` pour éviter les warnings.

4. **Tous les endroits** : Vérifier que TOUS les endroits où vous créez/modifiez/supprimez une transaction appellent `refreshAnalytics()`.

Implémentez le rafraîchissement automatique des Analytics selon les instructions ci-dessus.
```

---

## 📝 NOTES TECHNIQUES

1. **Événements personnalisés** : Utiliser `window.dispatchEvent` et `window.addEventListener` permet de découpler les composants.

2. **Hook réutilisable** : Le hook `useAnalyticsRefresh` peut être utilisé partout dans l'application.

3. **Performance** : Le rafraîchissement se fait uniquement après une action, pas en continu.

4. **Robustesse** : Même si la page Analytics n'est pas ouverte, l'événement est émis. Quand l'utilisateur y reviendra, les données seront à jour.

