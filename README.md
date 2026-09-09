# SMS_ORANGE

Plateforme d'envoi de campagnes SMS et de messages administratifs, via l'API Orange SMS Guinée (`mediumart/orange-sms`), pour l'UGLC-SC.

## Fonctionnalités

- **Campagnes** : création, import de destinataires (fichier Excel : nom, prénom, matricule, téléphone, message), lancement en file d'attente par lots, suivi de progression en temps réel, pause/reprise/annulation, réessai des échecs.
- **Dashboard** : solde SMS Orange en direct, statistiques réelles, graphiques (évolution des envois, répartition, performance des campagnes).
- **Authentification** : session + rôles (`SUPER_ADMIN`, `ADMIN`, `OPERATOR`, `VIEWER`).

## Stack

PHP 8 procédural avec quelques classes ciblées (`src/Services/`), PostgreSQL, Composer. Pas de framework — voir `ARCHITECTURE.md`.

## Démarrage rapide (développement, XAMPP)

```bash
composer install
cp .env.example .env   # puis renseigner DB_* et ORANGE_*
php database/migrate.php
php bin/create-user.php "Ton Nom" toi@example.com "MotDePasseFort123" SUPER_ADMIN
```

Puis ouvrir `http://localhost/sms_orange/app/login.php`.

## Documentation

- [ARCHITECTURE.md](ARCHITECTURE.md) — organisation du code, moteur de campagnes.
- [DATABASE.md](DATABASE.md) — schéma, migrations.
- [ORANGE_API.md](ORANGE_API.md) — intégration Orange, gestion du token, erreurs.
- [SECURITY.md](SECURITY.md) — authentification, rôles, CSRF, secrets.
- [DEPLOYMENT.md](DEPLOYMENT.md) — mise en production, worker CLI.
- [AUDIT.md](AUDIT.md) — audit complet et journal détaillé de chaque phase de la refonte (fichiers modifiés, tests réalisés, bugs trouvés/corrigés).

## Commandes utiles

```bash
php database/migrate.php              # applique les migrations non exécutées
php bin/create-user.php ...           # crée un compte (voir SECURITY.md)
php bin/process-campaign.php <id>     # vide une campagne (cron/Planificateur de tâches)
php bin/process-campaign.php --daemon # traite en continu toute campagne QUEUED/RUNNING
php bin/load-test.php [n] [batch]     # test de charge (dry-run, nettoie ses propres données)
```

## Tests

```bash
php vendor/bin/phpunit --testsuite unit         # logique pure, aucune base de données
php vendor/bin/phpunit --testsuite integration  # contre apiSms, dry-run uniquement, se nettoie
```

Voir `app/health.php` pour un état en direct du système (DB, API Orange, fichiers, configuration).
