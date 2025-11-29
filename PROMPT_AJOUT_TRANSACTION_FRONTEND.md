# 📋 PROMPT DÉTAILLÉ - AJOUT DE TRANSACTION (VENTE OU DÉPENSE)

## 🚀 Copiez ce prompt dans Cursor :

```
Je dois créer un composant de modale pour ajouter une transaction (vente ou dépense) dans ma page Wallet Next.js.

**IMPORTANT** : Ce composant utilise le Combobox de shadcn/ui. Si vous ne l'avez pas encore installé, exécutez :
```bash
npx shadcn-ui@latest add combobox
```

Le Combobox permet une recherche en temps réel avec autocomplétion, parfait pour rechercher parmi de nombreux articles.

## 🔗 CONFIGURATION API

**Endpoint** : `POST http://localhost:8000/api/transactions`

**Authentification** : Cookies HTTP-only (withCredentials: true)
- Utiliser l'instance axios configurée dans `lib/api.ts`
- Le cookie CSRF est géré automatiquement par l'intercepteur

## 📋 STRUCTURE DE LA MODALE

### 1. Sélection du Type de Transaction

La modale doit avoir deux onglets ou un sélecteur pour choisir entre :
- **Vente** : Pour enregistrer une vente d'article
- **Dépense** : Pour enregistrer une dépense

**Composant suggéré** : Utiliser Tabs de shadcn/ui ou un RadioGroup

### 2. Formulaire pour AJOUTER UNE VENTE

**Champs requis** :
1. **Barre de recherche d'article** (Combobox avec recherche) : 
   - Utiliser le composant Combobox de shadcn/ui
   - Récupérer la liste via `GET /api/articles` (les variations sont déjà chargées)
   - Fonctionnalité de recherche : filtrer les articles/variations en temps réel pendant la saisie
   - **Affichage pour articles simples** :
     - Format : `"Nom de l'article (Quantité restante: X)"`
     - Exemple : `"Ordinateur Portable Dell (Quantité restante: 12)"`
     - Au clic : récupérer uniquement l'`article_id`
   
   - **Affichage pour articles variables** :
     - Pour chaque variation de l'article, afficher une option séparée
     - Format : `"Nom de l'article - Nom de la variation (Quantité restante: X)"`
     - Exemple : `"T-Shirt Premium - S (Quantité restante: 20)"`
     - Exemple : `"T-Shirt Premium - M (Quantité restante: 15)"`
     - Au clic : récupérer l'`article_id` ET le `variable_id` (id de la variation)
   
   - **Comportement de recherche** :
     - Filtrer par nom d'article OU nom de variation
     - Si on tape "T-Shirt", afficher toutes les variations de "T-Shirt Premium"
     - Si on tape "S", afficher seulement "T-Shirt Premium - S"
   
   - **Sélection** :
     - Si article simple sélectionné : définir `article_id`, `variable_id = null`
     - Si article variable sélectionné : définir `article_id` et `variable_id`
     - Masquer automatiquement le champ "Variation" (plus besoin car sélectionné dans la recherche)

2. **Quantité** (Input nombre) :
   - Validation : nombre entier, minimum 1
   - Afficher la quantité disponible automatiquement selon la sélection :
     - Pour article simple : `article.remaining_quantity`
     - Pour article variable : `variation.remaining_quantity` (de la variation sélectionnée)
   - Vérifier que la quantité saisie ne dépasse pas le stock disponible
   - Mettre à jour automatiquement quand on change d'article/variation

3. **Prix de vente** (Input nombre optionnel) :
   - Valeur par défaut : `article.sale_price` (même prix pour toutes les variations d'un article)
   - Validation : nombre décimal, minimum 0
   - Format : 2 décimales
   - Si non renseigné, le backend utilise le prix de l'article

4. **Montant calculé** (Affichage en temps réel) :
   - Calcul : `quantity * sale_price`
   - Mise à jour automatique quand quantity ou sale_price change
   - Format : devise (ex: "1 799,98 €")

**Validation Zod pour vente** :
```typescript
const saleSchema = z.object({
  article_id: z.string().min(1, "L'article est requis"),
  variable_id: z.string().optional().nullable(),
  quantity: z.number().int().min(1, "La quantité doit être au moins 1"),
  sale_price: z.number().min(0, "Le prix de vente doit être positif").optional(),
});
```

**Note** : La validation de `variable_id` pour les articles variables sera gérée dans le composant, pas dans Zod, car on sélectionne directement l'article+variation dans la barre de recherche.

### 3. Formulaire pour AJOUTER UNE DÉPENSE

**Champs requis** :
1. **Nom** (Input texte) :
   - Validation : minimum 1 caractère
   - Exemple : "Loyer du local commercial", "Publicité Facebook Ads"

2. **Montant** (Input nombre) :
   - Validation : nombre décimal, minimum 0
   - Format : 2 décimales
   - Exemple : 1200.00

**Validation Zod pour dépense** :
```typescript
const expenseSchema = z.object({
  name: z.string().min(1, "Le nom est requis"),
  amount: z.number().min(0, "Le montant doit être positif"),
});
```

## 📤 ENDPOINTS À UTILISER

### Récupérer les articles
**GET** `/api/articles`

**Réponse** :
```typescript
{
  success: boolean;
  message: string;
  data: Article[];
}

interface Article {
  id: string;
  name: string;
  sale_price: number;
  quantity: number;
  type: 'simple' | 'variable';
  remaining_quantity: number; // Attribut calculé
  variations?: Variation[]; // Si type === 'variable'
}
```

### Créer une vente
**POST** `/api/transactions`

**Body pour vente** :
```typescript
{
  type: 'sale';
  article_id: string;
  variable_id?: string | null; // Requis si article.type === 'variable'
  quantity: number;
  sale_price?: number; // Optionnel, utilise article.sale_price par défaut
}
```

**Réponse succès (201)** :
```typescript
{
  success: true;
  message: "Vente enregistrée avec succès";
  data: Transaction; // Avec article et variation chargés
}
```

**Réponses erreur** :
- **422** : Erreur de validation (variable_id manquant pour article variable, etc.)
- **400** : Quantité insuffisante
- **403** : Article non trouvé ou non autorisé

### Créer une dépense
**POST** `/api/transactions`

**Body pour dépense** :
```typescript
{
  type: 'expense';
  name: string;
  amount: number;
}
```

**Réponse succès (201)** :
```typescript
{
  success: true;
  message: "Dépense enregistrée avec succès";
  data: Transaction;
}
```

## 🎨 COMPOSANT AddTransactionDialog

**Structure du composant** :
```typescript
interface AddTransactionDialogProps {
  open: boolean;
  onClose: () => void;
  onSuccess: (transaction: Transaction) => void;
}

const AddTransactionDialog: React.FC<AddTransactionDialogProps> = ({
  open,
  onClose,
  onSuccess,
}) => {
  const [transactionType, setTransactionType] = useState<'sale' | 'expense'>('sale');
  const [articles, setArticles] = useState<Article[]>([]);
  const [selectedArticle, setSelectedArticle] = useState<Article | null>(null);
  const [loading, setLoading] = useState(false);
  
  // ... implémentation
};
```

## 🔄 LOGIQUE POUR LA VENTE

### 1. Chargement des articles
```typescript
useEffect(() => {
  if (open && transactionType === 'sale') {
    fetchArticles();
  }
}, [open, transactionType]);

const fetchArticles = async () => {
  try {
    const response = await api.get('/api/articles');
    setArticles(response.data.data || []);
  } catch (error) {
    toast.error('Erreur lors du chargement des articles');
  }
};
```

### 2. Gestion de la sélection d'article
```typescript
const handleArticleChange = (articleId: string) => {
  const article = articles.find(a => a.id === articleId);
  setSelectedArticle(article || null);
  
  // Réinitialiser la variation si l'article change
  form.setValue('variable_id', null);
  
  // Si l'article est simple, réinitialiser variable_id
  if (article?.type === 'simple') {
    form.setValue('variable_id', null);
  }
};
```

### 4. Calcul du montant en temps réel
```typescript
const watchedQuantity = form.watch('quantity');
const watchedSalePrice = form.watch('sale_price');

const calculatedAmount = useMemo(() => {
  if (!watchedQuantity || !selectedOption) return 0;
  const price = watchedSalePrice || selectedOption.article.sale_price;
  return watchedQuantity * price;
}, [watchedQuantity, watchedSalePrice, selectedOption]);
```

### 5. Obtenir la quantité disponible
```typescript
const getAvailableQuantity = (): number => {
  if (!selectedOption) return 0;
  return selectedOption.remainingQuantity;
};
```

### 6. Filtrage de la recherche
```typescript
const [searchQuery, setSearchQuery] = useState('');

const filteredOptions = useMemo(() => {
  if (!searchQuery) return searchOptions;
  
  const query = searchQuery.toLowerCase();
  return searchOptions.filter(option => 
    option.label.toLowerCase().includes(query)
  );
}, [searchQuery, searchOptions]);
```

// Validation Zod avec superRefine pour validation dynamique
const saleSchema = z.object({
  article_id: z.string().min(1, "L'article est requis"),
  variable_id: z.string().optional().nullable(),
  quantity: z.number().int().min(1, "La quantité doit être au moins 1"),
  sale_price: z.number().min(0, "Le prix de vente doit être positif").optional(),
}).superRefine((data, ctx) => {
  // Validation de la quantité disponible (faite côté client pour UX)
  // Le backend validera aussi, mais on peut prévenir l'utilisateur
  // Cette validation sera faite dans le composant avec un useEffect
});
```

## 📝 EXEMPLE COMPLET DU COMPOSANT

```typescript
'use client';

import { useState, useEffect, useMemo } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Combobox } from '@/components/ui/combobox';
import { toast } from '@/hooks/use-toast';
import api from '@/lib/api';
import { formatCurrency } from '@/lib/utils/currency';

interface Article {
  id: string;
  name: string;
  sale_price: number;
  quantity: number;
  type: 'simple' | 'variable';
  remaining_quantity: number;
  variations?: Variation[];
}

interface Variation {
  id: string;
  name: string;
  quantity: number;
  remaining_quantity: number;
}

const saleSchema = z.object({
  article_id: z.string().min(1, "L'article est requis"),
  variable_id: z.string().optional().nullable(),
  quantity: z.number().int().min(1, "La quantité doit être au moins 1"),
  sale_price: z.number().min(0, "Le prix de vente doit être positif").optional(),
});

const expenseSchema = z.object({
  name: z.string().min(1, "Le nom est requis"),
  amount: z.number().min(0, "Le montant doit être positif"),
});

interface AddTransactionDialogProps {
  open: boolean;
  onClose: () => void;
  onSuccess: (transaction: any) => void;
}

interface SearchOption {
  id: string;
  label: string;
  articleId: string;
  variableId?: string | null;
  article: Article;
  variation?: Variation;
  remainingQuantity: number;
}

export const AddTransactionDialog: React.FC<AddTransactionDialogProps> = ({
  open,
  onClose,
  onSuccess,
}) => {
  const [transactionType, setTransactionType] = useState<'sale' | 'expense'>('sale');
  const [articles, setArticles] = useState<Article[]>([]);
  const [searchOptions, setSearchOptions] = useState<SearchOption[]>([]);
  const [selectedOption, setSelectedOption] = useState<SearchOption | null>(null);
  const [searchQuery, setSearchQuery] = useState('');
  const [loading, setLoading] = useState(false);

  const schema = transactionType === 'sale' ? saleSchema : expenseSchema;
  const form = useForm({
    resolver: zodResolver(schema),
    defaultValues: {
      article_id: '',
      variable_id: null,
      quantity: 1,
      sale_price: undefined,
      name: '',
      amount: 0,
    },
  });

  // Charger les articles quand la modale s'ouvre pour une vente
  useEffect(() => {
    if (open && transactionType === 'sale') {
      fetchArticles();
    }
  }, [open, transactionType]);


  // Préparer les options de recherche
  const prepareSearchOptions = (articlesData: Article[]): SearchOption[] => {
    const options: SearchOption[] = [];
    
    articlesData.forEach(article => {
      if (article.type === 'simple') {
        // Article simple : une seule option
        options.push({
          id: `article-${article.id}`,
          label: `${article.name} (Quantité restante: ${article.remaining_quantity})`,
          articleId: article.id,
          variableId: null,
          article: article,
          remainingQuantity: article.remaining_quantity,
        });
      } else if (article.type === 'variable' && article.variations) {
        // Article variable : une option par variation
        article.variations.forEach(variation => {
          options.push({
            id: `variation-${variation.id}`,
            label: `${article.name} - ${variation.name} (Quantité restante: ${variation.remaining_quantity})`,
            articleId: article.id,
            variableId: variation.id,
            article: article,
            variation: variation,
            remainingQuantity: variation.remaining_quantity,
          });
        });
      }
    });
    
    return options;
  };

  // Réinitialiser le formulaire quand on change de type
  useEffect(() => {
    form.reset();
    setSelectedOption(null);
    setSearchQuery('');
  }, [transactionType]);

  const fetchArticles = async () => {
    try {
      const response = await api.get('/api/articles');
      const articlesData = response.data.data || [];
      setArticles(articlesData);
      
      // Préparer les options de recherche
      const options = prepareSearchOptions(articlesData);
      setSearchOptions(options);
    } catch (error) {
      toast({
        title: "Erreur",
        description: "Erreur lors du chargement des articles",
        variant: "destructive",
      });
    }
  };

  // Filtrer les options selon la recherche
  const filteredOptions = useMemo(() => {
    if (!searchQuery) return searchOptions;
    
    const query = searchQuery.toLowerCase();
    return searchOptions.filter(option => 
      option.label.toLowerCase().includes(query)
    );
  }, [searchQuery, searchOptions]);

  const handleSelectOption = (option: SearchOption) => {
    setSelectedOption(option);
    
    // Définir les valeurs du formulaire
    form.setValue('article_id', option.articleId);
    form.setValue('variable_id', option.variableId || null);
    
    // Définir le prix par défaut
    form.setValue('sale_price', option.article.sale_price);
    
    // Réinitialiser la quantité
    form.setValue('quantity', 1);
    
    // Réinitialiser la recherche
    setSearchQuery('');
  };

  // Calculer le montant en temps réel pour les ventes
  const watchedQuantity = form.watch('quantity');
  const watchedSalePrice = form.watch('sale_price');
  
  const calculatedAmount = useMemo(() => {
    if (transactionType !== 'sale' || !watchedQuantity || !selectedOption) return 0;
    const price = watchedSalePrice || selectedOption.article.sale_price;
    return watchedQuantity * price;
  }, [transactionType, watchedQuantity, watchedSalePrice, selectedOption]);

  // Obtenir la quantité disponible
  const getAvailableQuantity = (): number => {
    if (!selectedOption) return 0;
    return selectedOption.remainingQuantity;
  };

  const onSubmit = async (data: any) => {
    setLoading(true);
    try {
      const payload: any = {
        type: transactionType,
      };

      if (transactionType === 'sale') {
        if (!selectedOption) {
          toast({
            title: "Erreur",
            description: "Veuillez sélectionner un article",
            variant: "destructive",
          });
          setLoading(false);
          return;
        }
        
        payload.article_id = selectedOption.articleId;
        payload.quantity = data.quantity;
        
        // Si c'est un article variable, ajouter variable_id
        if (selectedOption.variableId) {
          payload.variable_id = selectedOption.variableId;
        }
        
        // Si le prix est différent du prix par défaut, l'envoyer
        if (data.sale_price && data.sale_price !== selectedOption.article.sale_price) {
          payload.sale_price = data.sale_price;
        }
      } else {
        payload.name = data.name;
        payload.amount = data.amount;
      }

      const response = await api.post('/api/transactions', payload);
      
      toast({
        title: "Succès",
        description: transactionType === 'sale' 
          ? "Vente enregistrée avec succès" 
          : "Dépense enregistrée avec succès",
      });
      
      onSuccess(response.data.data);
      form.reset();
      setSelectedOption(null);
      setSearchQuery('');
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
          description: "Erreur lors de la création de la transaction",
          variant: "destructive",
        });
      }
    } finally {
      setLoading(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onClose}>
      <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Ajouter une transaction</DialogTitle>
        </DialogHeader>
        
        <Tabs value={transactionType} onValueChange={(v) => setTransactionType(v as 'sale' | 'expense')}>
          <TabsList className="grid w-full grid-cols-2">
            <TabsTrigger value="sale">Vente</TabsTrigger>
            <TabsTrigger value="expense">Dépense</TabsTrigger>
          </TabsList>

          <TabsContent value="sale">
            <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
              {/* Barre de recherche d'article/variation */}
              <div className="space-y-2">
                <Label>Rechercher un article *</Label>
                <Combobox
                  options={filteredOptions}
                  value={selectedOption}
                  onSelect={handleSelectOption}
                  searchQuery={searchQuery}
                  onSearchChange={setSearchQuery}
                  placeholder="Tapez pour rechercher un article..."
                  emptyMessage="Aucun article trouvé"
                  displayValue={(option) => option.label}
                />
                {!selectedOption && form.formState.errors.article_id && (
                  <p className="text-sm text-red-500">
                    {form.formState.errors.article_id.message}
                  </p>
                )}
                {selectedOption && (
                  <p className="text-sm text-muted-foreground">
                    Sélectionné : {selectedOption.label}
                  </p>
                )}
              </div>

              {/* Quantité */}
              <div className="space-y-2">
                <Label htmlFor="quantity">Quantité *</Label>
                <Input
                  id="quantity"
                  type="number"
                  min="1"
                  max={getAvailableQuantity()}
                  {...form.register('quantity', { 
                    valueAsNumber: true,
                    validate: (value) => {
                      const available = getAvailableQuantity();
                      if (value > available) {
                        return `Quantité insuffisante. Disponible: ${available}`;
                      }
                      return true;
                    }
                  })}
                />
                <p className="text-sm text-muted-foreground">
                  Disponible : {getAvailableQuantity()}
                </p>
                {form.formState.errors.quantity && (
                  <p className="text-sm text-red-500">
                    {form.formState.errors.quantity.message}
                  </p>
                )}
              </div>

              {/* Prix de vente */}
              <div className="space-y-2">
                <Label htmlFor="sale_price">Prix de vente (optionnel)</Label>
                <Input
                  id="sale_price"
                  type="number"
                  step="0.01"
                  min="0"
                  placeholder={selectedArticle?.sale_price?.toString() || "0.00"}
                  {...form.register('sale_price', { valueAsNumber: true })}
                />
                <p className="text-sm text-muted-foreground">
                  Prix par défaut : {formatCurrency(selectedArticle?.sale_price || 0)}
                </p>
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
              </div>

              <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onClose}>
                  Annuler
                </Button>
                <Button type="submit" disabled={loading}>
                  {loading ? 'Enregistrement...' : 'Enregistrer la vente'}
                </Button>
              </div>
            </form>
          </TabsContent>

          <TabsContent value="expense">
            <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
              {/* Nom de la dépense */}
              <div className="space-y-2">
                <Label htmlFor="name">Nom de la dépense *</Label>
                <Input
                  id="name"
                  placeholder="Ex: Loyer du local commercial"
                  {...form.register('name')}
                />
                {form.formState.errors.name && (
                  <p className="text-sm text-red-500">
                    {form.formState.errors.name.message}
                  </p>
                )}
              </div>

              {/* Montant */}
              <div className="space-y-2">
                <Label htmlFor="amount">Montant *</Label>
                <Input
                  id="amount"
                  type="number"
                  step="0.01"
                  min="0"
                  placeholder="0.00"
                  {...form.register('amount', { valueAsNumber: true })}
                />
                {form.formState.errors.amount && (
                  <p className="text-sm text-red-500">
                    {form.formState.errors.amount.message}
                  </p>
                )}
              </div>

              <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onClose}>
                  Annuler
                </Button>
                <Button type="submit" disabled={loading}>
                  {loading ? 'Enregistrement...' : 'Enregistrer la dépense'}
                </Button>
              </div>
            </form>
          </TabsContent>
        </Tabs>
      </DialogContent>
    </Dialog>
  );
};
```

## ✅ CHECKLIST

- [ ] Installer le composant Combobox de shadcn/ui (`npx shadcn-ui@latest add combobox`)
- [ ] Modale avec onglets Vente/Dépense
- [ ] Pour vente : **barre de recherche avec Combobox** (pas de Select)
- [ ] Pour vente : affichage des articles simples avec quantité restante
- [ ] Pour vente : affichage des articles variables avec format "Article - Variation (Quantité restante: X)"
- [ ] Pour vente : filtrage en temps réel pendant la saisie
- [ ] Pour vente : récupération automatique de `article_id` et `variable_id` au clic
- [ ] Pour vente : champs quantity et sale_price
- [ ] Pour vente : calcul en temps réel du montant
- [ ] Pour vente : affichage de la quantité disponible selon la sélection
- [ ] Pour dépense : champs name et amount
- [ ] Validation Zod complète
- [ ] Gestion des erreurs (422, 400, 403)
- [ ] Toast de succès/erreur
- [ ] Rechargement des statistiques après ajout
- [ ] Ajout de la transaction dans la liste locale
- [ ] Code typé TypeScript sans erreurs

## 🎯 RÉSULTAT ATTENDU

- La modale s'ouvre avec deux onglets : Vente et Dépense
- Pour une vente : **barre de recherche avec Combobox** qui permet de :
  - Taper pour rechercher un article ou une variation
  - Voir les articles simples : "Nom (Quantité restante: X)"
  - Voir les articles variables : "Nom Article - Nom Variation (Quantité restante: X)"
  - Filtrer en temps réel pendant la saisie
  - Cliquer pour sélectionner (récupère automatiquement `article_id` et `variable_id` si variable)
- Pour une dépense : on peut saisir nom et montant
- Le montant est calculé en temps réel pour les ventes
- Les erreurs sont gérées proprement
- Après ajout, la transaction apparaît dans la liste et les statistiques sont mises à jour

**Note sur le Combobox** : Si le composant Combobox de shadcn/ui n'existe pas ou a une API différente, vous pouvez utiliser un composant de recherche personnalisé basé sur Command + Popover de shadcn/ui, ou créer un composant simple avec Input + liste déroulante filtrée.

Crée le composant AddTransactionDialog complet avec toute cette logique.
```

---

## 📝 NOTES IMPORTANTES

1. **Articles variables** : Le champ `variable_id` est obligatoire si `article.type === 'variable'`
2. **Articles simples** : Le champ `variable_id` doit être `null` si `article.type === 'simple'`
3. **Prix de vente** : Si non renseigné, le backend utilise `article.sale_price`
4. **Nom de la vente** : Généré automatiquement par le backend
5. **Stock** : Vérifier la quantité disponible avant validation
6. **Calcul montant** : Mise à jour en temps réel pour les ventes

