-- Migration additive : module "Résultats académiques" (cahier des charges V2.0, §3-4, §16, §61-64).
-- Reconstruit proprement, avec des colonnes structurées (et non plus du texte libre dans
-- messages.matricule/notes comme avant la suppression du 2026-09-08 documentée dans AUDIT.md),
-- pour permettre un vrai filtrage (session/niveau/classe/programme/semestre) et une
-- prévisualisation fidèle du message final avant envoi. Ne touche à aucune table existante.

CREATE TABLE IF NOT EXISTS imports_resultats (
    id SERIAL PRIMARY KEY,
    filename VARCHAR(255),
    total_lignes INTEGER DEFAULT 0,
    valides INTEGER DEFAULT 0,
    invalides INTEGER DEFAULT 0,
    doublons INTEGER DEFAULT 0,
    errors_json TEXT,
    created_by VARCHAR(255),
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS resultats_academiques (
    id SERIAL PRIMARY KEY,
    import_id INTEGER REFERENCES imports_resultats(id) ON DELETE SET NULL,
    matricule VARCHAR(50),
    nom VARCHAR(150),
    prenom VARCHAR(150),
    telephone VARCHAR(20) NOT NULL,
    telephone_brut VARCHAR(50),
    etablissement VARCHAR(150),
    session_academique VARCHAR(50),
    niveau VARCHAR(50),
    classe VARCHAR(100),
    programme VARCHAR(150),
    semestre VARCHAR(50),
    moyenne VARCHAR(20),
    mention VARCHAR(50),
    rang VARCHAR(20),
    total_classe VARCHAR(20),
    credits VARCHAR(20),
    appreciation VARCHAR(255),
    statut VARCHAR(20) DEFAULT 'actif',
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Un même étudiant (matricule) ne doit apparaître qu'une fois par session+semestre :
-- un ré-import corrige/actualise la ligne existante au lieu d'en créer une deuxième (§10, §23).
CREATE UNIQUE INDEX IF NOT EXISTS uq_resultats_matricule_session_semestre
    ON resultats_academiques (matricule, session_academique, semestre)
    WHERE matricule IS NOT NULL AND matricule <> '';

-- Index de filtrage (§3-4 : sélection par session/niveau/classe/programme/semestre) et de recherche.
CREATE INDEX IF NOT EXISTS idx_resultats_filtres
    ON resultats_academiques (session_academique, niveau, classe, semestre);
CREATE INDEX IF NOT EXISTS idx_resultats_telephone ON resultats_academiques (telephone);
CREATE INDEX IF NOT EXISTS idx_resultats_import ON resultats_academiques (import_id);
