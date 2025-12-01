<?php

require_once __DIR__ . '/../vendor/autoload.php';
use Auth0\SDK\Auth0;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// Initialize Auth0 SDK
$auth0 = new Auth0([
    'domain' => $_ENV['AUTH0_DOMAIN'],
    'clientId' => $_ENV['AUTH0_CLIENT_ID'],
    'clientSecret' => $_ENV['AUTH0_CLIENT_SECRET'],
    'redirectUri' => $_ENV['AUTH0_REDIRECT_URI'],
    'cookieSecret' => $_ENV['AUTH0_COOKIE_SECRET'],
]);

// Generate login URL for Google
$loginUrl = $auth0->login(null, [
    'connection' => 'google-oauth2', // force Google login
]);

// Redirect user to Auth0 login page
header('Location: ' . $loginUrl);
exit;
