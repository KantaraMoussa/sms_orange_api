 <div class="row">
     <div align="right" class="mb-3"><button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addGroupeModal"> <i class="fa fa-check-circle"></i>&nbsp;Créer une campagne</button></div>

     <div class="col-sm-12">
         <div class="card table-card">
             <div class="card-header">
                 <h4>Listes des Campagnes </h4>
             </div>
             <div class="card-body p-2">
                 <div class="table-responsive">
                     <table id="groupesTable" class="table table-striped table-bordered">
                         <thead>
                             <tr>
                                 <th>Libelle </th>
                                 <th>Description</th>
                                 <th>Date Début</th>
                                  <th>Date Fin</th>
                                 <th>Status</th>
                                 <th>Action</th>
                             </tr>
                         </thead>
                         <tbody>
                             <?php if (!empty(getCampagne())): ?>
                                 <?php foreach (getCampagne() as $campagne): ?>
                                     <tr>
                                         <td><?= htmlspecialchars($campagne['nom']) ?></td>
                                         <td><?= htmlspecialchars($campagne['description']) ?></td>
                                         <td><?= htmlspecialchars($campagne['date_debut']) ?></td>
                                         <td><?= htmlspecialchars($campagne['date_fin']) ?></td>
                                         <td class="text-danger fw-bolder"><?= htmlspecialchars($campagne['statut']) ?></td>
                                         <td><a href="?page=campgagne&details=<?= $campagne['id'] ?>" class="btn btn-primary"> <i class="fa fa-eye"></i> Voir plus </a></td>
                                     </tr>
                                 <?php endforeach; ?>                                 
                             <?php endif; ?>
                         </tbody>
                     </table>
                 </div>
             </div>
         </div>
     </div>
     <div class="col-sm-4">
         <div class="card card-default">
             <div class="card-header">
                 <h3 class="card-title">Créer une Campagne</h3>
             </div>
             <div class="card-body">
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
     <!-- Recent Orders end -->

 </div>



 <div class="modal fade" id="addGroupeModal" tabindex="-1" aria-labelledby="addGroupeModalLabel" aria-hidden="true">
     <div class="modal-dialog modal-lg">
         <div class="modal-content shadow-lg">
             <div class="modal-header ">
                 <h3 class="card-title">Créer une campagne</h3>
             </div>
             <div class="modal-body">

                 <!-- Formulaire d’import CSV -->
                 <form action="../server/app.php" method="post">
                     <div class="row">
                         <div class="form-group mb-3" class="col-md-12">
                             <label for="campagne_name"> Libelle du Campagne * </label>
                             <input type="text" name="campagne_name" id="campagne_name" class="form-control" placeholder="Titre du campagne" required />
                         </div>
                         <div class="form-group mb-3" class="col-md-12">
                             <label for="campagne_description">Description du Campagne</label>
                             <textarea name="campagne_description" id="campagne_description" placeholder="Faire une description" required class="form-control" cols="30" rows="10"></textarea>
                         </div>
                         <div class="d-grid mt-4">
                             <button type="submit" class="btn btn-primary" name="create_campagne"> <i class="fa fa-plus"></i>&nbsp; Créer</button>
                         </div>
                     </div>
                 </form>

             </div>
         </div>
     </div>
 </div>