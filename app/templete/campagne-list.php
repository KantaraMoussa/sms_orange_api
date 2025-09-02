<div class="row mt-2 mb-3">
    <div class="col-md-6 col-sm-6"></div>
    <div class="col-md-6 col-sm-6" align="right">
        <button class="btn btn-large btn-dark" data-bs-toggle="modal" data-bs-target="#importModal">
            <span class="fa fa-upload"></span>&nbsp; Créer une campagne
        </button>
    </div>
</div>
<div class="row">
    <div class="col-sm-12">
        <div class="card table-card">
            <div class="card-header">
                <h4>Listes des Campagnes </h4>
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
                <h5 class="modal-title" id="importModalLabel">Créer une Campagne</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">

                <!-- Formulaire d’import CSV -->
                <form action="../server/send_sms.php" method="post" enctype="multipart/form-data">
                    <div class="form-group mb-3">
                        <input type="text" class="form-control" placeholder="Nom de la campagne" required />
                    </div>
                    <div class="form-group mb-3">
                        <input type="text" class="form-control" name="from_number" id="from_number" required value="+224600000000" placeholder="+224600000000" required>
                        <div class="form-text">Numéro ou expéditeur validé chez Orange</div>
                    </div>
                    <div class="form-group mb-3">
                        <input type="file" class="form-control" name="csv_file" id="csv_file" accept=".csv" required />
                        <div class="form-text">Format CSV : numéro,message (pas d’en-tête)</div>
                    </div>
                    <div class="d-grid mt-4">
                        <button type="submit" class="btn btn-primary">Envoyer maintenant</button>
                    </div>
                </form>

            </div>
        </div>
    </div>
</div>