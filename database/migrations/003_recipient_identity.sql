-- Migration additive : ajoute nom/prenom aux destinataires (messages) pour
-- l'import Excel direct (nom, prenom, matricule, telephone, message).
ALTER TABLE messages ADD COLUMN IF NOT EXISTS nom VARCHAR(255);
ALTER TABLE messages ADD COLUMN IF NOT EXISTS prenom VARCHAR(255);
