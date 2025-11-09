# 🚀 Guide Rapide Postman - Login

## ⚡ Démarrage Rapide

### 1️⃣ Récupérer le Cookie CSRF

**GET** `http://localhost:8000/sanctum/csrf-cookie`

**Headers** :
```
Origin: http://localhost:3000
Accept: application/json
```

✅ **Résultat** : HTTP 204 + Cookie `XSRF-TOKEN` dans l'onglet **Cookies**

---

### 2️⃣ Se Connecter

**POST** `http://localhost:8000/api/login`

**Headers** :
```
Content-Type: application/json
Origin: http://localhost:3000
Accept: application/json
X-XSRF-TOKEN: [COPIER LA VALEUR DU COOKIE XSRF-TOKEN ICI]
```

**Body** (raw JSON) :
```json
{
  "login": "test@example.com",
  "password": "password123"
}
```

✅ **Résultat** : HTTP 200 + Utilisateur en JSON + Cookie `laravel_session`

---

### 3️⃣ Vérifier l'Utilisateur

**GET** `http://localhost:8000/api/user`

**Headers** :
```
Origin: http://localhost:3000
Accept: application/json
```

✅ **Résultat** : HTTP 200 + Utilisateur connecté

---

## 🔑 Comment Obtenir le Token XSRF-TOKEN dans Postman

1. Après avoir exécuté la requête 1 (CSRF Cookie)
2. Cliquez sur l'onglet **Cookies** (en bas de Postman)
3. Trouvez le cookie `XSRF-TOKEN`
4. **Copiez la valeur** (elle est URL-encodée, c'est normal)
5. Collez-la dans le header `X-XSRF-TOKEN` de la requête Login

**OU** utilisez le script Pre-request ci-dessous pour automatiser.

---

## 🤖 Script Pre-request (Automatique)

Ajoutez ce script dans l'onglet **Pre-request Script** de votre requête Login :

```javascript
// Extraire automatiquement le token CSRF
const cookies = pm.cookies.all();
const xsrfCookie = cookies.find(cookie => cookie.name === 'XSRF-TOKEN');

if (xsrfCookie) {
    const token = decodeURIComponent(xsrfCookie.value);
    pm.environment.set('xsrf_token', token);
    console.log('✅ Token CSRF:', token.substring(0, 30) + '...');
} else {
    console.log('❌ Pas de cookie XSRF-TOKEN. Appelez d\'abord /sanctum/csrf-cookie');
}
```

Puis dans les **Headers**, utilisez :
```
X-XSRF-TOKEN: {{xsrf_token}}
```

---

## 📋 Exemple de Données de Test

### Login avec Email :
```json
{
  "login": "test@example.com",
  "password": "Password123!",
  "remember": false
}
```

### Login avec Username :
```json
{
  "login": "testuser",
  "password": "Password123!",
  "remember": false
}
```

---

## ✅ Vérifications

- [ ] Cookie `XSRF-TOKEN` présent après requête 1
- [ ] Header `X-XSRF-TOKEN` présent dans requête 2
- [ ] Cookie `laravel_session` présent après requête 2
- [ ] Requête 3 retourne l'utilisateur connecté (pas 401)

---

## 🐛 Erreurs Courantes

### 419 CSRF token mismatch
→ Vérifiez que vous avez appelé `/sanctum/csrf-cookie` avant
→ Vérifiez que le token XSRF est bien dans le header

### 401 Unauthenticated
→ Vérifiez que le cookie `laravel_session` est présent
→ Vérifiez que "Send cookies" est activé

### 401 Identifiants invalides
→ L'utilisateur n'existe pas ou le mot de passe est incorrect

