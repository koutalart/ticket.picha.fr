#!/bin/sh
# Sauvegarde quotidienne de la base PICHA Ticket (production). Cron sur le VPS :
#   0 3 * * * /opt/picha-ticket/docker/production/backup.sh >> /var/log/picha-backup.log 2>&1
set -eu

cd "$(dirname "$0")/../.."

BACKUP_DIR=/var/backups/picha-ticket
RETENTION_DAYS=14
mkdir -p "$BACKUP_DIR"

FILE="$BACKUP_DIR/picha-ticket-$(date +%Y%m%d-%H%M%S).sql.gz"
docker compose -p picha-ticket-prod -f docker/production/docker-compose.prod.yml exec -T postgres \
    pg_dump -U picha_ticket -d picha_ticket --no-owner | gzip > "$FILE"

find "$BACKUP_DIR" -name 'picha-ticket-*.sql.gz' -mtime +"$RETENTION_DAYS" -delete
echo "$(date -Iseconds) sauvegarde OK : $FILE"
