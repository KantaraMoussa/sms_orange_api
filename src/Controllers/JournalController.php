<?php

namespace App\Controllers;

use App\Core\Controller;

class JournalController extends Controller
{
    public function index(): void
    {
        $this->view('journal/index', [
            'logs' => activityLog()->recent(200),
            'actionLabels' => [
                'connexion' => 'Connexion',
                'deconnexion' => 'Déconnexion',
                'creation_campagne' => 'Création de campagne',
                'creation_campagne_resultats' => 'Création de campagne (résultats)',
                'lancement_campagne' => "Lancement d'envoi",
                'pause_campagne' => 'Mise en pause',
                'reprise_campagne' => 'Reprise',
                'annulation_campagne' => 'Annulation',
                'retry_campagne' => 'Réessai des échecs',
                'import_resultats' => 'Import de résultats',
                'creation_modele' => 'Création de modèle SMS',
                'modification_modele' => 'Modification de modèle SMS',
                'duplication_modele' => 'Duplication de modèle SMS',
                'archivage_modele' => 'Archivage de modèle SMS',
                'desarchivage_modele' => 'Réactivation de modèle SMS',
            ],
        ]);
    }
}
