# 📋 PROMPT DÉTAILLÉ - MODIFICATION DE TRANSACTION

## 🚀 Copiez ce prompt dans Cursor :

```
Je dois implémenter la modification de transactions dans ma page Wallet Next.js.

## 🔗 CONFIGURATION API

**Endpoint** : `PUT http://localhost:8000/api/transactions/{id}`

**Authentification** : Cookies HTTP-only (withCredentials: true)
- Utiliser l'instance axios configurée dans `lib/api.ts`
- Le cookie CSRF est géré automatiquement par l'intercepteur

## 📋 RÈGLES DE MODIFICATION

### Pour une transaction de type "sale" (Vente) :
- On peut modifier : `name`, `sale_price` OU `quantity` (ou les deux)
- Le `amount` est recalculé automatiquement : `amount = quantity * sale_price`
- Si la `quantity` change, le backend vérifie la disponibilité du stock
- Si la `quantity` ne change pas, le stock de l'article n'est pas modifié

### Pour une transaction de type "expense" (Dépense) :
- On peut modifier : `name` OU `amount` (ou les deux)
- Pas de calcul automatique, les valeurs sont directement mises à jour

## 📝 STRUCTURE DU FORMULAIRE

### Transaction de Vente (type="sale")

**Champs à afficher** :
1. **Name** (requis) : Input texte
   - Valeur par défaut : `transaction.name`
   - Validation : minimum 1 caractère

2. **Quantity** (optionnel) : Input nombre
   - Valeur par défaut : `transaction.quantity`
   - Validation : nombre entier, minimum 1
   - Afficher la quantité actuelle en lecture seule (pour référence)
   - Si modifié, le backend vérifiera la disponibilité

3. **Sale Price** (optionnel) : Input nombre avec décimales
   - Valeur par défaut : `transaction.sale_price`
   - Validation : nombre décimal, minimum 0
   - Format : 2 décimales (ex: 899.99)

4. **Amount** (calculé, lecture seule) : Affichage
   - Calculé automatiquement : `quantity * sale_price`
   - Mise à jour en temps réel quand quantity ou sale_price change
   - Format : devise (ex: "1 799,98 €")

**Validation Zod** :
```typescript
const saleSchema = z.object({
  name: z.string().min(1, "Le nom est requis"),
  quantity: z.number().int().min(1, "La quantité doit être au moins 1").optional(),
  sale_price: z.number().min(0, "Le prix de vente doit être positif").optional(),
}).refine(
  (data) => data.quantity !== undefined || data.sale_price !== undefined,
  {
    message: "Vous devez modifier au moins la quantité ou le prix de vente",
    path: ["quantity"],
  }
);
```

### Transaction de Dépense (type="expense")

**Champs à afficher** :
1. **Name** (requis) : Input texte
   - Valeur par défaut : `transaction.name`
   - Validation : minimum 1 caractère

2. **Amount** (optionnel) : Input nombre avec décimales
   - Valeur par défaut : `transaction.amount`
   - Validation : nombre décimal, minimum 0
   - Format : 2 décimales (ex: 1200.00)

**Validation Zod** :
```typescript
const expenseSchema = z.object({
  name: z.string().min(1, "Le nom est requis"),
  amount: z.number().min(0, "Le montant doit être positif").optional(),
});
```

## 🎨 COMPOSANT EditTransactionDialog

**Structure du composant** :
```typescript
interface EditTransactionDialogProps {
  transaction: Transaction;
  open: boolean;
  onClose: () => void;
  onUpdate: (updatedTransaction: Transaction) => void;
}

const EditTransactionDialog: React.FC<EditTransactionDialogProps> = ({
  transaction,
  open,
  onClose,
  onUpdate,
}) => {
  // ... implémentation
};
```

**Logique pour transaction de vente** :
1. Initialiser le formulaire avec les valeurs actuelles
2. Calculer et afficher `amount` en temps réel : `quantity * sale_price`
3. Si l'utilisateur modifie `quantity` ou `sale_price`, recalculer `amount` immédiatement
4. Afficher un indicateur visuel si la quantité change (ex: "⚠️ Le stock sera ajusté")
5. À la soumission, envoyer seulement les champs modifiés (ou tous si nécessaire)

**Logique pour transaction de dépense** :
1. Initialiser le formulaire avec les valeurs actuelles
2. Les champs `name` et `amount` sont indépendants
3. À la soumission, envoyer seulement les champs modifiés

## 🔄 GESTION DU STATE ET CALCULS

**Pour les ventes** :
```typescript
const [formData, setFormData] = useState({
  name: transaction.name,
  quantity: transaction.quantity,
  sale_price: transaction.sale_price,
});

// Calculer amount en temps réel
const calculatedAmount = useMemo(() => {
  return (formData.quantity || 0) * (formData.sale_price || 0);
}, [formData.quantity, formData.sale_price]);

// Détecter si la quantité a changé
const quantityChanged = formData.quantity !== transaction.quantity;
```

**Affichage du montant calculé** :
```typescript
<div className="space-y-2">
  <Label>Montant calculé</Label>
  <div className="text-lg font-semibold">
    {formatCurrency(calculatedAmount)}
  </div>
  {quantityChanged && (
    <p className="text-sm text-amber-600">
      ⚠️ La quantité a changé, le stock sera ajusté
    </p>
  )}
</div>
```

## 📤 ENVOI DE LA REQUÊTE

**Pour une vente** :
```typescript
const onSubmit = async (data: FormData) => {
  try {
    // Préparer les données à envoyer
    const payload: {
      name: string;
      quantity?: number;
      sale_price?: number;
    } = {
      name: data.name,
    };

    // Ajouter seulement les champs modifiés
    if (data.quantity !== transaction.quantity) {
      payload.quantity = data.quantity;
    }
    if (data.sale_price !== transaction.sale_price) {
      payload.sale_price = data.sale_price;
    }

    const response = await api.put(`/api/transactions/${transaction.id}`, payload);
    
    toast.success('Transaction modifiée avec succès');
    onUpdate(response.data.data);
    onClose();
  } catch (error: any) {
    // Gestion des erreurs
  }
};
```

**Pour une dépense** :
```typescript
const onSubmit = async (data: FormData) => {
  try {
    const payload: {
      name: string;
      amount?: number;
    } = {
      name: data.name,
    };

    // Ajouter seulement si modifié
    if (data.amount !== transaction.amount) {
      payload.amount = data.amount;
    }

    const response = await api.put(`/api/transactions/${transaction.id}`, payload);
    
    toast.success('Transaction modifiée avec succès');
    onUpdate(response.data.data);
    onClose();
  } catch (error: any) {
    // Gestion des erreurs
  }
};
```

## ⚠️ GESTION DES ERREURS

**Erreur 422 (Validation)** :
```typescript
if (error.response?.status === 422) {
  const errors = error.response.data.errors;
  Object.keys(errors).forEach(key => {
    form.setError(key as any, { 
      message: errors[key][0] 
    });
  });
}
```

**Erreur 400 (Quantité insuffisante)** :
```typescript
if (error.response?.status === 400) {
  toast.error(error.response.data.message);
  // Exemple : "Quantité insuffisante. Quantité disponible: 5"
}
```

**Erreur 404 (Transaction non trouvée)** :
```typescript
if (error.response?.status === 404) {
  toast.error('Transaction non trouvée');
  onClose();
}
```

**Erreur générique** :
```typescript
else {
  toast.error('Erreur lors de la modification de la transaction');
}
```

## 🎯 EXEMPLE COMPLET POUR TRANSACTION DE VENTE

```typescript
'use client';

import { useState, useMemo } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { toast } from '@/hooks/use-toast';
import api from '@/lib/api';
import { formatCurrency } from '@/lib/utils/currency';
import { Transaction } from '@/lib/types/transaction';

const saleSchema = z.object({
  name: z.string().min(1, "Le nom est requis"),
  quantity: z.number().int().min(1, "La quantité doit être au moins 1").optional(),
  sale_price: z.number().min(0, "Le prix de vente doit être positif").optional(),
}).refine(
  (data) => data.quantity !== undefined || data.sale_price !== undefined,
  {
    message: "Vous devez modifier au moins la quantité ou le prix de vente",
    path: ["quantity"],
  }
);

interface EditTransactionDialogProps {
  transaction: Transaction;
  open: boolean;
  onClose: () => void;
  onUpdate: (updatedTransaction: Transaction) => void;
}

const expenseSchema = z.object({
  name: z.string().min(1, "Le nom est requis"),
  amount: z.number().min(0, "Le montant doit être positif").optional(),
});

export const EditTransactionDialog: React.FC<EditTransactionDialogProps> = ({
  transaction,
  open,
  onClose,
  onUpdate,
}) => {
  const isSale = transaction.type === 'sale';
  const schema = isSale ? saleSchema : expenseSchema;

  const form = useForm({
    resolver: zodResolver(schema),
    defaultValues: {
      name: transaction.name,
      quantity: transaction.quantity,
      sale_price: transaction.sale_price,
      amount: transaction.amount,
    },
  });

  const watchedQuantity = form.watch('quantity');
  const watchedSalePrice = form.watch('sale_price');

  // Calculer amount en temps réel pour les ventes
  const calculatedAmount = useMemo(() => {
    if (!isSale) return transaction.amount;
    return (watchedQuantity || 0) * (watchedSalePrice || 0);
  }, [isSale, watchedQuantity, watchedSalePrice, transaction.amount]);

  const quantityChanged = isSale && watchedQuantity !== transaction.quantity;
  const salePriceChanged = isSale && watchedSalePrice !== transaction.sale_price;

  const onSubmit = async (data: any) => {
    try {
      const payload: any = { name: data.name };

      if (isSale) {
        if (quantityChanged) payload.quantity = data.quantity;
        if (salePriceChanged) payload.sale_price = data.sale_price;
      } else {
        if (data.amount !== transaction.amount) {
          payload.amount = data.amount;
        }
      }

      const response = await api.put(`/api/transactions/${transaction.id}`, payload);
      
      toast({
        title: "Succès",
        description: "Transaction modifiée avec succès",
      });
      
      onUpdate(response.data.data);
      onClose();
    } catch (error: any) {
      if (error.response?.status === 422) {
        const errors = error.response.data.errors;
        Object.keys(errors).forEach(key => {
          form.setError(key as any, { message: errors[key][0] });
        });
      } else if (error.response?.status === 400) {
        toast({
          title: "Erreur",
          description: error.response.data.message,
          variant: "destructive",
        });
      } else {
        toast({
          title: "Erreur",
          description: "Erreur lors de la modification",
          variant: "destructive",
        });
      }
    }
  };

  return (
    <Dialog open={open} onOpenChange={onClose}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            Modifier {isSale ? 'une vente' : 'une dépense'}
          </DialogTitle>
        </DialogHeader>
        
        <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
          {/* Champ Name */}
          <div className="space-y-2">
            <Label htmlFor="name">Nom</Label>
            <Input
              id="name"
              {...form.register('name')}
            />
            {form.formState.errors.name && (
              <p className="text-sm text-red-500">
                {form.formState.errors.name.message}
              </p>
            )}
          </div>

          {isSale ? (
            <>
              {/* Champ Quantity */}
              <div className="space-y-2">
                <Label htmlFor="quantity">Quantité</Label>
                <Input
                  id="quantity"
                  type="number"
                  min="1"
                  {...form.register('quantity', { valueAsNumber: true })}
                />
                <p className="text-sm text-muted-foreground">
                  Quantité actuelle : {transaction.quantity}
                </p>
                {form.formState.errors.quantity && (
                  <p className="text-sm text-red-500">
                    {form.formState.errors.quantity.message}
                  </p>
                )}
              </div>

              {/* Champ Sale Price */}
              <div className="space-y-2">
                <Label htmlFor="sale_price">Prix de vente</Label>
                <Input
                  id="sale_price"
                  type="number"
                  step="0.01"
                  min="0"
                  {...form.register('sale_price', { valueAsNumber: true })}
                />
                {form.formState.errors.sale_price && (
                  <p className="text-sm text-red-500">
                    {form.formState.errors.sale_price.message}
                  </p>
                )}
              </div>

              {/* Montant calculé */}
              <div className="space-y-2">
                <Label>Montant calculé</Label>
                <div className="text-lg font-semibold">
                  {formatCurrency(calculatedAmount)}
                </div>
                {quantityChanged && (
                  <p className="text-sm text-amber-600">
                    ⚠️ La quantité a changé, le stock sera ajusté
                  </p>
                )}
              </div>
            </>
          ) : (
            /* Champ Amount pour dépense */
            <div className="space-y-2">
              <Label htmlFor="amount">Montant</Label>
              <Input
                id="amount"
                type="number"
                step="0.01"
                min="0"
                {...form.register('amount', { valueAsNumber: true })}
              />
              {form.formState.errors.amount && (
                <p className="text-sm text-red-500">
                  {form.formState.errors.amount.message}
                </p>
              )}
            </div>
          )}

          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={onClose}>
              Annuler
            </Button>
            <Button type="submit">
              Enregistrer
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  );
};
```

## ✅ CHECKLIST

- [ ] Formulaire avec react-hook-form + Zod
- [ ] Pour les ventes : champs `name`, `quantity`, `sale_price`
- [ ] Pour les dépenses : champs `name`, `amount`
- [ ] Calcul automatique de `amount` pour les ventes (en temps réel)
- [ ] Indicateur visuel si la quantité change
- [ ] Validation avec messages d'erreur sous les inputs
- [ ] Gestion des erreurs 422, 400, 404
- [ ] Toast de succès/erreur
- [ ] Mise à jour du state local après modification
- [ ] Rechargement des statistiques utilisateur
- [ ] Code typé TypeScript sans erreurs

## 🎯 RÉSULTAT ATTENDU

- Le formulaire s'adapte selon le type de transaction
- Pour les ventes : on peut modifier `quantity` OU `sale_price` (ou les deux)
- Pour les dépenses : on peut modifier `name` OU `amount` (ou les deux)
- Le `amount` est calculé automatiquement pour les ventes
- Les erreurs sont gérées proprement avec des messages clairs
- L'interface est fluide et intuitive

Crée le composant EditTransactionDialog complet avec toute cette logique.
```

---

## 📝 NOTES IMPORTANTES

1. **Pour les ventes** : Le backend recalcule automatiquement `amount = quantity * sale_price`
2. **Pour les dépenses** : Les champs sont indépendants, pas de calcul automatique
3. **Validation** : Au moins un champ doit être modifié (quantity OU sale_price pour les ventes)
4. **Stock** : Si la quantité change, le backend gère automatiquement le stock
5. **UX** : Afficher un indicateur si la quantité change (le stock sera ajusté)

