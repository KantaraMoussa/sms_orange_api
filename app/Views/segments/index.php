<div class="row">
    <div align="right" class="mb-3">
        <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#createSegmentModal">
            <i class="ph ph-funnel"></i>&nbsp;Nouveau segment
        </button>
    </div>

    <div class="col-sm-12">
        <div class="card table-card">
            <div class="card-header">
                <h4>Segments dynamiques</h4>
            </div>
            <div class="card-body p-2">
                <?php if (empty($segmentsList)): ?>
                    <p class="text-muted text-center p-4 mb-0">Aucun segment. Un segment vous permet de cibler une campagne sur un sous-ensemble de contacts (ex. « recherche = Conakry »), recalculé à chaque envoi.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Nom</th>
                                <th>Critères</th>
                                <th>Contacts correspondants</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($segmentsList as $s): ?>
                                <?php
                                $criteriaLabels = [];
                                if (!empty($s['criteria']['search'])) $criteriaLabels[] = 'recherche « ' . $s['criteria']['search'] . ' »';
                                if (!empty($s['criteria']['statut'])) $criteriaLabels[] = 'statut = ' . $s['criteria']['statut'];
                                if (!empty($s['criteria']['groupe_id'])) {
                                    $g = array_values(array_filter($groupesDisponibles, fn($gr) => (int) $gr['id'] === (int) $s['criteria']['groupe_id']));
                                    $criteriaLabels[] = 'groupe = ' . ($g[0]['nom'] ?? '#' . $s['criteria']['groupe_id']);
                                }
                                if (!empty($s['criteria']['created_after'])) $criteriaLabels[] = 'ajouté après ' . $s['criteria']['created_after'];
                                if (!empty($s['criteria']['created_before'])) $criteriaLabels[] = 'ajouté avant ' . $s['criteria']['created_before'];
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($s['nom']) ?></td>
                                    <td><?= $criteriaLabels ? htmlspecialchars(implode(' · ', $criteriaLabels)) : '<span class="text-muted">tous les contacts</span>' ?></td>
                                    <td><span class="badge bg-light-primary"><?= segments()->countContacts($s['id']) ?></span></td>
                                    <td>
                                        <form method="post" action="<?= route('segments.delete') ?>" class="d-inline" onsubmit="return confirm('Supprimer le segment « <?= htmlspecialchars(addslashes($s['nom'])) ?> » ?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="segment_id" value="<?= (int) $s['id'] ?>">
                                            <button type="submit" name="delete_segment" class="btn btn-sm btn-light-danger"><i class="ph ph-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="createSegmentModal" tabindex="-1" aria-labelledby="createSegmentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content shadow-lg">
            <div class="modal-header">
                <h5 class="modal-title">Nouveau segment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <form action="<?= route('segments.create') ?>" method="post" id="createSegmentForm">
                <?= csrf_field() ?>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" for="segmentNom">Nom du segment *</label>
                        <input type="text" id="segmentNom" name="segment_nom" class="form-control" required>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="segmentSearch">Recherche (nom, prénom, téléphone, email)</label>
                            <input type="text" id="segmentSearch" name="criteria_search" class="form-control" oninput="updateSegmentPreview()">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="segmentStatut">Statut</label>
                            <select id="segmentStatut" name="criteria_statut" class="form-select" onchange="updateSegmentPreview()">
                                <option value="">— Tous —</option>
                                <option value="actif">Actif</option>
                                <option value="inactif">Inactif</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="segmentGroupe">Appartient au groupe</label>
                            <select id="segmentGroupe" name="criteria_groupe_id" class="form-select" onchange="updateSegmentPreview()">
                                <option value="">— N'importe lequel —</option>
                                <?php foreach ($groupesDisponibles as $g): ?>
                                    <option value="<?= (int) $g['id'] ?>"><?= htmlspecialchars($g['nom']) ?> (<?= (int) $g['nombre_contacts'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="segmentAfter">Ajouté après le</label>
                            <input type="date" id="segmentAfter" name="criteria_created_after" class="form-control" onchange="updateSegmentPreview()">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="segmentBefore">Ajouté avant le</label>
                            <input type="date" id="segmentBefore" name="criteria_created_before" class="form-control" onchange="updateSegmentPreview()">
                        </div>
                    </div>
                    <div class="alert alert-light border mt-3 mb-0" id="segmentPreviewBox">
                        <span id="segmentPreviewCount"><?= contacts()->countContacts() ?></span> contact(s) correspondent actuellement à ces critères.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-danger" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary" name="create_segment">Créer le segment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let segmentPreviewDebounce;
function updateSegmentPreview() {
    clearTimeout(segmentPreviewDebounce);
    segmentPreviewDebounce = setTimeout(function () {
        const params = new URLSearchParams({
            search: document.getElementById('segmentSearch').value,
            statut: document.getElementById('segmentStatut').value,
            groupe_id: document.getElementById('segmentGroupe').value,
            created_after: document.getElementById('segmentAfter').value,
            created_before: document.getElementById('segmentBefore').value,
        });
        fetch('<?= route('segments.previewCount') ?>&' + params.toString())
            .then(r => r.json())
            .then(data => {
                document.getElementById('segmentPreviewCount').textContent = data.count;
            });
    }, 300);
}
</script>
