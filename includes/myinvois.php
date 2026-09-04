<?php
/* LHDN MyInvois OAuth 2.0 & Submission helper — pure cURL, JSON parsed */

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
        
        // 4. ✅ Success: Save token to the `companies` table
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

/**
 * ✅ NEW: Submit document to LHDN and automatically log the sent JSON payload.
 * 
 * @param PDO $pdo Database connection
 * @param string $userId The user ID
 * @param string $recordId The einvoice_records ID
 * @param array $payload The JSON payload array to send to LHDN
 * @return array Result array with 'ok', 'http_code', 'response', and 'status'
 */
function myinvois_submit_document(PDO $pdo, string $userId, string $recordId, array $payload): array {
    // 1. Get or Refresh Token
    $tokenRes = myinvois_request_token($pdo, $userId);
    if (!$tokenRes['ok']) {
        // Log failure without payload if token fails
        $updateStmt = $pdo->prepare("UPDATE einvoice_records SET lhdn_status = 'error', lhdn_response = ? WHERE id = ? AND user_id = ?");
        $updateStmt->execute([json_encode(['error' => $tokenRes['error']]), $recordId, $userId]);
        return ['ok' => false, 'error' => $tokenRes['error']];
    }

    $token = $tokenRes['token'];
    $base  = $tokenRes['url'];

    // 2. Prepare cURL for Document Submission
    $ch = curl_init($base . '/api/v1.0/documents');
    
    // Ensure payload is a clean JSON string (this is what we will save to lhdn_jsonsend)
    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            'Accept: application/json'
        ],
        CURLOPT_POSTFIELDS     => $jsonPayload,
    ]);

    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    // 3. Handle Network Errors
    if ($resp === false) {
        $updateStmt = $pdo->prepare("UPDATE einvoice_records SET lhdn_jsonsend = ?, lhdn_response = ?, lhdn_status = 'error' WHERE id = ? AND user_id = ?");
        $updateStmt->execute([$jsonPayload, json_encode(['error' => $cerr]), $recordId, $userId]);
        return ['ok' => false, 'error' => 'cURL Network Error: ' . $cerr];
    }

    $jsonResp = json_decode($resp, true);

    // 4. Parse LHDN Response Status & IDs
    $status = 'pending';
    $lhdnUuid = null;
    $lhdnSubmissionId = null;
    $lhdnLongId = null;

    if (is_array($jsonResp)) {
        $rawStatus = strtolower($jsonResp['status'] ?? $jsonResp['documentStatus'] ?? $jsonResp['validationStatus'] ?? 'pending');
        
        if (in_array($rawStatus, ['valid', 'validated', 'success', 'approved'])) {
            $status = 'valid';
        } elseif (in_array($rawStatus, ['invalid', 'rejected', 'fail', 'failed', 'error'])) {
            $status = 'invalid';
        } else {
            $status = 'in_progress';
        }

        $lhdnUuid = $jsonResp['uuid'] ?? $jsonResp['documentUuid'] ?? null;
        $lhdnSubmissionId = $jsonResp['submissionId'] ?? $jsonResp['submissionUid'] ?? null;
        $lhdnLongId = $jsonResp['longId'] ?? $jsonResp['invoiceLongId'] ?? null;
    } else {
        $jsonResp = ['http_code' => $http, 'raw_response' => $resp];
        $status = 'error';
    }

    // 5. ✅ Update Database: Save BOTH the sent payload and the response
    $updateStmt = $pdo->prepare("
        UPDATE einvoice_records 
        SET lhdn_jsonsend = ?, 
            lhdn_response = ?, 
            lhdn_status = ?,
            lhdn_uuid = COALESCE(NULLIF(?, ''), lhdn_uuid),
            lhdn_submission_id = COALESCE(NULLIF(?, ''), lhdn_submission_id),
            lhdn_long_id = COALESCE(NULLIF(?, ''), lhdn_long_id)
        WHERE id = ? AND user_id = ?
    ");
    
    $updateStmt->execute([
        $jsonPayload,
        json_encode($jsonResp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $status,
        $lhdnUuid,
        $lhdnSubmissionId,
        $lhdnLongId,
        $recordId,
        $userId
    ]);

    return [
        'ok'          => ($http >= 200 && $http < 300),
        'http_code'   => $http,
        'response'    => $jsonResp,
        'status'      => $status,
        'lhdn_uuid'   => $lhdnUuid
    ];
}
