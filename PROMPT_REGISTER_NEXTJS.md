# 📋 PROMPT POUR CONFIGURER LE REGISTER DANS NEXT.JS

## 🚀 Copiez ce prompt dans Cursor :

```
Je dois configurer mon frontend Next.js pour que l'inscription (register) fonctionne avec mon backend Laravel Sanctum.

Mon backend Laravel est sur http://localhost:8000 avec l'endpoint :
- POST /api/register

Les données requises pour register sont :
{
  "first_name": string (requis),
  "last_name": string (requis),
  "username": string (requis, unique),
  "email": string (requis, email valide, unique),
  "password": string (requis, min 8 caractères, avec majuscule, minuscule, chiffre, symbole),
  "password_confirmation": string (requis, doit correspondre à password),
  "company_share": number (optionnel, 0-100, défaut 100),
  "profile_image": string (optionnel)
}

IMPORTANT :
- Toutes les requêtes doivent avoir withCredentials: true
- Le cookie CSRF doit être récupéré AVANT chaque POST
- Le backend retourne directement l'utilisateur (pas de wrapper) : { id, first_name, last_name, username, email, ... }
- L'utilisateur est automatiquement connecté après l'inscription
- En cas d'erreur de validation (422), le backend retourne { message: "...", errors: { field: ["erreur1", "erreur2"] } }

TÂCHES À EFFECTUER :

1. Si tu n'as pas encore de fichier lib/api.ts, crée-le avec :
   - Instance axios configurée avec baseURL: 'http://localhost:8000'
   - withCredentials: true pour tous les appels
   - Intercepteur pour récupérer automatiquement le cookie CSRF avant chaque POST/PUT/DELETE
   - Gestion des erreurs (intercepteur response pour les erreurs 401, 422, etc.)

2. Crée ou modifie lib/auth.ts avec :
   - Fonction getCsrfCookie() : GET /sanctum/csrf-cookie
   - Fonction register(data) : POST /api/register avec les données
   - Gestion des erreurs de validation (422) avec messages en français
   - Retourne l'utilisateur directement

3. Si tu as un AuthContext, mets à jour la fonction register() pour :
   - Appeler getCsrfCookie() avant register
   - Appeler api.post('/api/register', data)
   - Mettre à jour l'état user avec l'utilisateur retourné
   - Gérer les erreurs de validation et afficher les messages

4. Dans le composant de formulaire d'inscription :
   - Validation côté client pour améliorer l'UX
   - Afficher les erreurs de validation du backend (champs spécifiques)
   - Gérer le loading pendant l'inscription
   - Rediriger après inscription réussie ou afficher un message de succès

5. Les règles de validation du mot de passe (pour afficher les règles à l'utilisateur) :
   - Minimum 8 caractères
   - Au moins une majuscule et une minuscule
   - Au moins un chiffre
   - Au moins un symbole

6. Format des erreurs de validation à afficher :
   - Si erreur 422 : afficher les erreurs par champ (errors.email, errors.password, etc.)
   - Si erreur 500 : afficher un message générique
   - Si erreur réseau : afficher un message de connexion

Crée ou modifie les fichiers nécessaires pour que le register fonctionne parfaitement avec le backend Laravel Sanctum.
```

---

## 📝 Exemple de Code Complet

Si vous voulez que je vous donne le code complet à intégrer, voici ce que vous pouvez demander à Cursor après :

```
Maintenant, donne-moi le code complet pour :
1. La fonction register() dans lib/auth.ts
2. La fonction register() dans AuthContext
3. Un exemple de composant RegisterForm avec gestion des erreurs
```

---

## 🔧 Points Clés à Vérifier

1. ✅ **withCredentials: true** dans toutes les requêtes axios
2. ✅ **Cookie CSRF récupéré** avant chaque POST
3. ✅ **Gestion des erreurs 422** (validation) avec affichage par champ
4. ✅ **Format de réponse** : l'utilisateur est retourné directement
5. ✅ **Connexion automatique** : l'utilisateur est connecté après register

## 📋 Format des Données

### Données à envoyer :
```typescript
{
  first_name: string;
  last_name: string;
  username: string;
  email: string;
  password: string;
  password_confirmation: string;
  company_share?: number; // optionnel
  profile_image?: string; // optionnel
}
```

### Réponse succès (201) :
```typescript
{
  id: string;
  first_name: string;
  last_name: string;
  username: string;
  email: string;
  company_share: number;
  profile_image: string | null;
  created_at: string;
  updated_at: string;
  // ... autres champs calculés
}
```

### Réponse erreur (422) :
```typescript
{
  message: "The given data was invalid.",
  errors: {
    email: ["Cet email est déjà utilisé"],
    password: [
      "Le mot de passe doit contenir au moins une majuscule et une minuscule",
      "Le mot de passe doit contenir au moins un chiffre",
      "Le mot de passe doit contenir au moins un symbole"
    ],
    username: ["Ce nom d'utilisateur est déjà utilisé"]
  }
}
```

