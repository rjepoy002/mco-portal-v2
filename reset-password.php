<?php

require_once __DIR__ . '/config/database.php';

$message = '';
$messageType = '';

/*
 * ============================================
 * RESET TOKEN
 * ============================================
 *
 * Accept the token from either GET or POST.
 * The hidden POST field keeps the token available
 * when the password form is submitted.
 */

$token = trim(
    $_GET['token']
    ?? $_POST['token']
    ?? ''
);

$user = null;
$resetRecord = null;


/*
 * ============================================
 * VALIDATE RESET TOKEN
 * ============================================
 */

if (
    $token === ''
    || !preg_match('/^[a-f0-9]{64}$/i', $token)
) {

    $message =
        'This password reset link is invalid or has expired.';

    $messageType = 'error';

} else {

    $tokenHash = hash('sha256', $token);

    $stmt = $pdo->prepare(
        'SELECT
            password_resets.id AS reset_id,
            password_resets.user_id,
            password_resets.expires_at,
            users.name,
            users.email
         FROM password_resets
         INNER JOIN users
            ON users.id = password_resets.user_id
         WHERE password_resets.token_hash = :token_hash
           AND password_resets.used_at IS NULL
           AND password_resets.expires_at > NOW()
         LIMIT 1'
    );

    $stmt->execute([
        ':token_hash' => $tokenHash
    ]);

    $resetRecord = $stmt->fetch();

    if (!$resetRecord) {

        $message =
            'This password reset link is invalid or has expired.';

        $messageType = 'error';

    } else {

        $user = $resetRecord;
    }
}


/*
 * ============================================
 * HANDLE PASSWORD RESET
 * ============================================
 */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $user
    && $resetRecord
) {

    $newPassword =
        $_POST['password'] ?? '';

    $confirmPassword =
        $_POST['confirm_password'] ?? '';


    /*
     * -----------------------------------------
     * PASSWORD VALIDATION
     * -----------------------------------------
     */

    if ($newPassword === '') {

        $message =
            'Please enter a new password.';

        $messageType = 'error';

    } elseif (strlen($newPassword) < 8) {

        $message =
            'Password must be at least 8 characters.';

        $messageType = 'error';

    } elseif (!preg_match('/[A-Za-z]/', $newPassword)) {

        $message =
            'Password must contain at least one letter.';

        $messageType = 'error';

    } elseif (!preg_match('/[0-9]/', $newPassword)) {

        $message =
            'Password must contain at least one number.';

        $messageType = 'error';

    } elseif ($newPassword !== $confirmPassword) {

        $message =
            'Passwords do not match.';

        $messageType = 'error';

    } else {

        /*
         * -----------------------------------------
         * HASH NEW PASSWORD
         * -----------------------------------------
         */

        $passwordHash = password_hash(
            $newPassword,
            PASSWORD_DEFAULT
        );


        /*
         * -----------------------------------------
         * UPDATE PASSWORD + INVALIDATE TOKEN
         * -----------------------------------------
         */

        try {

            $pdo->beginTransaction();


            /*
             * Update user's password
             */

            $updateUser = $pdo->prepare(
                'UPDATE users
                 SET password_hash = :password
                 WHERE id = :user_id'
            );

            $updateUser->execute([
                ':password' => $passwordHash,
                ':user_id' => $user['user_id']
            ]);


            /*
             * Mark reset token as used
             */

            $updateReset = $pdo->prepare(
                'UPDATE password_resets
                 SET used_at = NOW()
                 WHERE id = :reset_id'
            );

            $updateReset->execute([
                ':reset_id' => $resetRecord['reset_id']
            ]);


            $pdo->commit();


            /*
             * Password successfully changed
             */

            header(
                'Location: login.php?reset=success'
            );

            exit;


        } catch (PDOException $e) {

            if ($pdo->inTransaction()) {

                $pdo->rollBack();
            }


            error_log(
                'Password reset error: '
                . $e->getMessage()
            );


            $message =
                'Something went wrong. Please try again.';

            $messageType = 'error';
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

    <title>
        Reset Password | MCO Portal
    </title>


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


    <!-- Reset Password CSS -->

    <link
        rel="stylesheet"
        href="assets/css/reset-password.css"
    >

</head>


<body>


<div class="page">


    <!-- =========================================
         LEFT BRANDING
    ========================================== -->

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


            <!-- HERO -->

            <div class="hero">

                <h1>

                    Create a

                    <span>
                        new password.
                    </span>

                </h1>


                <div class="green-line"></div>


                <p>

                    Choose a strong password to keep
                    your MCO Portal account secure.

                </p>

            </div>


        </div>


    </section>



    <!-- =========================================
         RIGHT RESET PASSWORD PANEL
    ========================================== -->

    <section class="login-panel">


        <div class="login-container">


            <div class="login-card">


                <?php if ($user): ?>


                    <!-- =================================
                         HEADER
                    ================================== -->

                    <div class="login-header">

                        <div class="eyebrow">
                            Account Recovery
                        </div>


                        <h2>
                            Reset Password
                        </h2>


                        <p>
                            Create a new password for your
                            MCO Portal account.
                        </p>

                    </div>



                    <!-- =================================
                         ERROR MESSAGE
                    ================================== -->

                    <?php if ($message !== ''): ?>

                        <div class="login-error">

                            <i class="bi bi-exclamation-circle"></i>

                            <span>
                                <?= htmlspecialchars($message) ?>
                            </span>

                        </div>

                    <?php endif; ?>



                    <!-- =================================
                         RESET FORM
                    ================================== -->

                    <form
                        method="POST"
                        action=""
                        id="resetPasswordForm"
                        novalidate
                    >


                        <!-- Keep reset token during POST -->

                        <input
                            type="hidden"
                            name="token"
                            value="<?= htmlspecialchars($token) ?>"
                        >



                        <!-- =================================
                             NEW PASSWORD
                        ================================== -->

                        <div class="form-group">

                            <label
                                for="password"
                                class="form-label"
                            >
                                New Password
                            </label>

                            <div class="input-wrapper">

                                <i class="bi bi-lock input-icon"></i>

                                <input
                                    type="password"
                                    id="password"
                                    name="password"
                                    class="form-control"
                                    placeholder="Create a new password"
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

                        <!-- =================================
                            CONFIRM PASSWORD
                        ================================== -->

                        <div class="form-group">

                            <label
                                for="confirm_password"
                                class="form-label"
                            >
                                Confirm Password
                            </label>

                            <div class="input-wrapper">

                                <i class="bi bi-lock-fill input-icon"></i>

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


                        <!-- =================================
                            RESET BUTTON
                        ================================== -->

                        <button
                            type="submit"
                            class="btn-signin"
                            id="submitButton"
                        >
                            Reset Password
                            <i class="bi bi-arrow-right"></i>
                        </button>


                    </form>



                    <!-- BACK TO LOGIN -->

                    <div class="register">

                        <a href="login.php">

                            <i class="bi bi-arrow-left"></i>

                            Back to Login

                        </a>

                    </div>



                <?php else: ?>


                    <!-- =================================
                         INVALID / EXPIRED LINK
                    ================================== -->

                    <div class="login-header">

                        <div class="eyebrow">
                            Account Recovery
                        </div>


                        <h2>
                            Link Unavailable
                        </h2>


                        <p>
                            This password reset link is invalid
                            or has expired.
                        </p>

                    </div>


                    <a
                        href="forgot-password.php"
                        class="btn-signin button-link"
                    >

                        Request New Reset Link

                    </a>


                    <div class="register">

                        <a href="login.php">

                            <i class="bi bi-arrow-left"></i>

                            Back to Login

                        </a>

                    </div>


                <?php endif; ?>


            </div>


        </div>


    </section>


</div>



<!-- Reset Password JavaScript -->

<script
    src="assets/js/reset-password.js"
></script>


</body>

</html>