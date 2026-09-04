<?php

$clientId     = '64afafbc-b38e-4038-b88a-e5ee5a420d29';
$clientSecret = '2e77367c-0535-41a9-a287-bda880d08891';

$icno = '791129105369';

// Sandbox
$baseUrl = 'https://preprod-api.myinvois.hasil.gov.my';

// --------------------------------------------------
// 1. Get Access Token
// --------------------------------------------------

$ch = curl_init($baseUrl . '/connect/token');

curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/x-www-form-urlencoded'
    ],
    CURLOPT_POSTFIELDS => http_build_query([
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'grant_type'    => 'client_credentials',
        'scope'         => 'InvoicingAPI'
    ])
]);

$response = curl_exec($ch);

if ($response === false) {
    die('Token Error: ' . curl_error($ch));
}

curl_close($ch);

$tokenData = json_decode($response, true);

if (!isset($tokenData['access_token'])) {
    die('Unable to get access token: ' . $response);
}

$accessToken = $tokenData['access_token'];


// --------------------------------------------------
// 2. Search TIN using IC / NRIC
// --------------------------------------------------

$url = $baseUrl . '/api/v1.0/taxpayer/search/tin?' . http_build_query([
    'idType'  => 'NRIC',
    'idValue' => $icno
]);

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json'
    ]
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($response === false) {
    die('Search Error: ' . curl_error($ch));
}

curl_close($ch);


// --------------------------------------------------
// 3. Display result
// --------------------------------------------------

echo "HTTP Code: " . $httpCode . "<br>";
echo "<pre>";
print_r(json_decode($response, true));
echo "</pre>";

?>
