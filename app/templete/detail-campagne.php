<?php
$campagne = (!empty($_GET['details'])) ? getSingleCampagne($_GET['details']) : null;
// §59 : une campagne_id valide mais appartenant à une autre organisation doit
// être traitée comme "introuvable", pas affichée (IDOR sinon — l'id de
// campagne est un entier auto-incrémenté global, donc devinable/énumérable).
if (!$campagne || (int) $campagne['organization_id'] !== auth()->organizationId()) {
    echo '<div class="alert alert-danger">Campagne introuvable.</div>';
    return;
}
$messages = getMessageCampagne($campagne['id']);
$isActive = in_array($campagne['statut'], ['QUEUED', 'RUNNING'], true);
$isDraft = $campagne['statut'] === 'DRAFT';
$isScheduled = $campagne['statut'] === 'SCHEDULED';
$isFinished = in_array($campagne['statut'], ['COMPLETED', 'PARTIAL', 'FAILED', 'CANCELLED'], true);
$badgeClass = [
    'DRAFT' => 'bg-secondary', 'SCHEDULED' => 'bg-primary', 'QUEUED' => 'bg-info', 'RUNNING' => 'bg-primary',
    'PAUSED' => 'bg-warning', 'COMPLETED' => 'bg-success', 'PARTIAL' => 'bg-warning',
    'FAILED' => 'bg-danger', 'CANCELLED' => 'bg-dark',
][$campagne['statut']] ?? 'bg-secondary';

$hasRecipients = (int) $campagne['total_destinataires'] > 0;

// §15 étape 5 / §20 / §30 : résumé avant lancement (immédiat ou planifié) —
// estimation du nombre réel de SMS (segments, pas juste 1 destinataire = 1
// SMS) et comparaison au solde Orange, affichés avant que l'utilisateur ne
// clique sur "Lancer"/"Programmer" (le blocage serveur existe déjà dans
// server/app.php, ceci n'est que l'affichage).
$smsNeeded = null;
$balanceInfo = null;
$balanceError = null;
if (($isDraft && $hasRecipients) || $isScheduled) {
    $smsNeeded = estimateSmsNeeded($campagne['id']);
    try {
        $balanceInfo = orangeSms()->getBalance();
    } catch (Exception $e) {
        $balanceError = $e->getMessage();
    }
}
$availableUnits = $balanceInfo['availableUnits'] ?? null;
$balanceSufficient = $availableUnits !== null && $smsNeeded !== null ? ((int) $availableUnits >= $smsNeeded) : null;

if ($isDraft && !$hasRecipients) {
    $groupesDisponibles = contacts()->allGroups();
    $templatesDisponibles = smsTemplates()->all();
    $totalContactsOrg = contacts()->countContacts();
}

$orgFuseauHoraire = 'Africa/Conakry';
if ($isDraft && $hasRecipients) {
    $orgFuseauHoraire = organizations()->find(auth()->organizationId())['fuseau_horaire'] ?? $orgFuseauHoraire;
}
?>
<hr>
<div class="row mb-3">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h4 class="mb-0"><?= htmlspecialchars($campagne['nom']) ?> <span class="badge <?= $badgeClass ?>" id="campagne-statut"><?= $campagne['statut'] ?></span></h4>
                <div>
                    <?php if ($isDraft && $hasRecipients): ?>
                        <form method="post" action="../server/app.php" class="d-inline" onsubmit="return confirm('Lancer l\'envoi de <?= (int)$campagne['total_destinataires'] ?> destinataire(s) (<?= (int) $smsNeeded ?> SMS estimés) maintenant ?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="campagne_id" value="<?= $campagne['id'] ?>">
                            <button type="submit" name="launch_campagne" class="btn btn-success" <?= $balanceSufficient === false ? 'disabled' : '' ?>>🚀 Lancer l'envoi</button>
                        </form>
                        <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#scheduleModal<?= $campagne['id'] ?>">📅 Programmer</button>
                    <?php elseif ($isScheduled): ?>
                        <form method="post" action="../server/app.php" class="d-inline" onsubmit="return confirm('Lancer l\'envoi maintenant plutôt que d\'attendre la date programmée ?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="campagne_id" value="<?= $campagne['id'] ?>">
                            <input type="hidden" name="dry_run" value="<?= ($campagne['dry_run'] === true || $campagne['dry_run'] === 't') ? '1' : '' ?>">
                            <button type="submit" name="launch_campagne" class="btn btn-success" <?= $balanceSufficient === false ? 'disabled' : '' ?>>🚀 Lancer maintenant</button>
                        </form>
                        <form method="post" action="../server/app.php" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="campagne_id" value="<?= $campagne['id'] ?>">
                            <button type="submit" name="unschedule_campagne" class="btn btn-outline-secondary">↩ Annuler la programmation</button>
                        </form>
                    <?php elseif ($campagne['statut'] === 'RUNNING'): ?>
                        <form method="post" action="../server/app.php" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="campagne_id" value="<?= $campagne['id'] ?>">
                            <button type="submit" name="pause_campagne" class="btn btn-warning">⏸ Pause</button>
                        </form>
                    <?php elseif ($campagne['statut'] === 'PAUSED'): ?>
                        <form method="post" action="../server/app.php" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="campagne_id" value="<?= $campagne['id'] ?>">
                            <button type="submit" name="resume_campagne" class="btn btn-success">▶ Reprendre</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($isActive || $campagne['statut'] === 'PAUSED'): ?>
                        <form method="post" action="../server/app.php" class="d-inline" onsubmit="return confirm('Annuler cette campagne ? Les SMS déjà envoyés resteront dans l\'historique.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="campagne_id" value="<?= $campagne['id'] ?>">
                            <button type="submit" name="cancel_campagne" class="btn btn-outline-danger">✖ Annuler</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <p class="text-muted mb-3"><?= htmlspecialchars($campagne['description']) ?></p>

                <div class="row text-center mb-3">
                    <div class="col"><h4 id="stat-total"><?= (int) $campagne['total_destinataires'] ?></h4><small class="text-muted">Total</small></div>
                    <div class="col"><h4 class="text-success" id="stat-sent"><?= (int) $campagne['nombre_envoyes'] ?></h4><small class="text-muted">Réussis</small></div>
                    <div class="col"><h4 class="text-danger" id="stat-failed"><?= (int) $campagne['nombre_echecs'] ?></h4><small class="text-muted">Échecs</small></div>
                    <div class="col"><h4 id="stat-pending"><?= max(0, (int) $campagne['total_destinataires'] - (int) $campagne['nombre_envoyes'] - (int) $campagne['nombre_echecs']) ?></h4><small class="text-muted">En attente</small></div>
                </div>

                <?php if ($isActive): ?>
                <div class="progress mb-2" style="height: 24px;">
                    <div class="progress-bar progress-bar-striped progress-bar-animated" id="campagne-progress" role="progressbar" style="width: 0%">0%</div>
                </div>
                <p class="text-muted" id="campagne-progress-text">Envoi en cours…</p>
                <?php endif; ?>

                <?php if ($isFinished && (int) $campagne['nombre_echecs'] > 0): ?>
                <form method="post" action="../server/app.php" class="mb-3" onsubmit="return confirm('Réessayer les <?= (int)$campagne['nombre_echecs'] ?> échec(s) rattrapables ?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="campagne_id" value="<?= $campagne['id'] ?>">
                    <button type="submit" name="retry_campagne_failures" class="btn btn-outline-warning">🔁 Réessayer les échecs</button>
                </form>
                <?php endif; ?>

                <?php if ($isDraft && !$hasRecipients): ?>
                <hr>
                <h6>Choisir les destinataires (§15)</h6>
                <div class="d-flex gap-2 flex-wrap">
                    <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#composeModal<?= $campagne['id'] ?>" onclick="document.getElementById('audience_all_<?= $campagne['id'] ?>').checked = true; toggleGroupeSelect<?= $campagne['id'] ?>();">
                        <i class="ph ph-address-book"></i>&nbsp; Tous mes contacts (<?= (int) $totalContactsOrg ?>)
                    </button>
                    <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#composeModal<?= $campagne['id'] ?>" onclick="document.getElementById('audience_group_<?= $campagne['id'] ?>').checked = true; toggleGroupeSelect<?= $campagne['id'] ?>();">
                        <i class="ph ph-users-three"></i>&nbsp; Un groupe
                    </button>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#importExcelModal<?= $campagne['id'] ?>">
                        <span class="fa fa-file-excel-o"></span>&nbsp; Importer un fichier Excel
                    </button>
                </div>
                <?php endif; ?>

                <?php if ($isScheduled): ?>
                <hr>
                <div class="alert alert-primary mb-0">
                    📅 Envoi programmé pour le <strong><?= formatOrgDateTime($campagne['scheduled_at'], 'd/m/Y \à H:i') ?></strong>.
                    <?php if ($balanceSufficient === false): ?>
                        Solde actuellement insuffisant — vérifiez-le avant l'heure prévue (détail ci-dessous).
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (($isDraft && $hasRecipients) || $isScheduled): ?>
                <hr>
                <div class="row g-3 mb-3">
                    <div class="col-md-8">
                        <div class="alert <?= $balanceSufficient === false ? 'alert-danger' : 'alert-light border' ?> mb-0">
                            <strong>Avant de lancer :</strong>
                            <?= (int) $campagne['total_destinataires'] ?> destinataire(s) ·
                            <strong><?= (int) $smsNeeded ?> SMS estimé(s)</strong> ·
                            solde disponible :
                            <?php if ($balanceError): ?>
                                <span class="text-muted">indisponible (<?= htmlspecialchars($balanceError) ?>)</span>
                            <?php else: ?>
                                <strong><?= (int) $availableUnits ?></strong>
                            <?php endif; ?>
                            <?php if ($balanceSufficient === false): ?>
                                <div class="mt-1">❌ Solde SMS insuffisant pour cette campagne.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <form method="post" action="../server/app.php" class="d-flex gap-1">
                            <?= csrf_field() ?>
                            <input type="hidden" name="campagne_id" value="<?= $campagne['id'] ?>">
                            <input type="text" name="test_numero" class="form-control" placeholder="+224XXXXXXXXX" required pattern="^(\+224|00224)6\d{8}$">
                            <button type="submit" name="send_test_sms" class="btn btn-outline-secondary text-nowrap">🧪 Tester</button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($isDraft && $hasRecipients): ?>
<div class="modal fade" id="scheduleModal<?= $campagne['id'] ?>" tabindex="-1" aria-labelledby="scheduleModalLabel<?= $campagne['id'] ?>" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content shadow-lg">
            <div class="modal-header">
                <h5 class="modal-title">📅 Programmer l'envoi de <span class="text-danger"><?= htmlspecialchars($campagne['nom']) ?></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <form action="../server/app.php" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="campagne_id" value="<?= $campagne['id'] ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" for="scheduledAt<?= $campagne['id'] ?>">Date et heure d'envoi *</label>
                        <input type="datetime-local" id="scheduledAt<?= $campagne['id'] ?>" name="scheduled_at" class="form-control" required min="<?= (new DateTime('+1 minute'))->format('Y-m-d\TH:i') ?>">
                        <small class="text-muted">Heure du serveur (fuseau de l'organisation : <?= htmlspecialchars($orgFuseauHoraire) ?>).</small>
                    </div>
                    <p class="mb-0"><?= (int) $campagne['total_destinataires'] ?> destinataire(s) · <?= (int) $smsNeeded ?> SMS estimé(s) · solde actuel : <?= $balanceError ? '—' : (int) $availableUnits ?>.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-danger" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary" name="schedule_campagne">📅 Programmer</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Recent Orders start -->
<div class="row">
    <div class="col-sm-12">
        <div class="card table-card">
            <div class="card-header">
                <h4>Journal d'envoi</h4>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="groupesTable" class="table table-striped table-bordered">
                        <thead>
                            <tr>
                                <th>Heure</th>
                                <th>Nom</th>
                                <th>Prénom</th>
                                <th>Destinataire</th>
                                <th>Statut</th>
                                <th>Erreur</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($messages as $m) { ?>
                            <tr>
                                <td><?= htmlspecialchars($m['date_traitement'] ?? $m['date_envoi']) ?></td>
                                <td><?= htmlspecialchars($m['nom'] ?? '') ?></td>
                                <td><?= htmlspecialchars($m['prenom'] ?? '') ?></td>
                                <td><?= htmlspecialchars($m['destinataire']) ?></td>
                                <td><?= htmlspecialchars($m['statut']) ?></td>
                                <td><?= htmlspecialchars($m['error_code'] ?? '') ?></td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<hr>

<?php if ($isDraft && !$hasRecipients): ?>
<div class="modal fade" id="composeModal<?= $campagne['id'] ?>" tabindex="-1" aria-labelledby="composeModalLabel<?= $campagne['id'] ?>" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content shadow-lg">
            <div class="modal-header">
                <h5 class="modal-title">✍️ Composer le message pour <span class="text-danger"><?= htmlspecialchars($campagne['nom']) ?></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <form action="../server/app.php" method="POST" id="composeForm<?= $campagne['id'] ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="campagne_id" value="<?= $campagne['id'] ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label d-block">Destinataires</label>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="audience_type" id="audience_all_<?= $campagne['id'] ?>" value="all" checked onchange="toggleGroupeSelect<?= $campagne['id'] ?>()">
                            <label class="form-check-label" for="audience_all_<?= $campagne['id'] ?>">Tous mes contacts (<?= (int) $totalContactsOrg ?>)</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="audience_type" id="audience_group_<?= $campagne['id'] ?>" value="group" onchange="toggleGroupeSelect<?= $campagne['id'] ?>()">
                            <label class="form-check-label" for="audience_group_<?= $campagne['id'] ?>">Un groupe</label>
                        </div>
                        <select name="groupe_id" id="groupeSelect<?= $campagne['id'] ?>" class="form-select mt-2" disabled onchange="updateCounterAndPreview<?= $campagne['id'] ?>()">
                            <option value="">— Choisir un groupe —</option>
                            <?php foreach ($groupesDisponibles as $g): ?>
                                <option value="<?= (int) $g['id'] ?>"><?= htmlspecialchars($g['nom']) ?> (<?= (int) $g['nombre_contacts'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if (!empty($templatesDisponibles)): ?>
                    <div class="mb-3">
                        <label class="form-label">Partir d'un modèle (optionnel)</label>
                        <select class="form-select" onchange="if(this.value){document.getElementById('campaignMessage<?= $campagne['id'] ?>').value = this.value; updateCounterAndPreview<?= $campagne['id'] ?>();}">
                            <option value="">— Message personnalisé —</option>
                            <?php foreach ($templatesDisponibles as $t): ?>
                                <option value="<?= htmlspecialchars($t['contenu']) ?>"><?= htmlspecialchars($t['nom']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="mb-2">
                        <label class="form-label">Message *</label>
                        <textarea name="campaign_message" id="campaignMessage<?= $campagne['id'] ?>" class="form-control" rows="4" required oninput="updateCounterAndPreview<?= $campagne['id'] ?>()"></textarea>
                        <small class="text-muted">Variables disponibles : <code>{{nom}}</code>, <code>{{prenom}}</code>, <code>{{telephone}}</code>, <code>{{email}}</code></small>
                    </div>
                    <div class="mb-3 small" id="counterInfo<?= $campagne['id'] ?>">0 caractère · 0 SMS</div>
                    <div class="mb-1"><strong>Aperçu réel (contact de l'audience)</strong></div>
                    <div class="border rounded p-2 bg-light" id="messagePreview<?= $campagne['id'] ?>" style="min-height: 2.5em;">—</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-danger" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-success" name="create_campaign_recipients">✅ Ajouter les destinataires</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
function toggleGroupeSelect<?= $campagne['id'] ?>() {
    const isGroup = document.getElementById('audience_group_<?= $campagne['id'] ?>').checked;
    const select = document.getElementById('groupeSelect<?= $campagne['id'] ?>');
    select.disabled = !isGroup;
    updateCounterAndPreview<?= $campagne['id'] ?>();
}

let debounceTimer<?= $campagne['id'] ?>;
function updateCounterAndPreview<?= $campagne['id'] ?>() {
    clearTimeout(debounceTimer<?= $campagne['id'] ?>);
    debounceTimer<?= $campagne['id'] ?> = setTimeout(function () {
        const message = document.getElementById('campaignMessage<?= $campagne['id'] ?>').value;
        const isGroup = document.getElementById('audience_group_<?= $campagne['id'] ?>').checked;
        const groupeId = isGroup ? document.getElementById('groupeSelect<?= $campagne['id'] ?>').value : '';
        const params = new URLSearchParams({ message: message, groupe_id: groupeId });

        fetch('../server/campaign_tools.php?' + params.toString())
            .then(r => r.json())
            .then(data => {
                document.getElementById('counterInfo<?= $campagne['id'] ?>').textContent =
                    data.length + ' caractère(s) · ' + data.encoding + ' · ' + data.segments + ' SMS';
                const previewEl = document.getElementById('messagePreview<?= $campagne['id'] ?>');
                if (data.has_contact) {
                    previewEl.textContent = data.preview || '(message vide)';
                } else {
                    previewEl.textContent = 'Aucun contact dans cette audience pour prévisualiser.';
                }
            });
    }, 300);
}
</script>
<?php endif; ?>

<?php if ($isDraft && !$hasRecipients): ?>
<div class="modal fade" id="importExcelModal<?= $campagne['id'] ?>" tabindex="-1" aria-labelledby="importExcelModalLabel<?= $campagne['id'] ?>" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content shadow-lg">
            <div class="modal-header">
                <h5 class="modal-title">📊 Importer un fichier Excel dans <span class="text-danger"><?= htmlspecialchars($campagne['nom']) ?></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">
                <form action="../server/app.php" method="POST" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="campagne_id" value="<?= $campagne['id'] ?>">
                    <div class="mb-3">
                        <label for="excelFile<?= $campagne['id'] ?>" class="form-label">Fichier Excel (.xlsx)</label>
                        <input class="form-control" type="file" id="excelFile<?= $campagne['id'] ?>" name="excelFile" accept=".xlsx,.xls" required>
                        <small class="text-muted">⚠️ Première ligne = en-têtes, colonnes attendues (ordre libre) : <b>nom, prenom, telephone, message</b></small>
                    </div>
                    <div class="d-flex justify-content-end">
                        <button type="button" class="btn btn-danger me-2" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-success" name="import_excel_recipients">📤 Importer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($isActive): ?>
<script>
(function () {
    const campagneId = <?= (int) $campagne['id'] ?>;
    function poll() {
        fetch('../server/campaign_worker.php?campagne_id=' + campagneId)
            .then(r => r.json())
            .then(data => {
                if (data.error) return;
                document.getElementById('stat-total').textContent = data.total;
                document.getElementById('stat-sent').textContent = data.sent;
                document.getElementById('stat-failed').textContent = data.failed;
                document.getElementById('stat-pending').textContent = data.pending + data.processing;
                const pct = data.total > 0 ? Math.round(((data.sent + data.failed) / data.total) * 100) : 0;
                const bar = document.getElementById('campagne-progress');
                if (bar) {
                    bar.style.width = pct + '%';
                    bar.textContent = pct + '%';
                }
                const text = document.getElementById('campagne-progress-text');
                if (text) {
                    text.textContent = (data.sent + data.failed) + ' / ' + data.total + ' traités — ' + data.sent + ' réussis, ' + data.failed + ' échecs';
                }
                if (data.done) {
                    setTimeout(() => location.reload(), 800);
                } else {
                    setTimeout(poll, 1200);
                }
            });
    }
    poll();
})();
</script>
<?php endif; ?>
