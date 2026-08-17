<?php
/* Google OAuth 2.0 helper — zero dependencies, pure cURL */

define('GOOGLE_CLIENT_ID',     '1024820598270-f1qk2htlpdf0lq772ufldnen6fcilsn8.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', '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');

function google_redirect_uri(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . '/public/google-auth.php';
}

function google_auth_url(): string {
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;
    $params = [
        'client_id'     => GOOGLE_CLIENT_ID,
        'redirect_uri'  => google_redirect_uri(),
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $state,
        'prompt'        => 'select_account',
        'access_type'   => 'online',
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

function google_exchange_code(string $code): ?array {
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'code'          => $code,
            'client_id'     => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'redirect_uri'  => google_redirect_uri(),
            'grant_type'    => 'authorization_code',
        ]),
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    if (!$resp) return null;
    $data = json_decode($resp, true);
    return isset($data['access_token']) ? $data : null;
}

function google_userinfo(string $access_token): ?array {
    $ch = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $access_token],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    if (!$resp) return null;
    $data = json_decode($resp, true);
    return (isset($data['email']) && $data['verified_email'] ?? false) ? $data : null;
}
