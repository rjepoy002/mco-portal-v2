<?php

session_start();
require_once __DIR__ . '/../includes/account-linking.php';

function accountLinkAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

accountLinkAssert(normalizePalecoAccCode('12-3456-7890') === '1234567890', 'Formatted AccCode was not accepted.');
accountLinkAssert(normalizePalecoAccCode(' 1234567890 ') === '1234567890', 'Unformatted AccCode was not canonicalized.');
accountLinkAssert(normalizePalecoAccCode('ab-1234-5678') === 'AB12345678', 'Alphabetic AccCode was not uppercased.');
accountLinkAssert(normalizePalecoAccCode('12_3456_7890') === null, 'Unsupported AccCode separators were accepted.');
accountLinkAssert(normalizePalecoAccCode('12-345-67890') === null, 'Malformed AccCode separator placement was accepted.');
accountLinkAssert(normalizePalecoAccCode('123') === null, 'Malformed AccCode was accepted.');
accountLinkAssert(normalizePalecoMeterSerial(' 00123A ') === '00123A', 'Meter serial leading zeroes were not preserved.');
accountLinkAssert(normalizePalecoMeterSerial("meter\nserial") === null, 'Meter serial control characters were accepted.');
accountLinkAssert(normalizePalecoMeterSerial(str_repeat('1', 21)) === null, 'Oversized meter serial was accepted.');

unset($_SESSION['account_link_failures']);
accountLinkAssert(!accountLinkRateLimited(), 'Fresh session was rate limited.');
for ($attempt = 0; $attempt < 5; $attempt += 1) {
    recordAccountLinkFailure();
}
accountLinkAssert(!accountLinkRateLimited(), 'Development mode must keep account-link rate limiting disabled.');
accountLinkAssert(!isset($_SESSION['account_link_failures']), 'Development mode must not record failed account-link attempts.');
clearAccountLinkFailures();


$verificationSql = palecoAccountVerificationSql();
accountLinkAssert(str_contains($verificationSql, "REPLACE(TRIM(AcctCode), '-', '') = :acct_code"), 'Verification query does not map normalized input to master.AcctCode.');
accountLinkAssert(str_contains($verificationSql, 'TRIM(MeterSerial) = :meter_serial'), 'Verification query does not require MeterSerial on the same master row.');

$dashboardSource = file_get_contents(__DIR__ . '/../dashboard.php');
accountLinkAssert(str_contains($dashboardSource, 'accountLinkAcquireLock'), 'Duplicate-link serialization is missing.');
accountLinkAssert(str_contains($dashboardSource, "WHERE AcctNo = ?\n                      AND user_id <> ?"), 'Cross-user duplicate account protection is missing.');
echo "Account linking helper tests passed.\n";