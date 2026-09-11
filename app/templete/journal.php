<?php
$logs = activityLog()->recent(200);
$actionLabels = [
    'connexion' => 'Connexion',
    'deconnexion' => 'Déconnexion',
    'creation_campagne' => 'Création de campagne',
    'creation_campagne_resultats' => 'Création de campagne (résultats)',
    'lancement_campagne' => "Lancement d'envoi",
    'pause_campagne' => 'Mise en pause',
    'reprise_campagne' => 'Reprise',
    'annulation_campagne' => 'Annulation',
    'retry_campagne' => 'Réessai des échecs',
    'import_resultats' => 'Import de résultats',
];
?>
<hr>
<div class="row">
    <div class="col-12">
        <div class="card table-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h4 class="mb-0">🕒 Journal d'activité</h4>
                <button class="btn btn-sm btn-outline-primary" onclick="exportTableToCsv('journalTable', 'journal_activite.csv')">⬇ Exporter CSV</button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="journalTable" class="table table-striped table-bordered">
                        <thead>
                            <tr>
                                <th>Date/heure</th>
                                <th>Utilisateur</th>
                                <th>Action</th>
                                <th>Campagne</th>
                                <th>Détails</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs)): ?>
                            <tr><td colspan="5" class="text-center text-muted">Aucune activité enregistrée pour le moment.</td></tr>
                            <?php else: foreach ($logs as $log): ?>
                            <tr>
                                <td><?= htmlspecialchars($log['created_at']) ?></td>
                                <td><?= htmlspecialchars($log['user_nom'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($actionLabels[$log['action']] ?? $log['action']) ?></td>
                                <td>
                                    <?php if (!empty($log['campagne_id'])): ?>
                                        <a href="?page=campgagne&details=<?= (int) $log['campagne_id'] ?>"><?= htmlspecialchars($log['campagne_nom'] ?? ('#' . $log['campagne_id'])) ?></a>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($log['details'] ?? '') ?></td>
                            </tr>
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
