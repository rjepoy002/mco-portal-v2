<?php

session_start();

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/google.php';
require_once __DIR__ . '/config/database.php';

// =========================================
// GOOGLE CLIENT
// =========================================

$client = new Google\Client();

$client->setClientId(GOOGLE_CLIENT_ID);
$client->setClientSecret(GOOGLE_CLIENT_SECRET);
$client->setRedirectUri(GOOGLE_REDIRECT_URI);

// =========================================
// CHECK GOOGLE RESPONSE
// =========================================

if (!isset($_GET['code'])) {
    die('Google authentication was cancelled or failed.');
}

try {

    // Exchange authorization code for access token
    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);

    if (isset($token['error'])) {
        die('Google authentication failed.');
    }

    $client->setAccessToken($token['access_token']);

    // =========================================
    // GET GOOGLE USER INFORMATION
    // =========================================

    $googleService = new Google\Service\Oauth2($client);

    $userInfo = $googleService->userinfo->get();

    $googleId = $userInfo->id;
    $email    = strtolower(trim($userInfo->email));
    $name     = trim($userInfo->name);

    // =========================================
    // CHECK IF USER EXISTS
    // =========================================

    $stmt = $pdo->prepare("
        SELECT *
        FROM users
        WHERE email = :email
        LIMIT 1
    ");

    $stmt->execute([
        'email' => $email
    ]);

    $user = $stmt->fetch();

    // =========================================
    // EXISTING USER
    // =========================================

    if ($user) {

        // If Google ID is not connected yet,
        // connect this Google account.
        if (empty($user['google_id'])) {

            $stmt = $pdo->prepare("
                UPDATE users
                SET google_id = :google_id,
                    email_verified_at = COALESCE(
                        email_verified_at,
                        NOW()
                    ),
                    updated_at = NOW()
                WHERE id = :id
            ");

            $stmt->execute([
                'google_id' => $googleId,
                'id'        => $user['id']
            ]);

        } elseif ($user['google_id'] !== $googleId) {

            // The email belongs to another Google account.
            die('This email address is already connected to another Google account.');

        }

    }

    // =========================================
    // NEW USER
    // =========================================

    else {

        // Generate a random password hash.
        // Google users do not need to know this password.
        $randomPassword = bin2hex(random_bytes(32));

        $passwordHash = password_hash(
            $randomPassword,
            PASSWORD_DEFAULT
        );

        $stmt = $pdo->prepare("
            INSERT INTO users (
                email,
                google_id,
                name,
                password_hash,
                email_verified_at,
                status
            )
            VALUES (
                :email,
                :google_id,
                :name,
                :password_hash,
                NOW(),
                'active'
            )
        ");

        $stmt->execute([
            'email'        => $email,
            'google_id'    => $googleId,
            'name'         => $name,
            'password_hash'=> $passwordHash
        ]);

        $userId = $pdo->lastInsertId();

        // Get the newly created user
        $stmt = $pdo->prepare("
            SELECT *
            FROM users
            WHERE id = :id
            LIMIT 1
        ");

        $stmt->execute([
            'id' => $userId
        ]);

        $user = $stmt->fetch();
    }

    // =========================================
    // CHECK ACCOUNT STATUS
    // =========================================

    if ($user['status'] !== 'active') {

        die('Your account is currently unavailable.');
    }

    // =========================================
    // CREATE LOGIN SESSION
    // =========================================

    session_regenerate_id(true);

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_name'] = $user['name'];
    unset($_SESSION['SelectedAcctNo']);
    $_SESSION['logged_in'] = true;

    // =========================================
    // REDIRECT AFTER LOGIN
    // =========================================

    header('Location: dashboard.php');
    exit;

} catch (Exception $e) {

    error_log(
        'Google Login Error: ' . $e->getMessage()
    );

    die('Unable to complete Google login. Please try again.');
}
