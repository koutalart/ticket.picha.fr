#!/bin/sh
set -e
for env_file in .env.staging .env.prod; do
  if grep -E "^VITE_.*=(sk_live_|sk_test_|whsec_)" "$env_file" 2>/dev/null; then
    echo "ERREUR: une cle secrete Stripe est presente dans une variable VITE_* (exposee au frontend public)."
    echo "Verifie frontend/$env_file - seules les cles pk_live_/pk_test_ doivent y figurer."
    exit 1
  fi
done
echo "OK: aucune cle secrete detectee dans les variables VITE_*."
