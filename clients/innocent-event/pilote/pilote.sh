#!/bin/sh
# Innocent Event, Halloween : pilote lancé toutes les 5 minutes par cron (utilisateur ubuntu).
# Vérifie la clé attendue (jour + tarif) ; si elle change : sauvegarde, puis mise en ligne.
set -u
D=/home/ubuntu/picha-clients/innocent-event/halloween
W=picha-ticket-prod-queue-worker-1
ETAT=$D/etat.txt
exec 9>"$D/.verrou"; flock -n 9 || exit 0
horo() { date -u +%Y-%m-%dT%H:%M:%SZ; }

docker cp "$D/pilote.php" $W:/tmp/innocent-pilote.php >/dev/null && docker exec -u root $W chmod a+r /tmp/innocent-pilote.php
R=$(docker exec -u www-data -e PILOTE_MODE=verifier $W php artisan tinker /tmp/innocent-pilote.php 2>&1 | grep '^CLE=' | head -1)
CLE=$(echo "$R" | sed -E 's/^CLE=([^ ]+).*/\1/')
if [ -z "$CLE" ]; then echo "$(horo) ERREUR vérification : $R"; docker exec -u root $W rm -f /tmp/innocent-pilote.php; exit 1; fi
if [ "$CLE" = "FIN" ]; then
    echo "$(horo) FIN : soirée passée, retrait de la tâche cron"
    docker exec -u root $W rm -f /tmp/innocent-pilote.php
    crontab -l 2>/dev/null | grep -v 'innocent-event/halloween/pilote.sh' | crontab -
    exit 0
fi
if [ "$CLE" = "$(cat "$ETAT" 2>/dev/null)" ]; then docker exec -u root $W rm -f /tmp/innocent-pilote.php; exit 0; fi

echo "$(horo) changement : $(cat "$ETAT" 2>/dev/null) -> $R"
S=$(sudo -n /opt/picha-ticket/docker/production/backup.sh 2>&1 | tail -1)
case "$S" in *"sauvegarde OK"*) echo "$(horo) $S" ;; *) echo "$(horo) ERREUR sauvegarde, rien n'est modifié : $S"; docker exec -u root $W rm -f /tmp/innocent-pilote.php; exit 1 ;; esac

docker exec -u root $W sh -c 'rm -rf /tmp/innocent-pilote && mkdir -p /tmp/innocent-pilote'
docker cp "$D/videos/halloween-$CLE.mp4" $W:/tmp/innocent-pilote/ && docker exec -u root $W chmod -R a+rX /tmp/innocent-pilote
A=$(docker exec -u www-data -e PILOTE_MODE=appliquer -e PILOTE_CLE="$CLE" $W php artisan tinker /tmp/innocent-pilote.php 2>&1 | grep -v DEPRECATED | grep -v '^>' )
docker exec -u root $W rm -rf /tmp/innocent-pilote /tmp/innocent-pilote.php
case "$A" in *"APPLIQUE=$CLE"*) echo "$CLE" > "$ETAT"; echo "$(horo) $A" ;; *) echo "$(horo) ERREUR application : $A"; exit 1 ;; esac
