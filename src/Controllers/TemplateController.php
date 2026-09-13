<?php

namespace App\Controllers;

use App\Core\Controller;

class TemplateController extends Controller
{
    public function index(): void
    {
        $this->view('templates/index', [
            'templates' => smsTemplates()->all(true),
            'categoryLabels' => [
                'marketing' => 'Marketing',
                'transactionnel' => 'Transactionnel',
                'notification' => 'Notification',
                'rappel' => 'Rappel',
                'alerte' => 'Alerte',
            ],
        ]);
    }

    public function create(): void
    {
        $this->requireMutationRole();
        $this->requireCsrf();

        $nom = trim($_POST['template_nom'] ?? '');
        $categorie = trim($_POST['template_categorie'] ?? 'notification');
        $contenu = trim($_POST['template_contenu'] ?? '');

        if ($nom === '' || $contenu === '') {
            $this->flash('alert alert-warning', 'Le nom et le contenu du modèle sont obligatoires.');
            $this->redirectBack();
        }

        $id = smsTemplates()->create($nom, $categorie, $contenu, $this->actor());
        activityLog()->log('creation_modele', null, $this->actor(), $nom);
        $this->flash('alert alert-success', "✅ Modèle « $nom » créé.");
        $this->redirect('templates.index');
    }

    public function update(): void
    {
        $this->requireMutationRole();
        $this->requireCsrf();

        $id = (int) ($_POST['template_id'] ?? 0);
        $nom = trim($_POST['template_nom'] ?? '');
        $categorie = trim($_POST['template_categorie'] ?? 'notification');
        $contenu = trim($_POST['template_contenu'] ?? '');

        if ($id > 0 && $nom !== '' && $contenu !== '') {
            smsTemplates()->update($id, $nom, $categorie, $contenu);
            activityLog()->log('modification_modele', null, $this->actor(), $nom);
            $this->flash('alert alert-success', "✅ Modèle « $nom » mis à jour.");
        } else {
            $this->flash('alert alert-warning', 'Le nom et le contenu du modèle sont obligatoires.');
        }
        $this->redirect('templates.index');
    }

    public function duplicate(): void
    {
        $this->requireMutationRole();
        $this->requireCsrf();

        $id = (int) ($_POST['template_id'] ?? 0);
        $newId = smsTemplates()->duplicate($id);
        if ($newId !== null) {
            activityLog()->log('duplication_modele', null, $this->actor(), "modèle #$id");
            $this->flash('alert alert-success', '✅ Modèle dupliqué.');
        } else {
            $this->flash('alert alert-danger', '❌ Modèle introuvable.');
        }
        $this->redirect('templates.index');
    }

    public function archive(): void
    {
        $this->requireMutationRole();
        $this->requireCsrf();

        $id = (int) ($_POST['template_id'] ?? 0);
        smsTemplates()->setArchived($id, true);
        activityLog()->log('archivage_modele', null, $this->actor(), "modèle #$id");
        $this->flash('alert alert-success', '✅ Modèle archivé.');
        $this->redirect('templates.index');
    }

    public function unarchive(): void
    {
        $this->requireMutationRole();
        $this->requireCsrf();

        $id = (int) ($_POST['template_id'] ?? 0);
        smsTemplates()->setArchived($id, false);
        activityLog()->log('desarchivage_modele', null, $this->actor(), "modèle #$id");
        $this->flash('alert alert-success', '✅ Modèle réactivé.');
        $this->redirect('templates.index');
    }
}
