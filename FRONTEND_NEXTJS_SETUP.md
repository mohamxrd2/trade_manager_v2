# 🚀 Configuration Frontend Next.js pour Laravel Sanctum

## 📋 Prompt à envoyer à Cursor

Copiez-collez ce prompt dans Cursor pour configurer automatiquement votre frontend Next.js :

---

```
Je veux configurer mon frontend Next.js pour utiliser l'authentification Laravel Sanctum avec cookies HTTP-only.

Mon backend Laravel est sur http://localhost:8000 et mon frontend Next.js sur http://localhost:3000.

OBJECTIFS :
- Configurer axios avec withCredentials: true pour tous les appels API
- Créer un système de récupération automatique du cookie CSRF avant login/register
- Implémenter les fonctions login() et register() qui fonctionnent avec Sanctum
- Gérer la persistance de session (l'utilisateur reste connecté après refresh)
- Créer un hook useAuth() pour gérer l'état d'authentification
- Gérer les erreurs et les messages de retour

CONFIGURATION REQUISE :

1. Créer un fichier lib/api.ts avec :
   - Instance axios configurée avec baseURL: 'http://localhost:8000'
   - withCredentials: true pour tous les appels
   - Intercepteur pour gérer automatiquement le CSRF cookie
   - Fonctions api.get(), api.post(), etc.

2. Créer un fichier lib/auth.ts avec :
   - Fonction getCsrfCookie() pour récupérer le cookie CSRF
   - Fonction login(login: string, password: string, remember?: boolean)
   - Fonction register(userData)
   - Fonction logout()
   - Fonction getUser() pour récupérer l'utilisateur connecté

3. Créer un contexte AuthContext avec :
   - État user (null ou User)
   - État loading
   - Fonction login()
   - Fonction register()
   - Fonction logout()
   - Fonction checkAuth() pour vérifier l'utilisateur au chargement
   - useEffect pour initialiser l'auth au montage du composant

4. Créer un composant AuthProvider pour wrapper l'application

5. Créer un hook useAuth() pour utiliser le contexte facilement

6. Les routes API backend sont :
   - GET /sanctum/csrf-cookie (pas besoin d'appeler directement, géré automatiquement)
   - POST /api/login (accepte {login, password, remember})
   - POST /api/register (accepte {first_name, last_name, username, email, password, password_confirmation})
   - POST /api/logout (protégé)
   - GET /api/user (protégé, retourne l'utilisateur directement)

IMPORTANT :
- Toutes les requêtes doivent inclure withCredentials: true
- Le cookie CSRF doit être récupéré avant chaque POST/PUT/DELETE
- Les erreurs doivent être gérées proprement
- L'utilisateur doit rester connecté après un refresh de page
- Les tokens Bearer ne sont PAS utilisés, uniquement les cookies HTTP-only

Crée tous les fichiers nécessaires avec du code TypeScript propre et bien structuré.
```

---

## 📁 Structure des fichiers à créer

Voici la structure complète que vous devriez avoir dans votre projet Next.js :

```
frontend-nextjs/
├── lib/
│   ├── api.ts          # Configuration axios
│   └── auth.ts         # Fonctions d'authentification
├── contexts/
│   └── AuthContext.tsx # Contexte React pour l'auth
├── hooks/
│   └── useAuth.ts      # Hook personnalisé
└── app/
    └── layout.tsx      # Wrapper avec AuthProvider
```

## 🔧 Code Complet à Implémenter

### 1. `lib/api.ts`

```typescript
import axios from 'axios';

const api = axios.create({
  baseURL: 'http://localhost:8000',
  withCredentials: true,
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  },
});

// Intercepteur pour récupérer le cookie CSRF automatiquement
let csrfTokenRetrieved = false;

api.interceptors.request.use(
  async (config) => {
    // Si c'est une requête POST/PUT/DELETE et qu'on n'a pas encore le cookie CSRF
    if (['post', 'put', 'delete', 'patch'].includes(config.method?.toLowerCase() || '')) {
      if (!csrfTokenRetrieved) {
        try {
          await axios.get('http://localhost:8000/sanctum/csrf-cookie', {
            withCredentials: true,
          });
          csrfTokenRetrieved = true;
        } catch (error) {
          console.error('Erreur lors de la récupération du cookie CSRF:', error);
        }
      }
    }
    return config;
  },
  (error) => {
    return Promise.reject(error);
  }
);

// Intercepteur pour gérer les erreurs
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      // Rediriger vers login si non authentifié
      if (typeof window !== 'undefined' && !window.location.pathname.includes('/login')) {
        // window.location.href = '/login';
      }
    }
    return Promise.reject(error);
  }
);

export default api;
```

### 2. `lib/auth.ts`

```typescript
import api from './api';

export interface User {
  id: string;
  first_name: string;
  last_name: string;
  username: string;
  email: string;
  company_share?: number;
  profile_image?: string;
}

export interface LoginCredentials {
  login: string; // email ou username
  password: string;
  remember?: boolean;
}

export interface RegisterData {
  first_name: string;
  last_name: string;
  username: string;
  email: string;
  password: string;
  password_confirmation: string;
  company_share?: number;
  profile_image?: string;
}

/**
 * Récupère le cookie CSRF
 */
export async function getCsrfCookie(): Promise<void> {
  try {
    await api.get('/sanctum/csrf-cookie');
  } catch (error) {
    console.error('Erreur lors de la récupération du cookie CSRF:', error);
    throw error;
  }
}

/**
 * Connexion d'un utilisateur
 */
export async function login(credentials: LoginCredentials): Promise<User> {
  // Récupérer le cookie CSRF avant le login
  await getCsrfCookie();
  
  const response = await api.post<User>('/api/login', credentials);
  return response.data;
}

/**
 * Inscription d'un nouvel utilisateur
 */
export async function register(data: RegisterData): Promise<User> {
  // Récupérer le cookie CSRF avant l'inscription
  await getCsrfCookie();
  
  const response = await api.post<{ success: boolean; message: string; data: { user: User } }>(
    '/api/register',
    data
  );
  return response.data.data.user;
}

/**
 * Déconnexion
 */
export async function logout(): Promise<void> {
  await getCsrfCookie();
  await api.post('/api/logout');
}

/**
 * Récupère l'utilisateur connecté
 */
export async function getUser(): Promise<User | null> {
  try {
    const response = await api.get<User>('/api/user');
    return response.data;
  } catch (error: any) {
    if (error.response?.status === 401) {
      return null;
    }
    throw error;
  }
}
```

### 3. `contexts/AuthContext.tsx`

```typescript
'use client';

import React, { createContext, useContext, useState, useEffect, useCallback } from 'react';
import { User, LoginCredentials, RegisterData } from '@/lib/auth';
import * as authService from '@/lib/auth';

interface AuthContextType {
  user: User | null;
  loading: boolean;
  login: (credentials: LoginCredentials) => Promise<void>;
  register: (data: RegisterData) => Promise<void>;
  logout: () => Promise<void>;
  checkAuth: () => Promise<void>;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);

  const checkAuth = useCallback(async () => {
    try {
      const currentUser = await authService.getUser();
      setUser(currentUser);
    } catch (error) {
      console.error('Erreur lors de la vérification de l\'authentification:', error);
      setUser(null);
    } finally {
      setLoading(false);
    }
  }, []);

  const login = useCallback(async (credentials: LoginCredentials) => {
    try {
      const loggedInUser = await authService.login(credentials);
      setUser(loggedInUser);
    } catch (error: any) {
      console.error('Erreur lors de la connexion:', error);
      throw error;
    }
  }, []);

  const register = useCallback(async (data: RegisterData) => {
    try {
      const registeredUser = await authService.register(data);
      setUser(registeredUser);
    } catch (error: any) {
      console.error('Erreur lors de l\'inscription:', error);
      throw error;
    }
  }, []);

  const logout = useCallback(async () => {
    try {
      await authService.logout();
      setUser(null);
    } catch (error) {
      console.error('Erreur lors de la déconnexion:', error);
      throw error;
    }
  }, []);

  // Vérifier l'authentification au chargement
  useEffect(() => {
    checkAuth();
  }, [checkAuth]);

  return (
    <AuthContext.Provider value={{ user, loading, login, register, logout, checkAuth }}>
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

### 4. `app/layout.tsx` (modifier pour inclure AuthProvider)

```typescript
import { AuthProvider } from '@/contexts/AuthContext';

export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html lang="fr">
      <body>
        <AuthProvider>
          {children}
        </AuthProvider>
      </body>
    </html>
  );
}
```

### 5. Exemple d'utilisation dans un composant Login

```typescript
'use client';

import { useState } from 'react';
import { useAuth } from '@/contexts/AuthContext';
import { useRouter } from 'next/navigation';

export default function LoginForm() {
  const [login, setLogin] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  
  const { login: loginUser } = useAuth();
  const router = useRouter();

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');
    setLoading(true);

    try {
      await loginUser({ login, password });
      router.push('/dashboard'); // Rediriger après connexion
    } catch (err: any) {
      setError(err.response?.data?.message || 'Erreur de connexion');
    } finally {
      setLoading(false);
    }
  };

  return (
    <form onSubmit={handleSubmit}>
      <input
        type="text"
        value={login}
        onChange={(e) => setLogin(e.target.value)}
        placeholder="Email ou username"
        required
      />
      <input
        type="password"
        value={password}
        onChange={(e) => setPassword(e.target.value)}
        placeholder="Mot de passe"
        required
      />
      {error && <div className="error">{error}</div>}
      <button type="submit" disabled={loading}>
        {loading ? 'Connexion...' : 'Se connecter'}
      </button>
    </form>
  );
}
```

## ✅ Checklist

- [ ] Installer axios : `npm install axios`
- [ ] Créer `lib/api.ts` avec la configuration axios
- [ ] Créer `lib/auth.ts` avec les fonctions d'authentification
- [ ] Créer `contexts/AuthContext.tsx`
- [ ] Créer `hooks/useAuth.ts` (ou l'exporter depuis AuthContext)
- [ ] Wrapper l'application avec `<AuthProvider>` dans `layout.tsx`
- [ ] Créer les composants Login et Register
- [ ] Tester le login avec email et username
- [ ] Vérifier que l'utilisateur reste connecté après refresh

## 🎯 Points Importants

1. **withCredentials: true** : Obligatoire pour tous les appels API
2. **Cookie CSRF** : Récupéré automatiquement avant chaque POST/PUT/DELETE
3. **Pas de localStorage** : Les cookies sont gérés automatiquement par le navigateur
4. **Persistance** : L'utilisateur reste connecté grâce aux cookies HTTP-only
5. **Gestion d'erreurs** : Les erreurs 401 sont gérées automatiquement

## 🚨 Erreurs Courantes à Éviter

- ❌ Oublier `withCredentials: true`
- ❌ Ne pas récupérer le cookie CSRF avant login/register
- ❌ Utiliser des tokens Bearer au lieu des cookies
- ❌ Stocker des tokens dans localStorage
- ❌ Ne pas gérer les erreurs 401

