        <div class="row">

          <div class="col-sm-8">
            <div class="card table-card">
              <div class="card-header">
                <h4>Listes des campagnes </h4>
              </div>
              <div class="card-body p-0">
                <div class="table-responsive">
                  <table class="table">
                    <tr>
                      <th>Image</th>
                      <th>Product Code</th>
                      <th>Customer</th>
                      <th>Purchased On</th>
                      <th>Status</th>
                      <th>Transaction ID</th>
                    </tr>
                    <tr>
                      <td><img src="../assets/images/widget/p4.jpg" alt="prod img" class="img-fluid" /></td>
                      <td>PNG002156</td>
                      <td>Jacqueline Howell</td>
                      <td>03-01-2017</td>
                      <td><span class="badge bg-warning">Pending</span></td>
                      <td>#7234454</td>
                    </tr>
                  </table>
                </div>
              </div>
            </div>
          </div>
          <div class="col-sm-4">
            <div class="card card-default">
              <div class="card-header">
                <h3 class="card-title">Envoyer un Méssage</h3>
              </div>
              <div class="card-body">
                <div class="row">
                  <form action="../server/app.php" method="post" enctype="multipart/form-data">
                    <div class="form-group mb-3">
                      <input type="text" class="form-control" name="number" id="number" required value="+224" required>
                      <div class="form-text">Numéro ou expéditeur validé chez Orange</div>
                    </div>
                    <div class="form-group mb-3">
                      <textarea class="form-control border-0 bg-transparent" name="message" id="message" rows="4" placeholder="Tapez votre message..."> 📢 ALERT UGLCS-SCOLARITE
                         La Scolarité de l’UGLC-SC vous informe que la biométrie commence le 19/11/2025 et prend fin le 27/11/2025. si vous n’êtes pas inscrit, vous ne pouvez pas être biométrisé et vous perdrez votre statut d’étudiant !!! Merci
                      </textarea>
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
        </div>
        

        <!-- Recent Orders end -->