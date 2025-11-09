# Configuration Laravel Sanctum avec Next.js - Authentification par Cookies

Ce document décrit la configuration complète pour utiliser Laravel Sanctum avec authentification par cookies HTTP-only pour un frontend Next.js.

## 📋 Configuration .env

Ajoutez ou modifiez ces variables dans votre fichier `.env` :

```env
# Sanctum Configuration
SANCTUM_STATEFUL_DOMAINS=localhost,localhost:3000,127.0.0.1,127.0.0.1:3000,::1

# Session Configuration
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_DOMAIN=localhost
SESSION_SECURE_COOKIE=false
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax

# CORS Configuration (optionnel, mais recommandé)
# Les configurations CORS sont dans config/cors.php
```

## 🔧 Fichiers Configurés

### 1. `config/sanctum.php`
✅ Domaines stateful configurés pour `localhost:3000` et `127.0.0.1:3000`
✅ Guard 'web' activé pour les sessions

### 2. `config/cors.php`
✅ `supports_credentials => true`
✅ `allowed_origins => ['http://localhost:3000']`
✅ `allowed_headers => ['*']`
✅ `allowed_methods => ['*']`
✅ Paths incluent `'api/*'` et `'sanctum/csrf-cookie'`

### 3. `bootstrap/app.php`
✅ Middleware `HandleCors` ajouté
✅ Middleware `EnsureFrontendRequestsAreStateful` ajouté

### 4. `routes/api.php`
✅ Route `/sanctum/csrf-cookie` ajoutée
✅ Routes `/api/login`, `/api/logout`, `/api/user` configurées

### 5. `app/Http/Controllers/API/AuthController.php`
✅ `login()` utilise `Auth::guard('web')->attempt()` avec sessions
✅ `logout()` utilise `Auth::guard('web')->logout()` et invalide la session
✅ `user()` utilise `Auth::guard('web')->user()`
✅ Plus de tokens Bearer - uniquement des cookies HTTP-only

## 🚀 Flux d'Authentification

### 1. Initialisation (Première requête)
```javascript
// Dans votre frontend Next.js
axios.get('http://localhost:8000/api/sanctum/csrf-cookie', {
  withCredentials: true
})
```

### 2. Connexion
```javascript
axios.post('http://localhost:8000/api/login', {
  login: 'email@example.com',
  password: 'password',
  remember: false
}, {
  withCredentials: true
})
```

### 3. Requêtes authentifiées
```javascript
// Les cookies sont envoyés automatiquement
axios.get('http://localhost:8000/api/user', {
  withCredentials: true
})
```

### 4. Déconnexion
```javascript
axios.post('http://localhost:8000/api/logout', {}, {
  withCredentials: true
})
```

## 🔒 Sécurité

- ✅ Cookies HTTP-only (non accessibles via JavaScript)
- ✅ Sessions sécurisées avec régénération après login
- ✅ CSRF protection activée
- ✅ CORS configuré pour autoriser uniquement `localhost:3000`
- ✅ SameSite=Lax pour le développement local (peut être changé en 'none' pour la production avec HTTPS)

## 📝 Notes Importantes

1. **Toutes les requêtes axios doivent inclure `withCredentials: true`**
2. **Le cookie CSRF doit être récupéré avant la première requête POST/PUT/DELETE**
3. **Pour la production**, configurez :
   - `SESSION_SECURE_COOKIE=true` (nécessite HTTPS)
   - `SESSION_SAME_SITE=none` (si nécessaire pour cross-domain)
   - `SANCTUM_STATEFUL_DOMAINS` avec votre domaine de production

## 🧪 Test

1. Démarrez le serveur Laravel : `php artisan serve`
2. Dans votre frontend Next.js, configurez axios :
```javascript
import axios from 'axios';

const api = axios.create({
  baseURL: 'http://localhost:8000',
  withCredentials: true,
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  }
});

// Récupérer le cookie CSRF au démarrage
api.get('/api/sanctum/csrf-cookie').then(() => {
  console.log('CSRF cookie obtenu');
});
```

3. Testez la connexion :
```javascript
api.post('/api/login', {
  login: 'test@example.com',
  password: 'password'
}).then(response => {
  console.log('Connecté:', response.data);
});
```

4. Vérifiez que l'utilisateur reste connecté après rafraîchissement :
```javascript
api.get('/api/user').then(response => {
  console.log('Utilisateur:', response.data);
});
```

