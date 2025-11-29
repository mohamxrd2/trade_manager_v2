# 📋 PROMPT DÉTAILLÉ - SUPPRESSION D'ARTICLE

## 🚀 Copiez ce prompt dans Cursor :

```
Je dois implémenter la fonctionnalité de suppression d'un article dans ma page de détail de produit Next.js.

## 🔗 CONFIGURATION API

**Endpoint** : `DELETE http://localhost:8000/api/articles/{id}`

**Authentification** : Cookies HTTP-only (withCredentials: true)
- Utiliser l'instance axios configurée dans `lib/api.ts`
- Le cookie CSRF est géré automatiquement par l'intercepteur

**Réponse succès (200)** :
```typescript
{
  success: true;
  message: "Article supprimé avec succès";
}
```

**Réponses erreur** :
- **404** : Article non trouvé
- **403** : Article non autorisé (n'appartient pas à l'utilisateur)
- **500** : Erreur serveur

## 📋 FONCTIONNALITÉ REQUISE

### 1. Bouton de suppression

**Emplacement** : Dans la page de détail de l'article (ArticleDetailPage)

**Composant** : Utiliser un `Button` de shadcn/ui avec variant "destructive"

**Texte** : "Supprimer l'article" ou "Supprimer"

**Icône** : Optionnel, utiliser `Trash2` de lucide-react

### 2. Dialogue de confirmation

**Composant** : Utiliser `AlertDialog` de shadcn/ui

**Contenu du dialogue** :
- **Titre** : "Supprimer l'article ?"
- **Description** : "Cette action est irréversible. L'article et toutes ses données associées seront définitivement supprimés."
- **Boutons** :
  - **Annuler** : Ferme le dialogue sans action
  - **Supprimer** : Confirme la suppression (variant "destructive")

### 3. Logique de suppression

**Étapes** :
1. Afficher le dialogue de confirmation au clic sur "Supprimer"
2. Si l'utilisateur confirme :
   - Afficher un état de chargement (désactiver le bouton)
   - Appeler `DELETE /api/articles/{id}`
   - Afficher un toast de succès
   - Rediriger vers la page de liste des produits (`/products` ou `/articles`)
3. Si erreur :
   - Afficher un toast d'erreur avec le message
   - Ne pas rediriger

## 🎨 EXEMPLE DE CODE

```typescript
'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { Button } from '@/components/ui/button';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { toast } from '@/hooks/use-toast';
import api from '@/lib/api';
import { Trash2 } from 'lucide-react';

interface ArticleDetailPageProps {
  articleId: string;
}

export default function ArticleDetailPage({ articleId }: ArticleDetailPageProps) {
  const router = useRouter();
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
  const [deleting, setDeleting] = useState(false);

  const handleDeleteArticle = async () => {
    setDeleting(true);
    try {
      await api.delete(`/api/articles/${articleId}`);
      
      toast({
        title: "Succès",
        description: "Article supprimé avec succès",
      });
      
      // Rediriger vers la liste des produits
      router.push('/products'); // ou '/articles' selon votre route
    } catch (error: any) {
      if (error.response?.status === 404) {
        toast({
          title: "Erreur",
          description: "Article non trouvé",
          variant: "destructive",
        });
      } else if (error.response?.status === 403) {
        toast({
          title: "Erreur",
          description: "Vous n'êtes pas autorisé à supprimer cet article",
          variant: "destructive",
        });
      } else {
        toast({
          title: "Erreur",
          description: error.response?.data?.message || "Erreur lors de la suppression de l'article",
          variant: "destructive",
        });
      }
    } finally {
      setDeleting(false);
      setDeleteDialogOpen(false);
    }
  };

  return (
    <div className="container mx-auto p-6">
      {/* ... autres contenus de la page ... */}
      
      {/* Bouton de suppression */}
      <div className="mt-6 flex justify-end">
        <AlertDialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
          <AlertDialogTrigger asChild>
            <Button variant="destructive" className="gap-2">
              <Trash2 className="h-4 w-4" />
              Supprimer l'article
            </Button>
          </AlertDialogTrigger>
          <AlertDialogContent>
            <AlertDialogHeader>
              <AlertDialogTitle>Supprimer l'article ?</AlertDialogTitle>
              <AlertDialogDescription>
                Cette action est irréversible. L'article et toutes ses données associées 
                (variations, transactions, etc.) seront définitivement supprimés.
              </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
              <AlertDialogCancel disabled={deleting}>Annuler</AlertDialogCancel>
              <AlertDialogAction
                onClick={handleDeleteArticle}
                disabled={deleting}
                className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              >
                {deleting ? 'Suppression...' : 'Supprimer'}
              </AlertDialogAction>
            </AlertDialogFooter>
          </AlertDialogContent>
        </AlertDialog>
      </div>
    </div>
  );
}
```

## 🔧 ALTERNATIVE : Intégration dans un composant existant

Si vous avez déjà une page de détail, ajoutez simplement :

```typescript
// Dans votre composant ArticleDetailPage existant

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { Button } from '@/components/ui/button';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { toast } from '@/hooks/use-toast';
import api from '@/lib/api';
import { Trash2 } from 'lucide-react';

// Dans votre composant
const router = useRouter();
const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
const [deleting, setDeleting] = useState(false);

const handleDeleteArticle = async () => {
  setDeleting(true);
  try {
    await api.delete(`/api/articles/${articleId}`);
    
    toast({
      title: "Succès",
      description: "Article supprimé avec succès",
    });
    
    router.push('/products'); // Ajustez selon votre route
  } catch (error: any) {
    toast({
      title: "Erreur",
      description: error.response?.data?.message || "Erreur lors de la suppression",
      variant: "destructive",
    });
  } finally {
    setDeleting(false);
    setDeleteDialogOpen(false);
  }
};

// Dans le JSX, ajoutez le bouton avec le dialogue :
<AlertDialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
  <AlertDialogTrigger asChild>
    <Button variant="destructive" className="gap-2">
      <Trash2 className="h-4 w-4" />
      Supprimer l'article
    </Button>
  </AlertDialogTrigger>
  <AlertDialogContent>
    <AlertDialogHeader>
      <AlertDialogTitle>Supprimer l'article ?</AlertDialogTitle>
      <AlertDialogDescription>
        Cette action est irréversible. L'article et toutes ses données associées 
        seront définitivement supprimés.
      </AlertDialogDescription>
    </AlertDialogHeader>
    <AlertDialogFooter>
      <AlertDialogCancel disabled={deleting}>Annuler</AlertDialogCancel>
      <AlertDialogAction
        onClick={handleDeleteArticle}
        disabled={deleting}
        className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
      >
        {deleting ? 'Suppression...' : 'Supprimer'}
      </AlertDialogAction>
    </AlertDialogFooter>
  </AlertDialogContent>
</AlertDialog>
```

## ✅ CHECKLIST

- [ ] Installer AlertDialog de shadcn/ui si nécessaire (`npx shadcn-ui@latest add alert-dialog`)
- [ ] Ajouter le bouton "Supprimer l'article" dans la page de détail
- [ ] Créer le dialogue de confirmation avec AlertDialog
- [ ] Implémenter la fonction `handleDeleteArticle`
- [ ] Gérer l'état de chargement (`deleting`)
- [ ] Afficher les toasts de succès/erreur
- [ ] Rediriger vers `/products` (ou votre route de liste) après suppression réussie
- [ ] Gérer les erreurs (404, 403, 500)
- [ ] Désactiver les boutons pendant la suppression

## 🎯 RÉSULTAT ATTENDU

- Un bouton "Supprimer l'article" est visible dans la page de détail
- Au clic, un dialogue de confirmation s'affiche
- Si l'utilisateur confirme, l'article est supprimé via l'API
- Un toast de succès s'affiche
- L'utilisateur est redirigé vers la page de liste des produits
- En cas d'erreur, un toast d'erreur s'affiche et l'utilisateur reste sur la page

## 📝 NOTES IMPORTANTES

1. **Route de redirection** : Ajustez `router.push('/products')` selon votre structure de routes (peut être `/articles`, `/dashboard/products`, etc.)

2. **Suppression en cascade** : Le backend supprime automatiquement les variations et transactions associées grâce aux contraintes de clé étrangère.

3. **Sécurité** : L'API vérifie que l'article appartient à l'utilisateur connecté avant de le supprimer.

4. **UX** : Le dialogue de confirmation est important car la suppression est irréversible.

5. **État de chargement** : Désactivez les boutons pendant la suppression pour éviter les doubles clics.

Créez ou modifiez la page de détail de produit pour inclure cette fonctionnalité de suppression.
```

---

## 📝 NOTES SUPPLÉMENTAIRES

1. **Composant AlertDialog** : Si vous ne l'avez pas encore, installez-le avec :
   ```bash
   npx shadcn-ui@latest add alert-dialog
   ```

2. **Icône Trash2** : Installer lucide-react si nécessaire :
   ```bash
   npm install lucide-react
   ```

3. **Route de redirection** : Vérifiez votre structure de routes et ajustez `router.push('/products')` en conséquence.

4. **Gestion des erreurs** : Le code gère les erreurs 404, 403 et autres, avec des messages appropriés.

