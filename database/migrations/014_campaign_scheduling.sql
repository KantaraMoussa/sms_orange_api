-- Planification de campagnes (cahier des charges V2.0 §30, Phase 2).
-- `statut` reste un varchar libre (comme documenté dans DATABASE.md) : la
-- valeur 'SCHEDULED' est ajoutée par convention applicative, pas de
-- contrainte CHECK à modifier.

ALTER TABLE campagne ADD COLUMN IF NOT EXISTS scheduled_at TIMESTAMP NULL;
CREATE INDEX IF NOT EXISTS idx_campagne_scheduled_at ON campagne (scheduled_at) WHERE statut = 'SCHEDULED';
