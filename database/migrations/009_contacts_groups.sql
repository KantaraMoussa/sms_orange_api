-- Recrée le module Contacts/Groupes (cahier des charges V2.0 §22-24), supprimé
-- le 2026-09-08 à la demande explicite de l'utilisateur (voir
-- 004_remove_contacts_and_notes.sql et archive/README.md), puis redemandé le
-- 2026-09-12. Reconstruit proprement avec un schéma dédié (`contacts_v2` etc.)
-- plutôt que de réutiliser les anciens noms de table : évite toute ambiguïté
-- avec l'historique de suppression, et repart sur un schéma aligné avec les
-- conventions actuelles (ContactService, comme AcademicResultsService).

CREATE TABLE IF NOT EXISTS contacts_v2 (
    id SERIAL PRIMARY KEY,
    nom VARCHAR(150),
    prenom VARCHAR(150),
    telephone VARCHAR(20) NOT NULL,
    telephone_brut VARCHAR(50),
    email VARCHAR(255),
    statut VARCHAR(20) NOT NULL DEFAULT 'actif',
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_contacts_v2_telephone ON contacts_v2 (telephone);

CREATE TABLE IF NOT EXISTS groupes_v2 (
    id SERIAL PRIMARY KEY,
    nom VARCHAR(150) NOT NULL,
    description TEXT,
    created_by VARCHAR(255),
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS groupe_contacts_v2 (
    groupe_id INTEGER NOT NULL REFERENCES groupes_v2(id) ON DELETE CASCADE,
    contact_id INTEGER NOT NULL REFERENCES contacts_v2(id) ON DELETE CASCADE,
    PRIMARY KEY (groupe_id, contact_id)
);

CREATE TABLE IF NOT EXISTS imports_contacts (
    id SERIAL PRIMARY KEY,
    filename VARCHAR(255),
    total_lignes INTEGER DEFAULT 0,
    valides INTEGER DEFAULT 0,
    invalides INTEGER DEFAULT 0,
    doublons INTEGER DEFAULT 0,
    errors_json TEXT,
    created_by VARCHAR(255),
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_groupe_contacts_v2_contact ON groupe_contacts_v2 (contact_id);
