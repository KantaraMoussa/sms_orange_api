<?php ?>
<hr>

<!-- Recent Orders start -->
<div class="row">

    <div class="col-sm-12">
        <div class="card table-card">
            <div class="card-header">
                <h4>Listes des notes </h4>
            </div>
            <div class="card-body p-2">
                <div class="table-responsive">
                    <table id="groupesTable" class="table table-striped table-bordered">
                        <thead>
                            <tr>
                                <th>Matricule</th>
                                <th>Contact</th>
                                <th>Notes</th>
                                 <th>Niveaux</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (getMessageSenderMarksheet() as $marksheet) { ?>
                                <tr>
                                    <td> <?php echo ($marksheet['matricule']) ?></td>
                                    <td><?php echo ($marksheet['destinataire']) ?></td>
                                    <td><?php echo ($marksheet['messages']) ?></td>
                                    <td><?php echo ($marksheet['niveaux']) ?></td>
                                    <td><a href="?page=notes&sender=<?= $marksheet['matricule'] ?>" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#senderMarksheetModal<?= $marksheet['matricule'] ?>"> <i class="fa fa-envelope-square" aria-hidden="true"></i></a></td>
                                    <div class="modal fade" id="senderMarksheetModal<?= $marksheet['matricule'] ?>" tabindex="-1" aria-labelledby="senderMarksheetModalLabel<?= $marksheet['matricule'] ?>" aria-hidden="true">
                                        <div class="modal-dialog modal-md">
                                            <div class="modal-content shadow-lg">
                                                <div class="modal-header ">
                                                    <h5 class="modal-title" id="senderMarksheetModalLabel">Envoyé le message <span class="text-danger"><?= htmlspecialchars($marksheet['matricule']) ?></span></h5>
                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
                                                </div>
                                                <div class="modal-body">

                                                    <!-- Formulaire d’import CSV -->
                                                    <form action="../server/app.php" method="POST" enctype="multipart/form-data">
                                                        <div class="mb-3">
                                                            <label for="number" class="form-label">Niveaux</label>
                                                     
                                                                <input value="<?= $marksheet['niveaux'] ?>" name="number" type="tel" class="form-control" required> 
                                                     
                                                        </div>
                                                        <div class="mb-3">
                                                            <label for="number" class="form-label">Numéro de Téléphone</label>
                                                     
                                                                <input value="<?= $marksheet['destinataire'] ?>" name="number" type="tel" class="form-control" required> 
                                                     
                                                        </div>
                                                        <div class="mb-3">
                                                            <label for="message" class="form-label">Message | <?= strlen($marksheet['messages']) ?></label>
                                                            <textarea class="form-control" name="message" id="message" cols="30" rows="5"><?= $marksheet['messages'] ?> </textarea>
                                                            <p>Nombre Sms :    <?= ceil(strlen($marksheet['messages'])/153) ?>/5</p>
                                                        </div>

                                                        <div class="d-flex justify-content-end">

                                                            <button type="submit" class="btn btn-success" name="single-sender">📤 Envoyé</button>
                                                        </div>
                                                    </form>

                                                </div>
                                            </div>
                                        </div>
                                    </div>

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