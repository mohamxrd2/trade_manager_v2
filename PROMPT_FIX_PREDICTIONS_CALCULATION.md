# 📋 PROMPT POUR CORRIGER LE CALCUL DES PRÉDICTIONS ET DES COMPARAISONS

## 🚀 Copiez ce prompt dans Cursor :

```
Je dois corriger deux problèmes dans ma page Analytics :
1. Vérifier que les pourcentages de comparaison sont bien calculés
2. Corriger le calcul des prédictions de réapprovisionnement

## 🔍 PROBLÈMES IDENTIFIÉS

**Problème 1 - Comparaisons** :
- Les pourcentages de variation peuvent ne pas être correctement calculés
- Besoin de vérifier la formule : ((current - previous) / previous) * 100

**Problème 2 - Prédictions** :
- Le calcul des jours jusqu'à la pénurie n'est pas correct
- Formule attendue : quantité_restante / (ventes_moyennes_par_jour)
- Ventes moyennes par jour = quantité totale vendue / nombre de jours entre première et dernière vente

## 🔧 CORRECTIONS BACKEND (DÉJÀ FAITES)

Le backend a été corrigé pour :
1. Calculer correctement les pourcentages de comparaison
2. Utiliser la bonne formule pour les prédictions : `jours_restants = quantité_restante / ventes_moyennes_par_jour`

## 🔧 CORRECTIONS FRONTEND

### 1. Vérifier l'affichage des pourcentages de comparaison

S'assurer que les pourcentages sont correctement formatés :

```typescript
const formatPercentage = (value: number | null | undefined): string => {
  if (value === null || value === undefined || isNaN(value)) {
    return '0%';
  }
  
  // Formater avec 2 décimales
  return `${value >= 0 ? '+' : ''}${value.toFixed(2)}%`;
};

// Utiliser dans ComparisonCard
<span className={`text-sm font-medium ${color}`}>
  {formatPercentage(change)}
</span>
```

### 2. Vérifier l'affichage des prédictions

S'assurer que les prédictions s'affichent correctement avec la nouvelle formule :

```typescript
// Les prédictions doivent toujours être chargées, peu importe la période
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

    // IMPORTANT : Toujours charger les prédictions, elles ne dépendent pas de la période
    const [overviewRes, trendsRes, categoryRes, comparisonsRes, kpisRes, transactionsRes, predictionsRes] = await Promise.all([
      api.get('/api/analytics/overview', { params }),
      api.get('/api/analytics/trends', { params: { ...params, type: 'both' } }),
      api.get('/api/analytics/category-analysis', { params }),
      period !== 'all' ? api.get('/api/analytics/comparisons', { params }) : Promise.resolve({ data: { data: null } }),
      api.get('/api/analytics/kpis', { params }),
      api.get('/api/analytics/transactions', { params: { ...params, page: 1 } }),
      api.get('/api/analytics/predictions'), // TOUJOURS charger les prédictions
    ]);

    setOverview(overviewRes.data.data);
    setTrends(trendsRes.data.data);
    setCategoryAnalysis(categoryRes.data.data);
    if (comparisonsRes.data.data) setComparisons(comparisonsRes.data.data);
    setKpis(kpisRes.data.data);
    setTransactions(transactionsRes.data.data.transactions);
    setPredictions(predictionsRes.data.data); // TOUJOURS définir les prédictions
  } catch (error) {
    toast.error('Erreur lors du chargement des statistiques');
  } finally {
    setLoading(false);
  }
};
```

### 3. Afficher les prédictions même pour "Depuis toujours"

S'assurer que la section prédictions s'affiche toujours :

```typescript
// Dans le rendu de la page Analytics
{/* Section Prédictions de Réapprovisionnement */}
{predictions && predictions.length > 0 ? (
  <Card>
    <CardHeader>
      <CardTitle>Prédictions de Réapprovisionnement</CardTitle>
    </CardHeader>
    <CardContent>
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Article</TableHead>
            <TableHead>Type</TableHead>
            <TableHead>Quantité actuelle</TableHead>
            <TableHead>Quantité vendue</TableHead>
            <TableHead>Quantité restante</TableHead>
            <TableHead>% vendu</TableHead>
            <TableHead>Ventes/jour</TableHead>
            <TableHead>Jours restants</TableHead>
            <TableHead>Date prédite</TableHead>
            <TableHead>Statut</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {predictions.map((prediction) => (
            <TableRow key={prediction.article_id}>
              <TableCell>{prediction.article_name}</TableCell>
              <TableCell>
                <Badge variant="outline">{prediction.type}</Badge>
              </TableCell>
              <TableCell>{prediction.current_quantity}</TableCell>
              <TableCell>{prediction.sold_quantity}</TableCell>
              <TableCell>{prediction.remaining_quantity}</TableCell>
              <TableCell>
                <div className="flex items-center space-x-2">
                  <Progress value={prediction.sales_percentage} className="w-16" />
                  <span className="text-sm">{prediction.sales_percentage.toFixed(1)}%</span>
                </div>
              </TableCell>
              <TableCell>{prediction.sales_rate_per_day.toFixed(2)}</TableCell>
              <TableCell>
                {prediction.days_until_reorder > 0 ? (
                  <span className={prediction.days_until_reorder < 7 ? 'text-red-600 font-semibold' : ''}>
                    {prediction.days_until_reorder} jours
                  </span>
                ) : (
                  <span className="text-red-600 font-semibold">Épuisé</span>
                )}
              </TableCell>
              <TableCell>
                {prediction.predicted_reorder_date ? (
                  dayjs(prediction.predicted_reorder_date).format('DD/MM/YYYY')
                ) : (
                  <span className="text-muted-foreground">-</span>
                )}
              </TableCell>
              <TableCell>
                <Badge 
                  variant={prediction.status === 'out_of_stock' ? 'destructive' : 
                          prediction.days_until_reorder < 7 ? 'default' : 'secondary'}
                  className={prediction.days_until_reorder < 7 && prediction.status !== 'out_of_stock' ? 'bg-amber-500' : ''}
                >
                  {prediction.status === 'out_of_stock' ? 'Épuisé' : 
                   prediction.days_until_reorder < 7 ? 'Urgent' : 'OK'}
                </Badge>
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </CardContent>
  </Card>
) : predictions && predictions.length === 0 ? (
  <Card>
    <CardContent className="p-6">
      <p className="text-muted-foreground text-center">
        Aucune prédiction disponible (pas assez de données de vente)
      </p>
    </CardContent>
  </Card>
) : (
  <Card>
    <CardContent className="p-6">
      <Skeleton className="h-32" />
    </CardContent>
  </Card>
)}
```

### 4. Vérifier la formule de calcul côté frontend (si nécessaire)

Si vous affichez des calculs côté frontend, utiliser la même formule :

```typescript
// Fonction helper pour calculer les jours restants (si nécessaire)
const calculateDaysUntilReorder = (
  remainingQuantity: number,
  salesRatePerDay: number
): number => {
  if (salesRatePerDay <= 0 || remainingQuantity <= 0) {
    return 0;
  }
  
  // Formule : quantité restante / ventes moyennes par jour
  return Math.ceil(remainingQuantity / salesRatePerDay);
};
```

## ✅ CHECKLIST

- [ ] Vérifier que les prédictions sont TOUJOURS chargées (pas de condition `period !== 'all'`)
- [ ] Vérifier que les pourcentages de comparaison sont formatés avec 2 décimales
- [ ] Vérifier que les prédictions s'affichent même pour "Depuis toujours"
- [ ] Vérifier l'affichage des jours restants (formule : quantité_restante / ventes_par_jour)
- [ ] Vérifier l'affichage du taux de vente par jour
- [ ] Tester avec "Aujourd'hui" : les prédictions doivent s'afficher
- [ ] Tester avec "Depuis toujours" : les prédictions doivent s'afficher
- [ ] Vérifier que les calculs correspondent à la formule backend

## 🎯 RÉSULTAT ATTENDU

- Les prédictions s'affichent pour toutes les périodes, y compris "Depuis toujours"
- Les jours restants sont calculés avec la formule : `quantité_restante / ventes_moyennes_par_jour`
- Les pourcentages de comparaison sont correctement calculés et formatés
- Les données sont cohérentes entre backend et frontend

## 📝 NOTES IMPORTANTES

1. **Prédictions indépendantes de la période** : Les prédictions ne dépendent pas de la période sélectionnée, elles sont basées sur toutes les ventes historiques de l'article.

2. **Formule de prédiction** : 
   - Ventes moyennes par jour = Quantité totale vendue / Nombre de jours entre première et dernière vente
   - Jours restants = Quantité restante / Ventes moyennes par jour

3. **Backend corrigé** : Le backend utilise maintenant la bonne formule pour calculer les prédictions.

4. **Chargement** : Les prédictions doivent toujours être chargées, peu importe la période sélectionnée.

Corrigez l'affichage des prédictions et vérifiez les calculs selon les instructions ci-dessus.
```

---

## 📝 NOTES TECHNIQUES

1. **Formule de prédiction** : `jours_restants = quantité_restante / (quantité_vendue_totale / jours_écoulés)`

2. **Indépendance de la période** : Les prédictions sont basées sur toutes les ventes historiques, pas sur une période spécifique.

3. **Backend corrigé** : La méthode `predictions()` a été corrigée pour utiliser la bonne formule.

4. **Chargement** : S'assurer que les prédictions sont toujours chargées dans `fetchAllData`, même pour "Depuis toujours".

