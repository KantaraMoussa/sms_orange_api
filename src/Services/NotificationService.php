<?php

namespace App\Services;

use PDO;

/**
 * Centre de notifications (cahier des charges V2.0 §73) : solde faible,
 * campagne terminée/partiellement échouée, import terminé. Discrètes et non
 * bloquantes — un bandeau dans l'en-tête (`app/index.php`), pas de popup.
 */
class NotificationService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function create(string $type, string $titre, string $message = '', ?int $campagneId = null): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO notifications (type, titre, message, campagne_id) VALUES (:type, :titre, :message, :campagne_id) RETURNING id"
        );
        $stmt->execute([':type' => $type, ':titre' => $titre, ':message' => $message, ':campagne_id' => $campagneId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Crée la notification seulement si aucune du même type n'existe déjà
     * dans la fenêtre donnée — évite de spammer (ex. solde faible à chaque
     * chargement du dashboard, campagne déjà signalée terminée à chaque poll).
     */
    public function createUnlessRecentDuplicate(string $type, string $titre, string $message, ?int $campagneId, int $windowMinutes): void
    {
        $where = 'type = :type AND created_at >= NOW() - (:mins || \' minutes\')::interval';
        $params = [':type' => $type, ':mins' => $windowMinutes];

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
        return (int) $this->pdo->query("SELECT COUNT(*) FROM notifications WHERE lu = FALSE")->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 15): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM notifications ORDER BY created_at DESC LIMIT :limit");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function markRead(int $id): void
    {
        $this->pdo->prepare("UPDATE notifications SET lu = TRUE WHERE id = :id")->execute([':id' => $id]);
    }

    public function markAllRead(): void
    {
        $this->pdo->exec("UPDATE notifications SET lu = TRUE WHERE lu = FALSE");
    }
}
