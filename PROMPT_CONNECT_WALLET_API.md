# 📋 PROMPT POUR CONNECTER LA PAGE WALLET À L'API LARAVEL

## 🚀 Copiez ce prompt dans Cursor :

```
Je dois connecter ma page Wallet (déjà créée) à mon backend Laravel pour qu'elle soit fonctionnelle.

## 🔗 CONFIGURATION API

**Base URL** : `http://localhost:8000`

**Authentification** : Cookies HTTP-only (withCredentials: true)
- Tous les appels doivent utiliser `withCredentials: true`
- Le cookie CSRF est géré automatiquement par l'intercepteur axios
- Pas de Bearer token nécessaire

**Instance axios** : Utiliser l'instance configurée dans `lib/api.ts` (déjà configurée avec withCredentials)

## 📊 1. RÉCUPÉRER LES STATISTIQUES (GET /api/user)

**Endpoint** : `GET http://localhost:8000/api/user`

**Réponse** :
```typescript
{
  id: string;
  calculated_wallet: number;  // Solde actuel
  total_sale: number;         // Total des ventes
  total_expense: number;      // Total des dépenses
  wallet: number;             // Revenu personnel
  // ... autres champs
}
```

**À faire** :
1. Dans le composant `WalletStats`, créer un hook `useEffect` qui appelle `GET /api/user`
2. Mapper les données :
   - Card "Solde actuel" → `user.calculated_wallet`
   - Card "Total des ventes" → `user.total_sale`
   - Card "Total des dépenses" → `user.total_expense`
   - Card "Revenu personnel" → `user.wallet`
3. Afficher un skeleton loader pendant le chargement initial
4. Recharger ces données après chaque modification/suppression de transaction

**Exemple de code** :
```typescript
const [stats, setStats] = useState({
  calculated_wallet: 0,
  total_sale: 0,
  total_expense: 0,
  wallet: 0,
});
const [loading, setLoading] = useState(true);

useEffect(() => {
  fetchUserStats();
}, []);

const fetchUserStats = async () => {
  try {
    const response = await api.get('/api/user');
    setStats({
      calculated_wallet: response.data.calculated_wallet || 0,
      total_sale: response.data.total_sale || 0,
      total_expense: response.data.total_expense || 0,
      wallet: response.data.wallet || 0,
    });
  } catch (error) {
    toast.error('Erreur lors du chargement des statistiques');
  } finally {
    setLoading(false);
  }
};
```

## 📋 2. AFFICHER LA LISTE DES TRANSACTIONS (GET /api/transactions)

**Endpoint** : `GET http://localhost:8000/api/transactions`

**Réponse** :
```typescript
{
  success: boolean;
  message: string;
  data: Transaction[];
}
```

**Structure Transaction** :
```typescript
interface Transaction {
  id: string;
  user_id: string;
  article_id?: string | null;
  variable_id?: string | null;
  name: string;
  quantity?: number | null;
  amount: number;
  sale_price?: number | null;
  type: 'sale' | 'expense';
  created_at: string;
  updated_at: string;
  article?: {
    id: string;
    name: string;
    sale_price: string;
    // ... autres champs
  } | null;
  variation?: {
    id: string;
    name: string;
    // ... autres champs
  } | null;
}
```

**À faire** :
1. Dans `TransactionsList`, créer un `useEffect` qui appelle `GET /api/transactions`
2. Mapper les transactions pour l'affichage :
   - Si `type === 'sale'` :
     - Texte : `transaction.name` (déjà formaté par le backend)
     - Montant : `transaction.amount` (ou `transaction.quantity * transaction.sale_price`)
     - Badge : "Vente" (vert)
   - Si `type === 'expense'` :
     - Texte : `transaction.name`
     - Montant : `transaction.amount`
     - Badge : "Dépense" (rouge)
3. Formater la date avec dayjs : `dayjs(transaction.created_at).format("DD MMM YYYY")`
4. Afficher un skeleton loader pendant le chargement initial

**Exemple de code** :
```typescript
const [transactions, setTransactions] = useState<Transaction[]>([]);
const [loading, setLoading] = useState(true);

useEffect(() => {
  fetchTransactions();
}, []);

const fetchTransactions = async () => {
  try {
    const response = await api.get('/api/transactions');
    setTransactions(response.data.data || []);
  } catch (error) {
    toast.error('Erreur lors du chargement des transactions');
  } finally {
    setLoading(false);
  }
};
```

## ✏️ 3. MODIFIER UNE TRANSACTION (PUT /api/transactions/{id})

**Endpoint** : `PUT http://localhost:8000/api/transactions/{id}`

**Body pour type="sale"** :
```typescript
{
  name: string;      // Ex: "Vente de 3 Ordinateur Portable Dell"
  sale_price: number; // Ex: 899.99 (le montant sera recalculé automatiquement : quantity * sale_price)
}
```

**Body pour type="expense"** :
```typescript
{
  name: string;   // Ex: "Loyer du local commercial"
  amount: number; // Ex: 1200.00
}
```

**Réponse succès (200)** :
```typescript
{
  success: true;
  message: "Transaction modifiée avec succès";
  data: Transaction;
}
```

**Réponse erreur (422)** :
```typescript
{
  success: false;
  message: "Erreur de validation";
  errors: {
    quantity?: string[];
    amount?: string[];
    name?: string[];
  };
}
```

**Réponse erreur (422)** :
```typescript
{
  success: false;
  message: "Erreur de validation";
  errors: {
    sale_price?: string[];
    name?: string[];
  };
}
```

**À faire** :
1. Dans `EditTransactionDialog`, créer un formulaire avec react-hook-form + Zod
2. Validation Zod :
   ```typescript
   const saleSchema = z.object({
     name: z.string().min(1, "Nom requis"),
     sale_price: z.number().min(0, "Prix de vente minimal 0"),
   });

   const expenseSchema = z.object({
     name: z.string().min(1, "Nom requis"),
     amount: z.number().min(0, "Montant minimal 0"),
   });
   ```
3. Si `transaction.type === 'sale'` : afficher champs `name` et `sale_price`
   - Le `amount` sera recalculé automatiquement côté backend : `quantity * sale_price`
   - Afficher la quantité actuelle en lecture seule (pour information)
4. Si `transaction.type === 'expense'` : afficher champs `name` et `amount`
5. Gérer les erreurs de validation (422) : afficher les messages sous les inputs
6. Si succès :
   - Toast "Transaction modifiée avec succès"
   - Mettre à jour la transaction dans le state local (inclure le nouveau `amount` calculé)
   - Recharger les statistiques utilisateur
   - Fermer la modale

**Exemple de code** :
```typescript
const onSubmit = async (data: FormData) => {
  try {
    const response = await api.put(`/api/transactions/${transaction.id}`, data);
    toast.success('Transaction modifiée avec succès');
    
    // Mettre à jour dans le state local
    // Le backend retourne la transaction avec le nouveau amount calculé
    setTransactions(prev => prev.map(t => 
      t.id === transaction.id ? response.data.data : t
    ));
    
    // Recharger les statistiques
    fetchUserStats();
    
    onClose();
  } catch (error: any) {
    if (error.response?.status === 422) {
      // Erreurs de validation
      const errors = error.response.data.errors;
      Object.keys(errors).forEach(key => {
        form.setError(key as any, { message: errors[key][0] });
      });
    } else {
      toast.error('Erreur lors de la modification');
    }
  }
};
```

## 🗑️ 4. SUPPRIMER UNE TRANSACTION (DELETE /api/transactions/{id})

**Endpoint** : `DELETE http://localhost:8000/api/transactions/{id}`

**Réponse succès (200)** :
```typescript
{
  success: true;
  message: "Transaction supprimée avec succès";
}
```

**Réponse erreur (404)** :
```typescript
{
  success: false;
  message: "Transaction non trouvée";
}
```

**À faire** :
1. Dans `DeleteTransactionDialog`, créer un AlertDialog de confirmation
2. Au clic sur "Supprimer", appeler `DELETE /api/transactions/{id}`
3. Si succès :
   - Toast "Transaction supprimée avec succès"
   - Retirer la transaction du state local
   - Recharger les statistiques utilisateur
   - Fermer la modale
4. Si erreur : afficher un toast d'erreur

**Exemple de code** :
```typescript
const handleDelete = async () => {
  try {
    await api.delete(`/api/transactions/${transaction.id}`);
    toast.success('Transaction supprimée avec succès');
    
    // Retirer du state local
    setTransactions(prev => prev.filter(t => t.id !== transaction.id));
    
    // Recharger les statistiques
    fetchUserStats();
    
    onClose();
  } catch (error: any) {
    if (error.response?.status === 404) {
      toast.error('Transaction non trouvée');
    } else {
      toast.error('Erreur lors de la suppression');
    }
  }
};
```

## 🔄 5. GESTION DU RECHARGEMENT DES DONNÉES

**Stratégie** :
- Après modification/suppression : mettre à jour le state local (pas de refetch complet)
- Recharger uniquement les statistiques utilisateur (GET /api/user)
- Ne pas recharger toute la liste des transactions (optimisation)

**Exemple** :
```typescript
// Après modification
const handleUpdate = (updatedTransaction: Transaction) => {
  setTransactions(prev => prev.map(t => 
    t.id === updatedTransaction.id ? updatedTransaction : t
  ));
  fetchUserStats(); // Recharger seulement les stats
};

// Après suppression
const handleDelete = (transactionId: string) => {
  setTransactions(prev => prev.filter(t => t.id !== transactionId));
  fetchUserStats(); // Recharger seulement les stats
};
```

## ✅ CHECKLIST

- [ ] Les 4 cards affichent les bonnes données depuis GET /api/user
- [ ] La liste des transactions s'affiche depuis GET /api/transactions
- [ ] Le bouton Modifier ouvre une modale avec formulaire
- [ ] Le formulaire de modification fonctionne (PUT /api/transactions/{id})
- [ ] Les erreurs de validation s'affichent sous les inputs
- [ ] Le bouton Supprimer ouvre un AlertDialog de confirmation
- [ ] La suppression fonctionne (DELETE /api/transactions/{id})
- [ ] Les statistiques se rechargent après modification/suppression
- [ ] Les skeletons s'affichent uniquement au premier chargement
- [ ] Tous les appels API utilisent `withCredentials: true`
- [ ] Tous les feedbacks utilisent toast (succès/erreur)
- [ ] Le code est typé TypeScript sans erreurs

## 🎯 RÉSULTAT ATTENDU

- Les statistiques s'affichent correctement depuis /api/user
- La liste des transactions s'affiche depuis /api/transactions
- La modification fonctionne avec validation et gestion d'erreurs
- La suppression fonctionne avec confirmation
- L'interface est fluide, sans rafraîchissement manuel
- Tous les appels API fonctionnent sans erreur

Connecte tous les composants Wallet à l'API Laravel en suivant ces spécifications.
```

---

## 📝 NOTES IMPORTANTES

1. **Authentification** : Utilisez toujours `withCredentials: true` (déjà configuré dans `lib/api.ts`)
2. **Gestion d'erreurs** : Tous les appels API doivent avoir un try/catch avec toast
3. **Validation** : Utilisez Zod pour valider les formulaires
4. **Performance** : Mettez à jour le state local après modification/suppression
5. **Types** : Créez des interfaces TypeScript pour Transaction et les réponses API

