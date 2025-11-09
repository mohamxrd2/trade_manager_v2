# Configuration Docker et PostgreSQL

## Prérequis
- Docker Desktop installé et démarré

## Démarrage de PostgreSQL avec Docker

1. **Démarrer le conteneur PostgreSQL :**
```bash
docker-compose up -d
```

2. **Vérifier que le conteneur fonctionne :**
```bash
docker-compose ps
```

3. **Voir les logs du conteneur (optionnel) :**
```bash
docker-compose logs postgres
```

## Configuration du fichier .env

Créez un fichier `.env` à la racine du projet avec la configuration suivante :

```env
APP_NAME="Trade Manager"
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_TIMEZONE=UTC
APP_URL=http://localhost

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=trade_manager
DB_USERNAME=postgres
DB_PASSWORD=secret
```

**Important :** Après avoir créé le fichier `.env`, générez la clé d'application :
```bash
php artisan key:generate
```

## Connexion avec Beekeeper Studio

1. **Téléchargez et installez Beekeeper Studio** depuis : https://www.beekeeperstudio.io/

2. **Créez une nouvelle connexion PostgreSQL avec ces paramètres :**
   - **Nom de connexion :** Trade Manager
   - **Hôte :** `127.0.0.1` ou `localhost`
   - **Port :** `5432`
   - **Nom d'utilisateur :** `postgres`
   - **Mot de passe :** `secret`
   - **Base de données :** `trade_manager`
   - **Schéma (optionnel) :** `public`

3. **Testez la connexion** puis sauvegardez.

## Commandes utiles

**Arrêter le conteneur :**
```bash
docker-compose down
```

**Redémarrer le conteneur :**
```bash
docker-compose restart postgres
```

**Accéder au shell PostgreSQL (optionnel) :**
```bash
docker-compose exec postgres psql -U postgres -d trade_manager
```

**Vider et recréer la base de données (ATTENTION : supprime toutes les données) :**
```bash
docker-compose down -v
docker-compose up -d
```

**Exécuter les migrations Laravel :**
```bash
php artisan migrate
```

## Dépannage

Si vous rencontrez des problèmes de connexion :

1. Vérifiez que Docker Desktop est bien démarré
2. Vérifiez que le port 5432 n'est pas déjà utilisé par une autre instance PostgreSQL
3. Vérifiez les logs : `docker-compose logs postgres`
4. Redémarrez le conteneur : `docker-compose restart postgres`


