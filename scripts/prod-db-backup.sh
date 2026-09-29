#!/usr/bin/env bash
# Sauvegarde quotidienne de la base prod PICHA (à lancer par cron sur le VPS).
# Usage : scripts/prod-db-backup.sh [dossier_prod] [dossier_sauvegardes] [jours_de_retention]
set -euo pipefail

PROD_DIR="${1:-/opt/picha-prod}"
BACKUP_DIR="${2:-/opt/picha-prod-backups}"
RETENTION_DAYS="${3:-14}"

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

target="$BACKUP_DIR/picha_ticket_prod_$(date +%Y%m%d_%H%M%S).sql.gz"

docker compose --project-directory "$PROD_DIR" -f "$PROD_DIR/docker-compose.prod.yml" \
    exec -T postgres pg_dump -U picha_prod -d picha_ticket_prod --no-owner \
    | gzip > "$target.partial"
mv "$target.partial" "$target"
chmod 600 "$target"

find "$BACKUP_DIR" -name 'picha_ticket_prod_*.sql.gz' -mtime +"$RETENTION_DAYS" -delete

echo "OK: $target"
