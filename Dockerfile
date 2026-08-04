FROM php:8.2-fpm

# Dépendances système RUNTIME (restent dans l'image finale) — Nginx/
# Supervisor pour la migration hors de `php artisan serve` (non recommandé
# en production par la doc officielle PHP elle-même — voir
# php.net/manual/en/features.commandline.webserver.php), git/unzip pour
# Composer (étape suivante).
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        nginx \
        supervisor \
        gettext-base \
    && rm -rf /var/lib/apt/lists/*

# Extensions PHP requises par Laravel + PostgreSQL (Neon) — inchangé :
# docker-php-ext-configure/install fonctionnent à l'identique sur toutes
# les variantes (-cli, -fpm, -apache) de l'image officielle php.
#
# Les paquets *-dev (headers/libs statiques) ne servent qu'à COMPILER ces
# extensions — installés, utilisés puis purgés dans CE MÊME RUN, donc
# cette même couche Docker. Une purge dans une couche ULTÉRIEURE ne réduit
# PAS la taille de l'image : les octets de la couche où ils ont été
# installés restent stockés (un "whiteout" masque juste le fichier dans la
# vue fusionnée) — vérifié par la taille réelle de l'image, inchangée après
# une première tentative de purge différée. Regrouper install+purge dans un
# seul RUN garantit qu'aucune couche exportée ne contient jamais ces ~53 Mo.
#
# Vérifié par ldd sur gd.so/pdo_pgsql.so/zip.so/intl.so/mbstring.so : chaque
# lib dynamique requise à l'exécution (libpq5, libzip5, libicu76, libpng16,
# libjpeg62-turbo, libfreetype6, libonig5) est un paquet SÉPARÉ des *-dev
# (ex: libpq-dev dépend de libpq5, pas l'inverse) — purger uniquement les
# *-dev, sans --autoremove, laisse ces libs runtime intactes.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev \
        libzip-dev \
        libicu-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_pgsql \
        mbstring \
        bcmath \
        intl \
        gd \
        zip \
        opcache \
    && apt-get purge -y --no-install-recommends \
        libpq-dev \
        libzip-dev \
        libicu-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libonig-dev \
    && rm -rf /var/lib/apt/lists/*

# OPcache — production (inchangé)
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.memory_consumption=192'; \
    } > /usr/local/etc/php/conf.d/opcache-prod.ini

# Composer (inchangé)
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

# COPY . . préserve les permissions d'origine du système de fichiers hôte
# — ici 700 (rwx------, root uniquement) sur la quasi-totalité du repo.
# Sans conséquence avec l'ancien `php artisan serve` (qui tournait en root,
# donc toujours propriétaire), mais BLOQUANT pour Nginx/PHP-FPM : leurs
# workers tournent en www-data (non-root) et doivent pouvoir lire/traverser
# tout /app — vérifié par test réel : sans normalisation, Nginx renvoie 404
# avec "Permission denied" sur le moindre stat() de fichier.
#
# Modèle retenu, délibérément DEUX granularités distinctes plutôt qu'un
# chown -R www-data uniforme sur tout /app : www-data n'a besoin d'écrire
# QUE dans storage/ et bootstrap/cache (le reste — code, vendor/ — n'est
# jamais modifié à l'exécution, lecture seule suffit). Un chown -R
# www-data sur tout /app a été mesuré à l'usage : +57 Mo sur l'image
# finale (vendor/, ~74 Mo, entièrement recopié par l'overlay Docker dès
# qu'on lui change son propriétaire), pour un gain de sécurité marginal
# dans ce conteneur mono-usage (seuls root et www-data y tournent — un
# accès "other" en lecture seule sur du code déjà non modifiable au
# runtime n'ouvre aucune capacité d'écriture supplémentaire). root reste
# donc propriétaire du code (755/644, lecture seule pour www-data via les
# bits "other"), www-data devient propriétaire UNIQUEMENT de storage/ et
# bootstrap/cache (775, accès écriture réellement nécessaire) — les deux
# opérations sont regroupées dans un seul RUN pour éviter de recopier deux
# fois ce même sous-arbre storage/ (chaque modification de permission
# déclenche une recopie complète du fichier par l'overlay Docker).
RUN mkdir -p storage/logs \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/app/public \
    && find /app -type d -exec chmod 755 {} \; \
    && find /app -type f -exec chmod 644 {} \; \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# --- Nginx / PHP-FPM / Supervisor ---

# Template Nginx : ${PORT} y est substitué au démarrage du conteneur par
# docker/entrypoint.sh (envsubst), Render n'injectant ce port qu'au
# runtime, jamais au moment du build.
COPY docker/nginx.conf.template /etc/nginx/templates/nginx.conf.template
# Retire le site par défaut du paquet Debian nginx (écoute en dur sur le
# port 80) : seule notre config, générée dynamiquement sur $PORT, doit
# servir du trafic.
RUN rm -f /etc/nginx/sites-enabled/default

# Pool PHP-FPM : remplace le pool par défaut (TCP:9000) par un socket Unix
# partagé avec Nginx — voir docker/php-fpm-pool.conf pour le détail.
COPY docker/php-fpm-pool.conf /usr/local/etc/php-fpm.d/www.conf

# Supervisor pilote Nginx + PHP-FPM comme deux process dans ce même
# conteneur, avec redémarrage automatique en cas de crash de l'un des deux
# (résilience que `php artisan serve` seul n'offrait pas).
COPY docker/supervisord.conf /etc/supervisor/supervisord.conf

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 10000

# Toute la logique de démarrage (génération de la config Nginx sur le vrai
# $PORT, cache Laravel, migrations, lancement de Supervisor) vit dans
# docker/entrypoint.sh — voir ce fichier pour la justification détaillée
# de chaque étape, notamment pourquoi le cache Laravel tourne ici et pas
# pendant le build (variables d'environnement Render non disponibles avant
# le runtime).
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
