# Base de données

PostgreSQL, base **`apiSms`** — c'est la seule base réellement utilisée par l'application (voir AUDIT.md §2 pour l'historique : une base `school` référencée dans un script archivé n'a jamais été branchée et est inaccessible).

## Tables

### `campagne`
| Colonne | Type | Note |
|---|---|---|
| id | serial PK | |
| nom, description | | |
| statut | varchar | `DRAFT`/`QUEUED`/`RUNNING`/`PAUSED`/`COMPLETED`/`PARTIAL`/`FAILED`/`CANCELLED` — défaut `DRAFT` depuis la migration 002 |
| type | varchar | `generique`/`test` — informatif |
| total_destinataires, nombre_envoyes, nombre_echecs | integer | mis à jour par `CampaignQueueService` |
| batch_size | integer | taille de lot pour `campaign_worker.php`/`process-campaign.php` |
| dry_run | boolean | si vrai, aucun appel réel à Orange n'est fait (simulation, §57 du cahier des charges) |
| created_by, date_lancement, date_completion | | |

### `messages` (= destinataires de campagne, une ligne par tâche d'envoi)
| Colonne | Type | Note |
|---|---|---|
| id | serial PK | |
| campagne_id | integer NOT NULL | |
| contenu | text | corps du message |
| destinataire | varchar | format canonique `+224XXXXXXXXX` (voir `PhoneNumberService`) |
| statut | varchar | `en_attente`/`en_cours`/`envoye`/`echec`/`annule` |
| matricule, nom, prenom | varchar | identité du destinataire, génériques (alimentés par l'import Excel), optionnels |
| unique_key | varchar | `sha256(campagne_id\|téléphone\|matricule)`, unique (idempotence) |
| tentative_count | integer | nombre d'essais d'envoi |
| error_code, error_message | | voir la classification dans `SmsErrorClassifier` |
| locked_at | timestamp | posé pendant le traitement d'un lot |
| date_traitement | timestamp | quand le statut final (`envoye`/`echec`) a été atteint |
| provider_message_id | varchar | référence retournée par Orange, si disponible |

Index : `idx_messages_campagne_statut (campagne_id, statut)` (claim de lot), `idx_messages_matricule`.

### `sms`, `sms_destinataires`
Présentes en base depuis l'origine du projet, **jamais utilisées par le code applicatif** (ni avant ni après cette refonte — le modèle `campagne`/`messages` a été préféré). Conservées telles quelles, non supprimées.

### `utilisateurs`
| Colonne | Type | Note |
|---|---|---|
| id | serial PK | |
| nom, email | varchar | |
| mot_de_passe | text | hash `password_hash()` |
| role | varchar | `SUPER_ADMIN`/`ADMIN`/`OPERATOR`/`VIEWER` |
| date_creation | timestamp | |

Table présente depuis l'origine, vide jusqu'à la Phase 37/38 (authentification) de la refonte.

### `schema_migrations`
`filename` (PK), `applied_at` — suivi des migrations déjà appliquées par `database/migrate.php`.

## Tables supprimées

Le module Contacts/Groupes (`contacts`, `groupes`, `groupe_contacts`) a été supprimé le 2026-09-08 à la demande explicite de l'utilisateur (migration `004_remove_contacts_and_notes.sql`), de même que les colonnes `notes`/`niveaux` de `messages` (module "résultats académiques"/Notes). Voir `archive/README.md` pour le détail de ce qui a été retiré du code applicatif en parallèle.

## Migrations

```bash
php database/migrate.php
```

Applique dans l'ordre alphabétique tout fichier `database/migrations/*.sql` non encore listé dans `schema_migrations`, dans une transaction.

| Fichier | Contenu |
|---|---|
| `001_campaign_engine.sql` | Colonnes de pilotage sur `campagne`, colonnes de file d'attente sur `messages` (additif). |
| `002_campaign_status_default.sql` | `campagne.statut` défaut `en_attente` → `DRAFT` (additif). |
| `003_recipient_identity.sql` | Colonnes `nom`/`prenom` sur `messages` pour l'import Excel direct (additif). |
| `004_remove_contacts_and_notes.sql` | **Destructive, exécutée avec accord explicite** : suppression de `contacts`/`groupes`/`groupe_contacts` et des colonnes `notes`/`niveaux` de `messages`. |

Pour une nouvelle migration : créer `005_....sql` (préfixe numérique croissant), relancer `php database/migrate.php`.
