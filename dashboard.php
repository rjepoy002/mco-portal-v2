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
    'dashboard'    => 'Dashboard',
    'bills'        => 'My Bills',
    'bill-history' => 'Bill History',
    'consumption'  => 'Consumption',
    'accounts'     => 'My Accounts',
    'profile'      => 'Profile / Settings',
];

$requestedPage = $_GET['page'] ?? 'dashboard';

$page = (
    is_string($requestedPage)
    && array_key_exists($requestedPage, $nav)
)
    ? $requestedPage
    : 'dashboard';


/* =========================================================
   POST ACTIONS
   ========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = (string) ($_POST['action'] ?? '');

    $returnPage = $action === 'switch'
        ? 'dashboard'
        : 'accounts';


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


    $acctNo = trim(
        (string) ($_POST['account_number'] ?? '')
    );


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
                    'dashboard',
                    'That account is not linked to your profile.',
                    'error'
                );
            }

            $_SESSION['SelectedAcctNo'] = $acctNo;

            dashboardReturn(
                'dashboard',
                'Viewing account updated.'
            );
        }


        /* =================================================
           ADD ACCOUNT

           Manual verification:
               master.AcctNo
               +
               master.MeterSerial

           master remains READ ONLY.
           ================================================= */

        if ($action === 'add') {

            $meter = trim(
                (string) ($_POST['meter_number'] ?? '')
            );

            $label = trim(
                (string) ($_POST['label'] ?? '')
            );


            if (
                $acctNo === ''
                || $meter === ''
                || strlen($acctNo) > 15
                || strlen($meter) > 20
                || mb_strlen($label) > 100
            ) {

                dashboardReturn(
                    'accounts',
                    'Enter a valid account number and meter number.',
                    'error'
                );
            }


            /*
             * PALECO MASTER — SELECT ONLY
             */

            $verify = $pdo->prepare("
                SELECT
                    AcctNo,
                    AcctCode,
                    Name,
                    Address,
                    Status,
                    MeterSerial
                FROM palecxzp_pal_db.master
                WHERE AcctNo = ?
                  AND MeterSerial = ?
                LIMIT 1
            ");

            $verify->execute([
                $acctNo,
                $meter
            ]);

            $verifiedAccount = $verify->fetch(
                PDO::FETCH_ASSOC
            );

            if (!$verifiedAccount) {

                dashboardReturn(
                    'accounts',
                    'The account and meter number could not be verified.',
                    'error'
                );
            }


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
             * Check existing portal account link.
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

            $check->execute([
                $userId,
                $acctNo
            ]);

            $existing = $check->fetch(
                PDO::FETCH_ASSOC
            );


            if (
                $existing
                && ($existing['status'] ?? '') === 'active'
            ) {

                $pdo->rollBack();

                dashboardReturn(
                    'accounts',
                    'This account is already linked.',
                    'error'
                );
            }


            /*
             * Is this the first active account?
             */

            $count = $pdo->prepare("
                SELECT COUNT(*)
                FROM mco_portal.paleco_accounts
                WHERE user_id = ?
                  AND status = 'active'
            ");

            $count->execute([$userId]);

            $first = (
                (int) $count->fetchColumn() === 0
            );


            /*
             * Restore previously inactive account.
             */

            if ($existing) {

                $stmt = $pdo->prepare("
                    UPDATE mco_portal.paleco_accounts
                    SET
                        account_nickname = ?,
                        status = 'active',
                        is_primary = ?,
                        verified_at = NOW()
                    WHERE id = ?
                      AND user_id = ?
                ");

                $stmt->execute([
                    $label !== '' ? $label : null,
                    $first ? 1 : 0,
                    $existing['id'],
                    $userId
                ]);

            } else {

                /*
                 * INSERT only into portal database.
                 */

                $stmt = $pdo->prepare("
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
                        ?,
                        ?,
                        'active',
                        NOW()
                    )
                ");

                $stmt->execute([
                    $userId,
                    $acctNo,
                    $label !== '' ? $label : null,
                    $first ? 1 : 0
                ]);
            }


            $pdo->commit();


            if ($first) {
                $_SESSION['SelectedAcctNo'] = $acctNo;
            }


            dashboardReturn(
                'accounts',
                'PALECO account linked successfully.'
            );
        }


        /* =================================================
           RENAME ACCOUNT
           ================================================= */

        if ($action === 'rename') {

            $label = trim(
                (string) ($_POST['label'] ?? '')
            );

            if (mb_strlen($label) > 100) {

                dashboardReturn(
                    'accounts',
                    'The label must be 100 characters or fewer.',
                    'error'
                );
            }


            /*
             * Verify account ownership.
             */

            $check = $pdo->prepare("
                SELECT id
                FROM mco_portal.paleco_accounts
                WHERE user_id = ?
                  AND AcctNo = ?
                  AND status = 'active'
                LIMIT 1
            ");

            $check->execute([
                $userId,
                $acctNo
            ]);

            if (!$check->fetch()) {

                dashboardReturn(
                    'accounts',
                    'That account is not linked to your profile.',
                    'error'
                );
            }


            /*
             * Portal-owned nickname only.
             */

            $stmt = $pdo->prepare("
                UPDATE mco_portal.paleco_accounts
                SET account_nickname = ?
                WHERE user_id = ?
                  AND AcctNo = ?
                  AND status = 'active'
            ");

            $stmt->execute([
                $label !== '' ? $label : null,
                $userId,
                $acctNo
            ]);


            dashboardReturn(
                'accounts',
                'Account label saved.'
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
                    'accounts',
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
                'accounts',
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
        m.MeterSerial,
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
}


/*
 * No accounts yet:
 * send user to My Accounts.
 */

if (
    !$selectedAccount
    && $page !== 'accounts'
) {

    header(
        'Location: dashboard.php?page=accounts'
    );

    exit;
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

</head>


<body class="bg-slate-50 text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">


<div class="min-h-screen lg:flex">


    <!-- ==================================================
         MOBILE BACKDROP
         ================================================== -->

    <div
        id="mobileBackdrop"
        class="
            fixed inset-0 z-40
            hidden
            bg-slate-950/40
            backdrop-blur-sm
            lg:hidden
        "
    ></div>


    <!-- ==================================================
         SIDEBAR
         ================================================== -->

    <aside
        id="sidebar"
        class="
            fixed inset-y-0 left-0 z-50
            flex w-72
            -translate-x-full
            flex-col
            border-r border-slate-200 dark:border-slate-800
            bg-white dark:bg-slate-900
            transition-transform duration-200
            lg:static
            lg:translate-x-0
        "
    >

        <div
            class="
                flex h-20
                items-center
                gap-3
                border-b border-slate-100 dark:border-slate-800
                px-6
            "
        >

            <div
                class="
                    flex h-11 w-11
                    items-center justify-center
                    overflow-hidden
                    rounded-xl
                    ring-1 ring-slate-200 dark:ring-slate-700
                "
            >

                <img
                    src="assets/images/logo.png"
                    alt="PALECO"
                    class="h-9 w-9 object-contain"
                >

            </div>


            <div>

                <div
                    class="
                        text-sm
                        font-bold
                        tracking-wide
                    "
                >
                    PALECO
                </div>

                <div
                    class="
                        text-xs
                        font-medium
                        text-slate-500
                    "
                >
                    MCO Portal
                </div>

            </div>


            <button
                type="button"
                id="closeSidebar"
                class="
                    ml-auto
                    rounded-lg
                    p-2
                    text-slate-500 dark:text-slate-400
                    hover:bg-slate-100 dark:hover:bg-slate-800
                    lg:hidden
                "
                aria-label="Close navigation"
            >
                ✕
            </button>

        </div>


        <!-- Navigation -->

        <nav
            class="
                flex-1
                space-y-1
                overflow-y-auto
                px-4 py-6
            "
            aria-label="Main navigation"
        >

            <?php foreach ($nav as $key => $title): ?>

                <?php $active = $page === $key; ?>

                <a
                    href="dashboard.php?page=<?= dashboardEscape($key) ?>"
                    class="
                        flex items-center
                        rounded-xl
                        px-4 py-3
                        text-sm
                        font-medium
                        transition

                        <?= $active
                            ? 'bg-paleco-50 text-paleco-800 dark:bg-paleco-900 dark:text-paleco-100'
                            : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white'
                        ?>
                    "
                    <?= $active
                        ? 'aria-current="page"'
                        : ''
                    ?>
                >
                    <?= dashboardEscape($title) ?>
                </a>

            <?php endforeach; ?>

        </nav>


        <!-- User -->

        <div
            class="
                border-t
                border-slate-100 dark:border-slate-800
                p-4
            "
        >

            <div
                class="
                    mb-3
                    rounded-xl
                    bg-slate-50 dark:bg-slate-800/50
                    px-4 py-3
                "
            >

                <div
                    class="
                        truncate
                        text-sm
                        font-semibold
                    "
                >
                    <?= dashboardEscape($userDisplayName) ?>
                </div>

                <?php if ($userEmail !== ''): ?>

                    <div
                        class="
                            mt-0.5
                            truncate
                            text-xs
                            text-slate-500 dark:text-slate-400
                        "
                    >
                        <?= dashboardEscape($userEmail) ?>
                    </div>

                <?php endif; ?>

            </div>


            <a
                href="logout.php"
                class="
                    flex w-full
                    justify-center
                    rounded-xl
                    border border-slate-200 dark:border-slate-700
                    px-4 py-2.5
                    text-sm
                    font-semibold
                    text-slate-700 dark:text-slate-200
                    transition
                    hover:bg-slate-50 dark:hover:bg-slate-800
                "
            >
                Sign Out
            </a>

        </div>

    </aside>


    <!-- ==================================================
         MAIN
         ================================================== -->

    <main class="min-w-0 flex-1">


        <!-- Top bar -->

        <header
            class="
                sticky top-0 z-30
                border-b border-slate-200 dark:border-slate-800
                bg-white/95 dark:bg-slate-900/95
                backdrop-blur
            "
        >

            <div
                class="
                    mx-auto
                    flex h-20
                    max-w-screen-2xl
                    items-center
                    gap-4
                    px-4
                    sm:px-6
                    lg:px-8
                "
            >

                <button
                    type="button"
                    id="openSidebar"
                    class="
                        rounded-xl
                        border border-slate-200 dark:border-slate-700
                        bg-white dark:bg-slate-900
                        p-2.5
                        text-slate-600 dark:text-slate-300
                        shadow-sm
                        lg:hidden
                    "
                    aria-label="Open navigation"
                >
                    ☰
                </button>

                <button
                    id="themeToggle"
                    type="button"
                    class="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-700 transition hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
                    aria-label="Switch to dark mode"
                    title="Switch to dark mode"
                ></button>


                <div class="min-w-0 flex-1">

                    <p
                        class="
                            hidden
                            text-xs
                            font-semibold
                            uppercase
                            tracking-[.14em]
                            text-slate-400
                            sm:block
                        "
                    >
                        Palawan Electric Cooperative
                    </p>

                    <h1
                        class="
                            truncate
                            text-xl
                            font-bold
                            tracking-tight
                        "
                    >
                        <?= dashboardEscape($nav[$page]) ?>
                    </h1>

                </div>


                <div
                    class="
                        hidden
                        rounded-full
                        bg-slate-100 dark:bg-slate-800
                        px-4 py-2
                        text-sm
                        font-semibold
                        text-slate-700 dark:text-slate-200
                        sm:block
                    "
                >
                    <?= dashboardEscape($userDisplayName) ?>
                </div>

            </div>

        </header>


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


            <!-- =================================================
                 VIEWING ACCOUNT
                 ================================================= -->

            <?php if ($selectedAccount): ?>

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

                    <div
                        class="
                            grid gap-5
                            lg:grid-cols-[1fr_auto]
                            lg:items-center
                        "
                    >

                        <div class="min-w-0">

                            <p
                                class="
                                    text-xs
                                    font-bold
                                    uppercase
                                    tracking-[.14em]
                                    text-paleco-700
                                "
                            >
                                Viewing Account
                            </p>


                            <div
                                class="
                                    mt-1
                                    flex
                                    flex-wrap
                                    items-center
                                    gap-2
                                "
                            >

                                <h2
                                    class="
                                        truncate
                                        text-xl
                                        font-bold
                                    "
                                >
                                    <?= dashboardEscape(
                                        $selectedAccount['account_nickname']
                                        ?: (
                                            $selectedAccount['Name']
                                            ?: 'PALECO account'
                                        )
                                    ) ?>
                                </h2>


                                <?php if ($selectedAccount['is_primary']): ?>

                                    <span
                                        class="
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


                            <p
                                class="
                                    mt-1
                                    text-sm
                                    text-slate-500
                                "
                            >
                                Account
                                <?= dashboardEscape(
                                    $selectedAccount['AcctNo']
                                ) ?>
                            </p>

                        </div>


                        <?php if (count($linkedAccounts) > 1): ?>

                            <form
                                method="post"
                                action="dashboard.php"
                                class="w-full lg:w-auto"
                            >

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


                                <label
                                    for="account-selector"
                                    class="
                                        mb-1.5
                                        block
                                        text-xs
                                        font-semibold
                                        text-slate-500
                                    "
                                >
                                    Switch account
                                </label>


                                <div
                                    class="
                                        flex
                                        flex-col
                                        gap-2
                                        sm:flex-row
                                    "
                                >

                                    <select
                                        id="account-selector"
                                        name="account_number"
                                        class="
                                            rounded-xl
                                            border border-slate-300
                                            bg-white
                                            px-3 py-2.5
                                            text-sm
                                            outline-none
                                            focus:border-paleco-500
                                            focus:ring-4
                                            focus:ring-paleco-500/10
                                            lg:min-w-72
                                        "
                                    >

                                        <?php foreach ($linkedAccounts as $account): ?>

                                            <option
                                                value="<?= dashboardEscape($account['AcctNo']) ?>"
                                                <?= $account['AcctNo']
                                                    === $selectedAccount['AcctNo']
                                                    ? 'selected'
                                                    : ''
                                                ?>
                                            >
                                                <?= dashboardEscape(
                                                    (
                                                        $account['account_nickname']
                                                        ?: (
                                                            $account['Name']
                                                            ?: 'PALECO account'
                                                        )
                                                    )
                                                    . ' · '
                                                    . $account['AcctNo']
                                                ) ?>
                                                <?= $account['is_primary']
                                                    ? ' (Primary)'
                                                    : ''
                                                ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>


                                    <button
                                        type="submit"
                                        class="
                                            rounded-xl
                                            bg-paleco-700
                                            px-5 py-2.5
                                            text-sm
                                            font-semibold
                                            text-white
                                            hover:bg-paleco-800
                                        "
                                    >
                                        View
                                    </button>

                                </div>

                            </form>

                        <?php endif; ?>

                    </div>

                </section>

            <?php endif; ?>


            <!-- =================================================
                 MY ACCOUNTS
                 ================================================= -->

            <?php if ($page === 'accounts'): ?>

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
                        Service Accounts
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
                                        Account
                                        <?= dashboardEscape(
                                            $account['AcctNo']
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
                                        <?= dashboardEscape(
                                            $account['account_nickname']
                                            ?: (
                                                $account['Name']
                                                ?: 'PALECO account'
                                            )
                                        ) ?>
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
                                    <?= dashboardEscape(
                                        $account['Name']
                                        ?: 'Account holder unavailable'
                                    ) ?>
                                </p>

                                <p class="text-slate-500">
                                    <?= dashboardEscape(
                                        $account['Address']
                                        ?: 'Service address unavailable'
                                    ) ?>
                                </p>

                                <?php if (!empty($account['MeterSerial'])): ?>

                                    <p
                                        class="
                                            pt-2
                                            text-xs
                                            text-slate-400
                                        "
                                    >
                                        Meter:
                                        <?= dashboardEscape(
                                            $account['MeterSerial']
                                        ) ?>
                                    </p>

                                <?php endif; ?>

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
                                        name="account_number"
                                        value="<?= dashboardEscape($account['AcctNo']) ?>"
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
                                            name="account_number"
                                            value="<?= dashboardEscape($account['AcctNo']) ?>"
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


                            <!-- Label -->

                            <form
                                method="post"
                                class="
                                    mt-5
                                    border-t
                                    border-slate-100
                                    pt-4
                                "
                            >

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= dashboardEscape($csrf) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="rename"
                                >

                                <input
                                    type="hidden"
                                    name="account_number"
                                    value="<?= dashboardEscape($account['AcctNo']) ?>"
                                >


                                <label
                                    class="
                                        mb-1.5
                                        block
                                        text-xs
                                        font-semibold
                                        text-slate-500
                                    "
                                >
                                    Friendly label
                                </label>


                                <div class="flex gap-2">

                                    <input
                                        name="label"
                                        maxlength="100"
                                        value="<?= dashboardEscape(
                                            $account['account_nickname']
                                            ?? ''
                                        ) ?>"
                                        placeholder="e.g. Home"
                                        class="
                                            min-w-0
                                            flex-1
                                            rounded-xl
                                            border border-slate-300
                                            px-3 py-2
                                            text-sm
                                            outline-none
                                            focus:border-paleco-500
                                            focus:ring-4
                                            focus:ring-paleco-500/10
                                        "
                                    >

                                    <button
                                        type="submit"
                                        class="
                                            rounded-xl
                                            bg-slate-900
                                            px-4 py-2
                                            text-sm
                                            font-semibold
                                            text-white
                                            hover:bg-slate-800
                                        "
                                    >
                                        Save
                                    </button>

                                </div>

                            </form>

                        </article>

                    <?php endforeach; ?>

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


                    <form
                        method="post"
                        class="
                            mt-6
                            grid gap-4
                            md:grid-cols-3
                        "
                    >

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= dashboardEscape($csrf) ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="add"
                        >


                        <label
                            class="
                                text-sm
                                font-semibold
                                text-slate-700
                            "
                        >

                            Account number

                            <input
                                name="account_number"
                                maxlength="15"
                                required
                                autocomplete="off"
                                class="
                                    mt-1.5
                                    block w-full
                                    rounded-xl
                                    border border-slate-300
                                    px-3 py-2.5
                                    outline-none
                                    focus:border-paleco-500
                                    focus:ring-4
                                    focus:ring-paleco-500/10
                                "
                            >

                        </label>


                        <label
                            class="
                                text-sm
                                font-semibold
                                text-slate-700
                            "
                        >

                            Meter number

                            <input
                                name="meter_number"
                                maxlength="20"
                                required
                                autocomplete="off"
                                class="
                                    mt-1.5
                                    block w-full
                                    rounded-xl
                                    border border-slate-300
                                    px-3 py-2.5
                                    outline-none
                                    focus:border-paleco-500
                                    focus:ring-4
                                    focus:ring-paleco-500/10
                                "
                            >

                        </label>


                        <label
                            class="
                                text-sm
                                font-semibold
                                text-slate-700
                            "
                        >

                            Friendly label

                            <span
                                class="
                                    font-normal
                                    text-slate-400
                                "
                            >
                                (optional)
                            </span>

                            <input
                                name="label"
                                maxlength="100"
                                placeholder="e.g. Home"
                                class="
                                    mt-1.5
                                    block w-full
                                    rounded-xl
                                    border border-slate-300
                                    px-3 py-2.5
                                    outline-none
                                    focus:border-paleco-500
                                    focus:ring-4
                                    focus:ring-paleco-500/10
                                "
                            >

                        </label>


                        <div class="md:col-span-3">

                            <button
                                type="submit"
                                class="
                                    rounded-xl
                                    bg-paleco-700
                                    px-5 py-2.5
                                    text-sm
                                    font-semibold
                                    text-white
                                    hover:bg-paleco-800
                                "
                            >
                                Add PALECO Account
                            </button>

                        </div>

                    </form>

                </section>


            <!-- =================================================
                 PROFILE
                 ================================================= -->

            <?php elseif ($page === 'profile'): ?>

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
                        Portal Profile
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


            <!-- =================================================
                 DASHBOARD / BILLS / HISTORY / CONSUMPTION
                 ================================================= -->

            <?php else: ?>


                <!-- Account heading -->

                <section
                    class="
                        flex
                        flex-col
                        gap-3
                        sm:flex-row
                        sm:items-end
                        sm:justify-between
                    "
                >

                    <div>

                        <p
                            class="
                                text-xs
                                font-bold
                                uppercase
                                tracking-[.14em]
                                text-paleco-700
                            "
                        >
                            Account Overview
                        </p>

                        <h2
                            class="
                                mt-1
                                text-2xl
                                font-bold
                            "
                        >
                            <?= dashboardEscape(
                                $selectedAccount['Name']
                                ?: 'Service account'
                            ) ?>
                        </h2>

                        <p
                            class="
                                mt-1
                                text-sm
                                text-slate-500
                            "
                        >
                            <?= dashboardEscape(
                                $selectedAccount['Address']
                                ?: 'Service address unavailable'
                            ) ?>
                        </p>

                    </div>


                    <a
                        href="dashboard.php?page=accounts"
                        class="
                            text-sm
                            font-semibold
                            text-paleco-700
                            hover:text-paleco-800
                        "
                    >
                        Manage accounts →
                    </a>

                </section>


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
                            Amount Due
                        </p>

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
                            Awaiting arledger integration.
                        </p>

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
                    || $page === 'consumption'
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
                    || $page === 'bill-history'
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

                <?php if ($page === 'bills'): ?>

                    <section
                        class="
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
                            Outstanding Bills
                        </p>

                        <h2
                            class="
                                mt-1
                                text-lg
                                font-bold
                            "
                        >
                            Current balance
                        </h2>


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
                            "
                        >

                            <h3
                                class="
                                    text-sm
                                    font-semibold
                                "
                            >
                                Ledger integration pending
                            </h3>

                            <p
                                class="
                                    mx-auto
                                    mt-2
                                    max-w-lg
                                    text-sm
                                    leading-6
                                    text-slate-500
                                "
                            >
                                Current unpaid balances and
                                surcharges will be loaded from
                                the PALECO arledger table after
                                its local schema is confirmed.
                            </p>

                        </div>

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
                                Account Number
                            </dt>

                            <dd
                                class="
                                    mt-1
                                    text-sm
                                    font-semibold
                                "
                            >
                                <?= dashboardEscape(
                                    $selectedAccount['AcctNo']
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
                                <?= dashboardEscape(
                                    $selectedAccount['Name']
                                    ?: 'Unavailable'
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
                                Meter Number
                            </dt>

                            <dd
                                class="
                                    mt-1
                                    text-sm
                                    font-semibold
                                "
                            >
                                <?= dashboardEscape(
                                    $selectedAccount['MeterSerial']
                                    ?: 'Unavailable'
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
                                Status
                            </dt>

                            <dd
                                class="
                                    mt-1
                                    text-sm
                                    font-semibold
                                "
                            >
                                <?= dashboardEscape(
                                    $selectedAccount['Status']
                                    ?: 'Unavailable'
                                ) ?>
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
                                <?= dashboardEscape(
                                    $selectedAccount['Address']
                                    ?: 'Unavailable'
                                ) ?>
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

    </main>

</div>


<!-- =========================================================
     JAVASCRIPT
     ========================================================= -->

<script src="assets/js/theme.js"></script>

<script>

(function () {

    const sidebar =
        document.getElementById('sidebar');

    const backdrop =
        document.getElementById('mobileBackdrop');

    const openButton =
        document.getElementById('openSidebar');

    const closeButton =
        document.getElementById('closeSidebar');


    function openSidebar() {

        if (!sidebar || !backdrop) {
            return;
        }

        sidebar.classList.remove(
            '-translate-x-full'
        );

        backdrop.classList.remove(
            'hidden'
        );

        document.body.classList.add(
            'overflow-hidden'
        );
    }


    function closeSidebar() {

        if (!sidebar || !backdrop) {
            return;
        }

        sidebar.classList.add(
            '-translate-x-full'
        );

        backdrop.classList.add(
            'hidden'
        );

        document.body.classList.remove(
            'overflow-hidden'
        );
    }


    if (openButton) {

        openButton.addEventListener(
            'click',
            openSidebar
        );
    }


    if (closeButton) {

        closeButton.addEventListener(
            'click',
            closeSidebar
        );
    }


    if (backdrop) {

        backdrop.addEventListener(
            'click',
            closeSidebar
        );
    }


    document.addEventListener(
        'keydown',
        function (event) {

            if (event.key === 'Escape') {
                closeSidebar();
            }

        }
    );


    window.addEventListener(
        'resize',
        function () {

            if (window.innerWidth >= 1024) {

                document.body.classList.remove(
                    'overflow-hidden'
                );

                if (backdrop) {

                    backdrop.classList.add(
                        'hidden'
                    );
                }
            }

        }
    );

})();


<?php if (
    $consumptionHistory
    && (
        $page === 'dashboard'
        || $page === 'consumption'
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


</body>
</html>
