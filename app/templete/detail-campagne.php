<?php
$campagne = (!empty($_GET['details'])) ? getSingleCampagne($_GET['details']) : null;
// §59 : une campagne_id valide mais appartenant à une autre organisation doit
// être traitée comme "introuvable", pas affichée (IDOR sinon — l'id de
// campagne est un entier auto-incrémenté global, donc devinable/énumérable).
if (!$campagne || (int) $campagne['organization_id'] !== auth()->organizationId()) {
    echo '<div class="alert alert-danger">Campagne introuvable.</div>';
    return;
}
$messages = getMessageCampagne($campagne['id']);
$isActive = in_array($campagne['statut'], ['QUEUED', 'RUNNING'], true);
$isDraft = $campagne['statut'] === 'DRAFT';
$isFinished = in_array($campagne['statut'], ['COMPLETED', 'PARTIAL', 'FAILED', 'CANCELLED'], true);
$badgeClass = [
    'DRAFT' => 'bg-secondary', 'QUEUED' => 'bg-info', 'RUNNING' => 'bg-primary',
    'PAUSED' => 'bg-warning', 'COMPLETED' => 'bg-success', 'PARTIAL' => 'bg-warning',
    'FAILED' => 'bg-danger', 'CANCELLED' => 'bg-dark',
][$campagne['statut']] ?? 'bg-secondary';
?>
<hr>
<div class="row mb-3">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h4 class="mb-0"><?= htmlspecialchars($campagne['nom']) ?> <span class="badge <?= $badgeClass ?>" id="campagne-statut"><?= $campagne['statut'] ?></span></h4>
                <div>
                    <?php if ($isDraft): ?>
                        <form method="post" action="../server/app.php" class="d-inline" onsubmit="return confirm('Lancer l\'envoi de <?= (int)$campagne['total_destinataires'] ?> SMS maintenant ?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="campagne_id" value="<?= $campagne['id'] ?>">
                            <button type="submit" name="launch_campagne" class="btn btn-success">🚀 Lancer l'envoi</button>
                        </form>
                    <?php elseif ($campagne['statut'] === 'RUNNING'): ?>
                        <form method="post" action="../server/app.php" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="campagne_id" value="<?= $campagne['id'] ?>">
                            <button type="submit" name="pause_campagne" class="btn btn-warning">⏸ Pause</button>
                        </form>
                    <?php elseif ($campagne['statut'] === 'PAUSED'): ?>
                        <form method="post" action="../server/app.php" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="campagne_id" value="<?= $campagne['id'] ?>">
                            <button type="submit" name="resume_campagne" class="btn btn-success">▶ Reprendre</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($isActive || $campagne['statut'] === 'PAUSED'): ?>
                        <form method="post" action="../server/app.php" class="d-inline" onsubmit="return confirm('Annuler cette campagne ? Les SMS déjà envoyés resteront dans l\'historique.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="campagne_id" value="<?= $campagne['id'] ?>">
                            <button type="submit" name="cancel_campagne" class="btn btn-outline-danger">✖ Annuler</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <p class="text-muted mb-3"><?= htmlspecialchars($campagne['description']) ?></p>

                <div class="row text-center mb-3">
                    <div class="col"><h4 id="stat-total"><?= (int) $campagne['total_destinataires'] ?></h4><small class="text-muted">Total</small></div>
                    <div class="col"><h4 class="text-success" id="stat-sent"><?= (int) $campagne['nombre_envoyes'] ?></h4><small class="text-muted">Réussis</small></div>
                    <div class="col"><h4 class="text-danger" id="stat-failed"><?= (int) $campagne['nombre_echecs'] ?></h4><small class="text-muted">Échecs</small></div>
                    <div class="col"><h4 id="stat-pending"><?= max(0, (int) $campagne['total_destinataires'] - (int) $campagne['nombre_envoyes'] - (int) $campagne['nombre_echecs']) ?></h4><small class="text-muted">En attente</small></div>
                </div>

                <?php if ($isActive): ?>
                <div class="progress mb-2" style="height: 24px;">
                    <div class="progress-bar progress-bar-striped progress-bar-animated" id="campagne-progress" role="progressbar" style="width: 0%">0%</div>
                </div>
                <p class="text-muted" id="campagne-progress-text">Envoi en cours…</p>
                <?php endif; ?>

                <?php if ($isFinished && (int) $campagne['nombre_echecs'] > 0): ?>
                <form method="post" action="../server/app.php" class="mb-3" onsubmit="return confirm('Réessayer les <?= (int)$campagne['nombre_echecs'] ?> échec(s) rattrapables ?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="campagne_id" value="<?= $campagne['id'] ?>">
                    <button type="submit" name="retry_campagne_failures" class="btn btn-outline-warning">🔁 Réessayer les échecs</button>
                </form>
                <?php endif; ?>

                <?php if ($isDraft): ?>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#importExcelModal<?= $campagne['id'] ?>">
                    <span class="fa fa-file-excel-o"></span>&nbsp; Importer un fichier Excel
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Recent Orders start -->
<div class="row">
    <div class="col-sm-12">
        <div class="card table-card">
            <div class="card-header">
                <h4>Journal d'envoi</h4>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="groupesTable" class="table table-striped table-bordered">
                        <thead>
                            <tr>
                                <th>Heure</th>
                                <th>Nom</th>
                                <th>Prénom</th>
                                <th>Destinataire</th>
                                <th>Statut</th>
                                <th>Erreur</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($messages as $m) { ?>
                            <tr>
                                <td><?= htmlspecialchars($m['date_traitement'] ?? $m['date_envoi']) ?></td>
                                <td><?= htmlspecialchars($m['nom'] ?? '') ?></td>
                                <td><?= htmlspecialchars($m['prenom'] ?? '') ?></td>
                                <td><?= htmlspecialchars($m['destinataire']) ?></td>
                                <td><?= htmlspecialchars($m['statut']) ?></td>
                                <td><?= htmlspecialchars($m['error_code'] ?? '') ?></td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<hr>

<?php if ($isDraft): ?>
<div class="modal fade" id="importExcelModal<?= $campagne['id'] ?>" tabindex="-1" aria-labelledby="importExcelModalLabel<?= $campagne['id'] ?>" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content shadow-lg">
            <div class="modal-header">
                <h5 class="modal-title">📊 Importer un fichier Excel dans <span class="text-danger"><?= htmlspecialchars($campagne['nom']) ?></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">
                <form action="../server/app.php" method="POST" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="campagne_id" value="<?= $campagne['id'] ?>">
                    <div class="mb-3">
                        <label for="excelFile<?= $campagne['id'] ?>" class="form-label">Fichier Excel (.xlsx)</label>
                        <input class="form-control" type="file" id="excelFile<?= $campagne['id'] ?>" name="excelFile" accept=".xlsx,.xls" required>
                        <small class="text-muted">⚠️ Première ligne = en-têtes, colonnes attendues (ordre libre) : <b>nom, prenom, telephone, message</b></small>
                    </div>
                    <div class="d-flex justify-content-end">
                        <button type="button" class="btn btn-danger me-2" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-success" name="import_excel_recipients">📤 Importer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($isActive): ?>
<script>
(function () {
    const campagneId = <?= (int) $campagne['id'] ?>;
    function poll() {
        fetch('../server/campaign_worker.php?campagne_id=' + campagneId)
            .then(r => r.json())
            .then(data => {
                if (data.error) return;
                document.getElementById('stat-total').textContent = data.total;
                document.getElementById('stat-sent').textContent = data.sent;
                document.getElementById('stat-failed').textContent = data.failed;
                document.getElementById('stat-pending').textContent = data.pending + data.processing;
                const pct = data.total > 0 ? Math.round(((data.sent + data.failed) / data.total) * 100) : 0;
                const bar = document.getElementById('campagne-progress');
                if (bar) {
                    bar.style.width = pct + '%';
                    bar.textContent = pct + '%';
                }
                const text = document.getElementById('campagne-progress-text');
                if (text) {
                    text.textContent = (data.sent + data.failed) + ' / ' + data.total + ' traités — ' + data.sent + ' réussis, ' + data.failed + ' échecs';
                }
                if (data.done) {
                    setTimeout(() => location.reload(), 800);
                } else {
                    setTimeout(poll, 1200);
                }
            });
    }
    poll();
})();
</script>
<?php endif; ?>
