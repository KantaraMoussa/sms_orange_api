-- Additif : bibliothèque de modèles SMS réutilisables (cahier des charges
-- V2.0, §25 "Modèles de SMS" : créer/modifier/dupliquer/archiver, catégories,
-- variables dynamiques supportées — réutilise le même moteur de variables
-- que l'écran Résultats académiques, MessageTemplateService).

CREATE TABLE IF NOT EXISTS sms_templates (
    id SERIAL PRIMARY KEY,
    nom VARCHAR(150) NOT NULL,
    categorie VARCHAR(50) NOT NULL DEFAULT 'notification',
    contenu TEXT NOT NULL,
    archive BOOLEAN NOT NULL DEFAULT FALSE,
    created_by VARCHAR(255),
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_sms_templates_archive ON sms_templates (archive);
