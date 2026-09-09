# Archive

Scripts et pages retirés du chemin actif de l'application le 2026-09-08 (cahier
des charges §65-§66), après vérification qu'aucun fichier actif ne les
référence (`grep` sur `app/` et `server/`). Conservés ici plutôt que supprimés,
au cas où une logique y serait encore utile.

| Fichier | Raison |
|---|---|
| `server/credit.php` | Script CLI de test (solde/stats), identifiants placeholders, jamais lié à l'UI. |
| `server/viaCsv.php` | Envoi CSV en CLI, doublon de la logique désormais dans `CampaignQueueService`. |
| `server/lot.php` | Envoi par lots en CLI (placeholders), doublon — sa logique de rate-limiting a inspiré `campaignQueue()->claimBatch()`. |
| `server/send.php` | Endpoint de test minimal, identifiants en dur, jamais lié à l'UI. |
| `server/send_sms.php` | Ancien point d'entrée du bouton "Créer une campagne" (boucle synchrone bloquante) — remplacé par le moteur de campagnes (Phase 6/7) ; plus aucune vue ne pointe dessus depuis que `campagne.php` a été mis à jour. |
| `server/index.php` | Test de connexion isolé vers une base `school` inaccessible (mauvais mot de passe), jamais inclus ailleurs. |
| `server/index.html` | Page par défaut Apache2 "It works", oubliée dans le dépôt. |
| `app/templete/campagne-list.php` | Vue brouillon à données 100% factices, jamais routée par `app/index.php`. |
| `pages/login-v1.html`, `pages/register-v1.html` | Maquettes statiques jamais connectées au backend, remplacées par `app/login.php` (Phase 37/38). |

## Module Contacts/Groupes et module Notes (supprimés le 2026-09-08)

Retirés à la demande explicite de l'utilisateur, y compris les données réelles en base (voir `database/migrations/004_remove_contacts_and_notes.sql` — tables `contacts`/`groupes`/`groupe_contacts` supprimées, colonnes `notes`/`niveaux` retirées de `messages`). Au moment de la suppression, `contacts` et `groupes` étaient déjà vides (0 ligne) ; seules 3296 lignes orphelines de `groupe_contacts` existaient encore, sans donnée réelle perdue.

| Fichier | Raison |
|---|---|
| `app/templete/contacts.php` | Page "Liste des Contacts" — données 100% factices ("Jacqueline Howell"), jamais fonctionnelle. |
| `app/templete/groupe.php`, `app/templete/detail-groupe.php` | Pages de gestion des groupes/contacts, dépendaient des tables supprimées. |
| `app/templete/sendMarksheets.php` | Page "Liste des Notes" (résultats académiques bruts, matricule/notes/niveaux) — module Notes entier supprimé. |
| `server/layout.php` | Seule fonction (`ListGroupe`) dépendait des groupes, devenue inutile. |

Fonctions supprimées de `server/config.php` : `getGroupes`, `getGroupe`, `getContactByGroupe`, `detailGroupe`, `getPhoneContact`, `phoneExiste`, `getMessageSenderMarksheet`, `getSingleStudentSendMarksheet`. Handlers supprimés de `server/app.php` : `create_group`, `import_csv` (contacts), `add_contact`, `importMessage_csv` (résultats bruts), `prepare_resultats_campagne`, `send_to_group`.

Conservé : l'import Excel de destinataires (`import_excel_recipients`) et les champs `matricule`/`nom`/`prenom` sur `messages`, génériques et indépendants du module Notes (utile pour identifier n'importe quel destinataire de campagne, pas seulement des résultats scolaires).

Voir `AUDIT.md` à la racine pour le détail de chaque décision.
