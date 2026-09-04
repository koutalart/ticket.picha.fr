#!/bin/sh
set -e
if grep -E "^VITE_.*=(sk_live_|sk_test_|whsec_)" .env.staging 2>/dev/null; then
  echo "ERREUR: une cle secrete Stripe est presente dans une variable VITE_* (exposee au frontend public)."
  echo "Verifie frontend/.env.staging - seules les cles pk_live_/pk_test_ doivent y figurer."
  exit 1
fi
echo "OK: aucune cle secrete detectee dans les variables VITE_*."
