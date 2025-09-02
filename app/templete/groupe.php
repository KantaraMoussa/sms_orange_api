 <div class="row">
   <div align="right" class="mb-3"><button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addGroupeModal"> <i class="fa fa-check-circle"></i>&nbsp;Créer un groupe</button></div>
   <div class="col-sm-12">
     <div class="card table-card">
       <div class="card-header">
         <h4>Listes des Groupes </h4>
       </div>
       <div class="card-body p-2">
         <div class="table-responsive">
           <table id="groupesTable" class="table table-striped table-bordered">
             <thead>
               <tr>
                 <th>Libelle du groupe</th>
                 <th>Date de création</th>
                 <th>Nombre de conctact</th>
                 <th>Action</th>
               </tr>
             </thead>
             <tbody>
               <?php ListGroupe() ?>
             </tbody>
           </table>
         </div>
       </div>
     </div>
   </div>
   <!-- Recent Orders end -->
 </div>

 <div class="modal fade" id="addGroupeModal" tabindex="-1" aria-labelledby="addGroupeModalLabel" aria-hidden="true">
   <div class="modal-dialog modal-lg">
     <div class="modal-content shadow-lg">
       <div class="modal-header ">
         <h3 class="card-title">Créer un groupe</h3>
       </div>
       <div class="modal-body">

         <!-- Formulaire d’import CSV -->
         <form action="../server/app.php" method="post">
           <div class="row">
             <div class="form-group mb-3" class="col-md-12">
               <label for="group_name"> Libelle du Groupe * </label>
               <input type="text" name="group_name" id="group_name" class="form-control" placeholder="Libelle du groupe" required />
             </div>
             <div class="form-group mb-3" class="col-md-12">
               <label for="group_description">Description du groupe</label>
               <textarea name="group_description" id="group_description" placeholder="faire un description ici....." required class="form-control" cols="30" rows="10"></textarea>
             </div>
             <div class="d-grid mt-4">
               <button type="submit" class="btn btn-primary" name="create_group">Créer un groupe</button>
             </div>
           </div>
         </form>

       </div>
     </div>
   </div>
 </div>