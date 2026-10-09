<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';

function dashboardEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function dashboardCsrfToken(): string
{
    if (empty($_SESSION['dashboard_csrf'])) {
        $_SESSION['dashboard_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['dashboard_csrf'];
}

function dashboardLinkedAccounts(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT pa.id, pa.AcctNo, pa.account_nickname, pa.is_primary,
        m.Name, m.Address, m.Status, m.AcctCode
        FROM paleco_accounts pa LEFT JOIN master m ON m.AcctNo = pa.AcctNo
        WHERE pa.user_id = ? AND pa.status = ?
        ORDER BY pa.is_primary DESC, pa.created_at ASC, pa.id ASC');
    $stmt->execute([$userId, 'active']);
    return $stmt->fetchAll();
}

function dashboardSelectedAccount(array $accounts): ?array
{
    if (!$accounts) {
        unset($_SESSION['SelectedAcctNo']);
        return null;
    }
    $selected = $_SESSION['SelectedAcctNo'] ?? null;
    foreach ($accounts as $account) {
        if ($selected !== null && (string) $account['AcctNo'] === (string) $selected) {
            return $account;
        }
    }
    $_SESSION['SelectedAcctNo'] = $accounts[0]['AcctNo'];
    return $accounts[0];
}


function dashboardIssueAccountSelectionTokens(array $accounts): array
{
    $tokens = [];
    $sessionMap = [];

    foreach ($accounts as $account) {
        $acctNo = trim((string) ($account['AcctNo'] ?? ''));
        if ($acctNo === '') {
            continue;
        }
        $token = bin2hex(random_bytes(32));
        $tokens[$acctNo] = $token;
        $sessionMap[$token] = $acctNo;
    }

    $_SESSION['dashboard_account_selection_tokens'] = $sessionMap;
    return $tokens;
}

function dashboardResolveAccountSelectionToken(string $token): ?string
{
    $tokens = $_SESSION['dashboard_account_selection_tokens'] ?? [];
    if (!is_array($tokens) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $acctNo = $tokens[$token] ?? null;
    return is_string($acctNo) && $acctNo !== '' ? $acctNo : null;
}function dashboardReturn(string $page, string $message, string $type = 'success'): void
{
    $_SESSION['dashboard_flash'] = ['message' => $message, 'type' => $type];
    header('Location: dashboard.php?page=' . rawurlencode($page));
    exit;
}
