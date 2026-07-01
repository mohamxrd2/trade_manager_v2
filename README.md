# Trade Manager API

API REST Laravel pour la gestion commerciale : articles, transactions, clients, factures et analytics.

## 🚀 Technologies

- **Framework:** Laravel 11
- **Base de données:** PostgreSQL
- **Authentification:** Laravel Sanctum (SPA)
- **PHP:** 8.2+

## 📦 Installation

```bash
# Cloner le projet
git clone <repository-url>
cd trade_manager_v2

# Installer les dépendances
composer install

# Copier le fichier d'environnement
cp .env.example .env

# Générer la clé d'application
php artisan key:generate

# Configurer la base de données dans .env
# DB_CONNECTION=pgsql
# DB_HOST=127.0.0.1
# DB_PORT=5432
# DB_DATABASE=trade_manager
# DB_USERNAME=postgres
# DB_PASSWORD=your_password

# Exécuter les migrations
php artisan migrate

# Créer le lien de stockage
php artisan storage:link

# Démarrer le serveur
php artisan serve
```

## 🔑 Configuration Sanctum (SPA)

Dans `.env` :

```env
SANCTUM_STATEFUL_DOMAINS=localhost:3000,127.0.0.1:3000
SESSION_DOMAIN=localhost
SESSION_DRIVER=database
```

## 📚 Endpoints API

### Authentification
| Méthode | Endpoint | Description |
|---------|----------|-------------|
| POST | `/api/register` | Inscription |
| POST | `/api/login` | Connexion |
| POST | `/api/logout` | Déconnexion |
| GET | `/api/user` | Utilisateur connecté |

### Articles
| Méthode | Endpoint | Description |
|---------|----------|-------------|
| GET | `/api/articles` | Liste des articles |
| POST | `/api/articles` | Créer un article |
| GET | `/api/articles/{id}` | Détail d'un article |
| PUT | `/api/articles/{id}` | Modifier un article |
| DELETE | `/api/articles/{id}` | Supprimer un article |
| POST | `/api/articles/{id}/add-stock` | Ajouter du stock |
| GET | `/api/articles/{id}/stock-history` | Historique stock |

### Transactions
| Méthode | Endpoint | Description |
|---------|----------|-------------|
| GET | `/api/transactions` | Liste des transactions |
| POST | `/api/transactions` | Créer une transaction |
| PUT | `/api/transactions/{id}` | Modifier une transaction |
| DELETE | `/api/transactions/{id}` | Supprimer une transaction |

### Clients
| Méthode | Endpoint | Description |
|---------|----------|-------------|
| GET | `/api/clients` | Liste des clients |
| POST | `/api/clients` | Créer un client |
| GET | `/api/clients/{id}` | Détail d'un client |
| PUT | `/api/clients/{id}` | Modifier un client |
| DELETE | `/api/clients/{id}` | Supprimer un client |
| GET | `/api/clients/dropdown` | Liste pour select |

### Factures
| Méthode | Endpoint | Description |
|---------|----------|-------------|
| GET | `/api/invoices` | Liste des factures |
| POST | `/api/invoices` | Créer une facture |
| GET | `/api/invoices/{id}` | Détail d'une facture |
| DELETE | `/api/invoices/{id}` | Supprimer (brouillon) |
| PATCH | `/api/invoices/{id}/status` | Changer le statut |
| POST | `/api/invoices/{id}/duplicate` | Dupliquer |
| GET | `/api/invoices/dashboard` | Dashboard facturation |
| GET | `/api/invoices/themes` | Thèmes disponibles |

### Entreprise
| Méthode | Endpoint | Description |
|---------|----------|-------------|
| GET | `/api/company` | Profil entreprise |
| PUT | `/api/company` | Modifier entreprise |
| POST | `/api/company/logo` | Upload logo |
| DELETE | `/api/company/logo` | Supprimer logo |
| GET | `/api/company/onboarding-status` | Statut onboarding |

### Analytics
| Méthode | Endpoint | Description |
|---------|----------|-------------|
| GET | `/api/analytics/summary` | Résumé analytics |
| GET | `/api/analytics/chart` | Données graphique |

### Notifications
| Méthode | Endpoint | Description |
|---------|----------|-------------|
| GET | `/api/notifications` | Liste notifications |
| PATCH | `/api/notifications/{id}/read` | Marquer comme lue |
| PATCH | `/api/notifications/read-all` | Tout marquer comme lu |

## 🗂️ Structure du projet

```
app/
├── Http/
│   ├── Controllers/API/    # Contrôleurs API
│   ├── Middleware/         # Middlewares personnalisés
│   └── Requests/           # Form Requests
├── Models/                 # Modèles Eloquent
├── Providers/              # Service Providers
└── Services/               # Services métier

database/
├── migrations/             # Migrations DB
└── seeders/                # Seeders

routes/
└── api.php                 # Routes API
```

## 🔒 Sécurité

- Toutes les routes API sont protégées par `auth:sanctum`
- Filtrage par `user_id` sur toutes les ressources
- Validation stricte des entrées
- Middleware `EnsureCompanyInvoiceReady` pour la facturation

## 🐳 Docker

```bash
# Démarrer PostgreSQL
docker-compose up -d

# Arrêter
docker-compose down
```

## 📝 License

MIT
