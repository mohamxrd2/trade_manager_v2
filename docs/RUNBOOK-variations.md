# RUNBOOK — Déploiement de l'overhaul « articles à variations »

Ce document couvre le déploiement en PRODUCTION du nouveau modèle (un
article variable n'a plus de quantité/prix propres ; chaque variation porte
les siens ; `articles.quantity`/`sale_price` deviennent un cache calculé).
Il suppose que la répétition complète sur STAGING (copie de prod restaurée)
a déjà été faite avec succès, --dry-run validé, --force exécuté sur
staging, 0 écart confirmé, et tests manuels passés.

**Ne rien exécuter de cette page sur la production sans l'accord explicite
du propriétaire du projet, à chaque étape.**

---

## 1. Prérequis avant toute intervention

### 1.1 Tag git de la version actuelle (avant tout changement)

```bash
# Backend (branche déployée en production — vérifier laquelle avant de taguer)
cd back-end-api/trade_manager_v2
git tag -a pre-variations-$(date +%Y%m%d) -m "État avant déploiement overhaul variations"
git push origin pre-variations-$(date +%Y%m%d)

# Frontend
cd front-web/front_trade_manager
git tag -a pre-variations-$(date +%Y%m%d) -m "État avant déploiement overhaul variations"
git push origin pre-variations-$(date +%Y%m%d)
```
Ce tag est ce qu'on redéploie en cas de retour arrière avant mise en ligne (§4a).

### 1.2 pg_dump de production, vérifié

```bash
pg_dump --no-owner --format=custom --dbname="$DATABASE_URL_PRODUCTION" \
  --file="prod_$(date +%Y%m%d_%H%M%S).dump"

# Vérification OBLIGATOIRE avant de considérer ce dump utilisable : lit le
# sommaire du dump sans rien restaurer, confirme qu'il n'est pas tronqué/corrompu.
pg_restore --list prod_YYYYMMDD_HHMMSS.dump | head -50
# Attendu : une longue liste de "TABLE DATA", "CONSTRAINT", "INDEX" — pas
# d'erreur, pas de sortie vide. Comptez les lignes "TABLE DATA" et comparez
# grossièrement au nombre de tables connues (articles, variations,
# transactions, invoices, invoice_items, stock_replenishments, users, ...).
pg_restore --list prod_YYYYMMDD_HHMMSS.dump | grep -c "TABLE DATA"
```

### 1.3 Section à remplir après l'audit staging

Articles correspondant au profil anomalie (`quantity=0`, variations non
vides, aucune vente/facture orpheline) détectés par `variations:audit` sur
**staging** — à passer à `--trust-variations=` lors du `--force` en
production. Ne jamais deviner ces IDs : ils doivent venir du rapport staging
réel, article par article.

| article_id | nom (pour mémoire) | user_id |
|---|---|---|
| _à remplir_ | | |
| _à remplir_ | | |

---

## 2. Séquence de production — commande par commande

Chaque commande ci-dessous s'exécute sur le serveur de production, dans cet
ordre exact. Durées estimées à partir des temps observés en local sur un
jeu de données réduit — **à recalibrer pendant la répétition staging**
(le volume réel de production peut faire varier `migrate`/`backfill`).

| # | Commande | Durée estimée |
|---|---|---|
| 1 | `php artisan down --retry=60 --render="errors::maintenance"` | < 1 s |
| 2 | `pg_dump` (si pas déjà fait en §1.2, sinon le réutiliser) | 1–10 min selon taille |
| 3 | `php artisan migrate --force` (4 migrations additives) | < 5 s |
| 4 | `php artisan variations:audit` — lire le rapport | < 5 s |
| 5 | `php artisan variations:backfill` (dry-run par défaut) — **montrer le rapport et attendre l'accord explicite avant de continuer** | < 5 s |
| 6 | `php artisan variations:backfill --force --trust-variations=<ids du §1.3>` | < 10 s |
| 7 | `php artisan variations:audit` — **doit afficher 0 écart** | < 5 s |
| 8 | Déployer le nouveau code backend (merge/déploiement de la branche de prod) | 2–5 min (CI/CD) |
| 9 | Vérifications post-déploiement (§3) | 2–3 min |
| 10 | `php artisan up` | < 1 s |
| 11 | Déployer le frontend (peut se faire juste après — l'ancienne API reste compatible pendant la bascule) | 2–5 min (CI/CD) |

**Durée totale de maintenance (site inaccessible, étapes 1 à 10) : environ
10 à 20 minutes**, migration et backfill étant rapides (secondes) — le gros
de la fenêtre est le déploiement du nouveau code backend (étape 8) et les
vérifications (étape 9), pas les commandes de données elles-mêmes.

---

## 3. Vérifications après déploiement (avant `php artisan up`)

Toutes à faire **pendant** que le site est encore en maintenance (le mode
maintenance n'empêche pas d'exécuter des commandes `artisan` ni d'appeler
l'API directement en curl/Postman si besoin) :

1. **`php artisan variations:audit` affiche 0 écart** (section c vide) pour
   tous les articles variables — c'est le critère d'acceptation principal.
2. **Une vente test** : créez une vente sur une variation réelle à faible
   enjeu (1 unité), confirmez que `remaining_quantity` de cette variation
   baisse de 1 et que `articles.quantity` (le cache) reste inchangé —
   supprimez cette transaction de test immédiatement après (`DELETE
   /api/transactions/{id}`) pour ne pas polluer les vraies données.
3. **Une facture test** : créez une facture brouillon avec une ligne sur un
   article variable (variation choisie), vérifiez le 422 si vous omettez la
   variation, puis **supprimez ce brouillon** (`DELETE /api/invoices/{id}`,
   autorisé tant qu'elle n'est pas "paid").
4. **Valeur du stock du tableau de bord identique à avant** : notez
   `GET /api/user` → `total_stock_value` **avant** l'étape 3 de la séquence
   (migration), comparez-la à la même valeur **après** l'étape 7 (backfill
   + resync) — elle doit être rigoureusement identique (aux centimes près).
   Une différence signale un problème de resynchronisation, pas juste un
   écart cosmétique : ne pas remettre en ligne tant qu'elle n'est pas
   expliquée.

Si l'un de ces points échoue : ne PAS faire `php artisan up` — passez à la
section 4a.

---

## 4. Retour arrière

### 4a. Échec détecté AVANT `php artisan up`

Le site est encore en maintenance, aucun trafic utilisateur n'a pu agir sur
les données depuis le début de la fenêtre — un retour en arrière complet ne
perd rien :

```bash
# 1. Restaurer le dump pris en §1.2 (écrase entièrement la base)
dropdb "<nom_base_prod>"      # ou TRUNCATE des tables concernées, selon votre contrainte d'accès
createdb "<nom_base_prod>"
pg_restore --no-owner --dbname="$DATABASE_URL_PRODUCTION" prod_YYYYMMDD_HHMMSS.dump

# 2. Redéployer le tag précédent (backend ET frontend)
git checkout pre-variations-YYYYMMDD
# puis votre procédure de déploiement habituelle sur ce tag

# 3. Relancer
php artisan up
```
**Rien n'est perdu** dans ce scénario : le site était inaccessible
(`artisan down`) pendant toute la fenêtre, donc aucune vente/facture/client
réel n'a pu être créé entre le dump et la restauration.

### 4b. Problème découvert APRÈS la remise en ligne (`php artisan up` déjà fait)

**Ne JAMAIS restaurer le dump pris en §1.2 à ce stade** : il effacerait
toute vente, facture, client ou article créé par de vrais utilisateurs
depuis la remise en ligne — une perte de données réelle, pas seulement un
retour à un état antérieur.

Marche à suivre :
1. **Si le problème est dans le CODE** (bug introduit par le nouveau
   backend, sans lien avec les données elles-mêmes) : redéployez le tag
   backend précédent. C'est sûr et ne perd rien, car la migration
   n'ajoute que des colonnes nullable — l'ancien code les ignore simplement.
   **Mais** : cela réactive aussi l'ancien comportement (pas de garde sur
   `variation_id`, plafond de `VariationController` revenu) — à ne garder
   que le temps de corriger, pas comme solution durable.
2. **Si le problème est dans les DONNÉES** (le backfill a mal reconstitué
   le stock d'un ou plusieurs articles précis) : identifiez les articles
   concernés via `php artisan variations:audit` (section écart) et
   corrigez-les un par un, manuellement, en SQL ciblé sur ces lignes
   précises — jamais une restauration globale. Documentez chaque correction
   manuelle (article_id, avant/après) pour traçabilité.
3. **Si le problème est une vraie régression de stock généralisée** (cas
   extrême, non rencontré en staging) : évaluez au cas par cas avec le
   propriétaire du projet avant toute action destructrice — ce runbook ne
   prescrit pas de restauration automatique dans ce cas précisément parce
   qu'elle perdrait du vrai trafic.

**Ce qui serait perdu par une restauration du dump malgré du trafic post-mise en ligne** :
toutes les ventes, factures, réapprovisionnements, nouveaux clients et
articles créés par de vrais utilisateurs entre l'instant du `php artisan up`
et l'instant de la restauration — sans recours possible (le dump ne les
contient pas, ils n'existaient pas encore au moment où il a été pris).

---

## 5. Après la production

- Garder les scripts de backfill/audit disponibles (ne pas les supprimer)
  pendant au moins quelques semaines, au cas où un écart isolé apparaîtrait
  plus tard sur un article qui n'avait pas encore été touché.
- Planifier séparément : passage de `variations.price` en `NOT NULL` et
  retrait du mode de compatibilité de l'ancienne API (Étape 5d de la spec
  initiale) — seulement une fois le frontend confirmé à jour chez tous les
  utilisateurs actifs (plus aucune occurrence de
  `STALE_CLIENT_MISSING_VARIATION` dans les logs depuis un certain temps).
