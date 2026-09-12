-- Fondation multi-tenant (cahier des charges V2.0 §5, §7, §41) : le produit
-- passe d'un outil mono-entreprise (UGLC-SC) à une plateforme où plusieurs
-- entreprises coexistent, chacune totalement isolée des autres (§59).
--
-- Stratégie : migration additive, aucune donnée existante ne bouge de sens.
-- On crée `organizations`, on y insère une organisation "bootstrap" (id=1)
-- qui représente l'unique client actuel, puis on ajoute `organization_id`
-- (NOT NULL, backfillé à 1) sur chaque table métier. Tant qu'une seule
-- organisation existe, le comportement observable de l'application est
-- inchangé ; l'isolation ne devient visible que lorsqu'une 2e organisation
-- est créée (app/register.php) et que le code applicatif filtre par
-- organization_id (voir AuthService/OrganizationService/*Service.php).
--
-- `contacts_v2.telephone` était unique globalement ; devient unique par
-- organisation (deux entreprises peuvent légitimement avoir un contact avec
-- le même numéro).

CREATE TABLE IF NOT EXISTS organizations (
    id SERIAL PRIMARY KEY,
    nom VARCHAR(255) NOT NULL,
    logo_url VARCHAR(500),
    secteur VARCHAR(100),
    telephone VARCHAR(30),
    email VARCHAR(255),
    adresse TEXT,
    pays VARCHAR(100) DEFAULT 'Guinée',
    fuseau_horaire VARCHAR(50) NOT NULL DEFAULT 'Africa/Conakry',
    devise VARCHAR(10) NOT NULL DEFAULT 'GNF',
    sender_name VARCHAR(20),
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

INSERT INTO organizations (id, nom, secteur, email, pays)
SELECT 1, 'UGLC-SC', 'Enseignement supérieur', 'moussaizaziszamalkantara@gmail.com', 'Guinée'
WHERE NOT EXISTS (SELECT 1 FROM organizations WHERE id = 1);

SELECT setval('organizations_id_seq', GREATEST((SELECT MAX(id) FROM organizations), 1));

ALTER TABLE utilisateurs ADD COLUMN IF NOT EXISTS organization_id INTEGER REFERENCES organizations(id);
UPDATE utilisateurs SET organization_id = 1 WHERE organization_id IS NULL;
ALTER TABLE utilisateurs ALTER COLUMN organization_id SET NOT NULL;
CREATE INDEX IF NOT EXISTS idx_utilisateurs_organization ON utilisateurs (organization_id);

ALTER TABLE campagne ADD COLUMN IF NOT EXISTS organization_id INTEGER REFERENCES organizations(id);
UPDATE campagne SET organization_id = 1 WHERE organization_id IS NULL;
ALTER TABLE campagne ALTER COLUMN organization_id SET NOT NULL;
CREATE INDEX IF NOT EXISTS idx_campagne_organization ON campagne (organization_id);

ALTER TABLE messages ADD COLUMN IF NOT EXISTS organization_id INTEGER REFERENCES organizations(id);
UPDATE messages SET organization_id = 1 WHERE organization_id IS NULL;
ALTER TABLE messages ALTER COLUMN organization_id SET NOT NULL;
CREATE INDEX IF NOT EXISTS idx_messages_organization ON messages (organization_id);

ALTER TABLE contacts_v2 ADD COLUMN IF NOT EXISTS organization_id INTEGER REFERENCES organizations(id);
UPDATE contacts_v2 SET organization_id = 1 WHERE organization_id IS NULL;
ALTER TABLE contacts_v2 ALTER COLUMN organization_id SET NOT NULL;
DROP INDEX IF EXISTS uq_contacts_v2_telephone;
CREATE UNIQUE INDEX IF NOT EXISTS uq_contacts_v2_org_telephone ON contacts_v2 (organization_id, telephone);
CREATE INDEX IF NOT EXISTS idx_contacts_v2_organization ON contacts_v2 (organization_id);

ALTER TABLE groupes_v2 ADD COLUMN IF NOT EXISTS organization_id INTEGER REFERENCES organizations(id);
UPDATE groupes_v2 SET organization_id = 1 WHERE organization_id IS NULL;
ALTER TABLE groupes_v2 ALTER COLUMN organization_id SET NOT NULL;
CREATE INDEX IF NOT EXISTS idx_groupes_v2_organization ON groupes_v2 (organization_id);

ALTER TABLE imports_contacts ADD COLUMN IF NOT EXISTS organization_id INTEGER REFERENCES organizations(id);
UPDATE imports_contacts SET organization_id = 1 WHERE organization_id IS NULL;
ALTER TABLE imports_contacts ALTER COLUMN organization_id SET NOT NULL;
CREATE INDEX IF NOT EXISTS idx_imports_contacts_organization ON imports_contacts (organization_id);

ALTER TABLE sms_templates ADD COLUMN IF NOT EXISTS organization_id INTEGER REFERENCES organizations(id);
UPDATE sms_templates SET organization_id = 1 WHERE organization_id IS NULL;
ALTER TABLE sms_templates ALTER COLUMN organization_id SET NOT NULL;
CREATE INDEX IF NOT EXISTS idx_sms_templates_organization ON sms_templates (organization_id);

ALTER TABLE notifications ADD COLUMN IF NOT EXISTS organization_id INTEGER REFERENCES organizations(id);
UPDATE notifications SET organization_id = 1 WHERE organization_id IS NULL;
ALTER TABLE notifications ALTER COLUMN organization_id SET NOT NULL;
CREATE INDEX IF NOT EXISTS idx_notifications_organization ON notifications (organization_id);

ALTER TABLE activity_logs ADD COLUMN IF NOT EXISTS organization_id INTEGER REFERENCES organizations(id);
UPDATE activity_logs SET organization_id = 1 WHERE organization_id IS NULL;
ALTER TABLE activity_logs ALTER COLUMN organization_id SET NOT NULL;
CREATE INDEX IF NOT EXISTS idx_activity_logs_organization ON activity_logs (organization_id);
