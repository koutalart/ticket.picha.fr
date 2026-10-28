#!/usr/bin/env bash
# Copie chiffrée des sauvegardes sur OVH Object Storage (S3), à lancer UNE fois sur le serveur par Jo :
#   sudo bash /opt/picha-ticket/docker/production/setup-offsite-ovh.sh
# Demande la clé d'accès S3 OVH (le secret n'est pas affiché), crée le remote rclone chiffré
# et ajoute OFFSITE_REMOTE dans /etc/picha-ticket/backup.env. backup.sh copie ensuite chaque nuit.
set -euo pipefail

CONF_DIR=/etc/picha-ticket
RCLONE_DIR="$CONF_DIR/rclone"
RCLONE_CONF="$RCLONE_DIR/rclone.conf"
BACKUP_ENV="$CONF_DIR/backup.env"
RCLONE=(docker run --rm -v "$RCLONE_DIR:/config/rclone" rclone/rclone:1)

[ "$(id -u)" = 0 ] || { echo "A lancer avec sudo." >&2; exit 1; }
mkdir -p "$RCLONE_DIR"; chmod 700 "$CONF_DIR" "$RCLONE_DIR"

read -rp "Région OVH du conteneur (ex. gra, sbg, rbx) : " REGION
read -rp "Nom du conteneur S3 (ex. picha-sauvegardes) : " BUCKET
read -rp "Access key S3 : " ACCESS
read -rsp "Secret key S3 (masquée) : " SECRET; echo

PASS1=$(openssl rand -base64 32); PASS2=$(openssl rand -base64 32)
OBS1=$("${RCLONE[@]}" obscure "$PASS1"); OBS2=$("${RCLONE[@]}" obscure "$PASS2")

[ -f "$RCLONE_CONF" ] && cp -p "$RCLONE_CONF" "$RCLONE_CONF.bak-$(date +%Y%m%d-%H%M%S)"
umask 077
cat > "$RCLONE_CONF" <<CONF
[ovh]
type = s3
provider = Other
access_key_id = $ACCESS
secret_access_key = $SECRET
endpoint = https://s3.$REGION.io.cloud.ovh.net
region = $REGION
acl = private

[ovh-chiffre]
type = crypt
remote = ovh:$BUCKET/picha-ticket
password = $OBS1
password2 = $OBS2
CONF
unset SECRET

touch "$BACKUP_ENV"; chmod 600 "$BACKUP_ENV"
grep -v '^OFFSITE_REMOTE=' "$BACKUP_ENV" > "$BACKUP_ENV.tmp" || true
echo 'OFFSITE_REMOTE=ovh-chiffre:' >> "$BACKUP_ENV.tmp"
mv "$BACKUP_ENV.tmp" "$BACKUP_ENV"

echo "Test d'accès au conteneur..."
"${RCLONE[@]}" lsd "ovh:$BUCKET" >/dev/null && echo "Accès OVH : OK"

cat <<MSG

IMPORTANT : sans ces deux mots de passe, les copies chez OVH sont illisibles.
Range-les tout de suite dans ton gestionnaire de mots de passe (« PICHA sauvegardes OVH ») :
  mot de passe 1 : $PASS1
  mot de passe 2 : $PASS2
Puis lance une première copie : sudo /opt/picha-ticket/docker/production/backup.sh
MSG
