# 📚 Référence API pour la Page Wallet

## 🔗 Endpoints Disponibles

### 1. GET /api/user
**Description** : Récupère les statistiques de l'utilisateur connecté

**Headers** :
```
Origin: http://localhost:3000
Accept: application/json
```

**Réponse (200)** :
```json
{
  "id": "uuid",
  "first_name": "Zoran",
  "last_name": "Stro",
  "email": "zoranstro@gmail.com",
  "calculated_wallet": 17416.87,
  "total_sale": 20883.35,
  "total_expense": 3466.48,
  "wallet": 17416.87,
  "total_articles": 9,
  "total_low_stock": 0,
  "total_stock_value": 12500.00,
  "total_remaining_quantity": 150,
  "created_at": "2025-11-06T20:06:15.000000Z",
  "updated_at": "2025-11-06T20:06:15.000000Z"
}
```

**Champs importants pour Wallet** :
- `calculated_wallet` : Solde actuel (total_sale - total_expense)
- `total_sale` : Total des ventes
- `total_expense` : Total des dépenses
- `wallet` : Wallet personnel (calculated_wallet * company_share / 100)

---

### 2. GET /api/transactions
**Description** : Liste toutes les transactions de l'utilisateur connecté

**Headers** :
```
Origin: http://localhost:3000
Accept: application/json
```

**Réponse (200)** :
```json
{
  "success": true,
  "message": "Transactions récupérées avec succès",
  "data": [
    {
      "id": "uuid",
      "user_id": "uuid",
      "article_id": "uuid",
      "variable_id": null,
      "name": "Vente de 2 Ordinateur Portable Dell",
      "quantity": 2,
      "amount": 1799.98,
      "sale_price": 899.99,
      "type": "sale",
      "created_at": "2025-11-05T12:19:59.000000Z",
      "updated_at": "2025-11-05T12:19:59.000000Z",
      "article": {
        "id": "uuid",
        "name": "Ordinateur Portable Dell",
        "sale_price": "899.99",
        "quantity": 15,
        "type": "simple"
      },
      "variation": null
    },
    {
      "id": "uuid",
      "user_id": "uuid",
      "article_id": null,
      "variable_id": null,
      "name": "Loyer du local commercial",
      "quantity": null,
      "amount": 1200.00,
      "sale_price": null,
      "type": "expense",
      "created_at": "2025-11-01T10:00:00.000000Z",
      "updated_at": "2025-11-01T10:00:00.000000Z",
      "article": null,
      "variation": null
    }
  ]
}
```

**Structure Transaction** :
- `type: "sale"` → Transaction de vente (a `article_id`, `quantity`, `sale_price`)
- `type: "expense"` → Dépense (a `name`, `amount`, pas d'`article_id`)

---

### 3. PUT /api/transactions/{id}
**Description** : Modifie une transaction existante

**Headers** :
```
Content-Type: application/json
Origin: http://localhost:3000
Accept: application/json
X-XSRF-TOKEN: [token]
```

**Body pour type="sale"** :
```json
{
  "name": "Vente de 3 Ordinateur Portable Dell",
  "quantity": 3
}
```

**Body pour type="expense"** :
```json
{
  "name": "Loyer du local commercial",
  "amount": 1200.00
}
```

**Réponse (200)** :
```json
{
  "success": true,
  "message": "Transaction modifiée avec succès",
  "data": {
    "id": "uuid",
    "name": "Vente de 3 Ordinateur Portable Dell",
    "quantity": 3,
    "amount": 2699.97,
    "type": "sale",
    // ... autres champs
  }
}
```

**Réponse (422) - Erreur de validation** :
```json
{
  "success": false,
  "message": "Erreur de validation",
  "errors": {
    "quantity": ["Quantité insuffisante. Quantité disponible: 5"]
  }
}
```

---

### 4. DELETE /api/transactions/{id}
**Description** : Supprime une transaction

**Headers** :
```
Origin: http://localhost:3000
Accept: application/json
X-XSRF-TOKEN: [token]
```

**Réponse (200)** :
```json
{
  "success": true,
  "message": "Transaction supprimée avec succès"
}
```

**Réponse (404)** :
```json
{
  "success": false,
  "message": "Transaction non trouvée"
}
```

---

## 🔐 Authentification

Tous les endpoints nécessitent une authentification via cookies HTTP-only.

**Important** :
- Utiliser `withCredentials: true` dans axios
- Le cookie CSRF doit être récupéré avant chaque POST/PUT/DELETE
- Pas de Bearer token nécessaire

---

## 📊 Mapping des Données pour l'Affichage

### Transaction de Vente (type="sale")
```typescript
const displayText = `Vente de ${transaction.quantity} ${transaction.article?.name || 'Article'}`;
const displayAmount = transaction.amount || (transaction.quantity * transaction.sale_price);
const displayDate = dayjs(transaction.created_at).format("DD MMM YYYY");
```

### Transaction de Dépense (type="expense")
```typescript
const displayText = `Dépense : ${transaction.name}`;
const displayAmount = transaction.amount;
const displayDate = dayjs(transaction.created_at).format("DD MMM YYYY");
```

---

## 🎨 Exemple de Format de Montant

```typescript
const formatCurrency = (amount: number): string => {
  return new Intl.NumberFormat('fr-FR', {
    style: 'currency',
    currency: 'EUR',
  }).format(amount);
};

// Usage: formatCurrency(17416.87) → "17 416,87 €"
```

---

## ⚠️ Gestion des Erreurs

Tous les endpoints peuvent retourner :
- **401** : Non authentifié
- **403** : Non autorisé
- **404** : Ressource non trouvée
- **422** : Erreur de validation
- **500** : Erreur serveur

**Exemple de gestion** :
```typescript
try {
  const response = await api.get('/api/transactions');
  setTransactions(response.data.data);
} catch (error: any) {
  if (error.response?.status === 401) {
    // Rediriger vers login
  } else if (error.response?.status === 422) {
    // Afficher les erreurs de validation
    const errors = error.response.data.errors;
  } else {
    toast.error('Erreur lors du chargement des transactions');
  }
}
```

