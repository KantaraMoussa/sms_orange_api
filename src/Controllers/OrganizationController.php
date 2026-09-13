<?php

namespace App\Controllers;

use App\Core\Controller;

class OrganizationController extends Controller
{
    public function index(): void
    {
        $this->view('organization/index', [
            'org' => organizations()->find(auth()->organizationId()),
        ]);
    }

    public function update(): void
    {
        $this->requireCsrf();
        $this->requireRole(\App\Services\AuthService::MANAGEMENT_ROLES);

        // Champ par champ, seulement si soumis : le formulaire "Alertes" ne
        // poste que org_nom (obligatoire) et org_low_balance_threshold —
        // construire l'update avec tous les champs organization_id => valeur
        // ?? null effacerait secteur/téléphone/etc. à chaque enregistrement
        // du seuil d'alerte.
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
        activityLog()->log('modification_organisation', null, $this->actor());
        $this->flash('alert alert-success', '✅ Informations de l\'organisation mises à jour.');
        $this->redirect('organization.index');
    }
}
