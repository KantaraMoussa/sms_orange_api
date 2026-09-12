<?php
// Crédits (cahier des charges V2.0 §34, Phase 2) : solde interne par
// organisation, distinct du solde Orange réel partagé entre organisations
// (§59) — voir CreditService pour le détail. Pas de vrai paiement (le
// cahier des charges demande seulement de préparer l'architecture) :
// recharge manuelle par un SUPER_ADMIN, réservée à ce rôle plateforme.
$balance = credits()->balance();
$history = credits()->history(50);
$isSuperAdmin = auth()->hasRole(['SUPER_ADMIN']);
$allOrganizations = $isSuperAdmin ? organizations()->all() : [];
?>
<div class="row">
    <div class="col-md-4">
        <div class="card bg-grd-primary order-card">
            <div class="card-body">
                <h6 class="text-white">Solde de crédits</h6>
                <h2 class="text-end text-white"><i class="ph ph-coins float-start"></i><span><?= number_format($balance, 0, ',', ' ') ?></span></h2>
            </div>
        </div>
    </div>
</div>

<?php if ($isSuperAdmin): ?>
<div class="row mt-2">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><h5>Recharger une organisation (SUPER_ADMIN)</h5></div>
            <div class="card-body">
                <form method="post" action="../server/app.php" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <div class="col-md-5">
                        <label class="form-label" for="creditOrgId">Organisation</label>
                        <select id="creditOrgId" name="credit_organization_id" class="form-select" required>
                            <?php foreach ($allOrganizations as $org): ?>
                                <option value="<?= (int) $org['id'] ?>"><?= htmlspecialchars($org['nom']) ?> (<?= number_format((int) $org['credits_balance'], 0, ',', ' ') ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="creditAmount">Montant</label>
                        <input type="number" id="creditAmount" name="credit_amount" class="form-control" min="1" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="creditDescription">Motif</label>
                        <input type="text" id="creditDescription" name="credit_description" class="form-control" placeholder="Ex. recharge manuelle">
                    </div>
                    <div class="col-12">
                        <button type="submit" name="recharge_credits" class="btn btn-primary">Recharger</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row mt-3">
    <div class="col-sm-12">
        <div class="card table-card">
            <div class="card-header"><h4>Historique de consommation</h4></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Montant</th>
                                <th>Solde après</th>
                                <th>Campagne</th>
                                <th>Détail</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($history)): ?>
                            <tr><td colspan="6" class="text-center text-muted">Aucune transaction pour le moment.</td></tr>
                            <?php else: foreach ($history as $h): ?>
                            <tr>
                                <td><?= htmlspecialchars($h['created_at']) ?></td>
                                <td><?= $h['type'] === 'credit' ? '<span class="badge bg-light-success">Recharge</span>' : '<span class="badge bg-light-danger">Consommation</span>' ?></td>
                                <td class="<?= $h['type'] === 'credit' ? 'text-success' : 'text-danger' ?>"><?= $h['type'] === 'credit' ? '+' : '-' ?><?= (int) $h['amount'] ?></td>
                                <td><?= number_format((int) $h['balance_after'], 0, ',', ' ') ?></td>
                                <td><?= htmlspecialchars($h['campagne_nom'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($h['description'] ?? '') ?></td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
