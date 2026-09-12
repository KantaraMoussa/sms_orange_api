<?php

namespace App\Services;

use PDO;
use Exception;

/**
 * Solde de crédits SMS interne par organisation (cahier des charges V2.0
 * §34, Phase 2) — distinct du solde Orange réel (OrangeSmsService::getBalance()),
 * qui est partagé entre toutes les organisations de la plateforme (§59).
 * Sans cette comptabilité séparée, rien n'empêcherait une organisation
 * d'épuiser le solde Orange partagé au détriment des autres.
 *
 * Pas de vraie passerelle de paiement (cahier des charges : "préparer une
 * architecture compatible avec un futur système de recharge/paiement", pas
 * l'implémenter) — recharge() est réservée à SUPER_ADMIN (vérifié par
 * l'appelant, server/app.php), une recharge manuelle en attendant une
 * intégration réelle.
 */
class CreditService
{
    public function __construct(private PDO $pdo, private int $organizationId)
    {
    }

    public function balance(): int
    {
        $stmt = $this->pdo->prepare('SELECT credits_balance FROM organizations WHERE id = :id');
        $stmt->execute([':id' => $this->organizationId]);

        return (int) $stmt->fetchColumn();
    }

    public function hasSufficientBalance(int $amount): bool
    {
        return $this->balance() >= $amount;
    }

    /**
     * Recharge manuelle (§34) — jamais appelée directement par une
     * organisation sur elle-même, réservée à SUPER_ADMIN.
     *
     * @throws Exception si $amount n'est pas strictement positif.
     */
    public function credit(int $amount, string $description, ?string $createdBy = null): int
    {
        if ($amount <= 0) {
            throw new Exception('Le montant à créditer doit être positif.');
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE organizations SET credits_balance = credits_balance + :amount WHERE id = :id')
                ->execute([':amount' => $amount, ':id' => $this->organizationId]);
            $newBalance = $this->balance();
            $this->recordTransaction('credit', $amount, $newBalance, $description, null, $createdBy);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $newBalance;
    }

    /**
     * Débite si le solde est suffisant (verrouillage de ligne pour empêcher
     * deux envois concurrents de faire passer le solde sous 0 — même
     * principe que CampaignQueueService::claimBatch()). Utilisé pour le
     * contrôle PRÉALABLE à un envoi (§20) : si insuffisant, ne débite rien
     * et renvoie false, à l'appelant de bloquer l'envoi.
     */
    public function debitIfSufficient(int $amount, string $description, ?int $campagneId = null): bool
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT credits_balance FROM organizations WHERE id = :id FOR UPDATE');
            $stmt->execute([':id' => $this->organizationId]);
            $current = (int) $stmt->fetchColumn();

            if ($current < $amount) {
                $this->pdo->rollBack();

                return false;
            }

            $newBalance = $current - $amount;
            $this->pdo->prepare('UPDATE organizations SET credits_balance = :balance WHERE id = :id')
                ->execute([':balance' => $newBalance, ':id' => $this->organizationId]);
            $this->recordTransaction('debit', $amount, $newBalance, $description, $campagneId, null);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return true;
    }

    /**
     * Enregistre la consommation d'un envoi RÉEL déjà effectué (appelée par
     * CampaignQueueService::processRecipient() après un envoi Orange réussi)
     * — contrairement à debitIfSufficient(), ne bloque jamais : le SMS a déjà
     * été facturé par Orange à ce stade, impossible de "l'annuler" si le
     * solde interne est insuffisant. Un solde négatif est possible (rare,
     * uniquement si plusieurs campagnes tournent en concurrence au-delà de
     * ce que le contrôle préalable avait anticipé) et volontairement visible
     * plutôt que masqué — signal qu'il faut recharger.
     */
    public function recordConsumption(int $amount, string $description, ?int $campagneId = null): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE organizations SET credits_balance = credits_balance - :amount WHERE id = :id')
                ->execute([':amount' => $amount, ':id' => $this->organizationId]);
            $newBalance = $this->balance();
            $this->recordTransaction('debit', $amount, $newBalance, $description, $campagneId, null);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @return list<array<string,mixed>> */
    public function history(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ct.*, c.nom AS campagne_nom FROM credit_transactions ct
             LEFT JOIN campagne c ON c.id = ct.campagne_id
             WHERE ct.organization_id = :org ORDER BY ct.created_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':org', $this->organizationId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function recordTransaction(string $type, int $amount, int $balanceAfter, string $description, ?int $campagneId, ?string $createdBy): void
    {
        $this->pdo->prepare(
            'INSERT INTO credit_transactions (organization_id, type, amount, balance_after, description, campagne_id, created_by)
             VALUES (:org, :type, :amount, :balance_after, :description, :campagne_id, :created_by)'
        )->execute([
            ':org' => $this->organizationId,
            ':type' => $type,
            ':amount' => $amount,
            ':balance_after' => $balanceAfter,
            ':description' => $description,
            ':campagne_id' => $campagneId,
            ':created_by' => $createdBy,
        ]);
    }
}
