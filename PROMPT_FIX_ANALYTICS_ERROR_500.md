# 📋 PROMPT POUR CORRIGER L'ERREUR 500 ANALYTICS

## 🚀 Copiez ce prompt dans Cursor :

```
J'ai une erreur 500 lors de l'appel à l'endpoint `/api/analytics/category-analysis` dans mon application Next.js.

## 🔍 PROBLÈME

**Erreur** : `❌ Erreur serveur 500 lors de la récupération de l'analyse par catégorie`

**Endpoint** : `GET /api/analytics/category-analysis`

**Fichier** : `lib/services/analytics.ts` (ligne 248)

## 🔧 SOLUTION

### 1. Vérifier les logs du backend Laravel

D'abord, vérifiez les logs Laravel pour voir l'erreur exacte :

```bash
tail -f storage/logs/laravel.log
```

Ou dans votre terminal Laravel, regardez l'erreur complète qui s'affiche.

### 2. Améliorer la gestion d'erreur côté frontend

Dans `lib/services/analytics.ts`, améliorer la fonction `getCategoryAnalysis` pour afficher plus de détails sur l'erreur :

```typescript
export const getCategoryAnalysis = async (params: AnalyticsParams) => {
  try {
    const response = await api.get('/api/analytics/category-analysis', { params });
    return response.data.data;
  } catch (error: any) {
    // Afficher plus de détails sur l'erreur
    console.error('❌ Erreur category-analysis:', {
      message: error.message,
      response: error.response?.data,
      status: error.response?.status,
      statusText: error.response?.statusText,
      config: {
        url: error.config?.url,
        params: error.config?.params
      }
    });
    
    // Afficher l'erreur dans un toast pour l'utilisateur
    toast.error(
      error.response?.data?.message || 
      'Erreur lors de la récupération de l\'analyse par catégorie'
    );
    
    throw error;
  }
};
```

### 3. Gérer le cas où il n'y a pas de données

Dans votre composant Analytics, gérer le cas où `categoryAnalysis` est vide ou null :

```typescript
const [categoryAnalysis, setCategoryAnalysis] = useState<{
  sales_by_type: Array<{ type: string; total: number; percentage: number }>;
  top_products: Array<{ id: string; name: string; type: string; total_quantity: number; total_amount: number }>;
} | null>(null);

// Dans le useEffect ou la fonction de chargement
try {
  const data = await getCategoryAnalysis(params);
  setCategoryAnalysis(data || {
    sales_by_type: [],
    top_products: []
  });
} catch (error) {
  // Définir des valeurs par défaut en cas d'erreur
  setCategoryAnalysis({
    sales_by_type: [],
    top_products: []
  });
}
```

### 4. Afficher un message si pas de données

Dans le rendu de la section "Analyse par catégorie" :

```typescript
{categoryAnalysis && (
  <>
    {categoryAnalysis.sales_by_type.length === 0 ? (
      <Card>
        <CardContent className="p-6">
          <p className="text-muted-foreground text-center">
            Aucune donnée de vente disponible pour cette période
          </p>
        </CardContent>
      </Card>
    ) : (
      // Afficher le PieChart
      <PieChart data={categoryAnalysis.sales_by_type} />
    )}
    
    {categoryAnalysis.top_products.length === 0 ? (
      <Card>
        <CardContent className="p-6">
          <p className="text-muted-foreground text-center">
            Aucun produit vendu pour cette période
          </p>
        </CardContent>
      </Card>
    ) : (
      // Afficher le BarChart
      <BarChart data={categoryAnalysis.top_products} />
    )}
  </>
)}
```

### 5. Vérifier les paramètres envoyés

Assurez-vous que les paramètres sont correctement formatés :

```typescript
const params: AnalyticsParams = {
  period: period,
  ...(period === 'custom' && startDate && endDate ? {
    start_date: dayjs(startDate).format('YYYY-MM-DD'),
    end_date: dayjs(endDate).format('YYYY-MM-DD')
  } : {})
};

console.log('📊 Paramètres category-analysis:', params);
```

### 6. Ajouter un fallback pour les erreurs réseau

Si l'erreur persiste, ajouter un retry ou un fallback :

```typescript
const getCategoryAnalysisWithRetry = async (params: AnalyticsParams, retries = 2) => {
  for (let i = 0; i <= retries; i++) {
    try {
      return await getCategoryAnalysis(params);
    } catch (error: any) {
      if (i === retries) {
        // Dernière tentative échouée, retourner des données vides
        console.warn('⚠️ Impossible de charger category-analysis après plusieurs tentatives');
        return {
          sales_by_type: [],
          top_products: []
        };
      }
      // Attendre avant de réessayer
      await new Promise(resolve => setTimeout(resolve, 1000 * (i + 1)));
    }
  }
};
```

### 7. Vérifier la réponse du backend

Dans votre composant, loguer la réponse complète pour déboguer :

```typescript
useEffect(() => {
  const fetchData = async () => {
    try {
      const response = await api.get('/api/analytics/category-analysis', { params });
      console.log('✅ Réponse category-analysis:', response.data);
      setCategoryAnalysis(response.data.data);
    } catch (error: any) {
      console.error('❌ Erreur complète:', {
        error,
        response: error.response,
        data: error.response?.data
      });
    }
  };
  
  fetchData();
}, [period, startDate, endDate]);
```

## 🎯 CHECKLIST DE DÉBOGAGE

- [ ] Vérifier les logs Laravel pour l'erreur exacte
- [ ] Améliorer la gestion d'erreur dans `getCategoryAnalysis`
- [ ] Ajouter des valeurs par défaut si les données sont vides
- [ ] Vérifier que les paramètres sont correctement formatés
- [ ] Afficher un message utilisateur si pas de données
- [ ] Tester avec différentes périodes (today, 7, 30, year, custom)
- [ ] Vérifier que l'utilisateur a bien des transactions de type 'sale'
- [ ] Vérifier que les transactions ont bien un `article_id` non null

## 🔍 CAUSES POSSIBLES

1. **Aucune transaction de vente** : Si l'utilisateur n'a pas de ventes, le join peut échouer
2. **Article supprimé** : Si un article a été supprimé, `article_id` peut pointer vers NULL
3. **Problème de permissions** : L'utilisateur peut ne pas avoir accès à certains articles
4. **Erreur SQL** : Problème avec le join ou le groupBy
5. **Format de date incorrect** : Les dates peuvent être mal formatées

## 📝 SOLUTION TEMPORAIRE

Si l'erreur persiste, vous pouvez temporairement désactiver cette section :

```typescript
const [showCategoryAnalysis, setShowCategoryAnalysis] = useState(true);

// Dans le catch
catch (error) {
  console.error('Erreur category-analysis, masquage de la section');
  setShowCategoryAnalysis(false);
}

// Dans le rendu
{showCategoryAnalysis && (
  // Section analyse par catégorie
)}
```

Corrigez la gestion d'erreur et ajoutez des fallbacks pour gérer les cas où les données sont vides ou l'API retourne une erreur.
```

---

## 📝 NOTES IMPORTANTES

1. **Backend corrigé** : Le backend a été corrigé pour utiliser `leftJoin` et gérer les cas où `article_id` est NULL.

2. **Gestion d'erreur** : Améliorer la gestion d'erreur côté frontend pour afficher plus de détails et gérer gracieusement les erreurs.

3. **Valeurs par défaut** : Toujours prévoir des valeurs par défaut (tableaux vides) si l'API échoue ou retourne des données vides.

4. **Logs** : Vérifier les logs Laravel pour identifier la cause exacte de l'erreur 500.

