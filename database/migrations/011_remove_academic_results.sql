-- Suppression du module "Résultats académiques" (tables et colonne matricule),
-- à la demande explicite de l'utilisateur (2026-09-12) : "élimine tout ce qui
-- est en rapport avec le résultat et le matricule aussi, c'est un projet
-- global destiné aux entreprises pas que à l'école ou université".
-- DESTRUCTIF ET INTENTIONNEL.
--
-- Le module avait été reconstruit le 2026-09-11 (résultats_academiques,
-- imports_resultats) pour un usage scolaire spécifique — l'orientation du
-- produit a changé depuis vers un usage générique multi-secteurs (marketing,
-- notifications, alertes...), donc ce module n'a plus sa place.

DROP TABLE IF EXISTS resultats_academiques;
DROP TABLE IF EXISTS imports_resultats;

-- `matricule` n'a de sens que pour un usage scolaire (identifiant étudiant) ;
-- l'import Excel générique de destinataires (nom/prenom/telephone/message)
-- reste, sans ce champ.
ALTER TABLE messages DROP COLUMN IF EXISTS matricule;
