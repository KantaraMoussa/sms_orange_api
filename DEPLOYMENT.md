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

`app/health.php` (cahier des charges §47) — 8 vérifications (Système/PHP, Base de données, API Orange, Fichiers/storage, Configuration/.env, File d'attente, Session utilisateur, Cache des jetons Orange, Dernière synchronisation Orange), chacune `OPERATIONAL`/`WARNING`/`ERROR`. Sortie JSON disponible (`?format=json`, HTTP 503 si `ERROR`) pour une supervision externe — **vérifier après toute modification de ce fichier qu'aucun check ne référence une table/méthode inexistante** (trois cas réels trouvés et corrigés le 2026-09-12 : `sync_log` n'existe pas, `AuthService::getCurrentUser()` n'existe pas, `cache()` n'existe pas — voir AUDIT.md).

## Checklist avant mise en ligne réelle

- [ ] `APP_ENV=production` et `APP_DEBUG=false` dans `.env` (actuellement `development`/`true` — volontaire pendant le développement, `health.php` le signale en `WARNING`).
- [ ] `display_errors=Off` / `log_errors=On` dans le `php.ini` du serveur.
- [ ] Régénérer le secret client Orange et le mot de passe PostgreSQL (compromis dans l'historique git, voir SECURITY.md) — indépendant du code, à faire dès que possible.
- [ ] Vérifier/renouveler le contrat SMS Orange (`health.php` a signalé un statut `EXPIRED` pendant le développement — à confirmer avant tout envoi réel).
- [ ] Supprimer le compte de test `admin@test.local` une fois un vrai compte créé (`bin/create-user.php`).
- [ ] `composer install --no-dev` (pas de PHPUnit ni ses dépendances en production).
- [ ] `php database/migrate.php` sur la base de production.
- [ ] Lancer `php app/health.php?format=json` (ou visiter la page) et confirmer `"status": "OPERATIONAL"` (les `WARNING` ci-dessus sont attendus tant que les points précédents ne sont pas traités).
- [ ] Mettre en place le worker de production (`bin/process-campaign.php --daemon`, voir ci-dessus) plutôt que de dépendre du worker AJAX navigateur.
- [ ] Mettre en place une sauvegarde `pg_dump` régulière (voir ci-dessous).

Identité visuelle : logo/favicon réels en place depuis le 2026-09-12 (`assets/images/sms-orange-logo.svg`) — plus de placeholder générique du thème.
