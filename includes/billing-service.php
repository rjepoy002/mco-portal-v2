<?php

/**
 * PALECO billing data is read-only. Portal account ownership is checked before
 * every billing query. AcctNo is a varchar in the legacy schema.
 */
function getUnpaidBills(PDO $pdo, int $userId, string $acctNo): array
{
    $ownership = $pdo->prepare(
        "SELECT 1 FROM mco_portal.paleco_accounts
         WHERE user_id = ? AND AcctNo = ? AND status = 'active'
         LIMIT 1"
    );
    $ownership->execute([$userId, $acctNo]);
    if (!$ownership->fetchColumn()) {
        throw new DomainException('Account is not linked to this portal user.');
    }

    // Both source tables are reduced to one row per account/month before the
    // join. SUM(PowerBill) matches the legacy rule, including reversal entries.
    // MAX(DueDate) gives duplicate bill-history months the latest due date.
    $stmt = $pdo->prepare(<<<'SQL'
WITH ledger AS (
    SELECT AcctNo, BillMonth,
           ROUND(SUM(Debit) - SUM(Credit), 2) AS outstanding_balance,
           SUM(PowerBill) AS power_bill
    FROM palecxzp_pal_db.arledger
    WHERE AcctNo = ?
    GROUP BY AcctNo, BillMonth
    HAVING ROUND(SUM(Debit) - SUM(Credit), 2) > 0
), due_dates AS (
    SELECT AcctNo, BillMonth,
           MAX(CASE WHEN DueDate >= '1000-01-01' THEN DATE(DueDate) END) AS due_date
    FROM palecxzp_pal_db.billhistory
    WHERE AcctNo = ?
    GROUP BY AcctNo, BillMonth
), charges AS (
    SELECT l.BillMonth, d.due_date, l.outstanding_balance,
           CASE
               WHEN d.due_date < CURDATE() AND l.BillMonth <> '202211'
               THEN ROUND(ROUND(l.power_bill * 0.05, 2)
                          + ROUND(l.power_bill * 0.05, 2) * 0.12, 2)
               ELSE 0.00
           END AS surcharge,
           CASE WHEN d.due_date < CURDATE() THEN 1 ELSE 0 END AS is_overdue
    FROM ledger AS l
    LEFT JOIN due_dates AS d
      ON d.AcctNo = l.AcctNo AND d.BillMonth = l.BillMonth
)
SELECT BillMonth AS bill_month, due_date, outstanding_balance, surcharge,
       ROUND(outstanding_balance + surcharge, 2) AS total_due, is_overdue
FROM charges
ORDER BY BillMonth DESC
SQL);
    $stmt->execute([$acctNo, $acctNo]);

    return array_map(static function (array $row): array {
        return [
            'bill_month' => (string) $row['bill_month'],
            'due_date' => $row['due_date'] !== null ? (string) $row['due_date'] : null,
            'outstanding_balance' => (float) $row['outstanding_balance'],
            'surcharge' => (float) $row['surcharge'],
            'total_due' => (float) $row['total_due'],
            'is_overdue' => (bool) $row['is_overdue'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function summarizeUnpaidBills(array $bills): array
{
    $totalCents = 0;
    $overdueCount = 0;
    foreach ($bills as $bill) {
        $totalCents += (int) round($bill['total_due'] * 100);
        if ($bill['is_overdue']) {
            $overdueCount++;
        }
    }
    return [
        'total_due' => $totalCents / 100,
        'unpaid_count' => count($bills),
        'overdue_count' => $overdueCount,
    ];
}
