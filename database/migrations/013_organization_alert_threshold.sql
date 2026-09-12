-- Alertes configurables par organisation (cahier des charges V2.0 §34).
-- Avant cette migration, le seuil d'alerte "solde SMS faible" était une
-- unique variable d'environnement globale (LOW_BALANCE_THRESHOLD),
-- incohérente avec le multi-tenant : deux entreprises n'ont pas forcément
-- le même volume d'envoi ni le même seuil d'alerte pertinent.

ALTER TABLE organizations ADD COLUMN IF NOT EXISTS low_balance_threshold INTEGER NOT NULL DEFAULT 2000;
