<?php
$filterOptions = academicResults()->getFilterOptions();
$importHistory = academicResults()->getImportHistory(5);
$defaultTemplate = "Bonjour {{prenom}} {{nom}},\n\nVos résultats du {{semestre}} - {{session}} sont disponibles.\n\nMoyenne : {{moyenne}}\nMention : {{mention}}\nRang : {{rang}}/{{total}}\n\nUGLC-SCOLARITE";
$availableVars = ['nom', 'prenom', 'matricule', 'classe', 'niveau', 'programme', 'semestre', 'session', 'moyenne', 'mention', 'rang', 'total', 'credits', 'appreciation'];
$savedTemplates = smsTemplates()->all(false);
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
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="exclude_already_sent">
                            <label class="form-check-label" for="exclude_already_sent">
                                Exclure les étudiants ayant déjà reçu leurs résultats
                            </label>
                        </div>
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
                    <input type="hidden" name="exclude_already_sent" id="hidden_exclude_already_sent" value="">
                    <input type="hidden" name="excluded_ids" id="hidden_excluded_ids" value="">

                    <?php if (!empty($savedTemplates)): ?>
                    <div class="mb-2">
                        <label for="saved_template_select" class="form-label">Charger un modèle enregistré</label>
                        <select class="form-select form-select-sm" id="saved_template_select">
                            <option value="">— Choisir un modèle —</option>
                            <?php foreach ($savedTemplates as $t): ?>
                                <option value="<?= htmlspecialchars($t['contenu']) ?>"><?= htmlspecialchars($t['nom']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Gérer les modèles depuis <a href="?page=modeles">Modèles SMS</a>.</small>
                    </div>
                    <?php endif; ?>

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

<div class="row mt-3">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h4 class="mb-0">📋 Liste des étudiants correspondants</h4>
                <div class="d-flex gap-2">
                    <input type="text" class="form-control form-control-sm" id="student_search" placeholder="Rechercher (nom, prénom, matricule)" style="width:250px;">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-reset-exclusions">Tout réinclure</button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0">
                        <thead>
                            <tr>
                                <th style="width:2rem;"><input type="checkbox" id="student-select-all" checked></th>
                                <th>Matricule</th><th>Nom</th><th>Prénom</th><th>Classe</th><th>Téléphone</th><th>Moyenne</th><th>Mention</th><th>Statut</th>
                            </tr>
                        </thead>
                        <tbody id="student-list-body">
                            <tr><td colspan="9" class="text-center text-muted p-3">Choisissez des filtres ci-dessus pour afficher les étudiants correspondants.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-between align-items-center">
                <small class="text-muted" id="student-list-summary"></small>
                <div>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-prev-page">« Précédent</button>
                    <span class="mx-2" id="student-page-indicator">—</span>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-next-page">Suivant »</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const filterIds = ['f_session', 'f_niveau', 'f_classe', 'f_programme', 'f_semestre'];
    const paramNames = { f_session: 'session', f_niveau: 'niveau', f_classe: 'classe', f_programme: 'programme', f_semestre: 'semestre' };
    let debounceTimer = null;
    let excludedIds = new Set();
    let currentPage = 1;
    let totalPages = 1;
    let lastTotal = 0;
    const rowsById = new Map(); // id -> row data, for the currently loaded page

    function currentFilters() {
        const params = {};
        filterIds.forEach(id => {
            const val = document.getElementById(id).value;
            params[paramNames[id]] = val;
            document.getElementById('hidden_' + id).value = val;
        });
        params.exclude_already_sent = document.getElementById('exclude_already_sent').checked ? '1' : '';
        return params;
    }

    function syncHiddenExclusionFields() {
        document.getElementById('hidden_exclude_already_sent').value = document.getElementById('exclude_already_sent').checked ? '1' : '';
        document.getElementById('hidden_excluded_ids').value = Array.from(excludedIds).join(',');
    }

    function refreshPreview() {
        const params = currentFilters();
        params.template = document.getElementById('message_template').value;
        params.exclude_ids = Array.from(excludedIds).join(',');
        syncHiddenExclusionFields();

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

    function renderStudentRow(row) {
        const tr = document.createElement('tr');
        const checked = !excludedIds.has(row.id);
        tr.innerHTML =
            '<td><input type="checkbox" class="student-row-check" data-id="' + row.id + '"' + (checked ? ' checked' : '') + '></td>' +
            '<td>' + (row.matricule || '') + '</td>' +
            '<td>' + (row.nom || '') + '</td>' +
            '<td>' + (row.prenom || '') + '</td>' +
            '<td>' + (row.classe || '') + '</td>' +
            '<td>' + (row.telephone || '') + '</td>' +
            '<td>' + (row.moyenne || '') + '</td>' +
            '<td>' + (row.mention || '') + '</td>' +
            '<td>' + (row.deja_envoye ? '<span class="badge bg-warning text-dark">Déjà envoyé</span>' : '<span class="badge bg-light text-muted">—</span>') + '</td>';
        return tr;
    }

    function refreshStudentList() {
        const params = currentFilters();
        params.search = document.getElementById('student_search').value;
        params.page = currentPage;
        params.per_page = 50;

        const qs = new URLSearchParams(params).toString();
        fetch('../server/resultats_list.php?' + qs)
            .then(r => r.json())
            .then(data => {
                if (data.error) return;
                totalPages = data.total_pages || 1;
                const tbody = document.getElementById('student-list-body');
                tbody.innerHTML = '';
                rowsById.clear();

                if (data.rows.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted p-3">Aucun étudiant ne correspond à ces critères.</td></tr>';
                } else {
                    data.rows.forEach(row => {
                        rowsById.set(row.id, row);
                        tbody.appendChild(renderStudentRow(row));
                    });
                }

                lastTotal = data.total;
                updateSummaryText();
                document.getElementById('student-page-indicator').textContent = 'Page ' + data.page + ' / ' + Math.max(1, totalPages);
                updateSelectAllCheckbox();
            })
            .catch(() => {});
    }

    function updateSummaryText() {
        document.getElementById('student-list-summary').textContent = lastTotal + ' étudiant(s) au total (' + excludedIds.size + ' exclu(s) manuellement)';
    }

    function updateSelectAllCheckbox() {
        const boxes = document.querySelectorAll('.student-row-check');
        const allChecked = Array.from(boxes).every(b => b.checked);
        document.getElementById('student-select-all').checked = boxes.length > 0 && allChecked;
    }

    function scheduleRefresh() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function () {
            refreshPreview();
            currentPage = 1;
            refreshStudentList();
        }, 300);
    }

    filterIds.forEach(id => document.getElementById(id).addEventListener('change', scheduleRefresh));
    document.getElementById('exclude_already_sent').addEventListener('change', scheduleRefresh);
    document.getElementById('message_template').addEventListener('input', function () {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(refreshPreview, 300); // le modèle n'affecte pas la liste, seulement l'aperçu/le calcul SMS
    });

    let searchDebounce = null;
    document.getElementById('student_search').addEventListener('input', function () {
        clearTimeout(searchDebounce);
        searchDebounce = setTimeout(function () { currentPage = 1; refreshStudentList(); }, 400);
    });

    document.getElementById('student-list-body').addEventListener('change', function (e) {
        if (!e.target.classList.contains('student-row-check')) return;
        const id = parseInt(e.target.dataset.id, 10);
        if (e.target.checked) {
            excludedIds.delete(id);
        } else {
            excludedIds.add(id);
        }
        syncHiddenExclusionFields();
        updateSelectAllCheckbox();
        updateSummaryText();
        refreshPreview();
    });

    document.getElementById('student-select-all').addEventListener('change', function () {
        const check = this.checked;
        document.querySelectorAll('.student-row-check').forEach(box => {
            const id = parseInt(box.dataset.id, 10);
            box.checked = check;
            if (check) { excludedIds.delete(id); } else { excludedIds.add(id); }
        });
        syncHiddenExclusionFields();
        updateSummaryText();
        refreshPreview();
    });

    document.getElementById('btn-reset-exclusions').addEventListener('click', function () {
        excludedIds.clear();
        syncHiddenExclusionFields();
        refreshPreview();
        refreshStudentList();
    });

    document.getElementById('btn-prev-page').addEventListener('click', function () {
        if (currentPage > 1) { currentPage--; refreshStudentList(); }
    });
    document.getElementById('btn-next-page').addEventListener('click', function () {
        if (currentPage < totalPages) { currentPage++; refreshStudentList(); }
    });

    document.querySelectorAll('.insert-var').forEach(btn => {
        btn.addEventListener('click', function () {
            const textarea = document.getElementById('message_template');
            const token = '{{' + this.dataset.var + '}}';
            const start = textarea.selectionStart;
            const end = textarea.selectionEnd;
            textarea.value = textarea.value.slice(0, start) + token + textarea.value.slice(end);
            textarea.focus();
            textarea.selectionStart = textarea.selectionEnd = start + token.length;
            refreshPreview();
        });
    });

    const savedTemplateSelect = document.getElementById('saved_template_select');
    if (savedTemplateSelect) {
        savedTemplateSelect.addEventListener('change', function () {
            if (this.value !== '') {
                document.getElementById('message_template').value = this.value;
                refreshPreview();
            }
        });
    }

    document.getElementById('resultatsForm').addEventListener('submit', function (e) {
        syncHiddenExclusionFields();
        const submitter = e.submitter;
        if (submitter && submitter.name === 'create_resultats_campagne') {
            const count = document.getElementById('preview-count').textContent;
            if (!confirm('Vous êtes sur le point de préparer une campagne de ' + count + ' SMS. Continuer ?')) {
                e.preventDefault();
            }
        }
    });

    refreshPreview();
    refreshStudentList();
})();
</script>
