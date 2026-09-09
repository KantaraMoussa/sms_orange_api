# Déploiement

## Prérequis

- PHP 8.0+ avec extensions `pdo_pgsql`, `pgsql`
- PostgreSQL 9.5+ (requis pour `SELECT ... FOR UPDATE SKIP LOCKED`, utilisé par le moteur de campagnes)
- Composer

## Installation

```bash
composer install --no-dev   # en production
cp .env.example .env
# renseigner DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASSWORD et ORANGE_CLIENT_ID/ORANGE_CLIENT_SECRET/ORANGE_SENDER_NAME
php database/migrate.php
php bin/create-user.php "Nom" email@example.com "MotDePasseFort" SUPER_ADMIN
```

Variables d'environnement : voir `.env.example`. `APP_ENV=production` et `APP_DEBUG=false` en production (actuellement lus par `config/bootstrap.php` mais pas encore exploités pour masquer les erreurs PHP — à faire avant mise en production réelle : configurer `display_errors=Off` / `log_errors=On` côté `php.ini` du serveur).

## Permissions fichiers

`storage/cache/` et `storage/logs/` doivent être accessibles en écriture par l'utilisateur du serveur web (cache du token Orange, futurs logs).

## Worker de campagnes en production

Le mode par défaut (`server/campaign_worker.php`, appelé en AJAX depuis la page de détail d'une campagne) suffit pour un usage interactif mais dépend d'un onglet navigateur ouvert. Pour un vrai traitement en arrière-plan, indépendant du navigateur :

**Linux (cron)** :
```
* * * * * php /chemin/vers/sms_orange/bin/process-campaign.php --daemon >> /chemin/vers/storage/logs/worker.log 2>&1
```
(à protéger par un verrou de type `flock` si le cron doit garantir une seule instance — le script `--daemon` tourne déjà en boucle continue, donc un lancement one-shot par minute suffit à le redémarrer s'il s'est arrêté).

**Windows (Planificateur de tâches)** : créer une tâche qui exécute `php.exe bin\process-campaign.php --daemon`, déclenchée au démarrage, avec redémarrage automatique en cas d'échec.

Arrêt : `Ctrl+C` en interactif, ou terminer le processus via le gestionnaire de tâches / `taskkill` en tâche planifiée — pas de fichier de signal dédié, volontairement simple.

## Sauvegarde

Aucune stratégie de sauvegarde PostgreSQL automatisée n'est fournie par ce dépôt. Recommandation minimale : `pg_dump` quotidien de la base `apiSms` (campagnes, historique d'envoi) vers un stockage externe au serveur.

## Health check

Non implémenté à ce stade (cahier des charges §47). À construire : une page qui vérifie la connexion DB (`db()->query('SELECT 1')`), l'authentification Orange (`orangeSms()->getBalance()`), et l'inscriptible de `storage/`.
