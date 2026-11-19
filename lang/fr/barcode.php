<?php

return [
    'empty' => 'Le code-barre est vide.',
    'check_digit' => 'Ce code-barre est invalide : le dernier chiffre (clé de contrôle) ne correspond pas. Vérifiez la saisie.',
    'format' => 'Code-barre non reconnu. Utilisez un EAN-13, EAN-8, UPC-A, ou un code de 4 à 32 caractères (lettres, chiffres, - . /).',
    'taken' => 'Ce code-barre est déjà utilisé par « :product ».',
    'duplicate' => 'Le code-barre :code est saisi deux fois.',
    'variable_article' => 'Un produit à variantes n\'a pas de code-barre propre : renseignez celui de chaque variante.',
    'already_set' => 'Ce produit a déjà un code-barre : il n\'est jamais remplacé automatiquement.',
    'not_found' => 'Aucun produit ne porte ce code-barre.',
    'exhausted' => 'Impossible de générer un code-barre pour le moment. Réessayez.',
    'generated' => '{0} Aucun code-barre à créer.|{1} 1 code-barre créé.|[2,*] :count codes-barres créés.',
    'item_failed' => 'Erreur inattendue lors de l\'enregistrement du code.',
    'generated_failed' => '{1} 1 produit n\'a pas pu recevoir de code.|[2,*] :count produits n\'ont pas pu recevoir de code.',
    'generate_failed' => 'Les codes-barres n\'ont pas pu être générés (erreur du serveur). Réessayez ; si cela continue, contactez-nous.',
    'lookup_failed' => 'La recherche du code a échoué (erreur du serveur). Réessayez.',
];
