# 📋 PROMPT POUR DÉFINIR "AUJOURD'HUI" COMME PÉRIODE PAR DÉFAUT

## 🚀 Copiez ce prompt dans Cursor :

```
Je dois définir "Aujourd'hui" comme période par défaut dans ma page Analytics.

## 🎯 OBJECTIF

Lorsque l'utilisateur ouvre la page Analytics, la période "Aujourd'hui" doit être automatiquement sélectionnée et les données doivent être chargées pour aujourd'hui.

## 🔧 MODIFICATIONS À FAIRE

### 1. Mettre à jour l'état initial de la période

Dans votre composant Analytics, changer la valeur par défaut de `period` :

```typescript
// AVANT
const [period, setPeriod] = useState<Period>('30'); // ou 'all', etc.

// APRÈS
const [period, setPeriod] = useState<Period>('today'); // "Aujourd'hui" par défaut
```

### 2. Vérifier que les données se chargent au montage

Assurez-vous que `useEffect` charge les données au montage du composant :

```typescript
useEffect(() => {
  fetchAllData();
}, [period, startDate, endDate]); // Recharger quand la période change
```

### 3. Vérifier l'affichage dans le Select

Le Select doit afficher "Aujourd'hui" comme sélectionné par défaut :

```typescript
<Select value={period} onValueChange={(value) => setPeriod(value as Period)}>
  <SelectTrigger>
    <SelectValue placeholder="Sélectionner une période" />
  </SelectTrigger>
  <SelectContent>
    <SelectItem value="today">Aujourd'hui</SelectItem>
    <SelectItem value="7">7 derniers jours</SelectItem>
    <SelectItem value="30">30 derniers jours</SelectItem>
    <SelectItem value="year">Cette année</SelectItem>
    <SelectItem value="all">Depuis toujours</SelectItem>
    <SelectItem value="custom">Personnalisé</SelectItem>
  </SelectContent>
</Select>
```

Avec `value={period}` et `period` initialisé à `'today'`, "Aujourd'hui" sera automatiquement sélectionné.

### 4. Vérifier le chargement initial

Au montage du composant, les données doivent se charger automatiquement :

```typescript
useEffect(() => {
  // Charger les données avec la période par défaut ('today')
  fetchAllData();
}, []); // Charger une seule fois au montage

// Et recharger quand la période change
useEffect(() => {
  fetchAllData();
}, [period, startDate, endDate]);
```

### 5. Exemple complet

```typescript
'use client';

import { useEffect, useState } from 'react';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

type Period = 'today' | '7' | '30' | 'year' | 'all' | 'custom';

export default function AnalyticsPage() {
  // "Aujourd'hui" par défaut
  const [period, setPeriod] = useState<Period>('today');
  const [startDate, setStartDate] = useState<Date | null>(null);
  const [endDate, setEndDate] = useState<Date | null>(null);
  const [loading, setLoading] = useState(false);

  // Charger les données au montage et quand la période change
  useEffect(() => {
    fetchAllData();
  }, [period, startDate, endDate]);

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

      // Charger toutes les données...
      const [overviewRes, trendsRes, ...] = await Promise.all([
        api.get('/api/analytics/overview', { params }),
        api.get('/api/analytics/trends', { params: { ...params, type: 'both' } }),
        // ... autres appels
      ]);

      // ... traitement des réponses
    } catch (error) {
      toast.error('Erreur lors du chargement des statistiques');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div>
      <Select value={period} onValueChange={(value) => setPeriod(value as Period)}>
        <SelectTrigger>
          <SelectValue placeholder="Sélectionner une période" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="today">Aujourd'hui</SelectItem>
          <SelectItem value="7">7 derniers jours</SelectItem>
          <SelectItem value="30">30 derniers jours</SelectItem>
          <SelectItem value="year">Cette année</SelectItem>
          <SelectItem value="all">Depuis toujours</SelectItem>
          <SelectItem value="custom">Personnalisé</SelectItem>
        </SelectContent>
      </Select>
      
      {/* Reste du composant */}
    </div>
  );
}
```

## ✅ CHECKLIST

- [ ] Changer `useState<Period>('30')` en `useState<Period>('today')`
- [ ] Vérifier que le Select affiche bien "Aujourd'hui" comme sélectionné au chargement
- [ ] Vérifier que les données se chargent automatiquement pour "Aujourd'hui" au montage
- [ ] Tester que le changement de période fonctionne toujours
- [ ] Vérifier que les graphiques et statistiques s'affichent correctement pour "Aujourd'hui"

## 🎯 RÉSULTAT ATTENDU

- Au chargement de la page Analytics, "Aujourd'hui" est sélectionné par défaut
- Les données pour aujourd'hui sont automatiquement chargées
- L'utilisateur peut toujours changer la période
- Le Select affiche correctement "Aujourd'hui" comme sélectionné

## 📝 NOTES IMPORTANTES

1. **Chargement initial** : Assurez-vous que `useEffect` charge les données au montage avec la période par défaut.

2. **Performance** : "Aujourd'hui" est généralement rapide car il n'y a qu'un jour de données à charger.

3. **UX** : "Aujourd'hui" est un bon choix par défaut car c'est souvent ce que l'utilisateur veut voir en premier.

Définissez "Aujourd'hui" comme période par défaut selon les instructions ci-dessus.
```

