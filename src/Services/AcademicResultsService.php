<?php

namespace App\Services;

use PDO;
use Exception;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;

/**
 * Module "Résultats académiques" (cahier des charges V2.0 §3-4, §16, §23-24, §61-64) :
 * import structuré des résultats d'étudiants (Excel/CSV), filtrage par
 * session/établissement/niveau/classe/programme/semestre, et alimentation
 * du moteur de campagnes existant (CampaignQueueService) une fois le
 * message généré via MessageTemplateService.
 *
 * Volontairement une classe à part (et non des fonctions ajoutées à
 * server/config.php, déjà identifié comme "God file" à ne pas aggraver,
 * cf. AUDIT.md §6/§49) : import + lecture des résultats scolaires est une
 * responsabilité cohérente et testable isolément.
 */
class AcademicResultsService
{
    /** En-têtes reconnus (français/anglais, insensible à la casse/accents) par colonne cible. */
    private const COLUMN_ALIASES = [
        'matricule' => ['matricule', 'id_etudiant', 'numero_etudiant', 'student_id'],
        'nom' => ['nom', 'lastname', 'last_name', 'name'],
        'prenom' => ['prenom', 'prénom', 'firstname', 'first_name'],
        'telephone' => ['telephone', 'téléphone', 'tel', 'phone', 'numero', 'numéro', 'numero_telephone'],
        'etablissement' => ['etablissement', 'établissement', 'ecole', 'école', 'institution'],
        'session_academique' => ['session', 'session_academique', 'annee', 'année', 'annee_academique'],
        'niveau' => ['niveau', 'level'],
        'classe' => ['classe', 'class'],
        'programme' => ['programme', 'filiere', 'filière', 'program'],
        'semestre' => ['semestre', 'semester'],
        'moyenne' => ['moyenne', 'average', 'note'],
        'mention' => ['mention'],
        'rang' => ['rang', 'rank'],
        'total_classe' => ['total', 'total_etudiants', 'effectif'],
        'credits' => ['credits', 'crédits'],
        'appreciation' => ['appreciation', 'appréciation', 'observation'],
    ];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Parcourt le fichier importé, valide/normalise chaque ligne, et enregistre
     * un rapport d'import complet (§10) — jamais d'import silencieux (§10).
     *
     * @return array{import_id:int, total:int, valides:int, invalides:int, doublons:int, errors:list<array{ligne:int,erreur:string}>}
     */
    public function importFile(string $path, string $extension, ?string $createdBy = null): array
    {
        $rows = $this->readFile($path, $extension);

        if (empty($rows)) {
            throw new Exception('Le fichier est vide.');
        }

        $headers = array_map(fn($h) => $this->normalizeHeader((string) $h), array_shift($rows));
        $colIndex = $this->mapColumns($headers);

        if (!isset($colIndex['telephone'])) {
            throw new Exception("Colonne téléphone introuvable. Colonnes attendues (au moins) : telephone/téléphone, et idéalement matricule, nom, moyenne.");
        }

        $total = 0;
        $valides = 0;
        $invalides = 0;
        $doublons = 0;
        $errors = [];
        $seenInBatch = [];

        $upsert = $this->pdo->prepare(
            "INSERT INTO resultats_academiques
                (import_id, matricule, nom, prenom, telephone, telephone_brut, etablissement,
                 session_academique, niveau, classe, programme, semestre, moyenne, mention,
                 rang, total_classe, credits, appreciation, statut, updated_at)
             VALUES
                (:import_id, :matricule, :nom, :prenom, :telephone, :telephone_brut, :etablissement,
                 :session_academique, :niveau, :classe, :programme, :semestre, :moyenne, :mention,
                 :rang, :total_classe, :credits, :appreciation, 'actif', NOW())
             ON CONFLICT (matricule, session_academique, semestre) WHERE matricule IS NOT NULL AND matricule <> ''
             DO UPDATE SET
                import_id = EXCLUDED.import_id, nom = EXCLUDED.nom, prenom = EXCLUDED.prenom,
                telephone = EXCLUDED.telephone, telephone_brut = EXCLUDED.telephone_brut,
                etablissement = EXCLUDED.etablissement, niveau = EXCLUDED.niveau, classe = EXCLUDED.classe,
                programme = EXCLUDED.programme, moyenne = EXCLUDED.moyenne, mention = EXCLUDED.mention,
                rang = EXCLUDED.rang, total_classe = EXCLUDED.total_classe, credits = EXCLUDED.credits,
                appreciation = EXCLUDED.appreciation, statut = 'actif', updated_at = NOW()
             RETURNING (xmax = 0) AS inserted"
        );

        $plainInsert = $this->pdo->prepare(
            "INSERT INTO resultats_academiques
                (import_id, matricule, nom, prenom, telephone, telephone_brut, etablissement,
                 session_academique, niveau, classe, programme, semestre, moyenne, mention,
                 rang, total_classe, credits, appreciation, statut, updated_at)
             VALUES
                (:import_id, :matricule, :nom, :prenom, :telephone, :telephone_brut, :etablissement,
                 :session_academique, :niveau, :classe, :programme, :semestre, :moyenne, :mention,
                 :rang, :total_classe, :credits, :appreciation, 'actif', NOW())"
        );

        // Le rapport d'import est créé avant traitement pour toujours disposer d'un id,
        // même si le script est interrompu au milieu d'un très gros fichier.
        $importId = $this->createImportBatch($path, $createdBy);

        foreach ($rows as $line) {
            $total++;
            $lineNumber = $total + 1; // +1 : compense la ligne d'en-tête retirée plus haut

            $get = fn(string $key) => isset($colIndex[$key]) ? trim((string) ($line[$colIndex[$key]] ?? '')) : '';

            $telephoneBrut = $get('telephone');
            $phone = PhoneNumberService::normalize($telephoneBrut);
            $matricule = $get('matricule');

            if (trim(implode('', $line)) === '') {
                $total--; // ligne totalement vide : ne compte pas comme une ligne analysée
                continue;
            }

            if ($phone === null) {
                $invalides++;
                $errors[] = ['ligne' => $lineNumber, 'erreur' => "Numéro de téléphone invalide : \"$telephoneBrut\""];
                continue;
            }

            $sessionAcademique = $get('session_academique');
            $semestre = $get('semestre');
            $dedupeKey = $matricule !== '' ? ($matricule . '|' . $sessionAcademique . '|' . $semestre) : null;

            if ($dedupeKey !== null && isset($seenInBatch[$dedupeKey])) {
                $doublons++;
                $errors[] = ['ligne' => $lineNumber, 'erreur' => "Doublon dans le fichier pour le matricule \"$matricule\" (même session/semestre)."];
                continue;
            }
            if ($dedupeKey !== null) {
                $seenInBatch[$dedupeKey] = true;
            }

            $params = [
                ':import_id' => $importId,
                ':matricule' => $matricule !== '' ? $matricule : null,
                ':nom' => $get('nom') ?: null,
                ':prenom' => $get('prenom') ?: null,
                ':telephone' => $phone,
                ':telephone_brut' => $telephoneBrut,
                ':etablissement' => $get('etablissement') ?: null,
                ':session_academique' => $sessionAcademique ?: null,
                ':niveau' => $get('niveau') ?: null,
                ':classe' => $get('classe') ?: null,
                ':programme' => $get('programme') ?: null,
                ':semestre' => $semestre ?: null,
                ':moyenne' => $get('moyenne') ?: null,
                ':mention' => $get('mention') ?: null,
                ':rang' => $get('rang') ?: null,
                ':total_classe' => $get('total_classe') ?: null,
                ':credits' => $get('credits') ?: null,
                ':appreciation' => $get('appreciation') ?: null,
            ];

            try {
                if ($matricule !== '') {
                    $upsert->execute($params);
                    $wasInsert = (bool) $upsert->fetchColumn();
                    if ($wasInsert) {
                        $valides++;
                    } else {
                        $doublons++; // ligne déjà présente pour ce matricule+session+semestre : mise à jour, comptée en doublon "corrigé"
                    }
                } else {
                    // Pas de matricule : impossible de dédupliquer, on insère tel quel.
                    $plainInsert->execute($params);
                    $valides++;
                }
            } catch (Exception $e) {
                $invalides++;
                $errors[] = ['ligne' => $lineNumber, 'erreur' => 'Erreur de base de données : ' . $e->getMessage()];
            }
        }

        $this->finalizeImportBatch($importId, $total, $valides, $invalides, $doublons, $errors);

        return [
            'import_id' => $importId,
            'total' => $total,
            'valides' => $valides,
            'invalides' => $invalides,
            'doublons' => $doublons,
            'errors' => $errors,
        ];
    }

    /** @return array<int,array<int,string>> */
    private function readFile(string $path, string $extension): array
    {
        if ($extension === 'csv') {
            $reader = new Csv();
            $reader->setDelimiter($this->detectDelimiter($path));
            $spreadsheet = $reader->load($path);
        } else {
            $spreadsheet = IOFactory::load($path);
        }

        return $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
    }

    private function detectDelimiter(string $path): string
    {
        $handle = fopen($path, 'r');
        $firstLine = $handle ? fgets($handle) : '';
        if ($handle) {
            fclose($handle);
        }

        $commaCount = substr_count((string) $firstLine, ',');
        $semicolonCount = substr_count((string) $firstLine, ';');

        return $semicolonCount > $commaCount ? ';' : ',';
    }

    private function normalizeHeader(string $header): string
    {
        $header = mb_strtolower(trim($header));
        $header = strtr($header, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'î' => 'i', 'ô' => 'o', 'ù' => 'u', 'ç' => 'c']);

        return $header;
    }

    /** @param list<string> $headers @return array<string,int> */
    private function mapColumns(array $headers): array
    {
        $map = [];
        foreach (self::COLUMN_ALIASES as $target => $aliases) {
            foreach ($headers as $index => $header) {
                if (in_array($header, array_map(fn($a) => $this->normalizeHeader($a), $aliases), true)) {
                    $map[$target] = $index;
                    break;
                }
            }
        }

        return $map;
    }

    private function createImportBatch(string $path, ?string $createdBy): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO imports_resultats (filename, created_by) VALUES (:filename, :created_by) RETURNING id"
        );
        $stmt->execute([':filename' => basename($path), ':created_by' => $createdBy]);

        return (int) $stmt->fetchColumn();
    }

    private function finalizeImportBatch(int $importId, int $total, int $valides, int $invalides, int $doublons, array $errors): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE imports_resultats SET total_lignes = :total, valides = :valides, invalides = :invalides,
             doublons = :doublons, errors_json = :errors WHERE id = :id"
        );
        $stmt->execute([
            ':total' => $total,
            ':valides' => $valides,
            ':invalides' => $invalides,
            ':doublons' => $doublons,
            ':errors' => json_encode(array_slice($errors, 0, 500)),
            ':id' => $importId,
        ]);
    }

    /**
     * Valeurs distinctes pour peupler les filtres de sélection (§3).
     * @return array<string,list<string>>
     */
    public function getFilterOptions(): array
    {
        $columns = ['session_academique', 'niveau', 'classe', 'programme', 'semestre'];
        $options = [];

        foreach ($columns as $col) {
            $stmt = $this->pdo->query(
                "SELECT DISTINCT $col FROM resultats_academiques WHERE $col IS NOT NULL AND $col <> '' AND statut = 'actif' ORDER BY $col"
            );
            $options[$col] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        return $options;
    }

    /**
     * @param array{session_academique?:string,niveau?:string,classe?:string,programme?:string,semestre?:string,search?:string,exclude_already_sent?:bool,exclude_ids?:list<int>} $filters
     */
    private function buildWhere(array $filters): array
    {
        $where = ["r.statut = 'actif'"];
        $params = [];

        foreach (['session_academique', 'niveau', 'classe', 'programme', 'semestre'] as $field) {
            if (!empty($filters[$field])) {
                $where[] = "r.$field = :$field";
                $params[":$field"] = $filters[$field];
            }
        }

        if (!empty($filters['search'])) {
            $where[] = "(r.nom ILIKE :search OR r.prenom ILIKE :search OR r.matricule ILIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['exclude_already_sent'])) {
            $where[] = "NOT COALESCE((SELECT bool_or(m.statut = 'envoye') FROM messages m
                WHERE m.campagne_id = r.derniere_campagne_id AND m.matricule = r.matricule), FALSE)";
        }

        if (!empty($filters['exclude_ids'])) {
            $ids = array_map('intval', $filters['exclude_ids']);
            $placeholders = [];
            foreach ($ids as $i => $id) {
                $key = ":excl_$i";
                $placeholders[] = $key;
                $params[$key] = $id;
            }
            $where[] = 'r.id NOT IN (' . implode(',', $placeholders) . ')';
        }

        return [implode(' AND ', $where), $params];
    }

    public function countMatching(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM resultats_academiques r WHERE $where");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<array<string,mixed>> chaque ligne inclut `deja_envoye` (bool) :
     *   vrai si la dernière campagne de résultats à laquelle cet étudiant a été
     *   ajouté (`derniere_campagne_id`) lui a effectivement envoyé un SMS (§17).
     */
    public function getMatching(array $filters, ?int $limit = null, int $offset = 0): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $sql = "SELECT r.*,
                    COALESCE((SELECT bool_or(m.statut = 'envoye') FROM messages m
                        WHERE m.campagne_id = r.derniere_campagne_id AND m.matricule = r.matricule), FALSE) AS deja_envoye
                FROM resultats_academiques r
                WHERE $where
                ORDER BY r.nom, r.prenom";
        if ($limit !== null) {
            $sql .= " LIMIT :limit OFFSET :offset";
        }

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        if ($limit !== null) {
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        }
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['deja_envoye'] = in_array($row['deja_envoye'], [true, 't', '1', 1], true);
        }

        return $rows;
    }

    public function getSample(array $filters): ?array
    {
        $rows = $this->getMatching($filters, 1, 0);

        return $rows[0] ?? null;
    }

    /**
     * Enregistre, pour chaque résultat inclus dans une campagne, l'identifiant
     * de cette campagne — seule façon fiable de répondre plus tard à "cet
     * étudiant a-t-il déjà reçu ses résultats ?" (§17) sans deviner par
     * correspondance de texte libre entre campagnes.
     *
     * @param list<int> $resultatIds
     */
    public function markCampaignForRows(int $campaignId, array $resultatIds): void
    {
        if (empty($resultatIds)) {
            return;
        }

        $ids = array_map('intval', $resultatIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("UPDATE resultats_academiques SET derniere_campagne_id = ? WHERE id IN ($placeholders)");
        $stmt->execute(array_merge([$campaignId], $ids));
    }

    /**
     * Traduit une ligne de `resultats_academiques` vers les noms de variables
     * du cahier des charges (§4/§62) : la table stocke `session_academique`/
     * `total_classe` (noms de colonnes explicites) mais le message utilise
     * `{{session}}`/`{{total}}` (noms courts). Utilisé partout où un message
     * est rendu (aperçu, test, campagne réelle) pour que les trois emploient
     * strictement le même jeu de variables (§17 : même moteur partout).
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function toTemplateVars(array $row): array
    {
        return [
            'nom' => $row['nom'] ?? null,
            'prenom' => $row['prenom'] ?? null,
            'matricule' => $row['matricule'] ?? null,
            'etablissement' => $row['etablissement'] ?? null,
            'classe' => $row['classe'] ?? null,
            'niveau' => $row['niveau'] ?? null,
            'programme' => $row['programme'] ?? null,
            'semestre' => $row['semestre'] ?? null,
            'session' => $row['session_academique'] ?? null,
            'moyenne' => $row['moyenne'] ?? null,
            'mention' => $row['mention'] ?? null,
            'rang' => $row['rang'] ?? null,
            'total' => $row['total_classe'] ?? null,
            'credits' => $row['credits'] ?? null,
            'appreciation' => $row['appreciation'] ?? null,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function getImportHistory(int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM imports_resultats ORDER BY created_at DESC LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array{ligne:int,erreur:string}> */
    public function getImportErrors(int $importId): array
    {
        $stmt = $this->pdo->prepare("SELECT errors_json FROM imports_resultats WHERE id = :id");
        $stmt->execute([':id' => $importId]);
        $json = $stmt->fetchColumn();

        if (!$json) {
            return [];
        }

        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
