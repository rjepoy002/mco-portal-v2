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

if (
    !str_contains($html, 'PALECO Account Code')
    || !str_contains($html, 'name="account_code"')
    || !str_contains($html, 'Meter Serial Number')
    || !str_contains($html, 'csrf_token')
) {
    throw new RuntimeException('Account management page did not render secure linking controls.');
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


$tokens = dashboardIssueAccountSelectionTokens([['AcctNo' => 'internal-test-account']]);
$token = $tokens['internal-test-account'] ?? '';
if (!preg_match('/^[a-f0-9]{64}$/', $token) || dashboardResolveAccountSelectionToken($token) !== 'internal-test-account') {
    throw new RuntimeException('Opaque account selection token did not resolve server-side.');
}

$dashboardSource = file_get_contents(__DIR__ . '/../dashboard.php');
if (
    !str_contains($dashboardSource, 'switch-account-modal')
    || str_contains($dashboardSource, 'account-selector')
    || str_contains($dashboardSource, 'name="account_number"')
    || !str_contains($dashboardSource, 'name="account_token"')
) {
    throw new RuntimeException('Modal selector or legacy account dropdown markup is incorrect.');
}
if (
    !str_contains($dashboardSource, "value=\"save_label\" data-friendly-label-action")
    || !str_contains($dashboardSource, "action?.value === 'remove_label'")
    || !str_contains($dashboardSource, "action.value = 'remove_label'")
    || str_contains($dashboardSource, "name=\"action\" value=\"rename\"")
) {
    throw new RuntimeException('Friendly Label save/remove action split is incomplete.');
}
if (dashboardFormatConsumerName('Padrones, Rosalino') !== 'PADRONES, ROSALINO') {
    throw new RuntimeException('Consumer name display formatting is incorrect.');
}
if (dashboardFormatServiceAddress('VILLA PRIN., STA. MONICA') !== 'Villa Prin., Sta. Monica') {
    throw new RuntimeException('Service address display formatting is incorrect.');
}
if (dashboardFormatAccountStatus('active') !== 'ACTIVE') {
    throw new RuntimeException('Account status display formatting is incorrect.');
}
if (dashboardDisplayAccountName(['account_nickname' => 'Main House', 'Name' => 'PADRONES']) !== 'Main House') {
    throw new RuntimeException('Friendly label capitalization was altered.');
}
echo "Dashboard smoke check passed.\n";
