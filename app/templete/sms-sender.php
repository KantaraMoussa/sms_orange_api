<div class="row mt-2 mb-3">
    <div class="col-12" align="right">
        <button class="btn btn-large btn-warning" data-bs-toggle="modal" data-bs-target="#importModal">
            <span class="fa fa-user"></span>&nbsp; Envoyer un Méssage
        </button>
    </div>
</div>
<div class="row">
    <div class="col-sm-12">
        <div class="card table-card">
            <div class="card-header">
                <h4>Campagnes récentes</h4>
            </div>
            <div class="card-body p-3">
                <div class="table-responsive">
                    <table id="groupesTable" class="table table-striped table-bordered">
                        <thead>
                            <tr>
                                <th>Nom</th>
                                <th>Statut</th>
                                <th>Date de création</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $campagnesListe = getCampagne(auth()->organizationId()); ?>
                            <?php if (!empty($campagnesListe)): foreach ($campagnesListe as $c): ?>
                            <tr>
                                <td><?= htmlspecialchars($c['nom']) ?></td>
                                <td><?= htmlspecialchars($c['statut']) ?></td>
                                <td><?= htmlspecialchars($c['date_creation']) ?></td>
                                <td><a href="?page=campgagne&details=<?= $c['id'] ?>" class="btn btn-primary"> <i class="fa fa-eye"></i> Voir plus </a></td>
                            </tr>
                            <?php endforeach; endif; ?>
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
                <form action="../server/app.php" method="post" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <div class="form-group mb-3">
                        <label for="number" class="visually-hidden">Numéro de téléphone</label>
                        <input type="text" class="form-control" name="number" id="number" required value="+224">
                        <div class="form-text">Numéro ou expéditeur validé chez Orange</div>
                    </div>
                    <div class="form-group mb-3">
                        <label for="message" class="visually-hidden">Message</label>
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
