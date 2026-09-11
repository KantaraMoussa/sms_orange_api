-- Additif : journal d'activité / piste d'audit (cahier des charges V2.0,
-- §35 "journalisation" et §64 "audit trail" — qui a créé/lancé/mis en pause/
-- repris/annulé/réessayé une campagne, et qui s'est connecté).

CREATE TABLE IF NOT EXISTS activity_logs (
    id SERIAL PRIMARY KEY,
    user_nom VARCHAR(255),
    action VARCHAR(100) NOT NULL,
    campagne_id INTEGER REFERENCES campagne(id) ON DELETE SET NULL,
    details TEXT,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_activity_logs_created_at ON activity_logs (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_activity_logs_campagne ON activity_logs (campagne_id);
