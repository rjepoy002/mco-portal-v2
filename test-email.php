<?php

require_once __DIR__ . '/config/mailer.php';

$sent = sendEmail(
    'rjepoy002@gmail.com',
    'Test User',
    'PALECO MCO Portal - Test Email',
    '
        <h2>Email Test Successful</h2>

        <p>
            This is a test email from the
            <strong>PALECO MCO Portal</strong>.
        </p>

        <p>
            PHPMailer and Gmail SMTP are working correctly.
        </p>
    ',
    'This is a test email from the PALECO MCO Portal.'
);

if ($sent) {

    echo 'Email sent successfully.';

} else {

    echo 'Failed to send email. Check the PHP error log.';
}