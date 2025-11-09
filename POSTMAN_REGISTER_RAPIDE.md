# ⚡ Guide Rapide Postman - Register

## 🎯 3 Étapes Simples

---

### 📍 ÉTAPE 1 : Récupérer le Cookie CSRF

**1. Créez une nouvelle requête**
- Méthode : `GET`
- URL : `http://localhost:8000/sanctum/csrf-cookie`

**2. Headers** :
```
Origin: http://localhost:3000
Accept: application/json
```

**3. Settings** (en bas) :
- ✅ Cochez "Save cookies"
- ✅ Cochez "Send cookies"

**4. Cliquez sur "Send"**

✅ **Résultat** : HTTP `204 No Content`

**5. IMPORTANT** : Allez dans l'onglet **"Cookies"** (en bas)
- Trouvez le cookie `XSRF-TOKEN`
- **COPIEZ SA VALEUR** (ex: `eyJpdiI6IlpyRW9XTHp6dXF3N2VZdWlEbFZqT1E9PSIsInZhbH...`)

---

### 📍 ÉTAPE 2 : Register (Inscription)

**1. Créez une nouvelle requête**
- Méthode : `POST`
- URL : `http://localhost:8000/api/register`

**2. Headers** :
```
Content-Type: application/json
Origin: http://localhost:3000
Accept: application/json
X-XSRF-TOKEN: [COLLEZ ICI LA VALEUR DU COOKIE XSRF-TOKEN]
```
⚠️ Remplacez `[COLLEZ ICI...]` par la valeur copiée à l'étape 1

**3. Body** (onglet Body → raw → JSON) :
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

**4. Settings** (en bas) :
- ✅ Cochez "Send cookies"

**5. Cliquez sur "Send"**

✅ **Résultat** : HTTP `201 Created` + Utilisateur en JSON

---

### 📍 ÉTAPE 3 : Vérifier la Connexion

**1. Créez une nouvelle requête**
- Méthode : `GET`
- URL : `http://localhost:8000/api/user`

**2. Headers** :
```
Origin: http://localhost:3000
Accept: application/json
```

**3. Settings** (en bas) :
- ✅ Cochez "Send cookies"

**4. Cliquez sur "Send"**

✅ **Résultat** : HTTP `200 OK` + Utilisateur connecté

---

## 📋 Checklist

- [ ] Étape 1 : CSRF Cookie récupéré (HTTP 204)
- [ ] Cookie XSRF-TOKEN copié
- [ ] Étape 2 : Register réussi (HTTP 201)
- [ ] Utilisateur créé visible dans la réponse
- [ ] Étape 3 : Utilisateur connecté (HTTP 200)

---

## 🐛 Erreurs Courantes

### ❌ Erreur 419 : CSRF token mismatch
→ Vous n'avez pas appelé `/sanctum/csrf-cookie` avant
→ Le token XSRF n'est pas dans le header

### ❌ Erreur 422 : Validation failed
→ Vérifiez que le mot de passe contient :
  - Minimum 8 caractères
  - Au moins 1 majuscule et 1 minuscule
  - Au moins 1 chiffre
  - Au moins 1 symbole
→ Vérifiez que `password_confirmation` = `password`

### ❌ Erreur 401 : Unauthenticated
→ Les cookies ne sont pas envoyés
→ Cochez "Send cookies" dans Settings

---

## 💡 Astuce : Automatiser le Token CSRF

Dans la requête Register, onglet **"Pre-request Script"**, collez :

```javascript
const cookies = pm.cookies.all();
const xsrfCookie = cookies.find(cookie => cookie.name === 'XSRF-TOKEN');
if (xsrfCookie) {
    pm.environment.set('xsrf_token', decodeURIComponent(xsrfCookie.value));
}
```

Puis dans Headers, utilisez : `X-XSRF-TOKEN: {{xsrf_token}}`

---

## ✅ C'est tout !

Suivez ces 3 étapes et votre register fonctionnera sur Postman ! 🎉

