-- Additif : permet de savoir si un étudiant a déjà reçu ses résultats par SMS
-- (cahier des charges V2.0, §17 "exclure les étudiants déjà envoyés") sans
-- deviner par correspondance de texte libre — la campagne d'origine est
-- enregistrée explicitement au moment où l'étudiant y est ajouté
-- (voir AcademicResultsService::markCampaignForRows(), appelé depuis
-- server/app.php::create_resultats_campagne).

ALTER TABLE resultats_academiques
    ADD COLUMN IF NOT EXISTS derniere_campagne_id INTEGER REFERENCES campagne(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_resultats_derniere_campagne ON resultats_academiques (derniere_campagne_id);
