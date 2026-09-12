-- Centre de notifications (cahier des charges V2.0, §73) : solde faible,
-- campagne terminée/partiellement échouée, import terminé. Additif.

CREATE TABLE IF NOT EXISTS notifications (
    id SERIAL PRIMARY KEY,
    type VARCHAR(50) NOT NULL,
    titre VARCHAR(255) NOT NULL,
    message TEXT,
    campagne_id INTEGER REFERENCES campagne(id) ON DELETE CASCADE,
    lu BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_notifications_lu ON notifications (lu);
CREATE INDEX IF NOT EXISTS idx_notifications_type_created ON notifications (type, created_at);
