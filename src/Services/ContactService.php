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
 *
 * Scopé à une organisation (§59, migration 012_organizations.sql) : chaque
 * méthode lit/écrit exclusivement les lignes de $organizationId, injecté à la
 * construction (voir config/services.php::contacts()). Une tentative d'agir
 * sur l'id d'un contact/groupe d'une autre organisation affecte 0 ligne au
 * lieu de lever une erreur — comportement volontaire : ne pas révéler par la
 * différence d'erreur si l'id existe chez un concurrent.
 */
class ContactService
{
    private const COLUMN_ALIASES = [
        'nom' => ['nom', 'lastname', 'last_name', 'name'],
        'prenom' => ['prenom', 'prénom', 'firstname', 'first_name'],
        'telephone' => ['telephone', 'téléphone', 'tel', 'phone', 'numero', 'numéro'],
        'email' => ['email', 'e-mail', 'mail'],
    ];

    public function __construct(private PDO $pdo, private int $organizationId)
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
            "INSERT INTO contacts_v2 (nom, prenom, telephone, telephone_brut, email, organization_id)
             VALUES (:nom, :prenom, :telephone, :telephone_brut, :email, :organization_id)
             ON CONFLICT (organization_id, telephone) DO UPDATE SET nom = EXCLUDED.nom, prenom = EXCLUDED.prenom,
                email = EXCLUDED.email, updated_at = NOW()
             RETURNING id"
        );
        $stmt->execute([
            ':nom' => $nom ?: null,
            ':prenom' => $prenom ?: null,
            ':telephone' => $phone,
            ':telephone_brut' => $telephoneRaw,
            ':email' => $email ?: null,
            ':organization_id' => $this->organizationId,
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function deleteContact(int $id): void
    {
        $this->pdo->prepare("DELETE FROM contacts_v2 WHERE id = :id AND organization_id = :organization_id")
            ->execute([':id' => $id, ':organization_id' => $this->organizationId]);
    }

    /** @return list<array<string,mixed>> */
    public function allContacts(?int $groupeId = null, string $search = ''): array
    {
        $where = ['c.organization_id = :organization_id'];
        $params = [':organization_id' => $this->organizationId];

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

    /**
     * Un seul contact réel de l'audience choisie, pour la prévisualisation
     * (§17 : le moteur de prévisualisation doit utiliser un vrai contact, pas
     * un placeholder) sans jamais charger toute l'audience en mémoire pour
     * ça (§42, jusqu'à 10 000+ contacts).
     */
    public function sampleContact(?int $groupeId = null): ?array
    {
        $where = ['c.organization_id = :organization_id'];
        $params = [':organization_id' => $this->organizationId];
        $join = '';

        if ($groupeId !== null) {
            $join = 'JOIN groupe_contacts_v2 gc ON gc.contact_id = c.id';
            $where[] = 'gc.groupe_id = :groupe_id';
            $params[':groupe_id'] = $groupeId;
        }

        $sql = "SELECT c.* FROM contacts_v2 c $join WHERE " . implode(' AND ', $where) . ' ORDER BY c.id LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function countContacts(): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM contacts_v2 WHERE organization_id = :organization_id");
        $stmt->execute([':organization_id' => $this->organizationId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Répartition des contacts selon qu'ils appartiennent à au moins un
     * groupe ou non — utilisée par le tableau de bord (carte "Contacts" en
     * anneau), pas de signification métier au-delà de ça.
     *
     * @return array{with_group:int, without_group:int}
     */
    public function countByGroupMembership(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                COUNT(*) FILTER (WHERE gc.contact_id IS NOT NULL) AS with_group,
                COUNT(*) FILTER (WHERE gc.contact_id IS NULL) AS without_group
             FROM contacts_v2 c
             LEFT JOIN (SELECT DISTINCT contact_id FROM groupe_contacts_v2) gc ON gc.contact_id = c.id
             WHERE c.organization_id = :organization_id"
        );
        $stmt->execute([':organization_id' => $this->organizationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'with_group' => (int) ($row['with_group'] ?? 0),
            'without_group' => (int) ($row['without_group'] ?? 0),
        ];
    }

    // ------------------------------------------------------------------
    // Groupes
    // ------------------------------------------------------------------

    public function createGroup(string $nom, string $description = '', ?string $createdBy = null): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO groupes_v2 (nom, description, created_by, organization_id) VALUES (:nom, :description, :created_by, :organization_id) RETURNING id"
        );
        $stmt->execute([
            ':nom' => $nom,
            ':description' => $description,
            ':created_by' => $createdBy,
            ':organization_id' => $this->organizationId,
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function deleteGroup(int $id): void
    {
        $this->pdo->prepare("DELETE FROM groupes_v2 WHERE id = :id AND organization_id = :organization_id")
            ->execute([':id' => $id, ':organization_id' => $this->organizationId]);
    }

    /** @return list<array<string,mixed>> */
    public function allGroups(): array
    {
        $sql = "SELECT g.*, COUNT(gc.contact_id) AS nombre_contacts
                FROM groupes_v2 g
                LEFT JOIN groupe_contacts_v2 gc ON gc.groupe_id = g.id
                WHERE g.organization_id = :organization_id
                GROUP BY g.id
                ORDER BY g.nom";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':organization_id' => $this->organizationId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findGroup(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM groupes_v2 WHERE id = :id AND organization_id = :organization_id");
        $stmt->execute([':id' => $id, ':organization_id' => $this->organizationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Le groupe ET le contact doivent appartenir à l'organisation courante —
     * sans cette double vérification, un id de contact d'une autre
     * organisation glissé dans le formulaire pourrait être rattaché à un de
     * nos groupes (les ids restent globaux, seule cette requête protège le
     * lien).
     */
    public function addContactToGroup(int $groupeId, int $contactId): void
    {
        $this->pdo->prepare(
            "INSERT INTO groupe_contacts_v2 (groupe_id, contact_id)
             SELECT :g, :c
             WHERE EXISTS (SELECT 1 FROM groupes_v2 WHERE id = :g AND organization_id = :organization_id)
               AND EXISTS (SELECT 1 FROM contacts_v2 WHERE id = :c AND organization_id = :organization_id)
             ON CONFLICT DO NOTHING"
        )->execute([':g' => $groupeId, ':c' => $contactId, ':organization_id' => $this->organizationId]);
    }

    public function removeContactFromGroup(int $groupeId, int $contactId): void
    {
        $this->pdo->prepare(
            "DELETE FROM groupe_contacts_v2 WHERE groupe_id = :g AND contact_id = :c
             AND EXISTS (SELECT 1 FROM groupes_v2 WHERE id = :g AND organization_id = :organization_id)"
        )->execute([':g' => $groupeId, ':c' => $contactId, ':organization_id' => $this->organizationId]);
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
            "INSERT INTO contacts_v2 (nom, prenom, telephone, telephone_brut, email, organization_id, updated_at)
             VALUES (:nom, :prenom, :telephone, :telephone_brut, :email, :organization_id, NOW())
             ON CONFLICT (organization_id, telephone) DO UPDATE SET nom = EXCLUDED.nom, prenom = EXCLUDED.prenom,
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
                ':organization_id' => $this->organizationId,
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
        $stmt = $this->pdo->prepare("INSERT INTO imports_contacts (filename, created_by, organization_id) VALUES (:filename, :created_by, :organization_id) RETURNING id");
        $stmt->execute([':filename' => basename($path), ':created_by' => $createdBy, ':organization_id' => $this->organizationId]);

        return (int) $stmt->fetchColumn();
    }

    private function finalizeImportBatch(int $importId, int $total, int $valides, int $invalides, int $doublons, array $errors): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE imports_contacts SET total_lignes = :total, valides = :valides, invalides = :invalides,
             doublons = :doublons, errors_json = :errors WHERE id = :id AND organization_id = :organization_id"
        );
        $stmt->execute([
            ':total' => $total,
            ':valides' => $valides,
            ':invalides' => $invalides,
            ':doublons' => $doublons,
            ':errors' => json_encode(array_slice($errors, 0, 500)),
            ':id' => $importId,
            ':organization_id' => $this->organizationId,
        ]);
    }

    /** @return list<array{ligne:int,erreur:string}> */
    public function getImportErrors(int $importId): array
    {
        $stmt = $this->pdo->prepare("SELECT errors_json FROM imports_contacts WHERE id = :id AND organization_id = :organization_id");
        $stmt->execute([':id' => $importId, ':organization_id' => $this->organizationId]);
        $json = $stmt->fetchColumn();
        $decoded = $json ? json_decode((string) $json, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /** @return list<array<string,mixed>> */
    public function getImportHistory(int $limit = 10): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM imports_contacts WHERE organization_id = :organization_id ORDER BY created_at DESC LIMIT :limit");
        $stmt->bindValue(':organization_id', $this->organizationId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
