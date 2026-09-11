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

// ------------------------------------------------------------------
// Résultats académiques (cahier des charges V2.0, §3-4, §16, §61-64) :
// import structuré (Excel/CSV) puis génération d'une campagne consolidée
// via le moteur existant (campaignQueue()), une fois le message rendu par
// MessageTemplateService à partir du modèle + des données de chaque étudiant.
// ------------------------------------------------------------------

if (isset($_POST['import_resultats']) && isset($_FILES['resultatsFile'])) {
    $file = $_FILES['resultatsFile'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $maxSize = 15 * 1024 * 1024; // 15 Mo

    if ($file['error'] !== UPLOAD_ERR_OK || !in_array($ext, ['xlsx', 'xls', 'csv'], true) || $file['size'] > $maxSize) {
        $_SESSION['class'] = "alert alert-danger";
        $_SESSION['message'] = "❌ Fichier invalide : un .xlsx, .xls ou .csv de moins de 15 Mo est attendu.";
        redirectBack();
        exit;
    }

    try {
        $report = academicResults()->importFile($file['tmp_name'], $ext, auth()->user()['nom'] ?? null);
        $_SESSION['class'] = "alert alert-success";
        $_SESSION['message'] = "✅ Import terminé ({$report['total']} ligne(s) analysée(s)) : {$report['valides']} valide(s), "
            . "{$report['invalides']} invalide(s), {$report['doublons']} doublon(s)/mise(s) à jour. "
            . ($report['invalides'] > 0 || $report['doublons'] > 0 ? "Voir le détail dans l'historique d'import ci-dessous." : "");
    } catch (Exception $e) {
        $_SESSION['class'] = "alert alert-danger";
        $_SESSION['message'] = "❌ Échec de l'import : " . $e->getMessage();
    }
    header("Location: ../app/index.php?page=resultats");
    exit;
}

if (isset($_POST['test_sms_resultats'])) {
    $numero = trim($_POST['test_number'] ?? '');
    $template = trim($_POST['message_template'] ?? '');
    $phone = \App\Services\PhoneNumberService::normalize($numero);

    if ($phone === null || $template === '') {
        $_SESSION['class'] = "alert alert-danger";
        $_SESSION['message'] = "❌ Numéro de test invalide ou message vide.";
        redirectBack();
        exit;
    }

    $filters = [
        'session_academique' => trim($_POST['f_session'] ?? ''),
        'niveau' => trim($_POST['f_niveau'] ?? ''),
        'classe' => trim($_POST['f_classe'] ?? ''),
        'programme' => trim($_POST['f_programme'] ?? ''),
        'semestre' => trim($_POST['f_semestre'] ?? ''),
    ];
    $sample = academicResults()->getSample($filters);
    $templateVars = $sample !== null ? \App\Services\AcademicResultsService::toTemplateVars($sample) : [];
    $rendered = \App\Services\MessageTemplateService::render($template, $templateVars);

    try {
        orangeSms()->sendSms($phone, '[TEST] ' . $rendered['message']);
        $_SESSION['class'] = "alert alert-success";
        $_SESSION['message'] = "✅ SMS de test envoyé à {$phone}.";
    } catch (Exception $e) {
        $_SESSION['class'] = "alert alert-danger";
        $_SESSION['message'] = "❌ Échec de l'envoi du test : " . $e->getMessage();
    }
    redirectBack();
    exit;
}

if (isset($_POST['create_resultats_campagne'])) {
    $nom = trim($_POST['campagne_name'] ?? '');
    $template = trim($_POST['message_template'] ?? '');
    $filters = [
        'session_academique' => trim($_POST['f_session'] ?? ''),
        'niveau' => trim($_POST['f_niveau'] ?? ''),
        'classe' => trim($_POST['f_classe'] ?? ''),
        'programme' => trim($_POST['f_programme'] ?? ''),
        'semestre' => trim($_POST['f_semestre'] ?? ''),
    ];

    if ($nom === '' || $template === '') {
        $_SESSION['class'] = "alert alert-warning";
        $_SESSION['message'] = "Le nom de la campagne et le modèle de message sont obligatoires.";
        redirectBack();
        exit;
    }

    $rows = academicResults()->getMatching($filters);
    if (empty($rows)) {
        $_SESSION['class'] = "alert alert-warning";
        $_SESSION['message'] = "Aucun étudiant ne correspond à ces critères.";
        redirectBack();
        exit;
    }

    // Vérification du solde avant création (§20/§27) : bloque si le solde Orange est insuffisant.
    try {
        $balance = orangeSms()->getBalance();
        $available = (int) ($balance['availableUnits'] ?? 0);
        if ($available > 0 && count($rows) > $available) {
            $_SESSION['class'] = "alert alert-danger";
            $_SESSION['message'] = "❌ Solde SMS insuffisant pour cette campagne (" . count($rows) . " nécessaires, {$available} disponibles).";
            redirectBack();
            exit;
        }
    } catch (Exception $e) {
        $_SESSION['class'] = "alert alert-warning";
        $_SESSION['message'] = "⚠️ Solde Orange non vérifiable pour le moment (" . $e->getMessage() . ").";
    }

    $campagneId = campaignQueue()->createCampaign(
        $nom,
        'Résultats académiques — ' . implode(' / ', array_filter($filters)),
        'resultats',
        auth()->user()['nom'] ?? null,
        50
    );

    $recipients = [];
    foreach ($rows as $row) {
        $rendered = \App\Services\MessageTemplateService::render($template, \App\Services\AcademicResultsService::toTemplateVars($row));
        $recipients[] = [
            'destinataire' => $row['telephone'],
            'contenu' => $rendered['message'],
            'matricule' => $row['matricule'],
            'nom' => $row['nom'],
            'prenom' => $row['prenom'],
        ];
    }
    $result = campaignQueue()->addRecipients($campagneId, $recipients);

    $_SESSION['class'] = "alert alert-success";
    $_SESSION['message'] = "✅ Campagne préparée : {$result['added']} destinataire(s) ajouté(s), {$result['duplicates']} déjà en file, {$result['invalid']} numéro(s) invalide(s). Vérifiez le journal puis lancez l'envoi.";
    header("Location: ../app/index.php?page=campgagne&details=$campagneId");
    exit;
}