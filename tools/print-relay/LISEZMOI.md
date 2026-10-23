# Relais d'impression PICHA Ticket

## À quoi ça sert

Quand le kiosque PICHA Ticket tourne dans un navigateur, le serveur PICHA (sur internet) ne peut pas joindre l'imprimante Zebra du lieu, qui est sur le réseau local (par exemple 192.168.1.21).

Le relais est un petit programme qui tourne sur un ordinateur du lieu, branché sur le même réseau que la Zebra. Toutes les une ou deux secondes, il demande à PICHA Ticket s'il y a des billets à imprimer, puis il les envoie à la Zebra.

Le kiosque peut tourner sur cet ordinateur ou sur un autre appareil : seul le relais doit être sur le réseau de l'imprimante.

Rien n'est installé sur le serveur : si le relais est arrêté, les billets attendent simplement dans la file.

## 1. Installer Python 3

### Sur Mac

1. Ouvrir le Terminal (Applications, puis Utilitaires, puis Terminal).
2. Taper `python3 --version` puis Entrée.
3. Si un numéro de version s'affiche (3.8 ou plus), Python est déjà là.
4. Sinon, télécharger Python sur https://www.python.org/downloads/ et l'installer.

### Sur Windows

1. Télécharger Python sur https://www.python.org/downloads/
2. Lancer l'installateur et **cocher la case "Add Python to PATH"** avant de cliquer sur Install.
3. Ouvrir l'Invite de commandes (touche Windows, taper `cmd`, Entrée).
4. Vérifier avec `python --version`.

Sur Windows, remplacez `python3` par `python` dans toutes les commandes ci-dessous.

Aucun autre module n'est nécessaire.

## 2. Récupérer le fichier du relais

Copier le fichier `picha_print_relay.py` sur l'ordinateur, par exemple sur le Bureau.

## 3. Trouver l'adresse IP de la Zebra

1. Allumer la Zebra et la brancher au réseau (câble ou Wi-Fi).
2. Imprimer l'étiquette de configuration : sur la plupart des modèles ZD, maintenir le bouton d'avance papier (FEED) quelques secondes jusqu'à ce que le voyant clignote, puis relâcher. Sur une ZD621 avec écran, l'adresse IP s'affiche aussi dans le menu Réseau.
3. Lire la ligne "IP ADDRESS" (par exemple 192.168.1.21).
4. Vérifier que l'ordinateur est connecté au même réseau (même box ou même Wi-Fi).

Tester l'imprimante, sans jeton :

```
python3 picha_print_relay.py --printer 192.168.1.21 --test
```

Une petite étiquette "PICHA Ticket, Test du relais d'impression" doit sortir.

## 4. Obtenir le jeton du relais

1. Dans le kiosque, ouvrir Réglages puis Impression.
2. Choisir "Zebra via relais (ordinateur)".
3. Cliquer sur "Générer un jeton de relais" (il faut un compte organisateur de l'événement, pas un compte guichetier).
4. Copier le jeton, ou directement la ligne de commande affichée.

Le jeton n'est affiché qu'une seule fois. Si vous le perdez, générez-en un nouveau : l'ancien cesse alors de fonctionner, il faudra relancer le relais avec le nouveau.

Un jeton correspond à un seul événement. Pour un autre événement, générez un autre jeton.

## 5. Lancer le relais

Dans le Terminal (ou l'Invite de commandes), aller dans le dossier du fichier :

```
cd Desktop
```

Puis lancer :

```
python3 picha_print_relay.py --token VOTRE_JETON --printer 192.168.1.21
```

Chaque billet imprimé affiche une ligne, par exemple :

```
14:02:11 Billet A-WTUCJLC imprime sur 192.168.1.21:9100
```

Laisser la fenêtre ouverte pendant tout l'événement. Pour arrêter le relais, appuyer sur Ctrl et C en même temps.

Options utiles :

* `--printer 192.168.1.21:9100` pour préciser un autre port (9100 par défaut).
* `--interval 1.5` pour changer le délai entre deux vérifications, en secondes.
* `--url https://ticket.picha.fr/api` pour changer l'adresse du serveur (déjà la bonne par défaut).
* Le jeton peut aussi être mis dans la variable d'environnement `PICHA_RELAY_TOKEN` au lieu de `--token`.

Conseil : désactiver la mise en veille de l'ordinateur pendant l'événement.

## 6. Dépannage

**"Jeton refuse par le serveur"** : le jeton est faux ou a été remplacé. Générer un nouveau jeton dans les réglages du kiosque et relancer la commande.

**"Echec impression billet ... Connection refused" ou "timed out"** : l'ordinateur n'arrive pas à joindre la Zebra.

* Vérifier que la Zebra est allumée, avec du papier, capot fermé.
* Vérifier l'adresse IP sur l'étiquette de configuration (elle peut changer après un redémarrage de la box).
* Vérifier que l'ordinateur est sur le même réseau que la Zebra.
* Tester avec `--test`.

Un billet qui échoue est retenté automatiquement, 3 essais au total. Ensuite il est abandonné : le réimprimer depuis le kiosque (onglet Commandes, bouton Réimprimer).

**"Probleme reseau : serveur injoignable"** : pas d'internet sur l'ordinateur. Le relais réessaie tout seul, rien à faire une fois la connexion revenue.

**Rien ne s'imprime mais aucune erreur** : vérifier dans le kiosque que l'impression est bien réglée sur "Zebra via relais (ordinateur)", et que le relais a été vu récemment ("Dernière connexion du relais" dans les réglages). Le nombre de billets en attente doit redescendre à zéro.

**"python3 n'est pas reconnu"** (Windows) : utiliser `python` à la place, ou réinstaller Python en cochant "Add Python to PATH".

**Un seul relais par événement** : ne pas lancer deux relais avec le même jeton, sinon certains billets pourraient sortir en double.

**L'étiquette est coupée ou décalée** : régler la résolution et le format d'étiquette dans les réglages Impression du kiosque, puis réimprimer.
