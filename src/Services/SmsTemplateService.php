<?php

namespace App\Services;

use PDO;

/**
 * Bibliothèque de modèles SMS réutilisables (cahier des charges V2.0 §25) :
 * créer/modifier/dupliquer/archiver, avec catégories. Le rendu des variables
 * ({{...}}) reste la responsabilité de MessageTemplateService — cette classe
 * ne fait que stocker/organiser le texte des modèles.
 */
class SmsTemplateService
{
    public const CATEGORIES = ['marketing', 'transactionnel', 'notification', 'rappel', 'alerte'];

    public function __construct(private PDO $pdo)
    {
    }

    public function create(string $nom, string $categorie, string $contenu, ?string $createdBy = null): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO sms_templates (nom, categorie, contenu, created_by) VALUES (:nom, :categorie, :contenu, :created_by) RETURNING id"
        );
        $stmt->execute([':nom' => $nom, ':categorie' => $categorie, ':contenu' => $contenu, ':created_by' => $createdBy]);

        return (int) $stmt->fetchColumn();
    }

    public function update(int $id, string $nom, string $categorie, string $contenu): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE sms_templates SET nom = :nom, categorie = :categorie, contenu = :contenu, updated_at = NOW() WHERE id = :id"
        );
        $stmt->execute([':nom' => $nom, ':categorie' => $categorie, ':contenu' => $contenu, ':id' => $id]);
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
        $this->pdo->prepare("UPDATE sms_templates SET archive = :a WHERE id = :id")
            ->execute([':a' => $archived ? 't' : 'f', ':id' => $id]);
    }

    /** @return list<array<string,mixed>> */
    public function all(bool $includeArchived = false): array
    {
        $sql = "SELECT * FROM sms_templates";
        if (!$includeArchived) {
            $sql .= " WHERE archive = FALSE";
        }
        $sql .= " ORDER BY categorie, nom";

        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM sms_templates WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }
}
