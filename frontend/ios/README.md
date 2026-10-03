# PICHA Kiosk — application iPad

Application iOS (iPad uniquement) qui embarque le Kiosk de ticket.picha.fr
(Capacitor 8). Le code web du Kiosk est empaqueté dans l'app et l'app parle
à l'API de production `https://ticket.picha.fr/api`.

Ce que l'app apporte par rapport au Kiosk dans Safari (décision D19d) :

- **Impression Zebra depuis l'iPad.** Le serveur de production ne peut pas
  joindre une imprimante sur le Wi-Fi d'un lieu. L'app demande le ZPL au
  serveur (`POST /events/{id}/attendees/{publicId}/zpl`), puis l'envoie
  elle-même à la Zebra en TCP 9100.
- **AirPrint** pour le mode PDF.
- Plein écran, icône PICHA, session conservée sur l'appareil.

## Développement

```bash
cd frontend
yarn install
yarn ios:sync    # build:app (dist-app) + cap sync ios
yarn ios:open    # ouvre le projet dans Xcode (Mac uniquement)
```

- Les valeurs du bundle sont dans `frontend/.env.app` : uniquement des
  valeurs publiques, jamais de secret (vite les inline dans le bundle).
- Le plugin natif est dans `ios/App/App/PichaPrinterPlugin.swift`.
- `ios/App/App/public` et `capacitor.config.json` sont regénérés par
  `cap sync` : ne pas les modifier à la main.

## Build sans Mac : GitHub Actions

Le workflow `.github/workflows/ios-kiosk.yml` tourne sur un runner macOS :

- **à chaque push** touchant le Kiosk : compile l'app pour le simulateur
  iPad, sans signature. Ça vérifie que tout compile, sans compte Apple ;
- **à la demande** (Actions → iOS Kiosk app → Run workflow → cocher
  « Signer et envoyer le build sur TestFlight ») : archive signée et envoi
  sur App Store Connect.

## Mise en ligne sur l'App Store : procédure

### 1. Compte Apple Developer (≈ 99 €/an)

Pour publier au nom de PICHA plutôt qu'à ton nom personnel :

1. Obtenir un **numéro D-U-N-S** pour la société (gratuit, via la page
   d'Apple « D-U-N-S Number Lookup », compter jusqu'à 2 semaines).
2. Créer un Apple ID au nom de la société et activer la double
   authentification (un iPhone ou un iPad suffit).
3. S'inscrire sur developer.apple.com/programs/enroll → **Organization**.
4. Noter le **Team ID** (Membership details, 10 caractères).

### 2. App Store Connect

1. **Certificates, Identifiers & Profiles → Identifiers** : créer l'App ID
   `fr.picha.ticket.kiosk`.
2. **App Store Connect → Apps → +** : nouvelle app iOS
   - nom : « PICHA Kiosk » ;
   - langue principale : français ;
   - bundle ID : `fr.picha.ticket.kiosk` ;
   - SKU : `picha-kiosk`.
3. **Users and Access → Integrations → App Store Connect API** :
   - générer une clé avec le rôle **Admin** (nécessaire pour la signature
     automatique) ;
   - télécharger le `.p8` (une seule fois possible) ;
   - noter le **Key ID** et l'**Issuer ID**.

### 3. Secrets GitHub

Dans le dépôt GitHub, aller dans Settings → Secrets and variables →
Actions et créer :

| Secret | Valeur |
|---|---|
| `APPLE_TEAM_ID` | Team ID |
| `APP_STORE_CONNECT_API_KEY_ID` | Key ID |
| `APP_STORE_CONNECT_ISSUER_ID` | Issuer ID |
| `APP_STORE_CONNECT_API_KEY_P8_BASE64` | contenu du `.p8` encodé : `base64 -i AuthKey_XXXX.p8` |

### 4. TestFlight

1. Lancer le workflow avec « Signer et envoyer le build sur TestFlight ».
2. Le build apparaît dans App Store Connect → TestFlight après 10 à
   30 minutes de traitement.
3. Ajouter les testeurs internes et installer **TestFlight** sur l'iPad.
4. Tester en conditions réelles au guichet :
   - vente, puis impression sur la Zebra ;
   - à la première impression, iOS demande l'accès au **réseau local** :
     il faut accepter ;
   - impression PDF en AirPrint ;
   - réimpression ;
   - déconnexion puis reconnexion.

### 5. Soumission à Apple

Dans App Store Connect, remplir la fiche de l'app :

- **Captures d'écran iPad 13"** (2064×2752 ou 2752×2064) : écran de
  connexion, vente, réglages imprimante.
- **URL de politique de confidentialité** : la page légale de
  ticket.picha.fr.
- **URL d'assistance.**
- **Confidentialité (App Privacy).** Données collectées :
  - nom et e-mail des acheteurs saisis au guichet ;
  - identifiants de l'opérateur.

  Aucune donnée n'est utilisée pour du suivi publicitaire.
- **Informations pour la vérification (App Review)** :
  - fournir un **compte opérateur de démonstration**, sur un événement de
    test avec des billets à 0 € ;
  - expliquer qu'il s'agit d'un outil de billetterie professionnel pour le
    guichet des organisateurs PICHA, qui imprime sur des imprimantes Zebra
    du réseau local.

  Sans compte de démo, Apple refuse l'app (règle 2.1).
- **Catégorie** : Business.
- **Chiffrement** : déjà déclaré dans l'app (`ITSAppUsesNonExemptEncryption`
  = false, HTTPS uniquement).

> Si l'app ne doit servir qu'aux organisateurs PICHA, Apple propose aussi la
> distribution **Unlisted** (app publique mais accessible seulement par
> lien). Cette option est à demander après une première validation.

## Côté serveur

- CORS : l'app appelle l'API depuis l'origine `capacitor://localhost`.
  Si `CORS_ALLOWED_ORIGINS` est restreint en production, il faut l'y
  ajouter (voir `docker/production/backend.env.production.example`).
- L'app s'authentifie en `Authorization: Bearer` (JWT valable 7 jours,
  `JWT_TTL`). À l'expiration, l'opérateur est renvoyé sur l'écran de
  connexion du Kiosk.
