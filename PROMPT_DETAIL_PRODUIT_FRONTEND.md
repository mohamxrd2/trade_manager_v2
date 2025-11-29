# 📋 PROMPT DÉTAILLÉ - PAGE DE DÉTAIL DE PRODUIT

## 🚀 Copiez ce prompt dans Cursor :

```
Je dois améliorer la page de détail de produit dans mon frontend Next.js pour :
1. Afficher la liste des transactions de vente de l'article
2. Si l'article est de type "variable", afficher un bouton "Ajouter une variation" qui ouvre un popup
3. Afficher les variations existantes sous forme de cards avec statistiques (total vendu, total restant, barre de progression)

## 🔗 CONFIGURATION API

**Base URL** : `http://localhost:8000`

**Endpoints utilisés** :
- `GET /api/articles/{id}` : Récupérer les détails de l'article (avec variations)
- `GET /api/transactions` : Récupérer toutes les transactions (filtrer par article_id côté frontend)
- `POST /api/variations` : Créer une nouvelle variation
- `GET /api/variations` : Récupérer toutes les variations (ou utiliser celles de l'article)

**Authentification** : Cookies HTTP-only (withCredentials: true)
- Utiliser l'instance axios configurée dans `lib/api.ts`

## 📋 STRUCTURE DE LA PAGE

### 1. Section Transactions de Vente

**Affichage** :
- Liste des transactions de type "sale" pour cet article
- Filtrer les transactions où `transaction.article_id === article.id`
- Afficher pour chaque transaction :
  - Date de la transaction (formatée avec dayjs)
  - Quantité vendue
  - Prix de vente unitaire
  - Montant total
  - Nom de la variation (si transaction.variable_id existe)
- Trier par date décroissante (plus récentes en premier)

**Composant suggéré** : Table ou Card list avec shadcn/ui

### 2. Section Variations (uniquement si article.type === 'variable')

**Bouton "Ajouter une variation"** :
- Afficher uniquement si `article.type === 'variable'`
- Au clic, ouvrir une modale (Dialog de shadcn/ui)

**Modal d'ajout de variation** :
- Champs :
  - **Nom** (Input texte) : Nom de la variation (ex: "Taille S", "Couleur Rouge")
  - **Quantité** (Input nombre) : Quantité disponible pour cette variation
  - **Image** (Input texte optionnel) : URL de l'image
- Validation :
  - Nom : requis, max 255 caractères
  - Quantité : requis, entier, minimum 1
  - Vérifier que la somme des quantités des variations ne dépasse pas la quantité totale de l'article
- Soumission : `POST /api/variations` avec `article_id`, `name`, `quantity`, `image`

**Affichage des variations existantes** :
- Cards (Card de shadcn/ui) pour chaque variation
- Chaque card doit afficher :
  - Image de la variation (si disponible)
  - Nom de la variation
  - **Total vendu** : `variation.sold_quantity` (calculé depuis les transactions)
  - **Total restant** : `variation.remaining_quantity` (quantity - sold_quantity)
  - **Barre de progression** : Pourcentage vendu (sold_quantity / quantity * 100)
  - **Statut** : "En stock" si remaining_quantity > 0, "Rupture de stock" sinon
  - **Pourcentage de vente** : `variation.sales_percentage` (si disponible)

## 📤 ENDPOINTS ET RÉPONSES

### Récupérer les détails de l'article
**GET** `/api/articles/{id}`

**Réponse** :
```typescript
{
  success: boolean;
  message: string;
  data: {
    id: string;
    name: string;
    sale_price: number;
    quantity: number;
    type: 'simple' | 'variable';
    remaining_quantity: number;
    sold_quantity: number;
    sales_percentage: number;
    variations?: Variation[]; // Si type === 'variable'
  }
}
```

### Récupérer les transactions
**GET** `/api/transactions`

**Réponse** :
```typescript
{
  success: boolean;
  message: string;
  data: Transaction[];
}

interface Transaction {
  id: string;
  article_id: string;
  variable_id?: string | null;
  name: string;
  quantity: number;
  amount: number;
  sale_price?: number;
  type: 'sale' | 'expense';
  created_at: string;
  updated_at: string;
  article?: Article;
  variation?: Variation;
}
```

**Note** : Filtrer côté frontend : `transactions.filter(t => t.article_id === articleId && t.type === 'sale')`

### Créer une variation
**POST** `/api/variations`

**Body** :
```typescript
{
  article_id: string;
  name: string;
  quantity: number;
  image?: string | null;
}
```

**Réponse succès (201)** :
```typescript
{
  success: true;
  message: "Variation ajoutée avec succès";
  data: {
    id: string;
    article_id: string;
    name: string;
    quantity: number;
    image?: string | null;
    sold_quantity: number; // Attribut calculé
    remaining_quantity: number; // Attribut calculé
    sales_percentage: number; // Attribut calculé
    low_stock: boolean; // Attribut calculé
  }
}
```

**Réponses erreur** :
- **422** : Erreur de validation
- **400** : Article non variable, variation existe déjà, quantité dépasse le total
- **403** : Article non trouvé ou non autorisé

## 🎨 COMPOSANT ArticleDetailPage

**Structure du composant** :
```typescript
'use client';

import { useState, useEffect } from 'react';
import { useParams } from 'next/navigation';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { toast } from '@/hooks/use-toast';
import api from '@/lib/api';
import dayjs from 'dayjs';
import { formatCurrency } from '@/lib/utils/currency';

interface Article {
  id: string;
  name: string;
  sale_price: number;
  quantity: number;
  type: 'simple' | 'variable';
  remaining_quantity: number;
  sold_quantity: number;
  sales_percentage: number;
  variations?: Variation[];
}

interface Variation {
  id: string;
  article_id: string;
  name: string;
  quantity: number;
  image?: string | null;
  sold_quantity: number;
  remaining_quantity: number;
  sales_percentage: number;
  low_stock: boolean;
}

interface Transaction {
  id: string;
  article_id: string;
  variable_id?: string | null;
  name: string;
  quantity: number;
  amount: number;
  sale_price?: number;
  type: 'sale' | 'expense';
  created_at: string;
  variation?: Variation;
}

export default function ArticleDetailPage() {
  const params = useParams();
  const articleId = params.id as string;
  
  const [article, setArticle] = useState<Article | null>(null);
  const [transactions, setTransactions] = useState<Transaction[]>([]);
  const [variations, setVariations] = useState<Variation[]>([]);
  const [loading, setLoading] = useState(true);
  const [addVariationOpen, setAddVariationOpen] = useState(false);
  const [variationForm, setVariationForm] = useState({
    name: '',
    quantity: 1,
    image: '',
  });
  const [submitting, setSubmitting] = useState(false);

  // Charger les données de l'article
  useEffect(() => {
    if (articleId) {
      fetchArticle();
      fetchTransactions();
    }
  }, [articleId]);

  const fetchArticle = async () => {
    try {
      const response = await api.get(`/api/articles/${articleId}`);
      setArticle(response.data.data);
      
      // Si l'article a des variations, les stocker
      if (response.data.data.variations) {
        setVariations(response.data.data.variations);
      }
    } catch (error) {
      toast({
        title: "Erreur",
        description: "Erreur lors du chargement de l'article",
        variant: "destructive",
      });
    } finally {
      setLoading(false);
    }
  };

  const fetchTransactions = async () => {
    try {
      const response = await api.get('/api/transactions');
      // Filtrer les transactions de vente pour cet article
      const articleTransactions = response.data.data.filter(
        (t: Transaction) => t.article_id === articleId && t.type === 'sale'
      );
      setTransactions(articleTransactions);
    } catch (error) {
      toast({
        title: "Erreur",
        description: "Erreur lors du chargement des transactions",
        variant: "destructive",
      });
    }
  };

  const handleAddVariation = async () => {
    // Validation
    if (!variationForm.name.trim()) {
      toast({
        title: "Erreur",
        description: "Le nom de la variation est requis",
        variant: "destructive",
      });
      return;
    }

    if (variationForm.quantity <= 0) {
      toast({
        title: "Erreur",
        description: "La quantité doit être supérieure à 0",
        variant: "destructive",
      });
      return;
    }

    setSubmitting(true);
    try {
      const response = await api.post('/api/variations', {
        article_id: articleId,
        name: variationForm.name,
        quantity: variationForm.quantity,
        image: variationForm.image || null,
      });

      toast({
        title: "Succès",
        description: "Variation ajoutée avec succès",
      });

      // Réinitialiser le formulaire
      setVariationForm({ name: '', quantity: 1, image: '' });
      setAddVariationOpen(false);

      // Recharger l'article pour obtenir les nouvelles variations
      await fetchArticle();
    } catch (error: any) {
      if (error.response?.status === 422) {
        const errors = error.response.data.errors;
        const firstError = Object.values(errors)[0] as string[];
        toast({
          title: "Erreur de validation",
          description: firstError[0],
          variant: "destructive",
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
          description: "Erreur lors de l'ajout de la variation",
          variant: "destructive",
        });
      }
    } finally {
      setSubmitting(false);
    }
  };

  if (loading) {
    return <div>Chargement...</div>;
  }

  if (!article) {
    return <div>Article non trouvé</div>;
  }

  // Calculer la quantité disponible pour les nouvelles variations
  const totalVariationsQuantity = variations.reduce((sum, v) => sum + v.quantity, 0);
  const availableQuantityForVariations = article.quantity - totalVariationsQuantity;

  return (
    <div className="container mx-auto p-6 space-y-6">
      {/* En-tête de l'article */}
      <Card>
        <CardHeader>
          <CardTitle>{article.name}</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div>
              <p className="text-sm text-muted-foreground">Prix de vente</p>
              <p className="text-lg font-semibold">{formatCurrency(article.sale_price)}</p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground">Quantité totale</p>
              <p className="text-lg font-semibold">{article.quantity}</p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground">Vendu</p>
              <p className="text-lg font-semibold">{article.sold_quantity}</p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground">Restant</p>
              <p className="text-lg font-semibold">{article.remaining_quantity}</p>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* Section Variations (si article variable) */}
      {article.type === 'variable' && (
        <Card>
          <CardHeader className="flex flex-row items-center justify-between">
            <CardTitle>Variations</CardTitle>
            <Button onClick={() => setAddVariationOpen(true)}>
              Ajouter une variation
            </Button>
          </CardHeader>
          <CardContent>
            {variations.length === 0 ? (
              <p className="text-muted-foreground">Aucune variation pour cet article</p>
            ) : (
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                {variations.map((variation) => (
                  <Card key={variation.id}>
                    <CardContent className="p-4">
                      {variation.image && (
                        <img
                          src={variation.image}
                          alt={variation.name}
                          className="w-full h-32 object-cover rounded mb-4"
                        />
                      )}
                      <h3 className="font-semibold text-lg mb-2">{variation.name}</h3>
                      
                      <div className="space-y-2">
                        <div className="flex justify-between text-sm">
                          <span className="text-muted-foreground">Total vendu:</span>
                          <span className="font-medium">{variation.sold_quantity}</span>
                        </div>
                        <div className="flex justify-between text-sm">
                          <span className="text-muted-foreground">Total restant:</span>
                          <span className="font-medium">{variation.remaining_quantity}</span>
                        </div>
                        <div className="flex justify-between text-sm">
                          <span className="text-muted-foreground">Pourcentage vendu:</span>
                          <span className="font-medium">{variation.sales_percentage.toFixed(1)}%</span>
                        </div>
                        
                        {/* Barre de progression */}
                        <div className="space-y-1">
                          <Progress value={variation.sales_percentage} className="h-2" />
                          <p className="text-xs text-muted-foreground">
                            {variation.sold_quantity} / {variation.quantity} vendus
                          </p>
                        </div>
                        
                        {/* Statut */}
                        <div className="mt-2">
                          {variation.remaining_quantity > 0 ? (
                            <Badge variant="default">En stock</Badge>
                          ) : (
                            <Badge variant="destructive">Rupture de stock</Badge>
                          )}
                          {variation.low_stock && (
                            <Badge variant="outline" className="ml-2">Stock faible</Badge>
                          )}
                        </div>
                      </div>
                    </CardContent>
                  </Card>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      )}

      {/* Section Transactions de vente */}
      <Card>
        <CardHeader>
          <CardTitle>Transactions de vente</CardTitle>
        </CardHeader>
        <CardContent>
          {transactions.length === 0 ? (
            <p className="text-muted-foreground">Aucune transaction de vente pour cet article</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Date</TableHead>
                  <TableHead>Variation</TableHead>
                  <TableHead>Quantité</TableHead>
                  <TableHead>Prix unitaire</TableHead>
                  <TableHead>Montant total</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {transactions.map((transaction) => (
                  <TableRow key={transaction.id}>
                    <TableCell>
                      {dayjs(transaction.created_at).format('DD/MM/YYYY HH:mm')}
                    </TableCell>
                    <TableCell>
                      {transaction.variation ? (
                        <Badge variant="outline">{transaction.variation.name}</Badge>
                      ) : (
                        <span className="text-muted-foreground">-</span>
                      )}
                    </TableCell>
                    <TableCell>{transaction.quantity}</TableCell>
                    <TableCell>{formatCurrency(transaction.sale_price || 0)}</TableCell>
                    <TableCell className="font-semibold">
                      {formatCurrency(transaction.amount)}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      {/* Modal d'ajout de variation */}
      <Dialog open={addVariationOpen} onOpenChange={setAddVariationOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Ajouter une variation</DialogTitle>
          </DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="variation-name">Nom de la variation *</Label>
              <Input
                id="variation-name"
                placeholder="Ex: Taille S, Couleur Rouge"
                value={variationForm.name}
                onChange={(e) => setVariationForm({ ...variationForm, name: e.target.value })}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="variation-quantity">Quantité *</Label>
              <Input
                id="variation-quantity"
                type="number"
                min="1"
                max={availableQuantityForVariations}
                value={variationForm.quantity}
                onChange={(e) => setVariationForm({ ...variationForm, quantity: parseInt(e.target.value) || 1 })}
              />
              <p className="text-sm text-muted-foreground">
                Quantité disponible pour les variations : {availableQuantityForVariations}
              </p>
            </div>
            <div className="space-y-2">
              <Label htmlFor="variation-image">URL de l'image (optionnel)</Label>
              <Input
                id="variation-image"
                type="url"
                placeholder="https://example.com/image.jpg"
                value={variationForm.image}
                onChange={(e) => setVariationForm({ ...variationForm, image: e.target.value })}
              />
            </div>
            <div className="flex justify-end gap-2">
              <Button
                variant="outline"
                onClick={() => {
                  setAddVariationOpen(false);
                  setVariationForm({ name: '', quantity: 1, image: '' });
                }}
              >
                Annuler
              </Button>
              <Button onClick={handleAddVariation} disabled={submitting}>
                {submitting ? 'Ajout...' : 'Ajouter'}
              </Button>
            </div>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  );
}
```

## ✅ CHECKLIST

- [ ] Récupérer les détails de l'article via `GET /api/articles/{id}`
- [ ] Récupérer toutes les transactions et filtrer par `article_id` et `type === 'sale'`
- [ ] Afficher la liste des transactions de vente dans un tableau
- [ ] Afficher le bouton "Ajouter une variation" uniquement si `article.type === 'variable'`
- [ ] Créer la modal d'ajout de variation avec validation
- [ ] Afficher les variations existantes en cards avec :
  - [ ] Image (si disponible)
  - [ ] Nom
  - [ ] Total vendu
  - [ ] Total restant
  - [ ] Barre de progression (pourcentage vendu)
  - [ ] Statut (En stock / Rupture de stock)
  - [ ] Badge "Stock faible" si applicable
- [ ] Recharger les données après ajout d'une variation
- [ ] Gérer les erreurs (validation, quantité insuffisante, etc.)
- [ ] Toast de succès/erreur
- [ ] Formatage des dates avec dayjs
- [ ] Formatage des montants avec formatCurrency

## 🎯 RÉSULTAT ATTENDU

- La page affiche les détails de l'article avec ses statistiques
- Si l'article est variable, un bouton "Ajouter une variation" est visible
- Les variations existantes sont affichées en cards avec toutes les statistiques
- La liste des transactions de vente est affichée dans un tableau
- L'ajout d'une variation ouvre une modal, valide les données, et met à jour l'affichage
- Toutes les erreurs sont gérées proprement avec des toasts

Crée la page ArticleDetailPage complète avec toute cette logique.
```

---

## 📝 NOTES IMPORTANTES

1. **Filtrage des transactions** : L'API `/api/transactions` retourne toutes les transactions. Il faut filtrer côté frontend par `article_id` et `type === 'sale'`.

2. **Variations** : Les variations sont déjà chargées avec l'article via `GET /api/articles/{id}`. Après ajout, recharger l'article pour obtenir les nouvelles variations avec leurs statistiques calculées.

3. **Validation de quantité** : Vérifier que la somme des quantités des variations ne dépasse pas la quantité totale de l'article. Afficher la quantité disponible pour les nouvelles variations.

4. **Barre de progression** : Utiliser le composant `Progress` de shadcn/ui avec `value={variation.sales_percentage}`.

5. **Statut** : Utiliser des `Badge` de shadcn/ui pour afficher "En stock", "Rupture de stock", "Stock faible".

6. **Formatage** : Utiliser `dayjs` pour les dates et `formatCurrency` pour les montants.

