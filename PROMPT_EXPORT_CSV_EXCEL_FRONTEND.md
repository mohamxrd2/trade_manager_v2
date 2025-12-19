# 📋 PROMPT POUR IMPLÉMENTER L'EXPORT CSV/EXCEL

## 🚀 Copiez ce prompt dans Cursor :

```
Je veux implémenter les fonctionnalités d'export CSV et Excel pour les transactions et les produits dans la page Settings. Les boutons existent déjà mais ne sont pas fonctionnels.

## 🎯 OBJECTIFS

1. Exporter les transactions en CSV
2. Exporter les transactions en Excel
3. Exporter les produits en CSV
4. Exporter les produits en Excel
5. Télécharger les fichiers générés

## 🔧 IMPLÉMENTATION

### 1. Installer les dépendances nécessaires

```bash
npm install xlsx file-saver
# ou
yarn add xlsx file-saver
```

### 2. Créer un fichier utilitaire pour les exports

Créez `lib/utils/export.ts` :

```typescript
import * as XLSX from 'xlsx';
import { saveAs } from 'file-saver';
import api from '@/lib/api';

/**
 * Exporte des données en CSV
 */
export function exportToCSV(data: any[], filename: string) {
  if (!data || data.length === 0) {
    throw new Error('Aucune donnée à exporter');
  }

  // Obtenir les en-têtes depuis les clés du premier objet
  const headers = Object.keys(data[0]);
  
  // Créer les lignes CSV
  const csvRows = [
    // En-têtes
    headers.join(','),
    // Données
    ...data.map(row => 
      headers.map(header => {
        const value = row[header];
        // Gérer les valeurs qui contiennent des virgules ou des guillemets
        if (value === null || value === undefined) {
          return '';
        }
        const stringValue = String(value);
        if (stringValue.includes(',') || stringValue.includes('"') || stringValue.includes('\n')) {
          return `"${stringValue.replace(/"/g, '""')}"`;
        }
        return stringValue;
      }).join(',')
    )
  ];

  // Créer le contenu CSV
  const csvContent = csvRows.join('\n');
  
  // Créer le blob et télécharger
  const blob = new Blob(['\ufeff' + csvContent], { type: 'text/csv;charset=utf-8;' }); // BOM pour Excel
  saveAs(blob, `${filename}.csv`);
}

/**
 * Exporte des données en Excel
 */
export function exportToExcel(data: any[], filename: string, sheetName: string = 'Sheet1') {
  if (!data || data.length === 0) {
    throw new Error('Aucune donnée à exporter');
  }

  // Créer un nouveau workbook
  const workbook = XLSX.utils.book_new();
  
  // Convertir les données en worksheet
  const worksheet = XLSX.utils.json_to_sheet(data);
  
  // Ajouter le worksheet au workbook
  XLSX.utils.book_append_sheet(workbook, worksheet, sheetName);
  
  // Générer le fichier Excel et télécharger
  const excelBuffer = XLSX.write(workbook, { bookType: 'xlsx', type: 'array' });
  const blob = new Blob([excelBuffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
  saveAs(blob, `${filename}.xlsx`);
}

/**
 * Formate les transactions pour l'export
 */
export function formatTransactionsForExport(transactions: any[], currency: string = 'FCFA'): any[] {
  return transactions.map(transaction => ({
    'ID': transaction.id,
    'Type': transaction.type === 'sale' ? 'Vente' : 'Dépense',
    'Nom': transaction.name || transaction.article?.name || '-',
    'Article': transaction.article?.name || '-',
    'Variation': transaction.variation?.name || '-',
    'Quantité': transaction.quantity || 0,
    'Prix unitaire': transaction.sale_price || transaction.amount / (transaction.quantity || 1),
    'Montant': formatCurrency(transaction.amount, currency),
    'Date': new Date(transaction.created_at).toLocaleDateString('fr-FR', {
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit'
    }),
    'Créé le': new Date(transaction.created_at).toLocaleString('fr-FR'),
  }));
}

/**
 * Formate les produits pour l'export
 */
export function formatProductsForExport(products: any[], currency: string = 'FCFA'): any[] {
  return products.map(product => ({
    'ID': product.id,
    'Nom': product.name,
    'Type': product.type === 'simple' ? 'Simple' : 'Variable',
    'Prix de vente': formatCurrency(product.sale_price, currency),
    'Quantité initiale': product.quantity,
    'Quantité vendue': product.sold_quantity || 0,
    'Quantité restante': product.remaining_quantity || 0,
    'Pourcentage vendu': `${product.sales_percentage || 0}%`,
    'Stock faible': product.low_stock ? 'Oui' : 'Non',
    'Valeur du stock': formatCurrency(product.stock_value || 0, currency),
    'Image': product.image || '-',
    'Créé le': new Date(product.created_at).toLocaleString('fr-FR'),
    'Modifié le': new Date(product.updated_at).toLocaleString('fr-FR'),
  }));
}

/**
 * Formate un montant avec la devise
 */
function formatCurrency(amount: number | string, currency: string): string {
  const numAmount = typeof amount === 'string' ? parseFloat(amount) : amount;
  
  if (isNaN(numAmount)) {
    return '0';
  }

  const formattedAmount = new Intl.NumberFormat('fr-FR', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  }).format(numAmount);

  switch (currency) {
    case 'EUR':
      return `${formattedAmount} €`;
    case 'USD':
      return `$${formattedAmount}`;
    case 'XOF':
      return `${formattedAmount} XOF`;
    case 'FCFA':
    default:
      return `${formattedAmount} FCFA`;
  }
}
```

### 3. Modifier la page Settings pour implémenter les exports

Dans `app/settings/page.tsx` ou votre composant Settings :

```typescript
'use client';

import { useState } from 'react';
import { useSettings } from '@/contexts/SettingsContext';
import { useToast } from '@/hooks/use-toast';
import { useTranslation } from '@/lib/i18n/hooks/useTranslation';
import api from '@/lib/api';
import { 
  exportToCSV, 
  exportToExcel, 
  formatTransactionsForExport, 
  formatProductsForExport 
} from '@/lib/utils/export';
import { Button } from '@/components/ui/button';
import { Download, Loader2 } from 'lucide-react';

export default function SettingsPage() {
  const { t } = useTranslation();
  const { settings } = useSettings();
  const { toast } = useToast();
  const [isExporting, setIsExporting] = useState<string | null>(null);

  /**
   * Récupère toutes les transactions depuis l'API
   */
  const fetchAllTransactions = async (): Promise<any[]> => {
    let allTransactions: any[] = [];
    let page = 1;
    let hasMore = true;

    while (hasMore) {
      try {
        const response = await api.get('/api/transactions', {
          params: { page, per_page: 100 } // Récupérer 100 transactions par page
        });

        if (response.data.success && response.data.data?.transactions) {
          const transactions = response.data.data.transactions;
          allTransactions = [...allTransactions, ...transactions];
          
          // Vérifier s'il y a plus de pages
          const total = response.data.data.total || 0;
          hasMore = allTransactions.length < total;
          page++;
        } else {
          hasMore = false;
        }
      } catch (error) {
        console.error('Erreur lors de la récupération des transactions:', error);
        hasMore = false;
      }
    }

    return allTransactions;
  };

  /**
   * Récupère tous les produits depuis l'API
   */
  const fetchAllProducts = async (): Promise<any[]> => {
    try {
      const response = await api.get('/api/articles');
      
      if (response.data.success && response.data.data) {
        return response.data.data;
      }
      
      return [];
    } catch (error) {
      console.error('Erreur lors de la récupération des produits:', error);
      throw error;
    }
  };

  /**
   * Exporte les transactions en CSV
   */
  const handleExportTransactionsCSV = async () => {
    setIsExporting('transactions-csv');
    try {
      toast({
        title: 'Export en cours...',
        description: 'Récupération des transactions',
      });

      // Récupérer toutes les transactions
      const transactions = await fetchAllTransactions();

      if (transactions.length === 0) {
        toast({
          title: 'Aucune donnée',
          description: 'Aucune transaction à exporter',
          variant: 'destructive',
        });
        return;
      }

      // Formater les données
      const formattedData = formatTransactionsForExport(
        transactions, 
        settings?.currency || 'FCFA'
      );

      // Exporter en CSV
      exportToCSV(formattedData, `transactions_${new Date().toISOString().split('T')[0]}`);

      toast({
        title: 'Export réussi',
        description: `${transactions.length} transaction(s) exportée(s) en CSV`,
      });
    } catch (error: any) {
      toast({
        title: 'Erreur',
        description: error.message || 'Impossible d\'exporter les transactions',
        variant: 'destructive',
      });
    } finally {
      setIsExporting(null);
    }
  };

  /**
   * Exporte les transactions en Excel
   */
  const handleExportTransactionsExcel = async () => {
    setIsExporting('transactions-excel');
    try {
      toast({
        title: 'Export en cours...',
        description: 'Récupération des transactions',
      });

      // Récupérer toutes les transactions
      const transactions = await fetchAllTransactions();

      if (transactions.length === 0) {
        toast({
          title: 'Aucune donnée',
          description: 'Aucune transaction à exporter',
          variant: 'destructive',
        });
        return;
      }

      // Formater les données
      const formattedData = formatTransactionsForExport(
        transactions, 
        settings?.currency || 'FCFA'
      );

      // Exporter en Excel
      exportToExcel(
        formattedData, 
        `transactions_${new Date().toISOString().split('T')[0]}`,
        'Transactions'
      );

      toast({
        title: 'Export réussi',
        description: `${transactions.length} transaction(s) exportée(s) en Excel`,
      });
    } catch (error: any) {
      toast({
        title: 'Erreur',
        description: error.message || 'Impossible d\'exporter les transactions',
        variant: 'destructive',
      });
    } finally {
      setIsExporting(null);
    }
  };

  /**
   * Exporte les produits en CSV
   */
  const handleExportProductsCSV = async () => {
    setIsExporting('products-csv');
    try {
      toast({
        title: 'Export en cours...',
        description: 'Récupération des produits',
      });

      // Récupérer tous les produits
      const products = await fetchAllProducts();

      if (products.length === 0) {
        toast({
          title: 'Aucune donnée',
          description: 'Aucun produit à exporter',
          variant: 'destructive',
        });
        return;
      }

      // Formater les données
      const formattedData = formatProductsForExport(
        products, 
        settings?.currency || 'FCFA'
      );

      // Exporter en CSV
      exportToCSV(formattedData, `produits_${new Date().toISOString().split('T')[0]}`);

      toast({
        title: 'Export réussi',
        description: `${products.length} produit(s) exporté(s) en CSV`,
      });
    } catch (error: any) {
      toast({
        title: 'Erreur',
        description: error.message || 'Impossible d\'exporter les produits',
        variant: 'destructive',
      });
    } finally {
      setIsExporting(null);
    }
  };

  /**
   * Exporte les produits en Excel
   */
  const handleExportProductsExcel = async () => {
    setIsExporting('products-excel');
    try {
      toast({
        title: 'Export en cours...',
        description: 'Récupération des produits',
      });

      // Récupérer tous les produits
      const products = await fetchAllProducts();

      if (products.length === 0) {
        toast({
          title: 'Aucune donnée',
          description: 'Aucun produit à exporter',
          variant: 'destructive',
        });
        return;
      }

      // Formater les données
      const formattedData = formatProductsForExport(
        products, 
        settings?.currency || 'FCFA'
      );

      // Exporter en Excel
      exportToExcel(
        formattedData, 
        `produits_${new Date().toISOString().split('T')[0]}`,
        'Produits'
      );

      toast({
        title: 'Export réussi',
        description: `${products.length} produit(s) exporté(s) en Excel`,
      });
    } catch (error: any) {
      toast({
        title: 'Erreur',
        description: error.message || 'Impossible d\'exporter les produits',
        variant: 'destructive',
      });
    } finally {
      setIsExporting(null);
    }
  };

  return (
    <div className="container mx-auto py-6 space-y-6">
      {/* ... autres sections ... */}

      {/* Section Export */}
      <Card>
        <CardHeader>
          <CardTitle>{t('settings.export.title')}</CardTitle>
          <CardDescription>
            {t('settings.export.description')}
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="space-y-2">
            <Label>{t('settings.export.exportTransactions')}</Label>
            <div className="flex flex-wrap gap-2">
              <Button
                variant="outline"
                onClick={handleExportTransactionsCSV}
                disabled={isExporting === 'transactions-csv'}
              >
                {isExporting === 'transactions-csv' ? (
                  <>
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    Export...
                  </>
                ) : (
                  <>
                    <Download className="mr-2 h-4 w-4" />
                    {t('settings.export.exportCSV')}
                  </>
                )}
              </Button>
              <Button
                variant="outline"
                onClick={handleExportTransactionsExcel}
                disabled={isExporting === 'transactions-excel'}
              >
                {isExporting === 'transactions-excel' ? (
                  <>
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    Export...
                  </>
                ) : (
                  <>
                    <Download className="mr-2 h-4 w-4" />
                    {t('settings.export.exportExcel')}
                  </>
                )}
              </Button>
            </div>
          </div>

          <Separator />

          <div className="space-y-2">
            <Label>{t('settings.export.exportProducts')}</Label>
            <div className="flex flex-wrap gap-2">
              <Button
                variant="outline"
                onClick={handleExportProductsCSV}
                disabled={isExporting === 'products-csv'}
              >
                {isExporting === 'products-csv' ? (
                  <>
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    Export...
                  </>
                ) : (
                  <>
                    <Download className="mr-2 h-4 w-4" />
                    {t('settings.export.exportCSV')}
                  </>
                )}
              </Button>
              <Button
                variant="outline"
                onClick={handleExportProductsExcel}
                disabled={isExporting === 'products-excel'}
              >
                {isExporting === 'products-excel' ? (
                  <>
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    Export...
                  </>
                ) : (
                  <>
                    <Download className="mr-2 h-4 w-4" />
                    {t('settings.export.exportExcel')}
                  </>
                )}
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* ... autres sections ... */}
    </div>
  );
}
```

### 4. Vérifier que l'API transactions supporte la pagination

Assurez-vous que votre API `/api/transactions` supporte les paramètres `page` et `per_page`. Si ce n'est pas le cas, modifiez le `TransactionController` :

```typescript
// Dans TransactionController.php
public function index(Request $request): JsonResponse
{
    try {
        $perPage = $request->get('per_page', 15);
        $page = $request->get('page', 1);

        $transactions = Transaction::where('user_id', Auth::id())
            ->with(['article', 'variation'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'success' => true,
            'message' => 'Transactions récupérées avec succès',
            'data' => [
                'transactions' => $transactions->items(),
                'total' => $transactions->total(),
                'per_page' => $transactions->perPage(),
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
            ]
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Erreur lors de la récupération des transactions',
            'error' => $e->getMessage()
        ], 500);
    }
}
```

## ✅ CHECKLIST D'IMPLÉMENTATION

- [ ] Installer les dépendances `xlsx` et `file-saver`
- [ ] Créer le fichier `lib/utils/export.ts` avec les fonctions d'export
- [ ] Implémenter les fonctions `handleExportTransactionsCSV` et `handleExportTransactionsExcel`
- [ ] Implémenter les fonctions `handleExportProductsCSV` et `handleExportProductsExcel`
- [ ] Ajouter les états de chargement pour chaque bouton
- [ ] Ajouter les toasts pour les succès et erreurs
- [ ] Vérifier que l'API transactions supporte la pagination
- [ ] Tester l'export avec des données réelles

## 📝 NOTES IMPORTANTES

1. **Pagination** : Les transactions peuvent être nombreuses, donc on récupère toutes les pages
2. **Formatage** : Les données sont formatées avec des noms de colonnes en français
3. **Devise** : La devise de l'utilisateur est utilisée pour formater les montants
4. **Dates** : Les dates sont formatées en français
5. **CSV** : Le BOM UTF-8 est ajouté pour que Excel ouvre correctement le fichier
6. **Excel** : Utilise la bibliothèque `xlsx` pour générer les fichiers .xlsx

## 🎨 AMÉLIORATIONS POSSIBLES

1. **Filtres d'export** : Permettre de filtrer les transactions/produits avant l'export
2. **Export partiel** : Permettre d'exporter seulement certaines colonnes
3. **Compression** : Compresser les fichiers Excel pour les grandes quantités de données
4. **Progression** : Afficher une barre de progression pour les exports volumineux

Implémentez ces fonctionnalités pour rendre les exports fonctionnels.
```

---

## 📝 NOTES TECHNIQUES

1. **Dépendances** : `xlsx` pour Excel, `file-saver` pour le téléchargement
2. **Pagination** : Récupérer toutes les pages de transactions si nécessaire
3. **Formatage** : Formater les données avec des colonnes en français et la devise de l'utilisateur
4. **CSV** : Ajouter le BOM UTF-8 pour Excel
5. **Excel** : Utiliser `XLSX.utils.json_to_sheet()` pour créer les feuilles

