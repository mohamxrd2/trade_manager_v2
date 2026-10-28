<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Nombre maximal de membres (comptes à accès) par compte entreprise
    |--------------------------------------------------------------------------
    |
    | Compte uniquement les personnes avec ACCÈS (users.account_id défini) —
    | un associé sans accès ne consomme pas ce quota. Servira de base aux
    | futures offres payantes (plus de membres = palier supérieur).
    |
    */

    'max_members' => (int) env('TEAM_MAX_MEMBERS', 10),

];
