<?php
$balanceError = null;
$balance = [];
$stats = [];
$history = [];

try {
    $balance = orangeSms()->getBalance();
    $stats = orangeSms()->getStatistics();
    $history = orangeSms()->getHistory();
} catch (Exception $e) {
    $balanceError = $e->getMessage();
}

// Aplati les statistiques d'usage (structure imbriquée service > pays > application) en lignes simples.
$statRows = [];
foreach (($stats['partnerStatistics']['statistics'] ?? []) as $service) {
    foreach (($service['serviceStatistics'] ?? []) as $svc) {
        foreach (($svc['countryStatistics'] ?? []) as $country) {
            $statRows[] = [
                'service' => $service['service'] ?? '',
                'pays' => $svc['country'] ?? '',
                'application' => $country['appid'] ?: '(par défaut)',
                'usage' => (int) ($country['usage'] ?? 0),
                'rejets' => (int) ($country['nbEnforcements'] ?? 0),
            ];
        }
    }
}
$totalUsage = array_sum(array_column($statRows, 'usage'));
?>
<hr>

<?php if ($balanceError): ?>
<div class="alert alert-danger">❌ Impossible de contacter l'API Orange : <?= htmlspecialchars($balanceError) ?></div>
<?php else: ?>

<div class="alert alert-info">
    ℹ️ L'API Orange SMS ne conserve pas le détail individuel des SMS envoyés (destinataire, contenu) — seulement le solde, des statistiques d'usage agrégées et l'historique des recharges. Pour le détail par SMS envoyé via SMS_ORANGE (destinataire, statut, erreur), voir le <a href="?page=rapports">module Rapports</a> ou le journal de chaque campagne.
</div>

<div class="row text-center mb-3">
    <div class="col-md-3 col-sm-6">
        <h3><?= (int) ($balance['availableUnits'] ?? 0) ?></h3>
        <small class="text-muted">SMS disponibles</small>
    </div>
    <div class="col-md-3 col-sm-6">
        <h3><?= $totalUsage ?></h3>
        <small class="text-muted">SMS utilisés (total API)</small>
    </div>
    <div class="col-md-3 col-sm-6">
        <h3 class="<?= ($balance['status'] ?? '') === 'EXPIRED' ? 'text-danger' : 'text-success' ?>"><?= htmlspecialchars($balance['status'] ?? '—') ?></h3>
        <small class="text-muted">Statut du contrat</small>
    </div>
    <div class="col-md-3 col-sm-6">
        <h3><?= !empty($balance['expirationDate']) ? (new DateTime($balance['expirationDate']))->format('d/m/Y') : '—' ?></h3>
        <small class="text-muted">Expiration</small>
    </div>
</div>

<div class="row">
    <div class="col-sm-6">
        <div class="card table-card">
            <div class="card-header">
                <h4>Statistiques d'usage (API Orange)</h4>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Service</th>
                                <th>Pays</th>
                                <th>Application</th>
                                <th>SMS utilisés</th>
                                <th>Rejets</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($statRows)): ?>
                            <tr><td colspan="5" class="text-center text-muted">Aucune statistique disponible.</td></tr>
                            <?php else: foreach ($statRows as $s): ?>
                            <tr>
                                <td><?= htmlspecialchars($s['service']) ?></td>
                                <td><?= htmlspecialchars($s['pays']) ?></td>
                                <td><?= htmlspecialchars($s['application']) ?></td>
                                <td><?= $s['usage'] ?></td>
                                <td><?= $s['rejets'] ?></td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6">
        <div class="card table-card">
            <div class="card-header">
                <h4>Historique des recharges (API Orange)</h4>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Offre</th>
                                <th>Prix</th>
                                <th>Paiement</th>
                                <th>Nouveau solde</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($history)): ?>
                            <tr><td colspan="5" class="text-center text-muted">Aucun historique disponible.</td></tr>
                            <?php else: foreach ($history as $h): ?>
                            <tr>
                                <td><?= !empty($h['purchaseDate']) ? (new DateTime($h['purchaseDate']))->format('d/m/Y H:i') : '' ?></td>
                                <td><?= htmlspecialchars($h['bundleDescription'] ?? '') ?></td>
                                <td><?= isset($h['price']) ? number_format((float) $h['price'], 0, ',', ' ') . ' ' . htmlspecialchars($h['currency'] ?? '') : '' ?></td>
                                <td><?= htmlspecialchars($h['paymentMode'] ?? '') ?></td>
                                <td><?= (int) ($h['newAvailableUnits'] ?? 0) ?></td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>
