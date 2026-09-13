<?php
$orgId = auth()->organizationId();
$evolution = getSmsEvolution($orgId, 14);
$globalStats = getGlobalSmsStats($orgId);
$performance = getCampaignPerformance($orgId, 6);
$successRateEvolution = getSuccessRateEvolution($orgId, 14);
$recentCampagnes = array_slice(getCampagne($orgId), 0, 5);
$smsToday = getSmsSentToday($orgId);
$smsThisMonth = getSmsSentThisMonth($orgId);
$activeCampaigns = getActiveCampaignsCount($orgId);
?>
<div class="row">
    <div class="col-md-6 col-xl-3">
        <div class="card bg-grd-primary order-card">
            <div class="card-body">
                <h6 class="text-white">SMS envoyés aujourd'hui</h6>
                <h2 class="text-end text-white"><i class="feather icon-send float-start"></i><span><?= $smsToday ?></span></h2>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card bg-grd-success order-card">
            <div class="card-body">
                <h6 class="text-white">SMS envoyés ce mois</h6>
                <h2 class="text-end text-white"><i class="feather icon-calendar float-start"></i><span><?= $smsThisMonth ?></span></h2>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card bg-grd-warning order-card">
            <div class="card-body">
                <h6 class="text-white">Campagnes actives</h6>
                <h2 class="text-end text-white"><i class="feather icon-activity float-start"></i><span><?= $activeCampaigns ?></span></h2>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card bg-grd-danger order-card">
            <div class="card-body">
                <h6 class="text-white">Solde SMS restant</h6>
                <h2 class="text-end text-white"><i class="feather icon-credit-card float-start"></i><span><?= htmlspecialchars($_SESSION['soldeSms'] ?? '—') ?></span></h2>
            </div>
        </div>
    </div>
</div>

<div class="row">
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
                <h4>Répartition des SMS</h4>
            </div>
            <div class="card-body">
                <div id="chart-repartition"></div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-sm-7">
        <div class="card">
            <div class="card-header">
                <h4>Performance des dernières campagnes</h4>
            </div>
            <div class="card-body">
                <div id="chart-performance"></div>
            </div>
        </div>
    </div>
    <div class="col-sm-5">
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

<div class="row">
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

<script>
// Les <script src="...apexcharts.min.js"> sont chargés dans le pied de page,
// APRÈS ce bloc inline dans le flux du document — sans ce report, ApexCharts
// n'est pas encore défini au moment où ce script s'exécute (erreur
// "ApexCharts is not defined").
document.addEventListener('DOMContentLoaded', function () {
    const evolution = <?= json_encode($evolution) ?>;
    new ApexCharts(document.querySelector("#chart-evolution"), {
        chart: { type: 'line', height: 260, toolbar: { show: false } },
        series: [{ name: 'SMS envoyés', data: Object.values(evolution) }],
        xaxis: { categories: Object.keys(evolution) },
        colors: ['#ff7900'],
        stroke: { curve: 'smooth', width: 3 },
        dataLabels: { enabled: false }
    }).render();

    new ApexCharts(document.querySelector("#chart-repartition"), {
        chart: { type: 'donut', height: 260 },
        series: [<?= $globalStats['envoyes'] ?>, <?= $globalStats['echecs'] ?>, <?= $globalStats['en_attente'] ?>],
        labels: ['Envoyés', 'Échecs', 'En attente'],
        colors: ['#2ca87f', '#e63757', '#f0ad4e'],
        legend: { position: 'bottom' }
    }).render();

    const perf = <?= json_encode($performance) ?>;
    new ApexCharts(document.querySelector("#chart-performance"), {
        chart: { type: 'bar', height: 280, toolbar: { show: false } },
        series: [
            { name: 'Réussis', data: perf.map(p => parseInt(p.nombre_envoyes || 0)) },
            { name: 'Échecs', data: perf.map(p => parseInt(p.nombre_echecs || 0)) }
        ],
        xaxis: { categories: perf.map(p => p.nom) },
        colors: ['#2ca87f', '#e63757'],
        plotOptions: { bar: { horizontal: false, columnWidth: '45%' } },
        dataLabels: { enabled: false }
    }).render();

    const successRate = <?= json_encode($successRateEvolution) ?>;
    new ApexCharts(document.querySelector("#chart-taux-reussite"), {
        chart: { type: 'line', height: 260, toolbar: { show: false } },
        series: [{ name: 'Taux de réussite (%)', data: Object.values(successRate) }],
        xaxis: { categories: Object.keys(successRate) },
        yaxis: { min: 0, max: 100, labels: { formatter: v => v + '%' } },
        colors: ['#1e88e5'],
        stroke: { curve: 'smooth', width: 3 },
        connectNulls: false,
        dataLabels: { enabled: false }
    }).render();
});
</script>
