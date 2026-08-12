<?php

session_start();

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/google.php';

// Create Google Client
$client = new Google\Client();

$client->setClientId(GOOGLE_CLIENT_ID);
$client->setClientSecret(GOOGLE_CLIENT_SECRET);
$client->setRedirectUri(GOOGLE_REDIRECT_URI);

// Request user's basic profile and email
$client->addScope('email');
$client->addScope('profile');

// Ask Google to show the account selection screen
$client->setPrompt('select_account');

// Generate Google login URL
$authUrl = $client->createAuthUrl();

// Redirect user to Google
header('Location: ' . $authUrl);
exit;