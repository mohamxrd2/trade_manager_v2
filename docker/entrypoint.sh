#!/bin/sh
# Point d'entrée du conteneur en production (Render).
# set -e : toute commande qui échoue arrête immédiatement le démarrage —
# on ne veut jamais servir du trafic après une migration ratée ou un
# cache cassé (comportement déjà implicite avec l'ancien CMD chaîné par
# des &&, préservé ici à l'identique).
set -e

echo "[entrypoint] Génération de la config Nginx (PORT=${PORT:-10000})"
# ${PORT} : injecté par Render au runtime, jamais connu au moment du build.
# On ne substitue QUE cette variable (liste explicite '${PORT}') — un
# envsubst sans liste remplacerait aussi les propres variables de Nginx
# ($uri, $document_root, etc.), qui partagent la même syntaxe $VAR.
export PORT="${PORT:-10000}"
envsubst '${PORT}' < /etc/nginx/templates/nginx.conf.template > /etc/nginx/conf.d/default.conf

echo "[entrypoint] Cache Laravel (config/route/view)"
# Exécutés ici, au démarrage du conteneur — jamais pendant le build de
# l'image, puisque Render n'injecte les variables d'environnement (DB_URL,
# clés OAuth, RESEND_KEY, etc.) qu'au runtime. Même remarque que l'ancien
# CMD : les mettre en cache pendant le build figerait des valeurs vides.
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "[entrypoint] Migrations"
php artisan migrate --force

echo "[entrypoint] Lien de stockage public"
# || true : comportement identique à l'ancien CMD — ne bloque pas le
# démarrage si le lien symbolique existe déjà (redéploiements successifs).
(php artisan storage:link || true)

echo "[entrypoint] Démarrage de Supervisor (Nginx + PHP-FPM)"
# exec remplace le process courant (PID 1) par supervisord, au lieu de le
# lancer en sous-process — nécessaire pour que Supervisor reçoive
# correctement les signaux d'arrêt envoyés par Render (SIGTERM lors d'un
# redéploiement ou d'une mise à l'échelle).
exec supervisord -c /etc/supervisor/supervisord.conf
