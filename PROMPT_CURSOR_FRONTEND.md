# 📋 PROMPT À COPIER-COLLER DANS CURSOR

## 🚀 Copiez ce prompt dans Cursor pour configurer votre frontend Next.js :

---

```
Je veux configurer mon frontend Next.js (port 3000) pour utiliser l'authentification Laravel Sanctum avec cookies HTTP-only.

Mon backend Laravel est sur http://localhost:8000 avec les routes suivantes :
- GET /sanctum/csrf-cookie (pour récupérer le cookie CSRF)
- POST /api/login (accepte {login: string, password: string, remember?: boolean})
- POST /api/register (accepte {first_name, last_name, username, email, password, password_confirmation})
- POST /api/logout (protégé)
- GET /api/user (protégé, retourne l'utilisateur directement)

OBJECTIFS :
1. Configurer axios avec baseURL: 'http://localhost:8000' et withCredentials: true
2. Créer un intercepteur axios qui récupère automatiquement le cookie CSRF avant chaque POST/PUT/DELETE
3. Créer les fonctions auth suivantes dans lib/auth.ts :
   - getCsrfCookie() : récupère le cookie CSRF
   - login(credentials) : connexion avec email ou username
   - register(data) : inscription
   - logout() : déconnexion
   - getUser() : récupère l'utilisateur connecté (retourne null si 401)

4. Créer un AuthContext (contexts/AuthContext.tsx) avec :
   - État user (User | null)
   - État loading (boolean)
   - Fonction login()
   - Fonction register()
   - Fonction logout()
   - Fonction checkAuth() qui appelle getUser() au chargement
   - useEffect qui appelle checkAuth() au montage

5. Créer un hook useAuth() qui utilise le contexte

6. Wrapper l'application dans app/layout.tsx avec <AuthProvider>

7. Les types TypeScript :
   - User: { id, first_name, last_name, username, email, company_share?, profile_image? }
   - LoginCredentials: { login: string, password: string, remember?: boolean }
   - RegisterData: { first_name, last_name, username, email, password, password_confirmation, company_share?, profile_image? }

IMPORTANT :
- Toutes les requêtes axios doivent avoir withCredentials: true
- Le cookie CSRF doit être récupéré automatiquement avant chaque POST/PUT/DELETE via un intercepteur
- Pas de tokens Bearer, uniquement des cookies HTTP-only
- L'utilisateur doit rester connecté après refresh (géré par les cookies)
- Gérer les erreurs 401 proprement (retourner null pour getUser, pas d'erreur)

Crée tous les fichiers nécessaires avec du code TypeScript propre et bien commenté.
```

---

## 📁 Fichiers à Créer

1. `lib/api.ts` - Configuration axios avec intercepteurs
2. `lib/auth.ts` - Fonctions d'authentification
3. `contexts/AuthContext.tsx` - Contexte React pour l'auth
4. `hooks/useAuth.ts` - Hook personnalisé (ou export depuis AuthContext)
5. Modifier `app/layout.tsx` - Ajouter AuthProvider

## ✅ Après avoir collé ce prompt dans Cursor

Cursor va créer tous les fichiers nécessaires. Ensuite :

1. Installez axios si ce n'est pas déjà fait :
   ```bash
   npm install axios
   ```

2. Utilisez le hook dans vos composants :
   ```typescript
   'use client';
   import { useAuth } from '@/contexts/AuthContext';
   
   export default function MyComponent() {
     const { user, login, logout, loading } = useAuth();
     // ...
   }
   ```

3. Testez le login :
   ```typescript
   await login({ login: 'test@example.com', password: 'password123' });
   ```

