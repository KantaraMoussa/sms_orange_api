<?php
$marksheets = getMessageSenderMarksheet();
$niveaux = array_values(array_unique(array_filter(array_column($marksheets, 'niveaux'))));
$selectedNiveau = $_GET['niveau'] ?? 'all';
$filtered = ($selectedNiveau !== 'all')
    ? array_filter($marksheets, fn($m) => $m['niveaux'] === $selectedNiveau)
    : $marksheets;

$totalEtudiants = count($filtered);
$numerosValides = 0;
$smsEstimes = 0;
foreach ($filtered as $m) {
    if (\App\Services\PhoneNumberService::isValid($m['destinataire'])) {
        $numerosValides++;
    }
    $smsEstimes += (int) ceil(strlen($m['messages']) / 153);
}
?>
<hr>

<!-- Aperçu et lancement d'une campagne de résultats académiques (§3-§4) -->
<div class="row mb-3">
    <div class="col-sm-12">
        <div class="card">
            <div class="card-header">
                <h4>📤 Envoyer les résultats par SMS</h4>
            </div>
            <div class="card-body">
                <form method="get" class="row g-2 mb-3">
                    <input type="hidden" name="page" value="notes">
                    <div class="col-md-4">
                        <label class="form-label">Niveau</label>
                        <select name="niveau" class="form-control" onchange="this.form.submit()">
                            <option value="all" <?= $selectedNiveau === 'all' ? 'selected' : '' ?>>Tous les niveaux</option>
                            <?php foreach ($niveaux as $n): ?>
                                <option value="<?= htmlspecialchars($n) ?>" <?= $selectedNiveau === $n ? 'selected' : '' ?>><?= htmlspecialchars($n) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>

                <div class="row text-center mb-3">
                    <div class="col-md-3 col-sm-6">
                        <h3><?= $totalEtudiants ?></h3>
                        <small class="text-muted">Étudiants concernés</small>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <h3 class="<?= $numerosValides < $totalEtudiants ? 'text-warning' : 'text-success' ?>"><?= $numerosValides ?></h3>
                        <small class="text-muted">Numéros valides</small>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <h3 class="<?= ($totalEtudiants - $numerosValides) > 0 ? 'text-danger' : '' ?>"><?= $totalEtudiants - $numerosValides ?></h3>
                        <small class="text-muted">Numéros invalides</small>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <h3><?= $smsEstimes ?></h3>
                        <small class="text-muted">SMS estimés</small>
                    </div>
                </div>

                <?php if ($totalEtudiants > 0): ?>
                <form method="post" action="../server/app.php" onsubmit="return confirm('Vous êtes sur le point de préparer une campagne pour <?= $totalEtudiants ?> étudiant(s) (<?= $numerosValides ?> numéro(s) valide(s), coût estimé : <?= $smsEstimes ?> SMS). Continuer ?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="niveau" value="<?= htmlspecialchars($selectedNiveau) ?>">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label">Session académique</label>
                            <input type="text" name="session" class="form-control" placeholder="2025-2026">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Semestre</label>
                            <input type="text" name="semestre" class="form-control" placeholder="Semestre 1">
                        </div>
                        <div class="col-md-3">
                            <button type="submit" name="prepare_resultats_campagne" class="btn btn-primary">
                                📨 Préparer la campagne (<?= $totalEtudiants ?> étudiants)
                            </button>
                        </div>
                    </div>
                </form>
                <?php else: ?>
                    <div class="alert alert-info mb-0">Aucun résultat disponible pour ce niveau. Importez des résultats via "Créer une campagne" → "Importer les Messages".</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Recent Orders start -->
<div class="row">

    <div class="col-sm-12">
        <div class="card table-card">
            <div class="card-header">
                <h4>Listes des notes <?= $selectedNiveau !== 'all' ? '— ' . htmlspecialchars($selectedNiveau) : '' ?></h4>
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
                            <?php foreach ($filtered as $marksheet) { ?>
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
                                                        <?= csrf_field() ?>
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