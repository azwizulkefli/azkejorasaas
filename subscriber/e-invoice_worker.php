<?php
// Background Worker for E-Invoice Queue Processing
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/lhdn_submit.php';

// Prevent multiple concurrent workers
$lockFile = sys_get_temp_dir() . '/einvoice_worker.lock';
$fp = fopen($lockFile, 'w');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    exit("Worker already running.\n");
}

$pdo = getDbConnection(); // Ensure this function is available from your auth/db include

function logAction($pdo, $userId, $submissionId, $action, $message, $response = null) {
    $stmt = $pdo->prepare("INSERT INTO einvoice_logs (user_id, submission_id, action, message, response) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$userId, $submissionId, $action, $message, $response ? json_encode($response) : null]);
}

while (true) {
    $stmt = $pdo->query("SELECT * FROM einvoice_queue WHERE status = 'pending' ORDER BY created_at ASC LIMIT 1");
    $job = $stmt->fetch();
    
    if (!$job) {
        flock($fp, LOCK_UN); fclose($fp); unlink($lockFile);
        exit("No pending jobs. Worker stopped.\n");
    }
    
    // Mark as processing
    $pdo->prepare("UPDATE einvoice_queue SET status = 'processing', attempts = attempts + 1 WHERE id = ?")->execute([$job['id']]);
    
    try {
        // 1. Fetch Company & Verify Token Expiry
        $cStmt = $pdo->prepare("SELECT * FROM companies WHERE user_id = ?");
        $cStmt->execute([$job['user_id']]); 
        $company = $cStmt->fetch();
        
        $isSandbox = true; // Adjust based on environment logic
        $tokenExpiry = $isSandbox ? ($company['sandbox_token_expiry'] ?? null) : ($company['prod_token_expiry'] ?? null);
        
        if (!$tokenExpiry || strtotime($tokenExpiry) <= time()) {
            throw new Exception("LHDN Token Expired. Halting queue. User must refresh token.");
        }

        $payload = json_decode($job['payload'], true);
        
        // 2. Submit Document to LHDN
        logAction($pdo, $job['user_id'], $job['submission_id'], 'SUBMIT_DOCUMENT', 'Sending payload to LHDN API');
        
        // Assume submitDocumentToLHDN handles the cURL request and returns array
        $submitResponse = submitDocumentToLHDN($payload, $company); 
        
        $lhdnUuid = $submitResponse['submissionUUID'] ?? null;
        $longId   = $submitResponse['longId'] ?? null; // LHDN Long ID for status tracking
        
        if (isset($submitResponse['error'])) {
            throw new Exception("LHDN API Error: " . ($submitResponse['error']['message'] ?? 'Unknown'));
        }

        $pdo->prepare("UPDATE einvoice_submissions SET lhdn_uuid = ?, long_id = ?, api_response = ?, status = 'submitted' WHERE id = ?")
            ->execute([$lhdnUuid, $longId, json_encode($submitResponse), $job['submission_id']]);
            
        logAction($pdo, $job['user_id'], $job['submission_id'], 'SUBMIT_SUCCESS', 'Document accepted by LHDN', $submitResponse);
        
        // 3. Timer: Wait 5 Seconds before checking status
        sleep(5);
        
        // 4. Get Document Status from LHDN
        logAction($pdo, $job['user_id'], $job['submission_id'], 'GET_STATUS', "Fetching status for Long ID: $longId");
        
        // Assume getDocumentStatusFromLHDN handles the cURL request
        $statusResponse = getDocumentStatusFromLHDN($longId, $company); 
        
        $docStatus = $statusResponse['status'] ?? 'Unknown';
        
        $pdo->prepare("UPDATE einvoice_submissions SET document_status = ?, document_status_response = ? WHERE id = ?")
            ->execute([$docStatus, json_encode($statusResponse), $job['submission_id']]);
            
        logAction($pdo, $job['user_id'], $job['submission_id'], 'STATUS_SUCCESS', "Document Status: $docStatus", $statusResponse);
        
        // Mark queue job as completed
        $pdo->prepare("UPDATE einvoice_queue SET status = 'completed', processed_at = NOW() WHERE id = ?")->execute([$job['id']]);
        
        // Update original record status
        if ($job['record_id']) {
            $pdo->prepare("UPDATE einvoice_records SET submission_status = ? WHERE id = ?")->execute([strtolower($docStatus), $job['record_id']]);
        }
        
        // 5. Timer: Wait 5 Seconds before processing next record
        sleep(5);
        
    } catch (Exception $e) {
        $errMsg = $e->getMessage();
        $pdo->prepare("UPDATE einvoice_queue SET status = 'failed', last_error = ? WHERE id = ?")->execute([$errMsg, $job['id']]);
        $pdo->prepare("UPDATE einvoice_submissions SET status = 'failed', error_message = ? WHERE id = ?")->execute([$errMsg, $job['submission_id']]);
        
        logAction($pdo, $job['user_id'], $job['submission_id'], 'ERROR', $errMsg);
        
        // Wait 5 seconds before moving to the next job to prevent rapid failure loops
        sleep(5); 
    }
}
