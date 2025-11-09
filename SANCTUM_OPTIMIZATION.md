# ✅ Configuration Sanctum Optimisée pour Next.js

## 🎯 Objectif

Authentification via cookies HTTP-only avec Laravel Sanctum, sans localStorage, pour une intégration parfaite avec Next.js.

## 📋 Configuration .env

```env
# Sanctum Configuration
SANCTUM_STATEFUL_DOMAINS=localhost,127.0.0.1,localhost:3000,127.0.0.1:3000

# Session Configuration
SESSION_DRIVER=cookie
SESSION_LIFETIME=120
SESSION_DOMAIN=localhost
SESSION_SECURE_COOKIE=false
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax

# Application URLs
APP_URL=http://localhost:8000
FRONTEND_URL=http://localhost:3000
```

## ✅ Fichiers Configurés

### 1. `config/sanctum.php`
```php
'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', 
    'localhost,127.0.0.1,localhost:3000,127.0.0.1:3000'
)),
'guard' => ['web'],
'expiration' => 43200, // 12 heures
```

✅ Domaines stateful configurés pour tous les variants localhost  
✅ Guard 'web' activé pour les sessions  
✅ Expiration de 12 heures

### 2. `config/cors.php`
```php
'paths' => ['api/*', 'login', 'logout', 'sanctum/csrf-cookie'],
'allowed_methods' => ['*'],
'allowed_origins' => ['http://localhost:3000'],
'allowed_headers' => ['*'],
'supports_credentials' => true,
```

✅ Tous les paths nécessaires inclus  
✅ Credentials supportés  
✅ Origine autorisée

### 3. `config/session.php`
```php
'driver' => env('SESSION_DRIVER', 'cookie'),
'lifetime' => (int) env('SESSION_LIFETIME', 120),
'domain' => env('SESSION_DOMAIN'),
'http_only' => env('SESSION_HTTP_ONLY', true),
'same_site' => env('SESSION_SAME_SITE', 'lax'),
```

✅ Driver 'cookie' pour une meilleure compatibilité  
✅ HTTP-only activé  
✅ SameSite=Lax pour le développement

### 4. `bootstrap/app.php`
```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->api(prepend: [
        \Illuminate\Http\Middleware\HandleCors::class,
        \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
    ]);
})
```

✅ Middleware CORS ajouté  
✅ Middleware Sanctum stateful ajouté

### 5. `routes/api.php`
```php
// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    // ... autres routes
});
```

✅ Toutes les routes d'authentification dans api.php  
✅ Middleware auth:sanctum appliqué

### 6. `app/Http/Controllers/API/AuthController.php`
✅ `login()` : Accepte email ou username, retourne l'utilisateur directement  
✅ `logout()` : Invalide la session proprement  
✅ `user()` : Retourne l'utilisateur directement (pas de wrapper)  
✅ `register()` : Connecte automatiquement après inscription

## 🚀 Flux d'Authentification

### 1. Récupérer le cookie CSRF (OBLIGATOIRE)
```javascript
await axios.get('http://localhost:8000/sanctum/csrf-cookie', {
  withCredentials: true
});
```
**Réponse** : 204 No Content + cookie `XSRF-TOKEN` défini

### 2. Se connecter
```javascript
const response = await axios.post('http://localhost:8000/api/login', {
  login: 'user@example.com', // ou username
  password: 'password',
  remember: false
}, {
  withCredentials: true
});

// Response: { id, name, email, ... } (objet user directement)
console.log(response.data);
```
**Réponse** : 200 OK + cookie de session `laravel_session` défini

### 3. Vérifier l'utilisateur connecté
```javascript
const response = await axios.get('http://localhost:8000/api/user', {
  withCredentials: true
});

// Response: { id, name, email, ... } (objet user)
console.log(response.data);
```
**Réponse** : 200 OK avec l'utilisateur OU 401 si non authentifié

### 4. Se déconnecter
```javascript
await axios.post('http://localhost:8000/api/logout', {}, {
  withCredentials: true
});
```
**Réponse** : 200 OK + cookies supprimés

## 🔒 Cookies Gérés par Sanctum

1. **XSRF-TOKEN** : Cookie CSRF (HttpOnly)
   - Défini par : `GET /sanctum/csrf-cookie`
   - Utilisé pour : Validation CSRF sur les requêtes POST/PUT/DELETE
   - Durée : Session

2. **laravel_session** : Cookie de session (HttpOnly)
   - Défini par : `POST /api/login` ou `POST /api/register`
   - Utilisé pour : Authentification de l'utilisateur
   - Durée : Selon `SESSION_LIFETIME` (120 minutes par défaut)

## ✅ Points Critiques

### ✅ Session Persistante
- Le cookie de session reste valide après refresh du navigateur
- La session persiste selon `SESSION_LIFETIME`
- Avec `remember: true`, la session peut être prolongée

### ✅ Pas d'Erreur 401 sur /api/user
- Le middleware `EnsureFrontendRequestsAreStateful` vérifie automatiquement les cookies
- Si le cookie est valide, l'utilisateur est authentifié
- Si le cookie est invalide/expiré, retourne 401

### ✅ CORS Correctement Configuré
- `supports_credentials: true` permet l'envoi de cookies
- `allowed_origins` spécifique à `http://localhost:3000`
- Tous les headers nécessaires autorisés

## 🧪 Tests

### Test 1: CSRF Cookie
```bash
curl -v http://localhost:8000/sanctum/csrf-cookie \
  -H "Origin: http://localhost:3000" \
  -c cookies.txt
```
**Attendu** : Cookie `XSRF-TOKEN` dans la réponse

### Test 2: Login
```bash
curl -v http://localhost:8000/api/login \
  -X POST \
  -H "Content-Type: application/json" \
  -H "Origin: http://localhost:3000" \
  -H "X-XSRF-TOKEN: [token from cookie]" \
  -d '{"login":"user@example.com","password":"password"}' \
  -b cookies.txt \
  -c cookies.txt
```
**Attendu** : Cookie `laravel_session` dans la réponse + JSON utilisateur

### Test 3: User
```bash
curl -v http://localhost:8000/api/user \
  -H "Origin: http://localhost:3000" \
  -b cookies.txt
```
**Attendu** : JSON utilisateur (pas de 401)

### Test 4: Logout
```bash
curl -v http://localhost:8000/api/logout \
  -X POST \
  -H "Origin: http://localhost:3000" \
  -H "X-XSRF-TOKEN: [token from cookie]" \
  -b cookies.txt \
  -c cookies.txt
```
**Attendu** : Cookies supprimés

## 🐛 Dépannage

### Problème : Erreur 401 sur /api/user après login
**Solutions** :
1. Vérifier que `withCredentials: true` est présent dans toutes les requêtes
2. Vérifier que `SANCTUM_STATEFUL_DOMAINS` contient bien `localhost:3000`
3. Vérifier que `SESSION_DOMAIN=localhost` dans `.env`
4. Vérifier que le cookie CSRF a été récupéré avant le login
5. Nettoyer le cache : `php artisan optimize:clear`

### Problème : Cookies non envoyés
**Solutions** :
1. Vérifier `supports_credentials: true` dans `config/cors.php`
2. Vérifier que l'origine est bien `http://localhost:3000`
3. Vérifier que `SESSION_DOMAIN=localhost` (pas `.localhost`)

### Problème : Session expirée trop rapidement
**Solutions** :
1. Augmenter `SESSION_LIFETIME` dans `.env`
2. Utiliser `remember: true` lors du login
3. Vérifier que `expire_on_close` est à `false`

## 📝 Checklist Finale

- [x] `config/sanctum.php` avec tous les domaines stateful
- [x] `config/cors.php` avec `supports_credentials: true`
- [x] `config/session.php` avec `driver: cookie`
- [x] `bootstrap/app.php` avec middlewares Sanctum
- [x] `routes/api.php` avec toutes les routes d'auth
- [x] `AuthController` optimisé pour retourner directement l'utilisateur
- [ ] `.env` mis à jour avec toutes les variables
- [ ] Cache Laravel nettoyé (`php artisan optimize:clear`)
- [ ] Serveur redémarré

## 🎉 Résultat Attendu

✅ Connexion avec email ou username  
✅ Session persistante après refresh  
✅ Pas d'erreur 401 sur `/api/user` si connecté  
✅ Déconnexion propre avec suppression des cookies  
✅ Cookies HTTP-only (sécurité maximale)

