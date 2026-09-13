<?php

namespace App\Controllers;

use App\Core\Controller;
use Exception;

class SmsSenderController extends Controller
{
    public function index(): void
    {
        $this->view('sms-sender/index', [
            'campagnesListe' => getCampagne(auth()->organizationId()),
        ]);
    }

    public function sendSingle(): void
    {
        $this->requireMutationRole();
        $this->requireCsrf();

        $pattern = "/^(\+224|00224)6\d{8}$/";
        $numero = trim($_POST['number']);
        $message = trim($_POST['message']);

        if ($numero == "" || $message == "") {
            $this->flash('alert alert-danger', 'Veuillez entrer le numéro de téléphone et le message .');
            $this->redirectBack();
        }

        if (!preg_match($pattern, $numero)) {
            $this->flash('alert alert-danger', "❌ Le numéro {$numero} est invalide  .");
            $this->redirectBack();
        }

        try {
            orangeSms()->sendSms($numero, $message);
        } catch (Exception $e) {
            $this->flash('alert alert-danger', "❌ Erreur d'envoi : " . $e->getMessage());
            $this->redirectBack();
        }

        $this->flash('alert alert-success', "✅ Message envoyé au {$numero} avec succéess .");
        $this->redirectBack();
    }
}
