#!/bin/sh
set -e
cd /var/www/html

mkdir -p var/uploads var/log var/cache

# OPTIONNEL, pour de futures migrations seulement (désactivé par défaut) :
# avec RUN_MIGRATIONS=1 et DATABASE_MIGRATION_URL (hôte direct + rôle propriétaire),
# les migrations Doctrine sont exécutées au démarrage. En cas d'échec, le démarrage s'arrête.
# Les 3 migrations actuelles sont DÉJÀ appliquées sur Neon : ne rien configurer pour l'instant.
if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
  if [ -z "${DATABASE_MIGRATION_URL:-}" ]; then
    echo "[entrypoint] RUN_MIGRATIONS=1 mais DATABASE_MIGRATION_URL est vide : arrêt." >&2
    exit 1
  fi
  echo "[entrypoint] Migrations Doctrine (connexion directe)..."
  DATABASE_URL="$DATABASE_MIGRATION_URL" php bin/console doctrine:migrations:migrate \
    --no-interaction --allow-no-migration --env=prod --no-debug
fi

chown -R www-data:www-data var
exec "$@"
