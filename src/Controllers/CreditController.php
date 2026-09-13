<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Services\CreditService;
use Exception;

class CreditController extends Controller
{
    public function index(): void
    {
        $isSuperAdmin = auth()->hasRole(['SUPER_ADMIN']);

        $this->view('credits/index', [
            'balance' => credits()->balance(),
            'history' => credits()->history(50),
            'isSuperAdmin' => $isSuperAdmin,
            'allOrganizations' => $isSuperAdmin ? organizations()->all() : [],
        ]);
    }

    public function recharge(): void
    {
        $this->requireCsrf();
        $this->requireRole(['SUPER_ADMIN']);

        $targetOrgId = (int) ($_POST['credit_organization_id'] ?? 0);
        $amount = (int) ($_POST['credit_amount'] ?? 0);
        $description = trim($_POST['credit_description'] ?? '') ?: 'Recharge manuelle';

        if (!organizations()->find($targetOrgId)) {
            $this->flash('alert alert-danger', '❌ Organisation introuvable.');
            $this->redirect('credits.index');
        }

        try {
            $newBalance = (new CreditService(db(), $targetOrgId))->credit($amount, $description, $this->actor());
            activityLog()->log('recharge_credits', null, $this->actor(), "organisation #$targetOrgId +$amount (solde: $newBalance)");
            $this->flash('alert alert-success', "✅ $amount crédit(s) ajouté(s). Nouveau solde : $newBalance.");
        } catch (Exception $e) {
            $this->flash('alert alert-danger', '❌ ' . $e->getMessage());
        }
        $this->redirect('credits.index');
    }
}
