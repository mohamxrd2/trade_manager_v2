# 📋 PROMPT POUR METTRE À JOUR LES ANALYTICS APRÈS CHAQUE TRANSACTION

## 🚀 Copiez ce prompt dans Cursor :

```
Je dois mettre à jour automatiquement les données Analytics à chaque fois qu'une transaction est créée, modifiée ou supprimée.

## 🎯 OBJECTIF

Lorsqu'un utilisateur :
- Ajoute une nouvelle transaction (vente ou dépense)
- Modifie une transaction existante
- Supprime une transaction

Les données Analytics doivent être automatiquement rafraîchies pour refléter les changements en temps réel.

## 🔧 SOLUTIONS À IMPLÉMENTER

### Solution 1 : Recharger les Analytics après chaque action (Recommandée)

#### 1.1 Créer un hook personnalisé pour gérer le rafraîchissement

Créer un hook `useAnalyticsRefresh` qui peut être appelé depuis n'importe quel composant :

```typescript
// hooks/useAnalyticsRefresh.ts
import { useCallback } from 'react';
import { useAnalyticsStore } from '@/stores/analytics-store'; // Si vous utilisez un store
// OU
// import { useAnalytics } from '@/contexts/AnalyticsContext'; // Si vous utilisez un contexte

export function useAnalyticsRefresh() {
  const refreshAnalytics = useCallback(async () => {
    // Option 1 : Si vous utilisez un store (Zustand, Redux, etc.)
    // const { fetchAllData } = useAnalyticsStore();
    // await fetchAllData();
    
    // Option 2 : Si vous utilisez un contexte
    // const { refresh } = useAnalytics();
    // await refresh();
    
    // Option 3 : Émettre un événement personnalisé
    window.dispatchEvent(new CustomEvent('analytics:refresh'));
    
    // Option 4 : Utiliser un query invalidation (React Query)
    // queryClient.invalidateQueries(['analytics']);
  }, []);

  return { refreshAnalytics };
}
```

#### 1.2 Utiliser le hook dans les composants de transaction

Dans votre composant `AddTransactionDialog` ou similaire :

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

      // Rafraîchir les Analytics
      await refreshAnalytics();

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

#### 1.3 Utiliser dans EditTransactionDialog

```typescript
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
      await refreshAnalytics();

      onOpenChange(false);
    } catch (error) {
      // Gestion d'erreur
    }
  };

  // ... reste du composant
}
```

#### 1.4 Utiliser dans DeleteTransactionDialog

```typescript
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
      await refreshAnalytics();

      onOpenChange(false);
    } catch (error) {
      // Gestion d'erreur
    }
  };

  // ... reste du composant
}
```

### Solution 2 : Utiliser un contexte Analytics global

Créer un contexte Analytics qui gère le rafraîchissement :

```typescript
// contexts/AnalyticsContext.tsx
'use client';

import { createContext, useContext, useState, useCallback, ReactNode } from 'react';
import { api } from '@/lib/api';
import { toast } from '@/hooks/use-toast';

interface AnalyticsContextType {
  refreshAnalytics: () => Promise<void>;
  isRefreshing: boolean;
}

const AnalyticsContext = createContext<AnalyticsContextType | undefined>(undefined);

export function AnalyticsProvider({ children }: { children: ReactNode }) {
  const [isRefreshing, setIsRefreshing] = useState(false);

  const refreshAnalytics = useCallback(async () => {
    setIsRefreshing(true);
    try {
      // Émettre un événement pour que la page Analytics se rafraîchisse
      window.dispatchEvent(new CustomEvent('analytics:refresh'));
    } catch (error) {
      console.error('Erreur lors du rafraîchissement des Analytics:', error);
    } finally {
      setIsRefreshing(false);
    }
  }, []);

  return (
    <AnalyticsContext.Provider value={{ refreshAnalytics, isRefreshing }}>
      {children}
    </AnalyticsContext.Provider>
  );
}

export function useAnalytics() {
  const context = useContext(AnalyticsContext);
  if (!context) {
    throw new Error('useAnalytics must be used within AnalyticsProvider');
  }
  return context;
}
```

Dans votre layout ou app :

```typescript
import { AnalyticsProvider } from '@/contexts/AnalyticsContext';

export default function RootLayout({ children }) {
  return (
    <html>
      <body>
        <AnalyticsProvider>
          {children}
        </AnalyticsProvider>
      </body>
    </html>
  );
}
```

### Solution 3 : Écouter les événements dans la page Analytics

Dans votre page Analytics, écouter les événements de rafraîchissement :

```typescript
// app/analytics/page.tsx ou components/AnalyticsPage.tsx
'use client';

import { useEffect } from 'react';

export default function AnalyticsPage() {
  const [period, setPeriod] = useState<Period>('today');
  // ... autres états

  const fetchAllData = async () => {
    // ... votre logique de chargement
  };

  useEffect(() => {
    // Charger les données au montage
    fetchAllData();
  }, [period, startDate, endDate]);

  useEffect(() => {
    // Écouter les événements de rafraîchissement
    const handleRefresh = () => {
      fetchAllData();
    };

    window.addEventListener('analytics:refresh', handleRefresh);

    return () => {
      window.removeEventListener('analytics:refresh', handleRefresh);
    };
  }, [period, startDate, endDate]); // Inclure les dépendances nécessaires
}
```

### Solution 4 : Utiliser React Query (si vous l'utilisez déjà)

Si vous utilisez React Query, invalider les queries après chaque transaction :

```typescript
import { useQueryClient } from '@tanstack/react-query';

export function AddTransactionDialog() {
  const queryClient = useQueryClient();

  const handleSubmit = async (data) => {
    try {
      await api.post('/api/transactions', data);
      
      // Invalider toutes les queries Analytics
      await queryClient.invalidateQueries({ queryKey: ['analytics'] });
      
      toast({
        title: "Succès",
        description: "Transaction ajoutée avec succès",
      });
    } catch (error) {
      // Gestion d'erreur
    }
  };
}
```

## 📋 IMPLÉMENTATION RECOMMANDÉE (Solution hybride)

### Étape 1 : Créer le hook useAnalyticsRefresh

```typescript
// hooks/useAnalyticsRefresh.ts
'use client';

import { useCallback } from 'react';

export function useAnalyticsRefresh() {
  const refreshAnalytics = useCallback(() => {
    // Émettre un événement personnalisé
    window.dispatchEvent(new CustomEvent('analytics:refresh'));
  }, []);

  return { refreshAnalytics };
}
```

### Étape 2 : Utiliser dans tous les composants de transaction

Dans `AddTransactionDialog`, `EditTransactionDialog`, `DeleteTransactionDialog` :

```typescript
import { useAnalyticsRefresh } from '@/hooks/useAnalyticsRefresh';

// Dans handleSubmit, handleUpdate, handleDelete
const { refreshAnalytics } = useAnalyticsRefresh();

// Après succès de l'opération
await refreshAnalytics();
```

### Étape 3 : Écouter dans la page Analytics

```typescript
useEffect(() => {
  const handleRefresh = () => {
    fetchAllData();
  };

  window.addEventListener('analytics:refresh', handleRefresh);

  return () => {
    window.removeEventListener('analytics:refresh', handleRefresh);
  };
}, [period, startDate, endDate]);
```

## ✅ CHECKLIST

- [ ] Créer le hook `useAnalyticsRefresh`
- [ ] Utiliser le hook dans `AddTransactionDialog` (après création)
- [ ] Utiliser le hook dans `EditTransactionDialog` (après modification)
- [ ] Utiliser le hook dans `DeleteTransactionDialog` (après suppression)
- [ ] Ajouter l'écouteur d'événement dans la page Analytics
- [ ] Tester : créer une transaction et vérifier que les Analytics se mettent à jour
- [ ] Tester : modifier une transaction et vérifier la mise à jour
- [ ] Tester : supprimer une transaction et vérifier la mise à jour
- [ ] Vérifier que les données se mettent à jour même si la page Analytics n'est pas ouverte (pour quand l'utilisateur y reviendra)

## 🎯 RÉSULTAT ATTENDU

- Après chaque création/modification/suppression de transaction, les Analytics se mettent à jour automatiquement
- Si l'utilisateur est sur la page Analytics, les données se rafraîchissent en temps réel
- Si l'utilisateur n'est pas sur la page Analytics, les données seront à jour quand il y reviendra
- Pas besoin de recharger manuellement la page

## 📝 NOTES IMPORTANTES

1. **Performance** : Le rafraîchissement ne se fait que si nécessaire (après une action utilisateur)

2. **UX** : Vous pouvez ajouter un indicateur de chargement subtil lors du rafraîchissement :



3. **Optimisation** : Si vous utilisez React Query ou un autre système de cache, vous pouvez optimiser en ne rafraîchissant que les sections nécessaires plutôt que toutes les données.

4. **Gestion d'erreur** : Si le rafraîchissement échoue, vous pouvez afficher un toast ou simplement ignorer l'erreur (les données seront à jour au prochain chargement).

Implémentez le rafraîchissement automatique des Analytics après chaque transaction selon la solution recommandée.
```

---

## 📝 NOTES TECHNIQUES

1. **Événements personnalisés** : Cette approche utilise des événements personnalisés du navigateur, ce qui est léger et ne nécessite pas de dépendances supplémentaires.

2. **Découplage** : Les composants de transaction n'ont pas besoin de connaître la structure de la page Analytics, ils émettent simplement un événement.

3. **Flexibilité** : Si vous changez d'approche plus tard (WebSockets, Server-Sent Events, etc.), vous n'avez qu'à modifier le hook `useAnalyticsRefresh`.

4. **Compatibilité** : Cette solution fonctionne avec n'importe quelle architecture (React Query, Zustand, Redux, Context API, etc.).

