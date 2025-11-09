# 📋 Guide Complet Postman - Test Register

## 🚀 Étapes Détaillées pour Tester Register sur Postman

### ÉTAPE 1 : Créer une Collection Postman

1. Ouvrez Postman
2. Cliquez sur **"New"** → **"Collection"**
3. Nommez-la : `Laravel Sanctum Auth`
4. Cliquez sur **"Create"**

---

### ÉTAPE 2 : Configurer les Variables d'Environnement

1. Cliquez sur votre collection `Laravel Sanctum Auth`
2. Allez dans l'onglet **"Variables"**
3. Ajoutez ces variables :
   - **Variable** : `base_url` → **Valeur** : `http://localhost:8000`
   - **Variable** : `frontend_url` → **Valeur** : `http://localhost:3000`
4. Cliquez sur **"Save"**

---

### ÉTAPE 3 : Requête 1 - Récupérer le Cookie CSRF

#### 3.1 Créer la Requête

1. Dans votre collection, cliquez sur **"Add request"**
2. Nommez-la : `1. Get CSRF Cookie`
3. Méthode : Sélectionnez **`GET`**
4. URL : `{{base_url}}/sanctum/csrf-cookie`

#### 3.2 Configurer les Headers

1. Allez dans l'onglet **"Headers"**
2. Ajoutez ces headers :
   ```
   Key: Origin
   Value: {{frontend_url}}
   ```
   ```
   Key: Accept
   Value: application/json
   ```

#### 3.3 Configurer les Cookies

1. Allez dans l'onglet **"Settings"** (en bas de Postman)
2. Cochez **"Save cookies"**
3. Cochez **"Send cookies"**

#### 3.4 Envoyer la Requête

1. Cliquez sur **"Send"**
2. **Résultat attendu** : HTTP `204 No Content`

#### 3.5 Vérifier le Cookie

1. Cliquez sur l'onglet **"Cookies"** (en bas de Postman, à côté de "Headers")
2. Vous devriez voir le cookie `XSRF-TOKEN`
3. **Copiez la valeur** du cookie (ex: `eyJpdiI6IlpyRW9XTHp6dXF3N2VZdWlEbFZqT1E9PSIsInZhbH...`)
4. **Important** : Gardez cette valeur pour l'étape suivante

---

### ÉTAPE 4 : Requête 2 - Register (Inscription)

#### 4.1 Créer la Requête

1. Dans votre collection, cliquez sur **"Add request"**
2. Nommez-la : `2. Register`
3. Méthode : Sélectionnez **`POST`**
4. URL : `{{base_url}}/api/register`

#### 4.2 Configurer les Headers

1. Allez dans l'onglet **"Headers"**
2. Ajoutez ces headers :
   ```
   Key: Content-Type
   Value: application/json
   ```
   ```
   Key: Origin
   Value: {{frontend_url}}
   ```
   ```
   Key: Accept
   Value: application/json
   ```
   ```
   Key: X-XSRF-TOKEN
   Value: [COLLEZ ICI LA VALEUR DU COOKIE XSRF-TOKEN DE L'ÉTAPE 3.5]
   ```
   ⚠️ **Important** : Remplacez `[COLLEZ ICI...]` par la valeur réelle du cookie

#### 4.3 Configurer le Body

1. Allez dans l'onglet **"Body"**
2. Sélectionnez **"raw"**
3. Dans le menu déroulant à droite, sélectionnez **"JSON"**
4. Collez ce JSON :
   ```json
   {
     "first_name": "John",
     "last_name": "Doe",
     "username": "johndoe",
     "email": "john.doe@example.com",
     "password": "Password123!",
     "password_confirmation": "Password123!",
     "company_share": 100
   }
   ```

#### 4.4 Configurer les Cookies

1. Allez dans l'onglet **"Settings"** (en bas)
2. Cochez **"Send cookies"** (pour envoyer les cookies de la requête précédente)

#### 4.5 Envoyer la Requête

1. Cliquez sur **"Send"**
2. **Résultat attendu** : HTTP `201 Created`
3. **Réponse** : Vous devriez voir l'utilisateur créé en JSON :
   ```json
   {
     "id": "019a5ac6-...",
     "first_name": "John",
     "last_name": "Doe",
     "username": "johndoe",
     "email": "john.doe@example.com",
     "company_share": "100.00",
     "profile_image": null,
     "created_at": "2025-11-06T20:06:15.000000Z",
     "updated_at": "2025-11-06T20:06:15.000000Z",
     ...
   }
   ```

---

### ÉTAPE 5 : Requête 3 - Vérifier l'Utilisateur Connecté

#### 5.1 Créer la Requête

1. Dans votre collection, cliquez sur **"Add request"**
2. Nommez-la : `3. Get User (vérifier connexion)`
3. Méthode : Sélectionnez **`GET`**
4. URL : `{{base_url}}/api/user`

#### 5.2 Configurer les Headers

1. Allez dans l'onglet **"Headers"**
2. Ajoutez ces headers :
   ```
   Key: Origin
   Value: {{frontend_url}}
   ```
   ```
   Key: Accept
   Value: application/json
   ```

#### 5.3 Configurer les Cookies

1. Allez dans l'onglet **"Settings"**
2. Cochez **"Send cookies"**

#### 5.4 Envoyer la Requête

1. Cliquez sur **"Send"**
2. **Résultat attendu** : HTTP `200 OK`
3. **Réponse** : Vous devriez voir le même utilisateur que celui créé à l'étape 4
4. ✅ **Cela confirme que l'utilisateur est automatiquement connecté après register**

---

## 🧪 Tests Supplémentaires (Optionnels)

### Test 1 : Register avec Mot de Passe Invalide

1. Répétez l'**ÉTAPE 3** (récupérer un nouveau CSRF)
2. Créez une nouvelle requête : `Register - Password Invalid`
3. Méthode : `POST`
4. URL : `{{base_url}}/api/register`
5. Headers : Identiques à l'étape 4.2
6. Body :
   ```json
   {
     "first_name": "Test",
     "last_name": "User",
     "username": "testuser123",
     "email": "test123@example.com",
     "password": "password",
     "password_confirmation": "password"
   }
   ```
7. **Résultat attendu** : HTTP `422 Unprocessable Entity`
8. **Erreurs** :
   ```json
   {
     "message": "The given data was invalid.",
     "errors": {
       "password": [
         "Le mot de passe doit contenir au moins une majuscule et une minuscule",
         "Le mot de passe doit contenir au moins un symbole",
         "Le mot de passe doit contenir au moins un chiffre"
       ]
     }
   }
   ```

### Test 2 : Register avec Email Déjà Utilisé

1. Répétez l'**ÉTAPE 3** (récupérer un nouveau CSRF)
2. Créez une nouvelle requête : `Register - Email Duplicate`
3. Méthode : `POST`
4. URL : `{{base_url}}/api/register`
5. Headers : Identiques à l'étape 4.2
6. Body : Utilisez le même email que celui créé à l'étape 4
   ```json
   {
     "first_name": "Test",
     "last_name": "User",
     "username": "testuser456",
     "email": "john.doe@example.com",
     "password": "Password123!",
     "password_confirmation": "Password123!"
   }
   ```
7. **Résultat attendu** : HTTP `422 Unprocessable Entity`
8. **Erreur** :
   ```json
   {
     "message": "The given data was invalid.",
     "errors": {
       "email": ["Cet email est déjà utilisé"]
     }
   }
   ```

---

## 🤖 Automatisation avec Pre-request Script (Optionnel)

Pour éviter de copier manuellement le token CSRF, vous pouvez ajouter un script automatique :

### Dans la Requête "2. Register"

1. Allez dans l'onglet **"Pre-request Script"**
2. Collez ce script :
   ```javascript
   // Récupérer automatiquement le cookie XSRF-TOKEN
   const cookies = pm.cookies.all();
   const xsrfCookie = cookies.find(cookie => cookie.name === 'XSRF-TOKEN');

   if (xsrfCookie) {
       // Décoder le token (il est URL-encodé dans le cookie)
       const xsrfToken = decodeURIComponent(xsrfCookie.value);
       pm.environment.set('xsrf_token', xsrfToken);
       console.log('✅ Token CSRF extrait:', xsrfToken.substring(0, 30) + '...');
   } else {
       console.log('❌ Pas de cookie XSRF-TOKEN. Appelez d\'abord /sanctum/csrf-cookie');
   }
   ```

3. Dans les **Headers**, utilisez :
   ```
   Key: X-XSRF-TOKEN
   Value: {{xsrf_token}}
   ```

4. Créez une variable d'environnement `xsrf_token` dans votre collection

---

## ✅ Checklist Complète

- [ ] Collection créée
- [ ] Variables `base_url` et `frontend_url` configurées
- [ ] Requête 1 : CSRF Cookie (GET) - HTTP 204
- [ ] Cookie `XSRF-TOKEN` visible dans l'onglet Cookies
- [ ] Token CSRF copié
- [ ] Requête 2 : Register (POST) - HTTP 201
- [ ] Utilisateur créé visible dans la réponse
- [ ] Cookie `laravel_session` visible dans l'onglet Cookies
- [ ] Requête 3 : Get User (GET) - HTTP 200
- [ ] Utilisateur connecté confirmé

---

## 🐛 Dépannage

### Erreur 419 : CSRF token mismatch
**Solution** :
1. Vérifiez que vous avez appelé `/sanctum/csrf-cookie` avant
2. Vérifiez que le header `X-XSRF-TOKEN` contient bien le token (pas URL-encodé)
3. Vérifiez que "Send cookies" est activé

### Erreur 422 : Validation failed
**Solution** :
- Vérifiez que tous les champs requis sont présents
- Vérifiez que le mot de passe respecte les règles :
  - Minimum 8 caractères
  - Au moins une majuscule et une minuscule
  - Au moins un chiffre
  - Au moins un symbole
- Vérifiez que `password_confirmation` correspond à `password`

### Cookies non envoyés
**Solution** :
1. Allez dans **Settings** → **General** → Cochez "Automatically follow redirects"
2. Dans la requête, onglet **Settings** → Cochez "Send cookies"
3. Vérifiez que le domaine du cookie est `localhost`

---

## 📸 Exemple de Configuration Postman

### Headers pour Register :
```
Content-Type: application/json
Origin: http://localhost:3000
Accept: application/json
X-XSRF-TOKEN: eyJpdiI6IlpyRW9XTHp6dXF3N2VZdWlEbFZqT1E9PSIsInZhbH...
```

### Body pour Register :
```json
{
  "first_name": "John",
  "last_name": "Doe",
  "username": "johndoe",
  "email": "john.doe@example.com",
  "password": "Password123!",
  "password_confirmation": "Password123!",
  "company_share": 100
}
```

### Réponse Attendue (201) :
```json
{
  "id": "019a5ac6-e498-73c6-9012-578a7c37a1ab",
  "first_name": "John",
  "last_name": "Doe",
  "username": "johndoe",
  "email": "john.doe@example.com",
  "company_share": "100.00",
  "profile_image": null,
  "created_at": "2025-11-06T20:06:15.000000Z",
  "updated_at": "2025-11-06T20:06:15.000000Z"
}
```

---

## 🎯 Résumé des Étapes

1. **GET** `/sanctum/csrf-cookie` → Récupère le cookie CSRF
2. **POST** `/api/register` → Crée l'utilisateur (avec token CSRF dans header)
3. **GET** `/api/user` → Vérifie que l'utilisateur est connecté

**Important** : N'oubliez pas de récupérer un nouveau token CSRF avant chaque requête POST !

