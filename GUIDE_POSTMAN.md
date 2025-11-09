# 🧪 Guide Postman pour Tester l'Authentification Sanctum

## 📋 Configuration Postman

### 1. Configuration Globale

1. Ouvrez Postman
2. Créez une nouvelle Collection : "Laravel Sanctum Auth"
3. Allez dans **Settings** (⚙️) de la collection
4. Dans l'onglet **Variables**, ajoutez :
   - `base_url` = `http://localhost:8000`
   - `frontend_url` = `http://localhost:3000`

## 🔐 Étapes pour Tester le Login

### Étape 1 : Récupérer le Cookie CSRF

**Requête 1 : GET CSRF Cookie**

- **Method**: `GET`
- **URL**: `{{base_url}}/sanctum/csrf-cookie`
- **Headers**:
  ```
  Origin: {{frontend_url}}
  Accept: application/json
  ```
- **Settings**:
  - ✅ Cocher "Save cookies" dans l'onglet **Cookies**
  
**Attendu** : HTTP 204 No Content
**Vérification** : Allez dans l'onglet **Cookies** et vérifiez que `XSRF-TOKEN` est présent

### Étape 2 : Se Connecter

**Requête 2 : POST Login**

- **Method**: `POST`
- **URL**: `{{base_url}}/api/login`
- **Headers**:
  ```
  Content-Type: application/json
  Origin: {{frontend_url}}
  Accept: application/json
  X-XSRF-TOKEN: {{xsrf_token}}
  ```
  ⚠️ **Note** : Pour obtenir le token XSRF, allez dans l'onglet **Cookies** après la requête 1, copiez la valeur de `XSRF-TOKEN` et créez une variable `xsrf_token` dans Postman, OU utilisez le script Pre-request ci-dessous.

- **Body** (raw JSON):
  ```json
  {
    "login": "test@example.com",
    "password": "password123"
  }
  ```
  Ou avec username :
  ```json
  {
    "login": "testuser",
    "password": "password123"
  }
  ```

- **Settings**:
  - ✅ Cocher "Save cookies"
  
**Attendu** : HTTP 200 OK avec l'utilisateur en JSON
**Vérification** : Cookie `laravel_session` doit être présent dans l'onglet **Cookies**

### Étape 3 : Vérifier l'Utilisateur Connecté

**Requête 3 : GET User**

- **Method**: `GET`
- **URL**: `{{base_url}}/api/user`
- **Headers**:
  ```
  Origin: {{frontend_url}}
  Accept: application/json
  ```
- **Settings**:
  - ✅ Cocher "Send cookies"
  
**Attendu** : HTTP 200 OK avec l'utilisateur connecté

### Étape 4 : Se Déconnecter

**Requête 4 : POST Logout**

- **Method**: `POST`
- **URL**: `{{base_url}}/api/logout`
- **Headers**:
  ```
  Content-Type: application/json
  Origin: {{frontend_url}}
  Accept: application/json
  X-XSRF-TOKEN: {{xsrf_token}}
  ```
- **Body** (raw JSON):
  ```json
  {}
  ```

**Attendu** : HTTP 200 OK avec `{"message": "Déconnexion réussie"}`

## 🔧 Script Pre-request pour Extraire le Token CSRF Automatiquement

Pour automatiser l'extraction du token CSRF, ajoutez ce script dans l'onglet **Pre-request Script** de votre requête Login :

```javascript
// Récupérer le cookie XSRF-TOKEN automatiquement
const cookies = pm.cookies.all();
const xsrfCookie = cookies.find(cookie => cookie.name === 'XSRF-TOKEN');

if (xsrfCookie) {
    // Décoder le token (il est URL-encodé dans le cookie)
    const xsrfToken = decodeURIComponent(xsrfCookie.value);
    pm.environment.set('xsrf_token', xsrfToken);
    console.log('Token CSRF extrait:', xsrfToken.substring(0, 50) + '...');
} else {
    console.log('Aucun cookie XSRF-TOKEN trouvé. Assurez-vous d\'avoir appelé /sanctum/csrf-cookie d\'abord.');
}
```

Puis dans les **Headers** de la requête Login, utilisez :
```
X-XSRF-TOKEN: {{xsrf_token}}
```

## 📝 Collection Postman Complète

### Requête 1 : CSRF Cookie
```
GET {{base_url}}/sanctum/csrf-cookie
Headers:
  Origin: {{frontend_url}}
  Accept: application/json
```

### Requête 2 : Login
```
POST {{base_url}}/api/login
Headers:
  Content-Type: application/json
  Origin: {{frontend_url}}
  Accept: application/json
  X-XSRF-TOKEN: {{xsrf_token}}
Body (JSON):
{
  "login": "test@example.com",
  "password": "password123"
}
```

### Requête 3 : Get User
```
GET {{base_url}}/api/user
Headers:
  Origin: {{frontend_url}}
  Accept: application/json
```

### Requête 4 : Logout
```
POST {{base_url}}/api/logout
Headers:
  Content-Type: application/json
  Origin: {{frontend_url}}
  Accept: application/json
  X-XSRF-TOKEN: {{xsrf_token}}
Body (JSON):
{}
```

## ✅ Checklist Postman

- [ ] Collection créée avec variables `base_url` et `frontend_url`
- [ ] Requête 1 : CSRF Cookie (GET) - HTTP 204
- [ ] Cookie `XSRF-TOKEN` visible dans l'onglet Cookies
- [ ] Requête 2 : Login (POST) - HTTP 200 avec utilisateur
- [ ] Cookie `laravel_session` visible dans l'onglet Cookies
- [ ] Requête 3 : Get User (GET) - HTTP 200 avec utilisateur
- [ ] Requête 4 : Logout (POST) - HTTP 200

## 🐛 Dépannage Postman

### Problème : Erreur 419 CSRF token mismatch
**Solution** :
1. Vérifiez que vous avez appelé `/sanctum/csrf-cookie` avant
2. Vérifiez que le header `X-XSRF-TOKEN` contient bien le token (pas URL-encodé)
3. Vérifiez que l'onglet **Cookies** est activé pour sauvegarder les cookies

### Problème : Erreur 401 Unauthenticated
**Solution** :
1. Vérifiez que le cookie `laravel_session` est présent
2. Vérifiez que "Send cookies" est activé dans les Settings
3. Vérifiez que l'origine est bien `http://localhost:3000`

### Problème : Cookies non envoyés
**Solution** :
1. Allez dans **Settings** → **General** → Cochez "Automatically follow redirects"
2. Dans la requête, onglet **Settings** → Cochez "Send cookies"
3. Vérifiez que le domaine du cookie est `localhost`

## 📸 Exemple de Configuration Postman

### Headers pour Login :
```
Content-Type: application/json
Origin: http://localhost:3000
Accept: application/json
X-XSRF-TOKEN: eyJpdiI6IlpyRW9XTHp6dXF3N2VZdWlEbFZqT1E9PSIsInZhbH...
```

### Body pour Login :
```json
{
  "login": "test@example.com",
  "password": "password123",
  "remember": false
}
```

### Réponse Attendue (200) :
```json
{
  "id": "019a53f6-...",
  "first_name": "Test",
  "last_name": "User",
  "username": "testuser",
  "email": "test@example.com",
  "company_share": "100.00",
  "profile_image": null,
  "created_at": "2025-11-05T12:19:59.000000Z",
  "updated_at": "2025-11-05T12:19:59.000000Z"
}
```

