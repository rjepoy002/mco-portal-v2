<?php

require_once __DIR__ . '/../includes/billing-service.php';

$emptySummary = summarizeUnpaidBills([]);
$expectedEmpty = [
    'total_due' => 0,
    'unpaid_count' => 0,
    'overdue_count' => 0,
];

if ($emptySummary !== $expectedEmpty) {
    throw new RuntimeException('Zero-balance summary did not match the empty unpaid-bills fixture.');
}

$fixture = [
    [
        'total_due' => 125.55,
        'is_overdue' => true,
    ],
    [
        'total_due' => 74.45,
        'is_overdue' => false,
    ],
];

$summary = summarizeUnpaidBills($fixture);
$expected = [
    'total_due' => 200,
    'unpaid_count' => 2,
    'overdue_count' => 1,
];

if ($summary !== $expected) {
    throw new RuntimeException('Unpaid-bills summary did not total the controlled fixture correctly.');
}

echo "Billing summary fixture check passed.\n";