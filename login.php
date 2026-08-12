<?php

session_start();

require_once __DIR__ . '/config/database.php';

$error = '';
$success = '';

if (isset($_GET['registered']) && $_GET['registered'] === '1') {

    $success =
        'Your account has been successfully created. You can now sign in.';

}

if (isset($_GET['reset']) && $_GET['reset'] === 'success') {

    $success =
        'Your password has been changed successfully. You can now sign in with your new password.';

}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = 'Please enter a valid email address.';

    } elseif ($password === '') {

        $error = 'Please enter your password.';

    } else {

        $stmt = $pdo->prepare("
            SELECT
                id,
                email,
                name,
                password_hash,
                google_id,
                status,
                email_verified_at
            FROM users
            WHERE email = ?
            LIMIT 1
        ");

        $stmt->execute([$email]);

        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {

            $error = 'Invalid email or password.';

            if (
                $user &&
                !empty($user['google_id'])
            ) {

                $error .= ' If you previously signed in with Google, you can continue with Google or reset your password.';

            }

        } elseif ($user['status'] !== 'active') {

            $error = 'Your account is not active.';

        } elseif ($user['email_verified_at'] === null) {

            $error = 'Please verify your email address first.';

        } else {

            session_regenerate_id(true);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_name'] = $user['name'];

            header('Location: dashboard.php');
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

    <title>PALECO MCO Portal</title>

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


    <!-- Login CSS -->
    <link
        rel="stylesheet"
        href="assets/css/login.css"
    >

</head>


<body>


<div class="page">


    <!-- =========================================
         LEFT BRANDING PANEL
    ========================================== -->

    <section class="branding-panel">


        <div class="brand-content">


            <!-- PALECO LOGO -->

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
                        KM 3.35, North National Highway, Barangay Tiniguiban, Puerto Princesa City
                    </div>

                </div>

            </div>


            <!-- HERO -->

            <div class="hero">

                <h1>

                    LET'S KEEP THE

                    <span>
                        LIGHTS ON!
                    </span>

                </h1>


                <div class="green-line"></div>


                <p>

                    Welcome to the PALECO
                    Member-Consumer Online Portal.

                    Manage your account, view your
                    bills, monitor your electricity
                    usage, and stay connected with PALECO.

                </p>

            </div>

        </div>


        <!-- FEATURES -->

        <div class="features">


            <div class="feature">

                <i class="bi bi-lightning-charge"></i>

                <strong>
                    Reliable Electricity
                </strong>

                <span>
                    Power for every home and family.
                </span>

            </div>


            <div class="feature">

                <i class="bi bi-people"></i>

                <strong>
                    Member Focused
                </strong>

                <span>
                    Services built around our consumers.
                </span>

            </div>


            <div class="feature">

                <i class="bi bi-tree"></i>

                <strong>
                    A Greener Palawan
                </strong>

                <span>
                    Building a better future together.
                </span>

            </div>


        </div>


    </section>



    <!-- =========================================
         RIGHT LOGIN PANEL
    ========================================== -->

    <main class="login-panel">


        <div class="login-container">


            <div class="login-card">


                <!-- HEADER -->

                <div class="login-header">

                    <div class="eyebrow">
                        Safe · Reliable · Member-Focused
                    </div>


                    <h2>
                        Welcome Back
                    </h2>


                    <p>
                        Sign in to your MCO Portal account.
                    </p>

                    <?php if ($success !== ''): ?>

                        <div class="login-success">
                            <i class="bi bi-check-circle"></i>

                            <span>
                                <?= htmlspecialchars($success) ?>
                            </span>
                        </div>

                    <?php endif; ?>

                    <?php if ($error !== ''): ?>

                        <div class="login-error" role="alert">

                            <i class="bi bi-exclamation-circle-fill"></i>

                            <span>
                                <?= htmlspecialchars($error) ?>
                            </span>

                        </div>

                    <?php endif; ?>

                </div>



                <!-- LOGIN FORM -->

                <form
                    action=""
                    method="POST"
                >


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
                                required
                            >

                        </div>

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
                                placeholder="Enter your password"
                                autocomplete="current-password"
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

                    </div>



                    <!-- OPTIONS -->

                    <div class="form-options">


                        <!-- <label class="remember">

                            <input
                                type="checkbox"
                                name="remember"
                            >

                            <span>
                                Remember me
                            </span>

                        </label> -->


                        <a
                            href="forgot-password.php"
                            class="forgot-password"
                        >
                            Forgot Password?
                        </a>


                    </div>



                    <!-- SIGN IN -->

                    <button
                        type="submit"
                        class="btn-signin"
                    >
                        Sign In
                    </button>


                </form>



                <!-- DIVIDER -->

                <div class="divider">
                    OR
                </div>



                <!-- GOOGLE LOGIN -->

                <button
                    type="button"
                    class="btn-google"
                    id="googleLogin"
                >


                    <svg
                        class="google-icon"
                        viewBox="0 0 24 24"
                        xmlns="http://www.w3.org/2000/svg"
                    >

                        <path
                            fill="#4285F4"
                            d="M21.35 12.27c0-.79-.07-1.54-.2-2.27H12v4.3h5.24a4.48 4.48 0 0 1-1.94 2.94v2.45h3.14c1.84-1.69 2.91-4.18 2.91-7.42z"
                        />

                        <path
                            fill="#34A853"
                            d="M12 21.5c2.63 0 4.84-.87 6.45-2.36l-3.14-2.45c-.87.58-1.98.92-3.31.92-2.54 0-4.69-1.72-5.46-4.03H3.3v2.53A9.74 9.74 0 0 0 12 21.5z"
                        />

                        <path
                            fill="#FBBC05"
                            d="M6.54 13.58A5.85 5.85 0 0 1 6.23 12c0-.55.11-1.08.31-1.58V7.89H3.3A9.76 9.76 0 0 0 2.25 12c0 1.57.38 3.05 1.05 4.11l3.24-2.53z"
                        />

                        <path
                            fill="#EA4335"
                            d="M12 6.39c1.43 0 2.72.49 3.73 1.45l2.8-2.8C16.84 3.48 14.63 2.5 12 2.5a9.74 9.74 0 0 0-8.7 5.39l3.24 2.53C7.31 8.11 9.46 6.39 12 6.39z"
                        />

                    </svg>


                    Continue with Google


                </button>



                <!-- REGISTER -->

                <div class="register">

                    Don't have an account?

                    <a href="register.php">
                        Register here
                    </a>

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



<!-- Login JavaScript -->
<script src="assets/js/login.js"></script>


</body>

</html>