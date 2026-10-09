<?php

if (
    PHP_SAPI !== 'cli'
    && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__
) {
    http_response_code(404);
    exit;
}
/**
 * Account-linking input and session safeguards. Legacy master data remains read-only.
 */
function normalizePalecoAccCode(string $value): ?string
{
    $value = trim($value);

    if (
        $value === ''
        || !preg_match('/^(?:[A-Za-z0-9]{10}|[A-Za-z0-9]{2}-[A-Za-z0-9]{4}-[A-Za-z0-9]{4})$/', $value)
    ) {
        return null;
    }

    $normalized = strtoupper(str_replace('-', '', $value));

    return $normalized;
}

function palecoAccountVerificationSql(): string
{
    return <<<'SQL'
SELECT AcctNo, AcctCode, MeterSerial, Name, Status
FROM palecxzp_pal_db.master
WHERE REPLACE(TRIM(AcctCode), '-', '') = :acct_code
  AND TRIM(MeterSerial) = :meter_serial
LIMIT 1
SQL;
}

function normalizePalecoMeterSerial(string $value): ?string
{
    $value = trim($value);

    if ($value === '' || strlen($value) > 20 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
        return null;
    }

    return $value;
}

/**
 * DEVELOPMENT ONLY: Account-link verification throttling is intentionally disabled.
 * TODO: Re-enable this logic before production deployment.
 *
 * Original behavior retained below for restoration and review:
 * - 5 failed attempts within a 15-minute session window
 * - successful links clear the failure state
 */
function accountLinkRateLimited(): bool
{
    return false;
}

function recordAccountLinkFailure(): void
{
    // TODO: Re-enable account-link failure recording before production deployment.
}

/*
Original implementation:

function accountLinkRateLimited(): bool
{
    $state = $_SESSION['account_link_failures'] ?? null;

    if (!is_array($state)) {
        return false;
    }

    $windowStarted = (int) ($state['window_started'] ?? 0);
    $attempts = (int) ($state['attempts'] ?? 0);

    if ($windowStarted <= 0 || time() - $windowStarted >= 900) {
        unset($_SESSION['account_link_failures']);
        return false;
    }

    return $attempts >= 5;
}

function recordAccountLinkFailure(): void
{
    $state = $_SESSION['account_link_failures'] ?? [];
    $windowStarted = (int) ($state['window_started'] ?? 0);

    if ($windowStarted <= 0 || time() - $windowStarted >= 900) {
        $state = ['window_started' => time(), 'attempts' => 0];
    }

    $state['attempts'] = (int) ($state['attempts'] ?? 0) + 1;
    $_SESSION['account_link_failures'] = $state;
}
*/
function clearAccountLinkFailures(): void
{
    unset($_SESSION['account_link_failures']);
}

function accountLinkAcquireLock(PDO $pdo, string $acctNo): bool
{
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 5)');
    $lock->execute(['mco-account-link-' . hash('sha256', $acctNo)]);

    return (int) $lock->fetchColumn() === 1;
}

function accountLinkReleaseLock(PDO $pdo, string $acctNo): void
{
    $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $release->execute(['mco-account-link-' . hash('sha256', $acctNo)]);
}