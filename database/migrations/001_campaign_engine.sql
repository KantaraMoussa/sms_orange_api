-- Migration additive : moteur de campagnes en file d'attente (cahier des charges §5-§10).
-- Ne supprime ni ne renomme aucune colonne existante — seulement des ajouts,
-- pour ne rien casser dans les 3296 contacts / 1 groupe déjà en production.

-- 1) Index manquants identifiés dans l'audit (recherche de doublon de téléphone en O(n) actuellement).
CREATE INDEX IF NOT EXISTS idx_contacts_telephone ON contacts (telephone);
CREATE UNIQUE INDEX IF NOT EXISTS uq_contacts_contact_id ON contacts (contact_id);

-- 2) Colonnes de pilotage de campagne (statuts normalisés §18, compteurs pour le suivi §11).
ALTER TABLE campagne ADD COLUMN IF NOT EXISTS type VARCHAR(50) DEFAULT 'generique';
ALTER TABLE campagne ADD COLUMN IF NOT EXISTS total_destinataires INTEGER DEFAULT 0;
ALTER TABLE campagne ADD COLUMN IF NOT EXISTS nombre_envoyes INTEGER DEFAULT 0;
ALTER TABLE campagne ADD COLUMN IF NOT EXISTS nombre_echecs INTEGER DEFAULT 0;
ALTER TABLE campagne ADD COLUMN IF NOT EXISTS batch_size INTEGER DEFAULT 50;
ALTER TABLE campagne ADD COLUMN IF NOT EXISTS created_by VARCHAR(255);
ALTER TABLE campagne ADD COLUMN IF NOT EXISTS date_lancement TIMESTAMP NULL;
ALTER TABLE campagne ADD COLUMN IF NOT EXISTS date_completion TIMESTAMP NULL;
ALTER TABLE campagne ADD COLUMN IF NOT EXISTS dry_run BOOLEAN DEFAULT FALSE;

-- Normalise les anciennes valeurs libres vers le vocabulaire de statut cible (§18).
UPDATE campagne SET statut = 'DRAFT' WHERE statut IS NULL OR statut = 'en_attente';

-- 3) `messages` devient la table "campaign_recipients" : chaque ligne = une tâche d'envoi indépendante (§5).
ALTER TABLE messages ADD COLUMN IF NOT EXISTS unique_key VARCHAR(64);
ALTER TABLE messages ADD COLUMN IF NOT EXISTS tentative_count INTEGER DEFAULT 0;
ALTER TABLE messages ADD COLUMN IF NOT EXISTS error_code VARCHAR(30);
ALTER TABLE messages ADD COLUMN IF NOT EXISTS error_message TEXT;
ALTER TABLE messages ADD COLUMN IF NOT EXISTS locked_at TIMESTAMP NULL;
ALTER TABLE messages ADD COLUMN IF NOT EXISTS date_traitement TIMESTAMP NULL;
ALTER TABLE messages ADD COLUMN IF NOT EXISTS provider_message_id VARCHAR(255);

-- Idempotence (§7) : un même (campagne, destinataire, matricule) ne peut pas être mis en file deux fois.
CREATE UNIQUE INDEX IF NOT EXISTS uq_messages_unique_key ON messages (unique_key) WHERE unique_key IS NOT NULL;

-- Verrouillage de lot (§55, FOR UPDATE SKIP LOCKED) : cet index rend le claim rapide même à 50 000+ lignes.
CREATE INDEX IF NOT EXISTS idx_messages_campagne_statut ON messages (campagne_id, statut);
CREATE INDEX IF NOT EXISTS idx_messages_matricule ON messages (matricule);
