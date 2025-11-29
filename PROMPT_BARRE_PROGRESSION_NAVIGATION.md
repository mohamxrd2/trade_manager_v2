# 📋 PROMPT POUR AJOUTER UNE BARRE DE PROGRESSION LORS DE LA NAVIGATION

## 🚀 Copiez ce prompt dans Cursor :

```
Je dois ajouter une barre de progression (progress bar) qui s'affiche en haut de la page lors de la navigation entre les pages de mon application Next.js, spécifiquement pour les pages non visitées ou qui nécessitent le chargement de données depuis l'API.

## 🎯 OBJECTIF

Créer un système de barre de progression qui :
1. S'affiche automatiquement lors de la navigation vers une nouvelle page
2. S'affiche lorsque des données doivent être chargées depuis l'API
3. Se cache automatiquement une fois le chargement terminé
4. Fonctionne avec Next.js App Router (si utilisé) ou Pages Router

## 🔧 IMPLÉMENTATION

### 1. Créer un composant `NavigationProgressBar`

Créer un composant réutilisable qui affiche une barre de progression en haut de la page :

```typescript
'use client';

import { useEffect, useState } from 'react';
import { usePathname, useSearchParams } from 'next/navigation';
import { Progress } from '@/components/ui/progress';

export function NavigationProgressBar() {
  const [isLoading, setIsLoading] = useState(false);
  const [progress, setProgress] = useState(0);
  const pathname = usePathname();
  const searchParams = useSearchParams();

  useEffect(() => {
    // Démarrer la barre de progression lors du changement de route
    setIsLoading(true);
    setProgress(0);

    // Simuler la progression
    const interval = setInterval(() => {
      setProgress((prev) => {
        if (prev >= 90) {
          clearInterval(interval);
          return 90;
        }
        return prev + 10;
      });
    }, 100);

    // Nettoyer l'intervalle
    return () => {
      clearInterval(interval);
      // Compléter la progression et masquer après un court délai
      setProgress(100);
      setTimeout(() => {
        setIsLoading(false);
        setProgress(0);
      }, 200);
    };
  }, [pathname, searchParams]);

  if (!isLoading) return null;

  return (
    <div className="fixed top-0 left-0 right-0 z-50">
      <Progress value={progress} className="h-1" />
    </div>
  );
}
```

### 2. Intégrer dans le Layout principal

Ajouter le composant dans le layout principal (`app/layout.tsx` ou `layouts/MainLayout.tsx`) :

```typescript
import { NavigationProgressBar } from '@/components/NavigationProgressBar';

export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html lang="fr">
      <body>
        <NavigationProgressBar />
        {children}
      </body>
    </html>
  );
}
```

### 3. Version avancée avec détection de chargement API

Si vous voulez que la barre s'affiche aussi lors des appels API, créer un contexte de chargement :

```typescript
'use client';

import { createContext, useContext, useState, ReactNode } from 'react';
import { usePathname, useSearchParams } from 'next/navigation';
import { Progress } from '@/components/ui/progress';

interface LoadingContextType {
  isLoading: boolean;
  setLoading: (loading: boolean) => void;
  startLoading: () => void;
  stopLoading: () => void;
}

const LoadingContext = createContext<LoadingContextType | undefined>(undefined);

export function useLoading() {
  const context = useContext(LoadingContext);
  if (!context) {
    throw new Error('useLoading must be used within LoadingProvider');
  }
  return context;
}

export function LoadingProvider({ children }: { children: ReactNode }) {
  const [isLoading, setIsLoading] = useState(false);
  const [progress, setProgress] = useState(0);
  const pathname = usePathname();
  const searchParams = useSearchParams();

  const setLoading = (loading: boolean) => {
    setIsLoading(loading);
    if (loading) {
      setProgress(0);
    } else {
      setProgress(100);
      setTimeout(() => {
        setProgress(0);
      }, 200);
    }
  };

  const startLoading = () => {
    setIsLoading(true);
    setProgress(0);
    
    // Simuler la progression
    const interval = setInterval(() => {
      setProgress((prev) => {
        if (prev >= 90) {
          clearInterval(interval);
          return 90;
        }
        return prev + 10;
      });
    }, 100);
  };

  const stopLoading = () => {
    setProgress(100);
    setTimeout(() => {
      setIsLoading(false);
      setProgress(0);
    }, 200);
  };

  // Détecter les changements de route
  useEffect(() => {
    startLoading();
    
    // Arrêter après un court délai (simulation)
    // En production, vous pouvez écouter les événements de fin de chargement
    const timer = setTimeout(() => {
      stopLoading();
    }, 500);

    return () => {
      clearTimeout(timer);
    };
  }, [pathname, searchParams]);

  return (
    <LoadingContext.Provider value={{ isLoading, setLoading, startLoading, stopLoading }}>
      {children}
      {isLoading && (
        <div className="fixed top-0 left-0 right-0 z-50">
          <Progress value={progress} className="h-1" />
        </div>
      )}
    </LoadingContext.Provider>
  );
}
```

### 4. Utiliser dans les composants de page

Dans vos pages qui chargent des données, utiliser le contexte :

```typescript
'use client';

import { useEffect, useState } from 'react';
import { useLoading } from '@/contexts/LoadingContext';
import { api } from '@/lib/api';

export default function CollaboratorsPage() {
  const { startLoading, stopLoading } = useLoading();
  const [collaborators, setCollaborators] = useState([]);

  useEffect(() => {
    const fetchData = async () => {
      startLoading();
      try {
        const response = await api.get('/api/collaborators');
        setCollaborators(response.data.data);
      } catch (error) {
        console.error('Error fetching collaborators:', error);
      } finally {
        stopLoading();
      }
    };

    fetchData();
  }, [startLoading, stopLoading]);

  // ... reste du composant
}
```

### 5. Version avec nprogress (recommandée pour Next.js)

Installer `nprogress` pour une meilleure expérience :

```bash
npm install nprogress
npm install -D @types/nprogress
```

Créer un composant `ProgressBar` :

```typescript
'use client';

import { useEffect } from 'react';
import { usePathname, useSearchParams } from 'next/navigation';
import NProgress from 'nprogress';
import 'nprogress/nprogress.css';

export function ProgressBar() {
  const pathname = usePathname();
  const searchParams = useSearchParams();

  useEffect(() => {
    NProgress.configure({ showSpinner: false });
    NProgress.start();

    // Simuler la fin du chargement après un court délai
    // En production, écouter les événements de fin de chargement
    const timer = setTimeout(() => {
      NProgress.done();
    }, 300);

    return () => {
      clearTimeout(timer);
      NProgress.done();
    };
  }, [pathname, searchParams]);

  return null;
}
```

Ajouter dans `app/layout.tsx` :

```typescript
import { ProgressBar } from '@/components/ProgressBar';

export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html lang="fr">
      <head>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/nprogress/0.2.0/nprogress.min.css" />
      </head>
      <body>
        <ProgressBar />
        {children}
      </body>
    </html>
  );
}
```

### 6. Personnaliser le style de nprogress

Créer un fichier CSS personnalisé (`app/globals.css` ou `styles/nprogress.css`) :

```css
/* Personnaliser nprogress */
#nprogress {
  pointer-events: none;
}

#nprogress .bar {
  background: hsl(var(--primary));
  position: fixed;
  z-index: 9999;
  top: 0;
  left: 0;
  width: 100%;
  height: 3px;
}

#nprogress .peg {
  display: block;
  position: absolute;
  right: 0px;
  width: 100px;
  height: 100%;
  box-shadow: 0 0 10px hsl(var(--primary)), 0 0 5px hsl(var(--primary));
  opacity: 1.0;
  transform: rotate(3deg) translate(0px, -4px);
}

/* Masquer le spinner */
#nprogress .spinner {
  display: none;
}
```

### 7. Version complète avec détection de chargement de données

Créer un hook personnalisé pour gérer le chargement :

```typescript
'use client';

import { useEffect, useState } from 'react';
import { usePathname } from 'next/navigation';
import NProgress from 'nprogress';
import 'nprogress/nprogress.css';

// Configuration globale
NProgress.configure({
  showSpinner: false,
  trickleSpeed: 100,
  minimum: 0.08,
});

export function usePageLoading() {
  const pathname = usePathname();
  const [isLoading, setIsLoading] = useState(false);

  useEffect(() => {
    // Démarrer la barre de progression
    setIsLoading(true);
    NProgress.start();

    // Simuler la fin du chargement
    // En production, vous pouvez écouter window.load ou d'autres événements
    const handleLoad = () => {
      NProgress.done();
      setIsLoading(false);
    };

    // Si la page est déjà chargée
    if (document.readyState === 'complete') {
      setTimeout(() => {
        NProgress.done();
        setIsLoading(false);
      }, 300);
    } else {
      window.addEventListener('load', handleLoad);
    }

    return () => {
      window.removeEventListener('load', handleLoad);
      NProgress.done();
      setIsLoading(false);
    };
  }, [pathname]);

  return isLoading;
}

// Composant ProgressBar
export function ProgressBar() {
  usePageLoading();
  return null;
}
```

### 8. Intégration avec les appels API

Créer un intercepteur axios pour gérer automatiquement la barre de progression :

```typescript
// lib/api.ts
import axios from 'axios';
import NProgress from 'nprogress';

const api = axios.create({
  baseURL: process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000',
  withCredentials: true,
});

// Intercepteur pour les requêtes
api.interceptors.request.use((config) => {
  // Démarrer la barre de progression pour les requêtes GET
  if (config.method === 'get') {
    NProgress.start();
  }
  return config;
});

// Intercepteur pour les réponses
api.interceptors.response.use(
  (response) => {
    NProgress.done();
    return response;
  },
  (error) => {
    NProgress.done();
    return Promise.reject(error);
  }
);

export { api };
```

## 🎨 STYLE PERSONNALISÉ (Optionnel)

Si vous utilisez shadcn/ui Progress, personnaliser le style :

```typescript
'use client';

import { useEffect, useState } from 'react';
import { usePathname, useSearchParams } from 'next/navigation';
import { Progress } from '@/components/ui/progress';
import { cn } from '@/lib/utils';

export function NavigationProgressBar() {
  const [progress, setProgress] = useState(0);
  const [isVisible, setIsVisible] = useState(false);
  const pathname = usePathname();
  const searchParams = useSearchParams();

  useEffect(() => {
    setIsVisible(true);
    setProgress(0);

    // Animation de progression
    const interval = setInterval(() => {
      setProgress((prev) => {
        if (prev >= 90) {
          clearInterval(interval);
          return 90;
        }
        // Progression non linéaire pour un effet plus naturel
        return prev + Math.random() * 15;
      });
    }, 100);

    // Compléter après un délai
    const completeTimer = setTimeout(() => {
      setProgress(100);
      setTimeout(() => {
        setIsVisible(false);
        setProgress(0);
      }, 300);
    }, 500);

    return () => {
      clearInterval(interval);
      clearTimeout(completeTimer);
    };
  }, [pathname, searchParams]);

  if (!isVisible) return null;

  return (
    <div className="fixed top-0 left-0 right-0 z-50">
      <Progress 
        value={progress} 
        className={cn(
          "h-1 transition-opacity duration-300",
          isVisible ? "opacity-100" : "opacity-0"
        )} 
      />
    </div>
  );
}
```

## ✅ CHECKLIST

- [ ] Installer `nprogress` (recommandé) ou utiliser shadcn/ui Progress
- [ ] Créer le composant `ProgressBar` ou `NavigationProgressBar`
- [ ] Ajouter le composant dans le layout principal
- [ ] Tester la navigation entre les pages : la barre doit s'afficher
- [ ] Personnaliser le style (couleur, hauteur, animation)
- [ ] (Optionnel) Intégrer avec les appels API pour afficher la barre lors du chargement de données
- [ ] Tester sur différentes pages (collaborateurs, articles, transactions, etc.)

## 🎯 RÉSULTAT ATTENDU

- Une barre de progression s'affiche en haut de la page lors de la navigation
- La barre se complète progressivement puis disparaît
- La barre s'affiche aussi lors du chargement de données depuis l'API (si implémenté)
- L'animation est fluide et non intrusive
- Le style s'intègre avec le design system de l'application

## 📝 NOTES IMPORTANTES

1. **Next.js App Router** : Utiliser `usePathname()` et `useSearchParams()` pour détecter les changements de route
2. **Next.js Pages Router** : Utiliser `useRouter()` et écouter les événements `routeChangeStart` et `routeChangeComplete`
3. **Performance** : La barre de progression améliore la perception de la performance même si le chargement est rapidex
4. **Accessibilité** : S'assurer que la barre ne bloque pas l'interaction avec la page
5. **nprogress** : Solution recommandée car elle est optimisée et légère

Implémentez la barre de progression de navigation selon l'une des méthodes ci-dessus.
```

---

## 📝 NOTES TECHNIQUES

### Options d'implémentation :

1. **nprogress** (Recommandé)
   - Légère et performante
   - Facile à personnaliser
   - Utilisée par de nombreuses applications

2. **shadcn/ui Progress**
   - Intégration native avec votre design system
   - Plus de contrôle sur l'animation
   - Nécessite plus de code personnalisé

3. **Contexte de chargement**
   - Contrôle total sur l'affichage
   - Peut être utilisé pour d'autres indicateurs de chargement
   - Plus complexe à maintenir

### Détection de fin de chargement :

Pour une détection plus précise de la fin du chargement :
- Écouter `window.load`
- Utiliser `document.readyState`
- Attendre la fin des appels API avec des intercepteurs
- Utiliser React Query ou SWR qui gèrent automatiquement les états de chargement

