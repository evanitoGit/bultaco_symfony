# Compte-rendu de sécurité

**Date :** 5 novembre 2026
**Code testé :** branche `dev_feature_securite` (contient Mon garage et la page par modèle)
**Conclusion :** aucune des 80 attaques tentées ne permet d'accéder aux données, aux comptes ou aux paiements. Les 3 seuls défauts trouvés sont corrigés sur cette branche.

## Méthode

- **Environnement :** copie du site en mode production (`APP_ENV=prod`, `APP_DEBUG=0`), servie par le serveur PHP intégré, avec MariaDB et une base de test.
- **Outils :**
  - [sqlmap](https://sqlmap.org/) : outil spécialisé dans la recherche d'injections SQL ;
  - un script d'attaques écrit pour le site (80 tests) ;
  - `composer audit` pour les bibliothèques ;
  - une relecture du code.
- **Déroulement :** les attaques ont été lancées une première fois sur le code d'origine, puis une seconde fois après les corrections.

## Résultats

| Type d'attaque | Tests | Avant correction | Après correction |
|---|---|---|---|
| Injection SQL | 14 + sqlmap | aucune faille | aucune faille |
| Injection de code JavaScript (XSS) | 6 | aucune faille | aucune faille |
| Accès aux données des autres et à l'administration | 18 | aucune faille | aucune faille |
| Envoi de fichiers piégés | 6 | **1 faille** | corrigée |
| Actions forcées depuis un autre site (CSRF) | 6 | aucune faille | aucune faille |
| Paiement et prix | 5 | aucune faille | aucune faille |
| Connexion et sessions | 7 | aucune faille | aucune faille |
| Fichiers sensibles et fuites d'informations | 16 | **2 failles** | corrigées |
| Injection dans les e-mails | 2 | aucune faille | aucune faille |
| **Total** | **80** | **77 bloquées, 3 failles** | **80 bloquées** |

## Détail des attaques

### Injection SQL

- **sqlmap** a testé des milliers de variantes (booléennes, par erreur, `UNION`, requêtes empilées, « à retardement ») sur :
  - `/recherche?q=` et `/recherche/suggestions?q=` ;
  - `/boutique/pieces?moto=`, `/boutique/pieces/vues?ids=`, `/boutique/selection/liste?ids=` ;
  - `/boutique/demande?pieces=&moto=&piece=` ;
  - le numéro de pièce (`/boutique/pieces/{id}-…`) et de modèle (`/modeles/{id}-…`) ;
  - `/compte/verifier-email?email=&empreinte=` ;
  - le formulaire de connexion (`email`, `password`).

  Résultat : aucun paramètre injectable.
- **À la main :**
  - se connecter sans mot de passe avec `' OR '1'='1' --`, `jean@example.com' --`, `" OR ""="` et `' OR 1=1#`, côté client comme côté admin ;
  - voler les mots de passe avec `UNION SELECT username,password … FROM admins` ;
  - effacer les pièces avec `'; DROP TABLE pieces_detachees; --` ;
  - attaque « à retardement » avec `SLEEP(3)` ;
  - champs du garage et quantité du panier piégés.

  Résultat : tout est refusé et la base est intacte.

**Pourquoi c'est sûr :**
- Les valeurs envoyées par les visiteurs sont toujours transmises à la base séparément de la requête (requêtes préparées de Doctrine DBAL), jamais collées dedans.
- Les noms de tables utilisés par l'admin sont écrits en dur dans `ResourceRegistry`. Une valeur inconnue dans l'adresse donne une erreur 404.

### Injection de code JavaScript (XSS)

- On a envoyé du code `<script>alert("xss")</script>` et `<img src=x onerror=alert(1)>` :
  - dans la recherche et le filtre « Ma moto » (renvoyés directement dans la page) ;
  - dans des champs enregistrés puis affichés plus tard : petit nom d'une moto du garage, prénom et nom à l'inscription, demande de pièce lue dans l'admin.
- Résultat : le code s'affiche toujours comme du simple texte, car Twig neutralise ces caractères avant affichage. Les rares `|raw` du site ne laissent passer que des valeurs déjà neutralisées avec `|e`.

### Accès aux données des autres et à l'administration

- **Accès à l'admin :**
  - un visiteur qui ouvre `/admin` est renvoyé vers la connexion ;
  - un client connecté est refusé sur `/admin/clients`, `/admin/commandes` et la modification d'une pièce, ainsi que sur la suppression d'une pièce ;
  - une requête `HEAD` (contournement connu de certaines versions de Symfony) est refusée.
- **Données des autres clients :**
  - une cliente ne peut pas ouvrir la commande d'un autre client (404), ni ses pages de paiement (`/paiement/{référence}/retour`, `relancer`, `autre-mode`, `annule`) ;
  - les photos des demandes de pièces sont réservées aux admins ;
  - l'export des données personnelles ne contient que les données du client connecté.
- **Remontée de dossiers :** `../../.env` dans l'adresse des photos ne donne rien.
- **Références de commande :** elles sont aléatoires (`BCF-261105-4F2A9C`) et la propriété de chaque commande est vérifiée.

### Envoi de fichiers piégés

| Fichier envoyé | Résultat |
|---|---|
| `shell.php` (script PHP) | refusé |
| `image.php.jpg` (script déguisé en image) | refusé |
| `gif-php.php` (vraie image GIF avec du PHP caché dedans) | enregistré en `.gif`, jamais exécuté |
| `piege.html` (page web déguisée en image) | refusé |
| `piege.svg` (image SVG contenant du code) | **accepté et servi tel quel : faille corrigée** |
| `.php` joint à une demande de pièce | enregistré en `.gif` hors du dossier public, jamais exécuté |

### Actions forcées depuis un autre site (CSRF)

Sans jeton de sécurité valide, rien ne passe :
- modifier son profil ;
- ajouter une moto au garage ;
- ajouter au panier ;
- passer commande ;
- supprimer une pièce dans l'admin ;
- changer l'ordre d'affichage dans l'admin.

### Paiement et prix

- **Fausses notifications :** une notification Stripe « commande payée » est refusée sans signature, avec une signature inventée ou signée avec une autre clé. La signature HMAC-SHA256 et son horodatage sont vérifiés.
- **Quantités :** les valeurs négatives, nulles, décimales ou énormes sont ramenées entre 1 et le stock disponible.
- **Prix :** il ne vient jamais du navigateur. Il est toujours relu en base.

### Connexion et sessions

- **Force brute :** après 5 essais ratés, le compte est bloqué 15 minutes, même si le sixième essai est le bon mot de passe.
- **Session :** son identifiant change à la connexion, pour empêcher qu'on impose une session à quelqu'un (fixation de session). Le cookie de session est `HttpOnly` et `SameSite`.
- **Mot de passe oublié :** la réponse est la même, que l'adresse e-mail ait un compte ou non. On ne peut donc pas deviner les adresses des clients.
- **Lien de réinitialisation :** un lien inventé est refusé.
- **Redirection :** impossible de renvoyer vers un site pirate après la connexion (`?retour=`, `_target_path`).

### Fichiers sensibles et fuites d'informations

- **Fichiers inaccessibles :** `/.env`, `/.env.local`, `/composer.json`, `/composer.lock`, `/var/log/prod.log`, `/config/…`, `/.git/config`, `/_profiler`, `/_wdt`, `/src/Kernel.php`, liste du dossier `/uploads/`.
- **Erreurs :** une erreur provoquée n'affiche aucune trace technique.
- **En-têtes de sécurité :** ils étaient absents. **Faille corrigée.**
- **Version de PHP :** elle était annoncée (`X-Powered-By: PHP/8.3.6`). **Faille corrigée.**

### Injection dans les e-mails

- **Dans le nom :** un retour à la ligne suivi de `Bcc: victime@example.com` ne crée pas de destinataire caché. Le nom est neutralisé dans l'en-tête de l'e-mail.
- **Dans l'adresse e-mail :** la même tentative est refusée par le formulaire.

## Corrections apportées

1. **Mise à jour de Symfony** de 7.4.20 à 7.4.23, avec ses dépendances. `composer audit` ne signale plus aucune faille.
2. **En-têtes de sécurité sur toutes les pages** (`src/EventListener/EnTetesSecuriteListener.php`) :

   | En-tête | Rôle |
   |---|---|
   | `X-Frame-Options: SAMEORIGIN` | interdit d'afficher le site dans le cadre d'un autre site |
   | `X-Content-Type-Options: nosniff` | le navigateur ne devine pas le type d'un fichier |
   | `Referrer-Policy: strict-origin-when-cross-origin` | l'adresse complète des pages n'est pas transmise aux autres sites |
   | `Permissions-Policy` | caméra, micro, localisation et paiement par le navigateur désactivés |
   | `Content-Security-Policy: frame-ancestors 'self'; object-src 'none'; base-uri 'self'` | pas de cadre externe, pas de plugins, pas de détournement des liens relatifs |
   | `Strict-Transport-Security` (en HTTPS seulement) | le navigateur ne repasse jamais par HTTP |

   L'en-tête `X-Powered-By` (version de PHP) est retiré.
3. **Images envoyées** (`ImageUploader`, `AdminResourceType`, `DemandePieceType`) :
   - seuls JPG, PNG, WEBP et GIF sont acceptés ; le SVG est refusé ;
   - l'extension du fichier enregistré vient de son contenu réel, jamais du nom donné par l'expéditeur.
4. **`public/uploads/.htaccess`** (serveur Apache) : aucun script n'est exécuté ni servi depuis le dossier des images, et la liste du dossier est désactivée.

Après les corrections, les 124 tests de bout en bout du site passent toujours (garage, page modèle, sélection, ordre dans l'admin, paiement). L'envoi d'images dans l'admin fonctionne, et aucune page n'affiche d'erreur JavaScript.

## Limites

- **Serveur :** les tests ont été faits sur le serveur PHP intégré, pas sur l'hébergeur final. Le `.htaccess` n'a pas pu être testé, faute d'Apache.
- **Saturation :** les attaques par saturation (déni de service) n'ont pas été testées. C'est le rôle de l'hébergeur.
- **Comptes admin :** ils sont considérés comme sûrs. Il faut leur choisir des mots de passe longs et uniques.

## À faire au moment de la mise en ligne

- [ ] `APP_ENV=prod` et `APP_DEBUG=0`
- [ ] un nouvel `APP_SECRET`, car celui de `.env.dev` est public sur GitHub
- [ ] un vrai mot de passe pour la base de données, dans `.env.local` sur le serveur et jamais dans Git
- [ ] le site en HTTPS
- [ ] les clés Stripe réelles et le secret du webhook de production
- [ ] `expose_php = Off` dans le `php.ini` du serveur
- [ ] des mots de passe admin longs et uniques
- [ ] lancer `composer audit` régulièrement (par exemple une fois par mois) et mettre Symfony à jour quand une faille est signalée
