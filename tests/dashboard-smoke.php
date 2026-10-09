<?php

session_save_path(sys_get_temp_dir());
session_start();
require_once __DIR__ . '/../config/database.php';

$userId = $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
if (!$userId) {
    throw new RuntimeException('A portal user is required for this local smoke check.');
}

$_SESSION['user_id'] = (int) $userId;
$_SESSION['user_name'] = 'Smoke Test';
require_once __DIR__ . '/../includes/account-context.php';
$_GET['page'] = 'accounts';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';

ob_start();
require __DIR__ . '/../dashboard.php';
$html = ob_get_clean();

if (!str_contains($html, 'Add PALECO Account') || !str_contains($html, 'csrf_token')) {
    throw new RuntimeException('Account management page did not render expected controls.');
}

$accounts = dashboardLinkedAccounts($pdo, (int) $userId);
if ($accounts) {
    $accountCode = trim((string) ($accounts[0]['AcctCode'] ?? ''));
    $expectedDisplay = $accountCode !== ''
        ? 'Account ' . htmlspecialchars($accountCode, ENT_QUOTES, 'UTF-8')
        : 'Account code unavailable';

    if (!str_contains($html, $expectedDisplay)) {
        throw new RuntimeException('Consumer-facing account code was not rendered safely.');
    }
}

$_SESSION['SelectedAcctNo'] = 'not-authorized';
if ($accounts) {
    $selected = dashboardSelectedAccount($accounts);
    if ($selected['AcctNo'] !== $accounts[0]['AcctNo']) {
        throw new RuntimeException('Invalid selection did not fall back to a linked account.');
    }
} elseif (dashboardSelectedAccount($accounts) !== null || isset($_SESSION['SelectedAcctNo'])) {
    throw new RuntimeException('Empty account list did not clear selection.');
}

echo "Dashboard smoke check passed.\n";
