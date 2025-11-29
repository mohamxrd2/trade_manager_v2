# 📋 PROMPT POUR CRÉER LA PAGE ANALYTICS/STATISTIQUES

## 🚀 Copiez ce prompt dans Cursor :

```
Je dois créer une page Analytics/Statistiques complète dans mon application Next.js. Supprimez tout le contenu existant de la page analytics et remplacez-le par cette nouvelle implémentation.

## 🎯 OBJECTIF

Créer une page de statistiques complète avec :
1. Aperçu des performances globales (revenu net, ventes, dépenses) avec filtre de période
2. Graphiques de tendances (ventes/dépenses dans le temps, wallet dans le temps)
3. Analyse par catégorie (répartition ventes, top 5 produits)
4. Comparaisons temporelles (période actuelle vs précédente)
5. Ratios financiers & KPI (marge nette, panier moyen, etc.)
6. Tableau détaillé filtrable des transactions
7. Prédictions de réapprovisionnement

## 🔗 CONFIGURATION API

**Base URL** : `http://localhost:8000`

**Endpoints disponibles** :
- `GET /api/analytics/overview?period=30&start_date=&end_date=` : Aperçu global
- `GET /api/analytics/trends?period=30&type=both&start_date=&end_date=` : Données pour graphiques
- `GET /api/analytics/category-analysis?period=30&start_date=&end_date=` : Analyse par catégorie
- `GET /api/analytics/comparisons?period=30&start_date=&end_date=` : Comparaisons temporelles
- `GET /api/analytics/kpis?period=30&start_date=&end_date=` : KPI financiers
- `GET /api/analytics/transactions?period=30&type=&search=&page=1&per_page=15` : Transactions détaillées
- `GET /api/analytics/predictions` : Prédictions de réapprovisionnement

**Authentification** : Cookies HTTP-only (withCredentials: true)
- Utiliser l'instance axios configurée dans `lib/api.ts`

**Paramètres de période** :
- `period` : `'today'`, `'7'`, `'30'`, `'year'`, `'all'`, `'custom'`
- Par défaut : `'today'` (Aujourd'hui)
- Si `period='custom'`, utiliser `start_date` et `end_date` (format: YYYY-MM-DD)

## 📋 STRUCTURE DE LA PAGE

### 1. Section : Filtres & Sélecteurs (En haut de la page)

**Composants** :
- Sélecteur de période : `Select` de shadcn/ui avec options :
  - "Aujourd'hui" (sélectionné par défaut)
  - "7 derniers jours"
  - "30 derniers jours"
  - "Cette année"
  - "Depuis toujours"
  - "Personnalisé"
- Si "Personnalisé" sélectionné, afficher deux `DatePicker` (date de début et fin)
- Bouton "Appliquer" pour déclencher le rechargement des données

**État** :
```typescript
const [period, setPeriod] = useState<'today' | '7' | '30' | 'year' | 'custom'>('30');
const [startDate, setStartDate] = useState<Date | null>(null);
const [endDate, setEndDate] = useState<Date | null>(null);
const [loading, setLoading] = useState(false);
```

### 2. Section : Aperçu des performances globales

**Cards** (3 cards côte à côte) :
- 💰 **Revenu net** : `net_revenue` (formaté avec `formatCurrency`)
- 📈 **Total des ventes** : `total_sales` (formaté avec `formatCurrency`, couleur verte)
- 📉 **Total des dépenses** : `total_expenses` (formaté avec `formatCurrency`, couleur rouge)

**API** : `GET /api/analytics/overview`

**Réponse** :
```typescript
{
  success: boolean;
  data: {
    net_revenue: number;
    total_sales: number;
    total_expenses: number;
    period: string;
    start_date: string;
    end_date: string;
  }
}
```

**Affichage** :
- Utiliser `Card` de shadcn/ui
- Afficher un skeleton loader pendant le chargement
- Icônes appropriées pour chaque métrique

### 3. Section : Graphiques de tendances

**Graphique 1 : Ventes & Dépenses dans le temps**
- Type : `AreaChart` de shadcn/ui (ou recharts)
- Axe X : Dates
- Axe Y : Montants
- Deux courbes : Ventes (vert) et Dépenses (rouge)
- Légende avec les deux séries

**Graphique 2 : Solde du wallet (calculated_wallet) dans le temps**
- Type : `AreaChart`
- Axe X : Dates
- Axe Y : Montant du wallet
- Une courbe : Wallet (bleu)
- Titre : "Évolution du portefeuille"

**API** : `GET /api/analytics/trends?type=both`

**Réponse** :
```typescript
{
  success: boolean;
  data: {
    sales_expenses?: {
      sales: Array<{ date: string; amount: number }>;
      expenses: Array<{ date: string; amount: number }>;
    };
    wallet?: Array<{ date: string; amount: number }>;
  }
}
```

**Installation** (si nécessaire) :
```bash
npm install recharts
```

### 4. Section : Analyse par catégorie

**Graphique 1 : Répartition des ventes par type d'article**
- Type : `PieChart` de shadcn/ui (ou recharts)
- Données : `sales_by_type` (array avec `type`, `total`, `percentage`)
- Afficher les pourcentages sur chaque segment
- Légende avec les types et leurs pourcentages

**Graphique 2 : Top 5 produits les plus vendus**
- Type : `BarChart` horizontal de recharts
- Axe Y : Noms des produits
- Axe X : Quantités vendues
- Afficher aussi le montant total à côté de chaque barre

**API** : `GET /api/analytics/category-analysis`

**Réponse** :
```typescript
{
  success: boolean;
  data: {
    sales_by_type: Array<{
      type: string;
      total: number;
      percentage: number;
    }>;
    top_products: Array<{
      id: string;
      name: string;
      type: string;
      total_quantity: number;
      total_amount: number;
    }>;
  }
}
```

### 5. Section : Comparaisons temporelles

**Cards** (3 cards) :
- 📈 **Ventes** : Période actuelle vs précédente avec variation en %
- 📉 **Dépenses** : Période actuelle vs précédente avec variation en %
- 💰 **Revenu net** : Période actuelle vs précédente avec variation en %

**Affichage** :
- Valeur actuelle en grand
- Valeur précédente en petit (gris)
- Variation en % avec flèche verte 🔼 (augmentation) ou rouge 🔽 (diminution)
- Exemple : "+15%" ou "-8%"

**API** : `GET /api/analytics/comparisons`

**Réponse** :
```typescript
{
  success: boolean;
  data: {
    sales: {
      current: number;
      previous: number;
      change: number;
      change_type: 'increase' | 'decrease';
    };
    expenses: { ... };
    net_revenue: { ... };
  }
}
```

### 6. Section : Ratios financiers & KPI

**Cards** (4 cards en grid) :
- 💸 **Marge nette** : `net_margin` % (format: "XX.XX%")
- 📦 **Panier moyen** : `average_basket` (formaté avec `formatCurrency`)
- ⏱️ **Ventes moyennes par jour** : `average_sales_per_day` (formaté avec `formatCurrency`)
- 📊 **Taux de dépenses** : `expense_rate` % (format: "XX.XX%")

**API** : `GET /api/analytics/kpis`

**Réponse** :
```typescript
{
  success: boolean;
  data: {
    net_margin: number;
    average_basket: number;
    average_sales_per_day: number;
    expense_rate: number;
    sales_count: number;
    days: number;
  }
}
```

### 7. Section : Tableau détaillé filtrable

**Fonctionnalités** :
- Tableau avec colonnes :
  - Date
  - Nom/Type
  - Type (Vente/Dépense)
  - Montant
  - Actions (optionnel)
- 🔍 **Recherche** : Input pour rechercher par nom d'article ou type
- 📆 **Filtre par type** : Select avec options "Tous", "Vente", "Dépense"
- 📄 **Pagination** : Utiliser `Pagination` de shadcn/ui
- 📤 **Bouton "Exporter"** : (Optionnel) Exporter en CSV

**API** : `GET /api/analytics/transactions`

**Paramètres** :
- `period`, `start_date`, `end_date` : Période
- `type` : `'sale'`, `'expense'`, ou `null` pour tous
- `search` : Terme de recherche
- `page` : Numéro de page (défaut: 1)
- `per_page` : Éléments par page (défaut: 15)

**Réponse** :
```typescript
{
  success: boolean;
  data: {
    transactions: Transaction[];
    pagination: {
      current_page: number;
      per_page: number;
      total: number;
      last_page: number;
    };
  }
}
```

**Affichage** :
- Badge vert pour "Vente", rouge pour "Dépense"
- Format de date avec dayjs : `dayjs(transaction.created_at).format("DD MMM YYYY")`
- Montant formaté avec `formatCurrency`

### 8. Section : Prédictions de réapprovisionnement

**Tableau** avec colonnes :
- Article
- Type
- Quantité actuelle
- Quantité vendue
- Quantité restante
- % vendu
- Taux de vente/jour
- Date prédite de réapprovisionnement
- Jours jusqu'à réapprovisionnement
- Statut (Badge : "En stock" ou "Épuisé")

**API** : `GET /api/analytics/predictions`

**Réponse** :
```typescript
{
  success: boolean;
  data: Array<{
    article_id: string;
    article_name: string;
    type: string;
    current_quantity: number;
    sold_quantity: number;
    remaining_quantity: number;
    sales_percentage: number;
    status: 'in_stock' | 'out_of_stock';
    predicted_reorder_date: string | null;
    days_until_reorder: number;
    sales_rate_per_day: number;
    average_interval_days: number;
  }>;
}
```

**Affichage** :
- Trier par `days_until_reorder` (plus urgent en premier)
- Badge rouge pour "Épuisé", orange pour "Urgent" (< 7 jours), vert pour "OK"
- Progress bar pour `sales_percentage`
- Format de date avec dayjs pour `predicted_reorder_date`

## 🎨 COMPOSANTS SHADCN/UI À UTILISER

- `Card`, `CardHeader`, `CardTitle`, `CardContent`
- `Select` pour le sélecteur de période
- `Button` pour "Appliquer"
- `DatePicker` (si disponible) ou `Input` type="date"
- `Table`, `TableHeader`, `TableBody`, `TableRow`, `TableCell`
- `Badge` pour les types et statuts
- `Progress` pour les barres de progression
- `Skeleton` pour les loaders
- `Tabs` (optionnel) pour organiser les sections

## 📦 INSTALLATION REQUISE

```bash
npm install recharts
npm install dayjs
```

## 🔧 CODE STRUCTURE

### État global de la page

```typescript
const [period, setPeriod] = useState<'today' | '7' | '30' | 'year' | 'custom'>('30');
const [startDate, setStartDate] = useState<Date | null>(null);
const [endDate, setEndDate] = useState<Date | null>(null);
const [loading, setLoading] = useState(false);

// Données
const [overview, setOverview] = useState(null);
const [trends, setTrends] = useState(null);
const [categoryAnalysis, setCategoryAnalysis] = useState(null);
const [comparisons, setComparisons] = useState(null);
const [kpis, setKpis] = useState(null);
const [transactions, setTransactions] = useState([]);
const [predictions, setPredictions] = useState([]);

// Pagination
const [currentPage, setCurrentPage] = useState(1);
const [searchQuery, setSearchQuery] = useState('');
const [transactionType, setTransactionType] = useState<string | null>(null);
```

### Fonction pour charger toutes les données

```typescript
const fetchAllData = async () => {
  setLoading(true);
  try {
    const params = {
      period,
      ...(period === 'custom' && startDate && endDate ? {
        start_date: dayjs(startDate).format('YYYY-MM-DD'),
        end_date: dayjs(endDate).format('YYYY-MM-DD')
      } : {})
    };

    // Charger toutes les données en parallèle
    const [
      overviewRes,
      trendsRes,
      categoryRes,
      comparisonsRes,
      kpisRes,
      transactionsRes,
      predictionsRes
    ] = await Promise.all([
      api.get('/api/analytics/overview', { params }),
      api.get('/api/analytics/trends', { params: { ...params, type: 'both' } }),
      api.get('/api/analytics/category-analysis', { params }),
      api.get('/api/analytics/comparisons', { params }),
      api.get('/api/analytics/kpis', { params }),
      api.get('/api/analytics/transactions', { 
        params: { ...params, page: currentPage, search: searchQuery, type: transactionType }
      }),
      api.get('/api/analytics/predictions')
    ]);

    setOverview(overviewRes.data.data);
    setTrends(trendsRes.data.data);
    setCategoryAnalysis(categoryRes.data.data);
    setComparisons(comparisonsRes.data.data);
    setKpis(kpisRes.data.data);
    setTransactions(transactionsRes.data.data.transactions);
    setPredictions(predictionsRes.data.data);
  } catch (error) {
    toast.error('Erreur lors du chargement des statistiques');
  } finally {
    setLoading(false);
  }
};
```

## ✅ CHECKLIST

- [ ] Supprimer le contenu existant de la page analytics
- [ ] Créer la section Filtres & Sélecteurs
- [ ] Implémenter la section Aperçu des performances globales
- [ ] Créer les graphiques de tendances (AreaChart)
- [ ] Implémenter l'analyse par catégorie (PieChart, BarChart)
- [ ] Créer la section Comparaisons temporelles
- [ ] Implémenter la section KPI
- [ ] Créer le tableau détaillé filtrable
- [ ] Implémenter la section Prédictions
- [ ] Ajouter les skeleton loaders
- [ ] Gérer les erreurs avec des toasts
- [ ] Tester avec différentes périodes
- [ ] Vérifier la responsivité mobile

## 🎯 RÉSULTAT ATTENDU

Une page Analytics complète avec :
- Filtres de période fonctionnels
- Toutes les sections affichées correctement
- Graphiques interactifs
- Tableaux avec pagination et recherche
- Prédictions de réapprovisionnement
- Design cohérent avec shadcn/ui
- Responsive et accessible

Créez la page Analytics complète selon ces spécifications.
```

---

## 📝 NOTES IMPORTANTES

1. **Graphiques** : Utiliser `recharts` pour les graphiques (AreaChart, PieChart, BarChart). Si vous préférez shadcn/ui, vérifiez la disponibilité des composants de graphiques.

2. **Formatage** : Utiliser `formatCurrency` pour les montants et `dayjs` pour les dates.

3. **Performance** : Charger toutes les données en parallèle avec `Promise.all()` pour améliorer les performances.

4. **Responsive** : S'assurer que les graphiques et tableaux sont responsive sur mobile.

5. **Prédictions** : La section prédictions utilise un algorithme basé sur la fréquence de vente pour prédire quand un article sera à 100% vendu.

