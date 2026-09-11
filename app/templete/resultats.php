<?php
$filterOptions = academicResults()->getFilterOptions();
$importHistory = academicResults()->getImportHistory(5);
$defaultTemplate = "Bonjour {{prenom}} {{nom}},\n\nVos résultats du {{semestre}} - {{session}} sont disponibles.\n\nMoyenne : {{moyenne}}\nMention : {{mention}}\nRang : {{rang}}/{{total}}\n\nUGLC-SCOLARITE";
$availableVars = ['nom', 'prenom', 'matricule', 'classe', 'niveau', 'programme', 'semestre', 'session', 'moyenne', 'mention', 'rang', 'total', 'credits', 'appreciation'];
?>
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h4 class="mb-0">📥 Import des résultats académiques</h4>
            </div>
            <div class="card-body">
                <form action="../server/app.php" method="POST" enctype="multipart/form-data" class="row g-3 align-items-end">
                    <?= csrf_field() ?>
                    <div class="col-md-8">
                        <label for="resultatsFile" class="form-label">Fichier Excel (.xlsx) ou CSV</label>
                        <input class="form-control" type="file" id="resultatsFile" name="resultatsFile" accept=".xlsx,.xls,.csv" required>
                        <small class="text-muted">
                            Colonnes reconnues (ordre libre, accents optionnels) : <b>telephone</b> (obligatoire), matricule, nom, prenom,
                            etablissement, session, niveau, classe, programme, semestre, moyenne, mention, rang, total, credits, appreciation.
                        </small>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary w-100" name="import_resultats">📤 Importer</button>
                    </div>
                </form>

                <?php if (!empty($importHistory)): ?>
                <hr>
                <h6>Derniers imports</h6>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead><tr><th>Date</th><th>Fichier</th><th>Analysées</th><th>Valides</th><th>Invalides</th><th>Doublons</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($importHistory as $imp): ?>
                            <tr>
                                <td><?= htmlspecialchars($imp['created_at']) ?></td>
                                <td><?= htmlspecialchars($imp['filename'] ?? '') ?></td>
                                <td><?= (int) $imp['total_lignes'] ?></td>
                                <td class="text-success"><?= (int) $imp['valides'] ?></td>
                                <td class="text-danger"><?= (int) $imp['invalides'] ?></td>
                                <td class="text-warning"><?= (int) $imp['doublons'] ?></td>
                                <td>
                                    <?php if ((int) $imp['invalides'] > 0 || (int) $imp['doublons'] > 0): ?>
                                    <a href="../server/resultats_export_errors.php?import_id=<?= (int) $imp['id'] ?>" class="btn btn-sm btn-outline-secondary">Télécharger les erreurs</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row mt-3">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><h4 class="mb-0">🎯 Sélection des destinataires</h4></div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label">Session</label>
                        <select class="form-select filter-input" id="f_session">
                            <option value="">Toutes</option>
                            <?php foreach ($filterOptions['session_academique'] as $v): ?>
                                <option value="<?= htmlspecialchars($v) ?>"><?= htmlspecialchars($v) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Niveau</label>
                        <select class="form-select filter-input" id="f_niveau">
                            <option value="">Tous</option>
                            <?php foreach ($filterOptions['niveau'] as $v): ?>
                                <option value="<?= htmlspecialchars($v) ?>"><?= htmlspecialchars($v) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Classe</label>
                        <select class="form-select filter-input" id="f_classe">
                            <option value="">Toutes</option>
                            <?php foreach ($filterOptions['classe'] as $v): ?>
                                <option value="<?= htmlspecialchars($v) ?>"><?= htmlspecialchars($v) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Programme</label>
                        <select class="form-select filter-input" id="f_programme">
                            <option value="">Tous</option>
                            <?php foreach ($filterOptions['programme'] as $v): ?>
                                <option value="<?= htmlspecialchars($v) ?>"><?= htmlspecialchars($v) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Semestre</label>
                        <select class="form-select filter-input" id="f_semestre">
                            <option value="">Tous</option>
                            <?php foreach ($filterOptions['semestre'] as $v): ?>
                                <option value="<?= htmlspecialchars($v) ?>"><?= htmlspecialchars($v) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <hr>
                <div class="text-center">
                    <h2 class="mb-0" id="preview-count">—</h2>
                    <small class="text-muted">étudiant(s) correspondant(s)</small>
                </div>
                <div class="row text-center mt-3">
                    <div class="col">
                        <div id="preview-sms-info">—</div>
                        <small class="text-muted">Longueur / SMS par étudiant</small>
                    </div>
                    <div class="col">
                        <div id="preview-total-sms">—</div>
                        <small class="text-muted">SMS estimés au total</small>
                    </div>
                </div>
                <div id="balance-warning" class="alert alert-danger mt-3" style="display:none;"></div>
                <div id="balance-info" class="text-muted mt-2 small"></div>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card">
            <div class="card-header"><h4 class="mb-0">✉️ Modèle de message &amp; envoi</h4></div>
            <div class="card-body">
                <form action="../server/app.php" method="POST" id="resultatsForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="f_session" id="hidden_f_session">
                    <input type="hidden" name="f_niveau" id="hidden_f_niveau">
                    <input type="hidden" name="f_classe" id="hidden_f_classe">
                    <input type="hidden" name="f_programme" id="hidden_f_programme">
                    <input type="hidden" name="f_semestre" id="hidden_f_semestre">

                    <div class="mb-2">
                        <label class="form-label">Variables disponibles (cliquer pour insérer)</label><br>
                        <?php foreach ($availableVars as $var): ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary mb-1 insert-var" data-var="<?= $var ?>">{{<?= $var ?>}}</button>
                        <?php endforeach; ?>
                    </div>

                    <div class="mb-3">
                        <label for="message_template" class="form-label">Modèle du SMS</label>
                        <textarea class="form-control" id="message_template" name="message_template" rows="6"><?= htmlspecialchars($defaultTemplate) ?></textarea>
                    </div>

                    <div class="mb-3 border rounded p-2 bg-light">
                        <small class="text-muted d-block mb-1">Aperçu réel (basé sur le premier étudiant correspondant) :</small>
                        <div id="preview-message" class="fw-bold" style="white-space: pre-wrap;">—</div>
                        <div id="preview-missing" class="text-danger small mt-1"></div>
                    </div>

                    <div class="row g-2 align-items-end mb-3">
                        <div class="col-md-8">
                            <label for="test_number" class="form-label">Numéro de test</label>
                            <input type="text" class="form-control" id="test_number" name="test_number" placeholder="+224622xxxxxx">
                        </div>
                        <div class="col-md-4">
                            <button type="submit" name="test_sms_resultats" class="btn btn-outline-primary w-100">📱 Envoyer un test</button>
                        </div>
                    </div>

                    <hr>
                    <div class="row g-2 align-items-end">
                        <div class="col-md-8">
                            <label for="campagne_name" class="form-label">Nom de la campagne</label>
                            <input type="text" class="form-control" id="campagne_name" name="campagne_name" placeholder="Ex: Résultats Semestre 4 - 2025-2026" required>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" name="create_resultats_campagne" id="btn-create-campagne" class="btn btn-success w-100">🚀 Préparer la campagne</button>
                        </div>
                    </div>
                    <small class="text-muted d-block mt-2">La campagne est créée en brouillon ; vous la lancerez (et suivrez sa progression) depuis l'écran Campagnes.</small>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const filterIds = ['f_session', 'f_niveau', 'f_classe', 'f_programme', 'f_semestre'];
    const paramNames = { f_session: 'session', f_niveau: 'niveau', f_classe: 'classe', f_programme: 'programme', f_semestre: 'semestre' };
    let debounceTimer = null;

    function currentFilters() {
        const params = {};
        filterIds.forEach(id => {
            const val = document.getElementById(id).value;
            params[paramNames[id]] = val;
            document.getElementById('hidden_' + id).value = val;
        });
        return params;
    }

    function refreshPreview() {
        const params = currentFilters();
        params.template = document.getElementById('message_template').value;

        const qs = new URLSearchParams(params).toString();
        fetch('../server/resultats_preview.php?' + qs)
            .then(r => r.json())
            .then(data => {
                if (data.error) return;
                document.getElementById('preview-count').textContent = data.count;
                document.getElementById('preview-message').textContent = data.count > 0 ? data.preview_message : 'Aucun étudiant ne correspond à ces critères.';
                document.getElementById('preview-missing').textContent = (data.missing_vars && data.missing_vars.length > 0)
                    ? '⚠️ Variable(s) sans valeur pour cet étudiant : ' + data.missing_vars.join(', ')
                    : '';
                document.getElementById('preview-sms-info').textContent = data.sms.length + ' car. · ' + data.sms.encoding + ' · ' + data.sms.segments + ' SMS';
                document.getElementById('preview-total-sms').textContent = data.estimated_sms_total;

                const warnEl = document.getElementById('balance-warning');
                const infoEl = document.getElementById('balance-info');
                const btn = document.getElementById('btn-create-campagne');
                if (data.balance === null) {
                    infoEl.textContent = 'Solde Orange non vérifiable actuellement.';
                    warnEl.style.display = 'none';
                    btn.disabled = false;
                } else {
                    infoEl.textContent = 'Solde Orange disponible : ' + data.balance + ' SMS.';
                    if (data.balance_sufficient === false && data.count > 0) {
                        warnEl.style.display = 'block';
                        warnEl.textContent = '❌ Solde SMS insuffisant pour cette campagne (' + data.estimated_sms_total + ' nécessaires, ' + data.balance + ' disponibles).';
                        btn.disabled = true;
                    } else {
                        warnEl.style.display = 'none';
                        btn.disabled = false;
                    }
                }
            })
            .catch(() => {});
    }

    function scheduleRefresh() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(refreshPreview, 300);
    }

    filterIds.forEach(id => document.getElementById(id).addEventListener('change', scheduleRefresh));
    document.getElementById('message_template').addEventListener('input', scheduleRefresh);

    document.querySelectorAll('.insert-var').forEach(btn => {
        btn.addEventListener('click', function () {
            const textarea = document.getElementById('message_template');
            const token = '{{' + this.dataset.var + '}}';
            const start = textarea.selectionStart;
            const end = textarea.selectionEnd;
            textarea.value = textarea.value.slice(0, start) + token + textarea.value.slice(end);
            textarea.focus();
            textarea.selectionStart = textarea.selectionEnd = start + token.length;
            scheduleRefresh();
        });
    });

    document.getElementById('resultatsForm').addEventListener('submit', function (e) {
        const submitter = e.submitter;
        if (submitter && submitter.name === 'create_resultats_campagne') {
            const count = document.getElementById('preview-count').textContent;
            if (!confirm('Vous êtes sur le point de préparer une campagne de ' + count + ' SMS. Continuer ?')) {
                e.preventDefault();
            }
        }
    });

    refreshPreview();
})();
</script>
