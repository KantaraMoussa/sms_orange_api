<?php if (!$group): ?>
    <div class="alert alert-danger mt-3">Groupe introuvable.</div>
<?php return; endif; ?>
<div class="row mt-3">
    <div class="col-12 mb-3">
        <a href="<?= route('groups.index') ?>" class="btn btn-sm btn-outline-secondary">« Retour aux groupes</a>
    </div>
    <div class="col-lg-7">
        <div class="card table-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h4 class="mb-0"><?= htmlspecialchars($group['nom']) ?> <span class="badge bg-light text-dark"><?= count($memberContacts) ?> contact(s)</span></h4>
                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addToGroupModal">+ Ajouter</button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="groupesTable" class="table table-striped table-bordered">
                        <thead><tr><th>Nom</th><th>Prénom</th><th>Téléphone</th><th>Email</th><th></th></tr></thead>
                        <tbody>
                        <?php if (empty($memberContacts)): ?>
                            <tr><td colspan="5" class="text-center text-muted">Aucun contact dans ce groupe pour le moment.</td></tr>
                        <?php else: foreach ($memberContacts as $c): ?>
                            <tr>
                                <td><?= htmlspecialchars($c['nom'] ?? '') ?></td>
                                <td><?= htmlspecialchars($c['prenom'] ?? '') ?></td>
                                <td><?= htmlspecialchars($c['telephone']) ?></td>
                                <td><?= htmlspecialchars($c['email'] ?? '') ?></td>
                                <td>
                                    <form action="<?= route('groups.removeContact') ?>" method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="groupe_id" value="<?= $groupeId ?>">
                                        <input type="hidden" name="contact_id" value="<?= (int) $c['id'] ?>">
                                        <button type="submit" name="remove_contact_from_group" class="btn btn-sm btn-outline-danger">Retirer</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header"><h5 class="mb-0">Importer un fichier directement dans ce groupe</h5></div>
            <div class="card-body">
                <form action="<?= route('contacts.import') ?>" method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="groupe_id" value="<?= $groupeId ?>">
                    <div class="col-md-8">
                        <label for="contactsFile" class="form-label">Fichier Excel (.xlsx) ou CSV</label>
                        <input class="form-control" type="file" id="contactsFile" name="contactsFile" accept=".xlsx,.xls,.csv" required>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" name="import_contacts" class="btn btn-success w-100">📤 Importer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card card-default">
            <div class="card-header"><h5 class="card-title mb-0">✉️ Envoyer un message au groupe</h5></div>
            <div class="card-body">
                <form action="<?= route('groups.sendMessage') ?>" method="post" onsubmit="return confirm('Créer une campagne pour les <?= count($memberContacts) ?> contact(s) de ce groupe ?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="groupe_id" value="<?= $groupeId ?>">
                    <div class="mb-3">
                        <label for="group_message" class="form-label">Message</label>
                        <textarea class="form-control" id="group_message" name="group_message" rows="5" placeholder="Tapez votre message..." required></textarea>
                    </div>
                    <div class="d-grid">
                        <button type="submit" name="send_to_group" class="btn btn-primary" <?= empty($memberContacts) ? 'disabled' : '' ?>>Créer la campagne</button>
                    </div>
                    <?php if (empty($memberContacts)): ?>
                        <small class="text-muted d-block mt-2">Ajoutez au moins un contact avant d'envoyer un message.</small>
                    <?php else: ?>
                        <small class="text-muted d-block mt-2">Crée une campagne en brouillon ; vous la lancerez depuis l'écran Campagnes.</small>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="addToGroupModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content shadow-lg">
            <div class="modal-header"><h5 class="modal-title">Ajouter un contact existant</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button></div>
            <div class="modal-body">
                <?php if (empty($availableToAdd)): ?>
                    <p class="text-muted">Tous vos contacts sont déjà dans ce groupe, ou vous n'avez encore aucun contact. <a href="<?= route('contacts.index') ?>">Ajouter des contacts</a>.</p>
                <?php else: ?>
                <form action="<?= route('groups.addContact') ?>" method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="groupe_id" value="<?= $groupeId ?>">
                    <div class="mb-3">
                        <label for="contact_id" class="form-label">Contact</label>
                        <select class="form-select" id="contact_id" name="contact_id" required>
                            <?php foreach ($availableToAdd as $c): ?>
                                <option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars(trim(($c['nom'] ?? '') . ' ' . ($c['prenom'] ?? ''))) ?> — <?= htmlspecialchars($c['telephone']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="d-flex justify-content-end">
                        <button type="button" class="btn btn-outline-secondary me-2" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" name="add_contact_to_group" class="btn btn-primary">Ajouter</button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
