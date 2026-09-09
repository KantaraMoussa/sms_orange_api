# Architecture

## Principe

PHP procédural pour les vues et les fonctions de lecture (héritage assumé, voir AUDIT.md), avec les parties à responsabilité claire extraites en classes sous `src/Services/`, autochargées via Composer (PSR-4, `App\`). Pas de framework MVC imposé — migration progressive, pas de réécriture complète (cahier des charges §48).

## Arborescence

```
app/                    Frontend (vues) + routeur simple
  index.php             Shell HTML (sidebar/header) + routeur par ?page=
  login.php, logout.php Authentification
  templete/*.php        Fragments de vue par page (dashboard, campagne, rapports...)

server/                 Backend
  config.php            Bootstrap legacy + fonctions de lecture (getCampagne, getGlobalSmsStats, etc.)
  app.php               Tous les handlers POST (mutations) — protégé par auth+CSRF+rôle
  campaign_worker.php   Endpoint JSON appelé en boucle par le navigateur (1 lot par appel)
  infosAPI.php          Synchronise le solde/statistiques Orange en session (page dashboard uniquement)

config/                 Bootstrap applicatif (functions-as-factories, pas de conteneur DI)
  bootstrap.php         Charge .env (vlucas/phpdotenv), expose env()
  database.php          Expose db() — connexion PDO unique et réutilisée
  orange.php            Expose orangeSms() — instance partagée d'OrangeSmsService
  auth.php              Expose auth() — instance partagée d'AuthService
  csrf.php              csrf_token()/csrf_field()/csrf_verify()
  services.php          Point d'entrée qui charge tout ce qui précède + campaignQueue()

src/Services/           Classes avec une responsabilité claire
  OrangeSmsService.php     Encapsule le SDK mediumart/orange-sms + cache de token
  CampaignQueueService.php Cœur du moteur de campagnes (voir plus bas)
  PhoneNumberService.php   Normalisation/validation des numéros guinéens
  AuthService.php          Authentification par session + rôles

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

## Moteur de campagnes (`CampaignQueueService`)

Remplace l'ancien modèle "boucle synchrone dans une requête HTTP" (`send_sms.php`, archivé) par :

```
Campagne (DRAFT) → destinataires importés → QUEUED → lots traités par campaign_worker.php
                                                        ou bin/process-campaign.php
                → RUNNING (mise à jour automatique dès qu'un lot est en attente)
                → COMPLETED / PARTIAL (selon présence d'échecs)
```

- **Idempotence** : chaque destinataire a un `unique_key = sha256(campagne_id|téléphone|matricule)`, contraint par un index unique partiel — un même destinataire ne peut jamais être mis en file deux fois dans la même campagne.
- **Verrouillage de lot** : `claimBatch()` utilise `SELECT ... FOR UPDATE SKIP LOCKED` dans une transaction courte (juste le temps de marquer les lignes `en_cours`) — deux workers ne traiteront jamais le même destinataire, et la transaction n'est jamais tenue ouverte pendant l'appel réseau à Orange.
- **Classification d'erreurs et retry** : `INVALID_PHONE`/`AUTH_ERROR`/`INSUFFICIENT_BALANCE` ne sont jamais réessayés ; `API_ERROR`/`TIMEOUT`/`RATE_LIMIT`/`UNKNOWN_ERROR` le sont, jusqu'à 3 tentatives.
- **Deux façons de faire avancer une campagne** : `server/campaign_worker.php` (polling AJAX depuis la page de détail, un lot par appel HTTP, ne bloque jamais le navigateur) ou `bin/process-campaign.php` (worker CLI pour une vraie production, via cron/Planificateur de tâches Windows).

## Ce qui n'a volontairement pas changé

- Pas de framework (Laravel/Symfony) : migration progressive assumée.
- `messages` reste la table des destinataires de campagne (renommer en `campaign_recipients` casserait des fonctions existantes sans bénéfice proportionné à ce stade).
- Pas de vraie file d'attente externe (Redis, RabbitMQ) : le modèle PostgreSQL + `SKIP LOCKED` suffit au volume visé et évite une dépendance d'infrastructure supplémentaire sous XAMPP.

Voir `AUDIT.md` pour le détail phase par phase (dates, fichiers, tests, bugs trouvés).
