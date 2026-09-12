<?php
require_once('./config.php');
session_start();
auth()->requireLogin('../app/login.php');
// app.php ne fait que des mutations (création/import/envoi) — un VIEWER ou un
// ANALYST (lecture seule, §6/§38) ne doit jamais pouvoir déclencher un envoi
// de SMS ou une suppression.
if (!auth()->hasRole(\App\Services\AuthService::MUTATION_ROLES)) {
    http_response_code(403);
    exit('Accès refusé : votre rôle ne permet pas cette action.');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    http_response_code(419);
    exit('Session expirée ou requête invalide, veuillez recharger la page et réessayer.');
}
$_SESSION['class'] = "";
$_SESSION['message'] = "";
$actor = auth()->user()['nom'] ?? null; // pour le journal d'activité (§35/§64)

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
        $campagneId = campaignQueue()->createCampaign(auth()->organizationId(), $nom, $description, 'generique', 'admin', 50);
        activityLog()->log('creation_campagne', $campagneId, $actor, $nom);
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
// message complet et prêt à l'envoi (nom, prenom, telephone, message).
// Une ligne importée = un destinataire de campagne (§59 : import massif).
if (isset($_POST['import_excel_recipients']) && isset($_FILES['excelFile']) && isset($_POST['campagne_id'])) {
    $campagneId = (int) $_POST['campagne_id'];
    $campagne = assertOwnsCampagne($campagneId);

    if ($campagne['statut'] !== 'DRAFT') {
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
    $required = ['nom', 'prenom', 'telephone', 'message'];
    $missingCols = array_diff($required, array_keys($colIndex));

    if (!empty($missingCols)) {
        $_SESSION['class'] = "alert alert-danger";
        $_SESSION['message'] = "❌ Colonnes manquantes dans le fichier : " . implode(', ', $missingCols) . ". Attendu : nom, prenom, telephone, message.";
        redirectBack();
        exit;
    }

    $rows = [];
    foreach ($data as $line) {
        $rows[] = [
            'nom' => trim((string) ($line[$colIndex['nom']] ?? '')),
            'prenom' => trim((string) ($line[$colIndex['prenom']] ?? '')),
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

// Compose un message (avec variables {{nom}}/{{prenom}}/{{telephone}}/{{email}})
// pour toute l'audience "tous mes contacts" ou "un groupe" (§15 étape 2,
// §16-17) — alternative à l'import Excel quand on veut envoyer le même
// message (personnalisé) à une audience déjà connue de la plateforme.
if (isset($_POST['create_campaign_recipients'])) {
    $campagneId = (int) ($_POST['campagne_id'] ?? 0);
    $campagne = assertOwnsCampagne($campagneId);

    if ($campagne['statut'] !== 'DRAFT') {
        $_SESSION['class'] = "alert alert-danger";
        $_SESSION['message'] = "❌ Impossible d'ajouter des destinataires : campagne déjà lancée.";
        header("Location: ../app/index.php?page=campgagne&details=$campagneId");
        exit;
    }

    $audienceType = $_POST['audience_type'] ?? 'all';
    $groupeId = ($audienceType === 'group' && !empty($_POST['groupe_id'])) ? (int) $_POST['groupe_id'] : null;
    $message = trim($_POST['campaign_message'] ?? '');

    if ($message === '') {
        $_SESSION['class'] = "alert alert-warning";
        $_SESSION['message'] = "Le message est obligatoire.";
        header("Location: ../app/index.php?page=campgagne&details=$campagneId");
        exit;
    }

    $audience = contacts()->allContacts($groupeId);
    if (empty($audience)) {
        $_SESSION['class'] = "alert alert-warning";
        $_SESSION['message'] = "Aucun contact dans cette audience.";
        header("Location: ../app/index.php?page=campgagne&details=$campagneId");
        exit;
    }

    $rows = [];
    foreach ($audience as $c) {
        $rendered = \App\Services\MessageTemplateService::render($message, [
            'nom' => $c['nom'],
            'prenom' => $c['prenom'],
            'telephone' => $c['telephone'],
            'email' => $c['email'],
        ]);
        $rows[] = [
            'destinataire' => $c['telephone'],
            'contenu' => $rendered['message'],
            'nom' => $c['nom'],
            'prenom' => $c['prenom'],
        ];
    }

    $result = campaignQueue()->addRecipients($campagneId, $rows);
    activityLog()->log('ajout_destinataires_campagne', $campagneId, $actor, "{$result['added']} ajouté(s) depuis " . ($groupeId !== null ? "groupe #$groupeId" : 'tous les contacts'));

    $_SESSION['class'] = "alert alert-success";
    $_SESSION['message'] = "✅ {$result['added']} destinataire(s) ajouté(s), {$result['duplicates']} doublon(s) ignoré(s).";
    header("Location: ../app/index.php?page=campgagne&details=$campagneId");
    exit;
}

// SMS de test (§19) : passe par le même service Orange que l'envoi réel,
// mais hors file d'attente — n'affecte jamais les compteurs/statuts de la
// campagne, distingué dans le journal d'activité.
if (isset($_POST['send_test_sms'])) {
    $campagneId = (int) ($_POST['campagne_id'] ?? 0);
    assertOwnsCampagne($campagneId);

    $numero = trim($_POST['test_numero'] ?? '');
    $pattern = "/^(\+224|00224)6\d{8}$/";

    if (!preg_match($pattern, $numero)) {
        $_SESSION['class'] = "alert alert-danger";
        $_SESSION['message'] = "❌ Numéro de test invalide (format attendu : +224XXXXXXXXX).";
        header("Location: ../app/index.php?page=campgagne&details=$campagneId");
        exit;
    }

    $stmt = PDO()->prepare("SELECT contenu FROM messages WHERE campagne_id = :id ORDER BY id LIMIT 1");
    $stmt->execute([':id' => $campagneId]);
    $contenu = $stmt->fetchColumn();

    if (!$contenu) {
        $_SESSION['class'] = "alert alert-warning";
        $_SESSION['message'] = "Ajoutez d'abord des destinataires avant d'envoyer un SMS de test.";
        header("Location: ../app/index.php?page=campgagne&details=$campagneId");
        exit;
    }

    try {
        orangeSms()->sendSms($numero, $contenu);
        activityLog()->log('test_sms_campagne', $campagneId, $actor, "test envoyé à $numero");
        $_SESSION['class'] = "alert alert-success";
        $_SESSION['message'] = "✅ SMS de test envoyé à $numero.";
    } catch (Exception $e) {
        $_SESSION['class'] = "alert alert-danger";
        $_SESSION['message'] = "❌ Échec de l'envoi du test : " . $e->getMessage();
    }
    header("Location: ../app/index.php?page=campgagne&details=$campagneId");
    exit;
}

// Passe une campagne DRAFT en file d'attente (§5) — le traitement réel se fait
// ensuite par lots via server/campaign_worker.php, jamais dans cette requête.
if (isset($_POST['launch_campagne'])) {
    $campagneId = (int) $_POST['campagne_id'];
    assertOwnsCampagne($campagneId);
    $dryRun = isset($_POST['dry_run']);

    // §20 : bloquer un lancement dont le coût dépasse le solde Orange
    // disponible plutôt que de laisser la campagne échouer destinataire par
    // destinataire une fois lancée. Pas de vérification en dry_run (aucun
    // SMS réel n'est consommé).
    if (!$dryRun) {
        $needed = estimateSmsNeeded($campagneId);
        $available = (int) (orangeSms()->getBalance()['availableUnits'] ?? 0);
        if ($needed > $available) {
            $_SESSION['class'] = "alert alert-danger";
            $_SESSION['message'] = "❌ Solde SMS insuffisant pour cette campagne : $needed SMS nécessaires, $available disponible(s).";
            header("Location: ../app/index.php?page=campgagne&details=$campagneId");
            exit;
        }
    }

    campaignQueue()->queueCampaign($campagneId, $dryRun);
    activityLog()->log('lancement_campagne', $campagneId, $actor, $dryRun ? 'dry_run' : null);
    header("Location: ../app/index.php?page=campgagne&details=$campagneId");
    exit;
}

if (isset($_POST['pause_campagne'])) {
    $campagneId = (int) $_POST['campagne_id'];
    assertOwnsCampagne($campagneId);
    campaignQueue()->pause($campagneId);
    activityLog()->log('pause_campagne', $campagneId, $actor);
    header("Location: ../app/index.php?page=campgagne&details=$campagneId");
    exit;
}

if (isset($_POST['resume_campagne'])) {
    $campagneId = (int) $_POST['campagne_id'];
    assertOwnsCampagne($campagneId);
    campaignQueue()->resume($campagneId);
    activityLog()->log('reprise_campagne', $campagneId, $actor);
    header("Location: ../app/index.php?page=campgagne&details=$campagneId");
    exit;
}

if (isset($_POST['cancel_campagne'])) {
    $campagneId = (int) $_POST['campagne_id'];
    assertOwnsCampagne($campagneId);
    campaignQueue()->cancel($campagneId);
    activityLog()->log('annulation_campagne', $campagneId, $actor);
    header("Location: ../app/index.php?page=campgagne&details=$campagneId");
    exit;
}

if (isset($_POST['retry_campagne_failures'])) {
    $campagneId = (int) $_POST['campagne_id'];
    assertOwnsCampagne($campagneId);
    $n = campaignQueue()->retryFailed($campagneId);
    campaignQueue()->queueCampaign($campagneId);
    activityLog()->log('retry_campagne', $campagneId, $actor, "$n message(s) remis en file");
    $_SESSION['class'] = "alert alert-success";
    $_SESSION['message'] = "🔁 $n échec(s) remis en file d'attente.";
    header("Location: ../app/index.php?page=campgagne&details=$campagneId");
    exit;
}

// ------------------------------------------------------------------
// Modèles SMS réutilisables (cahier des charges V2.0, §25) : créer,
// modifier, dupliquer, archiver. Le rendu des variables reste géré par
// MessageTemplateService — cette section ne gère que le stockage.
// ------------------------------------------------------------------

if (isset($_POST['create_template'])) {
    $nom = trim($_POST['template_nom'] ?? '');
    $categorie = trim($_POST['template_categorie'] ?? 'notification');
    $contenu = trim($_POST['template_contenu'] ?? '');

    if ($nom === '' || $contenu === '') {
        $_SESSION['class'] = "alert alert-warning";
        $_SESSION['message'] = "Le nom et le contenu du modèle sont obligatoires.";
        redirectBack();
        exit;
    }

    $id = smsTemplates()->create($nom, $categorie, $contenu, $actor);
    activityLog()->log('creation_modele', null, $actor, $nom);
    $_SESSION['class'] = "alert alert-success";
    $_SESSION['message'] = "✅ Modèle « $nom » créé.";
    header("Location: ../app/index.php?page=modeles");
    exit;
}

if (isset($_POST['update_template'])) {
    $id = (int) ($_POST['template_id'] ?? 0);
    $nom = trim($_POST['template_nom'] ?? '');
    $categorie = trim($_POST['template_categorie'] ?? 'notification');
    $contenu = trim($_POST['template_contenu'] ?? '');

    if ($id > 0 && $nom !== '' && $contenu !== '') {
        smsTemplates()->update($id, $nom, $categorie, $contenu);
        activityLog()->log('modification_modele', null, $actor, $nom);
        $_SESSION['class'] = "alert alert-success";
        $_SESSION['message'] = "✅ Modèle « $nom » mis à jour.";
    } else {
        $_SESSION['class'] = "alert alert-warning";
        $_SESSION['message'] = "Le nom et le contenu du modèle sont obligatoires.";
    }
    header("Location: ../app/index.php?page=modeles");
    exit;
}

if (isset($_POST['duplicate_template'])) {
    $id = (int) ($_POST['template_id'] ?? 0);
    $newId = smsTemplates()->duplicate($id);
    if ($newId !== null) {
        activityLog()->log('duplication_modele', null, $actor, "modèle #$id");
        $_SESSION['class'] = "alert alert-success";
        $_SESSION['message'] = "✅ Modèle dupliqué.";
    } else {
        $_SESSION['class'] = "alert alert-danger";
        $_SESSION['message'] = "❌ Modèle introuvable.";
    }
    header("Location: ../app/index.php?page=modeles");
    exit;
}

if (isset($_POST['archive_template'])) {
    $id = (int) ($_POST['template_id'] ?? 0);
    smsTemplates()->setArchived($id, true);
    activityLog()->log('archivage_modele', null, $actor, "modèle #$id");
    $_SESSION['class'] = "alert alert-success";
    $_SESSION['message'] = "✅ Modèle archivé.";
    header("Location: ../app/index.php?page=modeles");
    exit;
}

if (isset($_POST['unarchive_template'])) {
    $id = (int) ($_POST['template_id'] ?? 0);
    smsTemplates()->setArchived($id, false);
    activityLog()->log('desarchivage_modele', null, $actor, "modèle #$id");
    $_SESSION['class'] = "alert alert-success";
    $_SESSION['message'] = "✅ Modèle réactivé.";
    header("Location: ../app/index.php?page=modeles");
    exit;
}

// ------------------------------------------------------------------
// Paramètres de l'organisation (cahier des charges V2.0 §7). Toujours
// auth()->organizationId() comme cible — jamais un id soumis par le
// formulaire — pour qu'un utilisateur ne puisse modifier que sa propre
// organisation.
// ------------------------------------------------------------------

if (isset($_POST['update_organisation'])) {
    if (!auth()->hasRole(\App\Services\AuthService::MANAGEMENT_ROLES)) {
        http_response_code(403);
        exit('Accès refusé : seuls les administrateurs peuvent modifier les paramètres de l\'organisation.');
    }
    // Champ par champ, seulement si soumis : le formulaire "Alertes" de
    // app/templete/organisation.php ne poste que org_nom (obligatoire) et
    // org_low_balance_threshold — construire l'update avec tous les champs
    // organization_id => valeur ?? null effacerait secteur/téléphone/etc. à
    // chaque enregistrement du seuil d'alerte.
    $orgUpdate = ['nom' => trim($_POST['org_nom'] ?? '')];
    $optionalOrgFields = [
        'org_secteur' => 'secteur', 'org_telephone' => 'telephone', 'org_email' => 'email',
        'org_adresse' => 'adresse', 'org_pays' => 'pays', 'org_fuseau_horaire' => 'fuseau_horaire',
        'org_devise' => 'devise', 'org_sender_name' => 'sender_name',
    ];
    foreach ($optionalOrgFields as $postKey => $column) {
        if (isset($_POST[$postKey])) {
            $orgUpdate[$column] = trim($_POST[$postKey]) ?: null;
        }
    }
    if (isset($_POST['org_low_balance_threshold'])) {
        $orgUpdate['low_balance_threshold'] = max(0, (int) $_POST['org_low_balance_threshold']);
    }
    organizations()->update(auth()->organizationId(), $orgUpdate);
    activityLog()->log('modification_organisation', null, $actor);
    $_SESSION['class'] = "alert alert-success";
    $_SESSION['message'] = "✅ Informations de l'organisation mises à jour.";
    header("Location: ../app/index.php?page=organisation");
    exit;
}

// ------------------------------------------------------------------
// Équipe / utilisateurs (cahier des charges V2.0 §6, §7) : réservé aux
// rôles de gestion (SUPER_ADMIN/OWNER/ADMIN) — un CAMPAIGN_MANAGER ou un
// OPERATOR ne doit pas pouvoir ajouter/retirer des comptes ni changer les
// rôles de ses collègues.
// ------------------------------------------------------------------

if (isset($_POST['create_team_member'])) {
    if (!auth()->hasRole(\App\Services\AuthService::MANAGEMENT_ROLES)) {
        http_response_code(403);
        exit('Accès refusé : seuls les administrateurs peuvent ajouter un membre.');
    }
    $nom = trim($_POST['member_nom'] ?? '');
    $email = trim($_POST['member_email'] ?? '');
    $password = (string) ($_POST['member_password'] ?? '');
    $role = $_POST['member_role'] ?? 'VIEWER';

    if ($nom === '' || $email === '' || strlen($password) < 8 || !in_array($role, \App\Services\AuthService::ROLES, true)) {
        $_SESSION['class'] = "alert alert-warning";
        $_SESSION['message'] = "Nom, email, mot de passe (8 caractères min.) et rôle valide sont obligatoires.";
        header("Location: ../app/index.php?page=equipe");
        exit;
    }

    try {
        auth()->createUser(auth()->organizationId(), $nom, $email, $password, $role);
        activityLog()->log('ajout_membre_equipe', null, $actor, "$nom ($role)");
        $_SESSION['class'] = "alert alert-success";
        $_SESSION['message'] = "✅ « $nom » a été ajouté à votre équipe.";
    } catch (Exception $e) {
        $_SESSION['class'] = "alert alert-danger";
        $_SESSION['message'] = "❌ " . $e->getMessage();
    }
    header("Location: ../app/index.php?page=equipe");
    exit;
}

if (isset($_POST['update_team_member_role'])) {
    if (!auth()->hasRole(\App\Services\AuthService::MANAGEMENT_ROLES)) {
        http_response_code(403);
        exit('Accès refusé : seuls les administrateurs peuvent modifier les rôles.');
    }
    $userId = (int) ($_POST['member_id'] ?? 0);
    $role = $_POST['member_role'] ?? '';

    try {
        auth()->updateUserRole(auth()->organizationId(), $userId, $role);
        activityLog()->log('modification_role_membre', null, $actor, "utilisateur #$userId -> $role");
        $_SESSION['class'] = "alert alert-success";
        $_SESSION['message'] = "✅ Rôle mis à jour.";
    } catch (Exception $e) {
        $_SESSION['class'] = "alert alert-danger";
        $_SESSION['message'] = "❌ " . $e->getMessage();
    }
    header("Location: ../app/index.php?page=equipe");
    exit;
}

if (isset($_POST['delete_team_member'])) {
    if (!auth()->hasRole(\App\Services\AuthService::MANAGEMENT_ROLES)) {
        http_response_code(403);
        exit('Accès refusé : seuls les administrateurs peuvent retirer un membre.');
    }
    $userId = (int) ($_POST['member_id'] ?? 0);

    if ($userId === (int) auth()->user()['id']) {
        $_SESSION['class'] = "alert alert-warning";
        $_SESSION['message'] = "Vous ne pouvez pas vous retirer vous-même de l'équipe.";
        header("Location: ../app/index.php?page=equipe");
        exit;
    }

    try {
        auth()->deleteUser(auth()->organizationId(), $userId);
        activityLog()->log('suppression_membre_equipe', null, $actor, "utilisateur #$userId");
        $_SESSION['class'] = "alert alert-success";
        $_SESSION['message'] = "✅ Membre retiré de l'équipe.";
    } catch (Exception $e) {
        $_SESSION['class'] = "alert alert-danger";
        $_SESSION['message'] = "❌ " . $e->getMessage();
    }
    header("Location: ../app/index.php?page=equipe");
    exit;
}

if (isset($_POST['mark_all_notifications_read'])) {
    notifications()->markAllRead();
    redirectBack();
    exit;
}

// ------------------------------------------------------------------
// Contacts / Groupes (cahier des charges V2.0, §22-24). Recréé le
// 2026-09-12 sur un schéma dédié (contacts_v2/groupes_v2), après une
// suppression puis une nouvelle demande explicites de l'utilisateur
// (voir AUDIT.md pour l'historique complet des deux décisions).
// ------------------------------------------------------------------

if (isset($_POST['create_contact'])) {
    $nom = trim($_POST['contact_nom'] ?? '');
    $prenom = trim($_POST['contact_prenom'] ?? '');
    $telephone = trim($_POST['contact_telephone'] ?? '');
    $email = trim($_POST['contact_email'] ?? '');

    try {
        $id = contacts()->createContact($nom, $prenom, $telephone, $email);
        activityLog()->log('creation_contact', null, $actor, "$prenom $nom");
        $_SESSION['class'] = "alert alert-success";
        $_SESSION['message'] = "✅ Contact ajouté.";
    } catch (Exception $e) {
        $_SESSION['class'] = "alert alert-danger";
        $_SESSION['message'] = "❌ " . $e->getMessage();
    }
    redirectBack();
    exit;
}

if (isset($_POST['delete_contact'])) {
    $id = (int) ($_POST['contact_id'] ?? 0);
    contacts()->deleteContact($id);
    activityLog()->log('suppression_contact', null, $actor, "contact #$id");
    $_SESSION['class'] = "alert alert-success";
    $_SESSION['message'] = "✅ Contact supprimé.";
    redirectBack();
    exit;
}

if (isset($_POST['import_contacts']) && isset($_FILES['contactsFile'])) {
    $file = $_FILES['contactsFile'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $maxSize = 15 * 1024 * 1024;
    $groupeId = !empty($_POST['groupe_id']) ? (int) $_POST['groupe_id'] : null;

    if ($file['error'] !== UPLOAD_ERR_OK || !in_array($ext, ['xlsx', 'xls', 'csv'], true) || $file['size'] > $maxSize) {
        $_SESSION['class'] = "alert alert-danger";
        $_SESSION['message'] = "❌ Fichier invalide : un .xlsx, .xls ou .csv de moins de 15 Mo est attendu.";
        redirectBack();
        exit;
    }

    try {
        $report = contacts()->importFile($file['tmp_name'], $ext, $groupeId, $actor);
        activityLog()->log('import_contacts', null, $actor, "{$report['valides']} valide(s)/{$report['invalides']} invalide(s)/{$report['doublons']} doublon(s)");
        notifications()->create('import_termine', 'Import de contacts terminé', "{$report['valides']} valide(s), {$report['invalides']} invalide(s), {$report['doublons']} doublon(s) sur {$report['total']} ligne(s).");
        $_SESSION['class'] = "alert alert-success";
        $_SESSION['message'] = "✅ Import terminé ({$report['total']} ligne(s) analysée(s)) : {$report['valides']} valide(s), {$report['invalides']} invalide(s), {$report['doublons']} doublon(s).";
    } catch (Exception $e) {
        $_SESSION['class'] = "alert alert-danger";
        $_SESSION['message'] = "❌ Échec de l'import : " . $e->getMessage();
    }
    if ($groupeId !== null) {
        header("Location: ../app/index.php?page=detail-groupe&id=$groupeId");
    } else {
        header("Location: ../app/index.php?page=contacts");
    }
    exit;
}

if (isset($_POST['create_group'])) {
    $nom = trim($_POST['groupe_nom'] ?? '');
    $description = trim($_POST['groupe_description'] ?? '');

    if ($nom === '') {
        $_SESSION['class'] = "alert alert-warning";
        $_SESSION['message'] = "Le nom du groupe est obligatoire.";
        redirectBack();
        exit;
    }

    $id = contacts()->createGroup($nom, $description, $actor);
    activityLog()->log('creation_groupe', null, $actor, $nom);
    $_SESSION['class'] = "alert alert-success";
    $_SESSION['message'] = "✅ Groupe « $nom » créé.";
    header("Location: ../app/index.php?page=groupe");
    exit;
}

if (isset($_POST['delete_group'])) {
    $id = (int) ($_POST['groupe_id'] ?? 0);
    contacts()->deleteGroup($id);
    activityLog()->log('suppression_groupe', null, $actor, "groupe #$id");
    $_SESSION['class'] = "alert alert-success";
    $_SESSION['message'] = "✅ Groupe supprimé.";
    header("Location: ../app/index.php?page=groupe");
    exit;
}

if (isset($_POST['add_contact_to_group'])) {
    $groupeId = (int) ($_POST['groupe_id'] ?? 0);
    $contactId = (int) ($_POST['contact_id'] ?? 0);
    contacts()->addContactToGroup($groupeId, $contactId);
    $_SESSION['class'] = "alert alert-success";
    $_SESSION['message'] = "✅ Contact ajouté au groupe.";
    header("Location: ../app/index.php?page=detail-groupe&id=$groupeId");
    exit;
}

if (isset($_POST['remove_contact_from_group'])) {
    $groupeId = (int) ($_POST['groupe_id'] ?? 0);
    $contactId = (int) ($_POST['contact_id'] ?? 0);
    contacts()->removeContactFromGroup($groupeId, $contactId);
    $_SESSION['class'] = "alert alert-success";
    $_SESSION['message'] = "✅ Contact retiré du groupe.";
    header("Location: ../app/index.php?page=detail-groupe&id=$groupeId");
    exit;
}

// Envoie une campagne à tous les contacts d'un groupe (§24) — réutilise
// entièrement le moteur de campagnes existant.
if (isset($_POST['send_to_group'])) {
    $groupeId = (int) ($_POST['groupe_id'] ?? 0);
    $message = trim($_POST['group_message'] ?? '');
    $group = contacts()->findGroup($groupeId);

    if (!$group || $message === '') {
        $_SESSION['class'] = "alert alert-warning";
        $_SESSION['message'] = "Groupe introuvable ou message vide.";
        redirectBack();
        exit;
    }

    $groupContacts = contacts()->allContacts($groupeId);
    if (empty($groupContacts)) {
        $_SESSION['class'] = "alert alert-warning";
        $_SESSION['message'] = "Ce groupe ne contient aucun contact.";
        redirectBack();
        exit;
    }

    $campagneId = campaignQueue()->createCampaign(
        auth()->organizationId(),
        'Envoi au groupe ' . $group['nom'],
        'Campagne générée depuis le groupe « ' . $group['nom'] . ' »',
        'contacts',
        $actor,
        50
    );

    // §16-17 : rendre les variables ({{nom}}/{{prenom}}/...) au lieu d'envoyer
    // le texte brut du formulaire tel quel à tout le monde (bug trouvé en
    // auditant ce flux après l'ajout du créateur de campagne par audience,
    // qui utilise le même moteur — voir create_campaign_recipients ci-dessus).
    $recipients = [];
    foreach ($groupContacts as $c) {
        $rendered = \App\Services\MessageTemplateService::render($message, [
            'nom' => $c['nom'],
            'prenom' => $c['prenom'],
            'telephone' => $c['telephone'],
            'email' => $c['email'],
        ]);
        $recipients[] = [
            'destinataire' => $c['telephone'],
            'contenu' => $rendered['message'],
            'nom' => $c['nom'],
            'prenom' => $c['prenom'],
        ];
    }
    $result = campaignQueue()->addRecipients($campagneId, $recipients);
    activityLog()->log('creation_campagne_groupe', $campagneId, $actor, $group['nom'] . ' — ' . count($groupContacts) . ' contact(s)');

    $_SESSION['class'] = "alert alert-success";
    $_SESSION['message'] = "✅ Campagne créée pour le groupe « {$group['nom']} » : {$result['added']} destinataire(s). Vérifiez le journal puis lancez l'envoi.";
    header("Location: ../app/index.php?page=campgagne&details=$campagneId");
    exit;
}