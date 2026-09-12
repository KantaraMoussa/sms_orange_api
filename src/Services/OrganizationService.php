<?php

namespace App\Services;

use PDO;

/**
 * Fiche entreprise (cahier des charges V2.0 §5, §7) : une organisation par
 * client de la plateforme, totalement isolée des autres (voir migration
 * 012_organizations.sql pour la stratégie d'isolation par organization_id).
 */
class OrganizationService
{
    private const EDITABLE_FIELDS = [
        'nom', 'logo_url', 'secteur', 'telephone', 'email', 'adresse',
        'pays', 'fuseau_horaire', 'devise', 'sender_name', 'low_balance_threshold',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM organizations WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Liste TOUTES les organisations de la plateforme, volontairement non
     * scopée (contrairement aux autres Services) — réservée aux usages
     * plateforme (SUPER_ADMIN, ex. sélection d'une organisation à recharger
     * en crédits, cahier des charges §34) et jamais exposée aux rôles
     * organisation, qui ne doivent connaître que la leur.
     *
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM organizations ORDER BY nom')->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array{nom: string, secteur?: string, telephone?: string, email?: string,
     *              adresse?: string, pays?: string, fuseau_horaire?: string,
     *              devise?: string, sender_name?: string, logo_url?: string} $data
     */
    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO organizations (nom, secteur, telephone, email, adresse, pays, fuseau_horaire, devise, sender_name, logo_url)
             VALUES (:nom, :secteur, :telephone, :email, :adresse, :pays, :fuseau_horaire, :devise, :sender_name, :logo_url)
             RETURNING id"
        );
        $stmt->execute([
            ':nom' => trim($data['nom']),
            ':secteur' => $data['secteur'] ?? null,
            ':telephone' => $data['telephone'] ?? null,
            ':email' => $data['email'] ?? null,
            ':adresse' => $data['adresse'] ?? null,
            ':pays' => $data['pays'] ?? 'Guinée',
            ':fuseau_horaire' => $data['fuseau_horaire'] ?? 'Africa/Conakry',
            ':devise' => $data['devise'] ?? 'GNF',
            ':sender_name' => $data['sender_name'] ?? null,
            ':logo_url' => $data['logo_url'] ?? null,
        ]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Met à jour uniquement les champs fournis dans $data (édition partielle
     * depuis le formulaire de paramètres). Les clés inconnues sont ignorées.
     */
    public function update(int $id, array $data): void
    {
        $fields = array_intersect_key($data, array_flip(self::EDITABLE_FIELDS));
        if (empty($fields)) {
            return;
        }

        $set = implode(', ', array_map(fn($f) => "$f = :$f", array_keys($fields)));
        $params = [];
        foreach ($fields as $key => $value) {
            $params[":$key"] = $value;
        }
        $params[':id'] = $id;

        $this->pdo->prepare("UPDATE organizations SET $set, updated_at = NOW() WHERE id = :id")->execute($params);
    }
}
