-- Verrouillage anti brute-force sur la connexion (additif, cahier des charges,
-- point resté en suspens dans AUDIT.md : "pas de verrouillage de tentatives
-- de connexion").
ALTER TABLE utilisateurs ADD COLUMN IF NOT EXISTS failed_attempts INTEGER NOT NULL DEFAULT 0;
ALTER TABLE utilisateurs ADD COLUMN IF NOT EXISTS locked_until TIMESTAMP NULL;
