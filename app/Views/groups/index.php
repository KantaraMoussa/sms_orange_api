<div class="row">
    <div align="right" class="mb-3">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addGroupModal"><i class="ph ph-plus"></i>&nbsp;Créer un groupe</button>
    </div>

    <div class="col-sm-12">
        <div class="card table-card">
            <div class="card-header"><h4 class="mb-0">Groupes de contacts</h4></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="groupesTable" class="table table-striped table-bordered">
                        <thead><tr><th>Nom</th><th>Description</th><th>Contacts</th><th>Créé le</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php if (empty($groups)): ?>
                            <tr><td colspan="5" class="text-center text-muted">Aucun groupe. Créez-en un pour organiser vos contacts.</td></tr>
                        <?php else: foreach ($groups as $g): ?>
                            <tr>
                                <td><?= htmlspecialchars($g['nom']) ?></td>
                                <td><?= htmlspecialchars($g['description'] ?? '') ?></td>
                                <td><span class="badge bg-light text-dark"><?= (int) $g['nombre_contacts'] ?></span></td>
                                <td><?= htmlspecialchars($g['created_at']) ?></td>
                                <td>
                                    <a href="<?= route('groups.show', ['id' => (int) $g['id']]) ?>" class="btn btn-sm btn-primary"><i class="ph ph-eye"></i> Voir</a>
                                    <form action="<?= route('groups.delete') ?>" method="post" class="d-inline" onsubmit="return confirm('Supprimer ce groupe ? Les contacts eux-mêmes ne seront pas supprimés.');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="groupe_id" value="<?= (int) $g['id'] ?>">
                                        <button type="submit" name="delete_group" class="btn btn-sm btn-outline-danger"><i class="ph ph-trash"></i></button>
                                    </form>
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

<div class="modal fade" id="addGroupModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content shadow-lg">
            <div class="modal-header"><h5 class="modal-title">Créer un groupe</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button></div>
            <div class="modal-body">
                <form action="<?= route('groups.create') ?>" method="post">
                    <?= csrf_field() ?>
                    <div class="mb-3"><label for="groupe_nom" class="form-label">Nom du groupe *</label><input type="text" class="form-control" id="groupe_nom" name="groupe_nom" required placeholder="Ex : Clients VIP"></div>
                    <div class="mb-3"><label for="groupe_description" class="form-label">Description</label><textarea class="form-control" id="groupe_description" name="groupe_description" rows="3"></textarea></div>
                    <div class="d-flex justify-content-end">
                        <button type="button" class="btn btn-outline-secondary me-2" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" name="create_group" class="btn btn-primary">Créer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
