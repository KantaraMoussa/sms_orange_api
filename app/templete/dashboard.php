<?php
// Tableau de bord — refonte visuelle 2026-09-13 (cartes blanches en anneau +
// badges d'icône + mini-cartes chiffrées), voir sms-orange-overrides.css et
// AUDIT.md pour le contexte de ce changement de langage de composants.
$orgId = auth()->organizationId();
$evolution = getSmsEvolution($orgId, 14);
$globalStats = getGlobalSmsStats($orgId);
$performance = getCampaignPerformance($orgId, 6);
$successRateEvolution = getSuccessRateEvolution($orgId, 14);
$recentCampagnes = array_slice(getCampagne($orgId), 0, 5);
$smsToday = getSmsSentToday($orgId);
$smsThisMonth = getSmsSentThisMonth($orgId);
$activeCampaigns = getActiveCampaignsCount($orgId);
$campaignBreakdown = getCampaignStatusBreakdown($orgId);
$contactsTotal = contacts()->countContacts();
$contactsByGroup = contacts()->countByGroupMembership();
$templatesActive = count(smsTemplates()->all(false));
$templatesTotal = count(smsTemplates()->all(true));
$creditsBalance = credits()->balance();
?>
<div class="row g-3 mb-1">
    <div class="col-md-4">
        <div class="mini-stat-card py-3">
            <div class="d-flex align-items-center gap-3">
                <span class="stat-panel-icon" style="background: rgba(255,121,0,.1); color: var(--so-series-orange);"><i class="ph ph-device-mobile-speaker"></i></span>
                <div>
                    <div class="mini-stat-label mb-0">SMS disponible (Orange)</div>
                    <div class="mini-stat-value" style="font-size:20px;margin-top:2px;"><?= htmlspecialchars($_SESSION['soldeSms'] ?? '—') ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="mini-stat-card py-3">
            <div class="d-flex align-items-center gap-3">
                <span class="stat-panel-icon" style="background: rgba(59,130,246,.1); color: var(--so-series-blue);"><i class="ph ph-calendar"></i></span>
                <div>
                    <div class="mini-stat-label mb-0">Date d'expiration</div>
                    <div class="mini-stat-value" style="font-size:20px;margin-top:2px;"><?= htmlspecialchars($_SESSION['dateExpiration'] ?? '—') ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="mini-stat-card py-3">
            <div class="d-flex align-items-center gap-3">
                <span class="stat-panel-icon" style="background: rgba(20,184,166,.1); color: var(--so-series-teal);"><i class="ph ph-plug"></i></span>
                <div>
                    <div class="mini-stat-label mb-0">Statut du forfait Orange</div>
                    <div class="mini-stat-value" style="font-size:20px;margin-top:2px;"><?= htmlspecialchars($_SESSION['status'] ?? '—') ?></div>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="row g-3">
    <div class="col-lg-6">
        <div class="stat-panel">
            <div class="stat-panel-header">
                <div class="stat-panel-heading">
                    <span class="stat-panel-icon"><i class="ph ph-paper-plane-tilt"></i></span>
                    <div>
                        <p class="stat-panel-title">Campagnes</p>
                        <span class="stat-panel-subtitle">Toutes périodes</span>
                    </div>
                </div>
                <span class="stat-panel-info">i</span>
            </div>
            <div class="stat-donut-row">
                <div class="stat-donut-chart" id="donut-campagnes"></div>
                <div class="stat-legend">
                    <div class="stat-legend-row">
                        <span class="stat-legend-dot" style="background: var(--so-series-violet)"></span>
                        <span class="stat-legend-label">Actives</span>
                        <span class="stat-legend-value"><?= $campaignBreakdown['actives'] ?></span>
                        <span class="stat-legend-pct"><?= $campaignBreakdown['total'] > 0 ? round($campaignBreakdown['actives'] / $campaignBreakdown['total'] * 100, 1) : 0 ?>%</span>
                    </div>
                    <div class="stat-legend-row">
                        <span class="stat-legend-dot" style="background: var(--so-series-teal)"></span>
                        <span class="stat-legend-label">Terminées</span>
                        <span class="stat-legend-value"><?= $campaignBreakdown['terminees'] ?></span>
                        <span class="stat-legend-pct"><?= $campaignBreakdown['total'] > 0 ? round($campaignBreakdown['terminees'] / $campaignBreakdown['total'] * 100, 1) : 0 ?>%</span>
                    </div>
                    <div class="stat-legend-row">
                        <span class="stat-legend-dot" style="background: #d7dee8"></span>
                        <span class="stat-legend-label">Brouillons</span>
                        <span class="stat-legend-value"><?= $campaignBreakdown['brouillons'] ?></span>
                        <span class="stat-legend-pct"><?= $campaignBreakdown['total'] > 0 ? round($campaignBreakdown['brouillons'] / $campaignBreakdown['total'] * 100, 1) : 0 ?>%</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="stat-panel">
            <div class="stat-panel-header">
                <div class="stat-panel-heading">
                    <span class="stat-panel-icon"><i class="ph ph-address-book"></i></span>
                    <div>
                        <p class="stat-panel-title">Contacts</p>
                        <span class="stat-panel-subtitle">Base actuelle</span>
                    </div>
                </div>
                <span class="stat-panel-info">i</span>
            </div>
            <div class="stat-donut-row">
                <div class="stat-donut-chart" id="donut-contacts"></div>
                <div class="stat-legend">
                    <div class="stat-legend-row">
                        <span class="stat-legend-dot" style="background: var(--so-series-orange)"></span>
                        <span class="stat-legend-label">Dans un groupe</span>
                        <span class="stat-legend-value"><?= $contactsByGroup['with_group'] ?></span>
                        <span class="stat-legend-pct"><?= $contactsTotal > 0 ? round($contactsByGroup['with_group'] / $contactsTotal * 100, 1) : 0 ?>%</span>
                    </div>
                    <div class="stat-legend-row">
                        <span class="stat-legend-dot" style="background: var(--so-series-blue)"></span>
                        <span class="stat-legend-label">Sans groupe</span>
                        <span class="stat-legend-value"><?= $contactsByGroup['without_group'] ?></span>
                        <span class="stat-legend-pct"><?= $contactsTotal > 0 ? round($contactsByGroup['without_group'] / $contactsTotal * 100, 1) : 0 ?>%</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mt-1">
    <div class="col-md-4">
        <div class="mini-stat-card">
            <div class="mini-stat-top">
                <span class="stat-panel-icon"><i class="ph ph-paper-plane-right"></i></span>
                <span class="mini-stat-pill positive">Aujourd'hui</span>
            </div>
            <div class="mini-stat-value"><?= $smsToday ?><span class="mini-stat-unit">SMS</span></div>
            <div class="mini-stat-label">Envoyés aujourd'hui</div>
            <div class="mini-stat-bar"><span style="width: <?= min(100, $smsToday > 0 ? 100 : 4) ?>%; background: var(--so-series-orange);"></span></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="mini-stat-card">
            <div class="mini-stat-top">
                <span class="stat-panel-icon" style="background: rgba(20,184,166,.12); color: var(--so-series-teal);"><i class="ph ph-calendar-check"></i></span>
                <span class="mini-stat-pill">Ce mois</span>
            </div>
            <div class="mini-stat-value"><?= $smsThisMonth ?><span class="mini-stat-unit">SMS</span></div>
            <div class="mini-stat-label">Envoyés ce mois-ci</div>
            <div class="mini-stat-bar"><span style="width: <?= min(100, $smsThisMonth > 0 ? 100 : 4) ?>%; background: var(--so-series-teal);"></span></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="mini-stat-card">
            <div class="mini-stat-top">
                <span class="stat-panel-icon" style="background: rgba(124,58,237,.12); color: var(--so-series-violet);"><i class="ph ph-coins"></i></span>
                <span class="mini-stat-pill <?= $creditsBalance > 0 ? 'positive' : 'warning' ?>">Crédits</span>
            </div>
            <div class="mini-stat-value"><?= number_format($creditsBalance, 0, ',', ' ') ?></div>
            <div class="mini-stat-label">Solde de l'organisation</div>
            <a href="?page=credits" class="so-dashboard-link mt-2 d-inline-block">Voir les crédits <i class="ph ph-arrow-right"></i></a>
        </div>
    </div>
</div>

<div class="row g-3 mt-1">
    <div class="col-md-4">
        <div class="stat-panel">
            <div class="stat-panel-header">
                <div class="stat-panel-heading">
                    <span class="stat-panel-icon"><i class="ph ph-note-pencil"></i></span>
                    <div>
                        <p class="stat-panel-title">Modèles SMS</p>
                        <span class="stat-panel-subtitle"><?= $templatesTotal ?> au total</span>
                    </div>
                </div>
            </div>
            <div class="stat-donut-row">
                <div class="stat-donut-chart" style="width:96px;height:96px;" id="donut-modeles"></div>
                <div class="stat-legend">
                    <div class="stat-legend-row">
                        <span class="stat-legend-dot" style="background: var(--so-series-orange)"></span>
                        <span class="stat-legend-label">Actifs</span>
                        <span class="stat-legend-value"><?= $templatesActive ?></span>
                    </div>
                    <div class="stat-legend-row">
                        <span class="stat-legend-dot" style="background: #d7dee8"></span>
                        <span class="stat-legend-label">Archivés</span>
                        <span class="stat-legend-value"><?= max(0, $templatesTotal - $templatesActive) ?></span>
                    </div>
                </div>
            </div>
            <a href="?page=modeles" class="so-dashboard-link mt-3 d-inline-block">Voir les modèles <i class="ph ph-arrow-right"></i></a>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-panel">
            <div class="stat-panel-header">
                <div class="stat-panel-heading">
                    <span class="stat-panel-icon" style="background: rgba(20,184,166,.12); color: var(--so-series-teal);"><i class="ph ph-percent"></i></span>
                    <div>
                        <p class="stat-panel-title">Taux de réussite</p>
                        <span class="stat-panel-subtitle">Toutes campagnes</span>
                    </div>
                </div>
            </div>
            <div class="stat-donut-row">
                <div class="stat-donut-chart" style="width:96px;height:96px;" id="donut-taux"></div>
                <div class="stat-legend">
                    <div class="stat-legend-row">
                        <span class="stat-legend-dot" style="background: var(--so-series-teal)"></span>
                        <span class="stat-legend-label">Réussis</span>
                        <span class="stat-legend-value"><?= $globalStats['envoyes'] ?></span>
                    </div>
                    <div class="stat-legend-row">
                        <span class="stat-legend-dot" style="background: var(--so-series-rose)"></span>
                        <span class="stat-legend-label">Échoués</span>
                        <span class="stat-legend-value"><?= $globalStats['echecs'] ?></span>
                    </div>
                </div>
            </div>
            <a href="?page=rapports" class="so-dashboard-link mt-3 d-inline-block">Voir les rapports <i class="ph ph-arrow-right"></i></a>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-panel">
            <div class="stat-panel-header">
                <div class="stat-panel-heading">
                    <span class="stat-panel-icon" style="background: rgba(59,130,246,.12); color: var(--so-series-blue);"><i class="ph ph-activity"></i></span>
                    <div>
                        <p class="stat-panel-title">Campagnes actives</p>
                        <span class="stat-panel-subtitle">En cours en ce moment</span>
                    </div>
                </div>
            </div>
            <div class="mini-stat-value" style="margin-top:6px;"><?= $activeCampaigns ?></div>
            <div class="mini-stat-label">planifiée(s), en file ou en cours d'envoi</div>
            <a href="?page=campgagne" class="so-dashboard-link mt-3 d-inline-block">Voir les campagnes <i class="ph ph-arrow-right"></i></a>
        </div>
    </div>
</div>

<div class="row g-3 mt-1">
    <div class="col-sm-7">
        <div class="card">
            <div class="card-header">
                <h4>Évolution des SMS envoyés (14 derniers jours)</h4>
            </div>
            <div class="card-body">
                <div id="chart-evolution"></div>
            </div>
        </div>
    </div>
    <div class="col-sm-5">
        <div class="card">
            <div class="card-header">
                <h4>Performance des dernières campagnes</h4>
            </div>
            <div class="card-body">
                <div id="chart-performance"></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mt-1">
    <div class="col-sm-12">
        <div class="card">
            <div class="card-header">
                <h4>Évolution du taux de réussite (14 derniers jours)</h4>
            </div>
            <div class="card-body">
                <div id="chart-taux-reussite"></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mt-1">
    <div class="col-sm-8">
        <div class="card table-card">
            <div class="card-header">
                <h4>Campagnes récentes</h4>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table">
                        <tr>
                            <th>Nom</th>
                            <th>Statut</th>
                            <th>Destinataires</th>
                            <th>Créée le</th>
                        </tr>
                        <?php if (empty($recentCampagnes)): ?>
                        <tr><td colspan="4" class="text-center text-muted">Aucune campagne n'a encore été créée.</td></tr>
                        <?php else: foreach ($recentCampagnes as $c): ?>
                        <tr>
                            <td><?= htmlspecialchars($c['nom']) ?></td>
                            <td><?= htmlspecialchars($c['statut']) ?></td>
                            <td><?= (int) ($c['total_destinataires'] ?? 0) ?></td>
                            <td><?= htmlspecialchars($c['date_creation']) ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card card-default">
            <div class="card-header">
                <h3 class="card-title">Envoi rapide</h3>
            </div>
            <div class="card-body">
                <div class="row">
                    <form action="../server/app.php" method="post">
                        <?= csrf_field() ?>
                        <div class="form-group mb-3">
                            <label for="number" class="visually-hidden">Numéro de téléphone</label>
                            <input type="text" class="form-control" name="number" id="number" required value="+224">
                            <div class="form-text">Numéro ou expéditeur validé chez Orange</div>
                        </div>
                        <div class="form-group mb-3">
                            <label for="message" class="visually-hidden">Message</label>
                            <textarea class="form-control border-0 bg-transparent" name="message" id="message" rows="4" placeholder="Tapez votre message..."></textarea>
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

<div class="row g-3 mt-1">
    <div class="col-sm-12">
        <div class="so-hero-donut-card">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                    <p class="stat-panel-title mb-0">Répartition des SMS</p>
                    <span class="stat-panel-subtitle">Toutes campagnes, toutes périodes</span>
                </div>
                <div class="text-end">
                    <div class="so-hero-donut-total"><?= number_format($globalStats['envoyes'] + $globalStats['echecs'] + $globalStats['en_attente'], 0, ',', ' ') ?></div>
                    <div class="so-hero-donut-total-label">SMS au total</div>
                </div>
            </div>
            <div class="row align-items-center mt-3">
                <div class="col-md-5">
                    <div id="chart-repartition"></div>
                </div>
                <div class="col-md-7">
                    <div class="stat-legend-row">
                        <span class="stat-legend-dot" style="background: var(--so-series-teal)"></span>
                        <span class="stat-legend-label">Envoyés</span>
                        <span class="stat-legend-value"><?= $globalStats['envoyes'] ?></span>
                    </div>
                    <div class="stat-legend-row">
                        <span class="stat-legend-dot" style="background: var(--so-series-rose)"></span>
                        <span class="stat-legend-label">Échecs</span>
                        <span class="stat-legend-value"><?= $globalStats['echecs'] ?></span>
                    </div>
                    <div class="stat-legend-row">
                        <span class="stat-legend-dot" style="background: #f0ad4e"></span>
                        <span class="stat-legend-label">En attente</span>
                        <span class="stat-legend-value"><?= $globalStats['en_attente'] ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Les <script src="...apexcharts.min.js"> sont chargés dans le pied de page,
// APRÈS ce bloc inline dans le flux du document — sans ce report, ApexCharts
// n'est pas encore défini au moment où ce script s'exécute (erreur
// "ApexCharts is not defined").
document.addEventListener('DOMContentLoaded', function () {
    const donutOpts = (series, labels, colors, size) => ({
        chart: { type: 'donut', height: size, width: size },
        series: series,
        labels: labels,
        colors: colors,
        legend: { show: false },
        dataLabels: { enabled: false },
        plotOptions: { pie: { donut: { size: '72%' } } },
        stroke: { width: 0 }
    });

    // Sans donnée réelle (organisation toute neuve), une série entièrement à
    // zéro dessine un anneau dégénéré (un seul segment gris, aucun repère
    // visuel de "vide") plutôt que de déclencher l'option noData d'ApexCharts
    // (qui ne se déclenche pas pour une série [0,0,0] — seulement pour une
    // série absente/vide) : testé en conditions réelles sur une organisation
    // fraîchement créée. On affiche donc nous-mêmes un espace réservé simple
    // quand la somme de la série est nulle, plutôt que de laisser croire à un
    // graphique cassé.
    function renderDonut(selector, series, labels, colors, size) {
        const el = document.querySelector(selector);
        if (!el) return;
        const total = series.reduce((a, b) => a + b, 0);
        if (total === 0) {
            el.innerHTML = '<div class="d-flex align-items-center justify-content-center text-center text-muted small" style="width:' + size + 'px;height:' + size + 'px;border-radius:50%;border:10px solid #f1f2f6;margin:0 auto;">Aucune<br>donnée</div>';
            return;
        }
        new ApexCharts(el, donutOpts(series, labels, colors, size)).render();
    }

    renderDonut(
        "#donut-campagnes",
        [<?= $campaignBreakdown['actives'] ?>, <?= $campaignBreakdown['terminees'] ?>, <?= $campaignBreakdown['brouillons'] ?>],
        ['Actives', 'Terminées', 'Brouillons'],
        ['#7c3aed', '#14b8a6', '#d7dee8'],
        130
    );

    renderDonut(
        "#donut-contacts",
        [<?= $contactsByGroup['with_group'] ?>, <?= $contactsByGroup['without_group'] ?>],
        ['Dans un groupe', 'Sans groupe'],
        ['#ff7900', '#3b82f6'],
        130
    );

    renderDonut("#donut-modeles", [<?= $templatesActive ?>, <?= max(0, $templatesTotal - $templatesActive) ?>], ['Actifs', 'Archivés'], ['#ff7900', '#d7dee8'], 96);

    renderDonut("#donut-taux", [<?= $globalStats['envoyes'] ?>, <?= $globalStats['echecs'] ?>], ['Réussis', 'Échoués'], ['#14b8a6', '#f43f5e'], 96);

    const evolution = <?= json_encode($evolution) ?>;
    new ApexCharts(document.querySelector("#chart-evolution"), {
        chart: { type: 'line', height: 260, toolbar: { show: false } },
        series: [{ name: 'SMS envoyés', data: Object.values(evolution) }],
        xaxis: { categories: Object.keys(evolution) },
        colors: ['#ff7900'],
        stroke: { curve: 'smooth', width: 3 },
        dataLabels: { enabled: false }
    }).render();

    renderDonut(
        "#chart-repartition",
        [<?= $globalStats['envoyes'] ?>, <?= $globalStats['echecs'] ?>, <?= $globalStats['en_attente'] ?>],
        ['Envoyés', 'Échecs', 'En attente'],
        ['#14b8a6', '#f43f5e', '#f0ad4e'],
        220
    );

    const perf = <?= json_encode($performance) ?>;
    new ApexCharts(document.querySelector("#chart-performance"), {
        chart: { type: 'bar', height: 280, toolbar: { show: false } },
        series: [
            { name: 'Réussis', data: perf.map(p => parseInt(p.nombre_envoyes || 0)) },
            { name: 'Échecs', data: perf.map(p => parseInt(p.nombre_echecs || 0)) }
        ],
        xaxis: { categories: perf.map(p => p.nom) },
        colors: ['#14b8a6', '#f43f5e'],
        plotOptions: { bar: { horizontal: false, columnWidth: '45%' } },
        dataLabels: { enabled: false }
    }).render();

    const successRate = <?= json_encode($successRateEvolution) ?>;
    new ApexCharts(document.querySelector("#chart-taux-reussite"), {
        chart: { type: 'line', height: 260, toolbar: { show: false } },
        series: [{ name: 'Taux de réussite (%)', data: Object.values(successRate) }],
        xaxis: { categories: Object.keys(successRate) },
        yaxis: { min: 0, max: 100, labels: { formatter: v => v + '%' } },
        colors: ['#ff7900'],
        stroke: { curve: 'smooth', width: 3 },
        connectNulls: false,
        dataLabels: { enabled: false }
    }).render();
});
</script>
