<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/wallet_bootstrap.php';

$currentAdmin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}
$db = get_db();
$saveError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_role') {
    $id = (int) ($_POST['id'] ?? 0);
    $isVendor = isset($_POST['is_vendor']) ? 1 : 0;
    $isAdmin = isset($_POST['is_admin']) ? 1 : 0;
    $isBlocked = isset($_POST['is_blocked']) ? 1 : 0;
    $blockedReason = trim((string) ($_POST['blocked_reason'] ?? ''));
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    $previousStmt = $db->prepare('SELECT is_blocked, is_admin FROM users WHERE id = :id');
    $previousStmt->execute(['id' => $id]);
    $previous = $previousStmt->fetch();
    $wasBlocked = (int) ($previous['is_blocked'] ?? 0) === 1;
    $wasAdmin = (int) ($previous['is_admin'] ?? 0) === 1;
    $adminRoleChanging = $isAdmin !== ($wasAdmin ? 1 : 0);

    if ($id === (int) $currentAdmin['id'] && (!$isAdmin || $isBlocked)) {
        $saveError = "Vous ne pouvez pas retirer vos propres droits administrateur ni vous bloquer vous-même.";
    } elseif ($adminRoleChanging && !$currentAdmin['is_super_admin']) {
        $saveError = "Seul un super-administrateur peut accorder ou retirer le statut administrateur.";
    } elseif ($adminRoleChanging) {
        // Action la plus sensible du panneau admin : reconfirmation par mot
        // de passe, en plus du role super-admin deja verifie ci-dessus.
        $currentHashStmt = $db->prepare('SELECT password_hash FROM users WHERE id = :id');
        $currentHashStmt->execute(['id' => $currentAdmin['id']]);
        $currentHash = (string) $currentHashStmt->fetchColumn();
        if (!password_verify($confirmPassword, $currentHash)) {
            $saveError = 'Mot de passe incorrect — le changement de statut administrateur n\'a pas été appliqué.';
        }
    }

    if (!$saveError) {
        $stmt = $db->prepare('
            UPDATE users
            SET is_vendor = :is_vendor, is_admin = :is_admin, is_blocked = :is_blocked, blocked_reason = :reason
            WHERE id = :id
        ');
        $stmt->execute([
            'is_vendor' => $isVendor,
            'is_admin' => $isAdmin,
            'is_blocked' => $isBlocked,
            'reason' => $isBlocked && $blockedReason !== '' ? $blockedReason : null,
            'id' => $id,
        ]);

        if (!$wasBlocked && $isBlocked === 1) {
            wallet_audit_log_repo()->record((int) $currentAdmin['id'], 'user_blocked', 'user', $id, $blockedReason !== '' ? $blockedReason : null, $_SERVER['REMOTE_ADDR'] ?? null);
        } elseif ($wasBlocked && $isBlocked === 0) {
            wallet_audit_log_repo()->record((int) $currentAdmin['id'], 'user_unblocked', 'user', $id, null, $_SERVER['REMOTE_ADDR'] ?? null);
        }

        if (!$wasAdmin && $isAdmin === 1) {
            wallet_audit_log_repo()->record((int) $currentAdmin['id'], 'user_admin_granted', 'user', $id, null, $_SERVER['REMOTE_ADDR'] ?? null);
        } elseif ($wasAdmin && $isAdmin === 0) {
            wallet_audit_log_repo()->record((int) $currentAdmin['id'], 'user_admin_revoked', 'user', $id, null, $_SERVER['REMOTE_ADDR'] ?? null);
        }

        header('Location: /market/admin/utilisateurs');
        exit;
    }
}

$typeFilter = ($_GET['type'] ?? '') === 'vendeur' ? 'vendeur' : '';
$pageTitle = $typeFilter === 'vendeur' ? 'Commerçants' : 'Utilisateurs';

$editing = null;
$editingShops = [];
$loginHistory = [];
$userOrders = [];
$userNewOrders = [];
$userOrderStats = ['order_count' => 0, 'total_spent' => 0];
$userMessages = [];
$userReviews = [];

if (($_GET['action'] ?? '') === 'edit' && isset($_GET['id'])) {
    $stmt = $db->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->execute(['id' => (int) $_GET['id']]);
    $editing = $stmt->fetch() ?: null;

    if ($editing) {
        $editId = (int) $editing['id'];

        $stmt = $db->prepare('SELECT id, name, neighborhood FROM shops WHERE owner_id = :id');
        $stmt->execute(['id' => $editId]);
        $editingShops = $stmt->fetchAll();

        $stmt = $db->prepare('SELECT * FROM login_history WHERE user_id = :id ORDER BY created_at DESC LIMIT 10');
        $stmt->execute(['id' => $editId]);
        $loginHistory = $stmt->fetchAll();

        $stmt = $db->prepare("SELECT * FROM contact_messages WHERE user_id = :id AND subject = 'Commande' ORDER BY created_at DESC LIMIT 10");
        $stmt->execute(['id' => $editId]);
        $userOrders = $stmt->fetchAll();

        $stmt = $db->prepare('
            SELECT o.*, (SELECT p.status FROM payments p WHERE p.order_id = o.id ORDER BY p.id DESC LIMIT 1) AS payment_status_live
            FROM orders o
            WHERE o.customer_user_id = :id
            ORDER BY o.created_at DESC LIMIT 10
        ');
        $stmt->execute(['id' => $editId]);
        $userNewOrders = $stmt->fetchAll();

        $stmt = $db->prepare("
            SELECT COUNT(*) AS order_count, COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN total_amount ELSE 0 END), 0) AS total_spent
            FROM orders WHERE customer_user_id = :id
        ");
        $stmt->execute(['id' => $editId]);
        $userOrderStats = $stmt->fetch();

        $stmt = $db->prepare("SELECT * FROM contact_messages WHERE user_id = :id AND subject != 'Commande' ORDER BY created_at DESC LIMIT 10");
        $stmt->execute(['id' => $editId]);
        $userMessages = $stmt->fetchAll();

        $stmt = $db->prepare('
            SELECT r.*, p.name AS product_name, p.slug AS product_slug
            FROM reviews r JOIN products p ON p.id = r.product_id
            WHERE r.user_id = :id ORDER BY r.created_at DESC LIMIT 10
        ');
        $stmt->execute(['id' => $editId]);
        $userReviews = $stmt->fetchAll();
    }
}

require_once __DIR__ . '/../includes/admin_header.php';

if (!$editing) {
    $whereClause = $typeFilter === 'vendeur' ? ' WHERE u.is_vendor = 1' : '';
    $pagination = paginate((int) $db->query('SELECT COUNT(*) FROM users u' . $whereClause)->fetchColumn(), 20);
    $stmt = $db->prepare('
        SELECT u.*, (SELECT s.name FROM shops s WHERE s.owner_id = u.id ORDER BY s.id LIMIT 1) AS shop_name
        FROM users u' . $whereClause . '
        ORDER BY u.created_at DESC
        LIMIT :limit OFFSET :offset
    ');
    $stmt->bindValue('limit', $pagination['per_page'], PDO::PARAM_INT);
    $stmt->bindValue('offset', $pagination['offset'], PDO::PARAM_INT);
    $stmt->execute();
    $users = $stmt->fetchAll();
}
?>

<?php if ($editing): ?>

    <div class="card" style="margin-bottom: var(--gap);">
        <div class="admin-toolbar">
            <h2><?= e($editing['name']) ?></h2>
            <a href="/market/admin/utilisateurs" class="link-more"><?= icon('chevron-right', 14) ?> Retour à la liste</a>
        </div>

        <?php if ($saveError): ?>
            <div class="alert alert-error"><?= icon('x', 18) ?><span><?= e($saveError) ?></span></div>
        <?php endif; ?>

        <?php if ($editing['is_blocked']): ?>
            <div class="alert alert-error"><?= icon('x', 18) ?><span>Ce compte est actuellement bloqué<?= $editing['blocked_reason'] ? ' — ' . e($editing['blocked_reason']) : '' ?>.</span></div>
        <?php endif; ?>

        <ul class="account-info-list">
            <li><span class="account-info-label"><?= icon('send', 16) ?> Email</span><span><?= e($editing['email']) ?></span></li>
            <li><span class="account-info-label"><?= icon('phone', 16) ?> Téléphone</span><span><?= e($editing['phone'] ?? 'Non renseigné') ?></span></li>
            <li><span class="account-info-label"><?= icon('clock', 16) ?> Inscrit le</span><span><?= e(date('d/m/Y', strtotime((string) $editing['created_at']))) ?></span></li>
            <li><span class="account-info-label"><?= icon('clock', 16) ?> Statut</span><span><?= connection_status_html($editing['last_activity_at'], $editing['last_login_at']) ?></span></li>
        </ul>

        <?php if (!empty($editingShops)): ?>
            <p class="char-count">Boutique(s) dont cet utilisateur est propriétaire : <?php foreach ($editingShops as $s): ?><strong><?= e($s['name']) ?></strong> <?php endforeach; ?> — à modifier depuis <a href="/market/admin/boutiques">Boutiques</a>.</p>
        <?php endif; ?>

        <div class="admin-stats-grid" style="grid-template-columns: repeat(3, 1fr); margin: 14px 0;">
            <div>
                <span class="admin-stat-value" style="font-size:1.3rem;"><?= (int) $userOrderStats['order_count'] ?></span>
                <span class="admin-stat-label">Commande(s) (nouveau système)</span>
            </div>
            <div>
                <span class="admin-stat-value" style="font-size:1.3rem;"><?= format_price((int) round((float) $userOrderStats['total_spent'])) ?></span>
                <span class="admin-stat-label">Total dépensé (payé en ligne)</span>
            </div>
            <div>
                <span class="admin-stat-value" style="font-size:1.3rem;"><?= count($userReviews) ?></span>
                <span class="admin-stat-label">Avis déposé(s) (10 derniers)</span>
            </div>
        </div>

        <form method="post" action="/market/admin/utilisateurs">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_role">
            <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">

            <div class="form-field">
                <label class="filter-toggle">
                    <input type="checkbox" name="is_vendor" value="1" <?= $editing['is_vendor'] ? 'checked' : '' ?>>
                    <span>Compte vendeur (peut se voir assigner une boutique)</span>
                </label>
            </div>
            <div class="form-field">
                <?php if ($currentAdmin['is_super_admin']): ?>
                    <label class="filter-toggle">
                        <input type="checkbox" name="is_admin" value="1" <?= $editing['is_admin'] ? 'checked' : '' ?>>
                        <span>Administrateur (accès complet à ce panneau)</span>
                    </label>
                    <span class="char-count">Changer ce statut demande de reconfirmer votre mot de passe ci-dessous.</span>
                <?php else: ?>
                    <label class="filter-toggle">
                        <span>Administrateur : <strong><?= $editing['is_admin'] ? 'Oui' : 'Non' ?></strong></span>
                    </label>
                    <?php if ($editing['is_admin']): ?><input type="hidden" name="is_admin" value="1"><?php endif; ?>
                    <span class="char-count">Seul un super-administrateur peut modifier ce statut.</span>
                <?php endif; ?>
            </div>
            <div class="form-field">
                <label class="filter-toggle">
                    <input type="checkbox" name="is_blocked" value="1" <?= $editing['is_blocked'] ? 'checked' : '' ?>>
                    <span>Compte bloqué (connexion refusée, session actuelle immédiatement invalidée)</span>
                </label>
            </div>
            <div class="form-field">
                <label for="blocked_reason">Motif du blocage (optionnel, affiché à l'utilisateur)</label>
                <input type="text" id="blocked_reason" name="blocked_reason" value="<?= e((string) ($editing['blocked_reason'] ?? '')) ?>" placeholder="Ex : Non-respect des conditions d'utilisation">
            </div>

            <?php if ($currentAdmin['is_super_admin']): ?>
                <div class="form-field">
                    <label for="confirm_password">Votre mot de passe (uniquement requis pour changer le statut administrateur)</label>
                    <input type="password" id="confirm_password" name="confirm_password" autocomplete="current-password">
                </div>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary">Enregistrer</button>
        </form>
    </div>

    <div class="admin-dashboard-grid">
        <div class="card">
            <div class="admin-toolbar">
                <h2>Historique de connexion</h2>
            </div>
            <?php if (!$loginHistory): ?>
                <p class="empty-state">Aucune connexion enregistrée.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead><tr><th>Date</th><th>Adresse IP</th></tr></thead>
                        <tbody>
                            <?php foreach ($loginHistory as $login): ?>
                                <tr>
                                    <td><?= e(date('d/m/Y à H:i', strtotime((string) $login['created_at']))) ?></td>
                                    <td><?= e($login['ip_address'] ?? '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="admin-toolbar">
                <h2>Commandes (archive)</h2>
            </div>
            <?php if (!$userOrders): ?>
                <p class="empty-state">Aucune commande archivée.</p>
            <?php else: ?>
                <div class="admin-activity-list">
                    <?php foreach ($userOrders as $order): ?>
                        <div class="admin-activity-item">
                            <span class="admin-activity-icon"><?= icon('cart', 15) ?></span>
                            <div>
                                <div class="admin-activity-text"><span class="tag <?= order_status_tag_class($order['status']) ?>"><?= e(order_status_label($order['status'])) ?></span></div>
                                <div class="admin-activity-time"><?= e(date('d/m/Y à H:i', strtotime((string) $order['created_at']))) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="admin-dashboard-grid" style="margin-top: var(--gap);">
    <div class="col">
        <div class="card" style="margin-bottom: var(--gap);">
            <div class="admin-toolbar">
                <h2>Commandes (nouveau système)</h2>
            </div>
            <?php if (!$userNewOrders): ?>
                <p class="empty-state">Aucune commande sur le nouveau système.</p>
            <?php else: ?>
                <div class="admin-activity-list">
                    <?php foreach ($userNewOrders as $order): ?>
                        <div class="admin-activity-item">
                            <span class="admin-activity-icon"><?= icon('cart', 15) ?></span>
                            <div>
                                <div class="admin-activity-text">
                                    <?= format_price((int) round((float) $order['total_amount'])) ?>
                                    <span class="tag <?= order_status_tag_class($order['fulfillment_status']) ?>"><?= e(order_status_label($order['fulfillment_status'])) ?></span>
                                    <?php if ($order['payment_status_live']): ?>
                                        <span class="tag <?= payment_status_tag_class($order['payment_status_live']) ?>"><?= e(payment_status_label($order['payment_status_live'])) ?></span>
                                    <?php else: ?>
                                        <span class="char-count">À la livraison</span>
                                    <?php endif; ?>
                                </div>
                                <div class="admin-activity-time"><?= e(date('d/m/Y à H:i', strtotime((string) $order['created_at']))) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="admin-toolbar">
                <h2>Messages envoyés</h2>
            </div>
            <?php if (!$userMessages): ?>
                <p class="empty-state">Aucun message.</p>
            <?php else: ?>
                <div class="admin-activity-list">
                    <?php foreach ($userMessages as $msg): ?>
                        <div class="admin-activity-item">
                            <span class="admin-activity-icon"><?= icon('send', 15) ?></span>
                            <div>
                                <div class="admin-activity-text"><?= e($msg['subject']) ?> — <?= e(mb_strimwidth($msg['message'], 0, 60, '…')) ?></div>
                                <div class="admin-activity-time"><?= e(date('d/m/Y à H:i', strtotime((string) $msg['created_at']))) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

        <div class="card">
            <div class="admin-toolbar">
                <h2>Avis déposés</h2>
            </div>
            <?php if (!$userReviews): ?>
                <p class="empty-state">Aucun avis.</p>
            <?php else: ?>
                <div class="admin-activity-list">
                    <?php foreach ($userReviews as $review): ?>
                        <div class="admin-activity-item">
                            <span class="admin-activity-icon"><?= icon('star-filled', 15) ?></span>
                            <div>
                                <div class="admin-activity-text"><a href="/market/produit?slug=<?= e($review['product_slug']) ?>" class="link-muted"><?= e($review['product_name']) ?></a> <?= render_stars((float) $review['rating']) ?></div>
                                <div class="admin-activity-time"><?= e(date('d/m/Y à H:i', strtotime((string) $review['created_at']))) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php else: ?>

<div class="card">
    <div class="admin-toolbar">
        <h2><?= $typeFilter === 'vendeur' ? 'Commerçants' : 'Utilisateurs' ?> (<?= $pagination['total_items'] ?>)</h2>
        <div class="filter-sort">
            <label for="type-filter">Filtre</label>
            <select id="type-filter" onchange="location.href = this.value">
                <option value="/market/admin/utilisateurs" <?= $typeFilter === '' ? 'selected' : '' ?>>Tous</option>
                <option value="/market/admin/utilisateurs?type=vendeur" <?= $typeFilter === 'vendeur' ? 'selected' : '' ?>>Commerçants uniquement</option>
            </select>
        </div>
    </div>

    <?php if (!$users): ?>
        <p class="empty-state">Aucun utilisateur ne correspond à ce filtre.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Nom</th>
                        <th>Email</th>
                        <th>Rôle</th>
                        <th>Connexion</th>
                        <th>Inscrit le</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td><?= e($u['name']) ?><?php if ($u['is_blocked']): ?> <span class="tag tag-closed">Bloqué</span><?php endif; ?></td>
                            <td><?= e($u['email']) ?></td>
                            <td>
                                <?php if ($u['is_admin']): ?><span class="tag tag-green">Admin</span><?php endif; ?>
                                <?php if ($u['is_vendor']): ?><span class="tag tag-open">Vendeur</span><?php endif; ?>
                                <?php if (!$u['is_admin'] && !$u['is_vendor']): ?><span class="tag">Client</span><?php endif; ?>
                                <?php if ($u['is_vendor']): ?>
                                    <br><?= $u['shop_name'] ? '<span class="char-count">' . e($u['shop_name']) . '</span>' : '<span class="tag tag-pending">Aucune boutique</span>' ?>
                                <?php endif; ?>
                            </td>
                            <td><?= connection_status_html($u['last_activity_at'], $u['last_login_at']) ?></td>
                            <td><?= e(date('d/m/Y', strtotime((string) $u['created_at']))) ?></td>
                            <td><a href="/market/admin/utilisateurs?action=edit&id=<?= (int) $u['id'] ?>" class="btn btn-outline-primary btn-sm">Voir / Modifier</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= pagination_html($pagination['page'], $pagination['total_pages'], '/market/admin/utilisateurs', $typeFilter !== '' ? ['type' => $typeFilter] : []) ?>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
