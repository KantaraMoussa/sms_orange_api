# Architecture

## Principe (SESSION 15, 2026-09-13 : réorganisation MVC)

Architecture MVC complète avec routeur, à la demande explicite de l'utilisateur ("REORGANISE LE PROJET AVEC UNE ARCHITECTURE MVC" puis confirmation "MVC complet avec routeur" — voir AUDIT.md session 15). Ceci **remplace** le principe précédent ("pas de framework MVC imposé, migration progressive, cahier des charges §48") qui avait guidé les 14 sessions précédentes : décision explicitement révisée par l'utilisateur, pas un oubli.

Un unique point d'entrée (`app/index.php`, front controller) dispatche chaque requête, par nom de route, vers une méthode de Controller (`src/Controllers/`), qui appelle le Modèle (`server/config.php` + `src/Services/`) et rend une Vue (`app/Views/`). Pas de `mod_rewrite`/`.htaccess` dans cet environnement XAMPP (et modifier la configuration Apache était hors du périmètre de cette réorganisation) — les routes sont donc adressées par nom via `?route=xxx` plutôt que par un chemin joli, mais restent un vrai routeur (nom → [Controller, action]), pas la chaîne `if/elseif` qu'il remplace.

## Arborescence

```
app/
  index.php             Front controller unique : auth (sauf 3 routes JSON, voir plus bas),
                         charge le Modèle legacy, dispatche via Router
  routes.php            Table des routes : nom -> [Controller::class, méthode], GET et POST séparés
  login.php, logout.php, register.php, health.php
                         Authentification/health check — restent des scripts autonomes hors
                         routeur (déjà à responsabilité unique, aucun bénéfice MVC à les absorber)
  Views/
    layouts/app.php      Shell HTML (sidebar/header/pied de page), reçoit $content
    <resource>/index.php, show.php   Un dossier de vues par ressource (campaigns, contacts, ...)
    errors/404.php

src/
  Core/
    Router.php           Table de routes GET/POST, dispatch(method, routeName)
    Controller.php        Classe de base : view(), redirect(), json(), requireMutationRole(),
                          requireManagementRole(), requireCsrf(), flash(), redirectBack()
    View.php              Rendu d'une vue + layout (extract() + require + output buffering)
    helpers.php           route($name, $params) : construit une URL ?route=xxx
  Controllers/            Un Controller par ressource (voir liste ci-dessous)
  Services/               Classes métier (voir plus bas, inchangées par cette réorganisation)

server/
  config.php              Le Modèle "legacy" : fonctions globales de lecture/logique métier
                          (getCampagne, getGlobalSmsStats, assertOwnsCampagne, redirectBack, etc.)
                          — INCHANGÉ par cette réorganisation (voir "Ce qui n'a pas changé" plus bas)

config/                 Bootstrap applicatif (functions-as-factories, pas de conteneur DI)
  bootstrap.php         Charge .env (vlucas/phpdotenv), expose env()
  database.php          Expose db() — connexion PDO unique et réutilisée
  orange.php            Expose orangeSms() — instance partagée d'OrangeSmsService
  auth.php              Expose auth() — instance partagée d'AuthService
  csrf.php              csrf_token()/csrf_field()/csrf_verify()
  services.php          Point d'entrée qui charge tout ce qui précède + campaignQueue() + helpers.php

src/Services/           Classes avec une responsabilité claire
  OrangeSmsService.php     Encapsule le SDK mediumart/orange-sms + cache de token
  CampaignQueueService.php Cœur du moteur de campagnes (voir plus bas)
  PhoneNumberService.php   Normalisation/validation des numéros guinéens
  AuthService.php          Authentification par session + rôles
  MessageTemplateService.php Rendu des variables {{...}} — même moteur pour l'aperçu et l'envoi réel
  SmsCounterService.php      Calcul du nombre de SMS (encodage GSM-7/UCS-2, segments)
  ActivityLogger.php         Journal d'activité / audit trail (qui a fait quoi, quand)
  SmsTemplateService.php     Bibliothèque de modèles SMS réutilisables (CRUD, catégories)
  ContactService.php         Contacts/Groupes (§22-24) : CRUD, appartenance, import CSV/Excel
  NotificationService.php    Centre de notifications (§73) : création dédupliquée, lu/non lu

bin/                    Scripts CLI
  create-user.php          Provisionne un compte (pas d'inscription publique)
  process-campaign.php     Worker de campagne pour cron/Planificateur de tâches

database/
  migrate.php              Mini-runner de migrations (table schema_migrations)
  migrations/*.sql          Migrations SQL, additives uniquement

storage/
  cache/orange_token.json  Cache du token OAuth Orange (non versionné)

archive/                Scripts/pages retirés du chemin actif, conservés pour référence (voir archive/README.md)
```

## Routeur MVC

**Convention de nommage** : `ressource.action` (`campaigns.show`, `contacts.create`, `team.updateRole`...). GET pour tout ce qui affiche une vue, POST pour toute mutation — un même nom de route n'est jamais enregistré à la fois en GET et en POST.

**Controllers** (`src/Controllers/`) : `DashboardController`, `SmsSenderController`, `NotificationController`, `ContactController`, `GroupController`, `SegmentController`, `CampaignController` (le plus gros — reprend `server/app.php` + `server/campaign_worker.php` + `server/campaign_tools.php`), `TemplateController`, `ReportController`, `SmsHistoryController`, `JournalController`, `CreditController`, `OrganizationController`, `TeamController`, `ErrorController` (404).

**Trois routes restent des endpoints JSON purs** (`campaigns.poll`, `campaigns.previewTools`, `segments.previewCount` — anciennement `server/campaign_worker.php`/`campaign_tools.php`/`segment_tools.php`, appelés en `fetch()` depuis le navigateur) : `app/index.php` les exempte du `auth()->requireLogin()` global et leur fait répondre un `401` JSON à la place, exactement comme avant cette réorganisation — une redirection HTML casserait `response.json()` côté client si la session expire pendant un polling.

**Chaque action de mutation** appelle explicitement `requireMutationRole()` (ou `requireManagementRole()`/`requireRole(['SUPER_ADMIN'])` selon la ressource) puis `requireCsrf()` en début de méthode — remplace la garde unique en tête de `server/app.php` qui s'appliquait à tout le fichier d'un coup.

## Moteur de campagnes (`CampaignQueueService`)

Remplace l'ancien modèle "boucle synchrone dans une requête HTTP" (`send_sms.php`, archivé) par :

```
Campagne (DRAFT) → destinataires importés → QUEUED → lots traités par CampaignController::poll()
                                                        (route campaigns.poll) ou bin/process-campaign.php
                → RUNNING (mise à jour automatique dès qu'un lot est en attente)
                → COMPLETED / PARTIAL (selon présence d'échecs)
```

- **Idempotence** : chaque destinataire a un `unique_key = sha256(campagne_id|téléphone)`, contraint par un index unique partiel — un même numéro ne peut jamais être mis en file deux fois dans la même campagne.
- **Verrouillage de lot** : `claimBatch()` utilise `SELECT ... FOR UPDATE SKIP LOCKED` dans une transaction courte (juste le temps de marquer les lignes `en_cours`) — deux workers ne traiteront jamais le même destinataire, et la transaction n'est jamais tenue ouverte pendant l'appel réseau à Orange.
- **Classification d'erreurs et retry** : `INVALID_PHONE`/`AUTH_ERROR`/`INSUFFICIENT_BALANCE` ne sont jamais réessayés ; `API_ERROR`/`TIMEOUT`/`RATE_LIMIT`/`UNKNOWN_ERROR` le sont, jusqu'à 3 tentatives.
- **Deux façons de faire avancer une campagne** : route `campaigns.poll` (polling AJAX depuis la page de détail, un lot par appel HTTP, ne bloque jamais le navigateur) ou `bin/process-campaign.php` (worker CLI pour une vraie production, via cron/Planificateur de tâches Windows).

## Ce qui n'a volontairement pas changé (même après la réorganisation MVC)

- Pas de framework tiers (Laravel/Symfony) : le routeur/Controller/View ci-dessus est fait main, volontairement minimal — ni ORM, ni conteneur DI, ni moteur de templates. Cohérent avec le reste du projet (functions-as-factories dans `config/services.php` plutôt qu'un conteneur).
- **Le Modèle (`server/config.php` + `src/Services/*`) est resté intact** : ces fonctions/classes étaient déjà correctement séparées des vues avant la réorganisation MVC (aucune logique de présentation dedans), et 150 tests automatisés les appellent directement par leur nom (`getSingleCampagne()`, `assertOwnsCampagne()`, etc. — voir `tests/Integration/CampaignQueueServiceTest.php`, `AnalyticsTest.php`). Les réécrire en classes `Model` n'aurait rien apporté à l'objectif de l'utilisateur (remplacer le routeur `?page=` et le fourre-tout `server/app.php`) tout en risquant de casser ces tests pour un bénéfice nul.
- `messages` reste la table des destinataires de campagne (renommer en `campaign_recipients` casserait des fonctions existantes sans bénéfice proportionné à ce stade).
- Pas de vraie file d'attente externe (Redis, RabbitMQ) : le modèle PostgreSQL + `SKIP LOCKED` suffit au volume visé et évite une dépendance d'infrastructure supplémentaire sous XAMPP.
- Pas d'URLs "jolies" (`/campagnes/5` plutôt que `?route=campaigns.show&id=5`) : pas de `mod_rewrite` actif dans cet environnement XAMPP et modifier la configuration Apache était hors du périmètre demandé.

**Positionnement produit** : le module "Résultats académiques" (import/filtrage/envoi de résultats scolaires par matricule) a existé brièvement puis a été retiré le 2026-09-12 à la demande explicite de l'utilisateur — SMS_ORANGE est un outil de campagnes SMS générique pour entreprises, pas un produit scolaire. Le moteur de campagnes (`CampaignQueueService`) reste entièrement générique (Contacts/Groupes, import Excel nom/prénom/téléphone/message) et n'a jamais dépendu du module scolaire.

Voir `AUDIT.md` pour le détail phase par phase (dates, fichiers, tests, bugs trouvés).
