-- Suppression du module Contacts/Groupes et du module Notes (résultats
-- académiques bruts), à la demande explicite de l'utilisateur (2026-09-08).
-- DESTRUCTIF ET INTENTIONNEL, approuvé explicitement avant exécution.
-- État constaté avant migration : contacts (0 ligne), groupes (0 ligne),
-- groupe_contacts (3296 lignes orphelines, sans parent dans contacts/groupes),
-- messages (0 ligne) — aucune donnée réelle utilisable n'est perdue.

-- CASCADE ici ne supprime que la contrainte de clé étrangère orpheline
-- sms_destinataires_contact_id_fkey (table sms_destinataires, jamais utilisée
-- par le code applicatif — voir AUDIT.md §2 — et vide, 0 ligne) ; elle ne
-- supprime pas la table sms_destinataires elle-même.
DROP TABLE IF EXISTS groupe_contacts;
DROP TABLE IF EXISTS contacts CASCADE;
DROP TABLE IF EXISTS groupes;

ALTER TABLE messages DROP COLUMN IF EXISTS notes;
ALTER TABLE messages DROP COLUMN IF EXISTS niveaux;
