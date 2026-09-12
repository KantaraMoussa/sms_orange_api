<?php
// Paramètres de l'organisation (cahier des charges V2.0 §7). Toute écriture
// passe par server/app.php::update_organisation, scopée à
// auth()->organizationId() — un utilisateur ne peut jamais modifier une autre
// organisation que la sienne (pas d'id d'organisation dans le formulaire).
$org = organizations()->find(auth()->organizationId());
?>
<div class="row">
    <div class="col-xl-8">
        <div class="card">
            <div class="card-header">
                <h5>Informations de l'entreprise</h5>
            </div>
            <div class="card-body">
                <form method="post" action="../server/app.php">
                    <?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Nom de l'entreprise *</label>
                            <input type="text" name="org_nom" class="form-control" value="<?= htmlspecialchars($org['nom'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Secteur d'activité</label>
                            <input type="text" name="org_secteur" class="form-control" value="<?= htmlspecialchars($org['secteur'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Téléphone</label>
                            <input type="text" name="org_telephone" class="form-control" value="<?= htmlspecialchars($org['telephone'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="org_email" class="form-control" value="<?= htmlspecialchars($org['email'] ?? '') ?>">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Adresse</label>
                            <input type="text" name="org_adresse" class="form-control" value="<?= htmlspecialchars($org['adresse'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Pays</label>
                            <input type="text" name="org_pays" class="form-control" value="<?= htmlspecialchars($org['pays'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Fuseau horaire</label>
                            <input type="text" name="org_fuseau_horaire" class="form-control" value="<?= htmlspecialchars($org['fuseau_horaire'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Devise</label>
                            <input type="text" name="org_devise" class="form-control" value="<?= htmlspecialchars($org['devise'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Nom d'expéditeur SMS (sender name)</label>
                            <input type="text" name="org_sender_name" class="form-control" value="<?= htmlspecialchars($org['sender_name'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="mt-4">
                        <button type="submit" name="update_organisation" class="btn btn-primary">Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card">
            <div class="card-body">
                <h6 class="text-muted">Organisation</h6>
                <p class="mb-1">Créée le <?= htmlspecialchars($org['created_at'] ?? '—') ?></p>
                <p class="mb-0 text-muted small">Vos contacts, campagnes, modèles et rapports sont visibles uniquement par les membres de votre organisation.</p>
            </div>
        </div>
    </div>
</div>
