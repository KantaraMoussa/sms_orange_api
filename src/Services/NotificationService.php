<?php

namespace App\Services;

use PDO;

/**
 * Centre de notifications (cahier des charges V2.0 §73) : solde faible,
 * campagne terminée/partiellement échouée, import terminé. Discrètes et non
 * bloquantes — un bandeau dans l'en-tête (`app/index.php`), pas de popup.
 *
 * Scopé à une organisation (§59) : voir ContactService pour la stratégie.
 */
class NotificationService
{
    public function __construct(private PDO $pdo, private int $organizationId)
    {
    }

    public function create(string $type, string $titre, string $message = '', ?int $campagneId = null): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO notifications (type, titre, message, campagne_id, organization_id) VALUES (:type, :titre, :message, :campagne_id, :organization_id) RETURNING id"
        );
        $stmt->execute([
            ':type' => $type,
            ':titre' => $titre,
            ':message' => $message,
            ':campagne_id' => $campagneId,
            ':organization_id' => $this->organizationId,
        ]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Crée la notification seulement si aucune du même type n'existe déjà
     * dans la fenêtre donnée — évite de spammer (ex. solde faible à chaque
     * chargement du dashboard, campagne déjà signalée terminée à chaque poll).
     */
    public function createUnlessRecentDuplicate(string $type, string $titre, string $message, ?int $campagneId, int $windowMinutes): void
    {
        $where = 'organization_id = :organization_id AND type = :type AND created_at >= NOW() - (:mins || \' minutes\')::interval';
        $params = [':organization_id' => $this->organizationId, ':type' => $type, ':mins' => $windowMinutes];

        if ($campagneId !== null) {
            $where .= ' AND campagne_id = :campagne_id';
            $params[':campagne_id'] = $campagneId;
        }

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM notifications WHERE $where");
        $stmt->execute($params);

        if ((int) $stmt->fetchColumn() === 0) {
            $this->create($type, $titre, $message, $campagneId);
        }
    }

    public function unreadCount(): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM notifications WHERE lu = FALSE AND organization_id = :organization_id");
        $stmt->execute([':organization_id' => $this->organizationId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 15): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM notifications WHERE organization_id = :organization_id ORDER BY created_at DESC LIMIT :limit");
        $stmt->bindValue(':organization_id', $this->organizationId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function markRead(int $id): void
    {
        $this->pdo->prepare("UPDATE notifications SET lu = TRUE WHERE id = :id AND organization_id = :organization_id")
            ->execute([':id' => $id, ':organization_id' => $this->organizationId]);
    }

    public function markAllRead(): void
    {
        $this->pdo->prepare("UPDATE notifications SET lu = TRUE WHERE lu = FALSE AND organization_id = :organization_id")
            ->execute([':organization_id' => $this->organizationId]);
    }
}
