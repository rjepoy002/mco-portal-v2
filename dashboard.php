<?php

/**
 * PALECO MCO Portal
 * dashboard.php
 *
 * LOCAL XAMPP DEVELOPMENT ONLY
 *
 * DATABASE SEPARATION
 * ---------------------------------------------------------
 * mco_portal
 *   users              READ / WRITE
 *   paleco_accounts    READ / WRITE
 *
 * palecxzp_pal_db
 *   ebills             READ ONLY
 *   master             READ ONLY
 *   billhistory        READ ONLY
 *   arledger           READ ONLY
 *   member             READ ONLY
 */


/* =========================================================
   LOCALHOST ONLY
   ========================================================= */

$allowedHosts = [
    'localhost',
    '127.0.0.1',
    '::1',
];

$currentHost = $_SERVER['HTTP_HOST'] ?? '';

if (str_starts_with($currentHost, '[')) {

    $closingBracket = strpos($currentHost, ']');

    if ($closingBracket !== false) {
        $currentHost = substr(
            $currentHost,
            1,
            $closingBracket - 1
        );
    }

} else {

    $currentHost = preg_replace(
        '/:\d+$/',
        '',
        $currentHost
    );
}

$currentHost = strtolower(trim($currentHost));

if (!in_array($currentHost, $allowedHosts, true)) {

    http_response_code(403);

    exit(
        '<!doctype html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta
                name="viewport"
                content="width=device-width, initial-scale=1"
            >
            <title>403 - Local Development Only</title>
        </head>
        <body>
            <h1>403 - Local Development Only</h1>
            <p>
                This PALECO MCO Portal development build
                can only run on localhost.
            </p>
        </body>
        </html>'
    );
}


/* =========================================================
   BOOTSTRAP
   ========================================================= */

require_once __DIR__ . '/includes/account-context.php';
require_once __DIR__ . '/includes/account-linking.php';
require_once __DIR__ . '/includes/billing-service.php';


/* =========================================================
   AUTHENTICATION
   ========================================================= */

$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    header('Location: index.php');
    exit;
}


/* =========================================================
   USER INFORMATION

   Keep using session values because these are already
   populated by the existing login system.
   ========================================================= */

$userEmail = trim(
    (string) ($_SESSION['user_email'] ?? '')
);

$userDisplayName = trim(
    (string) ($_SESSION['user_name'] ?? '')
);

if ($userDisplayName === '') {
    $userDisplayName = $userEmail !== ''
        ? $userEmail
        : 'Member';
}


/* =========================================================
   DISPLAY HELPERS
   ========================================================= */

function formatBillMonth(?string $billMonth): string
{
    $billMonth = trim((string) $billMonth);

    if (!preg_match('/^(\d{4})(\d{2})$/', $billMonth, $match)) {
        return $billMonth !== '' ? $billMonth : '—';
    }

    $year = (int) $match[1];
    $month = (int) $match[2];

    if ($month < 1 || $month > 12) {
        return $billMonth;
    }

    return strtoupper(
        date(
            'M Y',
            mktime(0, 0, 0, $month, 1, $year)
        )
    );
}


function formatPortalDate(?string $date): string
{
    $date = trim((string) $date);

    if ($date === '') {
        return '—';
    }

    $timestamp = strtotime($date);

    if ($timestamp === false) {
        return $date;
    }

    return date('M j, Y', $timestamp);
}


function unpaidBillStatus(?string $dueDate): array
{
    $dueDate = trim((string) $dueDate);

    if ($dueDate === '') {
        return [
            'label' => 'Due Date Unavailable',
            'class' => 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
        ];
    }

    $timestamp = strtotime($dueDate);

    if ($timestamp === false) {
        return [
            'label' => 'Due Date Unavailable',
            'class' => 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
        ];
    }

    if (date('Y-m-d', $timestamp) < date('Y-m-d')) {
        return [
            'label' => 'Overdue',
            'class' => 'bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-300',
        ];
    }

    return [
        'label' => 'Not Yet Due',
        'class' => 'bg-paleco-50 text-paleco-700 dark:bg-paleco-900 dark:text-paleco-100',
    ];
}

function formatConsumerAccountCode(array $account): string
{
    $accountCode = trim((string) ($account['AcctCode'] ?? ''));

    return $accountCode !== ''
        ? 'Account ' . $accountCode
        : 'Account code unavailable';
}
/* =========================================================
   LEGACY EBILLS SYNCHRONIZATION

   READ:
       palecxzp_pal_db.ebills
       palecxzp_pal_db.master

   WRITE:
       mco_portal.paleco_accounts only
   ========================================================= */

function syncLegacyEbillsAccounts(
    PDO $pdo,
    int $userId,
    string $userEmail
): void {

    $userEmail = trim($userEmail);

    if ($userId <= 0 || $userEmail === '') {
        return;
    }

    /*
     * Existing eBills registrations:
     *
     * ebills.Email
     *      ↓
     * ebills.AccCode
     *      ↓
     * master.AcctCode
     *      ↓
     * master.AcctNo
     */

    $stmt = $pdo->prepare("
        SELECT DISTINCT
            m.AcctNo
        FROM palecxzp_pal_db.ebills AS e
        INNER JOIN palecxzp_pal_db.master AS m
            ON m.AcctCode = e.AccCode
        WHERE LOWER(TRIM(e.Email)) = LOWER(TRIM(?))
          AND e.AccCode IS NOT NULL
          AND e.AccCode <> ''
          AND m.AcctNo IS NOT NULL
          AND m.AcctNo <> ''
    ");

    $stmt->execute([$userEmail]);

    $legacyAccounts = $stmt->fetchAll(
        PDO::FETCH_COLUMN
    );

    if (!$legacyAccounts) {
        return;
    }

    try {

        $pdo->beginTransaction();

        /*
         * Lock portal user.
         */

        $lock = $pdo->prepare("
            SELECT id
            FROM mco_portal.users
            WHERE id = ?
            FOR UPDATE
        ");

        $lock->execute([$userId]);


        /*
         * Find an existing portal relationship.
         */

        $check = $pdo->prepare("
            SELECT
                id,
                status
            FROM mco_portal.paleco_accounts
            WHERE user_id = ?
              AND AcctNo = ?
            LIMIT 1
            FOR UPDATE
        ");


        /*
         * Add a missing portal relationship.
         */

        $insert = $pdo->prepare("
            INSERT INTO mco_portal.paleco_accounts
            (
                user_id,
                AcctNo,
                account_nickname,
                is_primary,
                status,
                verified_at
            )
            VALUES
            (
                ?,
                ?,
                NULL,
                0,
                'active',
                NOW()
            )
        ");


        /*
         * Restore inactive legacy-linked account.
         */

        $restore = $pdo->prepare("
            UPDATE mco_portal.paleco_accounts
            SET
                status = 'active',
                verified_at = NOW()
            WHERE id = ?
              AND user_id = ?
        ");


        foreach ($legacyAccounts as $acctNo) {

            $acctNo = trim((string) $acctNo);

            if ($acctNo === '') {
                continue;
            }

            $check->execute([
                $userId,
                $acctNo
            ]);

            $existing = $check->fetch(
                PDO::FETCH_ASSOC
            );

            if ($existing) {

                if (
                    ($existing['status'] ?? '')
                    !== 'active'
                ) {

                    $restore->execute([
                        $existing['id'],
                        $userId
                    ]);
                }

                continue;
            }

            $insert->execute([
                $userId,
                $acctNo
            ]);
        }


        /*
         * Check for primary account.
         */

        $primary = $pdo->prepare("
            SELECT id
            FROM mco_portal.paleco_accounts
            WHERE user_id = ?
              AND status = 'active'
              AND is_primary = 1
            LIMIT 1
        ");

        $primary->execute([$userId]);

        $hasPrimary = $primary->fetchColumn();


        /*
         * If no primary exists, use oldest active account.
         */

        if (!$hasPrimary) {

            $first = $pdo->prepare("
                SELECT id
                FROM mco_portal.paleco_accounts
                WHERE user_id = ?
                  AND status = 'active'
                ORDER BY id ASC
                LIMIT 1
            ");

            $first->execute([$userId]);

            $firstId = $first->fetchColumn();

            if ($firstId) {

                $setPrimary = $pdo->prepare("
                    UPDATE mco_portal.paleco_accounts
                    SET is_primary = 1
                    WHERE id = ?
                      AND user_id = ?
                ");

                $setPrimary->execute([
                    $firstId,
                    $userId
                ]);
            }
        }

        $pdo->commit();

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log(
            'Legacy eBills synchronization failed: '
            . $e->getMessage()
        );

        /*
         * Do not block dashboard access just because
         * legacy account synchronization failed.
         */
    }
}


/* =========================================================
   SYNCHRONIZE EBILLS ACCOUNTS
   ========================================================= */

syncLegacyEbillsAccounts(
    $pdo,
    $userId,
    $userEmail
);


/* =========================================================
   NAVIGATION
   ========================================================= */

$nav = [
    'dashboard'        => 'Dashboard',
    'bills'            => 'My Bills',
    'account-settings' => 'Account & Settings',
];

/* Keep legacy bookmarks functional without retaining redundant navigation. */
$legacyPageRoutes = [
    'bill-history' => ['page' => 'bills', 'tab' => 'history'],
    'consumption'  => ['page' => 'dashboard'],
    'accounts'     => ['page' => 'account-settings'],
    'profile'      => ['page' => 'account-settings'],
];

$requestedPage = $_GET['page'] ?? 'dashboard';
$legacyRoute = is_string($requestedPage)
    ? ($legacyPageRoutes[$requestedPage] ?? null)
    : null;

$page = $legacyRoute['page'] ?? (
    is_string($requestedPage)
    && array_key_exists($requestedPage, $nav)
        ? $requestedPage
        : 'dashboard'
);

$requestedBillsTab = $legacyRoute['tab'] ?? ($_GET['tab'] ?? 'unpaid');
$billsTab = (
    $page === 'bills'
    && is_string($requestedBillsTab)
    && in_array($requestedBillsTab, ['unpaid', 'history'], true)
)
    ? $requestedBillsTab
    : 'unpaid';

/* Explicit configuration only; localhost alone never enables this notice. */
$appEnvironment = strtolower((string) (getenv('MCO_APP_ENV') ?: 'production'));
$isDevelopmentMode = in_array(
    $appEnvironment,
    ['development', 'local'],
    true
);


/* =========================================================
   POST ACTIONS
   ========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = (string) ($_POST['action'] ?? '');

    $returnPage = 'account-settings';

    if ($action === 'switch') {
        $requestedReturnPage = (string) ($_POST['return_page'] ?? 'dashboard');
        $returnPage = array_key_exists($requestedReturnPage, $nav)
            ? $requestedReturnPage
            : 'dashboard';
    }


    /* -----------------------------------------------------
       CSRF
       ----------------------------------------------------- */

    if (
        !hash_equals(
            dashboardCsrfToken(),
            (string) ($_POST['csrf_token'] ?? '')
        )
    ) {

        dashboardReturn(
            $returnPage,
            'Your session expired. Please try again.',
            'error'
        );
    }


    $acctNo = dashboardResolveAccountSelectionToken(
        trim((string) ($_POST['account_token'] ?? ''))
    ) ?? '';


    try {

        /* =================================================
           SWITCH ACCOUNT
           ================================================= */

        if ($action === 'switch') {

            $stmt = $pdo->prepare("
                SELECT AcctNo
                FROM mco_portal.paleco_accounts
                WHERE user_id = ?
                  AND AcctNo = ?
                  AND status = 'active'
                LIMIT 1
            ");

            $stmt->execute([
                $userId,
                $acctNo
            ]);

            if (!$stmt->fetch()) {

                dashboardReturn(
                    $returnPage,
                    'That account is not linked to your profile.',
                    'error'
                );
            }

            $_SESSION['SelectedAcctNo'] = $acctNo;

            dashboardReturn(
                $returnPage,
                'Viewing account updated.'
            );
        }


        /* =================================================
           ADD ACCOUNT

           Manual verification:
               master.AcctCode
               +
               master.MeterSerial

           master remains READ ONLY.
           ================================================= */

        if ($action === 'add') {

            if (accountLinkRateLimited()) {
                $_SESSION['account_link_form_error'] = 'Too many unsuccessful verification attempts. Please wait before trying again.';
                dashboardReturn('account-settings', $_SESSION['account_link_form_error'], 'error');
            }

            $accountCode = normalizePalecoAccCode((string) ($_POST['account_code'] ?? ''));
            $meter = normalizePalecoMeterSerial((string) ($_POST['meter_number'] ?? ''));
            $label = trim((string) ($_POST['label'] ?? ''));

            if ($accountCode === null || $meter === null || mb_strlen($label) > 100) {
                recordAccountLinkFailure();
                $_SESSION['account_link_form_error'] = 'Enter a valid PALECO account code and meter serial number.';
                dashboardReturn('account-settings', $_SESSION['account_link_form_error'], 'error');
            }

            /* Both normalized AcctCode and MeterSerial must match one read-only master record. */
            $verify = $pdo->prepare(palecoAccountVerificationSql());
            $verify->execute([
                ':acct_code' => $accountCode,
                ':meter_serial' => $meter,
            ]);
            $verifiedAccount = $verify->fetch(PDO::FETCH_ASSOC);

            if (!$verifiedAccount) {
                recordAccountLinkFailure();
                $_SESSION['account_link_form_error'] = 'The account information could not be verified.';
                dashboardReturn('account-settings', $_SESSION['account_link_form_error'], 'error');
            }

            $verifiedAcctNo = trim((string) ($verifiedAccount['AcctNo'] ?? ''));

            if ($verifiedAcctNo === '' || !accountLinkAcquireLock($pdo, $verifiedAcctNo)) {
                $_SESSION['account_link_form_error'] = 'Account linking is temporarily unavailable. Please try again.';
                dashboardReturn('account-settings', $_SESSION['account_link_form_error'], 'error');
            }

            try {
                $pdo->beginTransaction();

                $lock = $pdo->prepare("
                    SELECT id FROM mco_portal.users WHERE id = ? FOR UPDATE
                ");
                $lock->execute([$userId]);

                /* This portal has no global AcctNo uniqueness constraint. Do not transfer or share links silently. */
                $otherOwner = $pdo->prepare("
                    SELECT user_id
                    FROM mco_portal.paleco_accounts
                    WHERE AcctNo = ?
                      AND user_id <> ?
                    LIMIT 1
                    FOR UPDATE
                ");
                $otherOwner->execute([$verifiedAcctNo, $userId]);

                if ($otherOwner->fetchColumn()) {
                    $pdo->rollBack();
                    accountLinkReleaseLock($pdo, $verifiedAcctNo);
                    $_SESSION['account_link_form_error'] = 'The account information could not be verified.';
                    dashboardReturn('account-settings', $_SESSION['account_link_form_error'], 'error');
                }

                $check = $pdo->prepare("
                    SELECT id, status
                    FROM mco_portal.paleco_accounts
                    WHERE user_id = ? AND AcctNo = ?
                    LIMIT 1
                    FOR UPDATE
                ");
                $check->execute([$userId, $verifiedAcctNo]);
                $existing = $check->fetch(PDO::FETCH_ASSOC);

                if ($existing && ($existing['status'] ?? '') === 'active') {
                    $pdo->rollBack();
                    accountLinkReleaseLock($pdo, $verifiedAcctNo);
                    $_SESSION['account_link_form_error'] = 'This account is already linked.';
                    dashboardReturn('account-settings', $_SESSION['account_link_form_error'], 'error');
                }

                $count = $pdo->prepare("
                    SELECT COUNT(*) FROM mco_portal.paleco_accounts
                    WHERE user_id = ? AND status = 'active'
                ");
                $count->execute([$userId]);
                $first = (int) $count->fetchColumn() === 0;

                if ($existing) {
                    $stmt = $pdo->prepare("
                        UPDATE mco_portal.paleco_accounts
                        SET account_nickname = ?, status = 'active', is_primary = ?, verified_at = NOW()
                        WHERE id = ? AND user_id = ?
                    ");
                    $stmt->execute([$label !== '' ? $label : null, $first ? 1 : 0, $existing['id'], $userId]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO mco_portal.paleco_accounts
                        (user_id, AcctNo, account_nickname, is_primary, status, verified_at)
                        VALUES (?, ?, ?, ?, 'active', NOW())
                    ");
                    $stmt->execute([$userId, $verifiedAcctNo, $label !== '' ? $label : null, $first ? 1 : 0]);
                }

                $pdo->commit();
            } finally {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                accountLinkReleaseLock($pdo, $verifiedAcctNo);
            }

            clearAccountLinkFailures();

            if ($first) {
                $_SESSION['SelectedAcctNo'] = $verifiedAcctNo;
            }

            dashboardReturn('account-settings', 'PALECO account linked successfully.');
        }

        /* =================================================
           FRIENDLY LABEL
           ================================================= */

        if ($action === 'save_label' || $action === 'remove_label') {
            $isRemoval = $action === 'remove_label';
            $label = trim((string) ($_POST['label'] ?? ''));

            if (!$isRemoval && $label === '') {
                dashboardReturn('account-settings', 'Enter a friendly label before saving.', 'error');
            }

            if (!$isRemoval && mb_strlen($label) > 100) {
                dashboardReturn('account-settings', 'The label must be 100 characters or fewer.', 'error');
            }

            $check = $pdo->prepare("
                SELECT id FROM mco_portal.paleco_accounts
                WHERE user_id = ? AND AcctNo = ? AND status = 'active'
                LIMIT 1
            ");
            $check->execute([$userId, $acctNo]);

            if (!$check->fetch()) {
                dashboardReturn('account-settings', 'That account is not linked to your profile.', 'error');
            }

            $stmt = $pdo->prepare("
                UPDATE mco_portal.paleco_accounts
                SET account_nickname = ?
                WHERE user_id = ? AND AcctNo = ? AND status = 'active'
            ");
            $stmt->execute([$isRemoval ? null : $label, $userId, $acctNo]);

            dashboardReturn(
                'account-settings',
                $isRemoval ? 'Friendly label removed.' : 'Account label saved.'
            );
        }

        /* =================================================
           MAKE PRIMARY
           ================================================= */

        if ($action === 'primary') {

            $pdo->beginTransaction();


            $lock = $pdo->prepare("
                SELECT id
                FROM mco_portal.users
                WHERE id = ?
                FOR UPDATE
            ");

            $lock->execute([$userId]);


            /*
             * Validate ownership before changing primary.
             */

            $check = $pdo->prepare("
                SELECT id
                FROM mco_portal.paleco_accounts
                WHERE user_id = ?
                  AND AcctNo = ?
                  AND status = 'active'
                LIMIT 1
                FOR UPDATE
            ");

            $check->execute([
                $userId,
                $acctNo
            ]);

            if (!$check->fetch()) {

                $pdo->rollBack();

                dashboardReturn(
                    'account-settings',
                    'That account is not linked to your profile.',
                    'error'
                );
            }


            /*
             * Clear previous primary.
             */

            $clear = $pdo->prepare("
                UPDATE mco_portal.paleco_accounts
                SET is_primary = 0
                WHERE user_id = ?
                  AND status = 'active'
            ");

            $clear->execute([$userId]);


            /*
             * Set new primary.
             */

            $set = $pdo->prepare("
                UPDATE mco_portal.paleco_accounts
                SET is_primary = 1
                WHERE user_id = ?
                  AND AcctNo = ?
                  AND status = 'active'
            ");

            $set->execute([
                $userId,
                $acctNo
            ]);


            $pdo->commit();


            dashboardReturn(
                'account-settings',
                'Primary account updated.'
            );
        }


        dashboardReturn(
            'dashboard',
            'Unknown action.',
            'error'
        );


    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log(
            'Dashboard action failed: '
            . $e->getMessage()
        );

        dashboardReturn(
            $returnPage,
            'We could not save that change. Please try again.',
            'error'
        );
    }
}


/* =========================================================
   LOAD LINKED ACCOUNTS

   IMPORTANT:
   We do this directly here instead of relying on
   dashboardLinkedAccounts(), because master lives in
   palecxzp_pal_db while paleco_accounts lives in mco_portal.
   ========================================================= */

$linkedStmt = $pdo->prepare("
    SELECT
        pa.id,
        pa.user_id,
        pa.AcctNo,
        pa.account_nickname,
        pa.is_primary,
        pa.status,
        pa.verified_at,

        m.AcctCode,
        m.Name,
        m.Address,
        m.Status AS MasterStatus,
        m.Area,
        m.Book,
        m.MeterBrand,
        m.MemberCode,
        m.ConnectionType

    FROM mco_portal.paleco_accounts AS pa

    LEFT JOIN palecxzp_pal_db.master AS m
        ON m.AcctNo = pa.AcctNo

    WHERE pa.user_id = ?
      AND pa.status = 'active'

    ORDER BY
        pa.is_primary DESC,
        pa.id ASC
");

$linkedStmt->execute([$userId]);

$linkedAccounts = $linkedStmt->fetchAll(
    PDO::FETCH_ASSOC
);


/*
 * Normalize master Status so existing UI can continue
 * using $account['Status'].
 */

foreach ($linkedAccounts as &$account) {
    $account['Status'] = $account['MasterStatus'] ?? null;
}

unset($account);


/* =========================================================
   DETERMINE SELECTED ACCOUNT
   ========================================================= */

$selectedAccount = null;

$sessionAcctNo = trim(
    (string) ($_SESSION['SelectedAcctNo'] ?? '')
);


/*
 * First preference:
 * currently selected authorized account.
 */

if ($sessionAcctNo !== '') {

    foreach ($linkedAccounts as $account) {

        if (
            (string) $account['AcctNo']
            === $sessionAcctNo
        ) {

            $selectedAccount = $account;

            break;
        }
    }
}


/*
 * Second preference:
 * primary account.
 */

if (!$selectedAccount) {

    foreach ($linkedAccounts as $account) {

        if ((int) $account['is_primary'] === 1) {

            $selectedAccount = $account;

            break;
        }
    }
}


/*
 * Last fallback:
 * first linked account.
 */

if (
    !$selectedAccount
    && !empty($linkedAccounts)
) {

    $selectedAccount = $linkedAccounts[0];
}


/*
 * Keep selected account in session.
 */

if ($selectedAccount) {

    $_SESSION['SelectedAcctNo']
        = $selectedAccount['AcctNo'];
}$accountSelectionTokens = dashboardIssueAccountSelectionTokens($linkedAccounts);


/*
 * No accounts yet:
 * send user to My Accounts.
 */

if (
    !$selectedAccount
    && $page !== 'account-settings'
) {

    header(
        'Location: dashboard.php?page=account-settings'
    );

    exit;
}


/* =========================================================
   UNPAID BILLS

   The billing service verifies that the selected account is an
   active portal link before it reads the legacy, read-only ledger.
   ========================================================= */

$unpaidBills = [];
$unpaidBillSummary = [
    'total_due' => 0.0,
    'unpaid_count' => 0,
    'overdue_count' => 0,
];
$unpaidBillsError = false;

if ($selectedAccount) {
    try {
        $unpaidBills = getUnpaidBills(
            $pdo,
            $userId,
            (string) $selectedAccount['AcctNo']
        );
        $unpaidBillSummary = summarizeUnpaidBills($unpaidBills);
    } catch (Throwable $e) {
        $unpaidBillsError = true;
        error_log('Unable to load unpaid PALECO bills: ' . $e->getMessage());
    }
}

/* =========================================================
   BILL HISTORY

   palecxzp_pal_db.billhistory = READ ONLY
   ========================================================= */

$billHistory = [];
$latestBill = null;
$consumptionHistory = [];

if ($selectedAccount) {

    $selectedAcctNo = trim(
        (string) $selectedAccount['AcctNo']
    );


    $billStmt = $pdo->prepare("
        SELECT
            TransID,
            BillMonth,
            BillNo,
            ReadingDate,
            BillDate,
            DueDate,
            PrsRdngKWH,
            PrvRdngKWH,
            KWH,
            Evat,
            PowerBill,
            TotalOC,
            TotalBill,
            MeterReader
        FROM palecxzp_pal_db.billhistory
        WHERE AcctNo = ?
        ORDER BY BillMonth DESC
        LIMIT 12
    ");

    $billStmt->execute([
        $selectedAcctNo
    ]);

    $billHistory = $billStmt->fetchAll(
        PDO::FETCH_ASSOC
    );


    $latestBill = $billHistory[0] ?? null;


    /*
     * Oldest -> newest for chart.
     */

    $consumptionHistory = array_reverse(
        $billHistory
    );
}


/* =========================================================
   CHART DATA
   ========================================================= */

$chartLabels = [];
$chartValues = [];

foreach ($consumptionHistory as $bill) {

    $chartLabels[] = formatBillMonth(
        $bill['BillMonth'] ?? ''
    );

    $chartValues[] = (float) (
        $bill['KWH'] ?? 0
    );
}


/* =========================================================
   FLASH + CSRF
   ========================================================= */

$flash = $_SESSION['dashboard_flash']
    ?? null;

unset($_SESSION['dashboard_flash']);

$accountLinkFormError = $_SESSION['account_link_form_error'] ?? null;
unset($_SESSION['account_link_form_error']);

$csrf = dashboardCsrfToken();

?>
<!doctype html>

<html lang="en">

<head>

    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>
        <?= dashboardEscape($nav[$page]) ?>
        | PALECO MCO Portal
    </title>

    <link
        rel="icon"
        href="assets/images/logo.png"
    >

    <script>
        (function () {
            const savedTheme = localStorage.getItem('mco-theme');
            const systemDark = window.matchMedia(
                '(prefers-color-scheme: dark)'
            ).matches;

            document.documentElement.classList.toggle(
                'dark',
                savedTheme === 'dark' || (!savedTheme && systemDark)
            );
        })();
    </script>


    <!--
        Development only.
        Requires internet access for Tailwind CDN.
    -->

    <script src="https://cdn.tailwindcss.com"></script>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>


    <script>

        tailwind.config = {

            darkMode: 'class',

            theme: {

                extend: {

                    colors: {

                        paleco: {

                            50: '#f0fdf4',
                            100: '#dcfce7',
                            200: '#bbf7d0',
                            500: '#22c55e',
                            600: '#16a34a',
                            700: '#15803d',
                            800: '#166534',
                            900: '#14532d'

                        }

                    },

                    boxShadow: {

                        card:
                            '0 1px 3px rgba(15,23,42,.06),' +
                            '0 1px 2px rgba(15,23,42,.04)'

                    }

                }

            }

        };

    </script>

    <link rel="stylesheet" href="assets/css/portal-shell.css">
    <script>
        (function () {
            try {
                if (localStorage.getItem('mco.sidebar.collapsed') === 'true') {
                    document.documentElement.classList.add('sidebar-collapsed');
                }
            } catch (error) {}
        })();
    </script>
</head>


<body class="bg-slate-50 text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">


<div class="min-h-screen">


    <?php
    $portalActivePage = $page;
    $portalUserDisplayName = $userDisplayName;
    $portalUserEmail = $userEmail;
    require __DIR__ . '/includes/portal-sidebar.php';
    ?>
    <main class="portal-main">


        <?php
        $portalPageTitle = $nav[$page];
        require __DIR__ . '/includes/portal-header.php';
        ?>
        <!-- Content -->

        <div
            class="
                mx-auto
                max-w-screen-2xl
                space-y-6
                p-4
                sm:p-6
                lg:p-8
                dark:[&_.bg-white]:bg-slate-900
                dark:[&_.bg-slate-50]:bg-slate-800/50
                dark:[&_.border-slate-100]:border-slate-800
                dark:[&_.border-slate-200]:border-slate-800
                dark:[&_.border-slate-300]:border-slate-700
                dark:[&_.divide-slate-100]:divide-slate-800
                dark:[&_.divide-slate-200]:divide-slate-800
                dark:[&_.text-slate-900]:text-slate-100
                dark:[&_.text-slate-700]:text-slate-200
                dark:[&_.text-slate-600]:text-slate-300
                dark:[&_.text-slate-500]:text-slate-400
                dark:[&_.text-slate-400]:text-slate-500
                dark:[&_.bg-paleco-50]:bg-paleco-900
                dark:[&_.text-paleco-700]:text-paleco-200
                dark:[&_input]:bg-slate-900
                dark:[&_input]:text-slate-100
                dark:[&_input]:placeholder:text-slate-500
                dark:[&_select]:border-slate-700
                dark:[&_select]:bg-slate-900
                dark:[&_select]:text-slate-100
                dark:[&_.hover\:bg-slate-50:hover]:bg-slate-800/50
            "
        >


            <!-- Flash -->

            <?php if ($flash): ?>

                <div
                    role="status"
                    class="
                        rounded-xl
                        border
                        px-4 py-3
                        text-sm
                        font-medium

                        <?= $flash['type'] === 'error'
                            ? 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300'
                            : 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300'
                        ?>
                    "
                >
                    <?= dashboardEscape($flash['message']) ?>
                </div>

            <?php endif; ?>


            <?php if ($selectedAccount): ?>
                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-card dark:border-slate-800 dark:bg-slate-900 sm:p-6">
                    <p class="text-xs font-bold uppercase tracking-[.14em] text-paleco-700 dark:text-paleco-200">Viewing Account</p>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <h2 class="min-w-0 break-words text-xl font-bold sm:text-2xl"><?= dashboardEscape(dashboardDisplayAccountName($selectedAccount)) ?></h2>
                        <?php if ($selectedAccount['is_primary']): ?><span class="rounded-full bg-paleco-50 px-2.5 py-1 text-xs font-bold text-paleco-700 dark:bg-paleco-900 dark:text-paleco-100">PRIMARY</span><?php endif; ?>
                        <?php if (count($linkedAccounts) > 1): ?>
                            <button type="button" data-switch-account-open class="ml-0 inline-flex items-center gap-2 rounded-xl border border-paleco-200 px-4 py-2 text-sm font-semibold text-paleco-700 transition hover:bg-paleco-50 focus:outline-none focus:ring-4 focus:ring-paleco-500/20 dark:border-paleco-800 dark:text-paleco-200 dark:hover:bg-paleco-900/40 sm:ml-auto" aria-haspopup="dialog" aria-controls="switch-account-dialog">
                                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4"><path d="M7 7h11l-3-3M17 17H6l3 3"/><path d="M18 7a7 7 0 0 0-12-3M6 17a7 7 0 0 0 12 3"/></svg>Switch Account
                            </button>
                        <?php else: ?>
                            <button type="button" disabled aria-describedby="switch-account-unavailable" class="ml-0 cursor-not-allowed rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-400 dark:border-slate-700 sm:ml-auto">Switch Account</button>
                            <span id="switch-account-unavailable" class="w-full text-xs text-slate-500 dark:text-slate-400">No other linked accounts available.</span>
                        <?php endif; ?>
                    </div>
                    <p class="mt-3 text-sm font-semibold text-slate-600 dark:text-slate-300"><?= dashboardEscape(trim((string) ($selectedAccount['AcctCode'] ?? '')) ?: 'Account code unavailable') ?></p>
                    <p class="mt-1 break-words text-sm text-slate-500 dark:text-slate-400"><?= dashboardEscape(dashboardFormatServiceAddress($selectedAccount['Address'] ?? '')) ?></p>
                </section>
            <?php endif; ?>
            <!-- =================================================
                 MY ACCOUNTS
                 ================================================= -->

            <?php if ($page === 'account-settings'): ?>

                <section>

                    <p
                        class="
                            text-xs
                            font-bold
                            uppercase
                            tracking-[.14em]
                            text-paleco-700
                        "
                    >
                        Linked Accounts
                    </p>

                    <h2
                        class="
                            mt-1
                            text-2xl
                            font-bold
                        "
                    >
                        Your linked accounts
                    </h2>

                    <p
                        class="
                            mt-1
                            text-sm
                            text-slate-500
                        "
                    >
                        Existing eBills accounts registered
                        with your email are linked automatically.
                        You can also add another account using
                        its account and meter numbers.
                    </p>

                </section>


                <?php if (!$linkedAccounts): ?>

                    <div
                        class="
                            rounded-2xl
                            border
                            border-dashed
                            border-slate-300
                            bg-white
                            px-6 py-10
                            text-center
                        "
                    >

                        <h3 class="font-semibold">
                            No linked accounts
                        </h3>

                        <p
                            class="
                                mt-2
                                text-sm
                                text-slate-500
                            "
                        >
                            Add your PALECO account below.
                        </p>

                    </div>

                <?php endif; ?>


                <!-- Account cards -->

                <div
                    class="
                        grid gap-5
                        md:grid-cols-2
                        xl:grid-cols-3
                    "
                >

                    <?php foreach ($linkedAccounts as $account): ?>

                        <article
                            class="
                                rounded-2xl
                                border border-slate-200
                                bg-white
                                p-5
                                shadow-card
                            "
                        >

                            <div
                                class="
                                    flex
                                    items-start
                                    justify-between
                                    gap-3
                                "
                            >

                                <div class="min-w-0">

                                    <p
                                        class="
                                            text-xs
                                            font-bold
                                            uppercase
                                            tracking-[.12em]
                                            text-slate-400
                                        "
                                    >
                                        <?= dashboardEscape(
                                            formatConsumerAccountCode($account)
                                        ) ?>
                                    </p>

                                    <h3
                                        class="
                                            mt-1
                                            truncate
                                            text-lg
                                            font-bold
                                        "
                                    >
                                        <?= dashboardEscape(dashboardDisplayAccountName($account)) ?>
                                    </h3>

                                </div>


                                <?php if ($account['is_primary']): ?>

                                    <span
                                        class="
                                            shrink-0
                                            rounded-full
                                            bg-paleco-50
                                            px-2.5 py-1
                                            text-xs
                                            font-bold
                                            text-paleco-700
                                        "
                                    >
                                        PRIMARY
                                    </span>

                                <?php endif; ?>

                            </div>


                            <div
                                class="
                                    mt-4
                                    space-y-1
                                    text-sm
                                "
                            >

                                <p class="font-medium">
                                    <?= dashboardEscape(dashboardFormatConsumerName($account['Name'] ?? '')) ?>
                                </p>

                                <p class="text-slate-500">
                                    <?= dashboardEscape(dashboardFormatServiceAddress($account['Address'] ?? '')) ?>
                                </p>

                            </div>


                            <!-- Actions -->

                            <div
                                class="
                                    mt-5
                                    flex
                                    flex-wrap
                                    gap-2
                                "
                            >

                                <form method="post">

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= dashboardEscape($csrf) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="switch"
                                    >

                                    <input
                                        type="hidden"
                                        name="account_token"
                                        value="<?= dashboardEscape($accountSelectionTokens[$account['AcctNo']] ?? '') ?>"
                                    >

                                    <button
                                        type="submit"
                                        class="
                                            rounded-lg
                                            border border-slate-200
                                            px-3 py-2
                                            text-sm
                                            font-semibold
                                            hover:bg-slate-50
                                        "
                                    >
                                        View Account
                                    </button>

                                </form>


                                <?php if (!$account['is_primary']): ?>

                                    <form method="post">

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= dashboardEscape($csrf) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="primary"
                                        >

                                        <input
                                            type="hidden"
                                            name="account_token"
                                        value="<?= dashboardEscape($accountSelectionTokens[$account['AcctNo']] ?? '') ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="
                                                rounded-lg
                                                bg-paleco-50
                                                px-3 py-2
                                                text-sm
                                                font-semibold
                                                text-paleco-700
                                                hover:bg-paleco-100
                                            "
                                        >
                                            Make Primary
                                        </button>

                                    </form>

                                <?php endif; ?>

                            </div>


                            <!-- Friendly label -->
                            <?php $savedLabel = trim((string) ($account['account_nickname'] ?? '')); ?>
                            <form method="post" class="mt-5 border-t border-slate-100 pt-4 dark:border-slate-800" data-friendly-label-form data-saved-label="<?= dashboardEscape($savedLabel) ?>">
                                <input type="hidden" name="csrf_token" value="<?= dashboardEscape($csrf) ?>">
                                <input type="hidden" name="action" value="save_label" data-friendly-label-action>
                                <input type="hidden" name="account_token" value="<?= dashboardEscape($accountSelectionTokens[$account['AcctNo']] ?? '') ?>">
                                <label class="mb-1.5 block text-xs font-semibold text-slate-500 dark:text-slate-400">Friendly label</label>
                                <div class="flex flex-wrap items-center gap-2 sm:flex-nowrap">
                                    <div class="relative min-w-0 flex-1">
                                        <input name="label" maxlength="100" value="<?= dashboardEscape($savedLabel) ?>" placeholder="e.g. Home" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none focus:border-paleco-500 focus:ring-4 focus:ring-paleco-500/10 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 <?= $savedLabel !== '' ? 'pr-10' : '' ?>" data-friendly-label-input>
                                        <?php if ($savedLabel !== ''): ?>
                                            <button type="button" data-friendly-label-remove class="absolute inset-y-0 right-0 inline-flex items-center px-3 text-slate-400 transition hover:text-slate-700 focus:outline-none focus:ring-2 focus:ring-paleco-500 dark:hover:text-slate-200" aria-label="Remove friendly label" title="Remove friendly label">
                                                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4"><path d="M18 6 6 18M6 6l12 12"></path></svg>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    <button type="submit" data-friendly-label-save class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 focus:outline-none focus:ring-4 focus:ring-slate-500/20 disabled:cursor-not-allowed disabled:opacity-70 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white <?= $savedLabel !== '' ? 'hidden' : '' ?>">Save</button>
                                </div>
                            </form>

                        </article>

                    <?php endforeach; ?>

                </div>


                <div id="remove-friendly-label-modal" class="fixed inset-0 z-[80] hidden items-center justify-center p-4" aria-hidden="true">
                    <div data-remove-label-backdrop class="absolute inset-0 bg-slate-950/50 backdrop-blur-sm"></div>
                    <section role="dialog" aria-modal="true" aria-labelledby="remove-friendly-label-title" aria-describedby="remove-friendly-label-description" class="relative z-10 w-full max-w-md rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl dark:border-slate-700 dark:bg-slate-900 sm:p-6">
                        <h2 id="remove-friendly-label-title" class="text-xl font-bold">Remove friendly label?</h2>
                        <p id="remove-friendly-label-description" class="mt-2 text-sm leading-6 text-slate-500 dark:text-slate-400">Are you sure you want to remove this label? The account will display its original consumer name.</p>
                        <div class="mt-6 flex flex-wrap justify-end gap-3"><button type="button" data-remove-label-cancel class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus:outline-none focus:ring-4 focus:ring-paleco-500/10 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">Cancel</button><button type="button" data-remove-label-confirm class="rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-red-700 focus:outline-none focus:ring-4 focus:ring-red-500/30">Remove Label</button></div>
                    </section>
                </div>
                <!-- Add account -->

                <section
                    class="
                        rounded-2xl
                        border border-slate-200
                        bg-white
                        p-5
                        shadow-card
                        sm:p-6
                    "
                >

                    <p
                        class="
                            text-xs
                            font-bold
                            uppercase
                            tracking-[.14em]
                            text-paleco-700
                        "
                    >
                        Add Account
                    </p>

                    <h2
                        class="
                            mt-1
                            text-xl
                            font-bold
                        "
                    >
                        Link another PALECO account
                    </h2>

                    <p
                        class="
                            mt-1
                            text-sm
                            text-slate-500
                        "
                    >
                        Enter the account number and meter
                        number shown on your PALECO bill.
                    </p>


                    <?php if ($accountLinkFormError): ?>
                        <div class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300" role="alert">
                            <?= dashboardEscape($accountLinkFormError) ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" class="mt-6 grid gap-4 md:grid-cols-3" onsubmit="const submit = this.querySelector('[data-link-submit]'); if (submit) { submit.disabled = true; submit.textContent = 'Linking account…'; }">
                        <input type="hidden" name="csrf_token" value="<?= dashboardEscape($csrf) ?>">
                        <input type="hidden" name="action" value="add">

                        <label class="text-sm font-semibold text-slate-700 dark:text-slate-200">
                            PALECO Account Code
                            <input name="account_code" maxlength="12" required autocomplete="off" inputmode="text" placeholder="XX-XXXX-XXXX" aria-describedby="account-code-help" class="mt-1.5 block w-full rounded-xl border border-slate-300 px-3 py-2.5 outline-none focus:border-paleco-500 focus:ring-4 focus:ring-paleco-500/10 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                            <span id="account-code-help" class="mt-1 block text-xs font-normal text-slate-500 dark:text-slate-400">Enter your PALECO account code.</span>
                        </label>

                        <label class="text-sm font-semibold text-slate-700 dark:text-slate-200">
                            Meter Serial Number
                            <input name="meter_number" maxlength="20" required autocomplete="off" placeholder="Enter meter serial number" aria-describedby="meter-number-help" class="mt-1.5 block w-full rounded-xl border border-slate-300 px-3 py-2.5 outline-none focus:border-paleco-500 focus:ring-4 focus:ring-paleco-500/10 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                            <span id="meter-number-help" class="mt-1 block text-xs font-normal text-slate-500 dark:text-slate-400">Enter the meter serial number registered to your account.</span>
                        </label>

                        <label class="text-sm font-semibold text-slate-700 dark:text-slate-200">
                            Friendly label <span class="font-normal text-slate-400">(optional)</span>
                            <input name="label" maxlength="100" placeholder="e.g. Home" class="mt-1.5 block w-full rounded-xl border border-slate-300 px-3 py-2.5 outline-none focus:border-paleco-500 focus:ring-4 focus:ring-paleco-500/10 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                        </label>

                        <div class="flex flex-wrap gap-3 md:col-span-3">
                            <button data-link-submit type="submit" class="rounded-xl bg-paleco-700 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-paleco-800 focus:outline-none focus:ring-4 focus:ring-paleco-500/30 disabled:cursor-not-allowed disabled:opacity-70">Link Account</button>
                            <a href="dashboard.php?page=account-settings" class="rounded-xl border border-slate-300 px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus:outline-none focus:ring-4 focus:ring-paleco-500/10 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">Cancel</a>
                        </div>
                    </form>
                </section>


            <!-- =================================================
                 PROFILE
                 ================================================= -->

            <!-- =================================================
                 MY PROFILE
                 ================================================= -->

                <section
                    class="
                        max-w-2xl
                        rounded-2xl
                        border border-slate-200
                        bg-white
                        p-6
                        shadow-card
                    "
                >

                    <p
                        class="
                            text-xs
                            font-bold
                            uppercase
                            tracking-[.14em]
                            text-paleco-700
                        "
                    >
                        My Profile
                    </p>

                    <h2
                        class="
                            mt-2
                            text-2xl
                            font-bold
                        "
                    >
                        <?= dashboardEscape($userDisplayName) ?>
                    </h2>

                    <p
                        class="
                            mt-1
                            text-sm
                            text-slate-500
                        "
                    >
                        <?= dashboardEscape($userEmail) ?>
                    </p>

                </section>

                <section class="max-w-2xl rounded-2xl border border-slate-200 bg-white p-6 shadow-card">
                    <p class="text-xs font-bold uppercase tracking-[.14em] text-paleco-700">Security &amp; Preferences</p>
                    <h2 class="mt-2 text-xl font-bold">Password and appearance</h2>
                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                        <div class="rounded-xl bg-slate-50 p-4">
                            <h3 class="font-semibold">Password</h3>
                            <p class="mt-1 text-sm text-slate-500">Request a secure password-reset link for your portal account.</p>
                            <a href="forgot-password.php" class="mt-3 inline-block text-sm font-semibold text-paleco-700 hover:text-paleco-800">Reset password →</a>
                        </div>
                        <div class="rounded-xl bg-slate-50 p-4">
                            <h3 class="font-semibold">Appearance</h3>
                            <p class="mt-1 text-sm text-slate-500">Use the theme button in the page header to switch between light and dark mode.</p>
                        </div>
                    </div>
                </section>


            <!-- =================================================
                 DASHBOARD / BILLS / HISTORY
                 ================================================= -->

            <?php else: ?>


                <?php if ($page === 'bills'): ?>
                    <nav
                        class="flex border-b border-slate-200 dark:border-slate-800"
                        aria-label="My Bills sections"
                        role="tablist"
                    >
                        <a
                            href="dashboard.php?page=bills&amp;tab=unpaid"
                            role="tab"
                            aria-selected="<?= $billsTab === 'unpaid' ? 'true' : 'false' ?>"
                            class="border-b-2 px-4 py-3 text-sm font-semibold <?= $billsTab === 'unpaid' ? 'border-paleco-700 text-paleco-700 dark:text-paleco-200' : 'border-transparent text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white' ?>"
                        >
                            Unpaid Bills
                        </a>
                        <a
                            href="dashboard.php?page=bills&amp;tab=history"
                            role="tab"
                            aria-selected="<?= $billsTab === 'history' ? 'true' : 'false' ?>"
                            class="border-b-2 px-4 py-3 text-sm font-semibold <?= $billsTab === 'history' ? 'border-paleco-700 text-paleco-700 dark:text-paleco-200' : 'border-transparent text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white' ?>"
                        >
                            Bill History
                        </a>
                    </nav>
                <?php endif; ?>
                <!-- =================================================
                     SUMMARY
                     ================================================= -->

                <div
                    class="
                        grid gap-4
                        md:grid-cols-3
                    "
                >


                    <!-- Amount due -->

                    <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
                        <p class="text-xs font-bold uppercase tracking-[.12em] text-slate-400">Amount Due</p>
                        <?php if ($unpaidBillsError): ?>
                            <h3 class="mt-4 text-2xl font-bold">—</h3>
                            <p class="mt-1 text-sm text-slate-500">Balance is temporarily unavailable.</p>
                        <?php else: ?>
                            <h3 class="mt-4 text-2xl font-bold">
                                ₱<?= number_format((float) $unpaidBillSummary['total_due'], 2) ?>
                            </h3>
                            <p class="mt-1 text-sm text-slate-500">
                                <?= (int) $unpaidBillSummary['unpaid_count'] ?> unpaid bill<?= (int) $unpaidBillSummary['unpaid_count'] === 1 ? '' : 's' ?>
                                <?php if ($unpaidBillSummary['overdue_count']): ?>
                                    · <?= (int) $unpaidBillSummary['overdue_count'] ?> overdue
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                        <a href="dashboard.php?page=bills" class="mt-3 inline-block text-sm font-semibold text-paleco-700 hover:text-paleco-800">View unpaid bills →</a>
                    </article>




                    <!-- Latest bill -->

                    <article
                        class="
                            rounded-2xl
                            border border-slate-200
                            bg-white
                            p-5
                            shadow-card
                        "
                    >

                        <p
                            class="
                                text-xs
                                font-bold
                                uppercase
                                tracking-[.12em]
                                text-slate-400
                            "
                        >
                            Latest Bill
                        </p>


                        <?php if ($latestBill): ?>

                            <h3
                                class="
                                    mt-4
                                    text-2xl
                                    font-bold
                                "
                            >
                                ₱<?= number_format(
                                    (float) $latestBill['TotalBill'],
                                    2
                                ) ?>
                            </h3>

                            <p
                                class="
                                    mt-1
                                    text-sm
                                    text-slate-500
                                "
                            >
                                <?= dashboardEscape(
                                    formatBillMonth(
                                        $latestBill['BillMonth']
                                    )
                                ) ?>
                            </p>

                        <?php else: ?>

                            <h3
                                class="
                                    mt-4
                                    text-2xl
                                    font-bold
                                "
                            >
                                —
                            </h3>

                            <p
                                class="
                                    mt-1
                                    text-sm
                                    text-slate-500
                                "
                            >
                                No billing history available.
                            </p>

                        <?php endif; ?>

                    </article>


                    <!-- Latest consumption -->

                    <article
                        class="
                            rounded-2xl
                            border border-slate-200
                            bg-white
                            p-5
                            shadow-card
                        "
                    >

                        <p
                            class="
                                text-xs
                                font-bold
                                uppercase
                                tracking-[.12em]
                                text-slate-400
                            "
                        >
                            Latest Consumption
                        </p>


                        <?php if ($latestBill): ?>

                            <h3
                                class="
                                    mt-4
                                    text-2xl
                                    font-bold
                                "
                            >
                                <?= number_format(
                                    (float) $latestBill['KWH'],
                                    0
                                ) ?>

                                <span
                                    class="
                                        text-sm
                                        font-medium
                                        text-slate-400
                                    "
                                >
                                    kWh
                                </span>
                            </h3>

                            <p
                                class="
                                    mt-1
                                    text-sm
                                    text-slate-500
                                "
                            >
                                <?= dashboardEscape(
                                    formatBillMonth(
                                        $latestBill['BillMonth']
                                    )
                                ) ?>
                            </p>

                        <?php else: ?>

                            <h3
                                class="
                                    mt-4
                                    text-2xl
                                    font-bold
                                "
                            >
                                —
                            </h3>

                            <p
                                class="
                                    mt-1
                                    text-sm
                                    text-slate-500
                                "
                            >
                                No consumption history available.
                            </p>

                        <?php endif; ?>

                    </article>

                </div>


                <!-- =================================================
                     CONSUMPTION
                     ================================================= -->

                <?php if (
                    $page === 'dashboard'
                ): ?>

                    <section
                        class="
                            rounded-2xl
                            border border-slate-200
                            bg-white
                            p-5
                            shadow-card
                            sm:p-6
                        "
                    >

                        <p
                            class="
                                text-xs
                                font-bold
                                uppercase
                                tracking-[.14em]
                                text-paleco-700
                            "
                        >
                            12-Month Consumption
                        </p>

                        <h2
                            class="
                                mt-1
                                text-lg
                                font-bold
                            "
                        >
                            Electricity use
                        </h2>


                        <?php if ($consumptionHistory): ?>

                            <div
                                class="
                                    mt-6
                                    h-72
                                    sm:h-80
                                "
                            >
                                <canvas id="consumptionChart"></canvas>
                            </div>

                        <?php else: ?>

                            <div
                                class="
                                    mt-5
                                    rounded-xl
                                    border
                                    border-dashed
                                    border-slate-300
                                    bg-slate-50
                                    px-5 py-10
                                    text-center
                                    text-sm
                                    text-slate-500
                                "
                            >
                                No consumption history available.
                            </div>

                        <?php endif; ?>

                    </section>

                <?php endif; ?>


                <!-- =================================================
                     BILL HISTORY
                     ================================================= -->

                <?php if (
                    $page === 'dashboard'
                    || ($page === 'bills' && $billsTab === 'history')
                ): ?>

                    <section
                        class="
                            overflow-hidden
                            rounded-2xl
                            border border-slate-200
                            bg-white
                            shadow-card
                        "
                    >

                        <div
                            class="
                                border-b
                                border-slate-100
                                p-5
                                sm:p-6
                            "
                        >

                            <p
                                class="
                                    text-xs
                                    font-bold
                                    uppercase
                                    tracking-[.14em]
                                    text-paleco-700
                                "
                            >
                                Billing History
                            </p>

                            <h2
                                class="
                                    mt-1
                                    text-lg
                                    font-bold
                                "
                            >
                                Recent bills
                            </h2>

                        </div>


                        <?php if ($billHistory): ?>

                            <div class="overflow-x-auto">

                                <table
                                    class="
                                        min-w-full
                                        divide-y
                                        divide-slate-200
                                        text-sm
                                    "
                                >

                                    <thead class="bg-slate-50">

                                    <tr
                                        class="
                                            text-left
                                            text-xs
                                            font-semibold
                                            uppercase
                                            tracking-wide
                                            text-slate-500
                                        "
                                    >

                                        <th class="px-5 py-3">
                                            Bill Month
                                        </th>

                                        <th class="px-5 py-3">
                                            Bill No.
                                        </th>

                                        <th class="px-5 py-3">
                                            Reading Date
                                        </th>

                                        <th class="px-5 py-3 text-right">
                                            Previous
                                        </th>

                                        <th class="px-5 py-3 text-right">
                                            Present
                                        </th>

                                        <th class="px-5 py-3 text-right">
                                            kWh
                                        </th>

                                        <th class="px-5 py-3">
                                            Due Date
                                        </th>

                                        <th class="px-5 py-3 text-right">
                                            Total Bill
                                        </th>

                                    </tr>

                                    </thead>


                                    <tbody
                                        class="
                                            divide-y
                                            divide-slate-100
                                            bg-white
                                        "
                                    >

                                    <?php foreach ($billHistory as $bill): ?>

                                        <tr class="hover:bg-slate-50">

                                            <td
                                                class="
                                                    whitespace-nowrap
                                                    px-5 py-4
                                                    font-semibold
                                                "
                                            >
                                                <?= dashboardEscape(
                                                    formatBillMonth(
                                                        $bill['BillMonth']
                                                    )
                                                ) ?>
                                            </td>


                                            <td
                                                class="
                                                    whitespace-nowrap
                                                    px-5 py-4
                                                    text-slate-600
                                                "
                                            >
                                                <?= dashboardEscape(
                                                    (string) $bill['BillNo']
                                                ) ?>
                                            </td>


                                            <td
                                                class="
                                                    whitespace-nowrap
                                                    px-5 py-4
                                                    text-slate-600
                                                "
                                            >
                                                <?= dashboardEscape(
                                                    formatPortalDate(
                                                        $bill['ReadingDate']
                                                    )
                                                ) ?>
                                            </td>


                                            <td
                                                class="
                                                    whitespace-nowrap
                                                    px-5 py-4
                                                    text-right
                                                    text-slate-600
                                                "
                                            >
                                                <?= number_format(
                                                    (float) $bill['PrvRdngKWH'],
                                                    0
                                                ) ?>
                                            </td>


                                            <td
                                                class="
                                                    whitespace-nowrap
                                                    px-5 py-4
                                                    text-right
                                                    text-slate-600
                                                "
                                            >
                                                <?= number_format(
                                                    (float) $bill['PrsRdngKWH'],
                                                    0
                                                ) ?>
                                            </td>


                                            <td
                                                class="
                                                    whitespace-nowrap
                                                    px-5 py-4
                                                    text-right
                                                    font-semibold
                                                "
                                            >
                                                <?= number_format(
                                                    (float) $bill['KWH'],
                                                    0
                                                ) ?>
                                            </td>


                                            <td
                                                class="
                                                    whitespace-nowrap
                                                    px-5 py-4
                                                    text-slate-600
                                                "
                                            >
                                                <?= dashboardEscape(
                                                    formatPortalDate(
                                                        $bill['DueDate']
                                                    )
                                                ) ?>
                                            </td>


                                            <td
                                                class="
                                                    whitespace-nowrap
                                                    px-5 py-4
                                                    text-right
                                                    font-semibold
                                                "
                                            >
                                                ₱<?= number_format(
                                                    (float) $bill['TotalBill'],
                                                    2
                                                ) ?>
                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                    </tbody>

                                </table>

                            </div>

                        <?php else: ?>

                            <div
                                class="
                                    px-6 py-12
                                    text-center
                                    text-sm
                                    text-slate-500
                                "
                            >
                                No billing history available.
                            </div>

                        <?php endif; ?>

                    </section>

                <?php endif; ?>


                <!-- =================================================
                     MY BILLS
                     ================================================= -->

                <?php if ($page === 'bills' && $billsTab === 'unpaid'): ?>

                    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card">
                        <div class="border-b border-slate-100 p-5 sm:p-6">
                            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-[.14em] text-paleco-700">Outstanding Bills</p>
                                    <h2 class="mt-1 text-lg font-bold">Current balance</h2>
                                    <p class="mt-1 text-sm text-slate-500">
                                        <?= dashboardEscape(formatConsumerAccountCode($selectedAccount)) ?>
                                    </p>
                                </div>
                                <a href="dashboard.php?page=account-settings" class="text-sm font-semibold text-paleco-700 hover:text-paleco-800">
                                    Switch account →
                                </a>
                            </div>

                            <?php if ($isDevelopmentMode): ?>
                                <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200" role="note">
                                    Development data — billing balances may not reflect current PALECO records.
                                </div>
                            <?php endif; ?>

                            <?php if (!$unpaidBillsError): ?>
                                <div class="mt-5 grid gap-3 sm:grid-cols-3">
                                    <div class="rounded-xl bg-slate-50 p-4">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Total amount due</p>
                                        <p class="mt-1 text-2xl font-bold">₱<?= number_format((float) $unpaidBillSummary['total_due'], 2) ?></p>
                                    </div>
                                    <div class="rounded-xl bg-slate-50 p-4">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Unpaid bills</p>
                                        <p class="mt-1 text-2xl font-bold"><?= (int) $unpaidBillSummary['unpaid_count'] ?></p>
                                    </div>
                                    <div class="rounded-xl bg-slate-50 p-4">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Overdue</p>
                                        <p class="mt-1 text-2xl font-bold <?= $unpaidBillSummary['overdue_count'] ? 'text-red-600 dark:text-red-400' : '' ?>">
                                            <?= (int) $unpaidBillSummary['overdue_count'] ?>
                                        </p>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($unpaidBillsError): ?>
                            <div class="px-6 py-12 text-center text-sm text-slate-500">
                                We could not load your outstanding bills right now. Please try again later.
                            </div>
                        <?php elseif ($unpaidBills): ?>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-slate-200 text-sm">
                                    <thead class="bg-slate-50">
                                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                            <th class="px-5 py-3">Bill month</th>
                                            <th class="px-5 py-3">Due date</th>
                                            <th class="px-5 py-3 text-right">Outstanding</th>
                                            <th class="px-5 py-3 text-right">Surcharge</th>
                                            <th class="px-5 py-3 text-right">Total amount</th>
                                            <th class="px-5 py-3">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100 bg-white">
                                        <?php foreach ($unpaidBills as $bill): ?>
                                            <tr class="hover:bg-slate-50">
                                                <td class="whitespace-nowrap px-5 py-4 font-semibold">
                                                    <?= dashboardEscape(formatBillMonth($bill['bill_month'])) ?>
                                                </td>
                                                <td class="whitespace-nowrap px-5 py-4 text-slate-600">
                                                    <?= dashboardEscape(formatPortalDate($bill['due_date'])) ?>
                                                </td>
                                                <td class="whitespace-nowrap px-5 py-4 text-right text-slate-600">
                                                    ₱<?= number_format((float) $bill['outstanding_balance'], 2) ?>
                                                </td>
                                                <td class="whitespace-nowrap px-5 py-4 text-right text-slate-600">
                                                    ₱<?= number_format((float) $bill['surcharge'], 2) ?>
                                                </td>
                                                <td class="whitespace-nowrap px-5 py-4 text-right font-bold">
                                                    ₱<?= number_format((float) $bill['total_due'], 2) ?>
                                                </td>
                                                <td class="whitespace-nowrap px-5 py-4">
                                                    <?php $billStatus = unpaidBillStatus($bill['due_date']); ?>
                                                    <span class="rounded-full px-2.5 py-1 text-xs font-bold <?= dashboardEscape($billStatus['class']) ?>">
                                                        <?= dashboardEscape($billStatus['label']) ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="px-6 py-12 text-center">
                                <h3 class="text-base font-semibold">No Unpaid Bills</h3>
                                <p class="mt-2 text-sm text-slate-500">
                                    You currently have no outstanding bills for this account.
                                </p>
                                <p class="mt-4 text-sm font-semibold text-slate-700 dark:text-slate-200">
                                    Total Outstanding Balance: ₱0.00
                                </p>
                            </div>
                        <?php endif; ?>
                    </section>

                <?php endif; ?>




                <!-- =================================================
                     SERVICE DETAILS
                     ================================================= -->

                <section
                    class="
                        rounded-2xl
                        border border-slate-200
                        bg-white
                        p-5
                        shadow-card
                        sm:p-6
                    "
                >

                    <p
                        class="
                            text-xs
                            font-bold
                            uppercase
                            tracking-[.14em]
                            text-paleco-700
                        "
                    >
                        Service Details
                    </p>

                    <h2
                        class="
                            mt-1
                            text-lg
                            font-bold
                        "
                    >
                        Account information
                    </h2>


                    <dl
                        class="
                            mt-5
                            grid
                            gap-x-8
                            gap-y-5
                            sm:grid-cols-2
                            xl:grid-cols-3
                        "
                    >

                        <div>

                            <dt
                                class="
                                    text-xs
                                    font-medium
                                    text-slate-400
                                "
                            >
                                Account Code
                            </dt>

                            <dd
                                class="
                                    mt-1
                                    text-sm
                                    font-semibold
                                "
                            >
                                <?= dashboardEscape(
                                    formatConsumerAccountCode($selectedAccount)
                                ) ?>
                            </dd>

                        </div>


                        <div>

                            <dt
                                class="
                                    text-xs
                                    font-medium
                                    text-slate-400
                                "
                            >
                                Account Holder
                            </dt>

                            <dd
                                class="
                                    mt-1
                                    text-sm
                                    font-semibold
                                "
                            >
                                <?= dashboardEscape(dashboardFormatConsumerName($selectedAccount['Name'] ?? '')) ?>
                            </dd>

                        </div>


                        <div>

                            <dt
                                class="
                                    text-xs
                                    font-medium
                                    text-slate-400
                                "
                            >
                                Status
                            </dt>

                            <dd
                                class="
                                    mt-1
                                    text-sm
                                    font-semibold
                                "
                            >
                                <?= dashboardEscape(dashboardFormatAccountStatus($selectedAccount['Status'] ?? '')) ?>
                            </dd>

                        </div>


                        <div class="sm:col-span-2">

                            <dt
                                class="
                                    text-xs
                                    font-medium
                                    text-slate-400
                                "
                            >
                                Service Address
                            </dt>

                            <dd
                                class="
                                    mt-1
                                    text-sm
                                    font-semibold
                                "
                            >
                                <?= dashboardEscape(dashboardFormatServiceAddress($selectedAccount['Address'] ?? '')) ?>
                            </dd>

                        </div>

                    </dl>

                </section>


            <?php endif; ?>


            <!-- Footer -->

            <footer
                class="
                    border-t
                    border-slate-200
                    pt-5
                    text-center
                    text-xs
                    text-slate-400
                "
            >
                PALECO MCO Portal ·
                Local XAMPP Development Environment
            </footer>

        </div>

    <?php if ($selectedAccount && count($linkedAccounts) > 1): ?>
        <div id="switch-account-modal" class="fixed inset-0 z-[70] hidden items-center justify-center p-4" aria-hidden="true">
            <div data-switch-account-backdrop class="absolute inset-0 bg-slate-950/50 backdrop-blur-sm"></div>
            <section id="switch-account-dialog" role="dialog" aria-modal="true" aria-labelledby="switch-account-title" aria-describedby="switch-account-description" class="relative z-10 flex max-h-[calc(100dvh-2rem)] w-full max-w-2xl flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900">
                <div class="flex items-start justify-between gap-4 border-b border-slate-100 p-5 dark:border-slate-800 sm:p-6">
                    <div class="min-w-0"><h2 id="switch-account-title" class="text-xl font-bold">Switch Account</h2><p id="switch-account-description" class="mt-1 text-sm text-slate-500 dark:text-slate-400">Select an account to view its dashboard.</p></div>
                    <button type="button" data-switch-account-close class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-paleco-500 dark:text-slate-400 dark:hover:bg-slate-800" aria-label="Close account selector">×</button>
                </div>
                <div class="min-h-0 overflow-y-auto p-3 sm:p-4">
                    <div class="space-y-2" role="list">
                        <?php foreach ($linkedAccounts as $account): ?>
                            <?php $isCurrent = (string) $account['AcctNo'] === (string) $selectedAccount['AcctNo']; ?>
                            <form method="post" action="dashboard.php" class="contents" data-switch-account-form>
                                <input type="hidden" name="csrf_token" value="<?= dashboardEscape($csrf) ?>"><input type="hidden" name="action" value="switch"><input type="hidden" name="return_page" value="<?= dashboardEscape($page) ?>"><input type="hidden" name="account_token" value="<?= dashboardEscape($accountSelectionTokens[$account['AcctNo']] ?? '') ?>">
                                <button type="submit" <?= $isCurrent ? 'disabled aria-current="true"' : '' ?> class="w-full rounded-xl border p-4 text-left transition focus:outline-none focus:ring-4 focus:ring-paleco-500/20 <?= $isCurrent ? 'cursor-default border-paleco-500 bg-paleco-50 dark:border-paleco-700 dark:bg-paleco-900/40' : 'border-slate-200 hover:border-paleco-300 hover:bg-slate-50 dark:border-slate-700 dark:hover:border-paleco-700 dark:hover:bg-slate-800/70' ?>">
                                    <div class="flex flex-wrap items-center gap-2"><span class="min-w-0 break-words font-semibold"><?= dashboardEscape(dashboardDisplayAccountName($account)) ?></span><?php if ($account['is_primary']): ?><span class="rounded-full bg-paleco-100 px-2 py-0.5 text-xs font-bold text-paleco-700 dark:bg-paleco-900 dark:text-paleco-100">PRIMARY</span><?php endif; ?><?php if ($isCurrent): ?><span class="rounded-full bg-slate-200 px-2 py-0.5 text-xs font-bold text-slate-700 dark:bg-slate-700 dark:text-slate-200">CURRENTLY VIEWING</span><?php endif; ?></div>
                                    <p class="mt-2 text-sm font-semibold text-slate-600 dark:text-slate-300"><?= dashboardEscape(trim((string) ($account['AcctCode'] ?? '')) ?: 'Account code unavailable') ?></p><p class="mt-1 break-words text-sm text-slate-500 dark:text-slate-400"><?= dashboardEscape(dashboardFormatServiceAddress($account['Address'] ?? '')) ?></p>
                                </button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                </div>
                <p data-switch-account-loading class="hidden border-t border-slate-100 px-5 py-3 text-sm font-medium text-paleco-700 dark:border-slate-800 dark:text-paleco-200">Switching account…</p>
            </section>
        </div>
    <?php endif; ?>
    </main>

</div>


<!-- =========================================================
     JAVASCRIPT
     ========================================================= -->

<script src="assets/js/theme.js"></script>
<script src="assets/js/portal-shell.js"></script>

<script>

<?php if (
    $consumptionHistory
    && (
        $page === 'dashboard'
    )
): ?>

/* =========================================================
   CONSUMPTION CHART
   ========================================================= */

(function () {

    const canvas =
        document.getElementById(
            'consumptionChart'
        );

    if (!canvas) {
        return;
    }


    const labels =
        <?= json_encode(
            $chartLabels,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        ) ?>;


    const values =
        <?= json_encode(
            $chartValues,
            JSON_NUMERIC_CHECK
        ) ?>;


    new Chart(
        canvas,
        {

            type: 'line',

            data: {

                labels: labels,

                datasets: [

                    {

                        label: 'kWh',

                        data: values,

                        borderColor:
                            '#15803d',

                        backgroundColor:
                            'rgba(21, 128, 61, 0.08)',

                        borderWidth: 2,

                        pointRadius: 3,

                        pointHoverRadius: 5,

                        fill: true,

                        tension: 0.3

                    }

                ]

            },


            options: {

                responsive: true,

                maintainAspectRatio: false,

                interaction: {

                    mode: 'index',

                    intersect: false

                },


                plugins: {

                    legend: {

                        display: false

                    },

                    tooltip: {

                        callbacks: {

                            label:
                                function (context) {

                                    return (
                                        Number(
                                            context.parsed.y
                                        ).toLocaleString()
                                        + ' kWh'
                                    );
                                }

                        }

                    }

                },


                scales: {

                    x: {

                        grid: {

                            display: false

                        }

                    },


                    y: {

                        beginAtZero: true,

                        ticks: {

                            callback:
                                function (value) {

                                    return (
                                        Number(value)
                                        .toLocaleString()
                                        + ' kWh'
                                    );
                                }

                        }

                    }

                }

            }

        }
    );

})();

<?php endif; ?>

</script>


<script>
(() => {
    const modal = document.getElementById('switch-account-modal');
    if (!modal) return;
    const dialog = document.getElementById('switch-account-dialog');
    const openers = document.querySelectorAll('[data-switch-account-open]');
    const closeButton = modal.querySelector('[data-switch-account-close]');
    const backdrop = modal.querySelector('[data-switch-account-backdrop]');
    const loading = modal.querySelector('[data-switch-account-loading]');
    let opener = null;
    const focusables = () => Array.from(dialog.querySelectorAll('button:not([disabled]), [href], input:not([disabled])'));
    const close = () => { modal.classList.add('hidden'); modal.classList.remove('flex'); modal.setAttribute('aria-hidden', 'true'); document.body.classList.remove('overflow-hidden'); opener?.focus(); };
    const open = (button) => { opener = button; modal.classList.remove('hidden'); modal.classList.add('flex'); modal.setAttribute('aria-hidden', 'false'); document.body.classList.add('overflow-hidden'); focusables()[0]?.focus(); };
    openers.forEach((button) => button.addEventListener('click', () => open(button)));
    closeButton?.addEventListener('click', close); backdrop?.addEventListener('click', close);
    document.addEventListener('keydown', (event) => { if (modal.classList.contains('hidden')) return; if (event.key === 'Escape') { event.preventDefault(); close(); } if (event.key === 'Tab') { const items = focusables(); if (!items.length) return; const first = items[0]; const last = items[items.length - 1]; if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); } else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); } } });
    modal.querySelectorAll('[data-switch-account-form]').forEach((form) => form.addEventListener('submit', (event) => { const button = form.querySelector('button'); if (button.disabled) { event.preventDefault(); return; } modal.querySelectorAll('button').forEach((item) => { item.disabled = true; }); loading?.classList.remove('hidden'); }));
})();
</script><script>
(() => {
    const modal = document.getElementById('remove-friendly-label-modal');
    if (!modal) return;
    const cancel = modal.querySelector('[data-remove-label-cancel]');
    const confirm = modal.querySelector('[data-remove-label-confirm]');
    const backdrop = modal.querySelector('[data-remove-label-backdrop]');
    let activeForm = null;
    let opener = null;
    const close = () => { modal.classList.add('hidden'); modal.classList.remove('flex'); modal.setAttribute('aria-hidden', 'true'); document.body.classList.remove('overflow-hidden'); opener?.focus(); activeForm = null; };
    const open = (button) => { opener = button; activeForm = button.closest('[data-friendly-label-form]'); modal.classList.remove('hidden'); modal.classList.add('flex'); modal.setAttribute('aria-hidden', 'false'); document.body.classList.add('overflow-hidden'); cancel?.focus(); };
    document.querySelectorAll('[data-friendly-label-form]').forEach((form) => {
        const input = form.querySelector('[data-friendly-label-input]');
        const save = form.querySelector('[data-friendly-label-save]');
        const remove = form.querySelector('[data-friendly-label-remove]');
        const action = form.querySelector('[data-friendly-label-action]');
        const saved = form.dataset.savedLabel || '';
        const updateSave = () => save?.classList.toggle('hidden', input.value === saved);
        input?.addEventListener('input', updateSave);
        remove?.addEventListener('click', () => open(remove));
        form.addEventListener('submit', (event) => { const removing = action?.value === 'remove_label'; const submitter = event.submitter || save; if ((!submitter || submitter.classList.contains('hidden')) && !removing) { event.preventDefault(); return; } form.querySelectorAll('button').forEach((control) => { control.disabled = true; }); if (input) input.readOnly = true; if (!removing) submitter.textContent = 'Saving…'; });
    });
    cancel?.addEventListener('click', close); backdrop?.addEventListener('click', close);
    confirm?.addEventListener('click', () => { if (!activeForm) return; const input = activeForm.querySelector('[data-friendly-label-input]'); const action = activeForm.querySelector('[data-friendly-label-action]'); input.value = ''; action.value = 'remove_label'; confirm.disabled = true; cancel.disabled = true; confirm.textContent = 'Removing…'; activeForm.requestSubmit(); });
    document.addEventListener('keydown', (event) => { if (modal.classList.contains('hidden')) return; if (event.key === 'Escape') { event.preventDefault(); close(); } if (event.key === 'Tab') { const controls = Array.from(modal.querySelectorAll('button:not([disabled])')); const first = controls[0]; const last = controls[controls.length - 1]; if (!first) return; if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); } else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); } } });
})();
</script></body>
</html>