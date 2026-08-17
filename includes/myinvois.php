<?php
/* LHDN MyInvois OAuth 2.0 helper — pure cURL, JSON parsed */

function myinvois_base_url(array $user): string {
    $env = $user['ei_env'] ?? 'sandbox';
    return $env === 'prod'
        ? (($user['ei_url_prod'] ?? '') ?: 'https://api.myinvois.hasil.gov.my')
        : (($user['ei_url_sandbox'] ?? '') ?: 'https://preprod-api.myinvois.hasil.gov.my');
}

function myinvois_request_token(PDO $pdo, string $userId): array {
    $u = $pdo->prepare("SELECT * FROM users WHERE id = ?"); $u->execute([$userId]);
    $user = $u->fetch();
    if (!$user) return ['ok' => false, 'error' => 'User not found.'];

    $c = $pdo->prepare("SELECT * FROM companies WHERE user_id = ?"); $c->execute([$userId]);
    $co = $c->fetch();
    if (!$co) return ['ok' => false, 'error' => 'Company profile not found — save it first.'];

    $env      = $user['ei_env'] ?? 'sandbox';
    $clientId = $env === 'prod' ? ($co['prod_clientid'] ?? '') : ($co['sandbox_clientid'] ?? '');
    $secrets  = $env === 'prod'
        ? array_filter([$co['prod_secret1'] ?? '', $co['prod_secret2'] ?? ''])
        : array_filter([$co['sandbox_secret1'] ?? '', $co['sandbox_secret2'] ?? '']);

    if ($clientId === '' || !$secrets)
        return ['ok' => false, 'error' => 'Missing Client ID / Secret for ' . ($env === 'prod' ? 'Production' : 'Sandbox') . '. Save the e-Invoice config first.'];

    $base    = rtrim(myinvois_base_url($user), '/');
    $lastErr = '';

    // Try secret 1 first, fall back to secret 2 automatically
    foreach ($secrets as $secret) {
        $ch = curl_init($base . '/connect/token');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
            // ✅ FIX: LHDN requires client_id, client_secret, grant_type, AND scope IN THE POST BODY
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type'    => 'client_credentials',
                'client_id'     => $clientId,
                'client_secret' => $secret,
                'scope'         => 'InvoicingAPI',  // ← Mandatory for LHDN
            ]),
        ]);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($resp === false) { $lastErr = 'cURL: ' . $cerr; continue; }

        $json = json_decode($resp, true);
        
        // ✅ Success
        if (is_array($json) && isset($json['access_token'])) {
            $pdo->prepare("UPDATE users SET ei_token = ?, ei_token_at = NOW() WHERE id = ?")
                ->execute([$json['access_token'], $userId]);
            return [
                'ok'         => true,
                'token'      => $json['access_token'],
                'expires_in' => $json['expires_in'] ?? null,
                'env'        => $env,
                'url'        => $base,
            ];
        }
        
        // ✅ Better error extraction for debugging
        if (is_array($json)) {
            $lastErr = $json['error_description'] ?? $json['error'] ?? ('HTTP ' . $http);
        } else {
            $lastErr = 'HTTP ' . $http . ' — ' . substr((string)$resp, 0, 150);
        }
    }

    return ['ok' => false, 'error' => 'Token request failed: ' . $lastErr];
}
