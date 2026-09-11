<?php
require_once __DIR__ . '/../config/services.php';
auth()->requireLogin('login.php');
require_once('../server/infosAPI.php');
?>
<!doctype html>
<html lang="en">
<!-- [Head] start -->

<head>
    <title>SMS_ORANGE — Tableau de bord</title>
    <!-- [Meta] -->
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="description" content="SMS_ORANGE — plateforme d'envoi de campagnes SMS et messages administratifs." />
    <meta name="author" content="UGLC-SC" />

    <!-- [Favicon] icon -->
    <link rel="icon" href="../assets/images/favicon.svg" type="image/x-icon" />

    <!-- [Google Font : Poppins] icon -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet" />

    <!-- [Tabler Icons] https://tablericons.com -->
    <link rel="stylesheet" href="../assets/fonts/tabler-icons.min.css" />
    <!-- [Feather Icons] https://feathericons.com -->
    <link rel="stylesheet" href="../assets/fonts/feather.css" />
    <!-- [Font Awesome Icons] https://fontawesome.com/icons -->
    <link rel="stylesheet" href="../assets/fonts/fontawesome.css" />
    <!-- [Material Icons] https://fonts.google.com/icons -->
    <link rel="stylesheet" href="../assets/fonts/material.css" />
    <!-- [Template CSS Files] -->
    <link rel="stylesheet" href="../assets/css/style.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/style-preset.css" />
    <link rel="stylesheet" href="../assets/css/sms-orange-overrides.css" />
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.5/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">
<link rel="stylesheet" href="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/css/alertify.min.css"/>
<link rel="stylesheet" href="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/css/themes/bootstrap.min.css"/>

</head>
<!-- [Head] end -->
<!-- [Body] Start -->

<body data-pc-header="header-1" data-pc-preset="preset-6" data-pc-sidebar-theme="light" data-pc-sidebar-caption="true"
    data-pc-direction="ltr" data-pc-theme="light">
    <!-- [ Pre-loader ] start -->
    <div class="loader-bg">
        <div class="loader-track">
            <div class="loader-fill"></div>
        </div>
    </div>
    <!-- [ Pre-loader ] End -->
    <!-- [ Sidebar Menu ] start -->
    <nav class="pc-sidebar">
        <div class="navbar-wrapper">
            <div class="m-header">
                <a href="index.php?page=dashdoards" class="b-brand text-primary">
                    <span class="fw-bold fs-4">SMS<span class="text-dark text-opacity-75">_ORANGE</span></span>
                </a>
            </div>
            <div class="navbar-content">
                <ul class="pc-navbar">
                    <li class="pc-item pc-caption">
                        <label>Navigation</label>
                    </li>
                    <li class="pc-item">
                        <a href="?page=dashdoards" class="pc-link"><span class="pc-micon"> <i
                                    class="ph ph-gauge"></i></span><span class="pc-mtext">Accueil</span></a>
                    </li>

                    <li class="pc-item pc-caption">
                        <label>Résultats académiques</label>
                        <i class="ph ph-graduation-cap"></i>
                    </li>
                    <li class="pc-item"><a href="?page=resultats" class="pc-link">
                            <span class="pc-micon">
                                <i class="ph ph-graduation-cap"></i>
                            </span>
                            <span class="pc-mtext">Envoyer les résultats</span></a></li>

                    <li class="pc-item pc-caption">
                        <label>Gestion des Messages</label>
                        <i class="ph ph-suitcase"></i>
                    </li>
                    <li class="pc-item"><a href="?page=campgagne" class="pc-link">
                            <span class="pc-micon">
                                <i class="ph ph-desktop"></i>
                            </span>
                            <span class="pc-mtext">Créer une campagne</span></a></li>

                    <li class="pc-item pc-hasmenu">
                        <a href="?page=sms-sender" class="pc-link"><span class="pc-micon"> <i class="ph ph-tree-structure"></i> </span><span
                                class="pc-mtext">Liste des Messages</span></a>
                    </li>
                    <li class="pc-item"><a href="?page=modeles" class="pc-link">
                            <span class="pc-micon">
                                <i class="ph ph-note-pencil"></i>
                            </span>
                            <span class="pc-mtext">Modèles SMS</span></a></li>
                    <li class="pc-item pc-caption">
                        <label>Rapport</label>
                    </li>
                    <li class="pc-item">
                        <a href="?page=rapports" class="pc-link"><span class="pc-micon"> <i class="ph ph-gauge"></i></span><span
                                class="pc-mtext">Rapport / Statistique</span></a>
                    </li>
                    <li class="pc-item">
                        <a href="?page=sms-history" class="pc-link"><span class="pc-micon"> <i class="ph ph-clock-counter-clockwise"></i></span><span
                                class="pc-mtext">Historique SMS (Orange)</span></a>
                    </li>
                    <li class="pc-item">
                        <a href="?page=journal" class="pc-link"><span class="pc-micon"> <i class="ph ph-list-checks"></i></span><span
                                class="pc-mtext">Journal d'activité</span></a>
                    </li>
                    <li class="pc-item">
                        <a href="health.php" class="pc-link"><span class="pc-micon"> <i class="ph ph-heartbeat"></i></span><span
                                class="pc-mtext">État du système</span></a>
                    </li>
                </ul>
            </div>

        </div>
    </nav>
    <!-- [ Sidebar Menu ] end -->
    <!-- [ Header Topbar ] start -->
    <header class="pc-header">
        <div class="m-header">
            <a href="index.php?page=dashdoards" class="b-brand">
                <span class="fw-bold fs-4 text-white">SMS_ORANGE</span>
            </a>
        </div>
        <div class="header-wrapper"> <!-- [Mobile Media Block] start -->
            <div class="me-auto pc-mob-drp">
                <ul class="list-unstyled">
                    <!-- ======= Menu collapse Icon ===== -->
                    <li class="pc-h-item pc-sidebar-collapse">
                        <a href="#" class="pc-head-link ms-0" id="sidebar-hide">
                            <i class="ph ph-list"></i>
                        </a>
                    </li>
                    <li class="pc-h-item pc-sidebar-popup">
                        <a href="#" class="pc-head-link ms-0" id="mobile-collapse">
                            <i class="ph ph-list"></i>
                        </a>
                    </li>
                    <li class="dropdown pc-h-item">
                        <a class="pc-head-link dropdown-toggle arrow-none m-0" data-bs-toggle="dropdown" href="#" role="button"
                            aria-haspopup="false" aria-expanded="false">
                            <i class="ph ph-magnifying-glass"></i>
                        </a>
                        <div class="dropdown-menu pc-h-dropdown drp-search">
                            <form class="px-3">
                                <div class="form-group mb-0 d-flex align-items-center">
                                    <label for="header-search" class="visually-hidden">Rechercher</label>
                                    <input type="search" id="header-search" class="form-control border-0 shadow-none" placeholder="Search here. . ." />
                                    <button type="submit" class="btn btn-light-secondary btn-search">Search</button>
                                </div>
                            </form>
                        </div>
                    </li>
                </ul>
            </div>
            <!-- [Mobile Media Block end] -->
            <div class="ms-auto">
                <ul class="list-unstyled">
                    <li class="dropdown pc-h-item header-user-profile">
                        <a class="pc-head-link dropdown-toggle arrow-none me-0" data-bs-toggle="dropdown" href="#" role="button"
                            aria-haspopup="false" data-bs-auto-close="outside" aria-expanded="false">
                            <img src="../assets/images/user/avatar-2.jpg" alt="user-image" class="user-avtar" />
                        </a>
                        <div class="dropdown-menu dropdown-user-profile dropdown-menu-end pc-h-dropdown">
                            <div class="dropdown-body">
                                <div class="profile-notification-scroll position-relative" style="max-height: calc(100vh - 225px)">
                                    <ul class="list-group list-group-flush w-100">
                                        <li class="list-group-item">
                                            <span class="d-flex align-items-center">
                                                <i class="ph ph-user-circle"></i>
                                                <span><?= htmlspecialchars(auth()->user()['nom'] ?? '') ?> — <?= htmlspecialchars(auth()->user()['role'] ?? '') ?></span>
                                            </span>
                                        </li>
                                        <li class="list-group-item">
                                            <a href="logout.php" class="dropdown-item">
                                                <span class="d-flex align-items-center">
                                                    <i class="ph ph-power"></i>
                                                    <span>Déconnexion</span>
                                                </span>
                                            </a>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </header>
    <!-- [ Header ] end -->



    <!-- [ Main Content ] start -->
    <div class="pc-container">
        <div class="pc-content">
            <!-- [ Main Content ] start -->
            <div class="row">
                <div class="col-md-6 col-xl-3">
                    <div class="card bg-grd-primary order-card">
                        <div class="card-body">
                            <h6 class="text-white">SMS envoyé</h6>
                            <h2 class="text-end text-white"><i class="feather icon-shopping-cart float-start"></i><span><?php echo ($_SESSION['totalSmsSend'] ?? '—') ?></span>
                            </h2>

                        </div>
                    </div>
                </div>
                <?php $globalStats = getGlobalSmsStats(); ?>
                <div class="col-md-6 col-xl-3">
                    <div class="card bg-grd-success order-card">
                        <div class="card-body">
                            <h6 class="text-white">SMS envoyés (SMS_ORANGE)</h6>
                            <h2 class="text-end text-white"><i class="feather icon-tag float-start"></i><span><?= $globalStats['envoyes'] ?></span> </h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="card bg-grd-warning order-card">
                        <div class="card-body">
                            <h6 class="text-white">SMS échoués</h6>
                            <h2 class="text-end text-white"><i class="feather icon-repeat float-start"></i><span><?= $globalStats['echecs'] ?></span></h2>

                        </div>
                    </div>
                </div>

                <div class="col-md-6 col-xl-3">
                    <div class="card bg-grd-danger order-card">
                        <div class="card-body">
                            <h6 class="text-white">Taux de réussite</h6>
                            <h2 class="text-end text-white"><i class="feather icon-award float-start"></i><span><?= $globalStats['taux_reussite'] ?>%</span></h2>
                        </div>
                    </div>
                </div>
                <!-- Recent Orders start -->
            </div>
           <div class="row mb-1 mt-1">
             <?php if (!empty($_SESSION['message'])) {  ?>
                <div class='<?php echo ($_SESSION['class']) ?>  alert-dismissible fade show' role='alert'>
                    <?php echo ($_SESSION['message']) ?>
                    <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
                </div>
            <?php $_SESSION['message'] = "";
            }  ?>
           </div>
            <!-- [ Main Content ] end -->
            <?php
            if (isset($_GET['page'])) {
                if ($_GET['page'] == "dashdoards") {
                    require_once('./templete/dashboard.php');
                } else   if ($_GET['page'] == "resultats") {
                    require_once('./templete/resultats.php');
                } else   if ($_GET['page'] == "campgagne") {
                     if (isset($_GET['details'])) {
                        require_once('./templete/detail-campagne.php');
                    } else {
                         require_once('./templete/campagne.php');
                    }
                }else   if ($_GET['page'] == "sms-sender") {
                    require_once('./templete/sms-sender.php');
                }else   if ($_GET['page'] == "rapports") {
                    require_once('./templete/rapports.php');
                }else   if ($_GET['page'] == "sms-history") {
                    require_once('./templete/sms-history.php');
                }else   if ($_GET['page'] == "journal") {
                    require_once('./templete/journal.php');
                }else   if ($_GET['page'] == "modeles") {
                    require_once('./templete/modeles.php');
                } else {
                    require_once('./templete/404.php');
                }
            } else {
                require_once('./templete/404.php');
            }



            ?>
            <hr>
            <div class="row">
                <div class="col-md-4 col-sm-6">
                    <div class="card statistics-card-1">
                        <div class="card-body">
                            <img src="../assets/images/widget/img-status-4.svg" alt="img" class="img-fluid img-bg" />
                            <div class="d-flex align-items-center justify-content-between mb-3 drp-div">
                                <h3 class="f-w-300 d-flex align-items-center m-b-0"><?php echo ($_SESSION['soldeSms'] ?? '—') ?></h3>
                            </div>
                            <div class="d-flex align-items-center mt-3">
                                <h6 class="mb-0">SMS disponible</h6>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 col-sm-6">
                    <div class="card statistics-card-1">
                        <div class="card-body">
                            <img src="../assets/images/widget/img-status-4.svg" alt="img" class="img-fluid img-bg" />
                            <div class="d-flex align-items-center justify-content-between mb-3 drp-div">
                                <h3 class="f-w-300 d-flex align-items-center m-b-0"><?php echo ($_SESSION['dateExpiration'] ?? '—') ?></h3>
                            </div>
                            <div class="d-flex align-items-center mt-3">
                                <h6 class="mb-0">Date expiration</h6>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 col-sm-6">
                    <div class="card statistics-card-1">
                        <div class="card-body">
                            <img src="../assets/images/widget/img-status-4.svg" alt="img" class="img-fluid img-bg" />
                            <div class="d-flex align-items-center justify-content-between mb-3 drp-div">
                                <h3 class="f-w-300 d-flex align-items-center m-b-0"><span class="text-success"><?php echo ($_SESSION['status'] ?? '—') ?></span></h3>
                            </div>
                            <div class="d-flex align-items-center mt-3">
                                <h6 class="mb-0">status</h6>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
    <!-- [ Main Content ] end -->
    <footer class="pc-footer">
        <div class="footer-wrapper container-fluid">
            <div class="row">
                <div class="col-sm-6 my-1">
                    <p class="m-0">SMS_ORANGE — UGLC-SC</p>
                </div>
                <div class="col-sm-6 ms-auto my-1">
                    <ul class="list-inline footer-link mb-0 justify-content-sm-end d-flex">
                        <li class="list-inline-item"><a href="health.php">État du système</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </footer>

    <!-- [Page Specific JS] start -->
    <script src="../assets/js/plugins/apexcharts.min.js"></script>
    <!-- [Page Specific JS] end -->
    <!-- Required Js -->
    <script src="../assets/js/plugins/popper.min.js"></script>
    <script src="../assets/js/plugins/simplebar.min.js"></script>
    <script src="../assets/js/plugins/bootstrap.min.js"></script>
    <script src="../assets/js/fonts/custom-font.js"></script>
    <script src="../assets/js/script.js"></script>
    <script src="../assets/js/theme.js"></script>
    <script src="../assets/js/plugins/feather.min.js"></script>



    <!-- JS jQuery + DataTables + Buttons -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.5/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.5/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
<script src="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/alertify.min.js"></script>
<script src="../assets/js/ajax.js"></script>

<!-- Initialisation DataTable -->
<script>
$(document).ready(function() {
    // Traductions en dur : le fichier i18n distant (cdn.datatables.net/plug-ins/.../fr-FR.json)
    // est bloqué par CORS depuis certains environnements et fait planter l'initialisation
    // de DataTables (erreur "_DT_CellIndex") — voir AUDIT.md pour le détail du bug.
    var dataTableFrFR = {
        "sEmptyTable": "Aucune donnée disponible dans le tableau",
        "sInfo": "Affichage de l'élément _START_ à _END_ sur _TOTAL_ éléments",
        "sInfoEmpty": "Affichage de l'élément 0 à 0 sur 0 élément",
        "sInfoFiltered": "(filtré à partir de _MAX_ éléments au total)",
        "sInfoPostFix": "",
        "sInfoThousands": " ",
        "sLengthMenu": "Afficher _MENU_ éléments",
        "sLoadingRecords": "Chargement...",
        "sProcessing": "Traitement...",
        "sSearch": "Rechercher :",
        "sZeroRecords": "Aucun élément correspondant trouvé",
        "oPaginate": {
            "sFirst": "Premier",
            "sLast": "Dernier",
            "sNext": "Suivant",
            "sPrevious": "Précédent"
        },
        "oAria": {
            "sSortAscending": ": activer pour trier la colonne par ordre croissant",
            "sSortDescending": ": activer pour trier la colonne par ordre décroissant"
        }
    };

    if ($('#groupesTable').length) {
        $('#groupesTable').DataTable({
            dom: 'Bfrtip', // bouton au-dessus du tableau
            buttons: [
                {
                    extend: 'csvHtml5',
                    text: 'Exporter CSV',
                    className: 'btn btn-success m-1'
                },
                {
                    extend: 'excelHtml5',
                    text: 'Exporter Excel',
                    className: 'btn btn-success m-1'
                },
                {
                    extend: 'pdfHtml5',
                    text: 'Exporter PDF',
                    className: 'btn btn-danger m-1'
                },
                {
                    extend: 'print',
                    text: 'Imprimer',
                    className: 'btn btn-primary m-1'
                }
            ],
            language: dataTableFrFR
        });
    }
});
</script>


</body>
<!-- [Body] end -->

</html>