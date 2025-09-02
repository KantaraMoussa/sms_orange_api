<div class="row mt-2 mb-3">
    <div class="col-md-6 col-sm-6">
        <button class="btn btn-large btn-primary" data-bs-toggle="modal" data-bs-target="#sendGroupModal">
            <span class="fa fa-users"></span>&nbsp; Envoyer un Méssage à un groupe
        </button>
    </div>
    <div class="col-md-6 col-sm-6" align="right">
        <button class="btn btn-large btn-warning" data-bs-toggle="modal" data-bs-target="#importModal">
            <span class="fa fa-user"></span>&nbsp; Envoyer un Méssage
        </button>
    </div>
</div>
<div class="row">
    <div class="col-sm-12">
        <div class="card table-card">
            <div class="card-header">
                <h4>Listes des Messages </h4>
            </div>
            <div class="card-body p-3">
                <div class="table-responsive">
                    <table id="groupesTable" class="table table-striped table-bordered">
                        <thead>
                            <tr>
                                <th>Libelle du groupe</th>
                                <th>Date de création</th>
                                <th>Nombre de conctact</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>PNG002156</td>
                                <td>Jacqueline Howell</td>
                                <td>03-01-2017</td>
                                <td><span class="badge bg-warning">Pending</span></td>
                                <td>
                                    <a href="?page=Groupes&details=1" class="btn btn-primary"> <i class="fa fa-eye"></i> Voir plus </a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <!-- Recent Orders end -->
</div>

<div class="modal fade" id="importModal" tabindex="-1" aria-labelledby="importModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-md">
        <div class="modal-content shadow-md">
            <div class="modal-header ">
                <h5 class="modal-title" id="importModalLabel">Envoyer un message</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">
                <!-- Formulaire d’import CSV -->
                <form action="../server/app.php" method="post" enctype="multipart/form-data">
                    <div class="form-group mb-3">
                        <input type="text" class="form-control" name="number" id="number" required value="+224"  required>
                        <div class="form-text">Numéro ou expéditeur validé chez Orange</div>
                    </div>
                    <div class="form-group mb-3">
                        <textarea class="form-control border-0 bg-transparent" name="message" id="message" rows="4" placeholder="Tapez votre message...">📢 ALERT UGLCS-SCOLARITE</textarea>
                        <div class="form-text">Votre Message ici</div>
                    </div>
                    <div class="d-grid mt-4">
                        <button type="submit" class="btn btn-primary" name="single-sender">Envoyer</button>
                    </div>
                </form>

            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="sendGroupModal" tabindex="-1" aria-labelledby="sendGroupModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-md">
        <div class="modal-content shadow-md">
            <div class="modal-header ">
                <h5 class="modal-title" id="sendGroupModalLabel">Envoyer un message au groupe</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">
                <!-- Formulaire d’import CSV -->
                <form action="../server/app.php" method="post" enctype="multipart/form-data">
                    <div class="mb-4">
                        <label for="group_id" class="form-label">Nom du Groupe</label>
                        <select name="group_id" id="group_id" class="form-control" required="required">
                            <option value="<?= $group['id'] ?>"><?= $group['name'] ?></option>
                        </select>
                    </div>
                    <div class="form-group mb-4">
                        <label for="message" class="form-label">Votre message ici...</label>
                        <textarea class="form-control border-0 bg-transparent" name="message" id="message" rows="4">📢 ALERT UGLCS-SCOLARITE</textarea>
                        <div class="form-text">Votre Message ici</div>
                    </div>
                    <div class="d-grid mt-4">
                        <button type="submit" class="btn btn-primary" name="single-sender">Envoyer</button>
                    </div>
                </form>

            </div>
        </div>
    </div>
</div>