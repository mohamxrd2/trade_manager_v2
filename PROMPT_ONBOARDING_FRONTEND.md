# 📋 PROMPT POUR CRÉER L'ÉCRAN D'ONBOARDING

## 🚀 Copiez ce prompt dans Cursor :

```
Je veux créer un écran d'onboarding qui s'affiche après la création d'un compte (par credentials ou réseaux sociaux). Cet écran doit permettre de collecter les informations de l'entreprise et les paramètres utilisateur.

## 🎯 OBJECTIFS

1. Afficher l'écran d'onboarding après l'inscription/connexion sociale
2. Collecter les informations de l'entreprise (nom, secteur, siège social, email, statut juridique, N° compte bancaire, logo)
3. Collecter les paramètres utilisateur (devise, seuil de stock faible, langue)
4. Valider et soumettre les données au backend
5. Rediriger vers le dashboard après complétion

## 📋 STRUCTURE DE L'ÉCRAN

L'écran d'onboarding doit être divisé en deux sections principales :

### Section 1 : Informations de l'entreprise
- Nom de l'entreprise (obligatoire)
- Secteur d'activité (optionnel)
- Siège social (optionnel)
- Email de l'entreprise (optionnel)
- Statut juridique (optionnel)
- N° Compte bancaire (optionnel)
- Logo de l'entreprise (optionnel, upload d'image)

### Section 2 : Paramètres
- Devise (obligatoire, sélection : FCFA, EUR, USD, XOF)
- Seuil de stock faible (optionnel, par défaut 80%)
- Langue (optionnel, par défaut français)

## 🔧 IMPLÉMENTATION

### 1. Créer le composant Onboarding

```typescript
'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import * as z from 'zod';
import { useToast } from '@/hooks/use-toast';
import api from '@/lib/api'; // Votre instance axios configurée

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
import { Loader2 } from 'lucide-react';

// Schéma de validation
const onboardingSchema = z.object({
  // Informations de l'entreprise
  company_name: z.string().min(1, 'Le nom de l\'entreprise est obligatoire'),
  company_sector: z.string().optional(),
  company_headquarters: z.string().optional(),
  company_email: z.string().email('Email invalide').optional().or(z.literal('')),
  company_legal_status: z.string().optional(),
  company_bank_account_number: z.string().optional(),
  company_logo: z.string().optional(),
  
  // Paramètres
  currency: z.enum(['FCFA', 'EUR', 'USD', 'XOF'], {
    required_error: 'La devise est obligatoire',
  }),
  low_stock_threshold: z.number().min(0).max(100).optional(),
  language: z.enum(['fr', 'en']).optional(),
});

type OnboardingFormData = z.infer<typeof onboardingSchema>;

export default function OnboardingPage() {
  const router = useRouter();
  const { toast } = useToast();
  const [isSubmitting, setIsSubmitting] = useState(false);

  const {
    register,
    handleSubmit,
    formState: { errors },
    setValue,
    watch,
  } = useForm<OnboardingFormData>({
    resolver: zodResolver(onboardingSchema),
    defaultValues: {
      currency: 'FCFA',
      low_stock_threshold: 80,
      language: 'fr',
    },
  });

  const onSubmit = async (data: OnboardingFormData) => {
    setIsSubmitting(true);
    try {
      const response = await api.post('/api/onboarding/complete', {
        company_name: data.company_name,
        company_sector: data.company_sector || null,
        company_headquarters: data.company_headquarters || null,
        company_email: data.company_email || null,
        company_legal_status: data.company_legal_status || null,
        company_bank_account_number: data.company_bank_account_number || null,
        company_logo: data.company_logo || null,
        currency: data.currency,
        low_stock_threshold: data.low_stock_threshold || 80,
        language: data.language || 'fr',
      });

      if (response.data.success) {
        toast({
          title: 'Onboarding complété',
          description: 'Vos informations ont été enregistrées avec succès',
        });
        
        // Rediriger vers le dashboard
        router.push('/dashboard');
      }
    } catch (error: any) {
      toast({
        title: 'Erreur',
        description: error.response?.data?.message || 'Une erreur est survenue',
        variant: 'destructive',
      });
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center bg-background p-4">
      <Card className="w-full max-w-2xl">
        <CardHeader>
          <CardTitle className="text-2xl">Configuration initiale</CardTitle>
          <CardDescription>
            Complétez votre profil pour commencer à utiliser l'application
          </CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={handleSubmit(onSubmit)} className="space-y-6">
            {/* Section Informations de l'entreprise */}
            <div className="space-y-4">
              <h3 className="text-lg font-semibold">Informations de l'entreprise</h3>
              
              <div className="space-y-2">
                <Label htmlFor="company_name">
                  Nom de l'entreprise <span className="text-destructive">*</span>
                </Label>
                <Input
                  id="company_name"
                  {...register('company_name')}
                  placeholder="Ex: Ma Société SARL"
                />
                {errors.company_name && (
                  <p className="text-sm text-destructive">
                    {errors.company_name.message}
                  </p>
                )}
              </div>

              <div className="space-y-2">
                <Label htmlFor="company_sector">Secteur d'activité</Label>
                <Input
                  id="company_sector"
                  {...register('company_sector')}
                  placeholder="Ex: Commerce, Services, etc."
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="company_headquarters">Siège social</Label>
                <Input
                  id="company_headquarters"
                  {...register('company_headquarters')}
                  placeholder="Ex: 123 Rue Example, Ville, Pays"
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="company_email">Email de l'entreprise</Label>
                <Input
                  id="company_email"
                  type="email"
                  {...register('company_email')}
                  placeholder="contact@entreprise.com"
                />
                {errors.company_email && (
                  <p className="text-sm text-destructive">
                    {errors.company_email.message}
                  </p>
                )}
              </div>

              <div className="space-y-2">
                <Label htmlFor="company_legal_status">Statut juridique</Label>
                <Input
                  id="company_legal_status"
                  {...register('company_legal_status')}
                  placeholder="Ex: SARL, SA, EURL, etc."
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="company_bank_account_number">N° Compte bancaire</Label>
                <Input
                  id="company_bank_account_number"
                  {...register('company_bank_account_number')}
                  placeholder="Ex: 1234567890"
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="company_logo">Logo de l'entreprise (URL)</Label>
                <Input
                  id="company_logo"
                  {...register('company_logo')}
                  placeholder="https://example.com/logo.png"
                />
                <p className="text-sm text-muted-foreground">
                  Vous pourrez uploader une image plus tard
                </p>
              </div>
            </div>

            <Separator />

            {/* Section Paramètres */}
            <div className="space-y-4">
              <h3 className="text-lg font-semibold">Paramètres</h3>
              
              <div className="space-y-2">
                <Label htmlFor="currency">
                  Devise <span className="text-destructive">*</span>
                </Label>
                <Select
                  value={watch('currency')}
                  onValueChange={(value) => setValue('currency', value as 'FCFA' | 'EUR' | 'USD' | 'XOF')}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Sélectionner une devise" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="FCFA">FCFA (Franc CFA)</SelectItem>
                    <SelectItem value="EUR">EUR (Euro)</SelectItem>
                    <SelectItem value="USD">USD (Dollar américain)</SelectItem>
                    <SelectItem value="XOF">XOF (Franc CFA Ouest)</SelectItem>
                  </SelectContent>
                </Select>
                {errors.currency && (
                  <p className="text-sm text-destructive">
                    {errors.currency.message}
                  </p>
                )}
              </div>

              <div className="space-y-2">
                <Label htmlFor="low_stock_threshold">
                  Seuil de stock faible (%)
                </Label>
                <Input
                  id="low_stock_threshold"
                  type="number"
                  min="0"
                  max="100"
                  {...register('low_stock_threshold', { valueAsNumber: true })}
                  placeholder="80"
                />
                <p className="text-sm text-muted-foreground">
                  Un article sera considéré en stock faible en dessous de ce pourcentage (défaut: 80%)
                </p>
                {errors.low_stock_threshold && (
                  <p className="text-sm text-destructive">
                    {errors.low_stock_threshold.message}
                  </p>
                )}
              </div>

              <div className="space-y-2">
                <Label htmlFor="language">Langue</Label>
                <Select
                  value={watch('language')}
                  onValueChange={(value) => setValue('language', value as 'fr' | 'en')}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Sélectionner une langue" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="fr">Français</SelectItem>
                    <SelectItem value="en">English</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>

            <div className="flex justify-end gap-4 pt-4">
              <Button
                type="submit"
                disabled={isSubmitting}
              >
                {isSubmitting ? (
                  <>
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    Enregistrement...
                  </>
                ) : (
                  'Terminer la configuration'
                )}
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>
    </div>
  );
}
```

### 2. Vérifier l'état d'onboarding après connexion

Dans votre `AuthContext` ou composant de layout, ajoutez une vérification :

```typescript
'use client';

import { useEffect, useState } from 'react';
import { useRouter, usePathname } from 'next/navigation';
import api from '@/lib/api';

export function OnboardingGuard({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();
  const [isChecking, setIsChecking] = useState(true);
  const [needsOnboarding, setNeedsOnboarding] = useState(false);

  useEffect(() => {
    const checkOnboarding = async () => {
      try {
        // Vérifier si l'utilisateur est authentifié
        const userResponse = await api.get('/api/user');
        
        if (userResponse.data) {
          // Vérifier l'état d'onboarding
          const onboardingResponse = await api.get('/api/onboarding/check');
          
          if (!onboardingResponse.data.data.is_complete) {
            // Rediriger vers l'onboarding si pas complété
            if (pathname !== '/onboarding') {
              setNeedsOnboarding(true);
              router.push('/onboarding');
            }
          }
        }
      } catch (error) {
        // Si non authentifié, ne rien faire (laisser passer)
      } finally {
        setIsChecking(false);
      }
    };

    checkOnboarding();
  }, [pathname, router]);

  // Ne pas afficher le contenu pendant la vérification
  if (isChecking) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <Loader2 className="h-8 w-8 animate-spin" />
      </div>
    );
  }

  return <>{children}</>;
}
```

### 3. Créer la route pour l'onboarding

Créez `app/onboarding/page.tsx` :

```typescript
import OnboardingPage from '@/components/onboarding/OnboardingPage';

export default function Onboarding() {
  return <OnboardingPage />;
}
```

### 4. Intégrer le guard dans le layout

Dans votre `app/layout.tsx` ou `app/dashboard/layout.tsx` :

```typescript
import { OnboardingGuard } from '@/components/onboarding/OnboardingGuard';

export default function DashboardLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <OnboardingGuard>
      {children}
    </OnboardingGuard>
  );
}
```

## ✅ FONCTIONNALITÉS À IMPLÉMENTER

1. **Validation** : Utiliser Zod pour valider les données du formulaire
2. **Champs obligatoires** : Nom de l'entreprise et devise
3. **Valeurs par défaut** : 
   - Devise : FCFA
   - Seuil de stock faible : 80%
   - Langue : Français
4. **Upload de logo** : Pour l'instant, utiliser un champ URL (vous pourrez ajouter un upload d'image plus tard)
5. **Redirection** : Après soumission réussie, rediriger vers `/dashboard`
6. **Gestion d'erreurs** : Afficher les erreurs de validation et les erreurs serveur
7. **État de chargement** : Afficher un spinner pendant la soumission

## 📝 NOTES IMPORTANTES

1. **Vérification d'onboarding** : Après chaque connexion (credentials ou sociale), vérifier si l'onboarding est complété
2. **Protection des routes** : Les routes protégées doivent vérifier l'onboarding avant d'afficher le contenu
3. **API Endpoints** :
   - `GET /api/onboarding/check` - Vérifier l'état d'onboarding
   - `POST /api/onboarding/complete` - Compléter l'onboarding

4. **Données par défaut** :
   - Devise : FCFA
   - Seuil de stock faible : 80% (défini dans le backend)
   - Langue : Français

5. **Champs optionnels** : Tous les champs de l'entreprise sont optionnels sauf le nom

6. **Validation email** : Si un email est fourni, il doit être valide

Créez cet écran d'onboarding avec toutes les fonctionnalités demandées.
```

---

## 📝 NOTES TECHNIQUES

1. **API Endpoints** :
   - `GET /api/onboarding/check` - Vérifie si l'onboarding est complété
   - `POST /api/onboarding/complete` - Complète l'onboarding avec les données

2. **Valeurs par défaut** :
   - Devise : FCFA
   - Seuil de stock faible : 80%
   - Langue : Français

3. **Protection des routes** : Utiliser un guard pour rediriger vers l'onboarding si non complété

4. **Validation** : Utiliser Zod pour valider les données côté client

5. **Upload de logo** : Pour l'instant, utiliser un champ URL. Vous pourrez ajouter un upload d'image plus tard.

