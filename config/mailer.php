<?php

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/../vendor/autoload.php';

function sendEmail(
    string $recipientEmail,
    string $recipientName,
    string $subject,
    string $htmlBody,
    string $plainBody = ''
): bool {

    $config = require __DIR__ . '/mail.php';

    $mail = new PHPMailer(true);

    try {

        $mail->isSMTP();

        $mail->Host       = $config['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['username'];
        $mail->Password   = $config['password'];
        $mail->Port       = $config['port'];

        if ($config['encryption'] === 'tls') {

            $mail->SMTPSecure =
                PHPMailer::ENCRYPTION_STARTTLS;

        } else {

            $mail->SMTPSecure =
                PHPMailer::ENCRYPTION_SMTPS;
        }

        $mail->CharSet = 'UTF-8';

        $mail->setFrom(
            $config['from_email'],
            $config['from_name']
        );

        $mail->addAddress(
            $recipientEmail,
            $recipientName
        );

        $mail->isHTML(true);

        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;

        $mail->AltBody =
            $plainBody !== ''
                ? $plainBody
                : strip_tags($htmlBody);

        return $mail->send();

    } catch (Exception $e) {

        error_log(
            'Mailer Error: ' . $mail->ErrorInfo
        );

        return false;
    }
}