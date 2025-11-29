# 📋 PROMPT DÉTAILLÉ - MODIFICATION D'ARTICLE

## 🚀 Copiez ce prompt dans Cursor :

```
Je dois créer un composant de modale pour modifier un article dans ma page de liste des produits Next.js.

## 🔗 CONFIGURATION API

**Endpoint** : `PUT http://localhost:8000/api/articles/{id}`

**Authentification** : Cookies HTTP-only (withCredentials: true)
- Utiliser l'instance axios configurée dans `lib/api.ts`
- Le cookie CSRF est géré automatiquement par l'intercepteur

## 📋 STRUCTURE DE LA MODALE

### 1. Bouton "Modifier"

**Emplacement** : Dans la liste des articles ou la page de détail

**Composant** : Utiliser un `Button` de shadcn/ui avec variant "outline" ou "ghost"

**Icône** : Utiliser `Pencil` ou `Edit` de lucide-react

**Action** : Au clic, ouvrir la modale avec les données de l'article pré-remplies

### 2. Modale de modification

**Composant** : Utiliser `Dialog` de shadcn/ui

**Champs modifiables** :
1. **Nom** (Input texte) : 
   - Validation : requis, max 255 caractères, unique par utilisateur
   - Valeur initiale : `article.name`

2. **Prix unitaire** (Input nombre) :
   - Validation : requis, nombre décimal, minimum 0
   - Format : 2 décimales
   - Valeur initiale : `article.sale_price`
   - Placeholder : "0.00"

3. **Quantité** (Input nombre) :
   - Validation : requis, nombre entier, minimum 0
   - Valeur initiale : `article.quantity`
   - Placeholder : "0"

4. **Image** (Input texte optionnel) :
   - Validation : string, max 255 caractères
   - Valeur initiale : `article.image || ''`
   - Placeholder : "https://example.com/image.jpg"

**Champs en lecture seule** (affichage informatif) :
- **Type** : Afficher le type de l'article (simple/variable) en lecture seule
- **Statistiques** : Optionnel, afficher sold_quantity, remaining_quantity, sales_percentage

### 3. Section Variations (uniquement si article.type === 'variable')

**Affichage conditionnel** : Cette section s'affiche UNIQUEMENT si l'article est de type "variable"

**Fonctionnalités** :
1. **Liste des variations** :
   - Afficher toutes les variations de l'article sous forme de cards ou liste
   - Pour chaque variation, afficher :
     - Image (si disponible)
     - Nom de la variation
     - Quantité disponible
     - Statistiques (sold_quantity, remaining_quantity, sales_percentage)

2. **Modifier une variation** :
   - Bouton "Modifier" sur chaque variation
   - Ouvrir une sous-modale ou un formulaire inline pour modifier :
     - Nom de la variation
     - Quantité
     - Image (optionnel)
   - Validation : nom unique par article, quantité positive, somme des quantités ≤ quantité totale de l'article

3. **Supprimer une variation** :
   - Bouton "Supprimer" sur chaque variation
   - Dialogue de confirmation (AlertDialog)
   - Après confirmation, supprimer la variation via l'API

4. **Ajouter une variation** (optionnel) :
   - Bouton "Ajouter une variation"
   - Ouvrir une modale pour créer une nouvelle variation

## 📤 ENDPOINT ET RÉPONSES

### Modifier un article
**PUT** `/api/articles/{id}`

**Body** :
```typescript
{
  name: string;
  sale_price: number;
  quantity: number;
  image?: string | null;
}
```

**Réponse succès (200)** :
```typescript
{
  success: true;
  message: "Article modifié avec succès";
  data: {
    id: string;
    name: string;
    sale_price: number;
    quantity: number;
    type: 'simple' | 'variable';
    image?: string | null;
    sold_quantity: number;
    remaining_quantity: number;
    sales_percentage: number;
    low_stock: boolean;
    stock_value: number;
  }
}
```

**Réponses erreur** :
- **404** : Article non trouvé
- **403** : Article non autorisé (n'appartient pas à l'utilisateur)
- **422** : Erreur de validation (nom déjà utilisé, valeurs invalides, etc.)
- **500** : Erreur serveur

### Modifier une variation
**PUT** `/api/variations/{id}`

**Body** :
```typescript
{
  name: string;
  quantity: number;
  image?: string | null;
}
```

**Réponse succès (200)** :
```typescript
{
  success: true;
  message: "Variation modifiée avec succès";
  data: {
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
}
```

**Réponses erreur** :
- **404** : Variation non trouvée
- **403** : Article non autorisé
- **422** : Erreur de validation
- **400** : Nom déjà utilisé, quantité dépasse le total de l'article

### Supprimer une variation
**DELETE** `/api/variations/{id}`

**Réponse succès (200)** :
```typescript
{
  success: true;
  message: "Variation supprimée avec succès";
}
```

**Réponses erreur** :
- **404** : Variation non trouvée
- **403** : Article non autorisé
- **500** : Erreur serveur

## 🎨 COMPOSANT EditArticleDialog

**Structure du composant** :
```typescript
'use client';

import { useState, useEffect } from 'react';
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

interface Article {
  id: string;
  name: string;
  sale_price: number;
  quantity: number;
  type: 'simple' | 'variable';
  image?: string | null;
  sold_quantity?: number;
  remaining_quantity?: number;
  sales_percentage?: number;
  low_stock?: boolean;
  stock_value?: number;
  variations?: Variation[];
}

interface Variation {
  id: string;
  article_id: string;
  name: string;
  quantity: number;
  image?: string | null;
  sold_quantity?: number;
  remaining_quantity?: number;
  sales_percentage?: number;
  low_stock?: boolean;
}

const articleSchema = z.object({
  name: z.string().min(1, "Le nom est requis").max(255, "Le nom ne peut pas dépasser 255 caractères"),
  sale_price: z.number().min(0, "Le prix doit être supérieur ou égal à 0"),
  quantity: z.number().int().min(0, "La quantité doit être supérieure ou égale à 0"),
  image: z.string().max(255, "L'URL de l'image ne peut pas dépasser 255 caractères").optional().nullable(),
});

interface EditArticleDialogProps {
  article: Article | null;
  open: boolean;
  onClose: () => void;
  onSuccess: (updatedArticle: Article) => void;
}

export const EditArticleDialog: React.FC<EditArticleDialogProps> = ({
  article,
  open,
  onClose,
  onSuccess,
}) => {
  const [loading, setLoading] = useState(false);
  const [variations, setVariations] = useState<Variation[]>([]);
  const [editingVariation, setEditingVariation] = useState<Variation | null>(null);
  const [editVariationDialogOpen, setEditVariationDialogOpen] = useState(false);
  const [deleteVariationDialogOpen, setDeleteVariationDialogOpen] = useState(false);
  const [variationToDelete, setVariationToDelete] = useState<Variation | null>(null);

  const form = useForm({
    resolver: zodResolver(articleSchema),
    defaultValues: {
      name: '',
      sale_price: 0,
      quantity: 0,
      image: '',
    },
  });

  // Réinitialiser le formulaire quand l'article change ou la modale s'ouvre
  useEffect(() => {
    if (article && open) {
      form.reset({
        name: article.name,
        sale_price: article.sale_price,
        quantity: article.quantity,
        image: article.image || '',
      });
      
      // Charger les variations si l'article est variable
      if (article.type === 'variable' && article.variations) {
        setVariations(article.variations);
      } else {
        setVariations([]);
      }
    }
  }, [article, open, form]);

  const onSubmit = async (data: z.infer<typeof articleSchema>) => {
    if (!article) return;

    setLoading(true);
    try {
      const payload: any = {
        name: data.name,
        sale_price: data.sale_price,
        quantity: data.quantity,
      };

      // Ajouter l'image seulement si elle est fournie
      if (data.image && data.image.trim() !== '') {
        payload.image = data.image;
      } else {
        payload.image = null;
      }

      const response = await api.put(`/api/articles/${article.id}`, payload);

      toast({
        title: "Succès",
        description: "Article modifié avec succès",
      });

      // Recharger les variations si l'article est variable
      if (response.data.data.type === 'variable' && response.data.data.variations) {
        setVariations(response.data.data.variations);
      }

      // Mettre à jour l'article dans le parent
      onSuccess(response.data.data);
      
      // Ne pas fermer la modale automatiquement pour permettre de continuer à modifier les variations
      // L'utilisateur peut fermer manuellement avec le bouton "Fermer"
    } catch (error: any) {
      if (error.response?.status === 422) {
        const errors = error.response.data.errors;
        Object.keys(errors).forEach(key => {
          form.setError(key as any, { message: errors[key][0] });
        });
        toast({
          title: "Erreur de validation",
          description: "Veuillez corriger les erreurs dans le formulaire",
          variant: "destructive",
        });
      } else if (error.response?.status === 404) {
        toast({
          title: "Erreur",
          description: "Article non trouvé",
          variant: "destructive",
        });
      } else if (error.response?.status === 403) {
        toast({
          title: "Erreur",
          description: "Vous n'êtes pas autorisé à modifier cet article",
          variant: "destructive",
        });
      } else {
        toast({
          title: "Erreur",
          description: error.response?.data?.message || "Erreur lors de la modification de l'article",
          variant: "destructive",
        });
      }
    } finally {
      setLoading(false);
    }
  };

  if (!article) return null;

  return (
    <Dialog open={open} onOpenChange={onClose}>
      <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Modifier l'article</DialogTitle>
        </DialogHeader>

        <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
          {/* Nom */}
          <div className="space-y-2">
            <Label htmlFor="name">Nom de l'article *</Label>
            <Input
              id="name"
              placeholder="Ex: Ordinateur Portable Dell"
              {...form.register('name')}
            />
            {form.formState.errors.name && (
              <p className="text-sm text-red-500">
                {form.formState.errors.name.message}
              </p>
            )}
          </div>

          {/* Prix unitaire */}
          <div className="space-y-2">
            <Label htmlFor="sale_price">Prix unitaire *</Label>
            <Input
              id="sale_price"
              type="number"
              step="0.01"
              min="0"
              placeholder="0.00"
              {...form.register('sale_price', { valueAsNumber: true })}
            />
            <p className="text-sm text-muted-foreground">
              Prix actuel : {formatCurrency(article.sale_price)}
            </p>
            {form.formState.errors.sale_price && (
              <p className="text-sm text-red-500">
                {form.formState.errors.sale_price.message}
              </p>
            )}
          </div>

          {/* Quantité */}
          <div className="space-y-2">
            <Label htmlFor="quantity">Quantité *</Label>
            <Input
              id="quantity"
              type="number"
              min="0"
              placeholder="0"
              {...form.register('quantity', { valueAsNumber: true })}
            />
            <p className="text-sm text-muted-foreground">
              Quantité actuelle : {article.quantity}
              {article.sold_quantity !== undefined && (
                <span className="ml-2">
                  (Vendu: {article.sold_quantity}, Restant: {article.remaining_quantity})
                </span>
              )}
            </p>
            {form.formState.errors.quantity && (
              <p className="text-sm text-red-500">
                {form.formState.errors.quantity.message}
              </p>
            )}
          </div>

          {/* Type (lecture seule) */}
          <div className="space-y-2">
            <Label>Type d'article</Label>
            <Input
              value={article.type === 'simple' ? 'Simple' : 'Variable'}
              disabled
              className="bg-muted"
            />
            <p className="text-sm text-muted-foreground">
              Le type d'article ne peut pas être modifié
            </p>
          </div>

          {/* Image */}
          <div className="space-y-2">
            <Label htmlFor="image">URL de l'image (optionnel)</Label>
            <Input
              id="image"
              type="url"
              placeholder="https://example.com/image.jpg"
              {...form.register('image')}
            />
            {form.formState.errors.image && (
              <p className="text-sm text-red-500">
                {form.formState.errors.image.message}
              </p>
            )}
            {article.image && (
              <div className="mt-2">
                <img
                  src={article.image}
                  alt={article.name}
                  className="w-32 h-32 object-cover rounded border"
                  onError={(e) => {
                    (e.target as HTMLImageElement).style.display = 'none';
                  }}
                />
              </div>
            )}
          </div>

          {/* Statistiques (optionnel, en lecture seule) */}
          {article.sales_percentage !== undefined && (
            <div className="space-y-2 p-4 bg-muted rounded-lg">
              <Label>Statistiques</Label>
              <div className="grid grid-cols-2 gap-4 text-sm">
                <div>
                  <span className="text-muted-foreground">Pourcentage vendu:</span>
                  <span className="ml-2 font-medium">{article.sales_percentage.toFixed(1)}%</span>
                </div>
                <div>
                  <span className="text-muted-foreground">Valeur du stock:</span>
                  <span className="ml-2 font-medium">
                    {article.stock_value !== undefined ? formatCurrency(article.stock_value) : '-'}
                  </span>
                </div>
                {article.low_stock && (
                  <div className="col-span-2">
                    <span className="text-orange-600 font-medium">⚠️ Stock faible</span>
                  </div>
                )}
              </div>
            </div>
          )}

          {/* Section Variations (si article variable) */}
          {article.type === 'variable' && (
            <div className="space-y-4 border-t pt-4">
              <div className="flex items-center justify-between">
                <Label className="text-lg font-semibold">Variations</Label>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={() => {
                    // Optionnel : ouvrir une modale pour ajouter une variation
                    // Vous pouvez utiliser le prompt PROMPT_DETAIL_PRODUIT_FRONTEND.md pour l'ajout
                  }}
                >
                  Ajouter une variation
                </Button>
              </div>

              {variations.length === 0 ? (
                <p className="text-sm text-muted-foreground">Aucune variation pour cet article</p>
              ) : (
                <div className="space-y-3">
                  {variations.map((variation) => (
                    <div
                      key={variation.id}
                      className="p-4 border rounded-lg flex items-start justify-between gap-4"
                    >
                      <div className="flex-1">
                        <div className="flex items-center gap-3">
                          {variation.image && (
                            <img
                              src={variation.image}
                              alt={variation.name}
                              className="w-16 h-16 object-cover rounded border"
                              onError={(e) => {
                                (e.target as HTMLImageElement).style.display = 'none';
                              }}
                            />
                          )}
                          <div>
                            <h4 className="font-semibold">{variation.name}</h4>
                            <div className="text-sm text-muted-foreground space-y-1">
                              <p>Quantité: {variation.quantity}</p>
                              {variation.sold_quantity !== undefined && (
                                <p>
                                  Vendu: {variation.sold_quantity} | Restant: {variation.remaining_quantity}
                                </p>
                              )}
                              {variation.sales_percentage !== undefined && (
                                <p>Pourcentage vendu: {variation.sales_percentage.toFixed(1)}%</p>
                              )}
                            </div>
                          </div>
                        </div>
                      </div>
                      <div className="flex gap-2">
                        <Button
                          type="button"
                          variant="outline"
                          size="sm"
                          onClick={() => {
                            setEditingVariation(variation);
                            setEditVariationDialogOpen(true);
                          }}
                        >
                          Modifier
                        </Button>
                        <Button
                          type="button"
                          variant="destructive"
                          size="sm"
                          onClick={() => {
                            setVariationToDelete(variation);
                            setDeleteVariationDialogOpen(true);
                          }}
                        >
                          Supprimer
                        </Button>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}

          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={onClose} disabled={loading}>
              Fermer
            </Button>
            <Button type="submit" disabled={loading}>
              {loading ? 'Modification...' : 'Modifier l\'article'}
            </Button>
          </div>
        </form>

        {/* Modale de modification de variation */}
        {editingVariation && (
          <EditVariationDialog
            variation={editingVariation}
            article={article}
            open={editVariationDialogOpen}
            onClose={() => {
              setEditVariationDialogOpen(false);
              setEditingVariation(null);
            }}
            onSuccess={(updatedVariation) => {
              setVariations(variations.map(v => v.id === updatedVariation.id ? updatedVariation : v));
              setEditVariationDialogOpen(false);
              setEditingVariation(null);
            }}
          />
        )}

        {/* Dialogue de confirmation de suppression de variation */}
        <AlertDialog open={deleteVariationDialogOpen} onOpenChange={setDeleteVariationDialogOpen}>
          <AlertDialogContent>
            <AlertDialogHeader>
              <AlertDialogTitle>Supprimer la variation ?</AlertDialogTitle>
              <AlertDialogDescription>
                Cette action est irréversible. La variation "{variationToDelete?.name}" sera définitivement supprimée.
              </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
              <AlertDialogCancel>Annuler</AlertDialogCancel>
              <AlertDialogAction
                onClick={async () => {
                  if (!variationToDelete) return;
                  
                  try {
                    await api.delete(`/api/variations/${variationToDelete.id}`);
                    toast({
                      title: "Succès",
                      description: "Variation supprimée avec succès",
                    });
                    setVariations(variations.filter(v => v.id !== variationToDelete.id));
                    setDeleteVariationDialogOpen(false);
                    setVariationToDelete(null);
                  } catch (error: any) {
                    toast({
                      title: "Erreur",
                      description: error.response?.data?.message || "Erreur lors de la suppression",
                      variant: "destructive",
                    });
                  }
                }}
                className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              >
                Supprimer
              </AlertDialogAction>
            </AlertDialogFooter>
          </AlertDialogContent>
        </AlertDialog>
      </DialogContent>
    </Dialog>
  );
};
```

## 🔧 INTÉGRATION DANS LA LISTE DES ARTICLES

**Exemple d'utilisation dans un composant de liste** :
```typescript
'use client';

import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { EditArticleDialog } from '@/components/articles/EditArticleDialog';
import { Pencil } from 'lucide-react';

export function ArticlesList() {
  const [editingArticle, setEditingArticle] = useState<Article | null>(null);
  const [editDialogOpen, setEditDialogOpen] = useState(false);

  const handleEdit = (article: Article) => {
    setEditingArticle(article);
    setEditDialogOpen(true);
  };

  const handleEditSuccess = (updatedArticle: Article) => {
    // Mettre à jour l'article dans la liste locale
    // ou recharger la liste depuis l'API
    // Exemple : setArticles(articles.map(a => a.id === updatedArticle.id ? updatedArticle : a));
  };

  return (
    <div>
      {/* Liste des articles */}
      {articles.map((article) => (
        <div key={article.id} className="flex items-center justify-between">
          <span>{article.name}</span>
          <Button
            variant="outline"
            size="sm"
            onClick={() => handleEdit(article)}
            className="gap-2"
          >
            <Pencil className="h-4 w-4" />
            Modifier
          </Button>
        </div>
      ))}

      {/* Modale de modification */}
      <EditArticleDialog
        article={editingArticle}
        open={editDialogOpen}
        onClose={() => {
          setEditDialogOpen(false);
          setEditingArticle(null);
        }}
        onSuccess={handleEditSuccess}
      />
    </div>
  );
}
```

## 🎨 COMPOSANT EditVariationDialog (pour modifier une variation)

**Structure du composant** :
```typescript
'use client';

import { useState, useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { toast } from '@/hooks/use-toast';
import api from '@/lib/api';

const variationSchema = z.object({
  name: z.string().min(1, "Le nom est requis").max(255, "Le nom ne peut pas dépasser 255 caractères"),
  quantity: z.number().int().min(1, "La quantité doit être supérieure à 0"),
  image: z.string().max(255, "L'URL de l'image ne peut pas dépasser 255 caractères").optional().nullable(),
});

interface EditVariationDialogProps {
  variation: Variation;
  article: Article;
  open: boolean;
  onClose: () => void;
  onSuccess: (updatedVariation: Variation) => void;
}

export const EditVariationDialog: React.FC<EditVariationDialogProps> = ({
  variation,
  article,
  open,
  onClose,
  onSuccess,
}) => {
  const [loading, setLoading] = useState(false);

  const form = useForm({
    resolver: zodResolver(variationSchema),
    defaultValues: {
      name: '',
      quantity: 1,
      image: '',
    },
  });

  useEffect(() => {
    if (variation && open) {
      form.reset({
        name: variation.name,
        quantity: variation.quantity,
        image: variation.image || '',
      });
    }
  }, [variation, open, form]);

  // Calculer la quantité disponible pour cette variation
  const totalOtherVariationsQuantity = article.variations
    ?.filter(v => v.id !== variation.id)
    .reduce((sum, v) => sum + v.quantity, 0) || 0;
  const availableQuantity = article.quantity - totalOtherVariationsQuantity;

  const onSubmit = async (data: z.infer<typeof variationSchema>) => {
    setLoading(true);
    try {
      const payload: any = {
        name: data.name,
        quantity: data.quantity,
      };

      if (data.image && data.image.trim() !== '') {
        payload.image = data.image;
      } else {
        payload.image = null;
      }

      const response = await api.put(`/api/variations/${variation.id}`, payload);

      toast({
        title: "Succès",
        description: "Variation modifiée avec succès",
      });

      onSuccess(response.data.data);
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
          description: "Erreur lors de la modification de la variation",
          variant: "destructive",
        });
      }
    } finally {
      setLoading(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onClose}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Modifier la variation</DialogTitle>
        </DialogHeader>
        <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="variation-name">Nom de la variation *</Label>
            <Input
              id="variation-name"
              placeholder="Ex: Taille S"
              {...form.register('name')}
            />
            {form.formState.errors.name && (
              <p className="text-sm text-red-500">{form.formState.errors.name.message}</p>
            )}
          </div>

          <div className="space-y-2">
            <Label htmlFor="variation-quantity">Quantité *</Label>
            <Input
              id="variation-quantity"
              type="number"
              min="1"
              max={availableQuantity}
              {...form.register('quantity', { valueAsNumber: true })}
            />
            <p className="text-sm text-muted-foreground">
              Quantité disponible : {availableQuantity}
            </p>
            {form.formState.errors.quantity && (
              <p className="text-sm text-red-500">{form.formState.errors.quantity.message}</p>
            )}
          </div>

          <div className="space-y-2">
            <Label htmlFor="variation-image">URL de l'image (optionnel)</Label>
            <Input
              id="variation-image"
              type="url"
              placeholder="https://example.com/image.jpg"
              {...form.register('image')}
            />
            {variation.image && (
              <img
                src={variation.image}
                alt={variation.name}
                className="w-32 h-32 object-cover rounded border mt-2"
              />
            )}
          </div>

          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={onClose} disabled={loading}>
              Annuler
            </Button>
            <Button type="submit" disabled={loading}>
              {loading ? 'Modification...' : 'Modifier'}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  );
};
```

## ✅ CHECKLIST

- [ ] Créer le composant `EditArticleDialog` avec tous les champs
- [ ] Pré-remplir le formulaire avec les données de l'article
- [ ] Validation Zod complète (name, sale_price, quantity, image)
- [ ] Gestion des erreurs (422, 404, 403, 500)
- [ ] Toast de succès/erreur
- [ ] Mise à jour de l'article dans la liste après modification
- [ ] Affichage des statistiques (optionnel)
- [ ] Affichage du type en lecture seule
- [ ] Gestion de l'image (affichage de l'image actuelle si disponible)
- [ ] **Section Variations (si article variable)** :
  - [ ] Afficher la liste des variations avec leurs statistiques
  - [ ] Bouton "Modifier" pour chaque variation
  - [ ] Bouton "Supprimer" pour chaque variation
  - [ ] Modale de modification de variation (`EditVariationDialog`)
  - [ ] Dialogue de confirmation pour la suppression
  - [ ] Mise à jour de la liste des variations après modification/suppression
- [ ] État de chargement pendant la soumission
- [ ] Code typé TypeScript sans erreurs

## 🎯 RÉSULTAT ATTENDU

- Un bouton "Modifier" est visible dans la liste ou la page de détail
- Au clic, une modale s'ouvre avec les données de l'article pré-remplies
- L'utilisateur peut modifier le nom, le prix unitaire, la quantité et l'image
- Le type est affiché en lecture seule
- **Pour les articles variables** :
  - Une section "Variations" s'affiche avec la liste des variations
  - Chaque variation peut être modifiée (nom, quantité, image)
  - Chaque variation peut être supprimée (avec confirmation)
  - Les statistiques de chaque variation sont affichées
- Après soumission, l'article est modifié via l'API
- Un toast de succès s'affiche
- L'article est mis à jour dans la liste
- Les variations sont mises à jour en temps réel
- Les erreurs sont gérées proprement avec des toasts

Créez le composant EditArticleDialog complet avec toute cette logique, incluant la gestion des variations pour les articles variables.
```

---

## 📝 NOTES IMPORTANTES

1. **Validation du nom unique** : Le backend vérifie que le nom est unique par utilisateur. Si le nom n'a pas changé, la validation passera. Si le nom a changé et existe déjà, une erreur 422 sera retournée.

2. **Type d'article** : Le type ne peut pas être modifié (vérifié côté backend). Il est affiché en lecture seule dans la modale.

3. **Image** : Si l'article a une image, elle est affichée dans la modale. L'utilisateur peut la modifier ou la supprimer en laissant le champ vide.

4. **Statistiques** : Les statistiques (sold_quantity, remaining_quantity, sales_percentage) sont affichées à titre informatif mais ne sont pas modifiables directement.

5. **Mise à jour de la liste** : Après modification réussie, il faut mettre à jour l'article dans la liste locale ou recharger la liste depuis l'API.

6. **Gestion des variations** :
   - Les variations sont chargées automatiquement si l'article est de type "variable"
   - La modale reste ouverte après modification de l'article pour permettre de continuer à modifier les variations
   - Chaque variation peut être modifiée via une sous-modale (`EditVariationDialog`)
   - La suppression d'une variation nécessite une confirmation (AlertDialog)
   - La liste des variations est mise à jour en temps réel après modification/suppression

7. **Quantité disponible pour les variations** : Lors de la modification d'une variation, la quantité disponible est calculée en soustrayant la somme des quantités des autres variations de la quantité totale de l'article.

8. **Rechargement des variations** : Après modification de l'article, si l'article est variable, recharger les variations depuis la réponse de l'API pour obtenir les statistiques à jour.

