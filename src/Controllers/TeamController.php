<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Services\AuthService;
use Exception;

class TeamController extends Controller
{
    public function index(): void
    {
        $this->view('team/index', [
            'canManage' => auth()->hasRole(AuthService::MANAGEMENT_ROLES),
            'members' => auth()->usersInOrganization(auth()->organizationId()),
            'currentUserId' => (int) auth()->user()['id'],
            'roleLabels' => [
                'SUPER_ADMIN' => 'Super admin',
                'OWNER' => 'Propriétaire',
                'ADMIN' => 'Administrateur',
                'CAMPAIGN_MANAGER' => 'Gestionnaire de campagnes',
                'OPERATOR' => 'Opérateur',
                'ANALYST' => 'Analyste',
                'VIEWER' => 'Lecture seule',
            ],
        ]);
    }

    public function create(): void
    {
        $this->requireCsrf();
        $this->requireRole(AuthService::MANAGEMENT_ROLES);

        $nom = trim($_POST['member_nom'] ?? '');
        $email = trim($_POST['member_email'] ?? '');
        $password = (string) ($_POST['member_password'] ?? '');
        $role = $_POST['member_role'] ?? 'VIEWER';

        if ($nom === '' || $email === '' || strlen($password) < 8 || !in_array($role, AuthService::ROLES, true)) {
            $this->flash('alert alert-warning', 'Nom, email, mot de passe (8 caractères min.) et rôle valide sont obligatoires.');
            $this->redirect('team.index');
        }

        try {
            auth()->createUser(auth()->organizationId(), $nom, $email, $password, $role);
            activityLog()->log('ajout_membre_equipe', null, $this->actor(), "$nom ($role)");
            $this->flash('alert alert-success', "✅ « $nom » a été ajouté à votre équipe.");
        } catch (Exception $e) {
            $this->flash('alert alert-danger', '❌ ' . $e->getMessage());
        }
        $this->redirect('team.index');
    }

    public function updateRole(): void
    {
        $this->requireCsrf();
        $this->requireRole(AuthService::MANAGEMENT_ROLES);

        $userId = (int) ($_POST['member_id'] ?? 0);
        $role = $_POST['member_role'] ?? '';

        try {
            auth()->updateUserRole(auth()->organizationId(), $userId, $role);
            activityLog()->log('modification_role_membre', null, $this->actor(), "utilisateur #$userId -> $role");
            $this->flash('alert alert-success', '✅ Rôle mis à jour.');
        } catch (Exception $e) {
            $this->flash('alert alert-danger', '❌ ' . $e->getMessage());
        }
        $this->redirect('team.index');
    }

    public function delete(): void
    {
        $this->requireCsrf();
        $this->requireRole(AuthService::MANAGEMENT_ROLES);

        $userId = (int) ($_POST['member_id'] ?? 0);

        if ($userId === (int) auth()->user()['id']) {
            $this->flash('alert alert-warning', 'Vous ne pouvez pas vous retirer vous-même de l\'équipe.');
            $this->redirect('team.index');
        }

        try {
            auth()->deleteUser(auth()->organizationId(), $userId);
            activityLog()->log('suppression_membre_equipe', null, $this->actor(), "utilisateur #$userId");
            $this->flash('alert alert-success', '✅ Membre retiré de l\'équipe.');
        } catch (Exception $e) {
            $this->flash('alert alert-danger', '❌ ' . $e->getMessage());
        }
        $this->redirect('team.index');
    }
}
