# Configuration de la Base de Données PostgreSQL

Ce document explique comment configurer et dépanner la connexion PostgreSQL dans l'application Trade Manager.

## Table des matières

1. [Configuration par environnement](#configuration-par-environnement)
2. [Variables d'environnement](#variables-denvironnement)
3. [Vérification de PostgreSQL](#vérification-de-postgresql)
4. [Gestion des erreurs](#gestion-des-erreurs)
5. [Dépannage](#dépannage)

---

## Configuration par environnement

### 🏠 Environnement Local (développement)

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=trade_manager
DB_USERNAME=postgres
DB_PASSWORD=votre_mot_de_passe

# Options de connexion
DB_TIMEOUT=5
DB_PERSISTENT=true
DB_RETRY_ON_TIMEOUT=true
DB_RETRY_ATTEMPTS=3
DB_RETRY_DELAY=100
```

### 🐳 Environnement Docker

```env
DB_CONNECTION=pgsql
# Si PostgreSQL tourne sur la machine hôte (pas dans Docker)
DB_HOST=host.docker.internal

# OU si PostgreSQL est un service Docker Compose
DB_HOST=postgres  # Nom du service dans docker-compose.yml

DB_PORT=5432
DB_DATABASE=trade_manager
DB_USERNAME=postgres
DB_PASSWORD=votre_mot_de_passe

# Options de connexion (augmenter le timeout pour Docker)
DB_TIMEOUT=10
DB_PERSISTENT=true
DB_RETRY_ON_TIMEOUT=true
DB_RETRY_ATTEMPTS=5
DB_RETRY_DELAY=200
```

**Exemple de `docker-compose.yml`:**

```yaml
version: '3.8'
services:
  app:
    build: .
    depends_on:
      - postgres
    environment:
      - DB_HOST=postgres
      - DB_PORT=5432

  postgres:
    image: postgres:15
    environment:
      POSTGRES_DB: trade_manager
      POSTGRES_USER: postgres
      POSTGRES_PASSWORD: votre_mot_de_passe
    ports:
      - "5432:5432"
    volumes:
      - postgres_data:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U postgres"]
      interval: 5s
      timeout: 5s
      retries: 5

volumes:
  postgres_data:
```

### 🚀 Environnement Production

```env
DB_CONNECTION=pgsql
DB_HOST=10.0.1.100  # IP du serveur DB ou nom DNS interne
DB_PORT=5432
DB_DATABASE=trade_manager_prod
DB_USERNAME=app_user
DB_PASSWORD=mot_de_passe_fort_et_unique

# Options de connexion (production = stabilité)
DB_TIMEOUT=10
DB_PERSISTENT=true
DB_RETRY_ON_TIMEOUT=true
DB_RETRY_ATTEMPTS=5
DB_RETRY_DELAY=500
```

**Bonnes pratiques production:**
- Utiliser un utilisateur DB dédié avec permissions minimales
- Configurer SSL: `DB_SSLMODE=require`
- Utiliser des mots de passe forts générés aléatoirement
- Configurer un pool de connexions (PgBouncer)

---

## Variables d'environnement

| Variable | Description | Valeur par défaut |
|----------|-------------|-------------------|
| `DB_CONNECTION` | Type de connexion | `pgsql` |
| `DB_HOST` | Hôte PostgreSQL | `127.0.0.1` |
| `DB_PORT` | Port PostgreSQL | `5432` |
| `DB_DATABASE` | Nom de la base | `laravel` |
| `DB_USERNAME` | Utilisateur DB | `root` |
| `DB_PASSWORD` | Mot de passe DB | (vide) |
| `DB_TIMEOUT` | Timeout de connexion (secondes) | `5` |
| `DB_PERSISTENT` | Connexion persistante | `true` |
| `DB_RETRY_ON_TIMEOUT` | Retry automatique | `true` |
| `DB_RETRY_ATTEMPTS` | Nombre de tentatives | `3` |
| `DB_RETRY_DELAY` | Délai entre tentatives (ms) | `100` |

### Configuration des Sessions

| Variable | Description | Valeur par défaut |
|----------|-------------|-------------------|
| `SESSION_DRIVER` | Driver de session | `cookie` |
| `SESSION_LIFETIME` | Durée de vie (minutes) | `120` |

**⚠️ Important:** Si `SESSION_DRIVER=database` et que PostgreSQL est indisponible, l'application bascule automatiquement vers `SESSION_DRIVER=file` pour éviter les crashs.

---

## Vérification de PostgreSQL

### Vérifier que PostgreSQL est en ligne

```bash
# Méthode 1: pg_isready (recommandé)
pg_isready -h 127.0.0.1 -p 5432

# Réponse attendue:
# 127.0.0.1:5432 - accepting connections

# Méthode 2: psql
psql -h 127.0.0.1 -U postgres -c "SELECT 1;"

# Méthode 3: via le service (macOS)
brew services list | grep postgresql

# Méthode 4: via le service (Linux)
sudo systemctl status postgresql
```

### Vérifier la connexion depuis Laravel

```bash
# Via Artisan
php artisan tinker

# Dans Tinker:
>>> DB::connection()->getPdo();
>>> DB::select('SELECT 1 as test');
```

---

## Gestion des erreurs

### Comportement automatique

L'application intègre plusieurs mécanismes de protection:

1. **Timeout de connexion**: 5 secondes par défaut (configurable via `DB_TIMEOUT`)

2. **Retry automatique**: 3 tentatives avec délai de 100ms entre chaque

3. **Fallback SESSION_DRIVER**: Si `SESSION_DRIVER=database` et DB down → bascule auto vers `file`

4. **Réponses JSON propres**: Au lieu d'erreurs 500, l'API retourne:

```json
{
    "success": false,
    "error": "database_connection_error",
    "message": "Le service de base de données est temporairement indisponible.",
    "details": {
        "type": "connection_timeout",
        "retry_after": 30
    }
}
```

### Codes d'erreur PostgreSQL gérés

| Code | Description |
|------|-------------|
| `08006` | Connection failure |
| `08001` | Unable to establish connection |
| `08003` | Connection does not exist |
| `08004` | Server rejected connection |
| `08007` | Transaction resolution unknown |
| `57P01` | Admin shutdown |
| `57P02` | Crash shutdown |
| `57P03` | Cannot connect now |

---

## Dépannage

### Erreur: "connection to server at 127.0.0.1:5432 failed: timeout expired"

**Causes possibles:**
1. PostgreSQL n'est pas démarré
2. Firewall bloque le port 5432
3. DB_HOST incorrect
4. PostgreSQL n'écoute pas sur l'interface réseau

**Solutions:**

```bash
# 1. Vérifier que PostgreSQL est démarré
# macOS avec Homebrew:
brew services start postgresql@15

# Linux:
sudo systemctl start postgresql
sudo systemctl enable postgresql

# 2. Vérifier que PostgreSQL écoute sur le bon port
sudo lsof -i :5432

# 3. Vérifier la configuration postgresql.conf
# Fichier: /usr/local/var/postgresql@15/postgresql.conf (macOS)
# ou /etc/postgresql/15/main/postgresql.conf (Linux)
listen_addresses = '*'  # ou 'localhost' pour local uniquement

# 4. Vérifier pg_hba.conf pour les autorisations
# host    all    all    127.0.0.1/32    scram-sha-256
```

### Erreur: "SQLSTATE[08006] select * from sessions"

**Cause:** `SESSION_DRIVER=database` mais la table `sessions` n'existe pas ou la DB est down.

**Solutions:**

```bash
# 1. Créer la table sessions
php artisan session:table
php artisan migrate

# 2. Ou changer le driver de session
# Dans .env:
SESSION_DRIVER=file
# ou
SESSION_DRIVER=cookie
```

### Redémarrer PostgreSQL

```bash
# macOS avec Homebrew
brew services restart postgresql@15

# Linux (systemd)
sudo systemctl restart postgresql

# Linux (service)
sudo service postgresql restart

# Docker
docker restart nom_du_container_postgres

# Docker Compose
docker-compose restart postgres
```

### Vérifier les logs PostgreSQL

```bash
# macOS
tail -f /usr/local/var/log/postgresql@15.log

# Linux
sudo tail -f /var/log/postgresql/postgresql-15-main.log

# Docker
docker logs -f nom_du_container_postgres
```

### Tester la connexion manuellement

```bash
# Test avec psql
psql -h 127.0.0.1 -p 5432 -U postgres -d trade_manager

# Test avec PHP
php -r "
try {
    \$pdo = new PDO(
        'pgsql:host=127.0.0.1;port=5432;dbname=trade_manager',
        'postgres',
        'votre_mot_de_passe',
        [PDO::ATTR_TIMEOUT => 5]
    );
    echo 'Connexion OK!';
} catch (PDOException \$e) {
    echo 'Erreur: ' . \$e->getMessage();
}
"
```

---

## Architecture de la gestion d'erreurs

```
┌─────────────────────────────────────────────────────────────────┐
│                     Requête API entrante                         │
└──────────────────────────────┬──────────────────────────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────────┐
│             DatabaseConnectionMiddleware                         │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │ 1. Vérifie la connexion DB avec retry                   │    │
│  │ 2. Si échec → Retourne JSON 503                         │    │
│  │ 3. Si succès → Passe au middleware suivant              │    │
│  └─────────────────────────────────────────────────────────┘    │
└──────────────────────────────┬──────────────────────────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────────┐
│                   Logique métier (Controller)                    │
└──────────────────────────────┬──────────────────────────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────────┐
│              Exception Handler (bootstrap/app.php)               │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │ - Capture PDOException (timeout, connexion perdue)      │    │
│  │ - Capture QueryException                                 │    │
│  │ - Retourne réponse JSON appropriée                       │    │
│  └─────────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────────┘
```

---

## Checklist de déploiement

- [ ] `DB_HOST` configuré selon l'environnement
- [ ] `DB_PASSWORD` défini et sécurisé
- [ ] `DB_TIMEOUT` ajusté (5-10 secondes)
- [ ] `DB_PERSISTENT=true` activé
- [ ] `DB_RETRY_ON_TIMEOUT=true` activé
- [ ] Table `sessions` créée si `SESSION_DRIVER=database`
- [ ] PostgreSQL accessible depuis l'application
- [ ] Logs configurés pour le monitoring

---

*Dernière mise à jour: Janvier 2026*

