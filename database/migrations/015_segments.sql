-- Segments dynamiques (cahier des charges V2.0 §13, Phase 2) : un segment
-- est une définition de filtre réutilisable comme audience de campagne —
-- il ne copie jamais les contacts, seul le critère est stocké (JSON), la
-- liste de contacts correspondante est recalculée à chaque utilisation
-- (SegmentService::resolveContacts()).
--
-- Champs de critère supportés (voir SegmentService pour le détail) : statut,
-- recherche texte (nom/prenom/telephone/email), appartenance à un groupe,
-- date d'ajout. Limité aux colonnes réellement présentes sur contacts_v2
-- aujourd'hui (pas de "ville" : ce champ n'existe pas encore sur les
-- contacts — cf. DATABASE.md).

CREATE TABLE IF NOT EXISTS segments (
    id SERIAL PRIMARY KEY,
    organization_id INTEGER NOT NULL REFERENCES organizations(id),
    nom VARCHAR(150) NOT NULL,
    criteria JSONB NOT NULL DEFAULT '{}',
    created_by VARCHAR(255),
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_segments_organization ON segments (organization_id);
