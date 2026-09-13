<div class="row">
    <div class="col-xl-8">
        <div class="card">
            <div class="card-header">
                <h5>Membres de l'équipe</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Nom</th>
                                <th>Email</th>
                                <th>Rôle</th>
                                <th>Depuis</th>
                                <?php if ($canManage): ?><th>Actions</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($members as $m): ?>
                                <tr>
                                    <td><?= htmlspecialchars($m['nom']) ?><?= (int) $m['id'] === $currentUserId ? ' <span class="badge bg-light-primary">vous</span>' : '' ?></td>
                                    <td><?= htmlspecialchars($m['email']) ?></td>
                                    <td>
                                        <?php if ($canManage): ?>
                                            <form method="post" action="<?= route('team.updateRole') ?>" class="d-flex gap-1">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="member_id" value="<?= (int) $m['id'] ?>">
                                                <select name="member_role" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
                                                    <?php foreach (\App\Services\AuthService::ROLES as $r): ?>
                                                        <option value="<?= $r ?>" <?= $r === $m['role'] ? 'selected' : '' ?>><?= htmlspecialchars($roleLabels[$r] ?? $r) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" name="update_team_member_role" class="visually-hidden">Mettre à jour</button>
                                            </form>
                                        <?php else: ?>
                                            <?= htmlspecialchars($roleLabels[$m['role']] ?? $m['role']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($m['date_creation']) ?></td>
                                    <?php if ($canManage): ?>
                                        <td>
                                            <?php if ((int) $m['id'] !== $currentUserId): ?>
                                                <form method="post" action="<?= route('team.delete') ?>" onsubmit="return confirm('Retirer <?= htmlspecialchars(addslashes($m['nom'])) ?> de l\'équipe ?');">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="member_id" value="<?= (int) $m['id'] ?>">
                                                    <button type="submit" name="delete_team_member" class="btn btn-sm btn-light-danger"><i class="ph ph-trash"></i></button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php if ($canManage): ?>
    <div class="col-xl-4">
        <div class="card">
            <div class="card-header">
                <h5>Ajouter un membre</h5>
            </div>
            <div class="card-body">
                <form method="post" action="<?= route('team.create') ?>">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label">Nom *</label>
                        <input type="text" name="member_nom" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email *</label>
                        <input type="email" name="member_email" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Mot de passe temporaire *</label>
                        <input type="password" name="member_password" class="form-control" minlength="8" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Rôle</label>
                        <select name="member_role" class="form-select">
                            <?php foreach (\App\Services\AuthService::ROLES as $r): if ($r === 'SUPER_ADMIN') continue; ?>
                                <option value="<?= $r ?>" <?= $r === 'VIEWER' ? 'selected' : '' ?>><?= htmlspecialchars($roleLabels[$r] ?? $r) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" name="create_team_member" class="btn btn-primary">Ajouter</button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
