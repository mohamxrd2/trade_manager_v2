# 📋 PROMPT POUR CRÉER LA PAGE SETTINGS (PARAMÈTRES)

## 🚀 Copiez ce prompt dans Cursor :

```
Je veux créer une page Settings (Paramètres) complète et moderne pour mon application Next.js. La page existe déjà mais je veux la remplir avec toutes les fonctionnalités suivantes.

## 🎨 DESIGN ET STRUCTURE

Utilisez shadcn/ui pour tous les composants :
- `Card`, `CardHeader`, `CardTitle`, `CardDescription`, `CardContent`
- `Switch` pour les toggle
- `Select` pour les sélecteurs
- `Button` pour les actions
- `Separator` pour diviser les sections
- `Badge` pour les indicateurs
- `Alert`, `AlertDescription` pour les messages
- `Dialog` pour les confirmations
- `Tabs` pour organiser les sections (optionnel)

Layout :
- Page responsive avec padding approprié
- Sections organisées en cartes (`Card`)
- Espacement cohérent entre les sections
- Utilisez le système de thème déjà en place (dark/light mode)

## 📋 SECTIONS À IMPLÉMENTER

### 1️⃣ PARAMÈTRES DE L'INTERFACE ET THÈME

**Section : Apparence**

```typescript
// Composant : ThemeToggle (déjà existant probablement, réutiliser)
// Afficher le mode actuel (Clair/Sombre)
// Toggle pour basculer entre les modes

<Card>
  <CardHeader>
    <CardTitle>Apparence</CardTitle>
    <CardDescription>
      Personnalisez l'apparence de l'application
    </CardDescription>
  </CardHeader>
  <CardContent className="space-y-4">
    <div className="flex items-center justify-between">
      <div className="space-y-0.5">
        <Label>Mode sombre</Label>
        <p className="text-sm text-muted-foreground">
          Activer le thème sombre pour réduire la fatigue visuelle
        </p>
      </div>
      <ThemeToggle /> {/* Réutiliser le composant existant */}
    </div>
  </CardContent>
</Card>
```

**Section : Langue**

```typescript
<Card>
  <CardHeader>
    <CardTitle>Langue de l'interface</CardTitle>
    <CardDescription>
      Choisissez la langue d'affichage de l'application
    </CardDescription>
  </CardHeader>
  <CardContent>
    <Select
      value={language}
      onValueChange={handleLanguageChange}
    >
      <SelectTrigger>
        <SelectValue placeholder="Sélectionner une langue" />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value="fr">Français</SelectItem>
        <SelectItem value="en">English</SelectItem>
        {/* Ajouter d'autres langues si nécessaire */}
      </SelectContent>
    </Select>
  </CardContent>
</Card>
```

### 2️⃣ PARAMÈTRES DE NOTIFICATIONS

**Section : Notifications par email**

```typescript
<Card>
  <CardHeader>
    <CardTitle>Notifications par email</CardTitle>
    <CardDescription>
      Configurez les notifications que vous souhaitez recevoir par email
    </CardDescription>
  </CardHeader>
  <CardContent className="space-y-4">
    <div className="flex items-center justify-between">
      <div className="space-y-0.5">
        <Label>Activer les notifications email</Label>
        <p className="text-sm text-muted-foreground">
          Recevoir des notifications importantes par email
        </p>
      </div>
      <Switch
        checked={emailNotifications}
        onCheckedChange={handleEmailNotificationsChange}
      />
    </div>
    
    <Separator />
    
    <div className="space-y-3">
      <Label>Types de notifications</Label>
      
      <div className="flex items-center justify-between">
        <Label htmlFor="notif-sales" className="font-normal">
          Nouvelles ventes
        </Label>
        <Switch
          id="notif-sales"
          checked={notificationTypes.sales}
          onCheckedChange={(checked) => 
            handleNotificationTypeChange('sales', checked)
          }
          disabled={!emailNotifications}
        />
      </div>
      
      <div className="flex items-center justify-between">
        <Label htmlFor="notif-stock" className="font-normal">
          Alertes de stock faible
        </Label>
        <Switch
          id="notif-stock"
          checked={notificationTypes.lowStock}
          onCheckedChange={(checked) => 
            handleNotificationTypeChange('lowStock', checked)
          }
          disabled={!emailNotifications}
        />
      </div>
      
      <div className="flex items-center justify-between">
        <Label htmlFor="notif-transactions" className="font-normal">
          Nouvelles transactions
        </Label>
        <Switch
          id="notif-transactions"
          checked={notificationTypes.transactions}
          onCheckedChange={(checked) => 
            handleNotificationTypeChange('transactions', checked)
          }
          disabled={!emailNotifications}
        />
      </div>
    </div>
  </CardContent>
</Card>
```

**Section : Notifications push (optionnel)**

```typescript
<Card>
  <CardHeader>
    <CardTitle>Notifications push</CardTitle>
    <CardDescription>
      Recevez des notifications en temps réel dans votre navigateur
    </CardDescription>
  </CardHeader>
  <CardContent>
    <div className="flex items-center justify-between">
      <div className="space-y-0.5">
        <Label>Activer les notifications push</Label>
        <p className="text-sm text-muted-foreground">
          Vous serez invité à autoriser les notifications
        </p>
      </div>
      <Switch
        checked={pushNotifications}
        onCheckedChange={handlePushNotificationsChange}
      />
    </div>
  </CardContent>
</Card>
```

### 3️⃣ PARAMÈTRES D'APPLICATION / FONCTIONNALITÉS

**Section : Fonctionnalités**

```typescript
<Card>
  <CardHeader>
    <CardTitle>Fonctionnalités</CardTitle>
    <CardDescription>
      Activez ou désactivez certaines fonctionnalités de l'application
    </CardDescription>
  </CardHeader>
  <CardContent className="space-y-4">
    <div className="flex items-center justify-between">
      <div className="space-y-0.5">
        <Label>Analytics</Label>
        <p className="text-sm text-muted-foreground">
          Afficher la page Analytics et les statistiques
        </p>
      </div>
      <Switch
        checked={features.analytics}
        onCheckedChange={(checked) => 
          handleFeatureChange('analytics', checked)
        }
      />
    </div>
    
    <div className="flex items-center justify-between">
      <div className="space-y-0.5">
        <Label>Rapports automatiques</Label>
        <p className="text-sm text-muted-foreground">
          Générer des rapports automatiques périodiques
        </p>
      </div>
      <Switch
        checked={features.autoReports}
        onCheckedChange={(checked) => 
          handleFeatureChange('autoReports', checked)
        }
      />
    </div>
  </CardContent>
</Card>
```

**Section : Affichage**

```typescript
<Card>
  <CardHeader>
    <CardTitle>Affichage</CardTitle>
    <CardDescription>
      Personnalisez l'affichage des tableaux et graphiques
    </CardDescription>
  </CardHeader>
  <CardContent className="space-y-4">
    <div className="space-y-2">
      <Label>Densité des tableaux</Label>
      <Select
        value={displaySettings.tableDensity}
        onValueChange={(value) => 
          handleDisplayChange('tableDensity', value)
        }
      >
        <SelectTrigger>
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="compact">Compact</SelectItem>
          <SelectItem value="normal">Normal</SelectItem>
          <SelectItem value="comfortable">Confortable</SelectItem>
        </SelectContent>
      </Select>
    </div>
    
    <div className="space-y-2">
      <Label>Type de graphique par défaut</Label>
      <Select
        value={displaySettings.defaultChartType}
        onValueChange={(value) => 
          handleDisplayChange('defaultChartType', value)
        }
      >
        <SelectTrigger>
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="line">Ligne</SelectItem>
          <SelectItem value="bar">Barres</SelectItem>
          <SelectItem value="area">Aire</SelectItem>
        </SelectContent>
      </Select>
    </div>
  </CardContent>
</Card>
```

**Section : Alertes et seuils**

```typescript
<Card>
  <CardHeader>
    <CardTitle>Alertes et seuils</CardTitle>
    <CardDescription>
      Configurez les seuils pour les alertes de stock et transactions
    </CardDescription>
  </CardHeader>
  <CardContent className="space-y-4">
    <div className="space-y-2">
      <Label htmlFor="low-stock-threshold">
        Seuil de stock faible (%)
      </Label>
      <Input
        id="low-stock-threshold"
        type="number"
        min="0"
        max="100"
        value={thresholds.lowStock}
        onChange={(e) => 
          handleThresholdChange('lowStock', parseInt(e.target.value))
        }
      />
      <p className="text-sm text-muted-foreground">
        Un article sera considéré en stock faible en dessous de ce pourcentage
      </p>
    </div>
    
    <div className="space-y-2">
      <Label htmlFor="transaction-limit">
        Limite d'alerte pour transactions (montant)
      </Label>
      <Input
        id="transaction-limit"
        type="number"
        min="0"
        value={thresholds.transactionLimit}
        onChange={(e) => 
          handleThresholdChange('transactionLimit', parseFloat(e.target.value))
        }
      />
      <p className="text-sm text-muted-foreground">
        Recevez une alerte pour les transactions supérieures à ce montant
      </p>
    </div>
  </CardContent>
</Card>
```

**Section : Devise**

```typescript
<Card>
  <CardHeader>
    <CardTitle>Devise</CardTitle>
    <CardDescription>
      Sélectionnez la devise d'affichage pour tous les montants
    </CardDescription>
  </CardHeader>
  <CardContent>
    <Select
      value={currency}
      onValueChange={handleCurrencyChange}
    >
      <SelectTrigger>
        <SelectValue placeholder="Sélectionner une devise" />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value="FCFA">FCFA (Franc CFA)</SelectItem>
        <SelectItem value="EUR">EUR (Euro)</SelectItem>
        <SelectItem value="USD">USD (Dollar américain)</SelectItem>
        <SelectItem value="XOF">XOF (Franc CFA Ouest)</SelectItem>
        {/* Ajouter d'autres devises si nécessaire */}
      </SelectContent>
    </Select>
  </CardContent>
</Card>
```

### 4️⃣ PARAMÈTRES DE SAUVEGARDE ET EXPORT

**Section : Sauvegarde**

```typescript
<Card>
  <CardHeader>
    <CardTitle>Sauvegarde</CardTitle>
    <CardDescription>
      Configurez la sauvegarde automatique de vos données
    </CardDescription>
  </CardHeader>
  <CardContent className="space-y-4">
    <div className="flex items-center justify-between">
      <div className="space-y-0.5">
        <Label>Sauvegarde automatique</Label>
        <p className="text-sm text-muted-foreground">
          Sauvegarder automatiquement vos données périodiquement
        </p>
      </div>
      <Switch
        checked={autoSave}
        onCheckedChange={handleAutoSaveChange}
      />
    </div>
    
    {autoSave && (
      <div className="space-y-2">
        <Label>Fréquence de sauvegarde</Label>
        <Select
          value={saveFrequency}
          onValueChange={handleSaveFrequencyChange}
        >
          <SelectTrigger>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="daily">Quotidienne</SelectItem>
            <SelectItem value="weekly">Hebdomadaire</SelectItem>
            <SelectItem value="monthly">Mensuelle</SelectItem>
          </SelectContent>
        </Select>
      </div>
    )}
  </CardContent>
</Card>
```

**Section : Export**

```typescript
<Card>
  <CardHeader>
    <CardTitle>Export des données</CardTitle>
    <CardDescription>
      Exportez vos données dans différents formats
    </CardDescription>
  </CardHeader>
  <CardContent className="space-y-4">
    <div className="space-y-2">
      <Label>Exporter les transactions</Label>
      <div className="flex gap-2">
        <Button
          variant="outline"
          onClick={handleExportTransactionsCSV}
        >
          <Download className="mr-2 h-4 w-4" />
          Exporter en CSV
        </Button>
        <Button
          variant="outline"
          onClick={handleExportTransactionsExcel}
        >
          <Download className="mr-2 h-4 w-4" />
          Exporter en Excel
        </Button>
      </div>
    </div>
    
    <Separator />
    
    <div className="space-y-2">
      <Label>Exporter les produits</Label>
      <div className="flex gap-2">
        <Button
          variant="outline"
          onClick={handleExportProductsCSV}
        >
          <Download className="mr-2 h-4 w-4" />
          Exporter en CSV
        </Button>
        <Button
          variant="outline"
          onClick={handleExportProductsExcel}
        >
          <Download className="mr-2 h-4 w-4" />
          Exporter en Excel
        </Button>
      </div>
    </div>
  </CardContent>
</Card>
```

**Section : Réinitialisation**

```typescript
<Card>
  <CardHeader>
    <CardTitle>Réinitialisation</CardTitle>
    <CardDescription>
      Réinitialisez vos paramètres ou vos données
    </CardDescription>
  </CardHeader>
  <CardContent className="space-y-4">
    <Alert>
      <AlertCircle className="h-4 w-4" />
      <AlertTitle>Attention</AlertTitle>
      <AlertDescription>
        Ces actions sont irréversibles. Assurez-vous d'avoir sauvegardé vos données importantes.
      </AlertDescription>
    </Alert>
    
    <div className="space-y-2">
      <Label>Réinitialiser les paramètres</Label>
      <p className="text-sm text-muted-foreground">
        Remettre tous les paramètres à leurs valeurs par défaut
      </p>
      <Button
        variant="outline"
        onClick={handleResetSettings}
      >
        <RotateCcw className="mr-2 h-4 w-4" />
        Réinitialiser les paramètres
      </Button>
    </div>
    
    <Separator />
    
    <div className="space-y-2">
      <Label className="text-destructive">Réinitialiser les données</Label>
      <p className="text-sm text-muted-foreground">
        Supprimer toutes les données (transactions, produits, etc.)
      </p>
      <Button
        variant="destructive"
        onClick={handleResetData}
      >
        <Trash2 className="mr-2 h-4 w-4" />
        Réinitialiser toutes les données
      </Button>
    </div>
  </CardContent>
</Card>
```

### 5️⃣ AIDE ET SUPPORT

**Section : Ressources**

```typescript
<Card>
  <CardHeader>
    <CardTitle>Aide et support</CardTitle>
    <CardDescription>
      Accédez à la documentation et au support
    </CardDescription>
  </CardHeader>
  <CardContent className="space-y-4">
    <div className="space-y-2">
      <Button
        variant="outline"
        className="w-full justify-start"
        onClick={() => window.open('/docs', '_blank')}
      >
        <BookOpen className="mr-2 h-4 w-4" />
        Documentation
      </Button>
      
      <Button
        variant="outline"
        className="w-full justify-start"
        onClick={() => window.open('/support', '_blank')}
      >
        <MessageSquare className="mr-2 h-4 w-4" />
        Support / Contact
      </Button>
      
      <Button
        variant="outline"
        className="w-full justify-start"
        onClick={() => window.open('/faq', '_blank')}
      >
        <HelpCircle className="mr-2 h-4 w-4" />
        FAQ
      </Button>
      
      <Button
        variant="outline"
        className="w-full justify-start"
        onClick={() => window.open('/tutorials', '_blank')}
      >
        <PlayCircle className="mr-2 h-4 w-4" />
        Tutoriels rapides
      </Button>
    </div>
  </CardContent>
</Card>
```

## 🔧 GESTION D'ÉTAT

Utilisez `useState` et `useEffect` pour gérer les paramètres :

```typescript
'use client';

import { useState, useEffect } from 'react';
import { useToast } from '@/hooks/use-toast';

export default function SettingsPage() {
  const { toast } = useToast();
  
  // État pour tous les paramètres
  const [settings, setSettings] = useState({
    // Interface
    theme: 'light', // ou utiliser le hook useTheme de next-themes
    language: 'fr',
    
    // Notifications
    emailNotifications: false,
    notificationTypes: {
      sales: false,
      lowStock: false,
      transactions: false,
    },
    pushNotifications: false,
    
    // Fonctionnalités
    features: {
      analytics: true,
      autoReports: false,
    },
    
    // Affichage
    displaySettings: {
      tableDensity: 'normal',
      defaultChartType: 'line',
    },
    
    // Seuils
    thresholds: {
      lowStock: 20,
      transactionLimit: 10000,
    },
    
    // Devise
    currency: 'FCFA',
    
    // Sauvegarde
    autoSave: false,
    saveFrequency: 'weekly',
  });
  
  // Charger les paramètres depuis localStorage ou API
  useEffect(() => {
    const savedSettings = localStorage.getItem('app-settings');
    if (savedSettings) {
      try {
        setSettings(JSON.parse(savedSettings));
      } catch (error) {
        console.error('Erreur lors du chargement des paramètres:', error);
      }
    }
  }, []);
  
  // Sauvegarder les paramètres dans localStorage
  useEffect(() => {
    localStorage.setItem('app-settings', JSON.stringify(settings));
  }, [settings]);
  
  // Handlers
  const handleEmailNotificationsChange = (checked: boolean) => {
    setSettings(prev => ({
      ...prev,
      emailNotifications: checked,
    }));
    toast({
      title: checked ? 'Notifications email activées' : 'Notifications email désactivées',
    });
  };
  
  const handleNotificationTypeChange = (type: string, checked: boolean) => {
    setSettings(prev => ({
      ...prev,
      notificationTypes: {
        ...prev.notificationTypes,
        [type]: checked,
      },
    }));
  };
  
  const handleCurrencyChange = (value: string) => {
    setSettings(prev => ({
      ...prev,
      currency: value,
    }));
    toast({
      title: `Devise changée: ${value}`,
    });
  };
  
  const handleResetSettings = () => {
    // Afficher un Dialog de confirmation avant de réinitialiser
    // Puis réinitialiser aux valeurs par défaut
    setSettings({
      theme: 'light',
      language: 'fr',
      emailNotifications: false,
      notificationTypes: {
        sales: false,
        lowStock: false,
        transactions: false,
      },
      pushNotifications: false,
      features: {
        analytics: true,
        autoReports: false,
      },
      displaySettings: {
        tableDensity: 'normal',
        defaultChartType: 'line',
      },
      thresholds: {
        lowStock: 20,
        transactionLimit: 10000,
      },
      currency: 'FCFA',
      autoSave: false,
      saveFrequency: 'weekly',
    });
    toast({
      title: 'Paramètres réinitialisés',
    });
  };
  
  const handleResetData = async () => {
    // Afficher un Dialog de confirmation
    // Appeler l'API pour supprimer toutes les données
    try {
      // await api.delete('/api/reset-data');
      toast({
        title: 'Données réinitialisées',
        variant: 'destructive',
      });
    } catch (error) {
      toast({
        title: 'Erreur',
        description: 'Impossible de réinitialiser les données',
        variant: 'destructive',
      });
    }
  };
  
  const handleExportTransactionsCSV = async () => {
    try {
      // Appeler l'API pour exporter
      // const response = await api.get('/api/transactions/export', { params: { format: 'csv' } });
      // Télécharger le fichier
      toast({
        title: 'Export réussi',
        description: 'Vos transactions ont été exportées',
      });
    } catch (error) {
      toast({
        title: 'Erreur',
        description: 'Impossible d\'exporter les transactions',
        variant: 'destructive',
      });
    }
  };
  
  // ... autres handlers
  
  return (
    <div className="container mx-auto py-6 space-y-6">
      <div>
        <h1 className="text-3xl font-bold">Paramètres</h1>
        <p className="text-muted-foreground">
          Gérez vos préférences et paramètres de l'application
        </p>
      </div>
      
      {/* Toutes les sections de cartes ici */}
    </div>
  );
}
```

## 📦 IMPORTS NÉCESSAIRES

```typescript
import { useState, useEffect } from 'react';
import { useTheme } from 'next-themes';
import { useToast } from '@/hooks/use-toast';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import { Label } from '@/components/ui/label';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import {
  Alert,
  AlertDescription,
  AlertTitle,
} from '@/components/ui/alert';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  Download,
  RotateCcw,
  Trash2,
  BookOpen,
  MessageSquare,
  HelpCircle,
  PlayCircle,
  AlertCircle,
} from 'lucide-react';
```

## ✅ FONCTIONNALITÉS À IMPLÉMENTER

1. **Persistance** : Sauvegarder tous les paramètres dans `localStorage` ou via une API
2. **Thème** : Intégrer avec `next-themes` si déjà installé
3. **Confirmations** : Utiliser `Dialog` pour les actions destructives (réinitialisation)
4. **Toast** : Afficher des notifications pour chaque changement
5. **Validation** : Valider les inputs (seuils, limites)
6. **Export** : Implémenter les fonctions d'export (appeler les APIs backend)
7. **Responsive** : S'assurer que la page est responsive sur mobile

## 🎨 STYLE ET UX

- Utiliser les couleurs du thème (dark/light)
- Espacement cohérent entre les sections
- Labels clairs et descriptions utiles
- Indicateurs visuels pour les états (actif/inactif)
- Animations subtiles pour les transitions
- États de chargement pour les exports

## 📝 NOTES IMPORTANTES

1. **API Backend** : Vous devrez créer les endpoints API pour :
   - Sauvegarder/charger les paramètres
   - Exporter les données (CSV, Excel)
   - Réinitialiser les données

2. **Thème** : Si vous utilisez `next-themes`, réutilisez le composant `ThemeToggle` existant

3. **Notifications Push** : Implémenter la demande de permission pour les notifications push du navigateur

4. **Export** : Les fonctions d'export devront télécharger les fichiers générés par le backend

5. **Sécurité** : Pour la réinitialisation des données, ajouter une confirmation avec mot de passe ou double confirmation

Créez cette page Settings complète avec toutes les sections demandées, en utilisant shadcn/ui et en suivant les meilleures pratiques React/Next.js.
```

---

## 📝 NOTES TECHNIQUES

1. **Persistance** : Les paramètres peuvent être sauvegardés dans `localStorage` pour une persistance locale, ou via une API pour une synchronisation multi-appareils.

2. **Thème** : Si `next-themes` est déjà installé, réutiliser le hook `useTheme()` et le composant `ThemeToggle` existant.

3. **API Endpoints** : Vous devrez créer les endpoints backend pour :
   - `GET /api/settings` - Récupérer les paramètres
   - `PUT /api/settings` - Sauvegarder les paramètres
   - `POST /api/export/transactions` - Exporter les transactions
   - `POST /api/export/products` - Exporter les produits
   - `POST /api/reset-data` - Réinitialiser les données

4. **Validation** : Ajouter une validation pour les seuils (0-100 pour les pourcentages, valeurs positives pour les montants).

5. **Confirmations** : Utiliser des `Dialog` avec confirmation pour les actions destructives.

