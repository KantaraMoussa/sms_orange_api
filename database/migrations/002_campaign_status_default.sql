-- Aligne la valeur par défaut de campagne.statut sur le vocabulaire cible du
-- moteur de campagnes (DRAFT/QUEUED/RUNNING/PAUSED/COMPLETED/PARTIAL/FAILED/CANCELLED, §18).
-- L'ancien défaut 'en_attente' datait du modèle initial et n'est plus produit
-- par aucun chemin de code depuis la Phase 6/7, mais on corrige la colonne
-- elle-même par sécurité (défense en profondeur en cas d'insertion directe future).
ALTER TABLE campagne ALTER COLUMN statut SET DEFAULT 'DRAFT';
