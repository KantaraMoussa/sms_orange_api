<?php

namespace App\Controllers;

use App\Core\Controller;

class GroupController extends Controller
{
    public function index(): void
    {
        $this->view('groups/index', [
            'groups' => contacts()->allGroups(),
        ]);
    }

    public function show(): void
    {
        $groupeId = (int) ($_GET['id'] ?? 0);
        $group = contacts()->findGroup($groupeId);
        $memberContacts = $group ? contacts()->allContacts($groupeId) : [];
        $memberIds = array_column($memberContacts, 'id');
        $allContactsList = $group ? contacts()->allContacts() : [];
        $availableToAdd = array_filter($allContactsList, fn($c) => !in_array($c['id'], $memberIds, true));

        $this->view('groups/show', [
            'groupeId' => $groupeId,
            'group' => $group,
            'memberContacts' => $memberContacts,
            'availableToAdd' => $availableToAdd,
        ]);
    }

    public function create(): void
    {
        $this->requireMutationRole();
        $this->requireCsrf();

        $nom = trim($_POST['groupe_nom'] ?? '');
        $description = trim($_POST['groupe_description'] ?? '');

        if ($nom === '') {
            $this->flash('alert alert-warning', 'Le nom du groupe est obligatoire.');
            $this->redirectBack();
        }

        $id = contacts()->createGroup($nom, $description, $this->actor());
        activityLog()->log('creation_groupe', null, $this->actor(), $nom);
        $this->flash('alert alert-success', "✅ Groupe « $nom » créé.");
        $this->redirect('groups.index');
    }

    public function delete(): void
    {
        $this->requireMutationRole();
        $this->requireCsrf();

        $id = (int) ($_POST['groupe_id'] ?? 0);
        contacts()->deleteGroup($id);
        activityLog()->log('suppression_groupe', null, $this->actor(), "groupe #$id");
        $this->flash('alert alert-success', '✅ Groupe supprimé.');
        $this->redirect('groups.index');
    }

    public function addContact(): void
    {
        $this->requireMutationRole();
        $this->requireCsrf();

        $groupeId = (int) ($_POST['groupe_id'] ?? 0);
        $contactId = (int) ($_POST['contact_id'] ?? 0);
        contacts()->addContactToGroup($groupeId, $contactId);
        $this->flash('alert alert-success', '✅ Contact ajouté au groupe.');
        $this->redirect('groups.show', ['id' => $groupeId]);
    }

    public function removeContact(): void
    {
        $this->requireMutationRole();
        $this->requireCsrf();

        $groupeId = (int) ($_POST['groupe_id'] ?? 0);
        $contactId = (int) ($_POST['contact_id'] ?? 0);
        contacts()->removeContactFromGroup($groupeId, $contactId);
        $this->flash('alert alert-success', '✅ Contact retiré du groupe.');
        $this->redirect('groups.show', ['id' => $groupeId]);
    }

    public function sendMessage(): void
    {
        $this->requireMutationRole();
        $this->requireCsrf();

        $groupeId = (int) ($_POST['groupe_id'] ?? 0);
        $message = trim($_POST['group_message'] ?? '');
        $group = contacts()->findGroup($groupeId);

        if (!$group || $message === '') {
            $this->flash('alert alert-warning', 'Groupe introuvable ou message vide.');
            $this->redirectBack();
        }

        $groupContacts = contacts()->allContacts($groupeId);
        if (empty($groupContacts)) {
            $this->flash('alert alert-warning', 'Ce groupe ne contient aucun contact.');
            $this->redirectBack();
        }

        $campagneId = campaignQueue()->createCampaign(
            auth()->organizationId(),
            'Envoi au groupe ' . $group['nom'],
            'Campagne générée depuis le groupe « ' . $group['nom'] . ' »',
            'contacts',
            $this->actor(),
            50
        );

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
        activityLog()->log('creation_campagne_groupe', $campagneId, $this->actor(), $group['nom'] . ' — ' . count($groupContacts) . ' contact(s)');

        $this->flash('alert alert-success', "✅ Campagne créée pour le groupe « {$group['nom']} » : {$result['added']} destinataire(s). Vérifiez le journal puis lancez l'envoi.");
        $this->redirect('campaigns.show', ['id' => $campagneId]);
    }
}
