<?php require_once('../server/infosAPI.php'); ?>
<!doctype html>
<html lang="en">
<!-- [Head] start -->

<head>
    <title>Home | Gradient Able Dashboard Template</title>
    <!-- [Meta] -->
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="description"
        content="Gradient Able is trending dashboard template made using Bootstrap 5 design framework. Gradient Able is available in Bootstrap, React, CodeIgniter, Angular,  and .net Technologies." />
    <meta name="keywords"
        content="Bootstrap admin template, Dashboard UI Kit, Dashboard Template, Backend Panel, react dashboard, angular dashboard" />
    <meta name="author" content="codedthemes" />

    <!-- [Favicon] icon -->
    <link rel="icon" href="../assets/images/favicon.svg" type="image/x-icon" />

    <!-- map-vector css -->
    <link rel="stylesheet" href="../assets/css/plugins/jsvectormap.min.css" />
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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.5/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">
<link rel="stylesheet" href="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/css/alertify.min.css"/>
<link rel="stylesheet" href="//cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/css/themes/bootstrap.min.css"/>

</head>
<!-- [Head] end -->
<!-- [Body] Start -->

<body data-pc-header="header-1" data-pc-preset="preset-1" data-pc-sidebar-theme="light" data-pc-sidebar-caption="true"
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
                <a href="../dashboard/index.html" class="b-brand text-primary">
                    <!-- ========   Change your logo from here   ============ -->
                    <img src="../assets/images/logo-white.svg" alt="logo image" class="logo-lg" />
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
                        <label>Gestion des contacts</label>
                        <i class="ph ph-compass-tool"></i>
                    </li>
                    <li class="pc-item">
                        <a href="?page=Groupes" class="pc-link">
                            <span class="pc-micon"><i class="ph ph-text-aa"></i></span>
                            <span class="pc-mtext">Gestion des Groupes</span>
                        </a>
                    </li>
                    <li class="pc-item">
                        <a href="?page=Contacts" class="pc-link">
                            <span class="pc-micon"><i class="ph ph-palette"></i></span>
                            <span class="pc-mtext">Liste des Contacts</span>
                        </a>
                    </li>
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
                    <li class="pc-item pc-caption">
                        <label>Rapport</label>
                    </li>
                    <li class="pc-item">
                        <a href="?page=rapports" class="pc-link"><span class="pc-micon"> <i class="ph ph-gauge"></i></span><span
                                class="pc-mtext">Rapport / Statistique</span></a>
                    </li>
                     <li class="pc-item pc-hasmenu">
                        <a href="?page=notes" class="pc-link"><span class="pc-micon"> <i class="ph ph-tree-structure"></i> </span><span
                                class="pc-mtext">Liste des Notes</span></a>
                    </li>
                </ul>
                <div class="card nav-action-card bg-brand-color-1">
                    <div class="card-body" style="background-image: url('../assets/images/layout/nav-card-bg.svg')">
                        <h5 class="text-white">Upgrade to Pro</h5>
                        <p class="text-white text-opacity-75">To get more features and components</p>
                        <a href="https://developer.orange.com" class="btn btn-light"
                            target="_blank">Buy now</a>
                    </div>
                </div>

            </div>

        </div>
    </nav>
    <!-- [ Sidebar Menu ] end -->
    <!-- [ Header Topbar ] start -->
    <header class="pc-header">
        <div class="m-header">
            <a href="../dashboard/index.html" class="b-brand text-primary">
                <!-- ========   Change your logo from here   ============ -->
                <img src="../assets/images/logo-white.svg" alt="logo image" class="logo-lg" />
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
                                    <input type="search" class="form-control border-0 shadow-none" placeholder="Search here. . ." />
                                    <button class="btn btn-light-secondary btn-search">Search</button>
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
                                            <a href="https://codedthemes.com/item/gradient-able-admin-template/" target="_blank"
                                                class="dropdown-item">
                                                <span class="d-flex align-items-center">
                                                    <i class="ph ph-arrow-circle-down"></i>
                                                    <span>Download</span>
                                                </span>
                                            </a>
                                        </li>
                                        <li class="list-group-item">
                                            <a href="#" class="dropdown-item">
                                                <span class="d-flex align-items-center">
                                                    <i class="ph ph-user-circle"></i>
                                                    <span>Edit profile</span>
                                                </span>
                                            </a>
                                            <a href="#" class="dropdown-item">
                                                <span class="d-flex align-items-center">
                                                    <i class="ph ph-bell"></i>
                                                    <span>Notifications</span>
                                                </span>
                                            </a>
                                            <a href="#" class="dropdown-item">
                                                <span class="d-flex align-items-center">
                                                    <i class="ph ph-gear-six"></i>
                                                    <span>Settings</span>
                                                </span>
                                            </a>
                                        </li>
                                        <li class="list-group-item">
                                            <a href="#" class="dropdown-item">
                                                <span class="d-flex align-items-center">
                                                    <i class="ph ph-plus-circle"></i>
                                                    <span>Add account</span>
                                                </span>
                                            </a>
                                            <a href="#" class="dropdown-item">
                                                <span class="d-flex align-items-center">
                                                    <i class="ph ph-power"></i>
                                                    <span>Logout</span>
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
                            <h2 class="text-end text-white"><i class="feather icon-shopping-cart float-start"></i><span><?php echo ($_SESSION['totalSmsSend']) ?></span>
                            </h2>

                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="card bg-grd-success order-card">
                        <div class="card-body">
                            <h6 class="text-white">SMS Livré</h6>
                            <h2 class="text-end text-white"><i class="feather icon-tag float-start"></i><span>1641</span> </h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="card bg-grd-warning order-card">
                        <div class="card-body">
                            <h6 class="text-white">SMS non livré</h6>
                            <h2 class="text-end text-white"><i class="feather icon-repeat float-start"></i><span>562</span></h2>

                        </div>
                    </div>
                </div>

                <div class="col-md-6 col-xl-3">
                    <div class="card bg-grd-danger order-card">
                        <div class="card-body">
                            <h6 class="text-white">Taux de réussite</h6>
                            <h2 class="text-end text-white"><i class="feather icon-award float-start"></i><span>562</span></h2>
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
                } else if ($_GET['page'] == "Groupes") {
                    if (isset($_GET['details'])) {
                        require_once('./templete/detail-groupe.php');
                    } else {
                        require_once('./templete/groupe.php');
                    }
                } else   if ($_GET['page'] == "campgagne") {
                     if (isset($_GET['details'])) {
                        require_once('./templete/detail-campagne.php');
                    } else {
                         require_once('./templete/campagne.php');
                    }
                }else   if ($_GET['page'] == "Contacts") {
                    require_once('./templete/contacts.php');
                }else   if ($_GET['page'] == "notes") {
                    require_once('./templete/sendMarksheets.php');
                }else   if ($_GET['page'] == "sms-sender") {
                    require_once('./templete/sms-sender.php');
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
                                <h3 class="f-w-300 d-flex align-items-center m-b-0"><?php echo ($_SESSION['soldeSms']) ?></h3>
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
                                <h3 class="f-w-300 d-flex align-items-center m-b-0"><?php echo ($_SESSION['dateExpiration']) ?></h3>
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
                                <h3 class="f-w-300 d-flex align-items-center m-b-0"><span class="text-success"><?php echo ($_SESSION['status']) ?></span></h3>
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
                    <p class="m-0">Gradient Able &#9829; crafted by Team <a href="https://codedthemes.com/"
                            target="_blank">Codedthemes</a></p>
                </div>
                <div class="col-sm-6 ms-auto my-1">
                    <ul class="list-inline footer-link mb-0 justify-content-sm-end d-flex">
                        <li class="list-inline-item"><a href="../index.html">Home</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </footer>

    <!-- [Page Specific JS] start -->
    <script src="../assets/js/plugins/apexcharts.min.js"></script>
    <script src="../assets/js/plugins/jsvectormap.min.js"></script>
    <script src="../assets/js/plugins/world.js"></script>
    <script src="../assets/js/plugins/world-merc.js"></script>
    <script src="../assets/js/pages/dashboard-sales.js"></script>
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
        language: {
            url: '//cdn.datatables.net/plug-ins/1.13.5/i18n/fr-FR.json'
        }
    });
});
</script>


</body>
<!-- [Body] end -->

</html>