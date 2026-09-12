# AUDIT DU PROJET — SMS_ORANGE (état au 2026-09-08)

Ce document est le livrable de la **Phase 1** du cahier des charges SMS_ORANGE V2.0 : audit complet du code existant avant toute réécriture. Aucune suppression n'a été effectuée à ce stade.

---

## 1. Architecture actuelle

Application PHP procédurale (pas de framework, pas d'autoloading applicatif — seul `vendor/autoload.php` de Composer est utilisé pour la lib Orange).

```
sms_orange/
├── app/
│   ├── index.php              -> shell HTML (sidebar/header), routeur par $_GET['page']
│   └── templete/               -> fragments de vue, mélangent HTML + accès DB direct
│       ├── dashboard.php       (envoi SMS unique + liste campagnes factice)
│       ├── groupe.php / detail-groupe.php
│       ├── campagne.php / detail-campagne.php / campagne-list.php (doublon)
│       ├── contacts.php        (données 100% statiques/factices)
│       ├── sms-sender.php      (données 100% statiques/factices)
│       └── sendMarksheets.php  (envoi des résultats — cœur métier actuel)
├── server/
│   ├── config.php              -> connexion PDO + client Orange + TOUTES les fonctions de requête (God file)
│   ├── app.php                 -> tous les handlers POST (create_group, import_csv, add_contact,
│   │                              single-sender, create_campagne, importMessage_csv) dans un seul fichier
│   ├── infosAPI.php            -> synchronise solde/statut/historique Orange dans $_SESSION
│   ├── layout.php              -> fonction ListGroupe() (rendu HTML depuis PHP, couplage fort)
│   ├── index.php               -> test de connexion autonome vers la base "school" (voir §5)
│   ├── index.html              -> page Apache2 "It works" par défaut, oubliée
│   ├── sms_log.txt             -> log texte brut, vide actuellement
│   ├── credit.php, viaCsv.php, lot.php, send_sms.php, send.php  -> scripts autonomes redondants (voir §4)
├── pages/
│   ├── login-v1.html, register-v1.html  -> HTML statique, jamais connecté au backend
├── assets/                     -> thème admin Bootstrap 5 "Gradient Able" (codedthemes), 100% front statique
├── vendor/                     -> Composer (mediumart/orange-sms + Guzzle/PSR-7)
├── composer.json               -> une seule dépendance déclarée
└── .env                        -> présent mais VIDE (aucune variable chargée nulle part, aucun lib dotenv)
```

**Flux d'envoi SMS actuel (fonctionnel) :**
`app/templete/dashboard.php` (formulaire) → POST `server/app.php` (`single-sender`) → validation regex `+224|00224` + `6\d{8}` → `$sms->message()->from()->to()->send()` (SDK `mediumart/orange-sms`, instancié dans `config.php`) → redirection avec message flash en session.

**Flux d'import (fonctionnel) :**
CSV `Nom,Telephone,email` → `server/app.php` (`import_csv`) → insertion `contacts` + `groupe_contacts` (groupe codé en dur `:group => 1`).

**Flux "résultats scolaires" (fonctionnel, cœur métier actuel) :**
CSV `telephone,message,matricule,notes,niveau` importé via `app.php` (`importMessage_csv`) → une ligne par (destinataire, matricule) est insérée dans `messages`, `campagne_id` obligatoire. `app/templete/sendMarksheets.php` regroupe ensuite via `STRING_AGG(...)` par `(destinataire, matricule, niveaux)` pour reconstituer un message composite, l'affiche dans un modal pré-rempli, et l'envoi repasse par le handler générique `single-sender` de `app.php`.
→ Il n'existe **aucune table `students`/`grades`/`sessions`**. Les "résultats" sont un texte libre stocké dans `messages.contenu` + `messages.notes`, sans lien avec un système scolaire structuré.

---

## 2. Structure réelle de la base de données (vérifiée en direct)

Deux configurations DB coexistent dans le code, avec des rôles très différents :

### `apiSms` (PostgreSQL, user `postgres`) — **la base réellement utilisée par l'application**
Connexion : `server/config.php`, fonction `PDO()`. Contenu vérifié :

| Table | Rôle | Lignes (au 2026-09-08) |
|---|---|---|
| `groupes` | groupes de contacts | 1 |
| `contacts` | contacts (nom, téléphone, email) | 3296 |
| `groupe_contacts` | liaison N-N groupe↔contact | 3296 |
| `campagne` | campagnes (nom, dates, statut) | 0 |
| `messages` | SMS à envoyer, `campagne_id` NOT NULL, colonnes `matricule/notes/niveaux` ajoutées après coup pour les résultats scolaires | 0 |
| `sms` | table "SMS envoyé" (sender_name, message, statut, utilisateur_id) — **jamais utilisée par le code applicatif** | 0 |
| `sms_destinataires` | destinataires d'un envoi groupé, avec `retour_api jsonb` — **jamais utilisée par le code applicatif** | 0 |
| `utilisateurs` | id, nom, email, mot_de_passe, **role**, date_creation — **table d'auth prête en base mais aucun code ne la lit/écrit** | 0 |

Constat clé : la base contient déjà une ébauche de modèle plus avancé (`sms`, `sms_destinataires`, `utilisateurs` avec rôle) qui a été commencée puis abandonnée au profit du couple `campagne`/`messages`, plus simple mais sans granularité par destinataire (pas de tracking individuel succès/échec, pas d'idempotence, pas de retry).

### `school` (PostgreSQL, user `userdbSchool`) — **inaccessible, isolée**
Connexion : `server/index.php` (script autonome, appelé nulle part ailleurs dans l'app). Test de connexion effectué : **échec d'authentification** (mot de passe invalide pour `userdbSchool`). Ce fichier ne fait qu'afficher "✅/❌ Connexion" et n'a aucune fonction — c'est un script de test isolé, probablement un essai de connexion au futur/existant système scolaire (matricules, notes, classes) qui n'a jamais été branché au reste de l'application. Aucune donnée scolaire structurée n'existe donc actuellement : tout passe par le texte libre dans `messages`.

**Aucun index** au-delà des clés primaires n'a été trouvé sur `contacts.telephone`, `messages.campagne_id`, `messages.matricule` — problématique dès que le volume dépasse quelques milliers de lignes (recherche de doublon de téléphone = scan séquentiel sur 3296 lignes à chaque insertion).

---

## 3. Dépendances

- **Déclarée** (`composer.json`) : `mediumart/orange-sms: ^2.0` uniquement.
- **Résolues via composer.lock/vendor** : guzzlehttp/guzzle + psr7 + psr/http-* + symfony/deprecation-contracts + ralouphie/getallheaders — toutes des dépendances transitives de Guzzle, rien d'inutile ici.
- **Frontend** : 100% CDN (jQuery, DataTables 1.13.5 + Buttons, AlertifyJS, Google Fonts Poppins) + assets locaux du thème Bootstrap "Gradient Able" (Apex Charts, jsvectormap, Feather/Tabler/FontAwesome icons — chargés globalement même quand non utilisés sur la page courante).
- **`.env`** présent mais vide et **non lu** : aucune librairie dotenv (vlucas/phpdotenv ou autre) n'est installée ni requise. Sa seule existence dans le repo aujourd'hui est trompeuse (donne l'illusion d'une config par env alors que tout est en dur).

---

## 4. Audit des scripts historiques (`server/*.php`)

| Fichier | Statut | Constat |
|---|---|---|
| `server/config.php` | **À CONSERVER (à découper)** | Connexion DB + client Orange + 12 fonctions métier. Utilisé par `app.php`, `layout.php`, toutes les vues. Point d'entrée central, mais viole la séparation des responsabilités (§49 du cahier des charges). |
| `server/app.php` | **À CONSERVER (à découper)** | Seul point d'entrée POST réellement utilisé par l'UI actuelle (tous les formulaires pointent vers lui). Contient un bloc mort commenté (l. 184-205, 309-359) à supprimer. |
| `server/infosAPI.php` | **UTILISÉ** | Appelé par `app/index.php`, uniquement quand `page=dashdoards`. Fait 4 appels API Orange synchrones à chaque chargement du dashboard (pas de cache) — problème de performance/latence (§27, §51). |
| `server/layout.php` | **À CONSERVER (à migrer)** | Une seule fonction (`ListGroupe`) qui produit du HTML depuis PHP — à remplacer par une vraie vue/template. |
| `server/index.php` | **OBSOLETE** | Script de test de connexion à une base `school` inexploitable (mauvais mot de passe), non inclus par aucun autre fichier. Candidat à la suppression après confirmation qu'aucune intégration "school" n'est prévue à court terme. |
| `server/index.html` | **OBSOLETE** | Page par défaut Apache2 "It works", aucune valeur, à supprimer. |
| `server/credit.php` | **DUPLICATE / OBSOLETE** | Script CLI autonome (ré-instancie son propre client Orange avec des placeholders `<client_id>`) pour tester solde + stats + envoi. Fait doublon avec `infosAPI.php` et `config.php`. Non lié à l'UI. |
| `server/viaCsv.php` | **DUPLICATE / OBSOLETE** | Envoi CSV en CLI (placeholders), non lié à l'UI, fait doublon fonctionnel avec `app.php`/`importMessage_csv` et avec `send_sms.php`. |
| `server/lot.php` | **DUPLICATE / OBSOLETE** | Envoi par lots de 500 avec `usleep(200000)` entre chaque SMS — bonne intuition (rate limiting, log fichier) mais placeholders `<client_id>`, non lié à l'UI, et l'approche "boucle synchrone dans un seul script" est justement ce que le cahier des charges interdit (§5) pour 10 000+ étudiants. À garder comme référence d'algorithme de rate-limiting, pas comme code de prod. |
| `server/send_sms.php` | **UTILISÉ (partiellement)** | Cible réelle du formulaire "Créer une campagne" (`app/templete/campagne.php` et `campagne-list.php`, action `../server/send_sms.php`). Contient les **identifiants Orange en dur** (les mêmes que `config.php`). Boucle synchrone `while(fgetcsv)` avec `usleep(200000)` — bloque le navigateur le temps de l'envoi complet, viole directement §5/§34 pour un volume important. Log dans `sms_log.txt` à la racine de `server/` (chemin relatif fragile selon le CWD du process PHP). |
| `server/send.php` | **DUPLICATE / OBSOLETE** | Endpoint POST minimal (`numero`,`message`) avec identifiants en dur et un numéro expéditeur différent (`+224627447348` vs `+224620000000` ailleurs) — aucune vue ne pointe dessus. Probablement un script de test laissé dans le repo. |

**Doublons de logique d'envoi identifiés** : `app.php` (single-sender), `send_sms.php`, `send.php`, `lot.php`, `viaCsv.php`, `credit.php` réimplémentent chacun leur propre instanciation `SMSClient`/`SMS`, avec des identifiants copiés-collés en dur à 4 endroits différents. C'est le problème n°1 à corriger avant toute nouvelle fonctionnalité (§28, §29).

**Vue doublon** : `app/templete/campagne-list.php` vs `campagne.php` — la première semble être un brouillon abandonné (données 100% factices, aucune référence dans le routeur `app/index.php`, donc **jamais rendue**). Candidate à la suppression.

**Pages jamais routées** (liens présents dans le sidebar mais sans handler dans `app/index.php`) :
- `?page=rapports` → aucun `require_once` correspondant, page blanche.
- `?page=notes` fonctionne mais le lien s'appelle "Liste des Notes" alors que le contenu réel gère l'envoi des résultats (`sendMarksheets.php`) — incohérence de nommage UX.
- `templete/404.php`, référencé comme fallback dans `app/index.php` ligne ~313, **n'existe pas** → tout accès sans `?page=` produit une `Warning: require_once(...): Failed to open stream`.

---

## 5. Problèmes de sécurité (par gravité)

1. **CRITIQUE — Secrets en clair dans le code versionné** : `client_id`/`client_secret` Orange (identiques dans `config.php`, `app.php` implicitement via `require`, `send_sms.php`, `send.php`, `credit.php`, `viaCsv.php`, `lot.php`) + mot de passe PostgreSQL (`stratus05@1993`, présent en clair dans `config.php` **et** `server/index.php`) sont committés dans le dépôt git. **Recommandation immédiate : régénérer le client_secret Orange et le mot de passe PostgreSQL dès que possible**, indépendamment de la refonte — ces secrets sont compromis dès l'instant où ils sont dans l'historique git.
2. **ÉLEVÉ — Aucune authentification** : `app/index.php` n'a aucun contrôle de session/login. Les pages `pages/login-v1.html` et `register-v1.html` sont des maquettes HTML statiques non connectées ; n'importe qui accédant à l'URL peut créer des campagnes, importer des contacts et envoyer des SMS (donc consommer le solde Orange payant).
3. **ÉLEVÉ — Pas de protection CSRF** sur les formulaires POST (`create_group`, `single-sender`, `create_campagne`, imports CSV) : un simple lien/formulaire externe pourrait déclencher un envoi de SMS ou une création de campagne au nom de l'admin connecté.
4. **MOYEN — Upload CSV non validé** : `import_csv`/`importMessage_csv` acceptent tout fichier envoyé sous le nom `csvFile` sans vérifier l'extension, le type MIME ni la taille ; le contenu est lu ligne à ligne sans limite (`fgetcsv` sans borne réelle de nombre de lignes).
5. **MOYEN — Pas d'échappement XSS systématique** : la plupart des vues font `echo $variable` brut (`dashboard.php`, `groupe.php` via `ListGroupe()`) sans `htmlspecialchars`, alors que certaines données viennent d'imports CSV utilisateur (nom, description). `detail-groupe.php`/`detail-campagne.php` échappent parfois (`htmlspecialchars`) et parfois non (incohérence).
6. **MOYEN — Injection potentielle via en-tête HTTP** : tous les handlers de `app.php` font `header("Location: " . $_SERVER['HTTP_REFERER'])` sans validation — un `Referer` forgé pourrait rediriger vers un site externe (open redirect), et `HTTP_REFERER` peut être absent (notice PHP).
7. **FAIBLE — Erreurs PDO affichées à l'écran** (`echo "❌ Erreur de connexion : " . $e->getMessage()`) : fuite d'information (nom d'hôte, structure) en cas d'erreur, à réserver aux logs.
8. **FAIBLE — Numéro expéditeur incohérent** : `+224620000000` (app.php), `+224627447348` (send.php), `SCOL_UGLCS` (lot.php) — aucune source de vérité unique pour l'identité d'expéditeur validée chez Orange.

---

## 6. Problèmes d'architecture

- **Aucune séparation des responsabilités** : `config.php` mélange connexion DB, client API, et 12 fonctions métier de requête (SRP violée, §49).
- **Couplage vue/données fort** : les templates (`app/templete/*.php`) appellent directement des fonctions de requête (`getGroupes()`, `getCampagne()`, `getMessageSenderMarksheet()`) — pas de couche service/repository, aucun moyen de tester la logique sans un serveur web + une base réelle.
- **God functions à effet de bord** : `getMessageSenderMarksheet()` fait un `STRING_AGG` global sans pagination — dès que la table `messages` contient des dizaines de milliers de lignes, la page `notes` chargera tout en mémoire d'un coup (§33, §34 explicitement visés).
- **Pas de couche d'abstraction Orange** : le SDK `mediumart/orange-sms` est instancié directement dans 7 fichiers différents avec des identifiants dupliqués — remplacer de fournisseur ou changer une politique de retry demanderait de modifier 7 fichiers.
- **Pas de file d'attente / traitement par lot** : chaque "envoi de campagne" est une boucle PHP synchrone dans la requête HTTP (`send_sms.php`), strictement l'anti-pattern documenté en §5 du cahier des charges — pour 10 000 étudiants, timeout HTTP quasi garanti (le `php.ini` XAMPP par défaut a `max_execution_time=30s`, largement insuffisant avec `usleep(200ms)` × 10 000 = 2000s).
- **Pas d'idempotence** : rien n'empêche de ré-importer/ré-envoyer deux fois le même message (aucune contrainte unique sur `(campagne_id, matricule)` ou équivalent dans `messages`).
- **Groupe codé en dur** : tout contact importé est rattaché au `groupe_id = 1` en dur dans `app.php` (l.84, l.135), qu'importe le groupe réellement sélectionné dans le formulaire d'import (`$_POST['group_id']` est lu mais jamais utilisé pour la liaison réelle) — **bug fonctionnel actif**, pas seulement une dette technique.

## 7. Problèmes de performance

- Recherche de doublon de téléphone (`phoneExiste`) = requête sans index sur 3296 lignes à chaque insertion CSV, en boucle → O(n×m) pour un import.
- `infosAPI.php` fait 4 appels réseau synchrones vers l'API Orange à **chaque** chargement de `?page=dashdoards`, sans cache ni décision de fréquence de sync (§27 demande une notion de "dernière synchronisation").
- Aucune pagination nulle part : `getGroupes()`, `getCampagne()`, `getMessageSenderMarksheet()` chargent l'intégralité de la table, DataTables ne fait que du rendu côté client sur un jeu déjà entièrement chargé dans le HTML.
- Boucle d'envoi bloquante (`send_sms.php`, `lot.php`) : latence perçue = somme des latences API Orange, aucun parallélisme ni file d'attente.

## 8. Problèmes UX/UI

- Le thème "Gradient Able" est un template générique de démo (liens "Buy now" vers `developer.orange.com`, footer "crafted by Codedthemes", logo par défaut) — aucune identité "SMS_ORANGE" (§67).
- Données factices affichées comme si elles étaient réelles : `campagne-list.php`, `contacts.php`, `sms-sender.php` montrent des lignes "Jacqueline Howell / PNG002156" codées en dur — trompeur pour un utilisateur réel.
- KPI dashboard partiellement câblés : "SMS envoyé" vient de l'API Orange (réel), mais "SMS Livré" (1641), "SMS non livré" (562), "Taux de réussite" (562) sont des **constantes codées en dur** dans `app/templete/dashboard.php` — donnent une fausse impression de suivi.
- Aucun état vide/chargement/erreur cohérent (§41) : listes vides affichent un tableau sans lignes sans message explicite ; pas de spinner pendant l'envoi.
- Incohérence de nommage : menu "Liste des Notes" → fonctionnalité réelle = "Envoyer les résultats scolaires par SMS" (fonctionnalité n°1 du produit cible, actuellement la moins mise en avant dans l'UI).
- Pas de confirmation avant actions critiques (§39) : la création de groupe/campagne et l'envoi simple n'ont pas de `alertify.confirm` côté serveur (le seul `alertify.confirm` existant, dans `assets/js/ajax.js`, cible un formulaire `#formGroupe` qui n'existe dans **aucune** vue actuelle — code mort/orphelin).

---

## 9. Recommandations (ordre de priorité pour la suite)

1. **Sécurité immédiate, hors refonte** : régénérer le secret Orange et le mot de passe PostgreSQL maintenant qu'ils sont identifiés comme exposés dans l'historique git ; ne pas attendre la fin du chantier.
2. **Phase Config/Sécurité** : introduire `vlucas/phpdotenv`, un seul point de config (`config/env.php`), supprimer les 7 duplications d'identifiants, corriger le bug du `groupe_id` codé en dur.
3. **Phase Service Orange** : encapsuler le SDK dans `OrangeSmsService` (authenticate/getBalance/sendSms/getStatistics/getHistory), avec cache du solde et timeouts explicites.
4. **Phase Base de données** : conserver `apiSms` comme unique source de vérité pour l'app SMS ; concevoir un schéma cible additif (campaigns/campaign_recipients/sms_logs) sans supprimer `contacts`/`groupes` existants (3296 contacts réels à préserver) ; ajouter les index manquants (`telephone`, `campaign_id`, `status`).
5. **Phase Moteur d'envoi massif** : remplacer les boucles synchrones (`send_sms.php`, `app.php`) par le modèle campagne → destinataires → traitement par lot avec verrouillage (`FOR UPDATE SKIP LOCKED`), seule façon de tenir la charge visée (10 000+ SMS) sans timeout HTTP.
6. **Nettoyage différé** : archiver (ne pas supprimer immédiatement) `credit.php`, `viaCsv.php`, `lot.php`, `send.php`, `server/index.php`, `server/index.html`, `campagne-list.php` une fois leur logique utile absorbée dans les nouveaux services — aucun de ces fichiers n'est actuellement lié à une vue active, sauf `send_sms.php` (à migrer en priorité car c'est le point d'entrée réel du bouton "Créer une campagne").
7. **Auth/rôles** : la table `utilisateurs` existe déjà en base avec un champ `role` — elle peut servir de socle direct à la Phase Authentification sans migration de schéma supplémentaire.

---

*Prochaine étape : Phase 2 — Architecture cible détaillée, schéma de base de données cible, et démarrage de la Phase 4 (Sécurité/Configuration), comme convenu dans le plan de migration.*

---

# JOURNAL DES PHASES

## Phase 4 — Sécurité / Configuration (terminée le 2026-09-08)

**Objectif** : supprimer les identifiants en dur, centraliser la connexion DB et le client Orange, corriger le bug fonctionnel du `groupe_id` figé.

**Fichiers créés :**
- `.env` (rempli avec les vraies valeurs, non versionné désormais), `.env.example` (gabarit versionné), `.gitignore`
- `config/bootstrap.php` — charge `.env` via `vlucas/phpdotenv`, expose `env()`
- `config/database.php` — expose `db()` : connexion PDO **unique et réutilisée** (remplace l'ancienne `PDO()` qui ouvrait une nouvelle connexion à chaque requête SQL)
- `config/orange.php` — expose `orangeSms()` : instance partagée de `OrangeSmsService`
- `src/Services/OrangeSmsService.php` — encapsule le SDK `mediumart/orange-sms` (§28) avec **cache du token d'accès** sur disque (`storage/cache/orange_token.json`, 60s de marge avant expiration) pour ne plus ré-authentifier à chaque envoi (§52)
- `storage/cache/`, `storage/logs/` (structure de dossiers prête pour la suite)

**Fichiers modifiés :**
- `server/config.php` — suppression des identifiants Orange/PostgreSQL en dur ; `PDO()` délègue maintenant à `db()` (conservée pour compatibilité, aucune fonction métier existante n'a changé de signature)
- `server/app.php` — envoi simple routé via `orangeSms()->sendSms()` ; **correction du bug** où tout contact importé (CSV ou formulaire) était systématiquement rattaché à `groupe_id = 1` au lieu du groupe réellement sélectionné ; les contacts déjà existants (même téléphone) sont maintenant rattachés au groupe via leur `contact_id` réel au lieu de générer un `contact_id` fantôme jamais inséré ; suppression des blocs de code mort commentés (§66)
- `server/infosAPI.php` — utilise `orangeSms()->getBalance()/getHistory()/getTotalSmsSent()` au lieu du SDK direct
- `server/send_sms.php` (cible réelle du bouton "Créer une campagne") — identifiants en dur supprimés, envoi routé via `orangeSms()->sendSms()`. **Non résolu à ce stade** : la boucle reste synchrone et bloquante (`usleep` par SMS dans la requête HTTP) — sera remplacé par le moteur de campagnes en file d'attente (Phase 6, priorité suivante).
- `composer.json` — ajout de `vlucas/phpdotenv`, autoload PSR-4 `App\` → `src/`

**Fichiers non touchés à dessein** (dette technique documentée, pas encore corrigée) : `server/credit.php`, `server/viaCsv.php`, `server/lot.php`, `server/send.php`, `server/index.php`, `server/index.html`, `app/templete/campagne-list.php` — toujours identifiés comme obsolètes/doublons (§4), à archiver en Phase 13 (nettoyage) une fois toute logique utile absorbée.

**Modifications DB** : aucune. Le schéma `apiSms` n'a pas été touché (aucune table créée/modifiée) — cette phase ne concernait que la couche application.

**Risques identifiés** :
- Le fichier `storage/cache/orange_token.json` contient un token d'accès Orange valide temporairement : ajouté au `.gitignore`, mais à surveiller si l'app est un jour déployée en environnement multi-utilisateur (le cache est actuellement partagé par fichier, pas par utilisateur — cohérent avec un usage mono-tenant actuel).
- `.env` a été retiré du suivi git (`git rm --cached`) mais **reste dans l'historique** du dépôt (commit "first commit 02082025") avec les anciennes valeurs vides — sans impact puisqu'il était vide, mais les secrets eux-mêmes (qui étaient dans `config.php`/`send_sms.php`/etc., pas dans `.env`) restent, eux, dans l'historique. Confirme la recommandation déjà faite : **régénérer le secret Orange et le mot de passe PostgreSQL**.

**Tests réalisés** :
1. `php -l` sur les 8 fichiers créés/modifiés → aucune erreur de syntaxe.
2. Script de fumée (`db()` singleton, `getGroupes()`, `orangeSms()->getBalance()`) exécuté en CLI → connexion DB OK (1 groupe réel retrouvé), même instance PDO réutilisée, authentification Orange + lecture de solde OK.
3. Serveur PHP intégré démarré sur le vrai code (`app/index.php?page=dashdoards`) → **HTTP 200, aucun warning/notice/fatal**, KPI "SMS envoyé" affiche la vraie valeur (209) obtenue via l'API Orange.

**⚠️ Constat opérationnel découvert pendant les tests (indépendant du code)** : l'appel réel à l'API Orange renvoie `"status": "EXPIRED"` avec `"expirationDate": "2025-12-11"` et `"availableUnits": 906`. **Le contrat SMS Orange actuel est expiré** alors que l'application affiche encore un solde disponible — à vérifier/renouveler auprès d'Orange avant toute campagne réelle, indépendamment de la suite du chantier.

**Résultat** : la fonctionnalité existante (envoi simple, import CSV contacts, dashboard, solde Orange) continue de fonctionner à l'identique pour l'utilisateur, sans secret en dur dans le code applicatif actif, avec une connexion DB et un client Orange désormais centralisés et réutilisables par les phases suivantes.

*Prochaine étape : Phase 6 — Moteur d'envoi massif (campagnes → destinataires → file d'attente par lots), qui remplacera la boucle synchrone de `send_sms.php` et posera le socle du module "Résultats académiques" (fonctionnalité n°1 du produit).*

## Phase 6 — Moteur d'envoi massif (terminée le 2026-09-08)

**Objectif** : remplacer la boucle synchrone bloquante (`send_sms.php`) par le modèle campagne → destinataires → file d'attente par lots décrit en §5-§9, sans exiger Redis ni serveur de queue dédié (§54), en restant déployable tel quel sous XAMPP.

**Fichiers créés :**
- `database/migrations/001_campaign_engine.sql` + `database/migrate.php` — mini-runner de migrations (une table `schema_migrations`, aucun framework imposé, §48). **Migration appliquée sur `apiSms` avec ton accord explicite** : uniquement des `ADD COLUMN IF NOT EXISTS` / `CREATE INDEX IF NOT EXISTS`, aucune suppression, les 3296 contacts et le groupe existant sont intacts.
- `src/Services/PhoneNumberService.php` — normalisation des numéros guinéens (622xxxxxx / +224622xxxxxx / 00224622xxxxxx → +224622xxxxxx), rejette tout ce qui n'est pas un mobile guinéen valide (§10).
- `src/Services/CampaignQueueService.php` — cœur du moteur : `createCampaign`, `addRecipients` (validation + idempotence via `unique_key`, §7), `queueCampaign`/`pause`/`resume`/`cancel`/`retryFailed` (§20-§21), `claimBatch` (`SELECT ... FOR UPDATE SKIP LOCKED`, §55, transaction courte — jamais ouverte pendant l'envoi réel, §53), `processRecipient` (envoi + classification d'erreur §8 + décision de retry §9), `getProgress` (bascule automatique QUEUED→RUNNING→COMPLETED/PARTIAL, §18-§19).
- `config/services.php` — expose `campaignQueue()`.
- `server/campaign_worker.php` — endpoint appelé en boucle par le navigateur (polling AJAX) : traite **un seul lot** par appel et rend la main, donc n'bloque jamais la requête HTTP (§5) ; c'est la solution "pas de vraie queue disponible" prévue par le §54.
- `bin/process-campaign.php` — worker CLI pour un vrai déploiement (cron / Planificateur de tâches Windows, §79) : `php bin/process-campaign.php <id>` vide une campagne, `--daemon` traite en continu toute campagne QUEUED/RUNNING.

**Modifications DB** : voir migration ci-dessus. Colonnes ajoutées sur `campagne` (type, total_destinataires, nombre_envoyes, nombre_echecs, batch_size, created_by, date_lancement, date_completion, dry_run) et sur `messages` (unique_key, tentative_count, error_code, error_message, locked_at, date_traitement, provider_message_id) + index sur `contacts.telephone`, `messages.campagne_id+statut`, `messages.matricule`.

**`getSingleCampagne()` modifiée** (`server/config.php`) pour faire `SELECT *` au lieu d'une liste de colonnes figée — sinon les nouvelles colonnes (dry_run, batch_size...) restaient invisibles au reste du code. Sans impact sur les appelants existants (ils ne lisaient que des clés qui existent toujours).

**Non fait à ce stade (volontairement)** : aucune UI n'utilise encore ce moteur — `send_sms.php` et les vues de campagne actuelles n'ont pas été branchées dessus. C'est la prochaine étape logique (Phase 7/8 : brancher le formulaire de campagne + le module résultats académiques sur `CampaignQueueService` au lieu de l'ancien chemin direct).

**Tests réalisés (avec de vraies requêtes contre `apiSms`, en mode `dry_run` pour ne jamais consommer le solde Orange expiré) :**
1. Normalisation téléphone : 6 formats valides + 2 invalides (dont un numéro ivoirien +225 pour vérifier le rejet hors-Guinée) → tous corrects.
2. Cycle de vie complet d'une campagne de 27 lignes (25 valides, 1 invalide, 1 doublon exact) : `addRecipients` → added=25/duplicates=1/invalid=1 ; traitement en 3 lots (taille 10) → statut bascule DRAFT→QUEUED→RUNNING→COMPLETED automatiquement.
3. Re-import strictement identique → added=0/duplicates=26 (idempotence confirmée par l'index unique).
4. Deux `claimBatch()` consécutifs sur une file vide → 0 et 0 (pas de double traitement).
5. Worker CLI (`bin/process-campaign.php <id>`) sur une campagne de 7 puis 8 destinataires avec `batch_size=5` → traite en plusieurs passes, log de progression correct, s'arrête proprement une fois `COMPLETED`.
6. Worker HTTP (`server/campaign_worker.php`) interrogé via de vraies requêtes `curl` sur un serveur PHP réel → réponses JSON correctes à chaque poll, campagne complétée en 2 appels, les appels suivants ne retraitent rien.
7. **Bug trouvé et corrigé pendant les tests** : `getSingleCampagne()` ne remontait pas les nouvelles colonnes → `Undefined array key "dry_run"` dans le worker CLI. Corrigé en passant la requête en `SELECT *`.
8. Toutes les données de test (5 campagnes `type='test'`, 77 destinataires factices) supprimées après validation, avec ton accord — aucune donnée réelle touchée.

**Risques identifiés** :
- Le worker AJAX dépend du navigateur resté ouvert sur la page de progression ; si l'admin ferme l'onglet en plein envoi, la campagne reste `RUNNING` avec des destinataires `en_attente` — reprenable sans perte (relancer un poll ou le worker CLI reprend exactement où c'était), mais aucune UI ne le fait encore automatiquement. Le worker CLI (`--daemon`) est la solution robuste pour une vraie mise en production et ne dépend d'aucun onglet ouvert.
- `claimBatch` utilise `FOR UPDATE SKIP LOCKED`, disponible depuis PostgreSQL 9.5 — à vérifier que la version en production le supporte (quasi certain, mais non vérifié ici faute d'accès à `SELECT version()` testé explicitement).

**Résultat** : le socle du moteur d'envoi massif est fonctionnel et testé de bout en bout (CLI et HTTP), sans dépendance nouvelle (pas de Redis), déployable tel quel sous XAMPP, avec un chemin de mise à niveau documenté vers un vrai worker cron pour la production.

*Prochaine étape : Phase 7/8 — brancher ce moteur sur une vraie interface de campagne (sélection session/niveau/classe/semestre comme décrit en §3-§4, écran de progression en temps réel) et sur le module "Résultats académiques", en remplacement des vues actuelles à données factices.*

## Phase 7/8 — Interface de campagne + module Résultats académiques (terminée le 2026-09-08)

**Objectif** : brancher le moteur de la Phase 6 sur une vraie interface (fonctionnalité n°1 du cahier des charges, §3-§4), en remplaçant les chemins directs vers `send_sms.php` par le cycle DRAFT → import → lancement → suivi en temps réel.

**Modèle retenu pour les "résultats académiques"** : la base actuelle n'a ni table `students` ni colonnes session/classe/programme/semestre (voir audit §2) — seul un champ `niveaux` existe réellement, alimenté par le CSV. Plutôt que d'ajouter des filtres sur des colonnes qui n'existent pas (ça aurait recréé le problème "données factices" déjà relevé en §8 de l'audit), la sélection du §3 est faite sur ce qui est réellement disponible : **niveau réel + session/semestre saisis en texte libre**, qui deviennent le nom/la description de la campagne. Une vraie table `students`/`sessions` reste une évolution possible si un référentiel scolaire structuré existe un jour (voir §61 du cahier des charges) — non fabriquée ici.

**Deux campagnes désormais distinctes, volontairement** :
1. **Campagne "brute"** (créée via "Créer une campagne") : reçoit l'import CSV `telephone,message,matricule,notes,niveau` tel quel — une ligne CSV = une ligne `messages` (peut être plusieurs lignes par étudiant, ex. une par matière).
2. **Campagne "consolidée"** (créée automatiquement par "Préparer la campagne" depuis l'écran Résultats) : une ligne = un étudiant, message composite obtenu via `STRING_AGG` (logique déjà existante, réutilisée). C'est cette campagne consolidée qui est réellement mise en file d'attente et envoyée.

**Fichiers modifiés :**
- `app/templete/sendMarksheets.php` — ajout d'un filtre par niveau, d'un aperçu (§3-§4 : nombre d'étudiants, numéros valides/invalides, coût SMS estimé) et du bouton "Préparer la campagne" avec confirmation avant action critique (§39).
- `app/templete/detail-campagne.php` — réécrite : bouton "Lancer l'envoi" (DRAFT→QUEUED), pause/reprise/annulation (§20), barre de progression en temps réel par polling JS sur `campaign_worker.php` (§6), rapport final avec bouton "Réessayer les échecs" (§21), modal d'import CSV conservée (ciblant désormais la campagne courante). L'ancien bloc `$group['id']` mort et cassé (variable jamais définie dans ce fichier) a été supprimé au passage.
- `app/templete/campagne.php` — suppression du mini-formulaire qui postait directement vers `send_sms.php` (contournait tout le moteur, sans idempotence ni suivi) ; la création de campagne passe uniquement par le modal existant, qui route maintenant vers `CampaignQueueService::createCampaign()`.
- `server/app.php` — nouveaux handlers `prepare_resultats_campagne`, `launch_campagne`, `pause_campagne`, `resume_campagne`, `cancel_campagne`, `retry_campagne_failures` ; `create_campagne` route désormais vers le moteur au lieu d'un `INSERT` direct (sinon les campagnes créées via ce formulaire restaient au statut `en_attente`, invisible pour le nouveau moteur qui attend `DRAFT`) ; normalisation téléphone (`PhoneNumberService`) appliquée à l'import CSV de résultats, qui faisait auparavant une concaténation `'+224'.$numero` aveugle (cassait tout numéro déjà préfixé).
- `server/config.php` — `getMessageCampagne()` remonte maintenant aussi `matricule`, `error_code`, `error_message`, `tentative_count`, `date_traitement` pour alimenter le journal (§19).
- `app/index.php` — correction de 4 warnings PHP pré-existants (`Undefined array key totalSmsSend/soldeSms/dateExpiration/status`) qui s'affichaient sur **toutes** les pages autres que le dashboard, depuis toujours (les variables de session ne sont peuplées que sur `?page=dashdoards`) — sans lien avec cette phase mais trouvé et corrigé en testant chaque page.
- `database/migrations/002_campaign_status_default.sql` — corrige la valeur par défaut de `campagne.statut` (`en_attente` → `DRAFT`) pour rester cohérent si un `INSERT` direct était fait un jour. **Appliquée sur `apiSms`** (additive, sans risque).

**Bug trouvé et corrigé pendant les tests** : `CampaignQueueService::getProgress()` ne faisait passer une campagne à `COMPLETED`/`PARTIAL` que si elle était déjà `RUNNING` — une campagne qui se termine en un seul lot (cas courant pour une petite classe, ex. moins de 50 étudiants avec la taille de lot par défaut) restait bloquée au statut `QUEUED` indéfiniment alors que tous les SMS étaient bien envoyés. Corrigé pour accepter `QUEUED` ou `RUNNING` comme état de départ.

**Tests réalisés (vraies requêtes HTTP contre un serveur PHP réel et la base `apiSms`, en mode dry-run) :**
1. Création de campagne via le formulaire réel (`create_campagne`) → redirection correcte vers l'écran détail, statut `DRAFT`.
2. Import d'un CSV réel de résultats (4 lignes valides dont 2 pour le même étudiant, 1 ligne avec numéro invalide) → la ligne invalide est rejetée à l'import (avant, elle aurait été insérée avec un numéro cassé) ; les 3 destinataires valides apparaissent dans le journal de la campagne "brute".
3. Écran Résultats académiques → aperçu correct (3 étudiants, 3 numéros valides, 3 SMS estimés — la fusion Mathématiques+Physique du même étudiant en un seul message composite fonctionne).
4. "Préparer la campagne" → nouvelle campagne consolidée créée avec exactement 3 destinataires (un par étudiant, pas un par ligne CSV).
5. Lancement en dry-run + polling réel du endpoint HTTP → progression 0→3, statut bascule bien jusqu'à `COMPLETED` (après correction du bug ci-dessus).
6. Toutes les données de test (campagnes 9, 10, 11 et leurs destinataires factices) supprimées après validation, avec ton accord — `contacts` (3296), `groupes` (1), `groupe_contacts` (3296) intacts et vérifiés après coup.

**Non fait à ce stade** : protection CSRF sur ces nouveaux formulaires (le reste de l'app n'en a pas non plus — reste une dette de sécurité globale, cf. audit §5.3) ; le worker AJAX dépend d'un onglet navigateur ouvert (le worker CLI `bin/process-campaign.php` est l'alternative robuste déjà livrée en Phase 6) ; le chemin d'échec réel (appel Orange qui échoue vraiment) n'a pas été testé contre l'API réelle pour éviter tout risque financier étant donné le contrat expiré déjà signalé — la logique de classification d'erreurs a été relue mais pas exercée en conditions réelles.

**Résultat** : la fonctionnalité n°1 du cahier des charges (envoi des résultats académiques par SMS) est maintenant un vrai flux de bout en bout — import, consolidation par étudiant, aperçu chiffré avant envoi, file d'attente par lots, suivi en temps réel, rapport final, réessai des échecs — au lieu de l'ancien clic manuel "un SMS à la fois" par étudiant.

*Prochaine étape suggérée : Phase 9/10 (tableau de bord avec graphiques réels, identité visuelle propre) ou Phase 37 (authentification — la table `utilisateurs` est prête depuis la Phase 1) selon la priorité que tu souhaites donner.*

## Phase 37/38 — Authentification et rôles (terminée le 2026-09-08)

**Objectif** : combler la faille de sécurité la plus grave identifiée en Phase 1 (§5.2) — l'application était accessible sans aucune authentification, donc n'importe qui avec l'URL pouvait envoyer des SMS payants ou importer des données.

**Fichiers créés :**
- `src/Services/AuthService.php` — authentification par session sur la table `utilisateurs` déjà présente en base (créée à l'origine, jamais utilisée) : `attempt()` (vérifie `password_verify`, régénère l'ID de session contre la fixation de session), `logout()`, `check()`, `user()`, `hasRole()`, `requireLogin()`.
- `config/auth.php` — expose `auth()`.
- `app/login.php` / `app/logout.php` — page de connexion (formulaire simple, pas de dépendance au thème générique) et déconnexion.
- `bin/create-user.php` — provisionnement des comptes en ligne de commande. **Aucune inscription publique n'a été exposée, volontairement** : c'est un outil interne qui envoie des SMS payants, pas un SaaS grand public — les comptes `pages/login-v1.html`/`register-v1.html` (jamais connectés, cf. audit §1) restent inertes et seront traités en phase de nettoyage.

**Fichiers modifiés :**
- `app/index.php` — `auth()->requireLogin('login.php')` avant tout rendu ; le menu utilisateur affiche désormais le vrai nom/rôle connecté et un lien de déconnexion fonctionnel, au lieu du menu factice du template ("Download", "Add account", liens vers `codedthemes.com`).
- `server/app.php` — protégé par `requireLogin()` **et** un contrôle de rôle : seuls `SUPER_ADMIN`/`ADMIN`/`OPERATOR` peuvent déclencher les actions de ce fichier (il ne fait que des mutations : création, import, envoi) ; un `VIEWER` reçoit un 403.
- `server/campaign_worker.php` — protégé par `auth()->check()` (401 JSON si non connecté) — sans ça, n'importe qui connaissant un `campagne_id` aurait pu déclencher l'envoi réel des lots depuis l'extérieur.

**Rôles** (`AuthService::ROLES`) : `SUPER_ADMIN`, `ADMIN`, `OPERATOR`, `VIEWER`, conformes au §38. Le contrôle est appliqué au niveau fichier pour `server/app.php` (toutes les mutations) — un contrôle plus fin par action (ex. seul `ADMIN`+ peut annuler une campagne, `OPERATOR` peut seulement lancer/importer) reste à affiner si le besoin se précise ; documenté ici comme limite connue plutôt que fait silencieusement.

**Tests réalisés (vraies requêtes HTTP, serveur réel)** :
1. Accès non authentifié à `app/index.php`, `server/app.php` (POST) et `server/campaign_worker.php` → redirigés/bloqués (302 vers login, 401 JSON) comme attendu.
2. Mauvais mot de passe → message d'erreur affiché, pas de session créée.
3. Bon mot de passe → session créée, accès au dashboard, nom/rôle réels affichés dans l'en-tête, zéro warning PHP.
4. Déconnexion → session détruite, accès de nouveau bloqué.
5. Compte `VIEWER` → bloqué avec un 403 explicite sur une tentative d'envoi de SMS, confirmant que le contrôle de rôle fonctionne et pas seulement le contrôle de connexion.

**Compte créé pour toi, à changer** : `admin@test.local` / `TempPass1234` (rôle `SUPER_ADMIN`) — c'est un compte de test que tu as demandé pour valider le flux immédiatement. **Change cet email/mot de passe dès que possible** avec :
```
php bin/create-user.php "Ton Nom" tonemail@example.com "UnMotDePasseFort" SUPER_ADMIN
```
puis supprime `admin@test.local` (`DELETE FROM utilisateurs WHERE email = 'admin@test.local'`) une fois ton vrai compte créé.

**Non fait à ce stade** : pas de "mot de passe oublié", pas de verrouillage après tentatives échouées répétées (brute-force), pas de CSRF token sur le formulaire de login lui-même (moins critique qu'ailleurs car pas de session préalable à détourner) — dette de sécurité mineure documentée plutôt que résolue silencieusement.

**Résultat** : l'application n'est plus accessible sans identifiants ; les actions d'envoi/import sont réservées aux rôles habilités ; le compte de test permet de se connecter dès maintenant.

*Prochaine étape : Phase 9/10 (tableau de bord + graphiques réels) ou nettoyage des scripts obsolètes — poursuite autonome comme demandé.*

## Phase 11/12 — Tableau de bord et graphiques réels (terminée le 2026-09-08)

**Objectif** : éliminer les données factices affichées comme réelles, identifiées en Phase 1 (§8) — les KPI "SMS Livré" (1641), "SMS non livré" (562), "Taux de réussite" (562, qui était même en unités de SMS et pas un pourcentage) étaient des constantes codées en dur dans `dashboard.php`, et la liste "Listes des campagnes" affichait une ligne e-commerce factice ("Jacqueline Howell / PNG002156").

**Fichiers modifiés :**
- `server/config.php` — 3 nouvelles fonctions : `getGlobalSmsStats()` (comptage réel envoyés/échecs/en attente + taux de réussite calculé, à partir de `messages`), `getSmsEvolution($days)` (série temporelle des envois réussis par jour, jours manquants comblés à 0 pour un graphique continu), `getCampaignPerformance($limit)` (réussis/échecs des dernières campagnes).
- `app/index.php` — les 3 cartes KPI (hors "SMS envoyé" qui vient déjà réellement de l'API Orange depuis la Phase 4) utilisent maintenant `getGlobalSmsStats()` au lieu de constantes.
- `app/templete/dashboard.php` — réécrit : 3 vrais graphiques **ApexCharts** (déjà chargé par le thème, aucune nouvelle dépendance CDN ajoutée) — évolution des envois (line chart, §12), répartition envoyés/échecs/en attente (donut, §12), performance des dernières campagnes (bar chart, §12) — plus une vraie liste des campagnes récentes (au lieu de la ligne factice) et l'envoi rapide conservé.

**Tests réalisés** :
1. Rendu à vide (base sans aucun message/campagne, état réel actuel) → 0/0/0%, graphiques affichés sans erreur JS ni warning PHP (état vide correctement géré, §41).
2. **Avec ton accord**, injection de données de test réalistes (1 campagne, 17 envoyés + 3 échecs répartis sur 10 jours) → KPI corrects (17/3/85%), série temporelle de 14 jours cohérente (somme = 17, jours sans envoi à 0), graphique de performance avec les bonnes valeurs. Toutes les données de test supprimées après vérification.

**Résultat** : le dashboard reflète maintenant l'activité réelle de l'application (et non plus un template de démonstration e-commerce) ; le jour où de vraies campagnes seront lancées, ces graphiques se rempliront automatiquement sans autre changement de code.

*Prochaine étape : nettoyage des scripts obsolètes identifiés en Phase 1 (§4).*

## Phase 13 — Nettoyage (terminée le 2026-09-08)

**Objectif** : traiter la liste des fichiers `OBSOLETE`/`DUPLICATE` identifiée en Phase 1 (§4), une fois leur logique utile absorbée par le nouveau moteur (§65-§66).

**Vérification avant tout déplacement** : `grep` sur `app/` et `server/` pour chaque fichier candidat, confirmant qu'aucune vue ni script actif ne le référence encore.

**Fichiers archivés** (déplacés vers `archive/` avec `git mv`, historique git préservé — rien supprimé, voir `archive/README.md`) :
`server/credit.php`, `server/viaCsv.php`, `server/lot.php`, `server/send.php`, `server/index.php`, `server/index.html`, `app/templete/campagne-list.php`, `pages/login-v1.html`, `pages/register-v1.html`.

**Décision prise pendant cette phase** : `server/send_sms.php` (corrigé en Phase 4, encore actif à l'époque) n'était plus référencé par aucune vue depuis que `app/templete/campagne.php` a été mis à jour en Phase 7/8 (le mini-formulaire qui pointait dessus a été retiré) — confirmé par grep, puis archivé également. Sa logique (boucle par lots avec pause, journalisation) est désormais entièrement portée par `CampaignQueueService`.

**Bug supplémentaire trouvé et corrigé pendant le balayage complet des pages** : `app/templete/sms-sender.php` avait le même bug que `detail-campagne.php` avant sa réécriture — un modal "Envoyer un message au groupe" référençant une variable `$group` jamais définie (`Undefined variable $group`), donc non fonctionnel à l'exécution. Corrigé : le sélecteur de groupe est maintenant alimenté par les vrais groupes (`getGroupes()`), et le bouton crée + lance une vraie campagne via `campaignQueue()` (nouveau handler `send_to_group` dans `server/app.php`) au lieu de router vers le handler `single-sender` (qui attend un numéro, pas un groupe, et aurait simplement échoué silencieusement). La fausse liste "Jacqueline Howell" de cette page a aussi été remplacée par la vraie liste de groupes.

**Trouvé en passant** : `server/test.csv`, un fichier de test contenant un **vrai numéro de téléphone guinéen et un message réel** (mentionnant un concurrent, nimbasms.com), non référencé par aucun code, oublié dans le dépôt. Archivé également — à garder à l'esprit pour l'hygiène des données (§63 : ne pas laisser traîner de données personnelles dans le code versionné).

**Tests réalisés** :
1. `php -l` sur l'intégralité des fichiers actifs (`app/`, `server/`, `config/`, `src/`, `bin/`, `database/`) après déplacement → aucune erreur.
2. Toutes les routes de l'application (`dashdoards`, `Groupes`, `campgagne`, `Contacts`, `notes`, `sms-sender`, `rapports`) chargées via un vrai serveur HTTP, en étant connecté → 200 partout, **zéro warning PHP** sur toutes les pages (avant cette phase, `sms-sender.php` en avait 4).
3. `send_to_group` **volontairement pas exercé en conditions réelles** : le seul groupe existant contient les 3296 vrais contacts — lancer un envoi réel de test aurait créé une campagne ciblant la totalité du carnet d'adresses. Le code réutilise exactement les mêmes fonctions (`createCampaign`, `addRecipients`, `queueCampaign`) déjà validées de bout en bout en Phase 6/7 sur des campagnes de test ; revu par relecture plutôt qu'exécuté ici par prudence.

**`page=rapports`** reste une page blanche (le lien existe dans le menu mais `app/index.php` n'a toujours pas de `require_once` correspondant) — non traité dans cette phase, car construire un vrai module de rapports (§44) est un chantier à part entière, pas un nettoyage ; le lien du menu pourrait être retiré en attendant si tu préfères ne pas laisser une page vide accessible.

**Résultat** : 10 fichiers obsolètes ou cassés retirés du chemin actif (archivés, pas perdus), un bug fonctionnel supplémentaire corrigé, toutes les pages de l'application repassées en revue une à une sans erreur.

## Phase 36 (partielle) — Protection CSRF (terminée le 2026-09-08)

**Objectif** : combler la faille §5.3 de l'audit — aucun des 15 formulaires POST de l'application n'avait de jeton CSRF, donc n'importe quelle page externe aurait pu forcer un admin connecté à créer un groupe, importer des contacts, envoyer un SMS ou annuler une campagne à son insu.

**Fichiers créés** : `config/csrf.php` — `csrf_token()` (génère/réutilise un jeton en session), `csrf_field()` (input caché à insérer dans chaque formulaire), `csrf_verify()` (comparaison `hash_equals`, résistante au timing attack).

**Fichiers modifiés** :
- `config/services.php` — charge `config/csrf.php`.
- `server/app.php` — vérifie `csrf_verify()` sur toute requête POST (juste après le contrôle de rôle), rejette avec un code `419` explicite sinon.
- Les 15 formulaires POST vers `server/app.php`, répartis dans `dashboard.php`, `groupe.php`, `detail-groupe.php`, `campagne.php`, `detail-campagne.php` (×6), `sendMarksheets.php` (×2), `sms-sender.php` (×2) — chacun reçoit désormais `<?= csrf_field() ?>`.
- **Corrigé au passage** : les 20 occurrences de `header("Location: " . $_SERVER['HTTP_REFERER'])` (audit §5.6 : warning PHP si l'en-tête est absent, et open-redirect possible si un `Referer` externe était forgé) remplacées par une nouvelle fonction `redirectBack()` dans `server/config.php`, qui vérifie que le `Referer` pointe bien vers le même hôte avant de l'utiliser, avec un repli sûr vers `app/index.php` sinon.

**Tests réalisés (vraies requêtes HTTP)** :
1. POST sans jeton `_csrf` → `419` explicite, requête bloquée.
2. Récupération du vrai jeton depuis une page réellement rendue, POST avec ce jeton → passe (redirection normale, pas de 419).
3. POST valide sans en-tête `Referer` (cas réel : certains navigateurs/proxys le suppriment) → plus de warning PHP, redirection vers le repli.
4. POST avec un `Referer` forgé vers un domaine externe (`evil.example.com`) → redirection forcée vers `app/index.php`, jamais vers le domaine externe.
5. Toutes les pages de l'application rechargées une dernière fois → toujours zéro warning.

**Non fait** : jeton CSRF sur le formulaire de login lui-même (risque moindre : pas de session à détourner avant authentification) ; rotation du jeton après usage (actuellement un seul jeton par session, valable pour toute sa durée — suffisant contre le CSRF classique mais pas contre un jeton qui fuiterait par ailleurs).

**Résultat** : les 15 formulaires de mutation de l'application sont protégés contre les soumissions forgées depuis un site tiers, et le mécanisme de redirection est à la fois plus robuste (pas de warning) et plus sûr (pas d'open-redirect).

*Bilan à ce stade : audit ✅, sécurité/config ✅, moteur de campagnes ✅, interface + résultats académiques ✅, authentification/rôles ✅, dashboard/graphiques ✅, nettoyage ✅, CSRF ✅. Le cœur fonctionnel et sécuritaire du cahier des charges est couvert. Restent, par ordre d'impact décroissant : documentation (README/ARCHITECTURE/DEPLOYMENT), module Rapports (page actuellement vide), tests automatisés (PHPUnit), et les items de polish (design system propre, accessibilité, health check, tests de charge 10k+).*

## Phase 77 — Documentation (terminée le 2026-09-08)

Créé à la racine : `README.md` (vue d'ensemble, démarrage rapide, commandes), `ARCHITECTURE.md` (arborescence, principe du moteur de campagnes, ce qui n'a volontairement pas changé), `DATABASE.md` (schéma complet table par table, migrations), `ORANGE_API.md` (intégration, cache de token, **rappel du contrat expiré**, classification d'erreurs), `SECURITY.md` (auth, rôles, CSRF, secrets, limites connues), `DEPLOYMENT.md` (installation, worker en production via cron/Planificateur de tâches, sauvegarde, health check à construire). Chaque document renvoie vers `AUDIT.md` pour le détail historique phase par phase.

## Phase 44 — Module Rapports (terminée le 2026-09-08)

**Objectif** : `?page=rapports` était un lien de menu sans handler dans `app/index.php` — page blanche depuis toujours (audit §4).

**Fichiers créés** : `app/templete/rapports.php` — KPI globaux réels, tableau "SMS par campagne" (destinataires/réussis/échecs/taux par campagne, export CSV côté client), tableau "Top erreurs" (comptage par `error_code`).
**Fichiers modifiés** : `server/config.php` (`getCampaignsReport()`, `getTopErrors()`) ; `app/index.php` (route `rapports` ajoutée, **et le fallback `404.php` référencé depuis l'origine mais jamais créé** — tout accès sans `?page=` valide provoquait une erreur fatale `require_once` avant cette phase) ; `app/templete/404.php` créé ; `server/infosAPI.php` — un warning "Undefined array key page" supplémentaire trouvé et corrigé en testant l'accès sans paramètre.

**Tests réalisés** : page rapports chargée avec la base actuellement vide → états vides corrects ("Aucune campagne...", "Aucune erreur...") ; accès à `?page=nimportequoi` → page 404 propre au lieu d'un fatal error ; accès à `app/index.php` sans aucun paramètre → 200 propre, plus aucun warning.

**Résultat** : plus aucun lien mort dans l'application ; le module Rapports s'alimentera automatiquement dès les premières vraies campagnes.

*Bilan de cette session : 10 phases du cahier des charges traitées et testées de bout en bout (audit, sécurité/config, moteur de campagnes, interface + résultats académiques, authentification/rôles, dashboard/graphiques, nettoyage, CSRF, documentation, rapports). Suite ci-dessous : tests automatisés, health check, identité visuelle, accessibilité, tests de charge.*

---

# JOURNAL — SESSION 2 (suite, 2026-09-08)

## ⚠️ Incident constaté en début de session (résolu, sans perte de données)

En reprenant le travail, l'audit de routine a montré `contacts` et `groupes` à **0 lignes** (3296 et 1 précédemment), avec `groupe_contacts` (3296 lignes) devenu orphelin et `messages_id_seq` à une valeur très élevée (291302) suggérant une activité importante entre les deux sessions. Aucune action de cette session (ni de la précédente) n'a touché ces deux tables — vérifié en retraçant chaque `DELETE` effectué, tous scopés à des `campagne_id`/`messages` de test. **Confirmé par l'utilisateur : suppression volontaire de sa part**, aucune perte accidentelle. `groupe_contacts` reste avec 3296 lignes orphelines — non nettoyé, à faire sur demande uniquement.

## Phase 59 — Tests automatisés PHPUnit (terminée)

**Objectif** : le cahier des charges §59 demande explicitement des tests pour la normalisation téléphone, le calcul SMS, la création de campagne, la sélection des destinataires, le retry, l'erreur API, l'idempotence, la progression, l'annulation, la pause/reprise — rien n'existait.

**Refactor préalable** : la classification d'erreurs (`INVALID_PHONE`/`AUTH_ERROR`/.../retryable ou non) vivait en méthode privée de `CampaignQueueService`, impossible à tester sans base de données. Extraite en classe pure `src/Services/SmsErrorClassifier.php` (aucun changement de comportement, juste déplacée), rendant §8/§9 testables sans DB ni API.

**Fichiers créés** :
- `phpunit.xml`, `tests/bootstrap.php` (charge l'app + démarre la session **avant** toute sortie console, sinon `session_start()`/`session_regenerate_id()` échouent en CLI une fois que PHPUnit a déjà imprimé des caractères — corrigé via `ob_start()`)
- `tests/Unit/PhoneNumberServiceTest.php` — 8 cas (formats valides/invalides, §10)
- `tests/Unit/SmsErrorClassifierTest.php` — 9 cas couvrant les 7 catégories d'erreur et leur retryabilité (§8-§9)
- `tests/Unit/AuthServiceTest.php` — PDO mocké (aucune vraie base), couvre échec/succès de connexion, hasRole, logout
- `tests/Integration/CampaignQueueServiceTest.php` — **contre la vraie base `apiSms`**, en dry-run, marqueur `type='phpunit_test'` nettoyé en `tearDown()` : création de campagne, idempotence, verrouillage de lot (aucun destinataire réclamé deux fois), **régression du bug de la Phase 7/8** (campagne finie en un seul lot doit passer à `COMPLETED`), pause/reprise/annulation, retry sélectif par code d'erreur.

**Composer** : ajout de `phpunit/phpunit` (dev), autoload-dev `Tests\\` → `tests/`.

**Résultat des tests, exécutés réellement** :
- Suite unitaire : `OK (31 tests, 60 assertions)`, aucune base de données touchée.
- Suite d'intégration (avec ton accord) : `OK (6 tests, 19 assertions)`, données de test nettoyées et vérifiées après coup (0 campagne `phpunit_test` restante).

**Bug de process trouvé pendant l'installation** : `composer require --dev` interrompu (timeout) a laissé une dépendance transitive (`sebastian/comparator`) manquante — corrigé par un `composer install` complet.

## Phase 47 — Health check (terminée)

**Fichier créé** : `app/health.php` — 6 vérifications (Système/PHP, Base de données, API Orange, Fichiers/storage, Configuration/.env, File d'attente bloquée), chacune `OPERATIONAL`/`WARNING`/`ERROR`, avec sortie JSON (`?format=json`, code HTTP 503 si `ERROR`) pour une supervision externe. Lien ajouté au menu principal.

**Bug réel trouvé en le testant** : `storage/logs/` n'existait pas (seul `storage/cache/` avait été créé en Phase 4) — le check "Fichiers" le signalait `ERROR`. Corrigé (dossier créé).

**Test réalisé** : page chargée en HTML et JSON via serveur réel connecté → statut global `WARNING` correctement remonté (contrat Orange expiré + `APP_DEBUG=true`), 0 warning PHP.

## Phase 67 — Identité visuelle (terminée)

**Objectif** : le thème restait le template de démo générique "Gradient Able" (§8 de l'audit initial : logo générique, lien "Buy now" vers developer.orange.com, footer "crafted by Codedthemes", carte publicitaire "Upgrade to Pro", couleur bleue par défaut).

**Changements** :
- `data-pc-preset` passé de `preset-1` (bleu) à `preset-6` (orange, palette complète déjà définie dans le thème — boutons, alertes, badges, pagination, etc. tous cohérents, pas de reskin partiel risqué) sur `app/index.php` et `index.html`.
- **Bug de spécificité CSS trouvé en vérifiant visuellement (capture d'écran réelle, pas seulement le code)** : le dégradé du bandeau supérieur n'est PAS piloté par le système de preset mais par `[data-pc-header=header-1]`, une règle définie sur `<body>` — un override sur `:root` était donc silencieusement ignoré par héritage (une propriété déclarée sur un ancêtre proche masque toujours celle d'un ancêtre plus lointain, indépendamment de la spécificité du sélecteur). Corrigé dans `assets/css/sms-orange-overrides.css` avec `body[data-pc-header=header-1]` (spécificité strictement supérieure, gagne quel que soit l'ordre de chargement).
- Logo générique remplacé par une marque texte "SMS_ORANGE" (aucune image de logo réelle fournie, mieux vaut du texte honnête qu'un logo générique) dans le sidebar et le header, avec le lien cassé `../dashboard/index.html` corrigé vers `index.php?page=dashdoards`.
- Carte publicitaire "Upgrade to Pro" supprimée, footer remplacé ("SMS_ORANGE — UGLC-SC" + lien vers le health check), meta description/author/keywords génériques remplacées.
- Page d'accueil (`index.html`) : lien "Se connecter" qui pointait vers `./dashboard/index.html` (page inexistante, **bug réel, lien mort**) corrigé vers `app/login.php`.
- Bouton "Se connecter" de `app/login.php` (resté bleu Bootstrap par défaut, cette page ne charge pas le thème) recoloré en orange par cohérence.

**Tests réalisés** : capture d'écran réelle (Playwright) des pages login/dashboard/groupes/health après connexion — c'est cette vérification visuelle qui a révélé le bug de spécificité CSS ci-dessus (le code semblait correct, `getComputedStyle` sur `:root` confirmait la bonne valeur, mais le rendu réel restait bleu ; la cause n'est apparue qu'en énumérant toutes les règles CSS correspondant à `.pc-header` sur toutes les feuilles de style). Après correction, capture de contrôle confirmant le dégradé orange sur le bandeau, cohérent avec la page de connexion.

**Non fait** : pas de logo image réel (aucun asset fourni), pas de reskin de la page d'accueil au-delà du lien cassé, boutons d'export DataTables (CSV/Excel/PDF/Imprimer) gardent leurs couleurs par défaut du plugin (hors périmètre du thème).

## Phase 43 (partielle) — Accessibilité (terminée pour les formulaires les plus utilisés)

Corrigé : labels non associés à leur champ (`for`/`id` manquants) sur `app/login.php` (email/mot de passe) et le formulaire d'envoi rapide de `dashboard.php` (ajout de labels `visually-hidden` pour ne pas changer le design compact existant). **Non fait** : passage exhaustif sur tous les formulaires de l'application (une dizaine d'autres vues ont le même style d'input sans label explicite) — corrigé aux endroits les plus visibles/fréquentés, le reste reste une dette documentée plutôt que prétendument réglée.

## Phase 60 — Test de charge à 10 000+ étudiants (terminée)

**Fichier créé** : `bin/load-test.php` — crée une campagne `type='loadtest'`, importe N destinataires synthétiques, lance `EXPLAIN ANALYZE` sur la requête exacte de `claimBatch()`, traite tous les lots en dry-run, mesure temps/mémoire/débit, **supprime systématiquement ses données dans un bloc `finally`** (même en cas d'erreur du script).

**Résultats réels, mesurés avec ton accord contre `apiSms`, 10 000 destinataires, lots de 200** :

| Mesure | Résultat |
|---|---|
| Import de 10 000 destinataires | 4,77 s (≈ 2100 insertions/s) |
| Traitement des 10 000 (dry-run) | 16,33 s en 50 lots (≈ 612 SMS/s simulés) |
| Stabilité entre le 1er et le 50ᵉ lot | 0,36 s → 0,39 s — **aucune dégradation** avec la taille de la table (confirme que `SKIP LOCKED` + l'index tiennent la charge) |
| Résultat | 10 000 réussis, 0 échec, statut final `COMPLETED` |
| Mémoire PHP (pic) | 8 MB seulement |
| `EXPLAIN ANALYZE` du claim de lot | 0,32 ms d'exécution réelle pour extraire 200 lignes parmi 10 000+ |

**Constat honnête sur l'index** : le planificateur PostgreSQL a choisi un parcours de `messages_pkey` (clé primaire) plutôt que l'index composite `idx_messages_campagne_statut` créé en Phase 6 — probablement des statistiques de table pas encore à jour après l'import massif. Sans impact pratique ici (0,32 ms), mais **recommandation pour la production** : lancer `ANALYZE messages;` après un import massif pour que le planificateur ait des statistiques fraîches, surtout si la table dépasse largement 10 000 lignes en usage réel prolongé.

**Bug de process trouvé (même cause que dans `bin/process-campaign.php` en Phase 6)** : le script requérait `config/services.php` au lieu de `server/config.php`, donc `getSingleCampagne()` était indéfinie — corrigé. Le nettoyage en `finally` a fonctionné correctement malgré le crash (vérifié : 0 ligne restante après coup), preuve que la stratégie « toujours nettoyer, même en cas d'erreur » est robuste.

---

## Bilan global des deux sessions

Toutes les phases prioritaires du cahier des charges ont été traitées, testées avec de vraies requêtes (pas de simulation déclarée sans preuve), et documentées avec leurs limites honnêtes plutôt que passées sous silence :

audit, sécurité/config, moteur de campagnes, interface + résultats académiques, authentification/rôles, dashboard/graphiques, nettoyage, CSRF, documentation, rapports, tests automatisés, health check, identité visuelle, accessibilité (partielle), test de charge 10k+.

**Reste, si une suite est souhaitée** : un vrai logo, et la mise à niveau `APP_ENV=production`/`APP_DEBUG=false` avant toute mise en ligne réelle (actuellement encore en développement, correctement signalé `WARNING` par `health.php`). L'accessibilité exhaustive, les tests de charge à 50 000/100 000 et le verrouillage anti brute-force, mentionnés ici comme suite possible, ont depuis été traités — voir la session 3 plus bas.

---

## Suppression des modules Contacts/Groupes et Notes (2026-09-08)

**Demande explicite de l'utilisateur** : "éliminer tout ce qui est en lien avec le contact et les notes". Deux clarifications obtenues avant exécution (voir échange) : (1) le module Groupes devait partir aussi, puisqu'il n'existait que pour organiser des contacts ; (2) les vraies données en base devaient être supprimées, pas seulement le code.

**Constat avant suppression** : les tables `contacts` et `groupes` étaient déjà vides (0 ligne chacune) au moment de la demande — seules 3296 lignes orphelines subsistaient dans `groupe_contacts` (sans parent dans `contacts` ni `groupes`). Aucune donnée réelle n'a donc été perdue par cette suppression, contrairement à ce que l'historique de la session précédente aurait pu laisser craindre (3296 contacts réels y avaient été constatés à l'époque).

**Fichiers archivés** (`git mv` vers `archive/`, historique préservé) : `app/templete/contacts.php`, `app/templete/groupe.php`, `app/templete/detail-groupe.php`, `app/templete/sendMarksheets.php`, `server/layout.php` (sa seule fonction, `ListGroupe`, ne servait qu'aux groupes).

**Fichiers modifiés** :
- `server/app.php` — handlers supprimés : `create_group`, `import_csv` (import CSV de contacts), `add_contact`, `importMessage_csv` (import CSV de résultats bruts matricule/notes/niveau), `prepare_resultats_campagne` (consolidation des résultats en campagne), `send_to_group`.
- `server/config.php` — fonctions supprimées : `getGroupes`, `getGroupe`, `getContactByGroupe`, `detailGroupe`, `getPhoneContact`, `phoneExiste`, `getMessageSenderMarksheet`, `getSingleStudentSendMarksheet`.
- `server/infosAPI.php` — `require_once('layout.php')` → `require_once('config.php')` (layout.php archivé).
- `src/Services/CampaignQueueService.php` — `addRecipients()` ne gère plus `notes`/`niveaux` (colonnes supprimées) ; conserve `matricule`/`nom`/`prenom`, génériques et utilisés par l'import Excel (non concerné par la demande — ce n'est pas le module "notes").
- `app/index.php` — retrait des entrées de menu "Gestion des Groupes", "Liste des Contacts", "Liste des Notes" et de leurs routes (`Groupes`, `Contacts`, `notes` → 404 propre désormais) ; description meta mise à jour (ne mentionne plus les résultats académiques).
- `app/templete/sms-sender.php` — retrait du bouton/modal "Envoyer à un groupe" et de la table de groupes (`ListGroupe()`) ; remplacée par une vraie liste des campagnes récentes, cohérente avec le reste de l'app.
- `app/templete/detail-campagne.php` — retrait du bouton/modal "Importer des résultats bruts (CSV)" (format `telephone,message,matricule,notes,niveau`) ; l'import Excel générique (`nom,prenom,matricule,telephone,message`) est conservé.
- `app/templete/campagne.php` — aucune référence trouvée, non modifié.
- Documentation : `DATABASE.md` (tables/colonnes retirées documentées), `ARCHITECTURE.md`, `README.md`, `DEPLOYMENT.md` mis à jour pour ne plus décrire des fonctionnalités qui n'existent plus.

**Migration DB** (`database/migrations/004_remove_contacts_and_notes.sql`, **destructive et intentionnelle, exécutée avec accord explicite**) :
```sql
DROP TABLE IF EXISTS groupe_contacts;
DROP TABLE IF EXISTS contacts CASCADE;  -- CASCADE ne supprime que la contrainte FK
DROP TABLE IF EXISTS groupes;           -- orpheline sur sms_destinataires (table déjà
ALTER TABLE messages DROP COLUMN IF EXISTS notes;    -- inutilisée, vide), pas la table elle-même
ALTER TABLE messages DROP COLUMN IF EXISTS niveaux;
```
Premier essai sans `CASCADE` rejeté par PostgreSQL (contrainte `sms_destinataires_contact_id_fkey` dépendante) — corrigé et réexécuté avec succès. `sms_destinataires` (table déjà signalée comme jamais utilisée par le code applicatif depuis la Phase 1) reste en place, juste sans cette contrainte désormais orpheline.

**Tests réalisés** :
1. `php -l` sur l'intégralité du code actif après suppression → aucune erreur.
2. Suite PHPUnit complète (unit + integration, contre la vraie base) → 37 tests, 79 assertions, tout au vert.
3. Balayage HTTP réel de toutes les pages restantes (`dashdoards`, `campgagne`, `sms-sender`, `rapports`) après connexion → 200 partout, zéro warning PHP.
4. Anciennes routes (`Groupes`, `Contacts`, `notes`) → 404 propre au lieu d'un fatal error ou d'une page cassée.
5. Cycle complet campagne réelle : création → ajout d'un destinataire (nom/prénom/matricule conservés) → dry-run → vérification du journal affichant correctement Nom/Prénom/Matricule/Destinataire/Statut sans les colonnes supprimées.
6. Données de test nettoyées après vérification.

---

## Correctif DataTables + page "Historique SMS (Orange)" (2026-09-08)

**Signalement utilisateur** : "les dataTable plante". Diagnostic fait avec un vrai navigateur headless (Playwright), pas seulement par relecture de code — trois bugs JS réels trouvés, invisibles aux tests précédents qui ne vérifiaient que les warnings PHP et le HTML statique, jamais l'exécution JS côté client.

**Bug n°1 (la cause du plantage signalé)** : `app/index.php` chargeait le fichier de traduction française de DataTables depuis `//cdn.datatables.net/plug-ins/1.13.5/i18n/fr-FR.json` en AJAX. Cette requête est bloquée par la politique CORS du navigateur (`Access to XMLHttpRequest ... has been blocked by CORS policy`), ce qui interrompt l'initialisation de DataTables en plein milieu et laisse le tableau dans un état cassé (erreur `Cannot set properties of undefined (setting '_DT_CellIndex')` à la moindre interaction). **Corrigé** : traductions françaises passées en dur dans un objet JS inline (`dataTableFrFR`), plus de dépendance réseau pour l'initialisation. Une garde `if ($('#groupesTable').length)` a aussi été ajoutée pour ne tenter l'initialisation que si le tableau existe réellement sur la page.

**Bug n°2** : `app/templete/dashboard.php` appelait `new ApexCharts(...)` dans un `<script>` inline placé dans le corps de la page, alors que `<script src="...apexcharts.min.js">` est chargé plus bas, dans le pied de page (`app/index.php`) — la bibliothèque n'était donc pas encore définie au moment de l'exécution (`ApexCharts is not defined`), sur la page la plus visitée de l'application. **Corrigé** : le script est désormais enveloppé dans `document.addEventListener('DOMContentLoaded', ...)`, qui garantit que tous les `<script src>` classiques (bloquants, sans `defer`) précédant ce point du document ont déjà été exécutés.

**Bug n°3** : `assets/js/pages/dashboard-sales.js`, chargé sans condition sur **toutes** les pages via `app/index.php`, tentait d'instancier une carte du monde (`jsVectorMap`) sur `#world-map-markers` et un graphique sur `#earnings-users-chart` — deux éléments du tableau de bord de démonstration d'origine ("Gradient Able"), qui n'existent plus nulle part dans l'application actuelle. Résultat : `Cannot read properties of null (reading 'classList')` sur **chaque** page, en boucle infinie potentielle vu le `setTimeout` de rappel. **Corrigé** : ce script (et les bibliothèques `jsvectormap.min.js`/`world.js`/`world-merc.js`/leur CSS, qui ne servaient qu'à lui) retiré de `app/index.php` — code mort de démo jamais utile à cette application.

**Vérification** : script Playwright réutilisable écoutant `console` et `pageerror` sur les 4 pages principales, avant/après. Avant : 2 à 3 erreurs JS par page. Après : **zéro erreur JS sur aucune page**. Capture d'écran de `?page=campgagne` confirmant visuellement le DataTable fonctionnel (recherche, tri des colonnes, pagination et les 4 boutons d'export tous rendus et cliquables, libellés en français correctement affichés). Capture d'écran du dashboard confirmant les 3 graphiques ApexCharts effectivement rendus (SVG présents dans le DOM, pas juste absence d'erreur).

**Nouvelle page : "Historique SMS (Orange)"** (`?page=sms-history`), demandée par l'utilisateur pour "récupérer les SMS envoyés via l'API". **Contrainte réelle découverte en interrogeant la vraie API** (`orangeSms()->getHistory()`) : l'API SMS Orange ne conserve **pas** le détail individuel des SMS envoyés (destinataire, contenu, date d'envoi précise) — `getHistory()` retourne en réalité l'historique des **recharges/achats de forfaits**, et `getStatistics()` ne donne que des compteurs d'usage agrégés par application. Il n'existe aucun endpoint Orange pour lister les SMS un par un après envoi. Plutôt que de construire une page qui prétendrait afficher quelque chose que l'API ne fournit pas, la page construite affiche honnêtement ce qui est réellement disponible :
- solde SMS, statut du contrat et date d'expiration en direct (`getBalance()`) ;
- statistiques d'usage agrégées par service/pays/application (`getStatistics()`) ;
- historique réel des recharges avec date, offre, prix, mode de paiement et solde résultant (`getHistory()`) ;
- un encart explicite renvoyant vers le module Rapports / le journal de campagne (déjà existants) pour le détail par SMS envoyé — cette donnée-là vient de notre propre base, pas de l'API Orange, et existe déjà.

**Fichiers créés** : `app/templete/sms-history.php`. **Fichiers modifiés** : `app/index.php` (entrée de menu + route `sms-history`, suppression du fichier i18n distant, suppression du script/CSS de démo mort, correctif dashboard.php cité plus haut).

**Testé contre la vraie API Orange** (capture d'écran) : solde 906 (calculé), statut `EXPIRED` correctement remonté et mis en rouge, 209 SMS d'usage agrégé (182+27, cohérent avec le compteur du dashboard), 4 lignes d'historique de recharges réelles avec montants en GNF. Aucune erreur JS ni PHP.

**Résultat** : le plantage des DataTables signalé est corrigé et vérifié en navigateur réel (pas seulement en relecture de code) ; le dashboard n'a plus aucune erreur JS console ; une nouvelle page expose fidèlement les données Orange réellement disponibles, sans fabriquer de fausse liste de SMS envoyés que l'API ne peut pas fournir.

**Résultat** : les modules Contacts, Groupes et Notes/Résultats académiques bruts sont entièrement retirés du chemin actif (code archivé, tables supprimées), sans casser le moteur de campagnes ni l'import Excel générique de destinataires, qui restent la seule voie d'ajout de destinataires — testé de bout en bout après coup.

---

# JOURNAL — SESSION 3 (2026-09-11) : reconstruction structurée du module "Résultats académiques"

**Contexte** : un nouveau cahier des charges (« SMS_ORANGE V2.0 — envoi des résultats scolaires par SMS ») a été fourni, dont la fonctionnalité n°1 (§3-4, §16, §61-64) est précisément ce qui avait été retiré lors de la session précédente (« Suppression des modules Contacts/Groupes et Notes » ci-dessus, à la demande explicite de l'utilisateur). Plutôt qu'un texte libre saisi via CSV (`matricule/notes/niveaux` dans `messages`), ce cahier des charges décrit un vrai modèle structuré (établissement/session/niveau/classe/programme/semestre/moyenne/mention/rang) — reconstruit ici proprement, en réutilisant entièrement le moteur de campagnes déjà existant (`CampaignQueueService`) plutôt que de dupliquer une logique d'envoi.

**⚠️ Coordination multi-session** : au démarrage de cette session, deux autres sessions Claude Code actives ont été détectées sur le même dépôt (voir échanges inter-sessions). Confirmation obtenue des deux qu'elles étaient inactives/idle avant toute modification, pour éviter d'écraser du travail non commité.

**Fichiers créés** :
- `database/migrations/005_academic_results.sql` — tables `resultats_academiques` (une ligne par étudiant/session/semestre, upsert par `(matricule, session_academique, semestre)`) et `imports_resultats` (rapport d'import, §10/§23). Additif, aucune table existante modifiée.
- `src/Services/SmsCounterService.php` — calcul du nombre de SMS avec détection réelle de l'encodage (GSM 7 bits vs UCS-2) et seuils de segmentation corrects (160/153 vs 70/67), pour ne jamais sous-estimer le nombre de SMS facturés (§18/§26).
- `src/Services/MessageTemplateService.php` — rendu des variables `{{...}}`, utilisé à l'identique pour l'aperçu et l'envoi réel (§17).
- `src/Services/AcademicResultsService.php` — import Excel/CSV (détection de colonnes par en-tête, alias FR/EN), validation/normalisation (`PhoneNumberService`), rapport d'import détaillé, filtrage par session/niveau/classe/programme/semestre, et `toTemplateVars()` (mapping colonnes DB → variables du cahier des charges).
- `app/templete/resultats.php` — écran du module : import, historique des imports avec téléchargement des erreurs, sélection par filtres avec compteur en direct, éditeur de modèle avec variables cliquables, aperçu réel + calcul SMS + solde en direct (AJAX), SMS de test, création de la campagne consolidée.
- `server/resultats_preview.php` — endpoint JSON (lecture seule, même modèle que `campaign_worker.php`) pour la prévisualisation en direct.
- `server/resultats_export_errors.php` — export CSV des erreurs d'un import (script autonome car un téléchargement de fichier doit envoyer ses en-têtes avant toute sortie HTML, impossible depuis une page déjà incluse dans le shell `app/index.php`).
- `tests/Unit/SmsCounterServiceTest.php`, `tests/Unit/MessageTemplateServiceTest.php`, `tests/Unit/AcademicResultsServiceMappingTest.php` (9 tests, purs, aucune DB) ; `tests/Integration/AcademicResultsServiceTest.php` (4 tests contre la vraie base `apiSms`, données taguées `PHPUNITRES-`/`phpunit_`, nettoyées en `tearDown()` et vérifiées après coup).

**Fichiers modifiés (additif uniquement, pas de restructuration)** :
- `config/services.php` — ajout de `academicResults()`.
- `server/app.php` — 3 nouveaux handlers en fin de fichier : `import_resultats`, `test_sms_resultats`, `create_resultats_campagne` (vérifie le solde Orange avant création, §20/§27, et bloque si insuffisant).
- `app/index.php` — entrée de menu "Envoyer les résultats" + route `resultats`.

**Bug réel trouvé et corrigé pendant le test du parcours complet en navigateur (Playwright, pas seulement relecture de code)** : le message rendu affichait *"Vos résultats du S4 -  sont disponibles."* — les variables `{{session}}` et `{{total}}` ne se résolvaient jamais, car la table stocke `session_academique`/`total_classe` (noms de colonnes explicites) alors que le message utilise `{{session}}`/`{{total}}` (noms courts du §4/§62). Corrigé en ajoutant `AcademicResultsService::toTemplateVars()`, appliqué aux trois points de rendu (aperçu AJAX, SMS de test, génération de la campagne réelle) ; régression couverte par `AcademicResultsServiceMappingTest`.

**Tests réalisés** :
1. `php -l` sur tous les fichiers créés/modifiés → aucune erreur (une erreur de syntaxe a été trouvée et corrigée pendant le développement : `$` non échappé dans une chaîne de constante de classe (`SmsCounterService::GSM_BASIC`) — PHP tente d'interpoler `$¥`/`$A`/etc. même dans une expression de constante et rejette avec *"Constant expression contains invalid operations"* ; corrigé en échappant `\$`).
2. Suite PHPUnit complète (unit + integration) → **60 tests, 122 assertions, tout au vert**, aucune régression sur les 47 tests précédents.
3. Migration appliquée sur la vraie base `apiSms` (`php database/migrate.php`) → OK.
4. Parcours complet en navigateur réel (Playwright headless, serveur PHP intégré) : connexion → page Résultats (aucune erreur console/JS) → import d'un CSV réel (3 lignes : 2 valides, 1 téléphone invalide) → rapport d'import correct → sélection de filtres → aperçu mis à jour en direct (compteur, message réel, longueur/encodage/SMS, solde Orange réel affiché) → création de la campagne consolidée (2 destinataires, DRAFT) → vérification du journal de la campagne. **La campagne n'a volontairement pas été lancée** (bouton "Lancer l'envoi" jamais cliqué) pour ne consommer aucun SMS réel pendant le test.
5. Toutes les données de test (résultats `PWTEST-*`/`PHPUNITRES-*`, imports, campagnes de test) supprimées après vérification.

**Non fait à ce stade** : pas de suppression/désactivation d'un résultat individuel depuis l'interface (seul un ré-import met à jour) ; pas de pagination sur la liste des résultats filtrés côté écran de sélection (le calcul d'aperçu ne charge qu'un seul échantillon, donc pas de problème de performance à 10 000+, mais aucune vue tabulaire des résultats correspondants n'est encore affichée avant la création de la campagne — seulement le compteur et un aperçu sur un étudiant) ; pas d'export du tableau des résultats filtrés (seul l'export des erreurs d'import existe).

**Résultat** : la fonctionnalité n°1 du cahier des charges V2.0 (envoi des résultats académiques par SMS, avec sélection structurée session/niveau/classe/programme/semestre) est reconstruite proprement — données structurées plutôt que texte libre, import avec rapport détaillé et déduplication, aperçu fidèle avant envoi, vérification du solde, et réutilisation complète du moteur de campagnes déjà validé (idempotence, retry, pause/reprise, suivi temps réel) sans dupliquer aucune logique d'envoi.

## Suite session 3 (2026-09-11) : liste d'étudiants sélectionnable + cache du solde Orange

**Objectif** : combler deux limites documentées dans la livraison précédente — §16-17 du cahier des charges demande un vrai tableau d'étudiants (matricule/nom/classe/téléphone/moyenne/mention/statut) avec sélection/désélection individuelle et exclusion des étudiants déjà envoyés, alors que le premier jet ne montrait qu'un compteur agrégé + un seul échantillon.

**Fichiers créés** :
- `database/migrations/006_resultats_derniere_campagne.sql` — ajoute `resultats_academiques.derniere_campagne_id` (FK vers `campagne`), pour savoir de façon fiable si un étudiant a déjà reçu ses résultats, sans deviner par correspondance de texte libre entre campagnes.
- `server/resultats_list.php` — endpoint JSON paginé (50/page) : matricule/nom/prénom/classe/téléphone/moyenne/mention/`deja_envoye`, avec recherche (`search`, ILIKE nom/prénom/matricule) et exclusion (`exclude_already_sent`).
- `tests/Unit/OrangeSmsServiceBalanceCacheTest.php` — 4 tests (via réflexion, aucun réseau réel) pour le cache du solde.

**Fichiers modifiés** :
- `src/Services/AcademicResultsService.php` — `buildWhere()`/`getMatching()`/`countMatching()` acceptent désormais `search`, `exclude_already_sent` et `exclude_ids` ; `getMatching()` calcule `deja_envoye` par sous-requête sur `messages` via `derniere_campagne_id` ; nouvelle méthode `markCampaignForRows()` appelée juste après `addRecipients()` pour enregistrer quelle campagne a été proposée à chaque étudiant.
- `server/app.php` — `create_resultats_campagne` et `test_sms_resultats` acceptent `excluded_ids`/`exclude_already_sent` (cases décochées dans le tableau) et appellent `markCampaignForRows()`.
- `server/resultats_preview.php` — honore les mêmes exclusions pour que le compteur/l'estimation SMS restent cohérents avec ce qui sera réellement créé.
- `app/templete/resultats.php` — nouveau tableau paginé avec case à cocher par ligne, case "tout sélectionner", case "exclure les étudiants déjà envoyés", recherche en direct, pagination, et badge "Déjà envoyé" par ligne.
- `src/Services/OrangeSmsService.php` — `getBalance(int $maxAgeSeconds = 20)` : cache fichier (comme le token) pour éviter un appel réseau réel à Orange à chaque frappe dans l'écran Résultats. `maxAgeSeconds = 0` force une lecture fraîche, utilisé pour le contrôle bloquant juste avant la création réelle d'une campagne (§20) — jamais de décision de blocage basée sur un solde périmé.

**Bug de perf réel trouvé en testant en navigateur (pas par relecture)** : chaque frappe dans le modèle de message ou changement de filtre déclenchait un appel réseau réel à `getBalance()` (aucun cache n'existait, problème déjà pointé du doigt dès l'audit initial §7 sans avoir été corrigé) — la liste d'étudiants mettait plusieurs secondes à apparaître, le serveur de dev PHP étant mono-thread et les requêtes AJAX se mettant en file derrière l'appel Orange. Corrigé par le cache ci-dessus ; après correctif, la liste s'affiche immédiatement lors du premier test navigateur suivant.

**Bug secondaire trouvé et corrigé** : le compteur "X exclu(s) manuellement" ne se mettait à jour qu'au rechargement complet de la liste (pagination/filtre), pas immédiatement après avoir décoché une case — corrigé en recalculant ce texte directement dans les gestionnaires de case à cocher.

**Tests réalisés** :
1. Suite PHPUnit complète → **67 tests, 136 assertions**, aucune régression (7 nouveaux tests d'intégration sur `search`/`exclude_ids`/`markCampaignForRows`/`exclude_already_sent`, 4 nouveaux tests unitaires sur le cache du solde).
2. Parcours navigateur réel (Playwright) : import de 3 étudiants de test → tableau affiché avec les bonnes colonnes → décocher un étudiant fait bien baisser le compteur d'aperçu (3→2) et met à jour `excluded_ids` caché → recherche par nom/matricule confirmée fonctionnelle contre le vrai endpoint (isolée du reste du flux) → case "exclure déjà envoyés" sans aucun envoi préalable laisse les 3 étudiants visibles (comportement attendu).
3. Données de test nettoyées après vérification (`BRTEST-*`, campagnes/imports associés).

**Résultat** : l'écran Résultats académiques permet maintenant une sélection fine (voir chaque étudiant, l'exclure individuellement, ou exclure en masse ceux déjà servis) conforme au §16-17, et le solde Orange n'est plus interrogé en réseau à chaque interaction — seulement au maximum une fois toutes les 20 secondes pour l'aperçu, et toujours en direct pour le contrôle bloquant réel.

## Suite session 3 (2026-09-11) : journal d'activité / audit trail (§35/§64)

**Objectif** : le cahier des charges V2.0 demande explicitement (§64) de savoir qui a créé/lancé/annulé/repris/réessayé une campagne, et (§35) de journaliser connexion/import/campagne. Rien de tel n'existait — seul `created_by` sur `campagne` capturait le créateur, sans historique des actions ultérieures ni des connexions.

**Fichiers créés** :
- `database/migrations/007_activity_logs.sql` — table `activity_logs` (additive).
- `src/Services/ActivityLogger.php` — `log()`, `recent()`, `forCampaign()`.
- `app/templete/journal.php` — page "Journal d'activité" (tableau, export CSV client, libellés d'action en français, lien vers la campagne concernée).
- `tests/Integration/ActivityLoggerTest.php` — 3 tests contre la vraie base, données taguées `phpunit_activity_test`, nettoyées en `tearDown()`.

**Fichiers modifiés (additif uniquement)** :
- `config/services.php` — ajout de `activityLog()`.
- `server/app.php` — un appel `activityLog()->log(...)` après chaque mutation réussie : `create_campagne`, `launch_campagne`, `pause_campagne`, `resume_campagne`, `cancel_campagne`, `retry_campagne_failures`, `import_resultats`, `create_resultats_campagne`.
- `app/login.php` / `app/logout.php` — `connexion`/`deconnexion` journalisées (uniquement en cas de succès réel, pas sur tentative échouée — déjà couvert par le verrouillage anti brute-force de la session précédente).
- `app/index.php` — entrée de menu "Journal d'activité" + route `journal`.

**Tests réalisés** :
1. Suite PHPUnit complète → **70 tests, 144 assertions**, aucune régression.
2. Connexion réelle en navigateur (Playwright) → entrée "Connexion" visible immédiatement dans `?page=journal` avec le bon utilisateur et horodatage, zéro erreur console/JS.

**Résultat** : chaque action significative sur une campagne (création, lancement, pause, reprise, annulation, réessai) ainsi que les connexions/déconnexions sont désormais tracées avec qui/quoi/quand, consultables depuis une page dédiée — la dernière lacune structurelle du §64 est comblée sans toucher à la logique métier existante (uniquement des appels de journalisation ajoutés après coup).

## Suite session 3 (2026-09-11) : modèles SMS réutilisables (§25) + 4ᵉ graphique dashboard (§12) + correctif redirection post-connexion

**Objectif** : combler les deux derniers écarts identifiés par rapport au cahier des charges V2.0 — §25 (bibliothèque de modèles SMS avec catégories, CRUD, duplication, archivage) et §12 (4 graphiques attendus sur le dashboard, seulement 3 existaient).

**Fichiers créés** :
- `database/migrations/008_sms_templates.sql` — table `sms_templates` (additive).
- `src/Services/SmsTemplateService.php` — `create/update/duplicate/setArchived/all/find`.
- `app/templete/modeles.php` — page CRUD (tableau, modal créer/modifier, dupliquer, archiver/réactiver).
- `tests/Integration/SmsTemplateServiceTest.php` — 5 tests contre la vraie base, données taguées `PHPUNITTPL-`, nettoyées en `tearDown()`.

**Fichiers modifiés** :
- `config/services.php` — ajout de `smsTemplates()`.
- `server/app.php` — handlers `create_template`/`update_template`/`duplicate_template`/`archive_template`/`unarchive_template`, chacun journalisé via `activityLog()`.
- `app/index.php` — entrée de menu "Modèles SMS" + route `modeles`.
- `app/templete/journal.php` — libellés pour les nouvelles actions de modèles.
- `app/templete/resultats.php` — sélecteur "Charger un modèle enregistré" au-dessus de l'éditeur de message ; sélectionner un modèle remplace le contenu de la zone de texte (même moteur de variables ensuite, aucune duplication de logique de rendu).
- `server/config.php` — nouvelle fonction `getSuccessRateEvolution($days)` (série temporelle du taux de réussite ; un jour sans SMS traité vaut `null`, pas 0%, pour ne pas laisser croire à un échec total un jour d'inactivité).
- `app/templete/dashboard.php` — 4ᵉ graphique ApexCharts (évolution du taux de réussite, line chart) à côté du graphique de performance des campagnes.

**Bug réel trouvé en testant le nouveau graphique en navigateur (pas par relecture)** : après connexion, `app/login.php` redirigeait vers `index.php` **sans** `?page=...` — `app/index.php` traite l'absence de paramètre comme une route invalide et affiche la page 404 au lieu du dashboard. Ce bug préexistait (introduit avant cette session) et n'avait jamais été remarqué car les tests précédents naviguaient toujours explicitement vers `?page=dashdoards` après connexion plutôt que de suivre la redirection réelle. Corrigé : les deux redirections de `login.php` (déjà connecté + connexion réussie) pointent maintenant vers `index.php?page=dashdoards`.

**Tests réalisés** :
1. Suite PHPUnit complète → **75 tests, 156 assertions**, aucune régression.
2. Parcours navigateur réel (Playwright) : création d'un modèle → apparaît dans le tableau avec le bon aperçu/catégorie → rechargé correctement dans l'éditeur de l'écran Résultats via le sélecteur.
3. Après correctif de la redirection : les 4 graphiques du dashboard (évolution des envois, répartition, performance des campagnes, taux de réussite) rendent bien 4 `<svg>` distincts, zéro erreur console/JS — **avant** le correctif, aucun des 4 graphiques ne s'affichait après une connexion réelle (page 404 à la place), ce qui n'avait jamais été détecté par les tests précédents qui contournaient systématiquement le flux de redirection réel.
4. Données de test nettoyées après vérification (`PWTPL*`, entrées de journal associées).

**Résultat** : le dashboard respecte maintenant les 4 graphiques attendus par le §12, un administrateur peut composer un message une fois et le réutiliser (§25), et un bug d'UX critique (dashboard invisible juste après connexion) — présent depuis plusieurs sessions sans être détecté — est corrigé.

## Suite session 3 (2026-09-11, fin) : finalisation — accessibilité, design system, responsive, sélection massive, test de charge 50k/100k

**Objectif** : traiter les 5 derniers écarts identifiés à la demande explicite de l'utilisateur ("finalise tous sa vite").

### Accessibilité (§43, exhaustive cette fois)
- Labels manquants corrigés : `sms-sender.php` (numéro/message, même formulaire "envoi rapide" que le dashboard mais jamais corrigé lors de la Phase 43 initiale), les 5 filtres de sélection de `resultats.php` (Session/Niveau/Classe/Programme/Semestre — les `<label>` existaient mais sans attribut `for`, donc non associés), le champ de recherche d'étudiants (`student_search`), la case "tout sélectionner" (`student-select-all`), et l'input de recherche décoratif de l'en-tête (`app/index.php`, hérité du thème).
- Case à cocher générée dynamiquement par ligne d'étudiant : `aria-label` explicite avec le nom complet de l'étudiant.
- Un `<label>` sans `for` (au-dessus des boutons "Variables disponibles", qui ne pilotent aucun champ unique) a été changé en `<span>` pour rester sémantiquement correct.

### 🔒 Faille XSS stockée trouvée et corrigée (hors périmètre initial, trouvée en corrigeant l'accessibilité)
En ajoutant l'`aria-label` de la case à cocher par étudiant, révision de `renderStudentRow()` (JS, `resultats.php`) : la fonction construisait chaque ligne via `innerHTML` + concaténation de chaînes avec les données `nom`/`prenom`/`matricule`/... **directement issues d'un fichier importé par l'utilisateur, jamais échappées**. Un nom d'étudiant contenant `<img src=x onerror=...>` se serait exécuté dans le navigateur de tout administrateur consultant l'écran Résultats. Corrigé en reconstruisant la ligne via le DOM (`createElement`/`textContent`) au lieu de `innerHTML`, qui élimine la classe de bug entièrement plutôt que d'ajouter un échappement au cas par cas. **Vérifié avec un vrai payload XSS importé** (Playwright) : le texte s'affiche littéralement, aucune boîte de dialogue `alert()` déclenchée, `<img` absent du DOM rendu.

Un second problème du même type, moins sévère (créé uniquement par un compte ADMIN/OPERATOR authentifié, donc surtout un risque de self-XSS), a été trouvé par relecture dans `app/templete/modeles.php` : `onclick='openEditTemplate(<?= json_encode($t) ?>)'` embarque du JSON dans un attribut HTML à guillemets simples sans échapper les guillemets simples du contenu — un modèle contenant une apostrophe casserait l'attribut. Corrigé avec `htmlspecialchars(json_encode($t), ENT_QUOTES)`.

### Design system (§14)
Création de `DESIGN_SYSTEM.md` : palette de couleurs réelle (preset `preset-6`, `#fd7e14`), typographie, jeux d'icônes, et convention documentée pour chaque famille de composant déjà en usage (boutons, cartes, badges, formulaires, tableaux, modales, notifications, barres de progression, graphiques, états vides/erreur), plus un rappel accessibilité et responsive. Documente l'existant plutôt que d'inventer un nouveau système parallèle.

### Responsive/mobile (§42, testé explicitement)
Testé en Playwright à 390px de large (gabarit iPhone) sur Dashboard/Résultats/Campagnes/Modèles/Journal : **aucun débordement horizontal** (`document.body.scrollWidth` = `window.innerWidth` sur les 5 pages), captures d'écran vérifiées visuellement — sidebar réduite en icône hamburger, cartes KPI empilées, graphiques lisibles, tableaux dans leur conteneur défilant. Le thème Bootstrap gérait déjà correctement le responsive ; aucun correctif de mise en page n'a été nécessaire, seulement la vérification.

### Sélection massive (§17, complétée)
Ajout de deux filtres réels dans `AcademicResultsService::buildWhere()` : `only_with_phone` (`telephone IS NOT NULL AND <> ''` — toujours vrai en pratique puisque le téléphone est validé à l'import, mais gardé comme garde-fou explicite plutôt que supposé) et `only_with_results` (`moyenne IS NOT NULL AND <> ''` — filtre réellement utile pour exclure les lignes importées avec une moyenne non encore renseignée). Câblés de bout en bout : `resultats_preview.php`, `resultats_list.php`, `server/app.php` (test SMS + création de campagne), UI (`resultats.php`, cases à cocher avec labels corrects). 2 nouveaux tests d'intégration.

### Test de charge 50 000 / 100 000 (§60, au-delà du minimum)
`bin/load-test.php` exécuté avec succès aux deux volumes (dry-run, données supprimées après coup) :

| Mesure | 50 000 | 100 000 |
|---|---|---|
| Import | 52,5 s (952/s) | 95,4 s (1048/s) |
| Traitement (lots de 200) | 254,95 s (196 SMS/s) | 423,75 s (236 SMS/s) |
| Premier lot → dernier lot | 0,46 s → 0,37 s | 0,5 s → 1,37 s |
| Résultat | 50 000 réussis, 0 échec | 100 000 réussis, 0 échec |
| Mémoire pic | — | 62 Mo |

Débit par SMS plus faible qu'au test à 10 000 (§Phase 60 initiale, ~612 SMS/s) : attendu, `processRecipient()` fait plusieurs `UPDATE` non groupés par destinataire (aucune dégradation algorithmique — `EXPLAIN ANALYZE` confirme un temps d'exécution du claim de lot toujours sous la milliseconde à 100 000 lignes, `Index Scan` sur `messages_pkey`). Le léger allongement du dernier lot à 100 000 (1,37 s vs 0,5 s) reste largement dans une marge acceptable et n'indique aucune croissance quadratique. Un test au-delà (500 000+) grouperait probablement les `UPDATE` par lot pour gagner en débit, mais dépasse le besoin exprimé par le cahier des charges (§34 : "10 000 ; 50 000 ; 100 000").

**Tests réalisés** :
1. Suite PHPUnit complète → **77 tests, 159 assertions**, aucune régression.
2. Vérification XSS en navigateur réel avec un payload injecté via import CSV → confirmé neutralisé (texte littéral, zéro exécution).
3. Vérification responsive à 390px sur 5 écrans → zéro débordement horizontal, captures contrôlées visuellement.
4. Vérification réseau (et non par délai fixe, peu fiable sur le serveur de dev PHP mono-thread partageant la même base que le test de charge en cours) des deux nouveaux filtres `only_with_phone`/`only_with_results` → comportement exact confirmé par inspection directe des réponses JSON.
5. Deux exécutions réelles du test de charge (50 000 et 100 000), données nettoyées et vérifiées après coup.

**Résultat** : les 5 points de finalisation demandés sont traités — accessibilité étendue à tous les écrans (formulaires + éléments dynamiques), une faille XSS stockée réelle éliminée au passage, design system documenté, responsive vérifié sans correctif nécessaire, sélection massive complète au sens du §17, et le moteur validé jusqu'à 100 000 destinataires sans dégradation ni échec.

## Suite session 3 (2026-09-12) : module Contacts/Groupes recréé (§22-24), deux bugs systémiques trouvés et corrigés

**Contexte** : l'utilisateur a explicitement redemandé les trois derniers écarts restants, dont le module Contacts/Groupes/Import CSV générique — retiré le 2026-09-08 à sa demande explicite, puis redemandé le 2026-09-12 après avoir vu ce rappel (« le cahier des charges les demande, mais tu avais toi-même demandé leur suppression »), qui constitue une confirmation informée plutôt qu'une simple ambiguïté. Reconstruit proprement sur un schéma dédié (`contacts_v2`/`groupes_v2`/`groupe_contacts_v2`) plutôt que de restaurer l'ancien code archivé, qui fonctionnait sur des données 100 % factices (voir `archive/README.md`).

**Fichiers créés** :
- `database/migrations/009_contacts_groups.sql`.
- `src/Services/ContactService.php` — CRUD contacts/groupes, appartenance, import Excel/CSV avec rapport détaillé (même modèle que `AcademicResultsService` : alias de colonnes, normalisation téléphone, déduplication, upsert par téléphone).
- `app/templete/contacts.php`, `app/templete/groupe.php`, `app/templete/detail-groupe.php`.
- `server/contacts_export_errors.php` (export CSV des erreurs d'import, même modèle que `resultats_export_errors.php`).
- `tests/Integration/ContactServiceTest.php` (6 tests), `tests/Unit/RedirectBackTest.php` (5 tests, voir bug ci-dessous).

**Fichiers modifiés (additif)** : `config/services.php` (`contacts()`), `server/app.php` (9 nouveaux handlers : `create_contact`, `delete_contact`, `import_contacts`, `create_group`, `delete_group`, `add_contact_to_group`, `remove_contact_from_group`, `send_to_group` — ce dernier crée une campagne via `campaignQueue()`, comme les résultats académiques, sans dupliquer de logique d'envoi), `app/index.php` (menu + routes `contacts`/`groupe`/`detail-groupe`).

### 🐛 Bug réel n°1 : `redirectBack()` perdait `?page=...` sur un port non standard
Trouvé en testant la création d'un contact en navigateur réel : après un `redirectBack()` réussi, la page atterrissait sur `index.php` **sans** paramètre, affichant la 404 au lieu du flash de succès attendu sur `?page=contacts`. Cause : `parse_url($referer, PHP_URL_HOST)` ne renvoie jamais le port, alors que `$_SERVER['HTTP_HOST']` l'inclut dès qu'il diffère de 80/443 (le serveur de dev PHP tourne sur `:8899`). La comparaison hôte échouait donc systématiquement hors Apache:80, et `redirectBack()` retombait toujours sur son fallback. **Ce bug touchait potentiellement tous les handlers utilisant `redirectBack()`** (envoi simple, tests SMS, contacts...) sur tout déploiement à port non standard — resté invisible car les sessions précédentes testaient principalement via Apache:80. Corrigé en comparant l'autorité complète (hôte + port) ; logique extraite dans `resolveRedirectTarget()` pour être testable sans dépendre de `header()`. 5 tests de régression.

### 🐛 Bug réel n°2 : DataTables cassait sur tout tableau vide de l'application
Trouvé en testant un groupe fraîchement créé (0 membre) : erreur JS `Cannot set properties of undefined (setting '_DT_CellIndex')`, déjà rencontrée et documentée (session 2, "Correctif DataTables") mais dont la cause avait alors été attribuée uniquement au fichier i18n distant bloqué par CORS. **Cause réelle, plus large** : DataTables tente d'indexer autant de cellules que de colonnes déclarées dans `<thead>` sur *chaque* ligne du corps — la ligne "Aucune donnée" codée à la main dans les vues (`<tr><td colspan="N">Aucun ...</td></tr>`, un seul `<td>` réel) casse cette hypothèse. **Reproduit indépendamment sur `contacts.php` (recherche sans résultat) et `detail-groupe.php` (groupe vide)** : ce n'est donc pas un bug du nouveau module, mais un défaut latent de l'initialisation globale de DataTables (`app/index.php`), qui touchait déjà silencieusement toute page de l'application affichant un tableau vide (campagnes, rapports, messages...) — simplement jamais déclenché lors des tests précédents car une donnée de test était toujours présente. Corrigé par une garde générale : `$('#groupesTable tbody td[colspan]').length === 0` avant d'initialiser DataTables — si la ligne "Aucune donnée" est présente, DataTables n'est pas initialisé (le tableau reste un `<table>` HTML simple, ce qui est de toute façon suffisant pour zéro ligne).

**Tests réalisés** :
1. Suite PHPUnit complète → **88 tests, 180 assertions**, aucune régression.
2. Parcours navigateur réel de bout en bout (Playwright) : création d'un contact → apparition dans la liste → création d'un groupe → ajout du contact au groupe → création d'une campagne depuis le groupe → arrivée correcte sur l'écran de détail de campagne, **zéro erreur console/JS** après les deux correctifs (contre 1 à 3 erreurs par exécution avant).
3. Reproduction isolée et confirmation des deux bugs sur des scénarios minimaux avant correctif, puis re-vérification après correctif sur les mêmes scénarios.
4. Données de test nettoyées après vérification.

**Résultat** : le module Contacts/Groupes/Import CSV (§22-24) est fonctionnel de bout en bout et intégré au moteur de campagnes existant ; deux bugs systémiques préexistants et invisibles jusqu'ici (redirection post-mutation sur port non standard, DataTables sur tableau vide) sont corrigés pour l'ensemble de l'application, pas seulement le nouveau module.

## Suite session 3 (2026-09-12) : centre de notifications (§73)

**Objectif** : dernier écart du cahier des charges V2.0 explicitement redemandé — un centre de notifications persistant (solde faible, campagne terminée/partiellement échouée, import terminé), remplaçant les seuls messages flash ponctuels existants.

**Fichiers créés** :
- `database/migrations/010_notifications.sql`.
- `src/Services/NotificationService.php` — `create()`, `createUnlessRecentDuplicate()` (déduplication par fenêtre de temps, scoping par campagne quand pertinent — testé explicitement pour ne pas confondre deux campagnes différentes), `unreadCount()`, `recent()`, `markRead()`, `markAllRead()`.
- `tests/Integration/NotificationServiceTest.php` (5 tests).

**Fichiers modifiés (additif)** :
- `config/services.php` — `notifications()`.
- `server/campaign_worker.php` et `bin/process-campaign.php` — notification `campagne_terminee`/`campagne_partielle` à la transition réelle vers `COMPLETED`/`PARTIAL` (dédupliquée sur une fenêtre d'un an par campagne, donc jamais répétée même si plusieurs requêtes concurrentes observent la même transition).
- `server/app.php` — notification `import_termine` après un import de résultats ou de contacts ; nouveau handler `mark_all_notifications_read`.
- `server/infosAPI.php` — notification `solde_faible` quand le solde Orange passe sous `LOW_BALANCE_THRESHOLD` (`.env`, défaut 2000), dédupliquée sur 24h pour ne pas spammer à chaque chargement du dashboard.
- `app/index.php` — cloche dans l'en-tête (badge non-lu, liste déroulante des 10 dernières, icône par type, bouton "Tout marquer comme lu").

**Tests réalisés** :
1. Suite PHPUnit complète → **93 tests, 189 assertions**, aucune régression.
2. Vérification visuelle en navigateur réel (Playwright, capture d'écran) : badge "2" sur la cloche, notifications affichées avec icône/titre/message/date, mise en évidence des non-lues, "Tout marquer comme lu" fonctionnel (badge revient à 0 après clic), zéro erreur console/JS.
3. Données de test nettoyées après vérification.

**Résultat** : les trois derniers écarts identifiés par rapport au cahier des charges V2.0 (centre de notifications, module Contacts/Groupes, finalisation accessibilité/design system/responsive/sélection massive/test de charge) sont désormais tous traités. Restent uniquement, comme signalé précédemment : un vrai logo, et la bascule `APP_ENV=production`/`APP_DEBUG=false` le jour du déploiement réel.

## Suite session 3 (2026-09-12, fin) : identité visuelle (logo réel) + 3 bugs trouvés dans `health.php`

**Objectif** : dernier point demandé, « pas de vrai logo » — création d'une identité visuelle réelle (l'application utilisait encore le favicon générique bleu du thème "Gradient Able", jamais remplacé malgré le rebranding de Phase 67).

**Fichiers créés** : `assets/images/sms-orange-logo.svg` — icône bulle de conversation, dégradé orange de marque (`#ff7900`→`#ff9e40`), trois points blancs (symbole SMS universel), lisible à toute taille (favicon 16px comme logo 64px).

**Fichiers modifiés** : `assets/images/favicon.svg` (remplace le gribouillis bleu générique) ; `index.html` (logo dans la navbar + suppression d'une image de démo orpheline sans alt dans le hero) ; `app/index.php` (logo dans le brand du sidebar et du header) ; `app/login.php`, `app/health.php` (logo/favicon ajoutés — ces deux pages n'avaient jamais de favicon).

### 🐛 Trois bugs réels trouvés dans `app/health.php` en ajoutant simplement une balise favicon
En touchant ce fichier, relecture complète déclenchée par prudence — a révélé que la page de santé (livrée en Phase 47, cf. plus haut) avait été étendue **après coup, hors du suivi git normal** (un seul commit historique sur ce fichier, antérieur à cette session) avec des vérifications supplémentaires jamais exercées :
1. **`Session utilisateur`** appelait `auth()->getCurrentUser()` — méthode inexistante (la vraie est `user()`). Ce check renvoyait `ERROR` à chaque chargement, quel que soit l'état réel de la session.
2. **`Cache (taux de hit)`** appelait `cache()`, une fonction qui n'a jamais existé dans ce projet (aucune couche de cache générique). Techniquement rattrapée par un `catch` interne donc pas fatale, mais toujours `WARNING` avec un message trompeur.
3. **`Dernière synchronisation`** interrogeait `SELECT ... FROM sync_log`, une table qui n'existe pas dans le schéma. Cette erreur SQL faisait passer le **statut global de la page à `ERROR` en permanence**, y compris via `?format=json` (code HTTP 503) — un moniteur externe de disponibilité aurait vu l'application "en panne" 24h/24 alors qu'elle fonctionne normalement.

**Corrigés** : (1) `user()` au lieu de `getCurrentUser()` ; (2) le check "Cache" rapporte désormais l'état réel des deux caches existants (`storage/cache/orange_token.json`/`orange_balance.json`, âge en secondes) au lieu d'un concept fictif ; (3) le check "Dernière synchronisation Orange" utilise l'horodatage du cache de solde (mis à jour uniquement après un appel Orange réellement réussi) au lieu de la table inexistante. Un quatrième problème mineur corrigé au passage : un lien CSS mort (`assets/css/plugins/fontawesome.min.css`, 404 silencieux, jamais utilisé sur cette page) pointait vers un chemin qui n'a jamais existé — corrigé vers le vrai fichier (`assets/fonts/fontawesome.css`).

**Fichiers modifiés** : `DEPLOYMENT.md` — section "Health check" corrigée (indiquait à tort "non implémenté" alors qu'il l'était depuis la Phase 47) + nouvelle checklist de mise en ligne consolidant tous les points restants identifiés au fil des sessions (APP_ENV/APP_DEBUG, secrets à régénérer, contrat Orange, compte de test à supprimer, etc.).

**Tests réalisés** :
1. Suite PHPUnit complète → **93 tests, 189 assertions**, aucune régression.
2. Vérification navigateur réelle (Playwright, captures d'écran) : logo visible et cohérent sur la page d'accueil publique, la page de connexion, le sidebar/header de l'application → confirmé visuellement, pas seulement par la présence de la balise `<img>`.
3. `app/health.php` rechargé avant/après correctif : avant, statut global `ERROR` permanent (erreur SQL visible dans le contenu de la page) ; après, plus aucune erreur "Call to undefined"/SQL, zéro erreur console/réseau (le 404 CSS mort a aussi disparu).

**Résultat** : identité visuelle réelle en place (logo cohérent sur toutes les pages publiques et applicatives) ; la page de santé — outil censé garantir la confiance avant/pendant la production — ne ment plus en permanence sur l'état de l'application, ce qui aurait pu tromper un opérateur ou un moniteur externe le jour d'une vraie mise en ligne. Cahier des charges V2.0 : tous les écarts identifiés au fil des trois sessions sont désormais traités ; ne restent que des actions hors-code (régénération de secrets, renouvellement du contrat Orange, bascule finale `APP_ENV=production`).

---

# JOURNAL — SESSION 4 (2026-09-12) : pivot produit — suppression du module "Résultats académiques" et du champ "matricule"

**Demande explicite de l'utilisateur** : « élimine tout ce qui est en rapport avec le résultat et le matricule aussi, c'est un projet global destiné aux entreprises pas que à l'école ou université ». Le module "Résultats académiques" avait été retiré une première fois le 2026-09-08 puis reconstruit le 2026-09-11 pour répondre à un cahier des charges orienté envoi de résultats scolaires (voir sessions précédentes). L'utilisateur clarifie ici que le positionnement produit définitif est un outil générique de campagnes SMS pour entreprises — le module scolaire n'a donc plus sa place, cette fois de façon permanente.

**⚠️ Coordination multi-session** : au moment de cette demande, plusieurs sessions Claude Code actives ont été détectées sur le même dépôt, dont une (`sms-orange-9b`) qui a d'abord décrit être en train d'exécuter exactement les mêmes actions (renommages vers `archive/`, création de la même migration `011_remove_academic_results.sql`). Vérification faite via `git status`/horodatage des fichiers : les actions avaient déjà été réalisées par cette session-ci ; l'autre session a confirmé qu'elle n'avait rien exécuté elle-même (avait mal interprété un état déjà présent sur le disque partagé comme le sien) et s'est arrêtée sur cette tâche pour éviter toute collision. Aucune perte ni écrasement de travail.

**Fichiers archivés** (`git mv`, historique préservé — voir `archive/README.md` pour le détail complet) :
`app/templete/resultats.php`, `server/resultats_preview.php`, `server/resultats_list.php`, `server/resultats_export_errors.php`, `src/Services/AcademicResultsService.php`, `tests/Integration/AcademicResultsServiceTest.php`, `tests/Unit/AcademicResultsServiceMappingTest.php`.

**Migration** (`database/migrations/011_remove_academic_results.sql`, **destructive, exécutée avec accord explicite**) : `DROP TABLE resultats_academiques`, `DROP TABLE imports_resultats`, `ALTER TABLE messages DROP COLUMN matricule`.

**Fichiers modifiés** :
- `app/index.php` — entrée de menu et route `resultats` retirées.
- `config/services.php` — factory `academicResults()` retirée.
- `server/app.php` — les trois handlers `import_resultats`/`test_sms_resultats`/`create_resultats_campagne` retirés (153 lignes) ; import Excel générique (`import_excel_recipients`) : colonne `matricule` retirée des colonnes requises/mappées ; commentaires mentionnant les "résultats académiques" mis à jour.
- `server/config.php` — `getMessageCampagne()` ne sélectionne plus `matricule`.
- `src/Services/CampaignQueueService.php` — `addRecipients()`/`claimBatch()` ne gèrent plus `matricule` ; **`unique_key` recalculé sur `campagne_id + téléphone` seul** (au lieu de `campagne_id + téléphone + matricule`) — un même numéro ne peut désormais apparaître qu'une seule fois par campagne, ce qui est le comportement attendu pour un outil générique (avant, le `matricule` permettait volontairement plusieurs lignes pour un même numéro, utile uniquement pour distinguer plusieurs bulletins/matières d'un même étudiant).
- `src/Services/SmsTemplateService.php` — catégories de modèles remplacées par un jeu générique (`marketing`/`transactionnel`/`notification`/`rappel`/`alerte`, cahier des charges §31) au lieu de `resultats_academiques`/`absence`/`paiement`.
- `app/templete/modeles.php`, `app/templete/detail-campagne.php` — libellés de catégories mis à jour ; colonne "Matricule" retirée du journal de campagne ; texte d'aide de l'import Excel mis à jour.
- `bin/load-test.php` — génère des messages génériques au lieu de "Bonjour étudiant...", ne référence plus `matricule`.
- `tests/Integration/CampaignQueueServiceTest.php`, `tests/Integration/SmsTemplateServiceTest.php` — retrait des références à `matricule`/catégories scolaires.
- `ARCHITECTURE.md`, `DATABASE.md`, `README.md`, `DESIGN_SYSTEM.md`, `archive/README.md` — documentation mise à jour (sections du module retirées, migrations 005/006 annotées comme neutralisées par la 011, note de positionnement produit ajoutée).

**Tests réalisés** :
1. `php -l` sur tous les fichiers modifiés → aucune erreur.
2. Suite PHPUnit complète → **83 tests, 164 assertions** (10 tests de moins qu'avant, correspondant exactement aux deux fichiers de test archivés), aucune régression sur le reste.
3. Migration appliquée sur la vraie base `apiSms` (`php database/migrate.php`) → OK, colonne et tables confirmées supprimées.
4. Recherche exhaustive (`grep -rln "matricule\|resultats_academique\|academicResults"`) sur tout le code actif (hors `archive/`/`vendor/`) → plus aucune occurrence fonctionnelle, seulement des commentaires déjà mis à jour.

**Résultat** : SMS_ORANGE ne porte plus aucune trace fonctionnelle de son usage scolaire d'origine — le moteur de campagnes, les contacts/groupes, les modèles SMS et le centre de notifications restent entièrement génériques et utilisables par n'importe quelle entreprise, conformément au positionnement produit confirmé par l'utilisateur.

---

# JOURNAL — SESSION 5 (2026-09-12) : fondation multi-tenant — Entreprises/Organisations

**Demande explicite de l'utilisateur** : nouvel ordre de développement fourni après le pivot produit générique (session 4) — "Entreprises/organisations" en tête de liste, suivi de "Utilisateurs & rôles", puis dashboard, import, contacts, campagnes, etc.

**⚠️ Coordination multi-session** : au moment de cette demande, 4 autres sessions Claude Code étaient actives sur le même dépôt (`sms-orange-86`, `sms-orange-19`, `sms-orange-55`, `sms-orange-9b`). Vérifié auprès de chacune qu'aucune n'avait reçu le même ordre de développement et qu'aucune ne travaillait sur le schéma multi-tenant. `sms-orange-55` finissait alors le retrait du module "résultats académiques"/matricule (commit `6944d04`) et a explicitement demandé d'attendre son commit avant toute migration touchant les mêmes tables — respecté. Travail démarré uniquement après confirmation utilisateur ("la session est terminée tu peux commencer").

**Stratégie** : migration additive plutôt que big-bang. Une organisation "bootstrap" (id=1, reprend les infos UGLC-SC) absorbe toutes les données existantes ; `organization_id` est ajouté `NOT NULL` partout avec backfill à 1, donc le comportement observable est inchangé tant qu'une seule organisation existe. L'isolation devient réelle uniquement une fois qu'une 2ᵉ organisation est créée (`app/register.php`) — vérifié par un test dédié plutôt que supposé.

**Migration** (`database/migrations/012_organizations.sql`) : nouvelle table `organizations` ; `organization_id` (FK, `NOT NULL`, indexé) ajouté sur `utilisateurs`, `campagne`, `messages`, `contacts_v2`, `groupes_v2`, `imports_contacts`, `sms_templates`, `notifications`, `activity_logs`. L'unicité de `contacts_v2.telephone` passe de globale à `(organization_id, telephone)` — deux entreprises peuvent légitimement partager un numéro.

**Fichiers créés** :
- `src/Services/OrganizationService.php` — CRUD fiche entreprise.
- `app/register.php` — auto-inscription publique (crée organisation + utilisateur OWNER dans une transaction), branché sur le bouton "S'inscrire" déjà présent sur `index.html`.
- `app/templete/organisation.php` — page de paramètres (édition des infos de l'organisation courante).
- `tests/Integration/OrganizationIsolationTest.php` — 8 tests dédiés au critère d'acceptation §59.

**Fichiers modifiés** :
- `src/Services/AuthService.php` — rôle `OWNER` ajouté ; session enrichie de `organization_id`/`organization_nom` ; `organizationId()` ; `createUser()` requiert désormais un `organization_id` ; nouvelle méthode `registerOrganization()` transactionnelle (rollback si l'email existe déjà ou si l'organisation échoue).
- `config/services.php` — `contacts()`, `smsTemplates()`, `notifications()`, `activityLog()` construisent désormais leur Service avec `auth()->organizationId()` : l'isolation est portée par le Service lui-même, pas par chaque appelant. `campaignQueue()` reste volontairement non scopée (le worker CLI `server/campaign_worker.php` n'a pas de session et doit traiter toutes les organisations).
- `src/Services/ContactService.php`, `SmsTemplateService.php`, `NotificationService.php`, `ActivityLogger.php` — chaque requête SQL filtre/tague désormais par `organization_id` ; `addContactToGroup`/`removeContactFromGroup` vérifient explicitement que le groupe ET le contact appartiennent à l'organisation courante (sinon un id d'une autre organisation, deviné ou énuméré, pourrait être rattaché silencieusement).
- `src/Services/CampaignQueueService.php` — `createCampaign()` prend un `organization_id` explicite ; `addRecipients()` reprend l'organisation de la campagne elle-même par sous-requête (pas de changement de signature, donc pas de risque de désynchronisation entre le message et sa campagne).
- `server/config.php` — `getCampagne`, `getGlobalSmsStats`, `getSmsEvolution`, `getSuccessRateEvolution`, `getCampaignPerformance`, `getCampaignsReport`, `getTopErrors` prennent désormais un `organization_id`. Nouvelle fonction `assertOwnsCampagne()` : garde-fou anti-IDOR (404 si le `campagne_id` fourni par le client n'appartient pas à l'organisation courante), utilisée par tous les handlers mutants de campagne dans `server/app.php` (lancement, pause, reprise, annulation, retry, import) et par `app/templete/detail-campagne.php` en lecture.
- `server/app.php` — gate de rôle en tête de fichier élargie à `OWNER` ; `create_campagne`/`send_to_group` transmettent l'organisation courante ; nouveau handler `update_organisation` (cible toujours `auth()->organizationId()`, jamais un id soumis par le formulaire).
- `app/index.php` — nouvelle section de sidebar "Paramètres" → "Organisation" ; nom de l'organisation affiché dans le menu profil ; route `?page=organisation`.
- `bin/create-user.php` — accepte un `organization_id` optionnel (défaut : 1) ; commentaire mis à jour (l'auto-inscription publique existe désormais, ce script sert à ajouter des comptes à une organisation existante).
- `bin/load-test.php` — `createCampaign()` appelé avec l'organisation bootstrap.
- Tests existants (`ContactServiceTest`, `SmsTemplateServiceTest`, `NotificationServiceTest`, `ActivityLoggerTest`, `CampaignQueueServiceTest`, `AuthServiceTest`) — adaptés aux nouvelles signatures.

**Tests réalisés** :
1. `php -l` sur tous les fichiers modifiés → aucune erreur.
2. Suite PHPUnit complète → **91 tests, 180 assertions** (83 existants + 8 nouveaux sur l'isolation), aucune régression.
3. Migration appliquée sur la vraie base `apiSms` (`php database/migrate.php`) → organisation bootstrap créée (id=1, "UGLC-SC"), toutes les tables backfillées, comptés vérifiés ligne par ligne avant/après.
4. **Smoke test manuel de bout en bout** (serveur `php -S` local, cookies de session réels, nettoyé après coup) : inscription d'une organisation "Acme", création d'un contact et d'une campagne ; inscription d'une organisation "Beta" ; confirmé que Beta ne voit ni le contact ni la campagne d'Acme dans ses pages ; confirmé que la tentative de Beta de consulter ou de mettre en pause la campagne d'Acme via son `campagne_id` renvoie "Campagne introuvable" (404) alors qu'Acme y accède normalement.

**Résultat** : le socle multi-tenant est en place et vérifié — pas seulement une colonne ajoutée, mais une isolation effective à chaque lecture/écriture, testée à la fois unitairement et en conditions réelles (deux organisations concurrentes, tentative de traversée explicite). Aucune régression sur les fonctionnalités mono-tenant existantes (comportement identique tant qu'une seule organisation existe). Prochaine étape de la feuille de route utilisateur : "Utilisateurs & rôles" (rôles fins par organisation, gestion d'équipe/invitations — actuellement seul `bin/create-user.php` permet d'ajouter un coéquipier).

---

# JOURNAL — SESSION 6 (2026-09-12) : rôles fins et gestion d'équipe par organisation

**Demande explicite de l'utilisateur** : point 2 de la feuille de route fournie en session 5 — "Utilisateurs & rôles", juste après "Entreprises/organisations".

**Stratégie** : la porte de rôle unique de `server/app.php` (une seule liste de rôles autorisée pour TOUTES les mutations) ne permettait pas de distinguer "peut envoyer des campagnes" de "peut gérer l'équipe/l'organisation" — exactement la distinction que demande le cahier des charges (§6 : ADMIN gère organisation/utilisateurs, CAMPAIGN_MANAGER gère contacts/campagnes/envois, ANALYST est lecture seule + rapports). Plutôt que de renommer les rôles existants (`OPERATOR` notamment, seul rôle "actif" avant cette session), ajout de `CAMPAIGN_MANAGER` et `ANALYST` en conservant `OPERATOR` pour compatibilité — les deux sont traités identiquement partout. Deux nouvelles constantes centralisent la décision (`AuthService::MUTATION_ROLES`, `MANAGEMENT_ROLES`) pour éviter que la logique de permission se disperse dans plusieurs listes en dur.

**Fichiers créés** :
- `app/templete/equipe.php` — page "Équipe" (liste des membres de l'organisation, ajout, changement de rôle en ligne, retrait).
- `tests/Integration/AuthServiceTeamTest.php` — 9 tests (ajout de coéquipier, changement de rôle, retrait, et surtout la garde-fou "jamais retirer le dernier OWNER" testée à la fois par rétrogradation et par suppression, avec un scénario positif où un 2ᵉ OWNER existe).

**Fichiers modifiés** :
- `src/Services/AuthService.php` — `ROLES` étendu (`CAMPAIGN_MANAGER`, `ANALYST`) ; nouvelles constantes `MUTATION_ROLES`/`MANAGEMENT_ROLES` ; `createUser()` vérifie désormais lui-même l'unicité de l'email (`utilisateurs.email` est unique globalement, pas par organisation) au lieu de laisser remonter une `PDOException` brute — `registerOrganization()` simplifiée en conséquence (elle réutilisait la même vérification en double) ; nouvelles méthodes `usersInOrganization()`, `updateUserRole()`, `deleteUser()` avec garde-fou anti-"dernier OWNER".
- `server/app.php` — porte de rôle en tête de fichier basée sur `MUTATION_ROLES` ; nouveaux handlers `create_team_member`/`update_team_member_role`/`delete_team_member` (réservés à `MANAGEMENT_ROLES`, auto-suppression bloquée) ; `update_organisation` gagne la même restriction (un `CAMPAIGN_MANAGER` pouvait auparavant passer la porte globale et modifier les paramètres de l'organisation — fermé ici).
- `app/index.php` — lien de sidebar "Équipe" sous "Paramètres" ; route `?page=equipe`.

**Tests réalisés** :
1. `php -l` sur tous les fichiers modifiés → aucune erreur.
2. Suite PHPUnit complète → **100 tests, 192 assertions** (91 existants + 9 nouveaux), aucune régression.
3. **Smoke test manuel de bout en bout** (serveur `php -S` local) : création d'une organisation, ajout d'un coéquipier `CAMPAIGN_MANAGER` par le `OWNER`, tentative de rétrograder l'unique `OWNER` → rejetée avec le message attendu ; connexion en tant que `VIEWER` → page équipe sans le panneau de gestion, tentative directe de `create_team_member` → HTTP 403 (bloqué par la porte globale, VIEWER n'est même pas dans `MUTATION_ROLES`) ; connexion en tant que `CAMPAIGN_MANAGER` → `create_team_member` refusé (HTTP 403, message spécifique "seuls les administrateurs...") mais `create_contact` accepté normalement (302), confirmant la séparation mutation-métier / gestion d'équipe.

**Résultat** : chaque organisation peut désormais composer sa propre équipe avec des rôles différenciés, sans qu'un rôle opérationnel (CAMPAIGN_MANAGER/OPERATOR) puisse toucher aux paramètres de l'organisation ou à la composition de l'équipe, et sans qu'une organisation puisse se retrouver sans propriétaire. Prochaine étape de la feuille de route utilisateur : Dashboard (déjà largement construit en session 3, à revérifier dans le contexte multi-tenant) puis Import Excel/CSV intelligent.

---

# JOURNAL — SESSION 7 (2026-09-12) : audit du créateur de campagnes + correctif IDOR + créateur de campagnes réel

**Contexte** : avant de continuer la liste (Dashboard, Import, Contacts+groupes, Créateur de campagnes, Templates+variables, SMS de test, Envoi massif, Suivi temps réel, Retry, Rapports), audit de l'existant pour éviter de refaire ce qui est déjà construit. Résultat : Import/Contacts-groupes/Envoi massif/Suivi temps réel/Retry/Rapports étaient déjà fonctionnels (sessions précédentes) ; Dashboard partiel (KPI manquants) ; mais le "Créateur de campagnes" ne créait qu'une coquille DRAFT vide (nom+description) — aucun sélecteur d'audience, aucun éditeur de message avec compteur, aucune prévisualisation réelle, aucun SMS de test, aucune vérification de solde avant lancement — alors que les moteurs correspondants (`MessageTemplateService` pour les variables, `SmsCounterService` pour le comptage GSM-7/UCS-2) existaient déjà, corrects et testés unitairement, mais **sans aucun appelant réel** (code mort depuis la suppression du module résultats académiques en session 4, qui était leur seul utilisateur).

**⚠️ Correctif de sécurité trouvé en cours d'audit** (avant même de commencer les nouvelles fonctionnalités) : `server/campaign_worker.php`, appelé en polling AJAX direct depuis le navigateur avec un `campagne_id` visible côté client, ne vérifiait que `auth()->check()` (connecté) et jamais l'appartenance à l'organisation — oubli lors du passage multi-tenant (session 5), qui avait pourtant ajouté `assertOwnsCampagne()` partout dans `server/app.php`. N'importe quel utilisateur connecté pouvait faire avancer le traitement et lire la progression de la campagne d'une autre organisation. Corrigé et vérifié séparément (commit `305a7ee`) avant de poursuivre.

**Fichiers créés** :
- `server/campaign_tools.php` — endpoint AJAX en lecture seule : compteur de caractères/segments SMS (`SmsCounterService::analyze`) et prévisualisation réelle (`MessageTemplateService::render` contre un vrai contact via la nouvelle `ContactService::sampleContact()`, qui ne charge qu'une ligne au lieu de toute l'audience).

**Fichiers modifiés** :
- `src/Services/ContactService.php` — `sampleContact(?int $groupeId)` : un contact réel de l'audience, scopé organisation, `LIMIT 1`.
- `server/config.php` — `estimateSmsNeeded(int $campagneId)` : somme des segments (pas juste 1 destinataire = 1 SMS) des messages encore `en_attente`.
- `server/app.php` — nouveau handler `create_campaign_recipients` (compose un message pour l'audience "tous les contacts" ou "un groupe", rend les variables `{{nom}}`/`{{prenom}}`/`{{telephone}}`/`{{email}}` par destinataire avant `addRecipients()`) ; nouveau handler `send_test_sms` (§19, même service Orange que l'envoi réel, hors compteurs de campagne) ; `launch_campagne` bloque désormais si `estimateSmsNeeded() > solde Orange disponible` (§20), sauf en `dry_run` ; `send_to_group` corrigé pour rendre les variables au lieu d'envoyer le texte brut à tout le monde (même bug que ci-dessus, trouvé par le même audit).
- `app/templete/detail-campagne.php` — pour une campagne `DRAFT` sans destinataire : boutons "Tous mes contacts" / "Un groupe" / "Importer un fichier Excel" ouvrant une modale de composition (sélecteur de modèle, éditeur avec compteur live, aperçu réel, tout via `campaign_tools.php`) ; pour une campagne `DRAFT` avec destinataires : résumé "Avant de lancer" (destinataires, SMS estimés, solde disponible, bouton désactivé si insuffisant) et formulaire de SMS de test.

**Tests réalisés** :
1. `php -l` sur tous les fichiers modifiés → aucune erreur.
2. Suite PHPUnit complète → **106 tests, 202 assertions** (100 existants + 6 nouveaux : estimation par segments vs nombre de destinataires, exclusion des messages déjà traités, rendu des variables par contact, `sampleContact()` scopé et avec/sans groupe), aucune régression.
3. **Smoke test manuel approfondi, sans jamais déclencher un envoi Orange réel** (solde sandbox réel constaté : 906 unités, statut `EXPIRED`) : compteur/aperçu AJAX vérifié avec un message contenant un accent (a aussi révélé et corrigé un bug séparé : `json_encode()` renvoie silencieusement `false` — donc une réponse HTTP 200 vide sans indice d'erreur — sur des octets UTF-8 invalides, corrigé avec `JSON_INVALID_UTF8_SUBSTITUTE`) ; composition "tous mes contacts" avec variables → vérifié en base que chaque destinataire a reçu un message personnalisé distinct ; lancement en `dry_run=1` → traité de bout en bout jusqu'à `COMPLETED` avec `provider_message_id = 'DRY-RUN'` (aucun appel Orange réel) ; **test du blocage solde insuffisant** en insérant directement 1000 lignes `messages` synthétiques (contournement volontaire du wizard, uniquement pour dépasser le solde réel de 906 sans jamais appeler `queueCampaign()`), puis appel direct au handler `launch_campagne` sans `dry_run` → campagne restée `DRAFT`, zéro message envoyé, message d'erreur exact du cahier des charges (§20 : "Solde SMS insuffisant pour cette campagne.") ; validation du numéro de test invalide rejetée avant tout appel Orange.

**Résultat** : le créateur de campagnes correspond maintenant réellement au cahier des charges (§15-§20) — audience choisie explicitement, message avec variables et compteur SMS fiable, aperçu sur un vrai contact, SMS de test, blocage effectif si le solde est insuffisant — sans qu'aucun test n'ait risqué d'envoyer un SMS réel. Un deuxième correctif de sécurité (IDOR) a été trouvé et corrigé au passage. Prochaine étape : Dashboard (KPI manquants identifiés dans l'audit initial de cette session : SMS envoyés aujourd'hui/ce mois, nombre de campagnes actives, solde restant en carte dédiée, graphique de consommation de crédits).
