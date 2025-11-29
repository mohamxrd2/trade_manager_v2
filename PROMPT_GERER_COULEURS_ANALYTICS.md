# 📋 PROMPT POUR GÉRER LES COULEURS DANS LA PAGE ANALYTICS

## 🚀 Copiez ce prompt dans Cursor :

```
Je dois gérer correctement les couleurs dans ma page Analytics pour assurer une cohérence visuelle et une bonne accessibilité.

## 🎯 OBJECTIF

Utiliser un système de couleurs cohérent et accessible dans tous les composants Analytics :
- Graphiques (ventes, dépenses, wallet)
- Cards de statistiques
- Badges et indicateurs
- Tableaux et listes
- Indicateurs de variation (augmentation/diminution)

## 🎨 SYSTÈME DE COULEURS À UTILISER

### 1. Couleurs principales (shadcn/ui)

Utiliser les couleurs du thème CSS de shadcn/ui :

```typescript
// Couleurs du thème
const colors = {
  primary: 'hsl(var(--primary))',
  secondary: 'hsl(var(--secondary))',
  success: 'hsl(var(--chart-1))', // Vert pour les ventes
  danger: 'hsl(var(--destructive))', // Rouge pour les dépenses
  warning: 'hsl(var(--warning))', // Orange/Amber
  info: 'hsl(var(--chart-2))', // Bleu pour le wallet
  muted: 'hsl(var(--muted))',
  background: 'hsl(var(--background))',
  foreground: 'hsl(var(--foreground))',
};
```

### 2. Couleurs pour les graphiques

**Ventes** : Vert (success)
- Couleur principale : `hsl(var(--chart-1))` ou `#22c55e` (green-500)
- Couleur de fond (area) : `hsl(var(--chart-1))` avec opacité 0.2
- Couleur de bordure : `hsl(var(--chart-1))`

**Dépenses** : Rouge (danger)
- Couleur principale : `hsl(var(--destructive))` ou `#ef4444` (red-500)
- Couleur de fond (area) : `hsl(var(--destructive))` avec opacité 0.2
- Couleur de bordure : `hsl(var(--destructive))`

**Wallet** : Bleu (info)
- Couleur principale : `hsl(var(--chart-2))` ou `#3b82f6` (blue-500)
- Couleur de fond (area) : `hsl(var(--chart-2))` avec opacité 0.2
- Couleur de bordure : `hsl(var(--chart-2))`

### 3. Couleurs pour les indicateurs de variation

**Augmentation** (positive) : Vert
- Couleur : `hsl(var(--chart-1))` ou `text-green-600`
- Icône : `TrendingUp` (flèche vers le haut)
- Badge : Badge vert

**Diminution** (négative) : Rouge
- Couleur : `hsl(var(--destructive))` ou `text-red-600`
- Icône : `TrendingDown` (flèche vers le bas)
- Badge : Badge rouge

**Neutre** (pas de changement) : Gris
- Couleur : `hsl(var(--muted-foreground))` ou `text-gray-500`
- Icône : `Minus` ou `ArrowRight`

### 4. Couleurs pour les types de transaction

**Vente** : Badge vert
```typescript
<Badge variant="outline" className="border-green-500 text-green-700 bg-green-50 dark:bg-green-950 dark:text-green-400">
  Vente
</Badge>
```

**Dépense** : Badge rouge
```typescript
<Badge variant="outline" className="border-red-500 text-red-700 bg-red-50 dark:bg-red-950 dark:text-red-400">
  Dépense
</Badge>
```

### 5. Couleurs pour les statuts

**En stock** : Vert
```typescript
<Badge className="bg-green-500 text-white">En stock</Badge>
```

**Stock faible** : Orange/Amber
```typescript
<Badge className="bg-amber-500 text-white">Stock faible</Badge>
```

**Épuisé** : Rouge
```typescript
<Badge className="bg-red-500 text-white">Épuisé</Badge>
```

## 📊 IMPLÉMENTATION DANS LES GRAPHIQUES

### Graphique Ventes & Dépenses (AreaChart)

```typescript
import { AreaChart, Area, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';

const chartColors = {
  sales: {
    stroke: 'hsl(var(--chart-1))', // Vert
    fill: 'hsl(var(--chart-1))',
    fillOpacity: 0.2,
  },
  expenses: {
    stroke: 'hsl(var(--destructive))', // Rouge
    fill: 'hsl(var(--destructive))',
    fillOpacity: 0.2,
  },
};

<ResponsiveContainer width="100%" height={300}>
  <AreaChart data={trends?.sales_expenses?.sales || []}>
    <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
    <XAxis 
      dataKey="date" 
      className="text-muted-foreground"
      tick={{ fill: 'hsl(var(--muted-foreground))' }}
    />
    <YAxis 
      className="text-muted-foreground"
      tick={{ fill: 'hsl(var(--muted-foreground))' }}
    />
    <Tooltip 
      contentStyle={{ 
        backgroundColor: 'hsl(var(--background))',
        border: '1px solid hsl(var(--border))',
        borderRadius: '8px',
      }}
    />
    <Legend />
    <Area
      type="monotone"
      dataKey="amount"
      name="Ventes"
      stroke={chartColors.sales.stroke}
      fill={chartColors.sales.fill}
      fillOpacity={chartColors.sales.fillOpacity}
    />
  </AreaChart>
</ResponsiveContainer>

// Pour les dépenses
<Area
  type="monotone"
  dataKey="amount"
  name="Dépenses"
  stroke={chartColors.expenses.stroke}
  fill={chartColors.expenses.fill}
  fillOpacity={chartColors.expenses.fillOpacity}
/>
```

### Graphique Wallet (AreaChart)

```typescript
const walletColors = {
  stroke: 'hsl(var(--chart-2))', // Bleu
  fill: 'hsl(var(--chart-2))',
  fillOpacity: 0.2,
};

<AreaChart data={trends?.wallet || []}>
  <Area
    type="monotone"
    dataKey="amount"
    name="Wallet"
    stroke={walletColors.stroke}
    fill={walletColors.fill}
    fillOpacity={walletColors.fillOpacity}
  />
</AreaChart>
```

### Graphique PieChart (Répartition par type)

```typescript
import { PieChart, Pie, Cell, ResponsiveContainer, Legend, Tooltip } from 'recharts';

const COLORS = [
  'hsl(var(--chart-1))', // Vert
  'hsl(var(--chart-2))', // Bleu
  'hsl(var(--chart-3))', // Violet
  'hsl(var(--chart-4))', // Orange
  'hsl(var(--chart-5))', // Rose
];

// Ou utiliser des couleurs personnalisées
const TYPE_COLORS: Record<string, string> = {
  simple: 'hsl(var(--chart-1))', // Vert pour simple
  variable: 'hsl(var(--chart-2))', // Bleu pour variable
};

<PieChart>
  <Pie
    data={categoryAnalysis?.sales_by_type || []}
    cx="50%"
    cy="50%"
    labelLine={false}
    label={({ name, percentage }) => `${name}: ${percentage}%`}
    outerRadius={80}
    fill="#8884d8"
    dataKey="total"
  >
    {categoryAnalysis?.sales_by_type?.map((entry, index) => (
      <Cell 
        key={`cell-${index}`} 
        fill={TYPE_COLORS[entry.type] || COLORS[index % COLORS.length]} 
      />
    ))}
  </Pie>
  <Tooltip 
    contentStyle={{ 
      backgroundColor: 'hsl(var(--background))',
      border: '1px solid hsl(var(--border))',
    }}
  />
  <Legend />
</PieChart>
```

### Graphique BarChart (Top 5 produits)

```typescript
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';

<BarChart data={categoryAnalysis?.top_products || []} layout="vertical">
  <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
  <XAxis 
    type="number"
    className="text-muted-foreground"
    tick={{ fill: 'hsl(var(--muted-foreground))' }}
  />
  <YAxis 
    dataKey="name" 
    type="category"
    className="text-muted-foreground"
    tick={{ fill: 'hsl(var(--muted-foreground))' }}
  />
  <Tooltip 
    contentStyle={{ 
      backgroundColor: 'hsl(var(--background))',
      border: '1px solid hsl(var(--border))',
    }}
  />
  <Bar 
    dataKey="total_quantity" 
    name="Quantité vendue"
    fill="hsl(var(--chart-1))" // Vert
    radius={[0, 8, 8, 0]}
  />
</BarChart>
```

## 💳 IMPLÉMENTATION DANS LES CARDS

### Card Revenu net

```typescript
<Card>
  <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
    <CardTitle className="text-sm font-medium">Revenu net</CardTitle>
    <DollarSign className="h-4 w-4 text-muted-foreground" />
  </CardHeader>
  <CardContent>
    <div className="text-2xl font-bold text-foreground">
      {formatCurrency(overview?.net_revenue || 0)}
    </div>
    <p className="text-xs text-muted-foreground mt-1">
      {overview?.start_date} - {overview?.end_date}
    </p>
  </CardContent>
</Card>
```

### Card Total des ventes (vert)

```typescript
<Card>
  <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
    <CardTitle className="text-sm font-medium">Total des ventes</CardTitle>
    <TrendingUp className="h-4 w-4 text-green-600" />
  </CardHeader>
  <CardContent>
    <div className="text-2xl font-bold text-green-600">
      {formatCurrency(overview?.total_sales || 0)}
    </div>
  </CardContent>
</Card>
```

### Card Total des dépenses (rouge)

```typescript
<Card>
  <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
    <CardTitle className="text-sm font-medium">Total des dépenses</CardTitle>
    <TrendingDown className="h-4 w-4 text-red-600" />
  </CardHeader>
  <CardContent>
    <div className="text-2xl font-bold text-red-600">
      {formatCurrency(overview?.total_expenses || 0)}
    </div>
  </CardContent>
</Card>
```

## 📈 IMPLÉMENTATION DANS LES COMPARAISONS

### Card avec variation

```typescript
const ComparisonCard = ({ title, current, previous, change, changeType }: ComparisonCardProps) => {
  const isIncrease = changeType === 'increase';
  const color = isIncrease ? 'text-green-600' : 'text-red-600';
  const bgColor = isIncrease ? 'bg-green-50 dark:bg-green-950' : 'bg-red-50 dark:bg-red-950';
  const Icon = isIncrease ? TrendingUp : TrendingDown;

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-sm font-medium">{title}</CardTitle>
      </CardHeader>
      <CardContent>
        <div className="text-2xl font-bold">{formatCurrency(current)}</div>
        <div className="flex items-center mt-2 space-x-2">
          <div className={`flex items-center space-x-1 px-2 py-1 rounded ${bgColor}`}>
            <Icon className={`h-4 w-4 ${color}`} />
            <span className={`text-sm font-medium ${color}`}>
              {change >= 0 ? '+' : ''}{change}%
            </span>
          </div>
          <span className="text-xs text-muted-foreground">
            vs période précédente
          </span>
        </div>
        <p className="text-xs text-muted-foreground mt-1">
          Période précédente: {formatCurrency(previous)}
        </p>
      </CardContent>
    </Card>
  );
};
```

## 🎨 FONCTION HELPER POUR LES COULEURS

Créer une fonction helper pour gérer les couleurs de manière cohérente :

```typescript
// utils/chartColors.ts
export const chartColors = {
  sales: {
    light: '#22c55e', // green-500
    dark: '#16a34a', // green-600
    css: 'hsl(var(--chart-1))',
  },
  expenses: {
    light: '#ef4444', // red-500
    dark: '#dc2626', // red-600
    css: 'hsl(var(--destructive))',
  },
  wallet: {
    light: '#3b82f6', // blue-500
    dark: '#2563eb', // blue-600
    css: 'hsl(var(--chart-2))',
  },
  success: {
    light: '#22c55e',
    dark: '#16a34a',
    css: 'hsl(var(--chart-1))',
  },
  danger: {
    light: '#ef4444',
    dark: '#dc2626',
    css: 'hsl(var(--destructive))',
  },
  warning: {
    light: '#f59e0b', // amber-500
    dark: '#d97706', // amber-600
    css: 'hsl(var(--chart-4))',
  },
};

export const getChartColor = (type: 'sales' | 'expenses' | 'wallet', theme: 'light' | 'dark' = 'light') => {
  return chartColors[type][theme];
};

export const getVariationColor = (changeType: 'increase' | 'decrease') => {
  return changeType === 'increase' 
    ? chartColors.success.css 
    : chartColors.danger.css;
};
```

## 🌓 SUPPORT DU MODE SOMBRE

S'assurer que toutes les couleurs fonctionnent en mode clair et sombre :

```typescript
// Utiliser les variables CSS du thème
const colors = {
  background: 'hsl(var(--background))',
  foreground: 'hsl(var(--foreground))',
  muted: 'hsl(var(--muted))',
  mutedForeground: 'hsl(var(--muted-foreground))',
  border: 'hsl(var(--border))',
  card: 'hsl(var(--card))',
  cardForeground: 'hsl(var(--card-foreground))',
};

// Dans les graphiques, utiliser les couleurs qui s'adaptent au thème
<Area
  stroke="hsl(var(--chart-1))"
  fill="hsl(var(--chart-1))"
  fillOpacity={0.2}
/>
```

## ✅ CHECKLIST

- [ ] Définir un système de couleurs cohérent (vert pour ventes, rouge pour dépenses, bleu pour wallet)
- [ ] Utiliser les couleurs du thème shadcn/ui (`hsl(var(--chart-1))`, etc.)
- [ ] Implémenter les couleurs dans tous les graphiques (AreaChart, PieChart, BarChart)
- [ ] Utiliser les bonnes couleurs dans les Cards (vert pour ventes, rouge pour dépenses)
- [ ] Gérer les couleurs des indicateurs de variation (vert pour augmentation, rouge pour diminution)
- [ ] Utiliser les bonnes couleurs pour les Badges (vert pour vente, rouge pour dépense)
- [ ] S'assurer que les couleurs fonctionnent en mode clair et sombre
- [ ] Tester l'accessibilité des couleurs (contraste suffisant)
- [ ] Créer une fonction helper pour les couleurs si nécessaire
- [ ] Vérifier la cohérence visuelle sur toute la page

## 🎯 RÉSULTAT ATTENDU

- Tous les graphiques utilisent des couleurs cohérentes et accessibles
- Les ventes sont toujours en vert, les dépenses en rouge, le wallet en bleu
- Les indicateurs de variation utilisent les bonnes couleurs (vert/rouge)
- Les couleurs s'adaptent au mode clair/sombre
- Bon contraste pour l'accessibilité
- Expérience visuelle cohérente et professionnelle

## 📝 NOTES IMPORTANTES

1. **Cohérence** : Utiliser toujours les mêmes couleurs pour les mêmes concepts (vert = ventes, rouge = dépenses).

2. **Accessibilité** : S'assurer que le contraste entre le texte et l'arrière-plan respecte WCAG AA (ratio de 4.5:1 minimum).

3. **Mode sombre** : Toutes les couleurs doivent fonctionner en mode clair et sombre. Utiliser les variables CSS du thème.

4. **Graphiques** : Les couleurs des graphiques doivent être cohérentes avec le reste de l'interface.

5. **Badges** : Utiliser des variantes de Badge appropriées avec les bonnes couleurs de bordure et de fond.

Gérez correctement toutes les couleurs dans la page Analytics selon les spécifications ci-dessus.
```

---

## 📝 NOTES TECHNIQUES

1. **Variables CSS** : Utiliser `hsl(var(--chart-1))` au lieu de couleurs hardcodées permet une meilleure adaptation au thème.

2. **Cohérence** : Toujours utiliser les mêmes couleurs pour les mêmes concepts dans toute l'application.

3. **Accessibilité** : Vérifier le contraste des couleurs avec un outil comme WebAIM Contrast Checker.

4. **Recharts** : Les couleurs dans Recharts peuvent être définies via des props ou des constantes.

5. **shadcn/ui** : Le système de couleurs de shadcn/ui utilise des variables CSS qui s'adaptent automatiquement au thème.

