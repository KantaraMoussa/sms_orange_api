<?php

namespace App\Services;

use PDO;
use Exception;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;

/**
 * Module Contacts/Groupes (cahier des charges V2.0 §22-24), recréé le
 * 2026-09-12 après une suppression puis une nouvelle demande explicites de
 * l'utilisateur (voir AUDIT.md) : import Excel/CSV avec rapport détaillé,
 * normalisation téléphone (partagée avec PhoneNumberService), détection de
 * doublons.
 */
class ContactService
{
    private const COLUMN_ALIASES = [
        'nom' => ['nom', 'lastname', 'last_name', 'name'],
        'prenom' => ['prenom', 'prénom', 'firstname', 'first_name'],
        'telephone' => ['telephone', 'téléphone', 'tel', 'phone', 'numero', 'numéro'],
        'email' => ['email', 'e-mail', 'mail'],
    ];

    public function __construct(private PDO $pdo)
    {
    }

    // ------------------------------------------------------------------
    // Contacts
    // ------------------------------------------------------------------

    public function createContact(string $nom, string $prenom, string $telephoneRaw, string $email = ''): int
    {
        $phone = PhoneNumberService::normalize($telephoneRaw);
        if ($phone === null) {
            throw new Exception("Numéro de téléphone invalide : \"$telephoneRaw\"");
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO contacts_v2 (nom, prenom, telephone, telephone_brut, email)
             VALUES (:nom, :prenom, :telephone, :telephone_brut, :email)
             ON CONFLICT (telephone) DO UPDATE SET nom = EXCLUDED.nom, prenom = EXCLUDED.prenom,
                email = EXCLUDED.email, updated_at = NOW()
             RETURNING id"
        );
        $stmt->execute([
            ':nom' => $nom ?: null,
            ':prenom' => $prenom ?: null,
            ':telephone' => $phone,
            ':telephone_brut' => $telephoneRaw,
            ':email' => $email ?: null,
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function deleteContact(int $id): void
    {
        $this->pdo->prepare("DELETE FROM contacts_v2 WHERE id = :id")->execute([':id' => $id]);
    }

    /** @return list<array<string,mixed>> */
    public function allContacts(?int $groupeId = null, string $search = ''): array
    {
        $where = ['1=1'];
        $params = [];

        if ($groupeId !== null) {
            $where[] = 'gc.groupe_id = :groupe_id';
            $params[':groupe_id'] = $groupeId;
        }
        if ($search !== '') {
            $where[] = '(c.nom ILIKE :search OR c.prenom ILIKE :search OR c.telephone ILIKE :search)';
            $params[':search'] = '%' . $search . '%';
        }

        $join = $groupeId !== null ? 'JOIN groupe_contacts_v2 gc ON gc.contact_id = c.id' : '';
        $sql = "SELECT DISTINCT c.* FROM contacts_v2 c $join WHERE " . implode(' AND ', $where) . ' ORDER BY c.nom, c.prenom';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countContacts(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM contacts_v2")->fetchColumn();
    }

    // ------------------------------------------------------------------
    // Groupes
    // ------------------------------------------------------------------

    public function createGroup(string $nom, string $description = '', ?string $createdBy = null): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO groupes_v2 (nom, description, created_by) VALUES (:nom, :description, :created_by) RETURNING id"
        );
        $stmt->execute([':nom' => $nom, ':description' => $description, ':created_by' => $createdBy]);

        return (int) $stmt->fetchColumn();
    }

    public function deleteGroup(int $id): void
    {
        $this->pdo->prepare("DELETE FROM groupes_v2 WHERE id = :id")->execute([':id' => $id]);
    }

    /** @return list<array<string,mixed>> */
    public function allGroups(): array
    {
        $sql = "SELECT g.*, COUNT(gc.contact_id) AS nombre_contacts
                FROM groupes_v2 g
                LEFT JOIN groupe_contacts_v2 gc ON gc.groupe_id = g.id
                GROUP BY g.id
                ORDER BY g.nom";

        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findGroup(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM groupes_v2 WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function addContactToGroup(int $groupeId, int $contactId): void
    {
        $this->pdo->prepare(
            "INSERT INTO groupe_contacts_v2 (groupe_id, contact_id) VALUES (:g, :c) ON CONFLICT DO NOTHING"
        )->execute([':g' => $groupeId, ':c' => $contactId]);
    }

    public function removeContactFromGroup(int $groupeId, int $contactId): void
    {
        $this->pdo->prepare(
            "DELETE FROM groupe_contacts_v2 WHERE groupe_id = :g AND contact_id = :c"
        )->execute([':g' => $groupeId, ':c' => $contactId]);
    }

    // ------------------------------------------------------------------
    // Import CSV/Excel (§23)
    // ------------------------------------------------------------------

    /**
     * @return array{import_id:int, total:int, valides:int, invalides:int, doublons:int, errors:list<array{ligne:int,erreur:string}>}
     */
    public function importFile(string $path, string $extension, ?int $groupeId, ?string $createdBy = null): array
    {
        $rows = $this->readFile($path, $extension);

        if (empty($rows)) {
            throw new Exception('Le fichier est vide.');
        }

        $headers = array_map(fn($h) => $this->normalizeHeader((string) $h), array_shift($rows));
        $colIndex = $this->mapColumns($headers);

        if (!isset($colIndex['telephone'])) {
            throw new Exception("Colonne téléphone introuvable. Colonnes attendues : telephone/téléphone (obligatoire), nom, prenom, email.");
        }

        $total = 0;
        $valides = 0;
        $invalides = 0;
        $doublons = 0;
        $errors = [];
        $seenInBatch = [];

        $importId = $this->createImportBatch($path, $createdBy);

        $upsert = $this->pdo->prepare(
            "INSERT INTO contacts_v2 (nom, prenom, telephone, telephone_brut, email, updated_at)
             VALUES (:nom, :prenom, :telephone, :telephone_brut, :email, NOW())
             ON CONFLICT (telephone) DO UPDATE SET nom = EXCLUDED.nom, prenom = EXCLUDED.prenom,
                email = EXCLUDED.email, updated_at = NOW()
             RETURNING id, (xmax = 0) AS inserted"
        );

        foreach ($rows as $line) {
            $lineNumber = $total + 2; // +1 pour compenser l'en-tête, +1 car $total pas encore incrémenté
            if (trim(implode('', $line)) === '') {
                continue; // ligne totalement vide : ne compte pas comme une ligne analysée
            }
            $total++;

            $get = fn(string $key) => isset($colIndex[$key]) ? trim((string) ($line[$colIndex[$key]] ?? '')) : '';
            $telephoneBrut = $get('telephone');
            $phone = PhoneNumberService::normalize($telephoneBrut);

            if ($phone === null) {
                $invalides++;
                $errors[] = ['ligne' => $lineNumber, 'erreur' => "Numéro de téléphone invalide : \"$telephoneBrut\""];
                continue;
            }

            if (isset($seenInBatch[$phone])) {
                $doublons++;
                $errors[] = ['ligne' => $lineNumber, 'erreur' => "Doublon dans le fichier pour le numéro $phone."];
                continue;
            }
            $seenInBatch[$phone] = true;

            $upsert->execute([
                ':nom' => $get('nom') ?: null,
                ':prenom' => $get('prenom') ?: null,
                ':telephone' => $phone,
                ':telephone_brut' => $telephoneBrut,
                ':email' => $get('email') ?: null,
            ]);
            $result = $upsert->fetch(PDO::FETCH_ASSOC);
            $wasInsert = in_array($result['inserted'], [true, 't', '1', 1], true);
            $wasInsert ? $valides++ : $doublons++;

            if ($groupeId !== null) {
                $this->addContactToGroup($groupeId, (int) $result['id']);
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

        return substr_count((string) $firstLine, ';') > substr_count((string) $firstLine, ',') ? ';' : ',';
    }

    private function normalizeHeader(string $header): string
    {
        $header = mb_strtolower(trim($header));

        return strtr($header, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a']);
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
        $stmt = $this->pdo->prepare("INSERT INTO imports_contacts (filename, created_by) VALUES (:filename, :created_by) RETURNING id");
        $stmt->execute([':filename' => basename($path), ':created_by' => $createdBy]);

        return (int) $stmt->fetchColumn();
    }

    private function finalizeImportBatch(int $importId, int $total, int $valides, int $invalides, int $doublons, array $errors): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE imports_contacts SET total_lignes = :total, valides = :valides, invalides = :invalides,
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

    /** @return list<array{ligne:int,erreur:string}> */
    public function getImportErrors(int $importId): array
    {
        $stmt = $this->pdo->prepare("SELECT errors_json FROM imports_contacts WHERE id = :id");
        $stmt->execute([':id' => $importId]);
        $json = $stmt->fetchColumn();
        $decoded = $json ? json_decode((string) $json, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /** @return list<array<string,mixed>> */
    public function getImportHistory(int $limit = 10): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM imports_contacts ORDER BY created_at DESC LIMIT :limit");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
