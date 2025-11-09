# ✅ Configuration Laravel Sanctum - Récapitulatif Final

## 📝 Configuration .env

Ajoutez/modifiez ces variables dans votre `.env` :

```env
# Sanctum Configuration
SANCTUM_STATEFUL_DOMAINS=localhost:3000

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
- ✅ `stateful` avec `localhost:3000`
- ✅ `expiration` => 43200 (12 heures)
- ✅ `guard` => ['web']

### 2. `config/cors.php`
- ✅ `paths` => ['api/*', 'login', 'logout', 'sanctum/csrf-cookie']
- ✅ `allowed_origins` => ['http://localhost:3000']
- ✅ `supports_credentials` => true
- ✅ `allowed_headers` => ['*']
- ✅ `allowed_methods` => ['*']

### 3. `bootstrap/app.php`
- ✅ Middleware `HandleCors` ajouté
- ✅ Middleware `EnsureFrontendRequestsAreStateful` ajouté

### 4. `routes/web.php`
- ✅ `POST /login` → AuthController@login
- ✅ `POST /logout` → AuthController@logout (avec auth:sanctum)

### 5. `routes/api.php`
- ✅ `GET /api/user` → retourne directement `$request->user()`

### 6. `app/Http/Controllers/API/AuthController.php`
- ✅ `login()` : accepte `email` et `password`, retourne directement l'utilisateur
- ✅ `logout()` : retourne `{'message': 'Déconnexion réussie'}`
- ✅ Utilise `Auth::guard('web')->attempt()` pour les sessions

## 🚀 Flux d'Authentification

### 1. Récupérer le cookie CSRF (OBLIGATOIRE avant login)
```javascript
await axios.get('http://localhost:8000/sanctum/csrf-cookie', {
  withCredentials: true
});
```

### 2. Se connecter
```javascript
const response = await axios.post('http://localhost:8000/login', {
  email: 'user@example.com',
  password: 'password',
  remember: false
}, {
  withCredentials: true
});

// Response: { id, name, email, ... } (objet user directement)
console.log(response.data);
```

### 3. Vérifier l'utilisateur connecté
```javascript
const response = await axios.get('http://localhost:8000/api/user', {
  withCredentials: true
});

// Response: { id, name, email, ... } (objet user)
console.log(response.data);
```

### 4. Se déconnecter
```javascript
await axios.post('http://localhost:8000/logout', {}, {
  withCredentials: true
});
```

## 🔍 Points Importants

1. **Toujours utiliser `withCredentials: true`** dans toutes les requêtes axios
2. **Récupérer le cookie CSRF AVANT** la première requête POST/PUT/DELETE
3. **Les routes `/login` et `/logout` sont dans `routes/web.php`** (pas dans `routes/api.php`)
4. **La route `/api/user` est dans `routes/api.php`** avec le middleware `auth:sanctum`
5. **Les cookies sont HTTP-only** : `laravel_session` et `XSRF-TOKEN` sont automatiquement gérés

## 🧪 Test Rapide

```bash
# 1. Nettoyer le cache
php artisan optimize:clear

# 2. Démarrer le serveur
php artisan serve
```

Testez ensuite depuis votre frontend Next.js :

```javascript
// 1. Récupérer CSRF
await axios.get('http://localhost:8000/sanctum/csrf-cookie', { withCredentials: true });

// 2. Se connecter
const loginResponse = await axios.post('http://localhost:8000/login', {
  email: 'test@example.com',
  password: 'password'
}, { withCredentials: true });
console.log('Login:', loginResponse.data);

// 3. Vérifier l'utilisateur
const userResponse = await axios.get('http://localhost:8000/api/user', {
  withCredentials: true
});
console.log('User:', userResponse.data);
```

## ❌ Dépannage

Si vous obtenez une erreur `{}` lors du login :

1. ✅ Vérifiez que `SANCTUM_STATEFUL_DOMAINS=localhost:3000` dans `.env`
2. ✅ Vérifiez que `SESSION_DOMAIN=localhost` dans `.env`
3. ✅ Vérifiez que `supports_credentials: true` dans `config/cors.php`
4. ✅ Vérifiez que le cookie CSRF a été récupéré avant le login
5. ✅ Vérifiez que `withCredentials: true` est présent dans toutes les requêtes
6. ✅ Vérifiez que `APP_URL=http://localhost:8000` dans `.env`

## 📋 Checklist Finale

- [x] `config/sanctum.php` configuré
- [x] `config/cors.php` configuré
- [x] `bootstrap/app.php` avec middlewares
- [x] `routes/web.php` avec `/login` et `/logout`
- [x] `routes/api.php` avec `/api/user`
- [x] `AuthController` simplifié
- [ ] `.env` mis à jour avec les bonnes valeurs
- [ ] Cache Laravel nettoyé (`php artisan optimize:clear`)
- [ ] Serveur redémarré

