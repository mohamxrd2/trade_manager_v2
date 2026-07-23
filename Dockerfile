FROM php:8.2-cli

# Dépendances système pour les extensions PHP
RUN apt-get update && apt-get install -y \
        git \
        unzip \
        libpq-dev \
        libzip-dev \
        libicu-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libonig-dev \
    && rm -rf /var/lib/apt/lists/*

# Extensions PHP requises par Laravel + PostgreSQL (Neon)
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_pgsql \
        mbstring \
        bcmath \
        intl \
        gd \
        zip \
        opcache

# OPcache — production
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.memory_consumption=192'; \
    } > /usr/local/etc/php/conf.d/opcache-prod.ini

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app

# Dépendances PHP d'abord (couche mise en cache tant que composer.json/lock
# ne changent pas). --no-scripts : les scripts composer (package:discover)
# ont besoin du reste de l'app, pas encore copiée à ce stade.
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

# Copier tout le projet
COPY . .

# Autoload optimisé + déclenche les scripts composer différés (package:discover)
RUN composer dump-autoload --optimize --no-interaction

# storage/logs, storage/framework/{cache,sessions,views} sont exclus par
# .dockerignore (contenu de dev non pertinent en prod) — mais Laravel a
# besoin que ces dossiers existent (vides) pour écrire logs/sessions/vues
# compilées/cache au runtime. On les recrée explicitement.
RUN mkdir -p storage/logs \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/app/public \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 10000

# config:cache / route:cache / view:cache tournent ICI, au démarrage du
# conteneur — PAS pendant le build. Render (comme Neon, Resend, les clés
# OAuth) n'injecte les variables d'environnement qu'au runtime, jamais au
# moment du build de l'image. Les mettre en cache pendant le build figerait
# des valeurs vides ou par défaut (ex: DB_HOST=127.0.0.1 au lieu de l'hôte
# Neon réel) au lieu des vraies variables Render, cassant la connexion DB,
# Resend, Sanctum et OAuth au démarrage.
CMD php artisan config:cache \
    && php artisan route:cache \
    && php artisan view:cache \
    && php artisan migrate --force \
    && (php artisan storage:link || true) \
    && php artisan serve --host=0.0.0.0 --port=${PORT:-10000}
