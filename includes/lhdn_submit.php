<?php
/**
 * LHDN MyInvois API submission helper
 */

function submitToLHDN(PDO $pdo, string $userId, array $invoiceData): array {
    require_once __DIR__ . '/myinvois.php';
    
    // Get user and company data
    $u = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $u->execute([$userId]);
    $user = $u->fetch();
    
    $c = $pdo->prepare("SELECT * FROM companies WHERE user_id = ?");
    $c->execute([$userId]);
    $company = $c->fetch();
    
    if (!$user || !$company) {
        return ['success' => false, 'error' => 'User or company not found'];
    }
    
    // Check if token exists
    if (empty($user['ei_token'])) {
        return ['success' => false, 'error' => 'No access token. Please generate token in Company settings.'];
    }
    
    $baseUrl = myinvois_base_url($user);
    $token = $user['ei_token'];
    
    // Build LHDN invoice payload
    $payload = [
        'documents' => [
            [
                'code' => $invoiceData['document_type'] ?? '01',
                'issuerTIN' => $company['taxpayer_tin'] ?? '',
                'issuerBRN' => $company['taxpayer_brn'] ?? '',
                'supplierName' => $company['name'] ?? '',
                'supplierAddress' => $company['address'] ?? '',
                'supplierCity' => $company['town'] ?? '',
                'supplierState' => $company['state'] ?? '',
                'supplierPostcode' => $company['postcode'] ?? '',
                'supplierMSICCode' => $company['msic_code'] ?? '',
                'buyerTIN' => $invoiceData['customer_ic'] ?? '',
                'buyerName' => $invoiceData['customer_name'] ?? '',
                'buyerAddress' => $invoiceData['customer_address'] ?? '',
                'buyerCity' => '',
                'buyerState' => '',
                'buyerPostcode' => $invoiceData['customer_postcode'] ?? '',
                'buyerEmail' => $invoiceData['customer_email'] ?? '',
                'buyerContact' => $invoiceData['customer_phone'] ?? '',
                'issueDateTime' => date('Y-m-d\TH:i:s\Z', strtotime($invoiceData['sale_datetime'] ?? 'now')),
                'currency' => 'MYR',
                'totalExcludingTax' => (float)$invoiceData['sale_amount'],
                'totalTax' => (float)$invoiceData['sale_tax'],
                'totalIncludingTax' => (float)$invoiceData['total_amount'],
                'lineItems' => [
                    [
                        'classificationCode' => $company['classification_code'] ?? '022',
                        'description' => $invoiceData['sale_title'] ?? 'Service',
                        'unitPrice' => (float)$invoiceData['sale_amount'],
                        'quantity' => 1,
                        'totalExcludingTax' => (float)$invoiceData['sale_amount'],
                        'taxRate' => 8.0,
                        'taxAmount' => (float)$invoiceData['sale_tax'],
                        'totalIncludingTax' => (float)$invoiceData['total_amount']
                    ]
                ]
            ]
        ]
    ];
    
    // Submit to LHDN
    $ch = curl_init($baseUrl . '/api/v1.0/documentsubmissions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 30,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($response === false) {
        return ['success' => false, 'error' => 'cURL error: ' . $error];
    }
    
    $json = json_decode($response, true);
    
    return [
        'success' => $httpCode >= 200 && $httpCode < 300,
        'http_code' => $httpCode,
        'response' => $json,
        'raw_response' => $response,
        'error' => $httpCode >= 400 ? ($json['message'] ?? 'API error') : null
    ];
}
