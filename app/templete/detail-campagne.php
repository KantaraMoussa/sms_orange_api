<?php (!empty($_GET['details'])) ? $campagne=detailCampagne($_GET['details']) : $campagne=[];

?>
<hr>
<div class="row">
    <div class="col-md-6 col-sm-6">
        <button class="btn btn-large btn-primary" data-bs-toggle="modal" data-bs-target="#addContactModal<?= $campagne['id'] ?>">
            <span class="fa fa-users"></span>&nbsp; Envoie sms a un groupe
        </button>
    </div>
    <div class="col-md-6 col-sm-6" align="right">
        <button class="btn btn-large btn-primary" data-bs-toggle="modal" data-bs-target="#importModal<?= $campagne['id'] ?>">
            <span class="fa fa-upload"></span>&nbsp; Importer les Messages
        </button>
    </div>

</div>
<hr>
<!-- Recent Orders start -->
<div class="row">

    <div class="col-sm-12">
        <div class="card table-card">
            <div class="card-header">
                <h4>Listes des Messages de la campgane sms | <span class="text-primary"><?php echo($campagne['libelle']) ?></span> </h4>
            </div>
            <div class="card-body p-2">
                <div class="table-responsive">
                    <table id="groupesTable" class="table table-striped table-bordered">
                        <thead>
                            <tr>
                                <th>Destinataire</th>
                                <th>Message</th>
                                <th>Statut</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php  foreach ($campagne["messages"] as $camp) { ?>
                            <tr>
                                <td>  <?php echo($camp['destinataire']) ?></td>
                                <td><?php echo($camp['contenu']) ?></td>
                                <td><?php echo($camp['statut']) ?></td>
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



<div class="modal fade" id="importModal<?= $campagne['id'] ?>" tabindex="-1" aria-labelledby="importModalLabel<?= $campagne['id'] ?>" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content shadow-lg">
            <div class="modal-header ">
                <h5 class="modal-title" id="importModalLabel">📥 Importer les messages de la campagne | <span class="text-danger"><?= htmlspecialchars($campagne['libelle']) ?></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">

                <!-- Formulaire d’import CSV -->
                <form action="../server/app.php" method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label for="campagne" class="form-label">Nom du Groupe</label>
                        <select name="campagne" id="campagne" class="form-control" required="required">
                            <option value="<?= $campagne['id'] ?>"><?= $campagne['libelle'] ?></option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="csvFile" class="form-label">Fichier CSV</label>
                        <input class="form-control" type="file" id="csvFile" name="csvFile" accept=".csv" required>
                        <small class="text-muted">⚠️ Format attendu : <b>telephone,message,matricule,notes</b></small>
                    </div>

                    <div class="d-flex justify-content-end">
                        <button type="button" class="btn btn-danger me-2" data-bs-dismiss="modal"> Annuler</button>
                        <button type="submit" class="btn btn-success" name="importMessage_csv">📤 Importer</button>
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