<?php
$campaigns = getCampaignsReport();
$topErrors = getTopErrors();
$globalStats = getGlobalSmsStats();
?>
<hr>
<div class="row text-center mb-3">
    <div class="col-md-3 col-sm-6"><h3><?= $globalStats['envoyes'] ?></h3><small class="text-muted">SMS envoyés (total)</small></div>
    <div class="col-md-3 col-sm-6"><h3 class="text-danger"><?= $globalStats['echecs'] ?></h3><small class="text-muted">Échecs (total)</small></div>
    <div class="col-md-3 col-sm-6"><h3><?= $globalStats['en_attente'] ?></h3><small class="text-muted">En attente</small></div>
    <div class="col-md-3 col-sm-6"><h3><?= $globalStats['taux_reussite'] ?>%</h3><small class="text-muted">Taux de réussite</small></div>
</div>

<div class="row">
    <div class="col-sm-8">
        <div class="card table-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h4 class="mb-0">SMS par campagne</h4>
                <button class="btn btn-sm btn-outline-primary" onclick="exportTableToCsv('rapportsTable', 'rapport_campagnes.csv')">⬇ Exporter CSV</button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="rapportsTable" class="table table-striped table-bordered">
                        <thead>
                            <tr>
                                <th>Campagne</th>
                                <th>Type</th>
                                <th>Statut</th>
                                <th>Destinataires</th>
                                <th>Réussis</th>
                                <th>Échecs</th>
                                <th>Taux</th>
                                <th>Créée le</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($campaigns)): ?>
                            <tr><td colspan="8" class="text-center text-muted">Aucune campagne n'a encore été créée.</td></tr>
                            <?php else: foreach ($campaigns as $c):
                                $traites = (int) $c['nombre_envoyes'] + (int) $c['nombre_echecs'];
                                $taux = $traites > 0 ? round(($c['nombre_envoyes'] / $traites) * 100, 1) : 0;
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($c['nom']) ?></td>
                                <td><?= htmlspecialchars($c['type']) ?></td>
                                <td><?= htmlspecialchars($c['statut']) ?></td>
                                <td><?= (int) $c['total_destinataires'] ?></td>
                                <td class="text-success"><?= (int) $c['nombre_envoyes'] ?></td>
                                <td class="text-danger"><?= (int) $c['nombre_echecs'] ?></td>
                                <td><?= $taux ?>%</td>
                                <td><?= htmlspecialchars($c['date_creation']) ?></td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card table-card">
            <div class="card-header">
                <h4>Top erreurs</h4>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr><th>Code erreur</th><th>Occurrences</th></tr></thead>
                        <tbody>
                            <?php if (empty($topErrors)): ?>
                            <tr><td colspan="2" class="text-center text-muted">Aucune erreur enregistrée.</td></tr>
                            <?php else: foreach ($topErrors as $e): ?>
                            <tr><td><?= htmlspecialchars($e['error_code']) ?></td><td><?= (int) $e['total'] ?></td></tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function exportTableToCsv(tableId, filename) {
    const rows = document.querySelectorAll('#' + tableId + ' tr');
    let csv = [];
    rows.forEach(row => {
        const cells = row.querySelectorAll('th, td');
        const line = Array.from(cells).map(c => '"' + c.textContent.trim().replace(/"/g, '""') + '"');
        csv.push(line.join(','));
    });
    const blob = new Blob([csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = filename;
    link.click();
}
</script>
