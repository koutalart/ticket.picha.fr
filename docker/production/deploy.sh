#!/bin/sh
# Mise à jour de la production PICHA Ticket, lancée par le workflow GitHub
# « Déployer en production » (ou à la main sur le VPS) :
#   sh /opt/picha-ticket/docker/production/deploy.sh <branche>
# (dépôt : PICHA_DIR, par défaut /opt/picha-ticket)
# Sauvegarde, mise à jour du code, reconstruction des conteneurs, migrations,
# puis vérification que le site et l'API répondent.
set -eu

# Tout le script est dans main() : sh le lit en entier avant de l'exécuter,
# même si le git merge ci-dessous remplace ce fichier pendant l'exécution.
main() {
    BRANCH="${1:-staging}"
    cd "${PICHA_DIR:-/opt/picha-ticket}"
    COMPOSE="docker compose -p picha-ticket-prod --env-file docker/production/.env -f docker/production/docker-compose.prod.yml"

    echo "==> Sauvegarde avant mise à jour"
    sh docker/production/backup.sh

    echo "==> Code : $BRANCH"
    git fetch --quiet origin "$BRANCH"
    git checkout --quiet "$BRANCH"
    git merge --ff-only --quiet "origin/$BRANCH"
    echo "    $(git log --oneline -1)"

    echo "==> Conteneurs"
    $COMPOSE up -d --build

    echo "==> Migrations"
    $COMPOSE exec -T backend php artisan migrate --force --no-interaction

    echo "==> Vérifications"
    attempt=0
    until [ "$(curl -s -o /dev/null -w '%{http_code}' https://ticket.picha.fr/)" = "200" ]; do
        attempt=$((attempt + 1))
        if [ "$attempt" -ge 30 ]; then
            echo "ERREUR : le site ne répond pas en 200 après 60 s" >&2
            exit 1
        fi
        sleep 2
    done
    api_status=$(curl -s -o /dev/null -w '%{http_code}' https://ticket.picha.fr/api/users/me)
    if [ "$api_status" -ge 500 ] || [ "$api_status" = "000" ]; then
        echo "ERREUR : l'API répond $api_status" >&2
        exit 1
    fi

    docker builder prune -f >/dev/null
    echo "$(date -Iseconds) déploiement OK ($BRANCH, API $api_status)"
}

main "$@"
exit
