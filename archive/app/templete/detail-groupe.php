<?php (!empty($_GET['details'])) ? $group=detailGroupe($_GET['details']) : $group=[];

?>
<hr>
<div class="row">
    <div class="col-md-6 col-sm-6">
        <button class="btn btn-large btn-primary" data-bs-toggle="modal" data-bs-target="#addContactModal<?= $group['id'] ?>">
            <span class="fa fa-users"></span>&nbsp; Créer un contact
        </button>
    </div>
    <div class="col-md-6 col-sm-6" align="right">
        <button class="btn btn-large btn-primary" data-bs-toggle="modal" data-bs-target="#importModal<?= $group['id'] ?>">
            <span class="fa fa-upload"></span>&nbsp; Importer un fichier
        </button>
    </div>

</div>
<hr>
<!-- Recent Orders start -->
<div class="row">

    <div class="col-sm-12">
        <div class="card table-card">
            <div class="card-header">
                <h4>Listes des Contacts du groupes | <span class="text-primary"><?php echo($group['libelle']) ?></span> </h4>
            </div>
            <div class="card-body p-2">
                <div class="table-responsive">
                    <table id="groupesTable" class="table table-striped table-bordered">
                        <thead>
                            <tr>
                                <th>Nom</th>
                                <th>Email</th>
                                <th>Téléphone</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php  foreach ($group["contacts"] as $contact) { ?>
                            <tr>
                                <td>  <?php echo($contact['nom']) ?></td>
                                <td><?php echo($contact['email']) ?></td>
                                <td><?php echo($contact['telephone']) ?></td>
                            </tr>
                             <?php  } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Recent Orders end -->
<hr>



<div class="modal fade" id="importModal<?= $group['id'] ?>" tabindex="-1" aria-labelledby="importModalLabel<?= $group['id'] ?>" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content shadow-lg">
            <div class="modal-header ">
                <h5 class="modal-title" id="importModalLabel">📥 Importer des Contacts dans un Groupe | <span class="text-danger"><?= htmlspecialchars($group['libelle']) ?></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">

                <!-- Formulaire d’import CSV -->
                <form action="../server/app.php" method="POST" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label for="group_id" class="form-label">Nom du Groupe</label>
                        <select name="group_id" id="group_id" class="form-control" required="required">
                            <option value="<?= $group['id'] ?>"><?= $group['libelle'] ?></option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="csvFile" class="form-label">Fichier CSV</label>
                        <input class="form-control" type="file" id="csvFile" name="csvFile" accept=".csv" required>
                        <small class="text-muted">⚠️ Format attendu : <b>Nom,Telephone,email</b></small>
                    </div>

                    <div class="d-flex justify-content-end">
                        <button type="button" class="btn btn-danger me-2" data-bs-dismiss="modal"> Annuler</button>
                        <button type="submit" class="btn btn-success" name="import_csv">📤 Importer</button>
                    </div>
                </form>

            </div>
        </div>
    </div>
</div>

<!-- Modal Bootstrap -->
<div class="modal fade" id="addContactModal<?= $group['id'] ?>" tabindex="-1" aria-labelledby="addContactModalLabel<?= $group['id'] ?>" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="../server/app.php">
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h5 class="modal-title" id="addContactModalLabel<?= $group['id'] ?>">Ajouter un contact au groupe | <span class="text-primary"><?= htmlspecialchars($group['libelle']) ?></span> </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="group_id" class="form-label">Nom du Groupe</label>
                        <select name="group_id" id="group_id" class="form-control" required="required">
                            <option value="<?= $group['id'] ?>"><?= $group['libelle'] ?></option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="contact_name_<?= $group['id'] ?>" class="form-label">Nom du contact</label>
                        <input type="text" class="form-control" id="contact_name" name="contact_name" required>
                    </div>
                    <div class="mb-3">
                        <label for="contact_phone_<?= $group['id'] ?>" class="form-label">Téléphone</label>
                        <input type="text" class="form-control" id="contact_phone" name="contact_phone" required>
                    </div>
                    <div class="mb-3">
                        <label for="contact_email_<?= $group['id'] ?>" class="form-label">Email</label>
                        <input type="text" class="form-control" id="contact_email" name="contact_email" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-danger" data-bs-dismiss="modal"> Annuler</button>
                    <button type="submit" name="add_contact" class="btn btn-primary">Ajouter Contact</button>
                </div>
            </form>
        </div>
    </div>
</div>