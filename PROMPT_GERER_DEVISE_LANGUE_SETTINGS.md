# 📋 PROMPT POUR GÉRER LA DEVISE, LA LANGUE ET LES SETTINGS

## 🚀 Copiez ce prompt dans Cursor :

```
Je veux implémenter la gestion de la devise, de la langue et des paramètres dans toute l'application. Voici les exigences :

## 🎯 OBJECTIFS

1. **Devise** : Utiliser la devise récupérée via l'API partout dans l'application
2. **Settings** : Permettre de modifier la devise et le seuil de stock faible avec un bouton "Enregistrer"
3. **Langue** : Vérifier la langue via l'API au chargement et permettre de la modifier

## 🔧 IMPLÉMENTATION

### 1. Créer un contexte pour les paramètres utilisateur

Créez `contexts/SettingsContext.tsx` :

```typescript
'use client';

import React, { createContext, useContext, useState, useEffect, useCallback } from 'react';
import { useAuth } from '@/contexts/AuthContext';
import api from '@/lib/api';
import { useToast } from '@/hooks/use-toast';

interface UserSettings {
  currency: string;
  low_stock_threshold: number;
  language: string;
}

interface SettingsContextType {
  settings: UserSettings | null;
  loading: boolean;
  updateSettings: (newSettings: Partial<UserSettings>) => Promise<void>;
  refreshSettings: () => Promise<void>;
}

const SettingsContext = createContext<SettingsContextType | undefined>(undefined);

export function SettingsProvider({ children }: { children: React.ReactNode }) {
  const { user } = useAuth();
  const { toast } = useToast();
  const [settings, setSettings] = useState<UserSettings | null>(null);
  const [loading, setLoading] = useState(true);

  const refreshSettings = useCallback(async () => {
    if (!user) {
      setSettings(null);
      setLoading(false);
      return;
    }

    try {
      const response = await api.get('/api/user/settings');
      if (response.data.success) {
        const settingsData = response.data.data;
        setSettings({
          currency: settingsData.currency || 'FCFA',
          low_stock_threshold: settingsData.low_stock_threshold || 80,
          language: settingsData.language || 'fr',
        });
      }
    } catch (error) {
      console.error('Erreur lors de la récupération des paramètres:', error);
      // Valeurs par défaut en cas d'erreur
      setSettings({
        currency: 'FCFA',
        low_stock_threshold: 80,
        language: 'fr',
      });
    } finally {
      setLoading(false);
    }
  }, [user]);

  const updateSettings = useCallback(async (newSettings: Partial<UserSettings>) => {
    try {
      const response = await api.put('/api/user/settings', newSettings);
      if (response.data.success) {
        setSettings((prev) => ({
          ...prev!,
          ...newSettings,
        }));
        toast({
          title: 'Paramètres mis à jour',
          description: 'Vos paramètres ont été enregistrés avec succès',
        });
      }
    } catch (error: any) {
      toast({
        title: 'Erreur',
        description: error.response?.data?.message || 'Impossible de mettre à jour les paramètres',
        variant: 'destructive',
      });
      throw error;
    }
  }, [toast]);

  // Charger les paramètres au montage et quand l'utilisateur change
  useEffect(() => {
    refreshSettings();
  }, [refreshSettings]);

  return (
    <SettingsContext.Provider value={{ settings, loading, updateSettings, refreshSettings }}>
      {children}
    </SettingsContext.Provider>
  );
}

export function useSettings() {
  const context = useContext(SettingsContext);
  if (context === undefined) {
    throw new Error('useSettings must be used within a SettingsProvider');
  }
  return context;
}
```

### 2. Intégrer le SettingsProvider dans le layout

Dans `app/layout.tsx` :

```typescript
import { AuthProvider } from '@/contexts/AuthContext';
import { SettingsProvider } from '@/contexts/SettingsContext';
import { LanguageProvider } from '@/lib/i18n/context/LanguageContext';

export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html lang="fr" suppressHydrationWarning>
      <body>
        <AuthProvider>
          <SettingsProvider>
            <LanguageProvider>
              {children}
            </LanguageProvider>
          </SettingsProvider>
        </AuthProvider>
      </body>
    </html>
  );
}
```

### 3. Créer un hook pour formater les montants avec la devise

Créez `lib/utils/currency.ts` :

```typescript
import { useSettings } from '@/contexts/SettingsContext';

/**
 * Formate un montant avec la devise de l'utilisateur
 */
export function formatCurrency(amount: number | string, currency?: string): string {
  const numAmount = typeof amount === 'string' ? parseFloat(amount) : amount;
  
  if (isNaN(numAmount)) {
    return '0';
  }

  const currencySymbol = currency || 'FCFA';
  
  // Formater le nombre avec des espaces pour les milliers
  const formattedAmount = new Intl.NumberFormat('fr-FR', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  }).format(numAmount);

  // Retourner selon la devise
  switch (currencySymbol) {
    case 'EUR':
      return `${formattedAmount} €`;
    case 'USD':
      return `$${formattedAmount}`;
    case 'XOF':
      return `${formattedAmount} XOF`;
    case 'FCFA':
    default:
      return `${formattedAmount} FCFA`;
  }
}

/**
 * Hook pour formater les montants avec la devise de l'utilisateur
 */
export function useCurrency() {
  const { settings } = useSettings();
  
  return {
    format: (amount: number | string) => formatCurrency(amount, settings?.currency),
    currency: settings?.currency || 'FCFA',
  };
}
```

### 4. Utiliser la devise partout dans l'application

Exemple dans un composant qui affiche des montants :

```typescript
'use client';

import { useCurrency } from '@/lib/utils/currency';

export function WalletCard() {
  const { format, currency } = useCurrency();
  const wallet = 150000; // Exemple

  return (
    <div>
      <h3>Portefeuille</h3>
      <p>{format(wallet)}</p>
      {/* Affichera : 150 000 FCFA (ou la devise sélectionnée) */}
    </div>
  );
}
```

### 5. Modifier le LanguageContext pour utiliser les settings

Modifiez `lib/i18n/context/LanguageContext.tsx` :

```typescript
'use client';

import React, { createContext, useContext, useState, useEffect, ReactNode } from 'react';
import { useSettings } from '@/contexts/SettingsContext';
import api from '@/lib/api';

type Language = 'fr' | 'en';

interface LanguageContextType {
  language: Language;
  setLanguage: (lang: Language) => void;
}

const LanguageContext = createContext<LanguageContextType | undefined>(undefined);

export function LanguageProvider({ children }: { children: ReactNode }) {
  const { settings, updateSettings } = useSettings();
  const [language, setLanguageState] = useState<Language>('fr');

  // Charger la langue depuis les settings au montage
  useEffect(() => {
    if (settings?.language) {
      setLanguageState(settings.language as Language);
    }
  }, [settings?.language]);

  // Mettre à jour la langue dans les settings
  const setLanguage = async (lang: Language) => {
    setLanguageState(lang);
    
    // Mettre à jour dans les settings via l'API
    try {
      await updateSettings({ language: lang });
      
      // Optionnel : recharger la page pour appliquer la langue partout
      // window.location.reload();
    } catch (error) {
      console.error('Erreur lors de la mise à jour de la langue:', error);
    }
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

### 6. Modifier la page Settings avec un bouton "Enregistrer"

Dans `app/settings/page.tsx` :

```typescript
'use client';

import { useState, useEffect } from 'react';
import { useSettings } from '@/contexts/SettingsContext';
import { useLanguage } from '@/lib/i18n/context/LanguageContext';
import { useTranslation } from '@/lib/i18n/hooks/useTranslation';
import { useToast } from '@/hooks/use-toast';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { Loader2, Save } from 'lucide-react';

export default function SettingsPage() {
  const { t } = useTranslation();
  const { settings, loading: settingsLoading, updateSettings } = useSettings();
  const { language, setLanguage } = useLanguage();
  const { toast } = useToast();
  
  // États locaux pour les modifications (non sauvegardées)
  const [localCurrency, setLocalCurrency] = useState<string>('FCFA');
  const [localLowStockThreshold, setLocalLowStockThreshold] = useState<number>(80);
  const [localLanguage, setLocalLanguage] = useState<string>('fr');
  const [isSaving, setIsSaving] = useState(false);
  const [hasChanges, setHasChanges] = useState(false);

  // Initialiser les valeurs locales depuis les settings
  useEffect(() => {
    if (settings) {
      setLocalCurrency(settings.currency);
      setLocalLowStockThreshold(settings.low_stock_threshold);
      setLocalLanguage(settings.language);
      setHasChanges(false);
    }
  }, [settings]);

  // Vérifier si des changements ont été faits
  useEffect(() => {
    if (settings) {
      const hasChanged = 
        localCurrency !== settings.currency ||
        localLowStockThreshold !== settings.low_stock_threshold ||
        localLanguage !== settings.language;
      setHasChanges(hasChanged);
    }
  }, [localCurrency, localLowStockThreshold, localLanguage, settings]);

  const handleSave = async () => {
    setIsSaving(true);
    try {
      await updateSettings({
        currency: localCurrency,
        low_stock_threshold: localLowStockThreshold,
        language: localLanguage as 'fr' | 'en',
      });
      
      // Mettre à jour la langue dans le contexte
      if (localLanguage !== language) {
        setLanguage(localLanguage as 'fr' | 'en');
      }
      
      setHasChanges(false);
    } catch (error) {
      // L'erreur est déjà gérée dans updateSettings
    } finally {
      setIsSaving(false);
    }
  };

  const handleReset = () => {
    if (settings) {
      setLocalCurrency(settings.currency);
      setLocalLowStockThreshold(settings.low_stock_threshold);
      setLocalLanguage(settings.language);
      setHasChanges(false);
    }
  };

  if (settingsLoading) {
    return (
      <div className="container mx-auto py-6 flex items-center justify-center">
        <Loader2 className="h-8 w-8 animate-spin" />
      </div>
    );
  }

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
        <CardContent className="space-y-6">
          {/* Devise */}
          <div className="space-y-2">
            <Label htmlFor="currency">
              {t('settings.currency.title')} <span className="text-destructive">*</span>
            </Label>
            <Select
              value={localCurrency}
              onValueChange={(value) => {
                setLocalCurrency(value);
              }}
            >
              <SelectTrigger>
                <SelectValue placeholder={t('settings.currency.title')} />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="FCFA">FCFA (Franc CFA)</SelectItem>
                <SelectItem value="EUR">EUR (Euro)</SelectItem>
                <SelectItem value="USD">USD (Dollar américain)</SelectItem>
                <SelectItem value="XOF">XOF (Franc CFA Ouest)</SelectItem>
              </SelectContent>
            </Select>
            <p className="text-sm text-muted-foreground">
              {t('settings.currency.description')}
            </p>
          </div>

          {/* Seuil de stock faible */}
          <div className="space-y-2">
            <Label htmlFor="low_stock_threshold">
              {t('settings.alerts.lowStockThreshold')}
            </Label>
            <Input
              id="low_stock_threshold"
              type="number"
              min="0"
              max="100"
              value={localLowStockThreshold}
              onChange={(e) => {
                const value = parseInt(e.target.value) || 0;
                setLocalLowStockThreshold(Math.min(100, Math.max(0, value)));
              }}
            />
            <p className="text-sm text-muted-foreground">
              {t('settings.alerts.lowStockDescription')}
            </p>
          </div>

          {/* Langue */}
          <div className="space-y-2">
            <Label htmlFor="language">
              {t('settings.appearance.language')}
            </Label>
            <Select
              value={localLanguage}
              onValueChange={(value) => {
                setLocalLanguage(value);
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

          <Separator />

          {/* Boutons d'action */}
          <div className="flex justify-end gap-4">
            {hasChanges && (
              <Button
                variant="outline"
                onClick={handleReset}
                disabled={isSaving}
              >
                Annuler
              </Button>
            )}
            <Button
              onClick={handleSave}
              disabled={!hasChanges || isSaving}
            >
              {isSaving ? (
                <>
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  Enregistrement...
                </>
              ) : (
                <>
                  <Save className="mr-2 h-4 w-4" />
                  Enregistrer les modifications
                </>
              )}
            </Button>
          </div>

          {hasChanges && (
            <div className="text-sm text-amber-600 bg-amber-50 p-3 rounded-md">
              ⚠️ Vous avez des modifications non enregistrées
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
```

### 7. Utiliser la devise dans tous les composants

Exemple dans `components/dashboard/WalletCard.tsx` :

```typescript
'use client';

import { useCurrency } from '@/lib/utils/currency';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

export function WalletCard({ wallet }: { wallet: number }) {
  const { format } = useCurrency();

  return (
    <Card>
      <CardHeader>
        <CardTitle>Portefeuille</CardTitle>
      </CardHeader>
      <CardContent>
        <p className="text-2xl font-bold">{format(wallet)}</p>
      </CardContent>
    </Card>
  );
}
```

Exemple dans `components/transactions/TransactionRow.tsx` :

```typescript
'use client';

import { useCurrency } from '@/lib/utils/currency';

export function TransactionRow({ transaction }: { transaction: any }) {
  const { format } = useCurrency();

  return (
    <tr>
      <td>{transaction.name}</td>
      <td>{format(transaction.amount)}</td>
      {/* ... autres colonnes */}
    </tr>
  );
}
```

### 8. Créer un composant utilitaire pour afficher les montants

Créez `components/ui/CurrencyDisplay.tsx` :

```typescript
'use client';

import { useCurrency } from '@/lib/utils/currency';

interface CurrencyDisplayProps {
  amount: number | string;
  className?: string;
}

export function CurrencyDisplay({ amount, className }: CurrencyDisplayProps) {
  const { format } = useCurrency();

  return <span className={className}>{format(amount)}</span>;
}
```

Utilisation :

```typescript
import { CurrencyDisplay } from '@/components/ui/CurrencyDisplay';

<CurrencyDisplay amount={150000} className="text-2xl font-bold" />
```

## ✅ CHECKLIST D'IMPLÉMENTATION

- [ ] Créer `SettingsContext` et `SettingsProvider`
- [ ] Intégrer `SettingsProvider` dans le layout
- [ ] Créer le hook `useCurrency()` pour formater les montants
- [ ] Modifier `LanguageContext` pour utiliser les settings
- [ ] Modifier la page Settings avec un bouton "Enregistrer"
- [ ] Remplacer tous les affichages de montants par `useCurrency()` ou `CurrencyDisplay`
- [ ] Tester que la devise change partout quand on la modifie
- [ ] Tester que la langue change quand on la modifie
- [ ] Tester que le seuil de stock faible est bien sauvegardé

## 📝 NOTES IMPORTANTES

1. **Devise** : Utiliser `useCurrency()` partout où un montant est affiché
2. **Settings** : Les modifications sont locales jusqu'au clic sur "Enregistrer"
3. **Langue** : La langue est chargée depuis les settings au démarrage
4. **Synchronisation** : Les settings sont récupérés au chargement et après chaque modification
5. **Validation** : Valider les valeurs avant de les envoyer à l'API

Implémentez ces fonctionnalités pour gérer la devise, la langue et les paramètres dans toute l'application.
```

---

## 📝 NOTES TECHNIQUES

1. **SettingsContext** : Gère les paramètres utilisateur (devise, seuil, langue)
2. **useCurrency()** : Hook pour formater les montants avec la devise de l'utilisateur
3. **LanguageContext** : Synchronisé avec les settings pour charger la langue au démarrage
4. **Page Settings** : Modifications locales avec bouton "Enregistrer" pour appliquer les changements
5. **Formatage** : Utiliser `formatCurrency()` ou `CurrencyDisplay` partout où un montant est affiché

