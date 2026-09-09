<?php
require_once('./config.php');
session_start();
auth()->requireLogin('../app/login.php');
// app.php ne fait que des mutations (création/import/envoi) — un VIEWER (lecture
// seule, §38) ne doit jamais pouvoir déclencher un envoi de SMS ou une suppression.
if (!auth()->hasRole(['SUPER_ADMIN', 'ADMIN', 'OPERATOR'])) {
    http_response_code(403);
    exit('Accès refusé : votre rôle ne permet pas cette action.');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    http_response_code(419);
    exit('Session expirée ou requête invalide, veuillez recharger la page et réessayer.');
}
$_SESSION['class'] = "";
$_SESSION['message'] = "";

if (isset($_POST['single-sender'])) {
    $pattern = "/^(\+224|00224)6\d{8}$/";
    $numero = trim($_POST['number']);
    $message = trim($_POST['message']);
    if ($numero != "" && $message != "") {

        if (preg_match($pattern, $numero)) {
            try {
                orangeSms()->sendSms($numero, $message);
            } catch (Exception $e) {
                $_SESSION['class'] = "alert alert-danger";
                $_SESSION['message'] = "❌ Erreur d'envoi : " . $e->getMessage();
                redirectBack();
                exit;
            }

            $_SESSION['class'] = "alert alert-success";
            $_SESSION['message'] = "✅ Message envoyé au {$numero} avec succéess .";
            redirectBack();
            exit;
        } else {
            $_SESSION['class'] = "alert alert-danger";
            $_SESSION['message'] = "❌ Le numéro {$numero} est invalide  .";
            redirectBack();
        }

    } else {
        $_SESSION['class'] = "alert alert-danger";
        $_SESSION['message'] = "Veuillez entrer le numéro de téléphone et le message .";
        redirectBack();
        exit;
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_campagne'])) {
    $nom         = trim($_POST['campagne_name']);
    $description = trim($_POST['campagne_description']);

    if (!empty($nom)) {
        $campagneId = campaignQueue()->createCampaign($nom, $description, 'generique', 'admin', 50);
        $_SESSION['class'] = "alert alert-success";
        $_SESSION['message'] = "✅ Campagne créée avec succès. Importez maintenant vos destinataires.";
        header("Location: ../app/index.php?page=campgagne&details=$campagneId");
        exit;
    } else {
        $_SESSION['class'] = "alert alert-warning";
        $_SESSION['message'] = "Le nom de la campagne est obligatoire ";
        redirectBack();
        exit;
    }
}
// Import direct depuis un fichier Excel (.xlsx) : chaque ligne est déjà un
// message complet et prêt à l'envoi (nom, prenom, matricule, telephone,
// message) — pas de consolidation nécessaire, contrairement à l'import CSV
// des résultats bruts ci-dessus. Une ligne importée = un destinataire de
// campagne (§59 : import massif des étudiants).
if (isset($_POST['import_excel_recipients']) && isset($_FILES['excelFile']) && isset($_POST['campagne_id'])) {
    $campagneId = (int) $_POST['campagne_id'];
    $campagne = getSingleCampagne($campagneId);

    if (!$campagne || $campagne['statut'] !== 'DRAFT') {
        $_SESSION['class'] = "alert alert-danger";
        $_SESSION['message'] = "❌ Impossible d'importer : campagne introuvable ou déjà lancée.";
        redirectBack();
        exit;
    }

    $file = $_FILES['excelFile'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $maxSize = 10 * 1024 * 1024; // 10 Mo

    if ($file['error'] !== UPLOAD_ERR_OK || !in_array($ext, ['xlsx', 'xls'], true) || $file['size'] > $maxSize) {
        $_SESSION['class'] = "alert alert-danger";
        $_SESSION['message'] = "❌ Fichier invalide : un .xlsx ou .xls de moins de 10 Mo est attendu.";
        redirectBack();
        exit;
    }

    try {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file['tmp_name']);
        $sheet = $spreadsheet->getActiveSheet();
        $data = $sheet->toArray(null, true, true, false);
    } catch (Exception $e) {
        $_SESSION['class'] = "alert alert-danger";
        $_SESSION['message'] = "❌ Impossible de lire le fichier Excel : " . $e->getMessage();
        redirectBack();
        exit;
    }

    if (empty($data)) {
        $_SESSION['class'] = "alert alert-warning";
        $_SESSION['message'] = "Le fichier est vide.";
        redirectBack();
        exit;
    }

    // Repère les colonnes par leur en-tête (ordre libre), plutôt que par position fixe.
    $headers = array_map(fn($h) => strtolower(trim((string) $h)), array_shift($data));
    $colIndex = array_flip($headers);
    $required = ['nom', 'prenom', 'matricule', 'telephone', 'message'];
    $missingCols = array_diff($required, array_keys($colIndex));

    if (!empty($missingCols)) {
        $_SESSION['class'] = "alert alert-danger";
        $_SESSION['message'] = "❌ Colonnes manquantes dans le fichier : " . implode(', ', $missingCols) . ". Attendu : nom, prenom, matricule, telephone, message.";
        redirectBack();
        exit;
    }

    $rows = [];
    foreach ($data as $line) {
        $rows[] = [
            'nom' => trim((string) ($line[$colIndex['nom']] ?? '')),
            'prenom' => trim((string) ($line[$colIndex['prenom']] ?? '')),
            'matricule' => trim((string) ($line[$colIndex['matricule']] ?? '')),
            'destinataire' => trim((string) ($line[$colIndex['telephone']] ?? '')),
            'contenu' => trim((string) ($line[$colIndex['message']] ?? '')),
        ];
    }

    $result = campaignQueue()->addRecipients($campagneId, $rows);

    $_SESSION['class'] = "alert alert-success";
    $_SESSION['message'] = "✅ Import terminé : {$result['added']} destinataire(s) ajouté(s), {$result['duplicates']} doublon(s) ignoré(s), {$result['invalid']} ligne(s) invalide(s) (numéro ou message manquant).";
    header("Location: ../app/index.php?page=campgagne&details=$campagneId");
    exit;
}

// Passe une campagne DRAFT en file d'attente (§5) — le traitement réel se fait
// ensuite par lots via server/campaign_worker.php, jamais dans cette requête.
if (isset($_POST['launch_campagne'])) {
    $campagneId = (int) $_POST['campagne_id'];
    $dryRun = isset($_POST['dry_run']);
    campaignQueue()->queueCampaign($campagneId, $dryRun);
    header("Location: ../app/index.php?page=campgagne&details=$campagneId");
    exit;
}

if (isset($_POST['pause_campagne'])) {
    $campagneId = (int) $_POST['campagne_id'];
    campaignQueue()->pause($campagneId);
    header("Location: ../app/index.php?page=campgagne&details=$campagneId");
    exit;
}

if (isset($_POST['resume_campagne'])) {
    $campagneId = (int) $_POST['campagne_id'];
    campaignQueue()->resume($campagneId);
    header("Location: ../app/index.php?page=campgagne&details=$campagneId");
    exit;
}

if (isset($_POST['cancel_campagne'])) {
    $campagneId = (int) $_POST['campagne_id'];
    campaignQueue()->cancel($campagneId);
    header("Location: ../app/index.php?page=campgagne&details=$campagneId");
    exit;
}

if (isset($_POST['retry_campagne_failures'])) {
    $campagneId = (int) $_POST['campagne_id'];
    $n = campaignQueue()->retryFailed($campagneId);
    campaignQueue()->queueCampaign($campagneId);
    $_SESSION['class'] = "alert alert-success";
    $_SESSION['message'] = "🔁 $n échec(s) remis en file d'attente.";
    header("Location: ../app/index.php?page=campgagne&details=$campagneId");
    exit;
}