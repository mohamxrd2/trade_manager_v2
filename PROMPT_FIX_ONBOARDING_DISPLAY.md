# 📋 PROMPT POUR CORRIGER L'AFFICHAGE DE L'ONBOARDING

## 🚀 Copiez ce prompt dans Cursor :

```
Je dois corriger le problème où l'écran d'onboarding ne s'affiche pas après la création d'un compte. Les pages ont été créées mais l'onboarding ne s'affiche pas automatiquement.

## 🔍 PROBLÈME IDENTIFIÉ

L'écran d'onboarding ne s'affiche pas après l'inscription car :
1. Le guard d'onboarding n'est pas correctement intégré
2. La redirection après l'inscription ne vérifie pas l'onboarding
3. Le guard ne vérifie peut-être pas au bon moment

## 🔧 CORRECTIONS À APPORTER

### 1. Modifier le AuthContext pour vérifier l'onboarding après login/register

Dans `contexts/AuthContext.tsx`, modifiez les fonctions `login` et `register` pour vérifier l'onboarding après la connexion :

```typescript
'use client';

import React, { createContext, useContext, useState, useEffect, useCallback } from 'react';
import { useRouter } from 'next/navigation';
import { User, LoginCredentials, RegisterData } from '@/lib/auth';
import * as authService from '@/lib/auth';
import api from '@/lib/api';

interface AuthContextType {
  user: User | null;
  loading: boolean;
  login: (credentials: LoginCredentials) => Promise<void>;
  register: (data: RegisterData) => Promise<void>;
  logout: () => Promise<void>;
  checkAuth: () => Promise<void>;
  checkOnboarding: () => Promise<boolean>; // Nouvelle fonction
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);
  const router = useRouter();

  const checkOnboarding = useCallback(async (): Promise<boolean> => {
    try {
      const response = await api.get('/api/onboarding/check');
      return response.data.data.is_complete;
    } catch (error) {
      console.error('Erreur lors de la vérification de l\'onboarding:', error);
      return false;
    }
  }, []);

  const checkAuth = useCallback(async () => {
    try {
      const currentUser = await authService.getUser();
      setUser(currentUser);
      
      // Si l'utilisateur est connecté, vérifier l'onboarding
      if (currentUser) {
        const isOnboardingComplete = await checkOnboarding();
        
        // Si l'onboarding n'est pas complété et qu'on n'est pas déjà sur la page onboarding
        if (!isOnboardingComplete && typeof window !== 'undefined') {
          const currentPath = window.location.pathname;
          if (currentPath !== '/onboarding') {
            router.push('/onboarding');
          }
        }
      }
    } catch (error) {
      console.error('Erreur lors de la vérification de l\'authentification:', error);
      setUser(null);
    } finally {
      setLoading(false);
    }
  }, [checkOnboarding, router]);

  const login = useCallback(async (credentials: LoginCredentials) => {
    try {
      const loggedInUser = await authService.login(credentials);
      setUser(loggedInUser);
      
      // Vérifier l'onboarding après la connexion
      const isOnboardingComplete = await checkOnboarding();
      
      if (!isOnboardingComplete) {
        // Rediriger vers l'onboarding
        router.push('/onboarding');
      } else {
        // Rediriger vers le dashboard
        router.push('/dashboard');
      }
    } catch (error: any) {
      console.error('Erreur lors de la connexion:', error);
      throw error;
    }
  }, [checkOnboarding, router]);

  const register = useCallback(async (data: RegisterData) => {
    try {
      const registeredUser = await authService.register(data);
      setUser(registeredUser);
      
      // Après l'inscription, rediriger vers l'onboarding (toujours nécessaire)
      router.push('/onboarding');
    } catch (error: any) {
      console.error('Erreur lors de l\'inscription:', error);
      throw error;
    }
  }, [router]);

  const logout = useCallback(async () => {
    try {
      await authService.logout();
      setUser(null);
      router.push('/login');
    } catch (error) {
      console.error('Erreur lors de la déconnexion:', error);
      throw error;
    }
  }, [router]);

  // Vérifier l'authentification au chargement
  useEffect(() => {
    checkAuth();
  }, [checkAuth]);

  return (
    <AuthContext.Provider value={{ 
      user, 
      loading, 
      login, 
      register, 
      logout, 
      checkAuth,
      checkOnboarding 
    }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const context = useContext(AuthContext);
  if (context === undefined) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
}
```

### 2. Créer un middleware pour protéger les routes

Créez `middleware.ts` à la racine du projet :

```typescript
import { NextResponse } from 'next/server';
import type { NextRequest } from 'next/server';

export function middleware(request: NextRequest) {
  // Liste des routes publiques (pas besoin d'authentification)
  const publicRoutes = ['/login', '/register', '/'];
  
  // Liste des routes qui nécessitent l'onboarding
  const protectedRoutes = ['/dashboard', '/products', '/transactions', '/wallet', '/analytics', '/collaborators', '/settings'];
  
  const { pathname } = request.nextUrl;
  
  // Si c'est une route publique, laisser passer
  if (publicRoutes.includes(pathname)) {
    return NextResponse.next();
  }
  
  // Si c'est la route onboarding, laisser passer
  if (pathname === '/onboarding') {
    return NextResponse.next();
  }
  
  // Pour les autres routes, la vérification se fera côté client
  return NextResponse.next();
}

export const config = {
  matcher: [
    /*
     * Match all request paths except for the ones starting with:
     * - api (API routes)
     * - _next/static (static files)
     * - _next/image (image optimization files)
     * - favicon.ico (favicon file)
     */
    '/((?!api|_next/static|_next/image|favicon.ico).*)',
  ],
};
```

### 3. Créer un composant ProtectedRoute

Créez `components/auth/ProtectedRoute.tsx` :

```typescript
'use client';

import { useEffect } from 'react';
import { useRouter, usePathname } from 'next/navigation';
import { useAuth } from '@/contexts/AuthContext';
import { Loader2 } from 'lucide-react';
import api from '@/lib/api';

export function ProtectedRoute({ children }: { children: React.ReactNode }) {
  const { user, loading } = useAuth();
  const router = useRouter();
  const pathname = usePathname();

  useEffect(() => {
    const checkOnboarding = async () => {
      // Si l'utilisateur n'est pas connecté, rediriger vers login
      if (!loading && !user) {
        router.push('/login');
        return;
      }

      // Si l'utilisateur est connecté, vérifier l'onboarding
      if (!loading && user) {
        try {
          const response = await api.get('/api/onboarding/check');
          const isComplete = response.data.data.is_complete;

          // Si l'onboarding n'est pas complété et qu'on n'est pas sur la page onboarding
          if (!isComplete && pathname !== '/onboarding') {
            router.push('/onboarding');
            return;
          }

          // Si l'onboarding est complété et qu'on est sur la page onboarding, rediriger vers dashboard
          if (isComplete && pathname === '/onboarding') {
            router.push('/dashboard');
            return;
          }
        } catch (error) {
          console.error('Erreur lors de la vérification de l\'onboarding:', error);
        }
      }
    };

    checkOnboarding();
  }, [user, loading, router, pathname]);

  // Afficher un loader pendant la vérification
  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <Loader2 className="h-8 w-8 animate-spin" />
      </div>
    );
  }

  // Si l'utilisateur n'est pas connecté, ne rien afficher (redirection en cours)
  if (!user) {
    return null;
  }

  return <>{children}</>;
}
```

### 4. Modifier le layout principal pour utiliser ProtectedRoute

Dans `app/layout.tsx` ou `app/(dashboard)/layout.tsx` :

```typescript
import { AuthProvider } from '@/contexts/AuthContext';
import { ProtectedRoute } from '@/components/auth/ProtectedRoute';

export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html lang="fr" suppressHydrationWarning>
      <body>
        <AuthProvider>
          <ProtectedRoute>
            {children}
          </ProtectedRoute>
        </AuthProvider>
      </body>
    </html>
  );
}
```

### 5. Modifier la page d'onboarding pour rediriger si déjà complété

Dans `app/onboarding/page.tsx` ou `components/onboarding/OnboardingPage.tsx` :

```typescript
'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useAuth } from '@/contexts/AuthContext';
import api from '@/lib/api';
import { Loader2 } from 'lucide-react';

// ... (votre code existant du formulaire d'onboarding)

export default function OnboardingPage() {
  const router = useRouter();
  const { user, loading: authLoading } = useAuth();
  const [checkingOnboarding, setCheckingOnboarding] = useState(true);

  useEffect(() => {
    const checkOnboardingStatus = async () => {
      // Attendre que l'auth soit chargé
      if (authLoading) return;

      // Si l'utilisateur n'est pas connecté, rediriger vers login
      if (!user) {
        router.push('/login');
        return;
      }

      try {
        // Vérifier si l'onboarding est déjà complété
        const response = await api.get('/api/onboarding/check');
        const isComplete = response.data.data.is_complete;

        // Si déjà complété, rediriger vers le dashboard
        if (isComplete) {
          router.push('/dashboard');
          return;
        }
      } catch (error) {
        console.error('Erreur lors de la vérification de l\'onboarding:', error);
      } finally {
        setCheckingOnboarding(false);
      }
    };

    checkOnboardingStatus();
  }, [user, authLoading, router]);

  // Afficher un loader pendant la vérification
  if (authLoading || checkingOnboarding) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <Loader2 className="h-8 w-8 animate-spin" />
      </div>
    );
  }

  // Si l'utilisateur n'est pas connecté, ne rien afficher (redirection en cours)
  if (!user) {
    return null;
  }

  // ... (votre code existant du formulaire)
}
```

### 6. Modifier les pages de login/register pour gérer la redirection

Dans `app/login/page.tsx` (ou votre composant de login) :

```typescript
'use client';

import { useAuth } from '@/contexts/AuthContext';
// ... autres imports

export default function LoginPage() {
  const { login, user } = useAuth();
  const router = useRouter();

  // Si l'utilisateur est déjà connecté, rediriger
  useEffect(() => {
    if (user) {
      // Vérifier l'onboarding et rediriger en conséquence
      const checkAndRedirect = async () => {
        try {
          const response = await api.get('/api/onboarding/check');
          if (response.data.data.is_complete) {
            router.push('/dashboard');
          } else {
            router.push('/onboarding');
          }
        } catch (error) {
          router.push('/onboarding');
        }
      };
      checkAndRedirect();
    }
  }, [user, router]);

  // ... reste du code du formulaire de login
}
```

Dans `app/register/page.tsx` (ou votre composant de register) :

```typescript
'use client';

import { useAuth } from '@/contexts/AuthContext';
// ... autres imports

export default function RegisterPage() {
  const { register } = useAuth();
  // La redirection vers /onboarding est déjà gérée dans le AuthContext.register()

  // ... reste du code du formulaire d'inscription
}
```

### 7. Gérer la connexion sociale

Si vous avez une page de callback pour les réseaux sociaux, modifiez-la également :

```typescript
'use client';

import { useEffect } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { useAuth } from '@/contexts/AuthContext';
import api from '@/lib/api';

export default function SocialCallbackPage() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const { checkAuth } = useAuth();

  useEffect(() => {
    const handleCallback = async () => {
      try {
        // Vérifier l'authentification
        await checkAuth();
        
        // Vérifier l'onboarding
        const response = await api.get('/api/onboarding/check');
        const isComplete = response.data.data.is_complete;

        if (isComplete) {
          router.push('/dashboard');
        } else {
          router.push('/onboarding');
        }
      } catch (error) {
        router.push('/login');
      }
    };

    handleCallback();
  }, [router, checkAuth]);

  return (
    <div className="min-h-screen flex items-center justify-center">
      <Loader2 className="h-8 w-8 animate-spin" />
    </div>
  );
}
```

## ✅ CHECKLIST DE VÉRIFICATION

- [ ] Le `AuthContext` vérifie l'onboarding après `login()` et `register()`
- [ ] Le `ProtectedRoute` vérifie l'onboarding pour toutes les routes protégées
- [ ] La page d'onboarding redirige si l'onboarding est déjà complété
- [ ] Les pages de login/register redirigent correctement après connexion/inscription
- [ ] Le layout principal utilise `ProtectedRoute`
- [ ] La connexion sociale redirige vers l'onboarding si nécessaire

## 🔍 POINTS IMPORTANTS

1. **Ordre de vérification** : Auth → Onboarding → Route
2. **Redirection après inscription** : Toujours vers `/onboarding`
3. **Redirection après login** : Vers `/onboarding` si non complété, sinon `/dashboard`
4. **Protection des routes** : Toutes les routes protégées doivent vérifier l'onboarding

## 🐛 DÉBOGAGE

Si l'onboarding ne s'affiche toujours pas :

1. Vérifier dans la console du navigateur s'il y a des erreurs
2. Vérifier que l'API `/api/onboarding/check` retourne bien `is_complete: false`
3. Vérifier que la redirection se fait bien avec `router.push('/onboarding')`
4. Vérifier que le `ProtectedRoute` est bien intégré dans le layout

Corrigez ces points pour que l'onboarding s'affiche correctement après l'inscription.
```

---

## 📝 NOTES TECHNIQUES

1. **Ordre d'exécution** : Auth → Onboarding Check → Redirection
2. **Protection** : Utiliser `ProtectedRoute` pour toutes les routes nécessitant l'onboarding
3. **Redirection** : Toujours rediriger vers `/onboarding` après inscription, vérifier après login
4. **État de chargement** : Afficher un loader pendant les vérifications

