<?php

namespace App\Controllers;

use App\Core\Controller;

class SegmentController extends Controller
{
    public function index(): void
    {
        $this->view('segments/index', [
            'segmentsList' => segments()->all(),
            'groupesDisponibles' => contacts()->allGroups(),
        ]);
    }

    public function create(): void
    {
        $this->requireMutationRole();
        $this->requireCsrf();

        $nom = trim($_POST['segment_nom'] ?? '');
        if ($nom === '') {
            $this->flash('alert alert-warning', 'Le nom du segment est obligatoire.');
            $this->redirectBack();
        }

        $criteria = [
            'search' => trim($_POST['criteria_search'] ?? ''),
            'statut' => trim($_POST['criteria_statut'] ?? ''),
            'groupe_id' => $_POST['criteria_groupe_id'] ?? null,
            'created_after' => trim($_POST['criteria_created_after'] ?? ''),
            'created_before' => trim($_POST['criteria_created_before'] ?? ''),
        ];
        $id = segments()->create($nom, $criteria, $this->actor());
        activityLog()->log('creation_segment', null, $this->actor(), $nom);
        $this->flash('alert alert-success', "✅ Segment « $nom » créé (" . segments()->countContacts($id) . " contact(s) actuellement).");
        $this->redirect('segments.index');
    }

    public function delete(): void
    {
        $this->requireMutationRole();
        $this->requireCsrf();

        $id = (int) ($_POST['segment_id'] ?? 0);
        segments()->delete($id);
        activityLog()->log('suppression_segment', null, $this->actor(), "segment #$id");
        $this->flash('alert alert-success', '✅ Segment supprimé.');
        $this->redirect('segments.index');
    }

    public function previewCount(): void
    {
        $criteria = [
            'search' => $_GET['search'] ?? null,
            'statut' => $_GET['statut'] ?? null,
            'groupe_id' => $_GET['groupe_id'] ?? null,
            'created_after' => $_GET['created_after'] ?? null,
            'created_before' => $_GET['created_before'] ?? null,
        ];

        $this->json(['count' => segments()->previewCount($criteria)]);
    }
}
