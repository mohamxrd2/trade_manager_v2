# 📋 PROMPT POUR CORRIGER LE DÉCALAGE DES DONNÉES ANALYTICS

## 🚀 Copiez ce prompt dans Cursor :

```
J'ai un problème de décalage dans l'affichage des données Analytics. Lorsque je me déconnecte et me reconnecte avec un autre compte, je vois d'abord les données de l'ancien compte pendant quelques secondes avant que les nouvelles données s'affichent.

## 🔍 PROBLÈME IDENTIFIÉ

**Symptôme** :
- Après déconnexion et reconnexion avec un autre compte
- Les données Analytics de l'ancien compte s'affichent brièvement
- Puis les nouvelles données se chargent et remplacent les anciennes

**Cause** :
- Le state des Analytics n'est pas réinitialisé lors de la déconnexion/connexion
- Les données sont mises en cache et ne sont pas invalidées
- Les requêtes en cours ne sont pas annulées lors du changement d'utilisateur

## 🔧 SOLUTIONS À IMPLÉMENTER

### Solution 1 : Réinitialiser le state lors de la déconnexion/connexion

#### 1.1 Réinitialiser lors de la déconnexion

Dans votre composant de déconnexion ou dans le contexte d'authentification :

```typescript
// contexts/AuthContext.tsx ou similaire
const logout = useCallback(async () => {
  try {
    await authService.logout();
    
    // Réinitialiser le state Analytics
    window.dispatchEvent(new CustomEvent('analytics:reset'));
    
    // OU si vous utilisez un store
    // analyticsStore.reset();
    
    setUser(null);
  } catch (error) {
    console.error('Erreur lors de la déconnexion:', error);
    throw error;
  }
}, []);
```

#### 1.2 Réinitialiser lors de la connexion

Dans votre composant de connexion ou dans le contexte d'authentification :

```typescript
const login = useCallback(async (credentials: LoginCredentials) => {
  try {
    const loggedInUser = await authService.login(credentials);
    
    // Réinitialiser le state Analytics avant de charger les nouvelles données
    window.dispatchEvent(new CustomEvent('analytics:reset'));
    
    setUser(loggedInUser);
    
    // Attendre un court instant pour s'assurer que le reset est terminé
    await new Promise(resolve => setTimeout(resolve, 100));
    
    // Charger les nouvelles données Analytics
    window.dispatchEvent(new CustomEvent('analytics:refresh'));
  } catch (error: any) {
    console.error('Erreur lors de la connexion:', error);
    throw error;
  }
}, []);
```

### Solution 2 : Réinitialiser dans la page Analytics

Dans votre page Analytics, écouter les événements de reset et de changement d'utilisateur :

```typescript
// app/analytics/page.tsx ou components/AnalyticsPage.tsx
'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/contexts/AuthContext';

export default function AnalyticsPage() {
  const { user } = useAuth();
  const [period, setPeriod] = useState<Period>('today');
  const [overview, setOverview] = useState(null);
  const [trends, setTrends] = useState(null);
  // ... autres états

  // Réinitialiser toutes les données
  const resetAnalytics = useCallback(() => {
    setOverview(null);
    setTrends(null);
    setCategoryAnalysis(null);
    setComparisons(null);
    setKpis(null);
    setTransactions([]);
    setPredictions([]);
    setLoading(true); // Afficher le loader pendant le chargement
  }, []);

  // Réinitialiser lors du changement d'utilisateur
  useEffect(() => {
    if (user) {
      // Nouvel utilisateur connecté, réinitialiser et recharger
      resetAnalytics();
      fetchAllData();
    } else {
      // Utilisateur déconnecté, réinitialiser
      resetAnalytics();
    }
  }, [user?.id]); // Dépendre de l'ID utilisateur, pas de l'objet user complet

  // Écouter les événements de reset
  useEffect(() => {
    const handleReset = () => {
      resetAnalytics();
    };

    window.addEventListener('analytics:reset', handleReset);

    return () => {
      window.removeEventListener('analytics:reset', handleReset);
    };
  }, [resetAnalytics]);

  // Écouter les événements de refresh
  useEffect(() => {
    const handleRefresh = () => {
      if (user) {
        fetchAllData();
      }
    };

    window.addEventListener('analytics:refresh', handleRefresh);

    return () => {
      window.removeEventListener('analytics:refresh', handleRefresh);
    };
  }, [user, period, startDate, endDate]);

  // ... reste du composant
}
```

### Solution 3 : Annuler les requêtes en cours

Si vous utilisez axios, créer un CancelToken pour annuler les requêtes en cours :

```typescript
import axios, { CancelTokenSource } from 'axios';

export default function AnalyticsPage() {
  const { user } = useAuth();
  const cancelTokenRef = useRef<CancelTokenSource | null>(null);

  const fetchAllData = async () => {
    // Annuler la requête précédente si elle existe
    if (cancelTokenRef.current) {
      cancelTokenRef.current.cancel('Nouvelle requête initiée');
    }

    // Créer un nouveau token d'annulation
    cancelTokenRef.current = axios.CancelToken.source();

    setLoading(true);
    try {
      const params = {
        period,
        ...(period === 'custom' && startDate && endDate ? {
          start_date: dayjs(startDate).format('YYYY-MM-DD'),
          end_date: dayjs(endDate).format('YYYY-MM-DD')
        } : {})
      };

      const [overviewRes, trendsRes, ...] = await Promise.all([
        api.get('/api/analytics/overview', { 
          params,
          cancelToken: cancelTokenRef.current.token 
        }),
        api.get('/api/analytics/trends', { 
          params: { ...params, type: 'both' },
          cancelToken: cancelTokenRef.current.token 
        }),
        // ... autres appels avec cancelToken
      ]);

      // Vérifier que l'utilisateur n'a pas changé pendant le chargement
      if (user && user.id === (await api.get('/api/user')).data.id) {
        setOverview(overviewRes.data.data);
        setTrends(trendsRes.data.data);
        // ... mettre à jour les autres états
      }
    } catch (error: any) {
      if (axios.isCancel(error)) {
        console.log('Requête annulée:', error.message);
      } else {
        toast.error('Erreur lors du chargement des statistiques');
      }
    } finally {
      setLoading(false);
      cancelTokenRef.current = null;
    }
  };

  // Annuler les requêtes lors de la déconnexion
  useEffect(() => {
    if (!user && cancelTokenRef.current) {
      cancelTokenRef.current.cancel('Utilisateur déconnecté');
      cancelTokenRef.current = null;
      resetAnalytics();
    }
  }, [user]);
}
```

### Solution 4 : Vérifier l'utilisateur avant d'afficher les données

Ajouter une vérification pour s'assurer que les données affichées correspondent à l'utilisateur connecté :

```typescript
export default function AnalyticsPage() {
  const { user } = useAuth();
  const [dataUserId, setDataUserId] = useState<string | null>(null);

  const fetchAllData = async () => {
    if (!user) {
      resetAnalytics();
      return;
    }

    setLoading(true);
    try {
      // Charger les données
      const [overviewRes, ...] = await Promise.all([
        api.get('/api/analytics/overview', { params }),
        // ... autres appels
      ]);

      // Vérifier que l'utilisateur n'a pas changé
      const currentUser = await api.get('/api/user');
      const currentUserId = currentUser.data.id;

      if (currentUserId !== user.id) {
        // L'utilisateur a changé, ne pas afficher les données
        console.log('Utilisateur a changé, annulation de l\'affichage');
        return;
      }

      // Mettre à jour l'ID de l'utilisateur des données
      setDataUserId(currentUserId);

      // Afficher les données seulement si elles correspondent à l'utilisateur actuel
      if (dataUserId === user.id || dataUserId === null) {
        setOverview(overviewRes.data.data);
        // ... mettre à jour les autres états
      }
    } catch (error) {
      // Gestion d'erreur
    } finally {
      setLoading(false);
    }
  };

  // Ne pas afficher les données si l'utilisateur ne correspond pas
  if (user && dataUserId && dataUserId !== user.id) {
    return (
      <div className="flex items-center justify-center p-8">
        <Loader2 className="h-8 w-8 animate-spin" />
      </div>
    );
  }

  // ... reste du composant
}
```

### Solution 5 : Utiliser un loading state strict

S'assurer qu'un loader s'affiche pendant le chargement et que les anciennes données ne sont pas visibles :

```typescript
export default function AnalyticsPage() {
  const { user } = useAuth();
  const [loading, setLoading] = useState(true); // true par défaut
  const [isInitialLoad, setIsInitialLoad] = useState(true);

  useEffect(() => {
    if (user) {
      setIsInitialLoad(true);
      setLoading(true);
      resetAnalytics();
      
      // Attendre un court instant avant de charger pour s'assurer que le reset est terminé
      const timer = setTimeout(() => {
        fetchAllData().finally(() => {
          setIsInitialLoad(false);
        });
      }, 100);

      return () => clearTimeout(timer);
    } else {
      resetAnalytics();
      setLoading(false);
    }
  }, [user?.id]);

  // Afficher un loader pendant le chargement initial ou si les données ne correspondent pas
  if (loading || isInitialLoad || !user) {
    return (
      <div className="space-y-4">
        <Skeleton className="h-8 w-64" />
        <div className="grid gap-4 md:grid-cols-3">
          <Skeleton className="h-32" />
          <Skeleton className="h-32" />
          <Skeleton className="h-32" />
        </div>
        {/* ... autres skeletons */}
      </div>
    );
  }

  // Afficher les données seulement si le chargement est terminé
  return (
    <div>
      {/* ... contenu Analytics */}
    </div>
  );
}
```

## 📋 IMPLÉMENTATION RECOMMANDÉE (Solution complète)

### Étape 1 : Réinitialiser lors de la déconnexion/connexion

```typescript
// contexts/AuthContext.tsx
const logout = useCallback(async () => {
  await authService.logout();
  window.dispatchEvent(new CustomEvent('analytics:reset'));
  setUser(null);
}, []);

const login = useCallback(async (credentials) => {
  const user = await authService.login(credentials);
  window.dispatchEvent(new CustomEvent('analytics:reset'));
  setUser(user);
  // Attendre avant de charger les nouvelles données
  setTimeout(() => {
    window.dispatchEvent(new CustomEvent('analytics:refresh'));
  }, 100);
}, []);
```

### Étape 2 : Réinitialiser dans la page Analytics

```typescript
// app/analytics/page.tsx
export default function AnalyticsPage() {
  const { user } = useAuth();
  const [loading, setLoading] = useState(true);
  const [dataUserId, setDataUserId] = useState<string | null>(null);

  const resetAnalytics = useCallback(() => {
    setOverview(null);
    setTrends(null);
    setCategoryAnalysis(null);
    setComparisons(null);
    setKpis(null);
    setTransactions([]);
    setPredictions([]);
    setDataUserId(null);
    setLoading(true);
  }, []);

  // Réinitialiser lors du changement d'utilisateur
  useEffect(() => {
    if (user) {
      resetAnalytics();
      const timer = setTimeout(() => {
        fetchAllData();
      }, 100);
      return () => clearTimeout(timer);
    } else {
      resetAnalytics();
      setLoading(false);
    }
  }, [user?.id]);

  // Écouter les événements
  useEffect(() => {
    const handleReset = () => resetAnalytics();
    const handleRefresh = () => {
      if (user) fetchAllData();
    };

    window.addEventListener('analytics:reset', handleReset);
    window.addEventListener('analytics:refresh', handleRefresh);

    return () => {
      window.removeEventListener('analytics:reset', handleReset);
      window.removeEventListener('analytics:refresh', handleRefresh);
    };
  }, [user, resetAnalytics]);

  const fetchAllData = async () => {
    if (!user) return;

    setLoading(true);
    try {
      // Vérifier l'utilisateur actuel
      const currentUserRes = await api.get('/api/user');
      const currentUserId = currentUserRes.data.id;

      if (currentUserId !== user.id) {
        return; // Utilisateur a changé
      }

      // Charger les données
      const [overviewRes, ...] = await Promise.all([
        api.get('/api/analytics/overview', { params }),
        // ... autres appels
      ]);

      // Vérifier à nouveau avant d'afficher
      const verifyUserRes = await api.get('/api/user');
      if (verifyUserRes.data.id === user.id) {
        setDataUserId(user.id);
        setOverview(overviewRes.data.data);
        // ... mettre à jour les autres états
      }
    } catch (error) {
      // Gestion d'erreur
    } finally {
      setLoading(false);
    }
  };

  // Afficher loader si pas d'utilisateur ou données ne correspondent pas
  if (!user || loading || (dataUserId && dataUserId !== user.id)) {
    return <AnalyticsSkeleton />;
  }

  return (
    <div>
      {/* Contenu Analytics */}
    </div>
  );
}
```

## ✅ CHECKLIST

- [ ] Réinitialiser le state Analytics lors de la déconnexion
- [ ] Réinitialiser le state Analytics lors de la connexion
- [ ] Écouter les changements d'utilisateur dans la page Analytics
- [ ] Vérifier que les données correspondent à l'utilisateur connecté
- [ ] Afficher un loader pendant le chargement initial
- [ ] Annuler les requêtes en cours lors du changement d'utilisateur (optionnel)
- [ ] Tester : se déconnecter et se reconnecter avec un autre compte
- [ ] Vérifier qu'aucune donnée de l'ancien compte ne s'affiche

## 🎯 RÉSULTAT ATTENDU

- Lors de la déconnexion, toutes les données Analytics sont réinitialisées
- Lors de la connexion avec un nouveau compte, un loader s'affiche immédiatement
- Aucune donnée de l'ancien compte ne s'affiche brièvement
- Les nouvelles données se chargent uniquement pour le nouvel utilisateur
- Pas de décalage ou de "flash" des anciennes données

## 📝 NOTES IMPORTANTES

1. **Performance** : Le délai de 100ms avant le chargement permet de s'assurer que le reset est terminé avant de charger les nouvelles données.

2. **Sécurité** : Vérifier l'utilisateur avant et après le chargement des données garantit qu'aucune donnée ne s'affiche pour le mauvais utilisateur.

3. **UX** : Afficher un loader immédiatement lors du changement d'utilisateur améliore l'expérience utilisateur et évite la confusion.

4. **State Management** : Si vous utilisez un store global (Zustand, Redux), assurez-vous de réinitialiser le store Analytics lors de la déconnexion/connexion.

Corrigez le problème de décalage des données Analytics selon les solutions ci-dessus.
```

---

## 📝 NOTES TECHNIQUES

1. **Race Condition** : Le problème vient souvent d'une race condition où les anciennes données sont encore affichées pendant que les nouvelles se chargent. La solution est de réinitialiser immédiatement et d'afficher un loader.

2. **Vérification utilisateur** : Vérifier l'utilisateur avant et après le chargement garantit que les données affichées correspondent toujours à l'utilisateur connecté.

3. **Événements personnalisés** : Utiliser des événements personnalisés permet de découpler la logique de réinitialisation de la logique d'affichage.

4. **Loading State** : Un loading state strict avec vérification de l'utilisateur empêche l'affichage de données incorrectes.

