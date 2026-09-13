<div class="row">
    <div align="right" class="mb-3">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addContactModal"><i class="ph ph-plus"></i>&nbsp;Ajouter un contact</button>
        <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#importContactsModal"><i class="ph ph-upload-simple"></i>&nbsp;Importer un fichier</button>
    </div>

    <div class="col-sm-12">
        <div class="card table-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h4 class="mb-0">Contacts (<?= contacts()->countContacts() ?>)</h4>
                <form method="get" class="d-flex gap-2">
                    <input type="hidden" name="route" value="contacts.index">
                    <label for="search" class="visually-hidden">Rechercher un contact</label>
                    <input type="text" class="form-control form-control-sm" id="search" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Rechercher (nom, prénom, téléphone)">
                    <button type="submit" class="btn btn-sm btn-outline-secondary">Rechercher</button>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="groupesTable" class="table table-striped table-bordered">
                        <thead>
                            <tr><th>Nom</th><th>Prénom</th><th>Téléphone</th><th>Email</th><th>Ajouté le</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($allContacts)): ?>
                                <tr><td colspan="6" class="text-center text-muted">Aucun contact. Ajoutez-en un ou importez un fichier.</td></tr>
                            <?php else: foreach ($allContacts as $c): ?>
                                <tr>
                                    <td><?= htmlspecialchars($c['nom'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($c['prenom'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($c['telephone']) ?></td>
                                    <td><?= htmlspecialchars($c['email'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($c['created_at']) ?></td>
                                    <td>
                                        <form action="<?= route('contacts.delete') ?>" method="post" class="d-inline" onsubmit="return confirm('Supprimer ce contact ?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="contact_id" value="<?= (int) $c['id'] ?>">
                                            <button type="submit" name="delete_contact" class="btn btn-sm btn-outline-danger"><i class="ph ph-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if (!empty($importHistory)): ?>
        <div class="card mt-3">
            <div class="card-header"><h5 class="mb-0">Derniers imports</h5></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Date</th><th>Fichier</th><th>Analysées</th><th>Valides</th><th>Invalides</th><th>Doublons</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($importHistory as $imp): ?>
                            <tr>
                                <td><?= htmlspecialchars($imp['created_at']) ?></td>
                                <td><?= htmlspecialchars($imp['filename'] ?? '') ?></td>
                                <td><?= (int) $imp['total_lignes'] ?></td>
                                <td class="text-success"><?= (int) $imp['valides'] ?></td>
                                <td class="text-danger"><?= (int) $imp['invalides'] ?></td>
                                <td class="text-warning"><?= (int) $imp['doublons'] ?></td>
                                <td>
                                    <?php if ((int) $imp['invalides'] > 0): ?>
                                    <a href="<?= route('contacts.exportErrors', ['import_id' => (int) $imp['id']]) ?>" class="btn btn-sm btn-outline-secondary">Télécharger les erreurs</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="addContactModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content shadow-lg">
            <div class="modal-header"><h5 class="modal-title">Ajouter un contact</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button></div>
            <div class="modal-body">
                <form action="<?= route('contacts.create') ?>" method="post">
                    <?= csrf_field() ?>
                    <div class="mb-3"><label for="contact_nom" class="form-label">Nom</label><input type="text" class="form-control" id="contact_nom" name="contact_nom"></div>
                    <div class="mb-3"><label for="contact_prenom" class="form-label">Prénom</label><input type="text" class="form-control" id="contact_prenom" name="contact_prenom"></div>
                    <div class="mb-3"><label for="contact_telephone" class="form-label">Téléphone *</label><input type="text" class="form-control" id="contact_telephone" name="contact_telephone" placeholder="+224622xxxxxx" required></div>
                    <div class="mb-3"><label for="contact_email" class="form-label">Email</label><input type="email" class="form-control" id="contact_email" name="contact_email"></div>
                    <div class="d-flex justify-content-end">
                        <button type="button" class="btn btn-outline-secondary me-2" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" name="create_contact" class="btn btn-primary">Ajouter</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="importContactsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content shadow-lg">
            <div class="modal-header"><h5 class="modal-title">Importer des contacts</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button></div>
            <div class="modal-body">
                <form action="<?= route('contacts.import') ?>" method="post" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label for="contactsFile" class="form-label">Fichier Excel (.xlsx) ou CSV</label>
                        <input class="form-control" type="file" id="contactsFile" name="contactsFile" accept=".xlsx,.xls,.csv" required>
                        <small class="text-muted">Colonnes reconnues (ordre libre) : <b>telephone</b> (obligatoire), nom, prenom, email.</small>
                    </div>
                    <div class="mb-3">
                        <label for="groupe_id" class="form-label">Ajouter directement à un groupe (optionnel)</label>
                        <select class="form-select" id="groupe_id" name="groupe_id">
                            <option value="">— Aucun —</option>
                            <?php foreach ($groups as $g): ?>
                                <option value="<?= (int) $g['id'] ?>"><?= htmlspecialchars($g['nom']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="d-flex justify-content-end">
                        <button type="button" class="btn btn-outline-secondary me-2" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" name="import_contacts" class="btn btn-success">📤 Importer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
