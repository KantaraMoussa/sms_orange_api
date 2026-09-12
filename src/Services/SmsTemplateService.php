<?php

namespace App\Services;

use PDO;

/**
 * Bibliothèque de modèles SMS réutilisables (cahier des charges V2.0 §25) :
 * créer/modifier/dupliquer/archiver, avec catégories. Le rendu des variables
 * ({{...}}) reste la responsabilité de MessageTemplateService — cette classe
 * ne fait que stocker/organiser le texte des modèles.
 *
 * Scopé à une organisation (§59) : voir ContactService pour la stratégie.
 */
class SmsTemplateService
{
    public const CATEGORIES = ['marketing', 'transactionnel', 'notification', 'rappel', 'alerte'];

    public function __construct(private PDO $pdo, private int $organizationId)
    {
    }

    public function create(string $nom, string $categorie, string $contenu, ?string $createdBy = null): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO sms_templates (nom, categorie, contenu, created_by, organization_id) VALUES (:nom, :categorie, :contenu, :created_by, :organization_id) RETURNING id"
        );
        $stmt->execute([
            ':nom' => $nom,
            ':categorie' => $categorie,
            ':contenu' => $contenu,
            ':created_by' => $createdBy,
            ':organization_id' => $this->organizationId,
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function update(int $id, string $nom, string $categorie, string $contenu): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE sms_templates SET nom = :nom, categorie = :categorie, contenu = :contenu, updated_at = NOW()
             WHERE id = :id AND organization_id = :organization_id"
        );
        $stmt->execute([
            ':nom' => $nom,
            ':categorie' => $categorie,
            ':contenu' => $contenu,
            ':id' => $id,
            ':organization_id' => $this->organizationId,
        ]);
    }

    public function duplicate(int $id): ?int
    {
        $template = $this->find($id);
        if ($template === null) {
            return null;
        }

        return $this->create($template['nom'] . ' (copie)', $template['categorie'], $template['contenu'], $template['created_by']);
    }

    public function setArchived(int $id, bool $archived): void
    {
        $this->pdo->prepare("UPDATE sms_templates SET archive = :a WHERE id = :id AND organization_id = :organization_id")
            ->execute([':a' => $archived ? 't' : 'f', ':id' => $id, ':organization_id' => $this->organizationId]);
    }

    /** @return list<array<string,mixed>> */
    public function all(bool $includeArchived = false): array
    {
        $sql = "SELECT * FROM sms_templates WHERE organization_id = :organization_id";
        if (!$includeArchived) {
            $sql .= " AND archive = FALSE";
        }
        $sql .= " ORDER BY categorie, nom";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':organization_id' => $this->organizationId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM sms_templates WHERE id = :id AND organization_id = :organization_id");
        $stmt->execute([':id' => $id, ':organization_id' => $this->organizationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }
}
