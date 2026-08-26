<?php
/* LHDN MyInvois OAuth 2.0 helper — pure cURL, JSON parsed */

function myinvois_base_url(array $user): string {
    $env = $user['ei_env'] ?? 'sandbox';
    return $env === 'prod'
        ? (($user['ei_url_prod'] ?? '') ?: 'https://api.myinvois.hasil.gov.my')
        : (($user['ei_url_sandbox'] ?? '') ?: 'https://preprod-api.myinvois.hasil.gov.my');
}

function myinvois_request_token(PDO $pdo, string $userId): array {
    // 1. Fetch user data to determine environment and base URL
    $u = $pdo->prepare("SELECT * FROM users WHERE id = ?"); 
    $u->execute([$userId]);
    $user = $u->fetch();
    if (!$user) {
        return ['ok' => false, 'error' => 'User not found.'];
    }

    // 2. Fetch company data for credentials and token storage
    $c = $pdo->prepare("SELECT * FROM companies WHERE user_id = ?"); 
    $c->execute([$userId]);
    $co = $c->fetch();
    if (!$co) {
        return ['ok' => false, 'error' => 'Company profile not found — save it first.'];
    }

    $env      = $user['ei_env'] ?? 'sandbox';
    $clientId = $env === 'prod' ? ($co['prod_clientid'] ?? '') : ($co['sandbox_clientid'] ?? '');
    
    // Try secret 1, then secret 2 if available
    $secrets  = $env === 'prod'
        ? array_filter([$co['prod_secret1'] ?? '', $co['prod_secret2'] ?? ''])
        : array_filter([$co['sandbox_secret1'] ?? '', $co['sandbox_secret2'] ?? '']);

    if ($clientId === '' || empty($secrets)) {
        return ['ok' => false, 'error' => 'Missing Client ID / Secret for ' . ($env === 'prod' ? 'Production' : 'Sandbox') . '. Save the e-Invoice config first.'];
    }

    $base    = rtrim(myinvois_base_url($user), '/');
    $lastErr = '';

    // 3. Try each secret until one works
    foreach ($secrets as $secret) {
        $ch = curl_init($base . '/connect/token');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
            // ✅ LHDN requires client_id, client_secret, grant_type, AND scope IN THE POST BODY
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type'    => 'client_credentials',
                'client_id'     => $clientId,
                'client_secret' => $secret,
                'scope'         => 'InvoicingAPI', // Mandatory for LHDN
            ]),
        ]);
        
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($resp === false) { 
            $lastErr = 'cURL Error: ' . $cerr; 
            continue; 
        }

        $json = json_decode($resp, true);
        
        // 4. ✅ Success: Save token to the `companies` table (matching your schema)
        if (is_array($json) && isset($json['access_token'])) {
            $expiresIn = (int)($json['expires_in'] ?? 3600);
            // Subtract 60 seconds as a safety buffer before actual expiry
            $expiryTime = date('Y-m-d H:i:s', time() + $expiresIn - 60);

            if ($env === 'prod') {
                $pdo->prepare("UPDATE companies SET 
                    prod_token = ?, 
                    prod_token_expiry = ?, 
                    updated_at = NOW() 
                    WHERE user_id = ?")
                    ->execute([$json['access_token'], $expiryTime, $userId]);
            } else {
                $pdo->prepare("UPDATE companies SET 
                    sandbox_token = ?, 
                    sandbox_token_expiry = ?, 
                    updated_at = NOW() 
                    WHERE user_id = ?")
                    ->execute([$json['access_token'], $expiryTime, $userId]);
            }

            return [
                'ok'         => true,
                'token'      => $json['access_token'],
                'expires_in' => $expiresIn,
                'expiry'     => $expiryTime,
                'env'        => $env,
                'url'        => $base,
            ];
        }
        
        // 5. ✅ Better error extraction for debugging
        if (is_array($json)) {
            $lastErr = $json['error_description'] ?? $json['error'] ?? ('HTTP ' . $http);
        } else {
            $lastErr = 'HTTP ' . $http . ' — ' . substr((string)$resp, 0, 150);
        }
    }

    return ['ok' => false, 'error' => 'Token request failed: ' . $lastErr];
}
