-- Automatisations : campagnes récurrentes (Phase 2, feuille de route
-- utilisateur — non détaillé dans le cahier des charges d'origine, interprété
-- comme "renouveler automatiquement une campagne à intervalle régulier" en
-- s'appuyant sur la planification (§30) déjà en place).
--
-- La campagne "parent" (celle sur laquelle la récurrence est configurée)
-- reste la définition vivante de la règle : à chaque échéance,
-- CampaignQueueService::processRecurringCampaigns() lui fait générer une
-- NOUVELLE campagne (audience réévaluée à cet instant, comme un segment)
-- puis avance son propre next_occurrence_at — elle n'est jamais elle-même
-- ré-envoyée. Pas de contrainte FK sur recurrence_groupe_id/segment_id :
-- volontairement une référence "molle", vérifiée en code au moment de
-- générer l'occurrence suivante (un groupe/segment supprimé entre-temps ne
-- doit pas bloquer une migration ni planter, juste sauter ce cycle).

ALTER TABLE campagne ADD COLUMN IF NOT EXISTS recurrence VARCHAR(20) NULL;
ALTER TABLE campagne ADD COLUMN IF NOT EXISTS recurrence_audience_type VARCHAR(20) NULL;
ALTER TABLE campagne ADD COLUMN IF NOT EXISTS recurrence_groupe_id INTEGER NULL;
ALTER TABLE campagne ADD COLUMN IF NOT EXISTS recurrence_segment_id INTEGER NULL;
ALTER TABLE campagne ADD COLUMN IF NOT EXISTS message_template TEXT NULL;
ALTER TABLE campagne ADD COLUMN IF NOT EXISTS next_occurrence_at TIMESTAMP NULL;

CREATE INDEX IF NOT EXISTS idx_campagne_recurrence_due ON campagne (next_occurrence_at) WHERE recurrence IS NOT NULL;
