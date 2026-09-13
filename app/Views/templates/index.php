<div class="row mt-3">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h4 class="mb-0">📝 Modèles SMS</h4>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#templateModal" onclick="openCreateTemplate()">+ Nouveau modèle</button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped mb-0">
                        <thead><tr><th>Nom</th><th>Catégorie</th><th>Aperçu</th><th>Statut</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php if (empty($templates)): ?>
                            <tr><td colspan="5" class="text-center text-muted p-3">Aucun modèle. Créez-en un pour le réutiliser dans vos campagnes.</td></tr>
                        <?php else: foreach ($templates as $t): ?>
                            <tr class="<?= $t['archive'] === 't' || $t['archive'] === true ? 'text-muted' : '' ?>">
                                <td><?= htmlspecialchars($t['nom']) ?></td>
                                <td><span class="badge bg-light text-dark"><?= htmlspecialchars($categoryLabels[$t['categorie']] ?? $t['categorie']) ?></span></td>
                                <td><small><?= htmlspecialchars(mb_substr($t['contenu'], 0, 60)) ?><?= mb_strlen($t['contenu']) > 60 ? '…' : '' ?></small></td>
                                <td><?= ($t['archive'] === 't' || $t['archive'] === true) ? '<span class="badge bg-secondary">Archivé</span>' : '<span class="badge bg-success">Actif</span>' ?></td>
                                <td class="text-nowrap">
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick='openEditTemplate(<?= htmlspecialchars(json_encode($t), ENT_QUOTES) ?>)'>Modifier</button>
                                    <form action="<?= route('templates.duplicate') ?>" method="post" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="template_id" value="<?= (int) $t['id'] ?>">
                                        <button type="submit" name="duplicate_template" class="btn btn-sm btn-outline-secondary">Dupliquer</button>
                                    </form>
                                    <?php if ($t['archive'] === 't' || $t['archive'] === true): ?>
                                        <form action="<?= route('templates.unarchive') ?>" method="post" class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="template_id" value="<?= (int) $t['id'] ?>">
                                            <button type="submit" name="unarchive_template" class="btn btn-sm btn-outline-success">Réactiver</button>
                                        </form>
                                    <?php else: ?>
                                        <form action="<?= route('templates.archive') ?>" method="post" class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="template_id" value="<?= (int) $t['id'] ?>">
                                            <button type="submit" name="archive_template" class="btn btn-sm btn-outline-danger" onclick="return confirm('Archiver ce modèle ?');">Archiver</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="templateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content shadow-lg">
            <div class="modal-header">
                <h5 class="modal-title" id="templateModalTitle">Nouveau modèle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">
                <form action="<?= route('templates.create') ?>" method="post" id="templateForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="template_id" id="template_id">
                    <div class="mb-3">
                        <label for="template_nom" class="form-label">Nom du modèle</label>
                        <input type="text" class="form-control" id="template_nom" name="template_nom" required>
                    </div>
                    <div class="mb-3">
                        <label for="template_categorie" class="form-label">Catégorie</label>
                        <select class="form-select" id="template_categorie" name="template_categorie">
                            <?php foreach ($categoryLabels as $key => $label): ?>
                                <option value="<?= $key ?>"><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="template_contenu" class="form-label">Contenu (variables {{...}} supportées)</label>
                        <textarea class="form-control" id="template_contenu" name="template_contenu" rows="6" required></textarea>
                    </div>
                    <div class="d-flex justify-content-end">
                        <button type="button" class="btn btn-outline-secondary me-2" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" name="create_template" id="templateSubmitBtn" class="btn btn-success">Créer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
const TEMPLATE_CREATE_URL = <?= json_encode(route('templates.create')) ?>;
const TEMPLATE_UPDATE_URL = <?= json_encode(route('templates.update')) ?>;

function openCreateTemplate() {
    document.getElementById('templateModalTitle').textContent = 'Nouveau modèle';
    document.getElementById('template_id').value = '';
    document.getElementById('template_nom').value = '';
    document.getElementById('template_categorie').value = 'notification';
    document.getElementById('template_contenu').value = '';
    document.getElementById('templateForm').action = TEMPLATE_CREATE_URL;
    const btn = document.getElementById('templateSubmitBtn');
    btn.name = 'create_template';
    btn.textContent = 'Créer';
}

function openEditTemplate(t) {
    document.getElementById('templateModalTitle').textContent = 'Modifier le modèle';
    document.getElementById('template_id').value = t.id;
    document.getElementById('template_nom').value = t.nom;
    document.getElementById('template_categorie').value = t.categorie;
    document.getElementById('template_contenu').value = t.contenu;
    document.getElementById('templateForm').action = TEMPLATE_UPDATE_URL;
    const btn = document.getElementById('templateSubmitBtn');
    btn.name = 'update_template';
    btn.textContent = 'Enregistrer';
    new bootstrap.Modal(document.getElementById('templateModal')).show();
}
</script>
