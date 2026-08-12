<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/mailer.php';

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email'] ?? '');

    // Validate email
    if ($email === '') {

        $message = 'Please enter your email address.';
        $messageType = 'error';

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $message = 'Please enter a valid email address.';
        $messageType = 'error';

    } else {

        // Find user by email
        $stmt = $pdo->prepare(
            'SELECT id, name, email FROM users WHERE email = :email LIMIT 1'
        );

        $stmt->execute([
            ':email' => $email
        ]);

        $user = $stmt->fetch();

        /*
         * Always show the same message whether the email
         * exists or not. This prevents account enumeration.
         */
        if ($user) {

            // Generate secure reset token
            $token = bin2hex(random_bytes(32));

            // Store only the token hash
            $tokenHash = hash('sha256', $token);

            // Token expires after 1 hour
            // $expiresAt = date('Y-m-d H:i:s', time() + 3600);

            // Remove previous unused reset tokens
            $deleteStmt = $pdo->prepare(
                'DELETE FROM password_resets
                WHERE user_id = :user_id'
            );

            $deleteStmt->execute([
                ':user_id' => $user['id']
            ]);

            // Store new reset token
            $insertStmt = $pdo->prepare(
                'INSERT INTO password_resets
                (user_id, token_hash, expires_at)
                VALUES
                (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 1 HOUR))'
            );

            $insertStmt->execute([
                ':user_id' => $user['id'],
                ':token_hash' => $tokenHash
            ]);

            // Create reset link
            $resetLink =
                'http://localhost:8000/reset-password.php?token='
                . urlencode($token);

            // Email content
            $subject = 'Reset Your MCO Portal Password';

            $htmlBody = '
                <h2>Password Reset Request</h2>

                <p>Hello ' . htmlspecialchars($user['name']) . ',</p>

                <p>
                    We received a request to reset your MCO Portal password.
                </p>

                <p>
                    Click the button below to create a new password:
                </p>

                <p>
                    <a href="' . htmlspecialchars($resetLink) . '"
                       style="
                           display:inline-block;
                           padding:12px 20px;
                           background:#1a73e8;
                           color:#ffffff;
                           text-decoration:none;
                           border-radius:6px;
                       ">
                        Reset Password
                    </a>
                </p>

                <p>
                    This link will expire in <strong>1 hour</strong>.
                </p>

                <p>
                    If you did not request a password reset, you can safely
                    ignore this email.
                </p>

                <p>
                    Regards,<br>
                    MCO Portal
                </p>
            ';

            $plainBody =
                "Hello {$user['name']},\n\n"
                . "We received a request to reset your MCO Portal password.\n\n"
                . "Reset your password using this link:\n"
                . $resetLink . "\n\n"
                . "This link will expire in 1 hour.\n\n"
                . "If you did not request a password reset, you can safely "
                . "ignore this email.\n\n"
                . "Regards,\n"
                . "MCO Portal";

            sendEmail(
                $user['email'],
                $user['name'],
                $subject,
                $htmlBody,
                $plainBody
            );
        }

        $message =
            'If an account exists with that email address, '
            . 'a password reset link has been sent.';

        $messageType = 'success';
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Forgot Password | MCO Portal</title>

    <link
        rel="stylesheet"
        href="assets/css/forgot-password.css"
    >

</head>

<body>

    <div class="page">

        <!-- LEFT BRANDING -->
        <section class="branding-panel">

            <div class="brand-content">

                <div class="logo-wrapper">

                    <img
                        src="assets/images/logo.png"
                        alt="MCO Portal Logo"
                        class="logo"
                    >

                    <div>
                        <div class="brand-name">
                            MCO PORTAL
                        </div>

                        <div class="brand-subtitle">
                            Management & Coordination Office
                        </div>
                    </div>

                </div>


                <div class="hero">

                    <h1>
                        Get back to
                        <span>your account.</span>
                    </h1>

                    <div class="green-line"></div>

                    <p>
                        Forgot your password? Don't worry.
                        We'll help you securely regain access
                        to your MCO Portal account.
                    </p>

                </div>

            </div>

        </section>


        <!-- RIGHT FORM -->
        <section class="login-panel">

            <div class="login-container">

                <div class="login-card">

                    <div class="login-header">

                        <div class="eyebrow">
                            Account Recovery
                        </div>

                        <h2>
                            Forgot Password?
                        </h2>

                        <p>
                            Enter your email address and we'll
                            send you a secure link to reset your password.
                        </p>

                    </div>


                    <?php if ($message !== ''): ?>

                        <div class="<?= $messageType === 'success'
                            ? 'login-success'
                            : 'login-error' ?>">

                            <i class="fa-solid <?= $messageType === 'success'
                                ? 'fa-circle-check'
                                : 'fa-circle-exclamation' ?>"></i>

                            <span>
                                <?= htmlspecialchars($message) ?>
                            </span>

                        </div>

                    <?php endif; ?>


                    <form
                        method="POST"
                        action=""
                        id="forgotPasswordForm"
                    >

                        <div class="form-group">

                            <label
                                for="email"
                                class="form-label"
                            >
                                Email Address
                            </label>

                            <div class="input-wrapper">

                                <i class="fa-regular fa-envelope"></i>

                                <input
                                    type="email"
                                    id="email"
                                    name="email"
                                    class="form-control"
                                    placeholder="Enter your email address"
                                    value="<?= htmlspecialchars(
                                        $_POST['email'] ?? ''
                                    ) ?>"
                                    required
                                    autocomplete="email"
                                >

                            </div>

                        </div>


                        <button
                            type="submit"
                            class="btn-signin"
                            id="submitButton"
                        >
                            Send Reset Link
                        </button>

                    </form>


                    <div class="register">

                        <a href="login.php">
                            ← Back to Login
                        </a>

                    </div>


                    <div class="footer">

                        MCO Portal &copy; <?= date('Y') ?>

                    </div>

                </div>

            </div>

        </section>

    </div>


    <script
        src="assets/js/forgot-password.js">
    </script>

</body>

</html>