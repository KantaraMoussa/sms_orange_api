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

### `activity_logs`
Journal d'activité / piste d'audit (§35/§64) : `user_nom`, `action` (connexion/deconnexion/creation_campagne/lancement_campagne/pause_campagne/reprise_campagne/annulation_campagne/retry_campagne/import_resultats/creation_campagne_resultats/creation_modele/modification_modele/duplication_modele/archivage_modele), `campagne_id` (FK, nullable), `details`, `created_at`. Alimenté par `ActivityLogger`, consulté depuis `?page=journal`.

### `contacts_v2`, `groupes_v2`, `groupe_contacts_v2`, `imports_contacts`
Module Contacts/Groupes (§22-24), recréé le 2026-09-12 après une suppression (§004) puis une nouvelle demande explicites de l'utilisateur — schéma dédié (suffixe `_v2`) plutôt que de réutiliser les anciens noms `contacts`/`groupes`, pour ne jamais confondre avec l'historique de suppression documenté dans `archive/README.md`.

| Table | Colonnes clés |
|---|---|
| `contacts_v2` | `nom`, `prenom`, `telephone` (unique, normalisé `+224XXXXXXXXX`), `telephone_brut`, `email`, `statut` |
| `groupes_v2` | `nom`, `description`, `created_by` |
| `groupe_contacts_v2` | `(groupe_id, contact_id)` — clé primaire composite, `ON DELETE CASCADE` des deux côtés |
| `imports_contacts` | rapport d'import (même modèle que `imports_resultats`) |

Un contact est unique par téléphone (index unique) : un import ou un ajout avec un numéro déjà connu **met à jour** le contact existant (nom/prénom/email) plutôt que de le dupliquer. Un envoi à un groupe (`send_to_group`) crée une campagne via `CampaignQueueService`, comme n'importe quelle autre campagne — aucune logique d'envoi séparée.

### `sms_templates`
Bibliothèque de modèles SMS réutilisables (§25) : `nom`, `categorie` (resultats_academiques/rappel/information/notification/absence/paiement), `contenu` (variables `{{...}}` supportées, rendues par `MessageTemplateService`), `archive`, `created_by`, `created_at`, `updated_at`. Gérée depuis `?page=modeles` ; chargeable directement dans l'éditeur de message de `?page=resultats`.

### `resultats_academiques`
Reconstruction structurée du module "résultats académiques" (cahier des charges V2.0, §3-4/§16/§61-64), après la suppression du 2026-09-08 (voir ci-dessous) de l'ancienne version en texte libre.

| Colonne | Type | Note |
|---|---|---|
| id | serial PK | |
| import_id | integer FK → imports_resultats | nullable |
| matricule, nom, prenom | varchar | |
| telephone | varchar(20) NOT NULL | format canonique `+224XXXXXXXXX` |
| telephone_brut | varchar | valeur brute avant normalisation, pour audit |
| etablissement, session_academique, niveau, classe, programme, semestre | varchar | critères de filtrage (§3) |
| moyenne, mention, rang, total_classe, credits, appreciation | varchar | valeurs affichées via les variables `{{moyenne}}`/`{{mention}}`/`{{rang}}`/`{{total}}`/`{{credits}}`/`{{appreciation}}` (voir `AcademicResultsService::toTemplateVars()` pour le mapping colonne → variable) |
| statut | varchar | `actif` par défaut |
| derniere_campagne_id | integer FK → campagne | posée par `AcademicResultsService::markCampaignForRows()` à la création d'une campagne ; sert à calculer `deja_envoye` (§17) par jointure sur `messages` |
| created_at, updated_at | | |

Un même `(matricule, session_academique, semestre)` est unique (index partiel) : un ré-import du même étudiant pour la même période **met à jour** la ligne existante au lieu d'en créer une deuxième.

### `imports_resultats`
Rapport de chaque import (§10/§23) : `filename`, `total_lignes`, `valides`, `invalides`, `doublons`, `errors_json` (liste `{ligne, erreur}`, téléchargeable en CSV depuis l'interface), `created_by`, `created_at`.

## Tables supprimées

Le module Contacts/Groupes (`contacts`, `groupes`, `groupe_contacts`) a été supprimé le 2026-09-08 à la demande explicite de l'utilisateur (migration `004_remove_contacts_and_notes.sql`), de même que les colonnes `notes`/`niveaux` de `messages` (ancienne version en texte libre du module "résultats académiques"). Voir `archive/README.md` pour le détail de ce qui a été retiré du code applicatif en parallèle, et `resultats_academiques` ci-dessus pour la reconstruction structurée qui l'a remplacé.

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
| `005_academic_results.sql` | Additif : crée `resultats_academiques` et `imports_resultats` (module Résultats académiques V2.0). |
| `006_resultats_derniere_campagne.sql` | Additif : `resultats_academiques.derniere_campagne_id` (FK `campagne`), pour le statut "déjà envoyé" (§17). |
| `007_activity_logs.sql` | Additif : crée `activity_logs` (journal d'activité / audit trail, §35/§64). |
| `008_sms_templates.sql` | Additif : crée `sms_templates` (bibliothèque de modèles SMS réutilisables, §25). |
| `009_contacts_groups.sql` | Additif : recrée `contacts_v2`/`groupes_v2`/`groupe_contacts_v2`/`imports_contacts` (§22-24). |

> **Note** : les deux migrations ci-dessus portent le même préfixe `005_` — créées en parallèle par deux sessions de travail différentes le même jour. Sans conséquence pratique : `schema_migrations` suit chaque fichier par son nom complet (pas seulement le préfixe), les deux ont été appliquées sans conflit (tables distinctes), et `database/migrate.php` les trie par ordre alphabétique complet. Laissé tel quel plutôt que renommé, pour ne pas risquer de perturber le suivi déjà enregistré sur la base de production.

Pour une nouvelle migration : créer `010_....sql` (préfixe numérique croissant), relancer `php database/migrate.php`.
