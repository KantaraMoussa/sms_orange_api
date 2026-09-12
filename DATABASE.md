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
| nom, prenom | varchar | identité du destinataire, génériques (alimentés par l'import Excel), optionnels |
| unique_key | varchar | `sha256(campagne_id\|téléphone)`, unique (idempotence) — un même numéro ne peut apparaître qu'une fois par campagne |
| tentative_count | integer | nombre d'essais d'envoi |
| error_code, error_message | | voir la classification dans `SmsErrorClassifier` |
| locked_at | timestamp | posé pendant le traitement d'un lot |
| date_traitement | timestamp | quand le statut final (`envoye`/`echec`) a été atteint |
| provider_message_id | varchar | référence retournée par Orange, si disponible |

Index : `idx_messages_campagne_statut (campagne_id, statut)` (claim de lot).

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

### `activity_logs`
Journal d'activité / piste d'audit (§35/§64) : `user_nom`, `action` (connexion/deconnexion/creation_campagne/lancement_campagne/pause_campagne/reprise_campagne/annulation_campagne/retry_campagne/import_contacts/creation_contact/suppression_contact/creation_groupe/suppression_groupe/creation_campagne_groupe/creation_modele/modification_modele/duplication_modele/archivage_modele/desarchivage_modele), `campagne_id` (FK, nullable), `details`, `created_at`. Alimenté par `ActivityLogger`, consulté depuis `?page=journal`.

### `contacts_v2`, `groupes_v2`, `groupe_contacts_v2`, `imports_contacts`
Module Contacts/Groupes (§22-24), recréé le 2026-09-12 après une suppression (§004) puis une nouvelle demande explicites de l'utilisateur — schéma dédié (suffixe `_v2`) plutôt que de réutiliser les anciens noms `contacts`/`groupes`, pour ne jamais confondre avec l'historique de suppression documenté dans `archive/README.md`.

| Table | Colonnes clés |
|---|---|
| `contacts_v2` | `nom`, `prenom`, `telephone` (unique, normalisé `+224XXXXXXXXX`), `telephone_brut`, `email`, `statut` |
| `groupes_v2` | `nom`, `description`, `created_by` |
| `groupe_contacts_v2` | `(groupe_id, contact_id)` — clé primaire composite, `ON DELETE CASCADE` des deux côtés |
| `imports_contacts` | rapport d'import (même modèle que `imports_resultats`) |

Un contact est unique par téléphone (index unique) : un import ou un ajout avec un numéro déjà connu **met à jour** le contact existant (nom/prénom/email) plutôt que de le dupliquer. Un envoi à un groupe (`send_to_group`) crée une campagne via `CampaignQueueService`, comme n'importe quelle autre campagne — aucune logique d'envoi séparée.

### `notifications`
Centre de notifications (§73) : `type` (`solde_faible`/`campagne_terminee`/`campagne_partielle`/`import_termine`), `titre`, `message`, `campagne_id` (FK, nullable), `lu`, `created_at`. Alimentée par `NotificationService`, affichée dans la cloche de l'en-tête (`app/index.php`). Les notifications de type solde/campagne sont dédupliquées (`createUnlessRecentDuplicate()`) pour ne jamais spammer.

### `sms_templates`
Bibliothèque de modèles SMS réutilisables (§25) : `nom`, `categorie` (marketing/transactionnel/notification/rappel/alerte), `contenu` (variables `{{...}}` supportées, rendues par `MessageTemplateService`), `archive`, `created_by`, `created_at`, `updated_at`. Gérée depuis `?page=modeles`.

## Tables supprimées

- Le module Contacts/Groupes d'origine (`contacts`, `groupes`, `groupe_contacts`) a été supprimé le 2026-09-08 à la demande explicite de l'utilisateur (migration `004_remove_contacts_and_notes.sql`), de même que les colonnes `notes`/`niveaux` de `messages`. Un module Contacts/Groupes a depuis été recréé sur un schéma différent (`contacts_v2`/`groupes_v2`, voir ci-dessus) suite à une nouvelle demande de l'utilisateur.
- Le module "Résultats académiques" (`resultats_academiques`, `imports_resultats`, colonne `messages.matricule`) a été supprimé le 2026-09-12 à la demande explicite de l'utilisateur : SMS_ORANGE est un outil générique de campagnes SMS pour entreprises, pas un produit scolaire (migration `011_remove_academic_results.sql`). Voir `archive/README.md` et `AUDIT.md` pour le détail de ce qui a été retiré du code applicatif en parallèle.

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
| `005_login_lockout.sql` | Additif : `failed_attempts`/`locked_until` sur `utilisateurs` (verrouillage anti brute-force). |
| `005_academic_results.sql` | Additif à l'origine (crée `resultats_academiques`/`imports_resultats`) — **tables supprimées depuis par `011_remove_academic_results.sql`**. |
| `006_resultats_derniere_campagne.sql` | Additif à l'origine (`resultats_academiques.derniere_campagne_id`) — **colonne/table supprimées depuis par `011_remove_academic_results.sql`**. |
| `007_activity_logs.sql` | Additif : crée `activity_logs` (journal d'activité / audit trail, §35/§64). |
| `008_sms_templates.sql` | Additif : crée `sms_templates` (bibliothèque de modèles SMS réutilisables, §25). |
| `009_contacts_groups.sql` | Additif : recrée `contacts_v2`/`groupes_v2`/`groupe_contacts_v2`/`imports_contacts` (§22-24). |
| `010_notifications.sql` | Additif : crée `notifications` (centre de notifications, §73). |
| `011_remove_academic_results.sql` | **Destructive, exécutée avec accord explicite** : suppression de `resultats_academiques`/`imports_resultats` et de la colonne `messages.matricule` — le produit devient un outil générique entreprises, plus scolaire. |

> **Note** : les deux migrations ci-dessus portent le même préfixe `005_` — créées en parallèle par deux sessions de travail différentes le même jour. Sans conséquence pratique : `schema_migrations` suit chaque fichier par son nom complet (pas seulement le préfixe), les deux ont été appliquées sans conflit (tables distinctes), et `database/migrate.php` les trie par ordre alphabétique complet. Laissé tel quel plutôt que renommé, pour ne pas risquer de perturber le suivi déjà enregistré sur la base de production.

Pour une nouvelle migration : créer `012_....sql` (préfixe numérique croissant), relancer `php database/migrate.php`.
