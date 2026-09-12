<?php

namespace App\Services;

use PDO;

/**
 * Segments dynamiques (cahier des charges V2.0 §13) : un segment stocke un
 * critère de filtre (JSON), jamais une copie des contacts — la liste de
 * contacts correspondante est recalculée à chaque utilisation
 * (resolveContacts()), pour toujours refléter l'état actuel de la base de
 * contacts (un contact ajouté après la création du segment y apparaît
 * automatiquement s'il correspond au critère).
 *
 * Critères supportés (limités aux colonnes réellement présentes sur
 * contacts_v2 aujourd'hui — pas de "ville" ou autre champ personnalisé) :
 * - statut: string|null — égalité exacte sur contacts_v2.statut
 * - search: string|null — sous-chaîne dans nom/prenom/telephone/email
 * - groupe_id: int|null — appartenance à ce groupe
 * - created_after / created_before: string|null (Y-m-d) — date d'ajout
 */
class SegmentService
{
    public function __construct(private PDO $pdo, private int $organizationId)
    {
    }

    /** @param array{statut?:?string, search?:?string, groupe_id?:?int, created_after?:?string, created_before?:?string} $criteria */
    public function create(string $nom, array $criteria, ?string $createdBy = null): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO segments (organization_id, nom, criteria, created_by) VALUES (:org, :nom, :criteria, :created_by) RETURNING id"
        );
        $stmt->execute([
            ':org' => $this->organizationId,
            ':nom' => $nom,
            ':criteria' => json_encode($this->normalizeCriteria($criteria)),
            ':created_by' => $createdBy,
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function update(int $id, string $nom, array $criteria): void
    {
        $this->pdo->prepare(
            "UPDATE segments SET nom = :nom, criteria = :criteria, updated_at = NOW() WHERE id = :id AND organization_id = :org"
        )->execute([
            ':nom' => $nom,
            ':criteria' => json_encode($this->normalizeCriteria($criteria)),
            ':id' => $id,
            ':org' => $this->organizationId,
        ]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare("DELETE FROM segments WHERE id = :id AND organization_id = :org")
            ->execute([':id' => $id, ':org' => $this->organizationId]);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM segments WHERE id = :id AND organization_id = :org");
        $stmt->execute([':id' => $id, ':org' => $this->organizationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['criteria'] = json_decode($row['criteria'], true) ?? [];

        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM segments WHERE organization_id = :org ORDER BY nom");
        $stmt->execute([':org' => $this->organizationId]);

        return array_map(function ($row) {
            $row['criteria'] = json_decode($row['criteria'], true) ?? [];

            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Contacts correspondant au critère d'un segment, à l'instant présent —
     * jamais une liste figée à la création du segment (§13 : "ne pas
     * nécessairement copier les contacts dans le segment").
     *
     * @return list<array<string,mixed>>
     */
    public function resolveContacts(int $segmentId): array
    {
        $segment = $this->find($segmentId);
        if ($segment === null) {
            return [];
        }

        [$where, $params, $join] = $this->buildWhere($segment['criteria']);
        $sql = "SELECT DISTINCT c.* FROM contacts_v2 c $join WHERE " . implode(' AND ', $where) . ' ORDER BY c.nom, c.prenom';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countContacts(int $segmentId): int
    {
        $segment = $this->find($segmentId);
        if ($segment === null) {
            return 0;
        }

        [$where, $params, $join] = $this->buildWhere($segment['criteria']);
        $sql = "SELECT COUNT(DISTINCT c.id) FROM contacts_v2 c $join WHERE " . implode(' AND ', $where);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Compte à blanc, sans avoir créé le segment — utilisé par l'aperçu en
     * direct du formulaire de création (§13 : voir immédiatement combien de
     * contacts correspondent avant d'enregistrer).
     */
    public function previewCount(array $criteria): int
    {
        [$where, $params, $join] = $this->buildWhere($this->normalizeCriteria($criteria));
        $sql = "SELECT COUNT(DISTINCT c.id) FROM contacts_v2 c $join WHERE " . implode(' AND ', $where);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Un contact réel correspondant au critère d'un segment déjà enregistré
     * — pour la prévisualisation du message dans le créateur de campagne
     * (même logique que ContactService::sampleContact(), réservée aux
     * groupes/toute l'audience).
     */
    public function sampleContact(int $segmentId): ?array
    {
        $segment = $this->find($segmentId);
        if ($segment === null) {
            return null;
        }

        [$where, $params, $join] = $this->buildWhere($segment['criteria']);
        $sql = "SELECT c.* FROM contacts_v2 c $join WHERE " . implode(' AND ', $where) . ' ORDER BY c.id LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    private function normalizeCriteria(array $criteria): array
    {
        return [
            'statut' => $criteria['statut'] ?? null ?: null,
            'search' => $criteria['search'] ?? null ?: null,
            'groupe_id' => !empty($criteria['groupe_id']) ? (int) $criteria['groupe_id'] : null,
            'created_after' => $criteria['created_after'] ?? null ?: null,
            'created_before' => $criteria['created_before'] ?? null ?: null,
        ];
    }

    /** @return array{0: list<string>, 1: array<string,mixed>, 2: string} */
    private function buildWhere(array $criteria): array
    {
        $where = ['c.organization_id = :organization_id'];
        $params = [':organization_id' => $this->organizationId];
        $join = '';

        if (!empty($criteria['statut'])) {
            $where[] = 'c.statut = :statut';
            $params[':statut'] = $criteria['statut'];
        }
        if (!empty($criteria['search'])) {
            $where[] = '(c.nom ILIKE :search OR c.prenom ILIKE :search OR c.telephone ILIKE :search OR c.email ILIKE :search)';
            $params[':search'] = '%' . $criteria['search'] . '%';
        }
        if (!empty($criteria['groupe_id'])) {
            $join = 'JOIN groupe_contacts_v2 gc ON gc.contact_id = c.id';
            $where[] = 'gc.groupe_id = :groupe_id';
            $params[':groupe_id'] = $criteria['groupe_id'];
        }
        if (!empty($criteria['created_after'])) {
            $where[] = 'c.created_at >= :created_after';
            $params[':created_after'] = $criteria['created_after'];
        }
        if (!empty($criteria['created_before'])) {
            $where[] = 'c.created_at < (:created_before)::date + INTERVAL \'1 day\'';
            $params[':created_before'] = $criteria['created_before'];
        }

        return [$where, $params, $join];
    }
}
