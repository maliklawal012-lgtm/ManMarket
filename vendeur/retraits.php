<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/wallet_bootstrap.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/rate_limit.php';

$vendorUser = require_vendor();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

$vendor = wallet_vendor_repo()->findByUserId((int) $vendorUser['id']);

$withdrawalMethods = [
    'wave' => ['label' => 'Wave', 'logo' => 'assets/images/payment-logos/wave.svg'],
    'orange_money' => ['label' => 'Orange Money', 'logo' => 'assets/images/payment-logos/orange.svg'],
    'mtn_money' => ['label' => 'MTN Money', 'logo' => 'assets/images/payment-logos/mtn.svg'],
    'moov_money' => ['label' => 'Moov Money', 'logo' => null],
];

$requestError = null;

if ($vendor && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request') {
    $amount = (int) ($_POST['amount'] ?? 0);
    $method = trim((string) ($_POST['payment_method'] ?? ''));
    $accountNumber = trim((string) ($_POST['account_number'] ?? ''));

    if (!rate_limit_check('withdrawal:' . $vendor['id'], 5, 3600)) {
        $requestError = 'Trop de demandes de retrait récentes. Veuillez réessayer dans quelques instants.';
        $result = null;
    } else {
        $result = wallet_withdrawal_service()->requestWithdrawal((int) $vendor['id'], $amount, $method, $accountNumber);
    }

    if ($result && $result->ok) {
        header('Location: /market/vendeur/retraits');
        exit;
    }
    if ($result) {
        $requestError = $result->error;
    }
}

$pageTitle = 'Retraits';
require_once __DIR__ . '/../includes/vendor_header.php';

$wallet = null;
$spendable = 0;
$withdrawals = [];
$transactions = [];

if ($vendor) {
    $vendorId = (int) $vendor['id'];
    $wallet = wallet_service()->getOrCreateWallet($vendorId);
    $openReserved = wallet_withdrawal_repo()->openReservedAmountForVendor($vendorId);
    $spendable = max(0, (int) round((float) $wallet['available_balance']) - $openReserved);
    $withdrawals = wallet_withdrawal_repo()->findByVendorId($vendorId);
    $transactions = wallet_transaction_repo()->findByVendorId($vendorId, 20);
}
?>

<?php if (!$vendor): ?>
    <div class="card">
        <p class="empty-state">Votre compte vendeur n'est pas encore rattaché à une boutique. Contactez l'équipe ManMarket pour finaliser la configuration de votre espace.</p>
    </div>
<?php else: ?>

    <div class="admin-stats-grid" style="grid-template-columns: repeat(3, 1fr);">
        <div class="card admin-stat-card">
            <span class="admin-stat-icon" style="background:#e8f8ee; color:#16a34a;"><?= icon('cart', 18) ?></span>
            <span class="admin-stat-value"><?= format_price($spendable) ?></span>
            <span class="admin-stat-label">Disponible pour retrait</span>
        </div>
        <div class="card admin-stat-card">
            <span class="admin-stat-icon" style="background:#fef3c7; color:#92400e;"><?= icon('clock', 18) ?></span>
            <span class="admin-stat-value"><?= format_price((int) round((float) $wallet['pending_balance'])) ?></span>
            <span class="admin-stat-label">En attente (commandes non encore livrées)</span>
        </div>
        <div class="card admin-stat-card">
            <span class="admin-stat-icon" style="background:#e0e7ff; color:#4338ca;"><?= icon('check-circle', 18) ?></span>
            <span class="admin-stat-value"><?= format_price((int) round((float) $wallet['total_withdrawn'])) ?></span>
            <span class="admin-stat-label">Total déjà retiré</span>
        </div>
    </div>

    <p class="char-count">Le solde disponible correspond à vos ventes en ligne réglées par les clients, moins la commission ManMarket (<?= number_format((float) ($wallet['total_commission_paid'] ?? 0)) ?> FCFA prélevés au total) et les retraits déjà demandés. Les commandes payées à la livraison ne sont pas comptées ici : vous avez déjà reçu ce paiement directement.</p>

    <div class="admin-dashboard-grid">
        <div class="card">
            <div class="admin-toolbar">
                <h2>Demander un retrait</h2>
            </div>

            <?php if ($requestError): ?>
                <div class="alert alert-error"><?= icon('x', 16) ?><span><?= e($requestError) ?></span></div>
            <?php endif; ?>

            <?php if ($spendable <= 0): ?>
                <p class="empty-state">Aucun solde disponible pour le moment.</p>
            <?php else: ?>
                <form method="post" action="/market/vendeur/retraits" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="request">

                    <div class="form-field">
                        <label for="amount">Montant à retirer (FCFA) *</label>
                        <input type="number" id="amount" name="amount" min="1" max="<?= $spendable ?>" required>
                    </div>

                    <div class="form-field">
                        <label>Moyen de réception *</label>
                        <div class="payment-choice-options payment-choice-options-grid">
                            <?php foreach ($withdrawalMethods as $methodKey => $method): ?>
                                <label class="payment-choice-option">
                                    <input type="radio" name="payment_method" value="<?= e($methodKey) ?>" required>
                                    <span>
                                        <?php if ($method['logo']): ?>
                                            <img src="/market/<?= e($method['logo']) ?>" alt="" class="payment-method-logo">
                                        <?php else: ?>
                                            <?= icon('cart', 16) ?>
                                        <?php endif; ?>
                                        <?= e($method['label']) ?>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="form-field">
                        <label for="account_number">Numéro de réception *</label>
                        <input type="tel" id="account_number" name="account_number" required>
                    </div>

                    <button type="submit" class="btn btn-primary">Envoyer la demande</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="admin-toolbar">
                <h2>Historique des retraits</h2>
            </div>

            <?php if (!$withdrawals): ?>
                <p class="empty-state">Aucune demande de retrait pour le moment.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Montant</th>
                                <th>Moyen</th>
                                <th>Statut</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($withdrawals as $w): ?>
                                <tr>
                                    <td><?= e(date('d/m/Y H:i', strtotime((string) $w['created_at']))) ?></td>
                                    <td><?= format_price((int) round((float) $w['amount'])) ?></td>
                                    <td><?= e($withdrawalMethods[$w['payment_method']]['label'] ?? $w['payment_method']) ?> — <?= e($w['account_number']) ?></td>
                                    <td>
                                        <span class="tag <?= wallet_withdrawal_status_tag_class($w['status']) ?>"><?= e(wallet_withdrawal_status_label($w['status'])) ?></span>
                                        <?php if ($w['admin_note']): ?><br><span class="char-count"><?= e($w['admin_note']) ?></span><?php endif; ?>
                                    </td>
                                    <td><a href="/market/vendeur/retrait-detail?id=<?= (int) $w['id'] ?>" class="btn btn-outline-primary btn-sm">Détail</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="admin-toolbar">
            <h2>Dernières transactions du portefeuille</h2>
        </div>

        <?php if (!$transactions): ?>
            <p class="empty-state">Aucune transaction pour le moment.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Montant</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $tx): ?>
                            <tr>
                                <td><?= e(date('d/m/Y H:i', strtotime((string) $tx['created_at']))) ?></td>
                                <td><?= e(wallet_transaction_type_label($tx['type'])) ?></td>
                                <td style="color: <?= (float) $tx['amount'] >= 0 ? '#16a34a' : '#dc2626' ?>;"><?= (float) $tx['amount'] >= 0 ? '+' : '' ?><?= format_price((int) round((float) $tx['amount'])) ?></td>
                                <td class="wrap"><?= e($tx['description']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/vendor_footer.php'; ?>
