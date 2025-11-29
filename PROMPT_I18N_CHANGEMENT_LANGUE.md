# 📋 PROMPT POUR IMPLÉMENTER LE CHANGEMENT DE LANGUE (i18n)

## 🚀 Copiez ce prompt dans Cursor :

```
Je veux implémenter un système de changement de langue (i18n) dans mon application Next.js. La langue par défaut est le français, et je veux pouvoir basculer vers l'anglais. Seuls les textes statiques (non dynamiques) de l'interface doivent changer.

## 🎯 OBJECTIFS

1. Créer un système de traduction simple avec fichiers JSON
2. Français par défaut
3. Possibilité de basculer vers l'anglais
4. Sauvegarder la préférence dans localStorage
5. Appliquer les traductions dans toute l'application

## 📁 STRUCTURE DES FICHIERS

Créer la structure suivante :

```
lib/
  i18n/
    translations/
      fr.json
      en.json
    context/
      LanguageContext.tsx
    hooks/
      useTranslation.ts
```

## 🔧 ÉTAPE 1 : CRÉER LES FICHIERS DE TRADUCTION

### `lib/i18n/translations/fr.json`

```json
{
  "common": {
    "save": "Enregistrer",
    "cancel": "Annuler",
    "delete": "Supprimer",
    "edit": "Modifier",
    "add": "Ajouter",
    "search": "Rechercher",
    "filter": "Filtrer",
    "export": "Exporter",
    "import": "Importer",
    "loading": "Chargement...",
    "error": "Erreur",
    "success": "Succès",
    "confirm": "Confirmer",
    "close": "Fermer"
  },
  "nav": {
    "dashboard": "Tableau de bord",
    "products": "Produits",
    "transactions": "Transactions",
    "wallet": "Portefeuille",
    "analytics": "Statistiques",
    "collaborators": "Collaborateurs",
    "settings": "Paramètres"
  },
  "settings": {
    "title": "Paramètres",
    "description": "Gérez vos préférences et paramètres de l'application",
    "appearance": {
      "title": "Apparence",
      "description": "Personnalisez l'apparence de l'application",
      "darkMode": "Mode sombre",
      "darkModeDescription": "Activer le thème sombre pour réduire la fatigue visuelle",
      "language": "Langue de l'interface",
      "languageDescription": "Choisissez la langue d'affichage de l'application"
    },
    "notifications": {
      "title": "Notifications par email",
      "description": "Configurez les notifications que vous souhaitez recevoir par email",
      "enable": "Activer les notifications email",
      "enableDescription": "Recevoir des notifications importantes par email",
      "types": "Types de notifications",
      "sales": "Nouvelles ventes",
      "lowStock": "Alertes de stock faible",
      "transactions": "Nouvelles transactions"
    },
    "features": {
      "title": "Fonctionnalités",
      "description": "Activez ou désactivez certaines fonctionnalités de l'application",
      "analytics": "Analytics",
      "analyticsDescription": "Afficher la page Analytics et les statistiques",
      "autoReports": "Rapports automatiques",
      "autoReportsDescription": "Générer des rapports automatiques périodiques"
    },
    "display": {
      "title": "Affichage",
      "description": "Personnalisez l'affichage des tableaux et graphiques",
      "tableDensity": "Densité des tableaux",
      "defaultChartType": "Type de graphique par défaut",
      "compact": "Compact",
      "normal": "Normal",
      "comfortable": "Confortable",
      "line": "Ligne",
      "bar": "Barres",
      "area": "Aire"
    },
    "alerts": {
      "title": "Alertes et seuils",
      "description": "Configurez les seuils pour les alertes de stock et transactions",
      "lowStockThreshold": "Seuil de stock faible (%)",
      "lowStockDescription": "Un article sera considéré en stock faible en dessous de ce pourcentage",
      "transactionLimit": "Limite d'alerte pour transactions (montant)",
      "transactionLimitDescription": "Recevez une alerte pour les transactions supérieures à ce montant"
    },
    "currency": {
      "title": "Devise",
      "description": "Sélectionnez la devise d'affichage pour tous les montants"
    },
    "backup": {
      "title": "Sauvegarde",
      "description": "Configurez la sauvegarde automatique de vos données",
      "autoSave": "Sauvegarde automatique",
      "autoSaveDescription": "Sauvegarder automatiquement vos données périodiquement",
      "frequency": "Fréquence de sauvegarde",
      "daily": "Quotidienne",
      "weekly": "Hebdomadaire",
      "monthly": "Mensuelle"
    },
    "export": {
      "title": "Export des données",
      "description": "Exportez vos données dans différents formats",
      "exportTransactions": "Exporter les transactions",
      "exportProducts": "Exporter les produits",
      "exportCSV": "Exporter en CSV",
      "exportExcel": "Exporter en Excel"
    },
    "reset": {
      "title": "Réinitialisation",
      "description": "Réinitialisez vos paramètres ou vos données",
      "warning": "Attention",
      "warningDescription": "Ces actions sont irréversibles. Assurez-vous d'avoir sauvegardé vos données importantes.",
      "resetSettings": "Réinitialiser les paramètres",
      "resetSettingsDescription": "Remettre tous les paramètres à leurs valeurs par défaut",
      "resetData": "Réinitialiser les données",
      "resetDataDescription": "Supprimer toutes les données (transactions, produits, etc.)"
    },
    "support": {
      "title": "Aide et support",
      "description": "Accédez à la documentation et au support",
      "documentation": "Documentation",
      "contact": "Support / Contact",
      "faq": "FAQ",
      "tutorials": "Tutoriels rapides"
    }
  },
  "dashboard": {
    "title": "Tableau de bord",
    "welcome": "Bienvenue",
    "totalSales": "Ventes totales",
    "totalExpenses": "Dépenses totales",
    "wallet": "Portefeuille",
    "lowStock": "Stock faible"
  },
  "products": {
    "title": "Produits",
    "addProduct": "Ajouter un produit",
    "editProduct": "Modifier le produit",
    "deleteProduct": "Supprimer le produit",
    "name": "Nom",
    "price": "Prix",
    "quantity": "Quantité",
    "type": "Type",
    "simple": "Simple",
    "variable": "Variable",
    "category": "Catégorie",
    "actions": "Actions"
  },
  "transactions": {
    "title": "Transactions",
    "addTransaction": "Ajouter une transaction",
    "editTransaction": "Modifier la transaction",
    "deleteTransaction": "Supprimer la transaction",
    "type": "Type",
    "sale": "Vente",
    "expense": "Dépense",
    "amount": "Montant",
    "date": "Date",
    "article": "Article",
    "quantity": "Quantité",
    "price": "Prix"
  },
  "wallet": {
    "title": "Portefeuille",
    "totalSales": "Ventes totales",
    "totalExpenses": "Dépenses totales",
    "calculatedWallet": "Portefeuille calculé",
    "wallet": "Portefeuille"
  },
  "analytics": {
    "title": "Statistiques",
    "overview": "Aperçu des performances globales",
    "trends": "Graphiques de tendances",
    "categoryAnalysis": "Analyse par catégorie",
    "comparisons": "Comparaisons temporelles",
    "kpis": "Ratios financiers & indicateurs clés",
    "transactions": "Tableau détaillé filtrable",
    "predictions": "Prédictions de Réapprovisionnement",
    "period": "Période",
    "today": "Aujourd'hui",
    "week": "7 derniers jours",
    "month": "30 derniers jours",
    "year": "Cette année",
    "all": "Depuis toujours",
    "custom": "Personnalisé"
  },
  "collaborators": {
    "title": "Collaborateurs",
    "addCollaborator": "Ajouter un collaborateur",
    "editCollaborator": "Modifier le collaborateur",
    "deleteCollaborator": "Supprimer le collaborateur",
    "name": "Nom",
    "email": "Email",
    "wallet": "Portefeuille",
    "actions": "Actions"
  }
}
```

### `lib/i18n/translations/en.json`

```json
{
  "common": {
    "save": "Save",
    "cancel": "Cancel",
    "delete": "Delete",
    "edit": "Edit",
    "add": "Add",
    "search": "Search",
    "filter": "Filter",
    "export": "Export",
    "import": "Import",
    "loading": "Loading...",
    "error": "Error",
    "success": "Success",
    "confirm": "Confirm",
    "close": "Close"
  },
  "nav": {
    "dashboard": "Dashboard",
    "products": "Products",
    "transactions": "Transactions",
    "wallet": "Wallet",
    "analytics": "Analytics",
    "collaborators": "Collaborators",
    "settings": "Settings"
  },
  "settings": {
    "title": "Settings",
    "description": "Manage your application preferences and settings",
    "appearance": {
      "title": "Appearance",
      "description": "Customize the appearance of the application",
      "darkMode": "Dark mode",
      "darkModeDescription": "Enable dark theme to reduce eye strain",
      "language": "Interface language",
      "languageDescription": "Choose the display language of the application"
    },
    "notifications": {
      "title": "Email notifications",
      "description": "Configure the notifications you want to receive by email",
      "enable": "Enable email notifications",
      "enableDescription": "Receive important notifications by email",
      "types": "Notification types",
      "sales": "New sales",
      "lowStock": "Low stock alerts",
      "transactions": "New transactions"
    },
    "features": {
      "title": "Features",
      "description": "Enable or disable certain application features",
      "analytics": "Analytics",
      "analyticsDescription": "Display Analytics page and statistics",
      "autoReports": "Automatic reports",
      "autoReportsDescription": "Generate periodic automatic reports"
    },
    "display": {
      "title": "Display",
      "description": "Customize the display of tables and charts",
      "tableDensity": "Table density",
      "defaultChartType": "Default chart type",
      "compact": "Compact",
      "normal": "Normal",
      "comfortable": "Comfortable",
      "line": "Line",
      "bar": "Bars",
      "area": "Area"
    },
    "alerts": {
      "title": "Alerts and thresholds",
      "description": "Configure thresholds for stock and transaction alerts",
      "lowStockThreshold": "Low stock threshold (%)",
      "lowStockDescription": "An item will be considered low stock below this percentage",
      "transactionLimit": "Transaction alert limit (amount)",
      "transactionLimitDescription": "Receive an alert for transactions above this amount"
    },
    "currency": {
      "title": "Currency",
      "description": "Select the display currency for all amounts"
    },
    "backup": {
      "title": "Backup",
      "description": "Configure automatic backup of your data",
      "autoSave": "Automatic backup",
      "autoSaveDescription": "Automatically backup your data periodically",
      "frequency": "Backup frequency",
      "daily": "Daily",
      "weekly": "Weekly",
      "monthly": "Monthly"
    },
    "export": {
      "title": "Data export",
      "description": "Export your data in different formats",
      "exportTransactions": "Export transactions",
      "exportProducts": "Export products",
      "exportCSV": "Export to CSV",
      "exportExcel": "Export to Excel"
    },
    "reset": {
      "title": "Reset",
      "description": "Reset your settings or data",
      "warning": "Warning",
      "warningDescription": "These actions are irreversible. Make sure you have saved your important data.",
      "resetSettings": "Reset settings",
      "resetSettingsDescription": "Reset all settings to their default values",
      "resetData": "Reset data",
      "resetDataDescription": "Delete all data (transactions, products, etc.)"
    },
    "support": {
      "title": "Help and support",
      "description": "Access documentation and support",
      "documentation": "Documentation",
      "contact": "Support / Contact",
      "faq": "FAQ",
      "tutorials": "Quick tutorials"
    }
  },
  "dashboard": {
    "title": "Dashboard",
    "welcome": "Welcome",
    "totalSales": "Total sales",
    "totalExpenses": "Total expenses",
    "wallet": "Wallet",
    "lowStock": "Low stock"
  },
  "products": {
    "title": "Products",
    "addProduct": "Add product",
    "editProduct": "Edit product",
    "deleteProduct": "Delete product",
    "name": "Name",
    "price": "Price",
    "quantity": "Quantity",
    "type": "Type",
    "simple": "Simple",
    "variable": "Variable",
    "category": "Category",
    "actions": "Actions"
  },
  "transactions": {
    "title": "Transactions",
    "addTransaction": "Add transaction",
    "editTransaction": "Edit transaction",
    "deleteTransaction": "Delete transaction",
    "type": "Type",
    "sale": "Sale",
    "expense": "Expense",
    "amount": "Amount",
    "date": "Date",
    "article": "Article",
    "quantity": "Quantity",
    "price": "Price"
  },
  "wallet": {
    "title": "Wallet",
    "totalSales": "Total sales",
    "totalExpenses": "Total expenses",
    "calculatedWallet": "Calculated wallet",
    "wallet": "Wallet"
  },
  "analytics": {
    "title": "Analytics",
    "overview": "Global performance overview",
    "trends": "Trend charts",
    "categoryAnalysis": "Category analysis",
    "comparisons": "Temporal comparisons",
    "kpis": "Financial ratios & key indicators",
    "transactions": "Detailed filterable table",
    "predictions": "Reorder Predictions",
    "period": "Period",
    "today": "Today",
    "week": "Last 7 days",
    "month": "Last 30 days",
    "year": "This year",
    "all": "All time",
    "custom": "Custom"
  },
  "collaborators": {
    "title": "Collaborators",
    "addCollaborator": "Add collaborator",
    "editCollaborator": "Edit collaborator",
    "deleteCollaborator": "Delete collaborator",
    "name": "Name",
    "email": "Email",
    "wallet": "Wallet",
    "actions": "Actions"
  }
}
```

## 🔧 ÉTAPE 2 : CRÉER LE CONTEXTE DE LANGUE

### `lib/i18n/context/LanguageContext.tsx`

```typescript
'use client';

import React, { createContext, useContext, useState, useEffect, ReactNode } from 'react';

type Language = 'fr' | 'en';

interface LanguageContextType {
  language: Language;
  setLanguage: (lang: Language) => void;
}

const LanguageContext = createContext<LanguageContextType | undefined>(undefined);

export function LanguageProvider({ children }: { children: ReactNode }) {
  const [language, setLanguageState] = useState<Language>('fr');

  // Charger la langue depuis localStorage au montage
  useEffect(() => {
    const savedLanguage = localStorage.getItem('app-language') as Language;
    if (savedLanguage && (savedLanguage === 'fr' || savedLanguage === 'en')) {
      setLanguageState(savedLanguage);
    }
  }, []);

  // Sauvegarder la langue dans localStorage quand elle change
  const setLanguage = (lang: Language) => {
    setLanguageState(lang);
    localStorage.setItem('app-language', lang);
    // Optionnel : recharger la page pour appliquer les changements partout
    // window.location.reload();
  };

  return (
    <LanguageContext.Provider value={{ language, setLanguage }}>
      {children}
    </LanguageContext.Provider>
  );
}

export function useLanguage() {
  const context = useContext(LanguageContext);
  if (context === undefined) {
    throw new Error('useLanguage must be used within a LanguageProvider');
  }
  return context;
}
```

## 🔧 ÉTAPE 3 : CRÉER LE HOOK DE TRADUCTION

### `lib/i18n/hooks/useTranslation.ts`

```typescript
'use client';

import { useLanguage } from '../context/LanguageContext';
import frTranslations from '../translations/fr.json';
import enTranslations from '../translations/en.json';

type TranslationKey = string;
type TranslationObject = Record<string, any>;

const translations: Record<'fr' | 'en', TranslationObject> = {
  fr: frTranslations,
  en: enTranslations,
};

export function useTranslation() {
  const { language } = useLanguage();

  const t = (key: TranslationKey, params?: Record<string, string | number>): string => {
    const keys = key.split('.');
    let value: any = translations[language];

    // Naviguer dans l'objet de traduction
    for (const k of keys) {
      if (value && typeof value === 'object' && k in value) {
        value = value[k];
      } else {
        // Si la clé n'existe pas, retourner la clé elle-même
        console.warn(`Translation key not found: ${key}`);
        return key;
      }
    }

    // Si la valeur finale est une chaîne, remplacer les paramètres
    if (typeof value === 'string' && params) {
      return value.replace(/\{\{(\w+)\}\}/g, (match, paramKey) => {
        return params[paramKey]?.toString() || match;
      });
    }

    return typeof value === 'string' ? value : key;
  };

  return { t, language };
}
```

## 🔧 ÉTAPE 4 : ENROBER L'APPLICATION AVEC LE PROVIDER

### `app/layout.tsx` ou `app/providers.tsx`

```typescript
import { LanguageProvider } from '@/lib/i18n/context/LanguageContext';

export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html lang="fr" suppressHydrationWarning>
      <body>
        <LanguageProvider>
          {children}
        </LanguageProvider>
      </body>
    </html>
  );
}
```

## 🔧 ÉTAPE 5 : UTILISER LES TRADUCTIONS DANS LES COMPOSANTS

### Exemple dans un composant

```typescript
'use client';

import { useTranslation } from '@/lib/i18n/hooks/useTranslation';
import { Button } from '@/components/ui/button';

export function MyComponent() {
  const { t } = useTranslation();

  return (
    <div>
      <h1>{t('dashboard.title')}</h1>
      <Button>{t('common.save')}</Button>
      <p>{t('dashboard.welcome')}</p>
    </div>
  );
}
```

## 🔧 ÉTAPE 6 : INTÉGRER LE SÉLECTEUR DE LANGUE DANS LA PAGE SETTINGS

### Dans `app/settings/page.tsx`

```typescript
'use client';

import { useTranslation } from '@/lib/i18n/hooks/useTranslation';
import { useLanguage } from '@/lib/i18n/context/LanguageContext';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';

export default function SettingsPage() {
  const { t } = useTranslation();
  const { language, setLanguage } = useLanguage();

  return (
    <div className="container mx-auto py-6 space-y-6">
      <div>
        <h1 className="text-3xl font-bold">{t('settings.title')}</h1>
        <p className="text-muted-foreground">
          {t('settings.description')}
        </p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>{t('settings.appearance.title')}</CardTitle>
          <CardDescription>
            {t('settings.appearance.description')}
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div className="space-y-2">
            <Label>{t('settings.appearance.language')}</Label>
            <Select
              value={language}
              onValueChange={(value: 'fr' | 'en') => {
                setLanguage(value);
                // Optionnel : recharger la page pour appliquer immédiatement
                // window.location.reload();
              }}
            >
              <SelectTrigger>
                <SelectValue placeholder={t('settings.appearance.language')} />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="fr">Français</SelectItem>
                <SelectItem value="en">English</SelectItem>
              </SelectContent>
            </Select>
            <p className="text-sm text-muted-foreground">
              {t('settings.appearance.languageDescription')}
            </p>
          </div>
        </CardContent>
      </Card>

      {/* Autres sections de paramètres */}
    </div>
  );
}
```

## 🔧 ÉTAPE 7 : UTILISER LES TRADUCTIONS DANS LA NAVIGATION

### Exemple dans `components/nav.tsx`

```typescript
'use client';

import { useTranslation } from '@/lib/i18n/hooks/useTranslation';
import Link from 'next/link';

export function Navigation() {
  const { t } = useTranslation();

  return (
    <nav>
      <Link href="/dashboard">{t('nav.dashboard')}</Link>
      <Link href="/products">{t('nav.products')}</Link>
      <Link href="/transactions">{t('nav.transactions')}</Link>
      <Link href="/wallet">{t('nav.wallet')}</Link>
      <Link href="/analytics">{t('nav.analytics')}</Link>
      <Link href="/collaborators">{t('nav.collaborators')}</Link>
      <Link href="/settings">{t('nav.settings')}</Link>
    </nav>
  );
}
```

## 🔧 ÉTAPE 8 : GÉRER LE RE-CHARGEMENT (OPTIONNEL)

Si vous voulez que les changements de langue s'appliquent immédiatement sans recharger la page, vous pouvez utiliser un effet pour forcer le re-render :

```typescript
// Dans LanguageContext.tsx
const setLanguage = (lang: Language) => {
  setLanguageState(lang);
  localStorage.setItem('app-language', lang);
  
  // Option 1 : Recharger la page (simple mais peut être lent)
  // window.location.reload();
  
  // Option 2 : Utiliser un événement personnalisé pour notifier les composants
  window.dispatchEvent(new CustomEvent('language-changed', { detail: lang }));
};
```

## ✅ CHECKLIST D'IMPLÉMENTATION

- [ ] Créer les fichiers de traduction `fr.json` et `en.json`
- [ ] Créer le contexte `LanguageContext.tsx`
- [ ] Créer le hook `useTranslation.ts`
- [ ] Enrober l'application avec `LanguageProvider` dans le layout
- [ ] Ajouter le sélecteur de langue dans la page Settings
- [ ] Remplacer tous les textes statiques par `t('key')` dans les composants
- [ ] Tester le changement de langue
- [ ] Vérifier que la préférence est sauvegardée dans localStorage

## 📝 NOTES IMPORTANTES

1. **Textes dynamiques** : Les textes provenant de la base de données (noms d'articles, descriptions, etc.) ne doivent PAS être traduits via ce système. Seuls les textes statiques de l'interface doivent l'être.

2. **Clés de traduction** : Utilisez une structure hiérarchique avec des points (ex: `settings.appearance.title`) pour une meilleure organisation.

3. **Paramètres** : Le hook `useTranslation` supporte les paramètres avec `{{param}}` dans les traductions :
   ```typescript
   t('common.welcome', { name: 'John' })
   // Dans fr.json: "Bienvenue {{name}}"
   ```

4. **Performance** : Les traductions sont chargées une seule fois au démarrage, donc pas d'impact sur les performances.

5. **Fallback** : Si une clé de traduction n'est pas trouvée, la clé elle-même est retournée (avec un warning en console).

6. **Ajout de nouvelles langues** : Pour ajouter une nouvelle langue, créez un nouveau fichier JSON et ajoutez-le dans le type `Language` et l'objet `translations`.

Implémentez ce système de traduction dans toute l'application en remplaçant tous les textes statiques par des appels à `t('key')`.
```

---

## 📝 NOTES TECHNIQUES

1. **Structure des clés** : Utilisez une structure hiérarchique (ex: `settings.appearance.title`) pour une meilleure organisation.

2. **Paramètres** : Support des paramètres dynamiques avec `{{param}}` dans les traductions.

3. **Persistance** : La langue est sauvegardée dans `localStorage` et chargée au démarrage.

4. **Performance** : Les traductions sont chargées une seule fois, pas d'impact sur les performances.

5. **Extensibilité** : Facile d'ajouter de nouvelles langues en créant de nouveaux fichiers JSON.

