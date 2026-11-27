# Carte fidélité Apple Wallet et Google Wallet

Premier client : La Terrasse de la Barge (organisateur 24). Règle : 9 commandes validées = 1 menu offert, puis la carte repart de zéro.

## Ce que fait le code

- Programme par organisateur : nom, récompense, nombre de tampons, couleurs, logo, jusqu'à 10 lieux GPS avec un message d'écran verrouillé par lieu, balise Bluetooth facultative.
- Page publique « Ajouter ma carte » : `https://ticket.picha.fr/fidelite/24`, ou `https://<domaine>/fidelite` si l'organisateur a un domaine personnalisé dans PICHA. Prénom + e-mail ou téléphone, puis boutons « Ajouter à Apple Wallet » et « Enregistrer dans Google Wallet ».
- Écran comptoir : Gestion > Carte fidélité > « Tamponner une carte ». Le scan du QR ajoute un tampon et pousse la mise à jour sur le téléphone. Un second tampon sur la même carte est refusé pendant 60 secondes.
- Pertinence : Apple reçoit `locations` (10 max, `relevantText` par lieu), `maxDistance` et `beacons` (UUID, major, minor). Google reçoit `merchantLocations` (10 max) sur la classe et sur chaque carte. Google n'accepte pas de texte par lieu : il affiche sa propre notification.

## Activer la rubrique pour la Barge

La rubrique « Carte fidélité » n'apparaît que si elle est listée dans les rubriques de l'organisateur :

```bash
php artisan organizer:sections 24 --only=dashboard,qr-codes,loyalty
```

(adapter la liste aux rubriques déjà ouvertes pour la Barge).

## Ce que Jo doit fournir

Aucun secret ne va dans le code, dans la PR ou dans la mémoire d'Anicka. Les fichiers et mots de passe vont seulement dans `/etc/picha-ticket/` sur le serveur, lus par les variables d'environnement ci-dessous.

### 1. Apple

1. Un compte Apple Developer (99 € par an) au nom de DIGIT.
2. Dans Certificates, Identifiers & Profiles : un **Pass Type ID**, par exemple `pass.fr.picha.fidelite`.
3. Le **certificat** de ce Pass Type ID, exporté en `.p12` avec un mot de passe.
4. Le **Team ID** (10 caractères, visible en haut à droite du compte développeur).

Variables :

```
MOBILE_PASS_APPLE_ORGANIZATION_NAME="PICHA Ticket"
MOBILE_PASS_APPLE_TYPE_IDENTIFIER=pass.fr.picha.fidelite
MOBILE_PASS_APPLE_TEAM_IDENTIFIER=XXXXXXXXXX
MOBILE_PASS_APPLE_CERTIFICATE_PATH=/etc/picha-ticket/wallet/pass.p12
MOBILE_PASS_APPLE_CERTIFICATE_PASSWORD=...
MOBILE_PASS_APPLE_WEBSERVICE_SECRET=<32 caractères aléatoires, généré sur le serveur>
MOBILE_PASS_APPLE_WEBSERVICE_HOST=https://ticket.picha.fr/api
```

Le web service (`/api/passkit/v1/...`) permet à l'iPhone de recevoir les nouveaux tampons. Sans `WEBSERVICE_SECRET` et `WEBSERVICE_HOST`, la carte s'installe mais ne se met plus à jour.

### 2. Google

1. Un compte émetteur **Google Wallet** (Google Pay & Wallet Console, gratuit), puis la demande d'accès en production.
2. Un projet Google Cloud avec l'API Google Wallet activée et un **compte de service** : sa clé JSON.
3. Ajouter l'e-mail du compte de service comme utilisateur dans la console Wallet.
4. L'**Issuer ID** (numéro affiché dans la console Wallet).

Variables :

```
MOBILE_PASS_GOOGLE_ISSUER_ID=3388000000000000000
MOBILE_PASS_GOOGLE_KEY_PATH=/etc/picha-ticket/wallet/google-service-account.json
```

Les notifications de proximité Google demandent une validation de Google sur la classe de carte : la première carte créée l'envoie en revue.

### 3. La Barge

- **Coordonnées GPS exactes** de l'entrée du restaurant (latitude, longitude avec 5 à 7 décimales), par exemple en appuyant longuement sur l'entrée dans Google Maps.
- Le message d'écran verrouillé souhaité (120 caractères max), par exemple « Votre carte est prête, à tout de suite au comptoir ».
- Le logo en PNG carré, hébergé en https (le logo du site convient).

### 4. Balise Bluetooth (facultatif, iPhone)

Si Jo achète une balise iBeacon (Kontakt.io, Minew, etc.) : son **UUID**, et si besoin ses numéros **major** et **minor**. Sans balise, la localisation GPS suffit.

## Mise en production

1. Fusionner la PR, déployer, lancer `php artisan migrate` (tables `loyalty_programs`, `loyalty_cards` et tables `mobile_passes` du paquet).
2. Déposer les fichiers Apple et Google dans `/etc/picha-ticket/wallet/` (droits 640, propriétaire du service PHP), remplir les variables, vider le cache de configuration.
3. Ouvrir la rubrique pour l'organisateur 24, régler le programme et cocher « Les clients peuvent ajouter la carte ».
4. Remplacer le bloc « Bientôt » du site laterrassedelabarge.yt par un lien vers la page publique.

Tant que les comptes Apple et Google ne sont pas branchés, l'inscription fonctionne et l'écran d'administration l'indique ; les boutons Wallet restent masqués.
