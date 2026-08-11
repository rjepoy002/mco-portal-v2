<?php

session_start();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/mailer.php';

$errors = [];
$otpError = '';
$showOtp = false;

$otpExpiresAt = null;
$resendAvailableAt = null;
$resendSeconds = 60;

// Keep OTP section visible while registration is pending
// only on a normal page load or OTP verification request.

if (
    $_SERVER['REQUEST_METHOD'] === 'GET' ||
    ($_POST['action'] ?? '') === 'verify_otp'
) {

    if (isset($_SESSION['registration'])) {

        $showOtp = true;

        $stmt = $pdo->prepare("
            SELECT expires_at, resend_available_at
            FROM email_verifications
            WHERE email = ?
            ORDER BY id DESC
            LIMIT 1
        ");

        $stmt->execute([
            $_SESSION['registration']['email']
        ]);

        $verification = $stmt->fetch();

        if ($verification) {

            $otpExpiresAt =
                $verification['expires_at'];

            $resendAvailableAt =
                $verification['resend_available_at'];

            if ($resendAvailableAt) {

                $resendSeconds = max(
                    0,
                    strtotime($resendAvailableAt) - time()
                );
            }
        }
    }
}


// =====================================================
// REGISTRATION
// =====================================================

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'register'
) {

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';


    // ---------------------------------------------
    // NAME
    // ---------------------------------------------

    if ($name === '') {

        $errors[] = 'Please enter your full name.';

    } elseif (strlen($name) > 150) {

        $errors[] = 'Your name is too long.';
    }


    // ---------------------------------------------
    // EMAIL
    // ---------------------------------------------

    if ($email === '') {

        $errors[] = 'Please enter your email address.';

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $errors[] = 'Please enter a valid email address.';

    } elseif (strlen($email) > 255) {

        $errors[] = 'Your email address is too long.';
    }


    // ---------------------------------------------
    // PASSWORD
    // ---------------------------------------------

    if ($password === '') {

        $errors[] = 'Please enter a password.';

    } elseif (strlen($password) < 8) {

        $errors[] = 'Password must be at least 8 characters.';

    } elseif (!preg_match('/[A-Za-z]/', $password)) {

        $errors[] = 'Password must contain at least one letter.';

    } elseif (!preg_match('/[0-9]/', $password)) {

        $errors[] = 'Password must contain at least one number.';
    }


    // ---------------------------------------------
    // CONFIRM PASSWORD
    // ---------------------------------------------

    if ($password !== $confirmPassword) {

        $errors[] = 'Passwords do not match.';
    }


    // ---------------------------------------------
    // TERMS
    // ---------------------------------------------

    if (!isset($_POST['terms'])) {

        $errors[] =
            'Please agree to the Terms of Use and Privacy Policy.';
    }


    // ---------------------------------------------
    // CHECK EMAIL
    // ---------------------------------------------

    if (empty($errors)) {

        $stmt = $pdo->prepare("
            SELECT id
            FROM users
            WHERE email = ?
            LIMIT 1
        ");

        $stmt->execute([$email]);

        if ($stmt->fetch()) {

            $errors[] =
                'An account with this email address already exists.';
        }
    }


    // ---------------------------------------------
    // CREATE OTP
    // ---------------------------------------------

    if (empty($errors)) {

        try {

            $otp = (string) random_int(100000, 999999);

            $otpHash = password_hash(
                $otp,
                PASSWORD_DEFAULT
            );

            $expiresAt = date(
                'Y-m-d H:i:s',
                time() + 600
            );

            $resendAvailableAt = date(
                'Y-m-d H:i:s',
                time() + 60
            );

            $otpExpiresAt = $expiresAt;
            $resendSeconds = 60;

            // Remove previous verification codes

            $stmt = $pdo->prepare("
                DELETE FROM email_verifications
                WHERE email = ?
            ");

            $stmt->execute([$email]);


            // Store new OTP

            $stmt = $pdo->prepare("
                INSERT INTO email_verifications
                (
                    email,
                    otp_hash,
                    expires_at,
                    resend_available_at
                )
                VALUES
                (?, ?, ?, ?)
            ");

            $stmt->execute([
                $email,
                $otpHash,
                $expiresAt,
                $resendAvailableAt
            ]);


            // Store registration information
            // temporarily in the session

            $_SESSION['registration'] = [
                'name' => $name,
                'email' => $email,
                'password_hash' => password_hash(
                    $password,
                    PASSWORD_DEFAULT
                )
            ];


            // Send OTP email

            $emailSent = sendEmail(
                $email,
                $name,
                'PALECO MCO Portal - Email Verification',
                '
                    <div style="
                        font-family: Arial, sans-serif;
                        max-width: 600px;
                        margin: 0 auto;
                        padding: 30px;
                    ">

                        <h2>
                            Verify Your Email
                        </h2>

                        <p>
                            Hello ' .
                            htmlspecialchars($name) .
                            ',
                        </p>

                        <p>
                            Thank you for registering
                            for the PALECO Member-Consumer
                            Online Portal.
                        </p>

                        <p>
                            Your verification code is:
                        </p>

                        <div style="
                            font-size: 32px;
                            font-weight: bold;
                            letter-spacing: 8px;
                            margin: 25px 0;
                        ">
                            ' . $otp . '
                        </div>

                        <p>
                            This code will expire in
                            <strong>10 minutes</strong>.
                        </p>

                        <p>
                            If you did not request this
                            verification code, you may
                            safely ignore this email.
                        </p>

                        <p>
                            PALECO MCO Portal
                        </p>

                    </div>
                ',
                'Your PALECO MCO Portal verification code is: '
                . $otp
                . "\n\n"
                . 'This code will expire in 10 minutes.'
            );


            if (!$emailSent) {

                // Remove OTP if email failed

                $stmt = $pdo->prepare("
                    DELETE FROM email_verifications
                    WHERE email = ?
                ");

                $stmt->execute([$email]);

                unset($_SESSION['registration']);

                $errors[] =
                    'We could not send the verification email. '
                    . 'Please try again.';
            } else {

                $showOtp = true;
            }


        } catch (Throwable $e) {

            error_log(
                'Registration error: ' . $e->getMessage()
            );

            unset($_SESSION['registration']);

            $errors[] =
                'Something went wrong while creating '
                . 'your verification request. Please try again.';
        }
    }
}

// =====================================================
// RESEND OTP
// =====================================================

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'resend_otp'
) {

    header('Content-Type: application/json');

    if (!isset($_SESSION['registration'])) {

        echo json_encode([
            'success' => false,
            'message' =>
                'Your registration session has expired. Please register again.'
        ]);

        exit;
    }


    $registration = $_SESSION['registration'];

    $email = $registration['email'];
    $name  = $registration['name'];


    // ---------------------------------------------
    // Get current verification record
    // ---------------------------------------------

    $stmt = $pdo->prepare("
        SELECT *
        FROM email_verifications
        WHERE email = ?
        ORDER BY id DESC
        LIMIT 1
    ");

    $stmt->execute([$email]);

    $verification = $stmt->fetch();


    if (!$verification) {

        echo json_encode([
            'success' => false,
            'message' =>
                'Verification request not found. Please register again.'
        ]);

        exit;
    }


    // ---------------------------------------------
    // Check resend cooldown
    // ---------------------------------------------

    if (
        $verification['resend_available_at'] !== null &&
        strtotime($verification['resend_available_at']) > time()
    ) {

        $remaining = max(
            1,
            strtotime($verification['resend_available_at']) - time()
        );

        echo json_encode([
            'success' => false,
            'message' =>
                'Please wait before requesting another code.',
            'remaining' => $remaining
        ]);

        exit;
    }


    try {

        // -----------------------------------------
        // Generate new OTP
        // -----------------------------------------

        $otp = (string) random_int(100000, 999999);

        $otpHash = password_hash(
            $otp,
            PASSWORD_DEFAULT
        );


        $expiresAt = date(
            'Y-m-d H:i:s',
            time() + 600
        );

        $resendAvailableAt = date(
            'Y-m-d H:i:s',
            time() + 60
        );


        // -----------------------------------------
        // Update verification record
        // -----------------------------------------

        $stmt = $pdo->prepare("
            UPDATE email_verifications
            SET
                otp_hash = ?,
                expires_at = ?,
                resend_available_at = ?,
                attempts = 0,
                verified_at = NULL
            WHERE id = ?
        ");

        $stmt->execute([
            $otpHash,
            $expiresAt,
            $resendAvailableAt,
            $verification['id']
        ]);


        // -----------------------------------------
        // Send email
        // -----------------------------------------

        $emailSent = sendEmail(
            $email,
            $name,
            'PALECO MCO Portal - New Verification Code',

            '
                <div style="
                    font-family: Arial, sans-serif;
                    max-width: 600px;
                    margin: 0 auto;
                    padding: 30px;
                ">

                    <h2>
                        Your New Verification Code
                    </h2>

                    <p>
                        Hello ' .
                        htmlspecialchars($name) .
                        ',
                    </p>

                    <p>
                        You requested a new verification
                        code for your PALECO MCO Portal
                        registration.
                    </p>

                    <p>
                        Your new verification code is:
                    </p>

                    <div style="
                        font-size: 32px;
                        font-weight: bold;
                        letter-spacing: 8px;
                        margin: 25px 0;
                    ">
                        ' . $otp . '
                    </div>

                    <p>
                        This code will expire in
                        <strong>10 minutes</strong>.
                    </p>

                    <p>
                        PALECO MCO Portal
                    </p>

                </div>
            ',

            'Your new PALECO MCO Portal verification code is: '
            . $otp
            . "\n\n"
            . 'This code will expire in 10 minutes.'
        );


        if (!$emailSent) {

            echo json_encode([
                'success' => false,
                'message' =>
                    'We could not send the verification email. Please try again.'
            ]);

            exit;
        }


        echo json_encode([
            'success' => true,
            'message' =>
                'A new verification code has been sent to your email.',
            'remaining' => 60
        ]);

        exit;


    } catch (Throwable $e) {

        error_log(
            'Resend OTP error: ' . $e->getMessage()
        );

        echo json_encode([
            'success' => false,
            'message' =>
                'Something went wrong. Please try again.'
        ]);

        exit;
    }
}


// =====================================================
// OTP VERIFICATION
// =====================================================

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'verify_otp'
) {

    $otp = preg_replace(
        '/[^0-9]/',
        '',
        $_POST['otp'] ?? ''
    );


    if (!isset($_SESSION['registration'])) {

        $otpError =
            'Your registration session has expired. '
            . 'Please register again.';

    } elseif (strlen($otp) !== 6) {

        $otpError =
            'Please enter the 6-digit verification code.';

    } else {

        $registration = $_SESSION['registration'];


        $stmt = $pdo->prepare("
            SELECT *
            FROM email_verifications
            WHERE email = ?
            ORDER BY id DESC
            LIMIT 1
        ");

        $stmt->execute([
            $registration['email']
        ]);

        $verification = $stmt->fetch();


        if (!$verification) {

            $otpError =
                'Verification code not found. '
                . 'Please request a new code.';

        } elseif ($verification['verified_at'] !== null) {

            $otpError =
                'This verification code has already been used.';

        } elseif (
            strtotime($verification['expires_at']) < time()
        ) {

            $otpError =
                'This verification code has expired. '
                . 'Please request a new code.';

        } elseif ($verification['attempts'] >= 5) {

            $otpError =
                'Too many incorrect attempts. '
                . 'Please request a new code.';

        } elseif (
            !password_verify(
                $otp,
                $verification['otp_hash']
            )
        ) {

            $stmt = $pdo->prepare("
                UPDATE email_verifications
                SET attempts = attempts + 1
                WHERE id = ?
            ");

            $stmt->execute([
                $verification['id']
            ]);

            $otpError =
                'Invalid verification code.';

        } else {

            // -----------------------------------------
            // OTP VERIFIED
            // -----------------------------------------

            $stmt = $pdo->prepare("
                INSERT INTO users
                    (
                        email,
                        name,
                        password_hash,
                        email_verified_at,
                        status
                    )
                VALUES
                    (?, ?, ?, NOW(), 'active')
            ");

            $stmt->execute([
                $registration['email'],
                $registration['name'],
                $registration['password_hash']
            ]);


            $stmt = $pdo->prepare("
                UPDATE email_verifications
                SET verified_at = NOW()
                WHERE id = ?
            ");

            $stmt->execute([
                $verification['id']
            ]);


            // Clear temporary registration data

            unset($_SESSION['registration']);


            // Registration successful

            header('Location: login.php?registered=1');
            exit;
        }
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

    <title>Create Account | PALECO MCO Portal</title>

    <link
        rel="icon"
        type="image/png"
        href="assets/images/logo.png"
    >

    <!-- Google Font -->
    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
    >

    <link
        rel="preconnect"
        href="https://fonts.gstatic.com"
        crossorigin
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <!-- Bootstrap Icons -->
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >

    <!-- Register CSS -->
    <link
        rel="stylesheet"
        href="assets/css/register.css"
    >

</head>


<body>


<div class="page">


    <!-- =========================================
         LEFT BRANDING PANEL
    ========================================== -->

    <section class="branding-panel">


        <div class="brand-content">


            <!-- LOGO -->

            <div class="logo-wrapper">

                <img
                    src="assets/images/logo.png"
                    alt="Palawan Electric Cooperative Logo"
                    class="logo"
                >

                <div>

                    <div class="brand-name">
                        PALAWAN ELECTRIC COOPERATIVE (PALECO)
                    </div>

                    <div class="brand-subtitle">
                        KM 3.35, North National Highway,
                        Barangay Tiniguiban,
                        Puerto Princesa City
                    </div>

                </div>

            </div>


            <!-- HERO -->

            <div class="hero">

                <h1>

                    STAY CONNECTED.

                    <span>
                        STAY INFORMED.
                    </span>

                </h1>


                <div class="green-line"></div>


                <p>

                    Create your PALECO Member-Consumer
                    Online Portal account and conveniently
                    manage your electricity services online.

                </p>

            </div>

        </div>


        <!-- FEATURES -->

        <div class="features">


            <div class="feature">

                <i class="bi bi-person-check"></i>

                <strong>
                    Easy Access
                </strong>

                <span>
                    Manage your account anytime.
                </span>

            </div>


            <div class="feature">

                <i class="bi bi-shield-check"></i>

                <strong>
                    Secure
                </strong>

                <span>
                    Your account is protected.
                </span>

            </div>


            <div class="feature">

                <i class="bi bi-envelope-check"></i>

                <strong>
                    Verified Email
                </strong>

                <span>
                    Confirm your email securely.
                </span>

            </div>


        </div>


    </section>



    <!-- =========================================
         RIGHT REGISTRATION PANEL
    ========================================== -->

    <main class="register-panel">


        <div class="register-container">


            <div class="register-card">


                <!-- =================================
                     REGISTRATION FORM
                ================================== -->

                <div
                    class="registration-section"
                    id="registrationSection"
                >


                    <div class="register-header">

                        <div class="eyebrow">
                            Member-Consumer Registration
                        </div>

                        <h2>
                            Create Account
                        </h2>

                        <p>
                            Enter your details to get started.
                        </p>

                    </div>


                    <!-- PHP ERRORS -->

                    <?php if (!empty($errors)): ?>

                        <div class="error-box">

                            <i class="bi bi-exclamation-circle"></i>

                            <div>

                                <?php foreach ($errors as $error): ?>

                                    <div>
                                        <?= htmlspecialchars($error) ?>
                                    </div>

                                <?php endforeach; ?>

                            </div>

                        </div>

                    <?php endif; ?>


                    <form
                        id="registerForm"
                        action="register.php"
                        method="POST"
                        novalidate
                    >

                        <input
                            type="hidden"
                            name="action"
                            value="register"
                        >

                        <!-- FULL NAME -->

                        <div class="form-group">

                            <label
                                for="name"
                                class="form-label"
                            >
                                Full Name
                            </label>


                            <div class="input-wrapper">

                                <i class="bi bi-person"></i>

                                <input
                                    type="text"
                                    id="name"
                                    name="name"
                                    class="form-control"
                                    placeholder="Enter your full name"
                                    autocomplete="name"
                                    maxlength="100"
                                    value="<?= htmlspecialchars($_POST["name"] ?? "") ?>"
                                    required
                                >

                            </div>


                            <div
                                class="field-error"
                                id="nameError"
                            ></div>

                        </div>



                        <!-- EMAIL -->

                        <div class="form-group">

                            <label
                                for="email"
                                class="form-label"
                            >
                                Email Address
                            </label>


                            <div class="input-wrapper">

                                <i class="bi bi-envelope"></i>

                                <input
                                    type="email"
                                    id="email"
                                    name="email"
                                    class="form-control"
                                    placeholder="Enter your email address"
                                    autocomplete="email"
                                    maxlength="254"
                                    value="<?= htmlspecialchars($_POST["email"] ?? "") ?>"
                                    required
                                >

                            </div>


                            <div class="field-help">
                                We'll send a verification code to this email.
                            </div>


                            <div
                                class="field-error"
                                id="emailError"
                            ></div>

                        </div>



                        <!-- PASSWORD -->

                        <div class="form-group">

                            <label
                                for="password"
                                class="form-label"
                            >
                                Password
                            </label>


                            <div class="input-wrapper">

                                <i class="bi bi-lock"></i>

                                <input
                                    type="password"
                                    id="password"
                                    name="password"
                                    class="form-control"
                                    placeholder="Create a password"
                                    autocomplete="new-password"
                                    required
                                >


                                <button
                                    type="button"
                                    class="password-toggle"
                                    id="passwordToggle"
                                    aria-label="Show password"
                                    tabindex="-1"
                                >

                                    <i class="bi bi-eye"></i>

                                </button>

                            </div>


                            <!-- PASSWORD STRENGTH -->

                            <div class="password-strength">

                                <div class="strength-bars">

                                    <span></span>
                                    <span></span>
                                    <span></span>
                                    <span></span>

                                </div>


                                <span
                                    id="strengthText"
                                    class="strength-text"
                                >
                                    Enter a password
                                </span>

                            </div>


                            <div class="password-requirements">

                                <div id="lengthRequirement">

                                    <i class="bi bi-circle"></i>

                                    At least 8 characters

                                </div>


                                <div id="numberRequirement">

                                    <i class="bi bi-circle"></i>

                                    At least one number

                                </div>


                                <div id="letterRequirement">

                                    <i class="bi bi-circle"></i>

                                    At least one letter

                                </div>

                            </div>


                            <div
                                class="field-error"
                                id="passwordError"
                            ></div>

                        </div>



                        <!-- CONFIRM PASSWORD -->

                        <div class="form-group">

                            <label
                                for="confirm_password"
                                class="form-label"
                            >
                                Confirm Password
                            </label>


                            <div class="input-wrapper">

                                <i class="bi bi-lock-fill"></i>

                                <input
                                    type="password"
                                    id="confirm_password"
                                    name="confirm_password"
                                    class="form-control"
                                    placeholder="Re-enter your password"
                                    autocomplete="new-password"
                                    required
                                >


                                <button
                                    type="button"
                                    class="password-toggle"
                                    id="confirmPasswordToggle"
                                    aria-label="Show password"
                                    tabindex="-1"
                                >

                                    <i class="bi bi-eye"></i>

                                </button>

                            </div>


                            <div
                                class="field-error"
                                id="confirmPasswordError"
                            ></div>

                        </div>



                        <!-- TERMS -->

                        <label class="terms">

                            <input
                                type="checkbox"
                                id="terms"
                                name="terms"
                                required
                            >

                            <span>

                                I agree to the
                                <a href="#">
                                    Terms of Use
                                </a>
                                and
                                <a href="#">
                                    Privacy Policy
                                </a>.

                            </span>

                        </label>


                        <div
                            class="field-error"
                            id="termsError"
                        ></div>



                        <!-- CREATE ACCOUNT -->

                        <button
                            type="submit"
                            class="btn-register"
                        >

                            Create Account

                            <i class="bi bi-arrow-right"></i>

                        </button>


                    </form>


                    <!-- LOGIN LINK -->

                    <div class="login-link">

                        Already have an account?

                        <a href="login.php">
                            Sign in
                        </a>

                    </div>


                </div>



                <!-- =================================
                     OTP VERIFICATION
                ================================== -->

                <div
                    class="otp-section"
                    id="otpSection"
                    hidden
                >


                    <div class="otp-icon">

                        <i class="bi bi-envelope-check"></i>

                    </div>


                    <div class="register-header">

                        <div class="eyebrow">
                            Email Verification
                        </div>

                        <h2>
                            Verify Your Email
                        </h2>

                        <p>

                            We've sent a 6-digit
                            verification code to

                        </p>


                        <strong
                            id="otpEmail"
                            class="otp-email"
                        >
                            your email address
                        </strong>

                    </div>


                    <!-- OTP FORM -->

                    <form
                        id="otpForm"
                        action="register.php"
                        method="POST"
                    >

                        <input
                            type="hidden"
                            name="action"
                            value="verify_otp"
                        >

                        <div class="otp-inputs">

                            <input
                                type="text"
                                maxlength="1"
                                inputmode="numeric"
                                autocomplete="one-time-code"
                                class="otp-input"
                            >

                            <input
                                type="text"
                                maxlength="1"
                                inputmode="numeric"
                                class="otp-input"
                            >

                            <input
                                type="text"
                                maxlength="1"
                                inputmode="numeric"
                                class="otp-input"
                            >

                            <input
                                type="text"
                                maxlength="1"
                                inputmode="numeric"
                                class="otp-input"
                            >

                            <input
                                type="text"
                                maxlength="1"
                                inputmode="numeric"
                                class="otp-input"
                            >

                            <input
                                type="text"
                                maxlength="1"
                                inputmode="numeric"
                                class="otp-input"
                            >

                            <input
                                type="hidden"
                                name="otp"
                                id="otpCode"
                            >

                        </div>

                        <div
                            class="otp-error"
                            id="otpError"
                        >
                            <?= htmlspecialchars($otpError) ?>
                        </div>


                        <button
                            type="submit"
                            class="btn-register"
                        >

                            Verify Email

                            <i class="bi bi-check-lg"></i>

                        </button>


                    </form>


                    <!-- RESEND -->

                    <div class="resend-section">

                        <span>
                            Didn't receive the code?
                        </span>


                        <button
                            type="button"
                            id="resendOtp"
                            class="resend-button"
                        >
                            Resend Code
                        </button>


                        <div
                            id="resendTimer"
                            class="resend-timer"
                        ></div>

                    </div>


                    <button
                        type="button"
                        id="backToRegistration"
                        class="back-button"
                    >

                        <i class="bi bi-arrow-left"></i>

                        Back to registration

                    </button>


                </div>


            </div>


            <!-- FOOTER -->

            <div class="footer">

                © 2026 Palawan Electric Cooperative
                (PALECO).
                All rights reserved.

            </div>


        </div>


    </main>


</div>



<!-- Register JavaScript -->

<script src="assets/js/register.js"></script>

<?php if ($showOtp): ?>

<script>
    document.addEventListener('DOMContentLoaded', function () {

        showOtpSection();

        startResendTimer(
            <?= (int)$resendSeconds ?>
        );

    });
</script>

<?php endif; ?>

</body>

</html>