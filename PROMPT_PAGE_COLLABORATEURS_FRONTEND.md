# 📋 PROMPT DÉTAILLÉ - PAGE COLLABORATEURS

## 🚀 Copiez ce prompt dans Cursor :

```
Je dois créer une page complète de gestion des collaborateurs dans mon frontend Next.js avec :
1. Une page de liste des collaborateurs avec des cards
2. Un bouton pour ajouter un collaborateur
3. La possibilité de modifier un collaborateur
4. La possibilité de supprimer un collaborateur
5. Une page de détail lorsqu'on clique sur un collaborateur

## 🔗 CONFIGURATION API

**Base URL** : `http://localhost:8000`

**Endpoints** :
- `GET /api/collaborators` : Liste des collaborateurs
- `GET /api/collaborators/{id}` : Détails d'un collaborateur
- `POST /api/collaborators` : Créer un collaborateur
- `PUT /api/collaborators/{id}` : Modifier un collaborateur
- `DELETE /api/collaborators/{id}` : Supprimer un collaborateur
- `GET /api/user` : Récupérer les informations de l'utilisateur (pour obtenir company_share disponible)

**Authentification** : Cookies HTTP-only (withCredentials: true)
- Utiliser l'instance axios configurée dans `lib/api.ts`

## 📋 STRUCTURE DE LA PAGE

### 1. Page de Liste des Collaborateurs (`/collaborators`)

**Layout** :
- En-tête avec titre "Collaborateurs"
- Bouton "Ajouter un collaborateur" en haut à droite
- Liste des collaborateurs en cards (grid responsive)

**Card de collaborateur** :
- Image du collaborateur (si disponible) ou avatar par défaut
- Nom du collaborateur
- Téléphone
- Part (pourcentage) : `{part}%`
- Wallet calculé : `{wallet} €` (formaté avec formatCurrency)
- Actions : 
  - Bouton "Voir détails" (redirige vers `/collaborators/{id}`)
  - Bouton "Modifier" (ouvre modale)
  - Bouton "Supprimer" (ouvre dialogue de confirmation)

**États** :
- Loading : Skeleton loaders pendant le chargement
- Empty : Message si aucun collaborateur
- Error : Affichage d'erreur avec possibilité de réessayer

### 2. Modale d'Ajout de Collaborateur

**Champs** :
1. **Nom** (Input texte) : 
   - Validation : requis, max 255 caractères
   - Placeholder : "Ex: Jean Dupont"

2. **Téléphone** (Input texte) :
   - Validation : requis, max 20 caractères
   - Placeholder : "Ex: +33 6 12 34 56 78"

3. **Part** (Input nombre) :
   - Validation : requis, nombre décimal, entre 0.01 et 99.99
   - Format : 2 décimales
   - Afficher la part disponible de l'utilisateur : "Part disponible : {user.company_share}%"
   - Validation : part ≤ company_share disponible

4. **Image** (Input texte optionnel) :
   - Validation : string, max 255 caractères
   - Placeholder : "https://example.com/image.jpg"

**Validation Zod** :
```typescript
const collaboratorSchema = z.object({
  name: z.string().min(1, "Le nom est requis").max(255, "Le nom ne peut pas dépasser 255 caractères"),
  phone: z.string().min(1, "Le téléphone est requis").max(20, "Le téléphone ne peut pas dépasser 20 caractères"),
  part: z.number().min(0.01, "La part doit être au moins 0.01%").max(99.99, "La part ne peut pas dépasser 99.99%"),
  image: z.string().max(255, "L'URL de l'image ne peut pas dépasser 255 caractères").optional().nullable(),
});
```

### 3. Modale de Modification de Collaborateur

**Champs modifiables** :
- **Nom** : Modifiable
- **Téléphone** : Modifiable
- **Image** : Modifiable

**Champs en lecture seule** :
- **Part** : Affiché mais non modifiable (la part ne peut pas être modifiée après création)
- **Wallet** : Affiché mais non modifiable (calculé automatiquement)

### 4. Dialogue de Suppression

**Composant** : `AlertDialog` de shadcn/ui

**Contenu** :
- Titre : "Supprimer le collaborateur ?"
- Description : "Cette action est irréversible. La part du collaborateur sera restituée à votre compte."
- Boutons : "Annuler" et "Supprimer" (destructive)

### 5. Page de Détail (`/collaborators/[id]`)

**Informations affichées** :
- Image du collaborateur (grande taille)
- Nom complet
- Téléphone
- Part (pourcentage)
- Wallet calculé
- Date de création
- Statistiques optionnelles

**Actions** :
- Bouton "Modifier" (ouvre modale)
- Bouton "Supprimer" (ouvre dialogue de confirmation)
- Bouton "Retour" (retour à la liste)

## 📤 ENDPOINTS ET RÉPONSES

### Récupérer la liste des collaborateurs
**GET** `/api/collaborators`

**Réponse** :
```typescript
{
  success: boolean;
  message: string;
  data: Collaborator[];
}

interface Collaborator {
  id: string;
  user_id: string;
  name: string;
  phone: string;
  part: number; // Décimal entre 0.01 et 99.99
  image?: string | null;
  wallet: number; // Calculé automatiquement
  created_at: string;
  updated_at: string;
}
```

### Récupérer les détails d'un collaborateur
**GET** `/api/collaborators/{id}`

**Réponse** : Même structure que ci-dessus, mais un seul objet

### Créer un collaborateur
**POST** `/api/collaborators`

**Body** :
```typescript
{
  name: string;
  phone: string;
  part: number; // Entre 0.01 et 99.99, doit être ≤ user.company_share
  image?: string | null;
}
```

**Réponse succès (201)** :
```typescript
{
  success: true;
  message: "Collaborator created";
  data: Collaborator;
}
```

**Réponses erreur** :
- **422** : Erreur de validation
- **400** : Part dépasse company_share disponible
- **404** : Utilisateur non trouvé

### Modifier un collaborateur
**PUT** `/api/collaborators/{id}`

**Body** :
```typescript
{
  name?: string;
  phone?: string;
  image?: string | null;
}
```

**Note** : `part` et `wallet` ne peuvent PAS être modifiés (erreur 422 si envoyés)

**Réponse succès (200)** :
```typescript
{
  success: true;
  message: "Collaborateur mis à jour avec succès";
  data: Collaborator;
}
```

**Réponses erreur** :
- **404** : Collaborateur non trouvé
- **403** : Collaborateur non autorisé
- **422** : Erreur de validation ou tentative de modifier part/wallet

### Supprimer un collaborateur
**DELETE** `/api/collaborators/{id}`

**Réponse succès (200)** :
```typescript
{
  success: true;
  message: "Collaborateur supprimé avec succès";
  data: {
    returned_part: number; // Part restituée à l'utilisateur
  }
}
```

**Réponses erreur** :
- **404** : Collaborateur non trouvé
- **403** : Collaborateur non autorisé
- **500** : Erreur serveur

### Récupérer les informations de l'utilisateur
**GET** `/api/user`

**Réponse** :
```typescript
{
  id: string;
  company_share: number; // Part disponible pour créer des collaborateurs
  // ... autres champs
}
```

## 🎨 COMPOSANT CollaboratorsPage

**Structure du composant** :
```typescript
'use client';

import { useState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { toast } from '@/hooks/use-toast';
import api from '@/lib/api';
import { formatCurrency } from '@/lib/utils/currency';
import { Plus, Pencil, Trash2, User } from 'lucide-react';
import { AddCollaboratorDialog } from '@/components/collaborators/AddCollaboratorDialog';
import { EditCollaboratorDialog } from '@/components/collaborators/EditCollaboratorDialog';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';

interface Collaborator {
  id: string;
  user_id: string;
  name: string;
  phone: string;
  part: number;
  image?: string | null;
  wallet: number;
  created_at: string;
  updated_at: string;
}

interface User {
  id: string;
  company_share: number;
  // ... autres champs
}

export default function CollaboratorsPage() {
  const router = useRouter();
  const [collaborators, setCollaborators] = useState<Collaborator[]>([]);
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);
  const [addDialogOpen, setAddDialogOpen] = useState(false);
  const [editingCollaborator, setEditingCollaborator] = useState<Collaborator | null>(null);
  const [editDialogOpen, setEditDialogOpen] = useState(false);
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
  const [collaboratorToDelete, setCollaboratorToDelete] = useState<Collaborator | null>(null);

  useEffect(() => {
    fetchCollaborators();
    fetchUser();
  }, []);

  const fetchCollaborators = async () => {
    try {
      const response = await api.get('/api/collaborators');
      setCollaborators(response.data.data || []);
    } catch (error) {
      toast({
        title: "Erreur",
        description: "Erreur lors du chargement des collaborateurs",
        variant: "destructive",
      });
    } finally {
      setLoading(false);
    }
  };

  const fetchUser = async () => {
    try {
      const response = await api.get('/api/user');
      setUser(response.data);
    } catch (error) {
      console.error('Erreur lors du chargement de l\'utilisateur');
    }
  };

  const handleAddSuccess = (newCollaborator: Collaborator) => {
    setCollaborators([newCollaborator, ...collaborators]);
    fetchUser(); // Recharger pour mettre à jour company_share
    toast({
      title: "Succès",
      description: "Collaborateur ajouté avec succès",
    });
  };

  const handleEditSuccess = (updatedCollaborator: Collaborator) => {
    setCollaborators(collaborators.map(c => 
      c.id === updatedCollaborator.id ? updatedCollaborator : c
    ));
    toast({
      title: "Succès",
      description: "Collaborateur modifié avec succès",
    });
  };

  const handleDelete = async () => {
    if (!collaboratorToDelete) return;

    try {
      await api.delete(`/api/collaborators/${collaboratorToDelete.id}`);
      setCollaborators(collaborators.filter(c => c.id !== collaboratorToDelete.id));
      fetchUser(); // Recharger pour mettre à jour company_share
      toast({
        title: "Succès",
        description: "Collaborateur supprimé avec succès",
      });
      setDeleteDialogOpen(false);
      setCollaboratorToDelete(null);
    } catch (error: any) {
      toast({
        title: "Erreur",
        description: error.response?.data?.message || "Erreur lors de la suppression",
        variant: "destructive",
      });
    }
  };

  if (loading) {
    return (
      <div className="container mx-auto p-6">
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {[1, 2, 3].map((i) => (
            <Card key={i}>
              <CardContent className="p-6">
                <Skeleton className="h-32 w-full mb-4" />
                <Skeleton className="h-4 w-3/4 mb-2" />
                <Skeleton className="h-4 w-1/2" />
              </CardContent>
            </Card>
          ))}
        </div>
      </div>
    );
  }

  return (
    <div className="container mx-auto p-6 space-y-6">
      {/* En-tête */}
      <div className="flex items-center justify-between">
        <h1 className="text-3xl font-bold">Collaborateurs</h1>
        <Button onClick={() => setAddDialogOpen(true)} className="gap-2">
          <Plus className="h-4 w-4" />
          Ajouter un collaborateur
        </Button>
      </div>

      {/* Liste des collaborateurs */}
      {collaborators.length === 0 ? (
        <Card>
          <CardContent className="p-12 text-center">
            <User className="h-16 w-16 mx-auto text-muted-foreground mb-4" />
            <p className="text-lg text-muted-foreground">Aucun collaborateur</p>
            <p className="text-sm text-muted-foreground mt-2">
              Commencez par ajouter votre premier collaborateur
            </p>
          </CardContent>
        </Card>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {collaborators.map((collaborator) => (
            <Card key={collaborator.id} className="hover:shadow-lg transition-shadow">
              <CardContent className="p-6">
                {/* Image ou avatar */}
                <div className="flex justify-center mb-4">
                  {collaborator.image ? (
                    <img
                      src={collaborator.image}
                      alt={collaborator.name}
                      className="w-24 h-24 rounded-full object-cover border-2"
                      onError={(e) => {
                        (e.target as HTMLImageElement).style.display = 'none';
                        (e.target as HTMLImageElement).nextElementSibling?.classList.remove('hidden');
                      }}
                    />
                  ) : null}
                  <div className={`w-24 h-24 rounded-full bg-muted flex items-center justify-center ${collaborator.image ? 'hidden' : ''}`}>
                    <User className="h-12 w-12 text-muted-foreground" />
                  </div>
                </div>

                {/* Informations */}
                <div className="text-center space-y-2 mb-4">
                  <h3 className="text-xl font-semibold">{collaborator.name}</h3>
                  <p className="text-sm text-muted-foreground">{collaborator.phone}</p>
                  <div className="flex items-center justify-center gap-4 text-sm">
                    <span className="text-muted-foreground">Part:</span>
                    <span className="font-medium">{collaborator.part}%</span>
                  </div>
                  <div className="flex items-center justify-center gap-4 text-sm">
                    <span className="text-muted-foreground">Wallet:</span>
                    <span className="font-semibold text-lg">{formatCurrency(collaborator.wallet)}</span>
                  </div>
                </div>

                {/* Actions */}
                <div className="flex gap-2">
                  <Button
                    variant="outline"
                    className="flex-1"
                    onClick={() => router.push(`/collaborators/${collaborator.id}`)}
                  >
                    Voir détails
                  </Button>
                  <Button
                    variant="outline"
                    size="icon"
                    onClick={() => {
                      setEditingCollaborator(collaborator);
                      setEditDialogOpen(true);
                    }}
                  >
                    <Pencil className="h-4 w-4" />
                  </Button>
                  <Button
                    variant="destructive"
                    size="icon"
                    onClick={() => {
                      setCollaboratorToDelete(collaborator);
                      setDeleteDialogOpen(true);
                    }}
                  >
                    <Trash2 className="h-4 w-4" />
                  </Button>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      {/* Modale d'ajout */}
      <AddCollaboratorDialog
        open={addDialogOpen}
        onClose={() => setAddDialogOpen(false)}
        onSuccess={handleAddSuccess}
        user={user}
      />

      {/* Modale de modification */}
      {editingCollaborator && (
        <EditCollaboratorDialog
          collaborator={editingCollaborator}
          open={editDialogOpen}
          onClose={() => {
            setEditDialogOpen(false);
            setEditingCollaborator(null);
          }}
          onSuccess={handleEditSuccess}
        />
      )}

      {/* Dialogue de suppression */}
      <AlertDialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Supprimer le collaborateur ?</AlertDialogTitle>
            <AlertDialogDescription>
              Cette action est irréversible. La part du collaborateur "{collaboratorToDelete?.name}" 
              ({collaboratorToDelete?.part}%) sera restituée à votre compte.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Annuler</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleDelete}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              Supprimer
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
```

## 🎨 COMPOSANT AddCollaboratorDialog

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

const collaboratorSchema = z.object({
  name: z.string().min(1, "Le nom est requis").max(255, "Le nom ne peut pas dépasser 255 caractères"),
  phone: z.string().min(1, "Le téléphone est requis").max(20, "Le téléphone ne peut pas dépasser 20 caractères"),
  part: z.number().min(0.01, "La part doit être au moins 0.01%").max(99.99, "La part ne peut pas dépasser 99.99%"),
  image: z.string().max(255, "L'URL de l'image ne peut pas dépasser 255 caractères").optional().nullable(),
}).refine(
  (data) => {
    // Cette validation sera faite côté backend aussi
    return true;
  },
  {
    message: "La part ne peut pas dépasser la part disponible",
    path: ["part"],
  }
);

interface AddCollaboratorDialogProps {
  open: boolean;
  onClose: () => void;
  onSuccess: (collaborator: any) => void;
  user: { company_share: number } | null;
}

export const AddCollaboratorDialog: React.FC<AddCollaboratorDialogProps> = ({
  open,
  onClose,
  onSuccess,
  user,
}) => {
  const [loading, setLoading] = useState(false);

  const form = useForm({
    resolver: zodResolver(collaboratorSchema),
    defaultValues: {
      name: '',
      phone: '',
      part: 0.01,
      image: '',
    },
  });

  useEffect(() => {
    if (open) {
      form.reset();
    }
  }, [open, form]);

  const onSubmit = async (data: z.infer<typeof collaboratorSchema>) => {
    // Validation côté client : part ≤ company_share
    if (user && data.part > user.company_share) {
      form.setError('part', {
        message: `La part ne peut pas dépasser ${user.company_share}% (part disponible)`,
      });
      return;
    }

    setLoading(true);
    try {
      const payload: any = {
        name: data.name,
        phone: data.phone,
        part: data.part,
      };

      if (data.image && data.image.trim() !== '') {
        payload.image = data.image;
      } else {
        payload.image = null;
      }

      const response = await api.post('/api/collaborators', payload);

      onSuccess(response.data.data);
      form.reset();
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
          description: "Erreur lors de la création du collaborateur",
          variant: "destructive",
        });
      }
    } finally {
      setLoading(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onClose}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>Ajouter un collaborateur</DialogTitle>
        </DialogHeader>
        <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="name">Nom *</Label>
            <Input
              id="name"
              placeholder="Ex: Jean Dupont"
              {...form.register('name')}
            />
            {form.formState.errors.name && (
              <p className="text-sm text-red-500">{form.formState.errors.name.message}</p>
            )}
          </div>

          <div className="space-y-2">
            <Label htmlFor="phone">Téléphone *</Label>
            <Input
              id="phone"
              placeholder="Ex: +33 6 12 34 56 78"
              {...form.register('phone')}
            />
            {form.formState.errors.phone && (
              <p className="text-sm text-red-500">{form.formState.errors.phone.message}</p>
            )}
          </div>

          <div className="space-y-2">
            <Label htmlFor="part">Part (%) *</Label>
            <Input
              id="part"
              type="number"
              step="0.01"
              min="0.01"
              max={user?.company_share || 99.99}
              {...form.register('part', { valueAsNumber: true })}
            />
            <p className="text-sm text-muted-foreground">
              Part disponible : {user?.company_share.toFixed(2) || '0.00'}%
            </p>
            {form.formState.errors.part && (
              <p className="text-sm text-red-500">{form.formState.errors.part.message}</p>
            )}
          </div>

          <div className="space-y-2">
            <Label htmlFor="image">URL de l'image (optionnel)</Label>
            <Input
              id="image"
              type="url"
              placeholder="https://example.com/image.jpg"
              {...form.register('image')}
            />
            {form.formState.errors.image && (
              <p className="text-sm text-red-500">{form.formState.errors.image.message}</p>
            )}
          </div>

          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={onClose} disabled={loading}>
              Annuler
            </Button>
            <Button type="submit" disabled={loading}>
              {loading ? 'Création...' : 'Créer'}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  );
};
```

## 🎨 COMPOSANT EditCollaboratorDialog

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

const editCollaboratorSchema = z.object({
  name: z.string().min(1, "Le nom est requis").max(255, "Le nom ne peut pas dépasser 255 caractères"),
  phone: z.string().min(1, "Le téléphone est requis").max(20, "Le téléphone ne peut pas dépasser 20 caractères"),
  image: z.string().max(255, "L'URL de l'image ne peut pas dépasser 255 caractères").optional().nullable(),
});

interface EditCollaboratorDialogProps {
  collaborator: Collaborator;
  open: boolean;
  onClose: () => void;
  onSuccess: (collaborator: Collaborator) => void;
}

export const EditCollaboratorDialog: React.FC<EditCollaboratorDialogProps> = ({
  collaborator,
  open,
  onClose,
  onSuccess,
}) => {
  const [loading, setLoading] = useState(false);

  const form = useForm({
    resolver: zodResolver(editCollaboratorSchema),
    defaultValues: {
      name: '',
      phone: '',
      image: '',
    },
  });

  useEffect(() => {
    if (collaborator && open) {
      form.reset({
        name: collaborator.name,
        phone: collaborator.phone,
        image: collaborator.image || '',
      });
    }
  }, [collaborator, open, form]);

  const onSubmit = async (data: z.infer<typeof editCollaboratorSchema>) => {
    setLoading(true);
    try {
      const payload: any = {
        name: data.name,
        phone: data.phone,
      };

      if (data.image && data.image.trim() !== '') {
        payload.image = data.image;
      } else {
        payload.image = null;
      }

      const response = await api.put(`/api/collaborators/${collaborator.id}`, payload);

      toast({
        title: "Succès",
        description: "Collaborateur modifié avec succès",
      });

      onSuccess(response.data.data);
      onClose();
    } catch (error: any) {
      if (error.response?.status === 422) {
        const errors = error.response.data.errors;
        Object.keys(errors).forEach(key => {
          form.setError(key as any, { message: errors[key][0] });
        });
      } else {
        toast({
          title: "Erreur",
          description: error.response?.data?.message || "Erreur lors de la modification",
          variant: "destructive",
        });
      }
    } finally {
      setLoading(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onClose}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>Modifier le collaborateur</DialogTitle>
        </DialogHeader>
        <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="edit-name">Nom *</Label>
            <Input
              id="edit-name"
              {...form.register('name')}
            />
            {form.formState.errors.name && (
              <p className="text-sm text-red-500">{form.formState.errors.name.message}</p>
            )}
          </div>

          <div className="space-y-2">
            <Label htmlFor="edit-phone">Téléphone *</Label>
            <Input
              id="edit-phone"
              {...form.register('phone')}
            />
            {form.formState.errors.phone && (
              <p className="text-sm text-red-500">{form.formState.errors.phone.message}</p>
            )}
          </div>

          {/* Part en lecture seule */}
          <div className="space-y-2">
            <Label>Part (%)</Label>
            <Input
              value={collaborator.part}
              disabled
              className="bg-muted"
            />
            <p className="text-sm text-muted-foreground">
              La part ne peut pas être modifiée après création
            </p>
          </div>

          {/* Wallet en lecture seule */}
          <div className="space-y-2">
            <Label>Wallet</Label>
            <Input
              value={formatCurrency(collaborator.wallet)}
              disabled
              className="bg-muted"
            />
            <p className="text-sm text-muted-foreground">
              Le wallet est calculé automatiquement
            </p>
          </div>

          <div className="space-y-2">
            <Label htmlFor="edit-image">URL de l'image (optionnel)</Label>
            <Input
              id="edit-image"
              type="url"
              placeholder="https://example.com/image.jpg"
              {...form.register('image')}
            />
            {collaborator.image && (
              <img
                src={collaborator.image}
                alt={collaborator.name}
                className="w-32 h-32 object-cover rounded border mt-2"
                onError={(e) => {
                  (e.target as HTMLImageElement).style.display = 'none';
                }}
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

## 🎨 COMPOSANT CollaboratorDetailPage

```typescript
'use client';

import { useState, useEffect } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { toast } from '@/hooks/use-toast';
import api from '@/lib/api';
import { formatCurrency } from '@/lib/utils/currency';
import { ArrowLeft, Pencil, Trash2, User } from 'lucide-react';
import { EditCollaboratorDialog } from '@/components/collaborators/EditCollaboratorDialog';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import dayjs from 'dayjs';

export default function CollaboratorDetailPage() {
  const params = useParams();
  const router = useRouter();
  const collaboratorId = params.id as string;
  
  const [collaborator, setCollaborator] = useState<Collaborator | null>(null);
  const [loading, setLoading] = useState(true);
  const [editDialogOpen, setEditDialogOpen] = useState(false);
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);

  useEffect(() => {
    if (collaboratorId) {
      fetchCollaborator();
    }
  }, [collaboratorId]);

  const fetchCollaborator = async () => {
    try {
      const response = await api.get(`/api/collaborators/${collaboratorId}`);
      setCollaborator(response.data.data);
    } catch (error: any) {
      if (error.response?.status === 404) {
        toast({
          title: "Erreur",
          description: "Collaborateur non trouvé",
          variant: "destructive",
        });
        router.push('/collaborators');
      } else {
        toast({
          title: "Erreur",
          description: "Erreur lors du chargement du collaborateur",
          variant: "destructive",
        });
      }
    } finally {
      setLoading(false);
    }
  };

  const handleEditSuccess = (updatedCollaborator: Collaborator) => {
    setCollaborator(updatedCollaborator);
    toast({
      title: "Succès",
      description: "Collaborateur modifié avec succès",
    });
  };

  const handleDelete = async () => {
    if (!collaborator) return;

    try {
      await api.delete(`/api/collaborators/${collaborator.id}`);
      toast({
        title: "Succès",
        description: "Collaborateur supprimé avec succès",
      });
      router.push('/collaborators');
    } catch (error: any) {
      toast({
        title: "Erreur",
        description: error.response?.data?.message || "Erreur lors de la suppression",
        variant: "destructive",
      });
    }
  };

  if (loading) {
    return (
      <div className="container mx-auto p-6">
        <Skeleton className="h-8 w-64 mb-6" />
        <Card>
          <CardContent className="p-6">
            <Skeleton className="h-64 w-full" />
          </CardContent>
        </Card>
      </div>
    );
  }

  if (!collaborator) {
    return (
      <div className="container mx-auto p-6">
        <p>Collaborateur non trouvé</p>
      </div>
    );
  }

  return (
    <div className="container mx-auto p-6 space-y-6">
      {/* Bouton retour */}
      <Button
        variant="ghost"
        onClick={() => router.push('/collaborators')}
        className="gap-2"
      >
        <ArrowLeft className="h-4 w-4" />
        Retour à la liste
      </Button>

      {/* En-tête avec actions */}
      <div className="flex items-center justify-between">
        <h1 className="text-3xl font-bold">Détails du collaborateur</h1>
        <div className="flex gap-2">
          <Button
            variant="outline"
            onClick={() => setEditDialogOpen(true)}
            className="gap-2"
          >
            <Pencil className="h-4 w-4" />
            Modifier
          </Button>
          <Button
            variant="destructive"
            onClick={() => setDeleteDialogOpen(true)}
            className="gap-2"
          >
            <Trash2 className="h-4 w-4" />
            Supprimer
          </Button>
        </div>
      </div>

      {/* Informations du collaborateur */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        <Card>
          <CardHeader>
            <CardTitle>Informations personnelles</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex justify-center">
              {collaborator.image ? (
                <img
                  src={collaborator.image}
                  alt={collaborator.name}
                  className="w-32 h-32 rounded-full object-cover border-2"
                  onError={(e) => {
                    (e.target as HTMLImageElement).style.display = 'none';
                    (e.target as HTMLImageElement).nextElementSibling?.classList.remove('hidden');
                  }}
                />
              ) : null}
              <div className={`w-32 h-32 rounded-full bg-muted flex items-center justify-center ${collaborator.image ? 'hidden' : ''}`}>
                <User className="h-16 w-16 text-muted-foreground" />
              </div>
            </div>
            <div className="space-y-2 text-center">
              <div>
                <p className="text-sm text-muted-foreground">Nom</p>
                <p className="text-lg font-semibold">{collaborator.name}</p>
              </div>
              <div>
                <p className="text-sm text-muted-foreground">Téléphone</p>
                <p className="text-lg">{collaborator.phone}</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Informations financières</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <div>
              <p className="text-sm text-muted-foreground">Part</p>
              <p className="text-2xl font-bold">{collaborator.part}%</p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground">Wallet</p>
              <p className="text-2xl font-bold text-primary">
                {formatCurrency(collaborator.wallet)}
              </p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground">Date de création</p>
              <p className="text-sm">
                {dayjs(collaborator.created_at).format('DD/MM/YYYY à HH:mm')}
              </p>
            </div>
          </CardContent>
        </Card>
      </div>

      {/* Modale de modification */}
      <EditCollaboratorDialog
        collaborator={collaborator}
        open={editDialogOpen}
        onClose={() => setEditDialogOpen(false)}
        onSuccess={handleEditSuccess}
      />

      {/* Dialogue de suppression */}
      <AlertDialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Supprimer le collaborateur ?</AlertDialogTitle>
            <AlertDialogDescription>
              Cette action est irréversible. La part du collaborateur "{collaborator.name}" 
              ({collaborator.part}%) sera restituée à votre compte.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Annuler</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleDelete}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              Supprimer
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
```

## ✅ CHECKLIST

- [ ] Créer la page `/collaborators` avec liste en cards
- [ ] Bouton "Ajouter un collaborateur" qui ouvre une modale
- [ ] Modale `AddCollaboratorDialog` avec validation
- [ ] Modale `EditCollaboratorDialog` pour modifier (name, phone, image)
- [ ] Dialogue de confirmation pour la suppression
- [ ] Page de détail `/collaborators/[id]`
- [ ] Redirection vers la page de détail au clic sur "Voir détails"
- [ ] Affichage de la part disponible lors de l'ajout
- [ ] Validation : part ≤ company_share disponible
- [ ] Rechargement de `company_share` après ajout/suppression
- [ ] Gestion des erreurs (422, 400, 404, 403)
- [ ] Toast de succès/erreur
- [ ] Skeleton loaders pendant le chargement
- [ ] Affichage du wallet calculé pour chaque collaborateur
- [ ] Code typé TypeScript sans erreurs

## 🎯 RÉSULTAT ATTENDU

- La page `/collaborators` affiche la liste des collaborateurs en cards
- Chaque card affiche : image, nom, téléphone, part, wallet
- Bouton "Ajouter" ouvre une modale avec formulaire
- Bouton "Modifier" ouvre une modale (seulement name, phone, image modifiables)
- Bouton "Supprimer" ouvre un dialogue de confirmation
- Clic sur "Voir détails" redirige vers `/collaborators/{id}`
- La page de détail affiche toutes les informations du collaborateur
- Les actions mettent à jour la liste en temps réel
- La part disponible est mise à jour après ajout/suppression

Créez tous les composants nécessaires avec cette logique.
```

---

## 📝 NOTES IMPORTANTES

1. **Part non modifiable** : La `part` d'un collaborateur ne peut pas être modifiée après création. Elle est affichée en lecture seule dans la modale de modification.

2. **Wallet calculé** : Le `wallet` est calculé automatiquement par le backend et ne peut pas être modifié. Il est affiché en lecture seule.

3. **Part disponible** : Lors de l'ajout, vérifier que `part ≤ user.company_share`. La part disponible est décrémentée lors de l'ajout et récupérée lors de la suppression.

4. **Validation** : Le backend valide que la part ne dépasse pas `company_share`. Afficher un message d'erreur clair si cette condition n'est pas respectée.

5. **Rechargement** : Après ajout ou suppression, recharger les informations de l'utilisateur pour mettre à jour `company_share`.

6. **Navigation** : Utiliser `useRouter` de Next.js pour la navigation vers la page de détail.

7. **Formatage** : Utiliser `formatCurrency` pour le wallet et `dayjs` pour les dates.

