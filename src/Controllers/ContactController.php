<?php

namespace App\Controllers;

use App\Core\Controller;
use Exception;

class ContactController extends Controller
{
    public function index(): void
    {
        $search = trim($_GET['search'] ?? '');

        $this->view('contacts/index', [
            'search' => $search,
            'allContacts' => contacts()->allContacts(null, $search),
            'importHistory' => contacts()->getImportHistory(5),
            'groups' => contacts()->allGroups(),
        ]);
    }

    public function create(): void
    {
        $this->requireMutationRole();
        $this->requireCsrf();

        $nom = trim($_POST['contact_nom'] ?? '');
        $prenom = trim($_POST['contact_prenom'] ?? '');
        $telephone = trim($_POST['contact_telephone'] ?? '');
        $email = trim($_POST['contact_email'] ?? '');

        try {
            $id = contacts()->createContact($nom, $prenom, $telephone, $email);
            activityLog()->log('creation_contact', null, $this->actor(), "$prenom $nom");
            $this->flash('alert alert-success', '✅ Contact ajouté.');
        } catch (Exception $e) {
            $this->flash('alert alert-danger', '❌ ' . $e->getMessage());
        }
        $this->redirectBack();
    }

    public function delete(): void
    {
        $this->requireMutationRole();
        $this->requireCsrf();

        $id = (int) ($_POST['contact_id'] ?? 0);
        contacts()->deleteContact($id);
        activityLog()->log('suppression_contact', null, $this->actor(), "contact #$id");
        $this->flash('alert alert-success', '✅ Contact supprimé.');
        $this->redirectBack();
    }

    public function import(): void
    {
        $this->requireMutationRole();
        $this->requireCsrf();

        if (!isset($_FILES['contactsFile'])) {
            $this->flash('alert alert-danger', '❌ Fichier invalide.');
            $this->redirectBack();
        }

        $file = $_FILES['contactsFile'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $maxSize = 15 * 1024 * 1024;
        $groupeId = !empty($_POST['groupe_id']) ? (int) $_POST['groupe_id'] : null;

        if ($file['error'] !== UPLOAD_ERR_OK || !in_array($ext, ['xlsx', 'xls', 'csv'], true) || $file['size'] > $maxSize) {
            $this->flash('alert alert-danger', '❌ Fichier invalide : un .xlsx, .xls ou .csv de moins de 15 Mo est attendu.');
            $this->redirectBack();
        }

        try {
            $report = contacts()->importFile($file['tmp_name'], $ext, $groupeId, $this->actor());
            activityLog()->log('import_contacts', null, $this->actor(), "{$report['valides']} valide(s)/{$report['invalides']} invalide(s)/{$report['doublons']} doublon(s)");
            notifications()->create('import_termine', 'Import de contacts terminé', "{$report['valides']} valide(s), {$report['invalides']} invalide(s), {$report['doublons']} doublon(s) sur {$report['total']} ligne(s).");
            $this->flash('alert alert-success', "✅ Import terminé ({$report['total']} ligne(s) analysée(s)) : {$report['valides']} valide(s), {$report['invalides']} invalide(s), {$report['doublons']} doublon(s).");
        } catch (Exception $e) {
            $this->flash('alert alert-danger', '❌ Échec de l\'import : ' . $e->getMessage());
        }

        if ($groupeId !== null) {
            $this->redirect('groups.show', ['id' => $groupeId]);
        }
        $this->redirect('contacts.index');
    }

    public function exportErrors(): void
    {
        $importId = filter_input(INPUT_GET, 'import_id', FILTER_VALIDATE_INT);
        if (!$importId) {
            http_response_code(400);
            exit('import_id manquant ou invalide');
        }

        $errors = contacts()->getImportErrors($importId);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="erreurs_import_contacts_' . $importId . '.csv"');

        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Ligne', 'Erreur'], ';');
        foreach ($errors as $error) {
            fputcsv($out, [$error['ligne'] ?? '', $error['erreur'] ?? ''], ';');
        }
        fclose($out);
        exit;
    }
}
