<?php

namespace App\Services;

use PDO;

/**
 * Journal d'activité / piste d'audit (cahier des charges V2.0 §35/§64) :
 * qui a créé, lancé, mis en pause, repris, annulé ou réessayé une campagne,
 * et qui s'est connecté/déconnecté. Volontairement une classe à part et non
 * des fonctions ajoutées à server/config.php (déjà signalé comme "God file"
 * à ne pas aggraver, AUDIT.md §6/§49).
 */
class ActivityLogger
{
    public function __construct(private PDO $pdo)
    {
    }

    public function log(string $action, ?int $campagneId = null, ?string $userNom = null, ?string $details = null): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO activity_logs (user_nom, action, campagne_id, details) VALUES (:u, :a, :c, :d)"
        );
        $stmt->execute([
            ':u' => $userNom,
            ':a' => $action,
            ':c' => $campagneId,
            ':d' => $details,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT al.*, c.nom AS campagne_nom
             FROM activity_logs al
             LEFT JOIN campagne c ON c.id = al.campagne_id
             ORDER BY al.created_at DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function forCampaign(int $campagneId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM activity_logs WHERE campagne_id = :id ORDER BY created_at DESC"
        );
        $stmt->execute([':id' => $campagneId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
