<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/excel_reader.php';
require_once __DIR__ . '/../includes/lhdn_submit.php';
requireCustomer();
ensure_settings_table($pdo);
$uid = currentUserId();
$me  = currentUser();

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['invoice_file'])) {
    $uploadDir = __DIR__ . '/../storage/uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    
    $file = $_FILES['invoice_file'];
    if ($file['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['csv', 'xlsx', 'xls'];
        
        if (in_array($ext, $allowed)) {
            $filename = $uid . '_' . time() . '.' . $ext;
            $filePath = $uploadDir . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $filePath)) {
                // Parse file
                $result = parseInvoiceFile($filePath);
                
                if ($result['success']) {
                    // Create upload record
                    $pdo->prepare("INSERT INTO einvoice_uploads (user_id, filename, file_path, total_records, status)
                                   VALUES (?, ?, ?, ?, 'processing') RETURNING id")
                        ->execute([$uid, $file['name'], $filename, count($result['rows'])]);
                    $uploadId = $pdo->lastInsertId();
                    
                    // Validate and insert records
                    $validCount = 0;
                    $invalidCount = 0;
                    
                    foreach ($result['rows'] as $row) {
                        $validation = validateInvoiceRecord($row);
                        $status = $validation['valid'] ? 'valid' : 'invalid';
                        $errors = $validation['valid'] ? null : json_encode($validation['errors']);
                        
                        $pdo->prepare("INSERT INTO einvoice_records 
                            (upload_id, user_id, document_type, sale_no, customer_name, customer_address,
                             customer_postcode, customer_phone, customer_email, customer_ic, customer_type,
                             sale_title, sale_amount, sale_tax, total_amount, sale_datetime,
                             validation_status, validation_errors)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                            ->execute([
                                $uploadId, $uid,
                                $row['document_type'] ?? '01',
                                $row['sale_no'] ?? '',
                                $row['customer_name'] ?? '',
                                $row['customer_address'] ?? '',
                                $row['customer_postcode'] ?? '',
                                $row['customer_phone'] ?? '',
                                $row['customer_email'] ?? '',
                                $row['customer_ic'] ?? '',
                                $row['customer_type'] ?? 'general',
                                $row['sale_title'] ?? '',
                                (float)($row['sale_amount'] ?? 0),
                                (float)($row['sale_tax'] ?? 0),
                                (float)($row['total_amount'] ?? 0),
                                $row['sale_datetime'] ?? date('Y-m-d H:i:s'),
                                $status,
                                $errors
                            ]);
                        
                        if ($validation['valid']) $validCount++;
                        else $invalidCount++;
                    }
                    
                    // Update upload status
                    $pdo->prepare("UPDATE einvoice_uploads SET valid_records = ?, invalid_records = ?, status = 'completed' WHERE id = ?")
                        ->execute([$validCount, $invalidCount, $uploadId]);
                    
                    header("Location: e-invoice_upload.php?upload=" . $uploadId);
                    exit;
                } else {
                    header("Location: e-invoice_upload.php?err=" . urlencode($result['error']));
                    exit;
                }
            }
        } else {
            header("Location: e-invoice_upload.php?err=" . urlencode('Invalid file format. Please upload CSV or Excel.'));
            exit;
        }
    }
}

// Handle submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'submit_individual') {
        $recordIds = $_POST['record_ids'] ?? [];
        $submitted = 0;
        $failed = 0;
        
        foreach ($recordIds as $recordId) {
            // Get record
            $stmt = $pdo->prepare("SELECT * FROM einvoice_records WHERE id = ? AND user_id = ?");
            $stmt->execute([$recordId, $uid]);
            $record = $stmt->fetch();
            
            if ($record) {
                $result = submitToLHDN($pdo, $uid, $record);
                
                // Log submission
                $pdo->prepare("INSERT INTO einvoice_submissions 
                    (user_id, record_id, submission_type, lhdn_uuid, submission_datetime, status, api_response, error_message)
                    VALUES (?, ?, 'individual', ?, NOW(), ?, ?, ?)")
                    ->execute([
                        $uid,
                        $recordId,
                        $result['response']['submissionUUID'] ?? null,
                        $result['success'] ? 'submitted' : 'failed',
                        json_encode($result['response'] ?? []),
                        $result['error'] ?? null
                    ]);
                
                // Update record status
                $pdo->prepare("UPDATE einvoice_records SET submission_status = ? WHERE id = ?")
                    ->execute([$result['success'] ? 'submitted' : 'failed', $recordId]);
                
                if ($result['success']) $submitted++;
                else $failed++;
            }
        }
        
        header("Location: e-invoice_upload.php?submitted=individual&count=$submitted&failed=$failed");
        exit;
    }
    
    if ($_POST['action'] === 'submit_consolidated') {
        $date = $_POST['consolidate_date'] ?? date('Y-m-d');
        
        // Get all valid records for this date
        $stmt = $pdo->prepare("SELECT * FROM einvoice_records 
            WHERE user_id = ? AND DATE(sale_datetime) = ? AND validation_status = 'valid' AND submission_status = 'pending'");
        $stmt->execute([$uid, $date]);
        $records = $stmt->fetchAll();
        
        if (empty($records)) {
            header("Location: e-invoice_upload.php?err=" . urlencode('No valid records for this date'));
            exit;
        }
        
        // Calculate totals
        $totalAmount = array_sum(array_column($records, 'sale_amount'));
        $totalTax = array_sum(array_column($records, 'sale_tax'));
        $grandTotal = array_sum(array_column($records, 'total_amount'));
        
        // Create consolidated record
        $pdo->prepare("INSERT INTO einvoice_consolidated 
            (user_id, sale_date, total_records, total_amount, total_tax, grand_total, submission_status)
            VALUES (?, ?, ?, ?, ?, ?, 'pending') RETURNING id")
            ->execute([$uid, $date, count($records), $totalAmount, $totalTax, $grandTotal]);
        $consolidatedId = $pdo->lastInsertId();
        
        // Submit consolidated invoice
        $consolidatedData = [
            'document_type' => '01',
            'sale_no' => 'CONSOL-' . $date . '-' . substr($uid, 0, 8),
            'customer_name' => 'Consolidated Sales',
            'customer_address' => 'Multiple Customers',
            'customer_postcode' => '',
            'customer_phone' => '',
            'customer_email' => $me['email'],
            'customer_ic' => '',
            'customer_type' => 'general',
            'sale_title' => 'Consolidated Daily Sales - ' . $date,
            'sale_amount' => $totalAmount,
            'sale_tax' => $totalTax,
            'total_amount' => $grandTotal,
            'sale_datetime' => $date . ' 23:59:59'
        ];
        
        $result = submitToLHDN($pdo, $uid, $consolidatedData);
        
        // Log submission
        $pdo->prepare("INSERT INTO einvoice_submissions 
            (user_id, consolidated_id, submission_type, lhdn_uuid, submission_datetime, status, api_response, error_message)
            VALUES (?, ?, 'consolidated', ?, NOW(), ?, ?, ?)")
            ->execute([
                $uid,
                $consolidatedId,
                $result['response']['submissionUUID'] ?? null,
                $result['success'] ? 'submitted' : 'failed',
                json_encode($result['response'] ?? []),
                $result['error'] ?? null
            ]);
        
        // Update consolidated status
        $pdo->prepare("UPDATE einvoice_consolidated SET submission_status = ? WHERE id = ?")
            ->execute([$result['success'] ? 'submitted' : 'failed', $consolidatedId]);
        
        // Mark all records as submitted
        foreach ($records as $record) {
            $pdo->prepare("UPDATE einvoice_records SET submission_status = 'consolidated' WHERE id = ?")
                ->execute([$record['id']]);
        }
        
        header("Location: e-invoice_upload.php?submitted=consolidated&status=" . ($result['success'] ? 'success' : 'failed'));
        exit;
    }
}

// Load data
$uploads = $pdo->prepare("SELECT * FROM einvoice_uploads WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$uploads->execute([$uid]);
$uploadsList = $uploads->fetchAll();

$currentUpload = null;
$records = [];
if (isset($_GET['upload'])) {
    $stmt = $pdo->prepare("SELECT * FROM einvoice_uploads WHERE id = ? AND user_id = ?");
    $stmt->execute([$_GET['upload'], $uid]);
    $currentUpload = $stmt->fetch();
    
    if ($currentUpload) {
        $stmt = $pdo->prepare("SELECT * FROM einvoice_records WHERE upload_id = ? ORDER BY created_at");
        $stmt->execute([$_GET['upload']]);
        $records = $stmt->fetchAll();
    }
}

// Submission history
$submissions = $pdo->prepare("SELECT s.*, r.sale_no, r.customer_name, c.sale_date
    FROM einvoice_submissions s
    LEFT JOIN einvoice_records r ON s.record_id = r.id
    LEFT JOIN einvoice_consolidated c ON s.consolidated_id = c.id
    WHERE s.user_id = ?
    ORDER BY s.created_at DESC LIMIT 20");
$submissions->execute([$uid]);
$submissionsList = $submissions->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Upload E-Invoice — AZ Kejora SaaS</title>
<style>
:root{--ink:#131327;--bg:#F6F7FB;--brand:#5457e5;--violet:#8b5cf6;--muted:#64748b;--faint:#94a3b8;--line:#e2e8f0;--grad:linear-gradient(90deg,var(--brand),var(--violet));--card:0 1px 2px rgba(19,19,39,.06),0 12px 32px -16px rgba(19,19,39,.12)}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;background:var(--bg);color:var(--ink)}
a{text-decoration:none}button{font:inherit;cursor:pointer;border:none}

.loading-overlay{position:fixed;inset:0;background:rgba(255,255,255,.92);backdrop-filter:blur(4px);display:none;place-items:center;z-index:9999}
.loading-overlay.active{display:grid}
.spinner{width:48px;height:48px;border:4px solid #e2e8f0;border-top-color:var(--brand);border-radius:50%;animation:spin .8s linear infinite;margin:0 auto}
@keyframes spin{to{transform:rotate(360deg)}}
.spinner-text{margin-top:16px;font-size:13px;font-weight:600;color:var(--muted);text-align:center}

.sidebar{position:fixed;top:0;left:0;bottom:0;width:260px;background:#fff;border-right:1px solid var(--line);padding:24px 16px;z-index:30;transition:transform .3s ease;display:flex;flex-direction:column}
.sidebar-brand{padding:0 8px 24px;border-bottom:1px solid var(--line);margin-bottom:16px}
.sidebar-nav{display:flex;flex-direction:column;gap:4px}
.menu-section{margin-top:16px;padding:0 8px 8px;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--faint)}
.menu-item{display:flex;align-items:center;gap:12px;padding:12px 16px;border-radius:10px;font-size:14px;font-weight:600;color:var(--muted);text-decoration:none;transition:.15s}
.menu-item:hover{background:#f8fafc;color:var(--ink)}
.menu-item.active{background:var(--grad);color:#fff;box-shadow:0 4px 12px -4px rgba(84,87,229,.4)}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(19,19,39,.5);backdrop-filter:blur(4px);z-index:25}

.main-wrapper{margin-left:260px;min-height:100vh;display:flex;flex-direction:column}
.topbar{background:#fff;border-bottom:1px solid var(--line);padding:14px 24px;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:10;gap:12px;flex-wrap:wrap}
.brand{display:flex;align-items:center;gap:10px;font-weight:800;font-size:17px}
.logo{width:36px;height:36px;border-radius:12px;background:var(--grad);color:#fff;display:grid;place-items:center}
.brand em{font-style:normal;color:var(--brand)}
.top-right{display:flex;align-items:center;gap:14px;font-size:13px;color:var(--muted);flex-wrap:wrap}
.menu-toggle{display:none;background:none;border:none;font-size:22px;cursor:pointer;color:var(--ink);padding:4px}
.avatar{width:36px;height:36px;border-radius:50%;background:var(--grad);color:#fff;display:grid;place-items:center;font-weight:800;font-size:13px;overflow:hidden}
.btn-out{background:#fff1f2;color:#e11d48;border-radius:10px;padding:8px 14px;font-size:12px;font-weight:700}

.main{max-width:1200px;margin:0 auto;padding:32px 24px;width:100%}
h1{font-size:28px;font-weight:800;letter-spacing:-.02em}
.sub{color:var(--muted);font-size:14px;margin-top:4px}

.banner{margin:16px 0 0;border-radius:12px;padding:12px 18px;font-size:13px;font-weight:600}
.banner.success{background:#d1fae5;color:#059669}
.banner.error{background:#ffe4e6;color:#e11d48}
.banner.info{background:#e0e5ff;color:#4644cf}

.card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:28px;box-shadow:var(--card);margin-bottom:24px}
.card h2{font-size:20px;font-weight:800;margin-bottom:4px}
.card .msub{font-size:13px;color:var(--muted);margin-bottom:20px}

.steps{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-bottom:32px}
.step{background:#fff;border:1px solid var(--line);border-radius:16px;padding:24px;text-align:center;position:relative}
.step-num{width:40px;height:40px;border-radius:50%;background:var(--grad);color:#fff;display:grid;place-items:center;font-weight:800;font-size:18px;margin:0 auto 12px}
.step h3{font-size:16px;font-weight:700;margin-bottom:8px}
.step p{font-size:13px;color:var(--muted);line-height:1.5}

.upload-zone{border:2px dashed var(--line);border-radius:16px;padding:48px;text-align:center;transition:.2s;cursor:pointer}
.upload-zone:hover{border-color:var(--brand);background:#f8fafc}
.upload-zone.dragover{border-color:var(--brand);background:#e0e5ff}
.upload-icon{width:64px;height:64px;border-radius:16px;background:var(--grad);color:#fff;display:grid;place-items:center;font-size:28px;margin:0 auto 16px}

.btn{display:inline-flex;align-items:center;gap:8px;border-radius:12px;padding:11px 18px;font-size:13px;font-weight:700;transition:.15s;text-decoration:none}
.btn.primary{background:var(--grad);color:#fff;box-shadow:0 10px 24px -8px rgba(84,87,229,.5)}
.btn.ghost{background:#f1f5f9;color:#475569}
.btn.ghost:hover{background:#e2e8f0}
.btn.success{background:#d1fae5;color:#059669}

table{width:100%;border-collapse:collapse;font-size:14px}
th{padding:12px 16px;text-align:left;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--faint);background:#f8fafc;border-bottom:1px solid #f1f5f9}
td{padding:12px 16px;border-bottom:1px solid #f1f5f9;color:var(--muted);vertical-align:middle}
tbody tr:hover{background:#f8fafc}

.badge{display:inline-block;border-radius:999px;padding:3px 10px;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase}
.badge.valid{background:#d1fae5;color:#059669}
.badge.invalid{background:#ffe4e6;color:#e11d48}
.badge.pending{background:#fef3c7;color:#d97706}
.badge.submitted{background:#d1fae5;color:#059669}
.badge.failed{background:#ffe4e6;color:#e11d48}
.badge.consolidated{background:#e0e5ff;color:#4644cf}

.summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px}
.summary-card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:20px;text-align:center}
.summary-card b{display:block;font-size:28px;font-weight:800;color:var(--brand)}
.summary-card p{font-size:12px;font-weight:600;color:var(--muted);margin-top:4px;text-transform:uppercase;letter-spacing:.05em}

.action-bar{display:flex;gap:12px;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap}
.checkbox-label{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:var(--muted);cursor:pointer}

@media(max-width:900px){
  .sidebar{transform:translateX(-100%)}
  .sidebar.open{transform:translateX(0)}
  .sidebar-overlay.open{display:block}
  .main-wrapper{margin-left:0}
  .menu-toggle{display:block}
  .steps{grid-template-columns:1fr}
  .summary-grid{grid-template-columns:repeat(2,1fr)}
}
@media(max-width:760px){
  .main{padding:20px 12px}
  h1{font-size:22px}
  .summary-grid{grid-template-columns:1fr}
}
</style>
</head>
<body>
<div class="loading-overlay" id="loadingOverlay">
  <div>
    <div class="spinner"></div>
    <p class="spinner-text">Processing…</p>
  </div>
</div>

<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <span class="brand"><span class="logo">⚡</span>AZ Kejora <em>SaaS</em></span>
  </div>
  <nav class="sidebar-nav">
    <a href="main.php" class="menu-item">🏠 Home</a>
    <a href="e-invoice.php" class="menu-item active">🧾 E-Invoice</a>
    <div class="menu-section">Submission</div>
    <a href="e-invoice_upload.php" class="menu-item">📤 Upload</a>
    <a href="e-invoice_manual.php" class="menu-item">✍️ Manual Entry</a>
    <div class="menu-section">Setup</div>
    <a href="company.php" class="menu-item">🏢 Company</a>
    <a href="users.php" class="menu-item">👥 Users</a>
    <a href="profile.php" class="menu-item">👤 Profile</a>
  </nav>
</aside>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<div class="main-wrapper">
  <nav class="topbar">
    <div style="display:flex;align-items:center;gap:12px">
      <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
      <a href="e-invoice.php" class="brand"><span class="logo">⚡</span>AZ Kejora <em>SaaS</em></a>
    </div>
    <div class="top-right">
      <span>Welcome, <b><?= htmlspecialchars(explode(' ', $me['name'])[0]) ?></b></span>
      <span class="avatar"><?= strtoupper(substr($me['name'],0,1)) ?></span>
      <a class="btn-out" href="/public/login.php?logout=1">Sign out</a>
    </div>
  </nav>

  <main class="main">
    <h1>Upload E-Invoice 📤</h1>
    <p class="sub">Bulk upload your invoices from Excel or CSV files.</p>

    <?php if (isset($_GET['err'])): ?>
      <div class="banner error">✗ <?= htmlspecialchars($_GET['err']) ?></div>
    <?php endif; ?>
    
    <?php if (isset($_GET['submitted'])): ?>
      <?php if ($_GET['submitted'] === 'individual'): ?>
        <div class="banner success">✓ Submitted <?= (int)$_GET['count'] ?> invoice(s)<?= (int)$_GET['failed'] > 0 ? ', ' . (int)$_GET['failed'] . ' failed' : '' ?>.</div>
      <?php elseif ($_GET['submitted'] === 'consolidated'): ?>
        <div class="banner <?= $_GET['status'] === 'success' ? 'success' : 'error' ?>">
          <?= $_GET['status'] === 'success' ? '✓ Consolidated invoice submitted successfully.' : '✗ Consolidated submission failed.' ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <!-- Step-by-step guide -->
    <div class="steps">
      <div class="step">
        <div class="step-num">1</div>
        <h3>Download Template</h3>
        <p>Download our Excel template with all required fields pre-formatted.</p>
        <a href="download_template.php" class="btn ghost" style="margin-top:12px">📥 Download</a>
      </div>
      <div class="step">
        <div class="step-num">2</div>
        <h3>Fill Your Data</h3>
        <p>Add your invoice records to the template. Ensure all mandatory fields are filled.</p>
      </div>
      <div class="step">
        <div class="step-num">3</div>
        <h3>Upload & Submit</h3>
        <p>Upload the completed file, validate, and submit to LHDN MyInvois.</p>
      </div>
    </div>

    <?php if (!$currentUpload): ?>
      <!-- Upload form -->
      <div class="card">
        <h2>Upload Invoice File</h2>
        <p class="msub">Select your completed Excel or CSV file to begin validation.</p>
        
        <form method="POST" enctype="multipart/form-data" id="uploadForm">
          <div class="upload-zone" id="dropZone" onclick="document.getElementById('fileInput').click()">
            <div class="upload-icon">📤</div>
            <h3 style="font-size:18px;font-weight:700;margin-bottom:8px">Drop your file here</h3>
            <p style="color:var(--muted);margin-bottom:16px">or click to browse</p>
            <p style="font-size:12px;color:var(--faint)">Supports CSV, XLSX, XLS · Max 10MB</p>
            <input type="file" id="fileInput" name="invoice_file" accept=".csv,.xlsx,.xls" style="display:none" required>
          </div>
          <div id="fileInfo" style="margin-top:16px;display:none">
            <div style="display:flex;align-items:center;gap:12px;padding:12px;background:#f8fafc;border-radius:10px">
              <span style="font-size:24px">📄</span>
              <div style="flex:1">
                <p style="font-weight:600" id="fileName"></p>
                <p style="font-size:12px;color:var(--faint)" id="fileSize"></p>
              </div>
              <button type="submit" class="btn primary">Upload & Validate</button>
            </div>
          </div>
        </form>
      </div>

      <!-- Recent uploads -->
      <?php if (!empty($uploadsList)): ?>
        <div class="card">
          <h2>Recent Uploads</h2>
          <p class="msub">Your last 10 uploaded files.</p>
          <table>
            <thead>
              <tr>
                <th>Filename</th>
                <th>Date</th>
                <th>Total</th>
                <th>Valid</th>
                <th>Invalid</th>
                <th>Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($uploadsList as $up): ?>
                <tr>
                  <td><b><?= htmlspecialchars($up['filename']) ?></b></td>
                  <td><?= date('M d, H:i', strtotime($up['created_at'])) ?></td>
                  <td><?= $up['total_records'] ?></td>
                  <td style="color:#059669"><?= $up['valid_records'] ?></td>
                  <td style="color:#e11d48"><?= $up['invalid_records'] ?></td>
                  <td><span class="badge <?= $up['status'] ?>"><?= $up['status'] ?></span></td>
                  <td><a href="?upload=<?= $up['id'] ?>" class="btn ghost" style="padding:6px 12px;font-size:11px">View</a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

    <?php else: ?>
      <!-- Validation results -->
      <div class="card">
        <h2>Validation Results</h2>
        <p class="msub"><?= htmlspecialchars($currentUpload['filename']) ?> · Uploaded <?= date('M d, H:i', strtotime($currentUpload['created_at'])) ?></p>
        
        <div class="summary-grid">
          <div class="summary-card">
            <b><?= $currentUpload['total_records'] ?></b>
            <p>Total Records</p>
          </div>
          <div class="summary-card">
            <b style="color:#059669"><?= $currentUpload['valid_records'] ?></b>
            <p>Valid</p>
          </div>
          <div class="summary-card">
            <b style="color:#e11d48"><?= $currentUpload['invalid_records'] ?></b>
            <p>Invalid</p>
          </div>
          <div class="summary-card">
            <b><?= number_format(array_sum(array_column($records, 'total_amount')), 2) ?></b>
            <p>Total Amount (RM)</p>
          </div>
        </div>

        <?php if ($currentUpload['valid_records'] > 0): ?>
          <div class="action-bar">
            <label class="checkbox-label">
              <input type="checkbox" id="selectAll">
              <span>Select all valid records</span>
            </label>
            <div style="display:flex;gap:8px">
              <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="submit_individual">
                <div id="selectedIds"></div>
                <button type="submit" class="btn primary" id="submitSelected" disabled>Submit Selected</button>
              </form>
            </div>
          </div>
        <?php endif; ?>

        <div style="overflow-x:auto">
          <table>
            <thead>
              <tr>
                <th><input type="checkbox" id="checkAll"></th>
                <th>Sale No</th>
                <th>Customer</th>
                <th>Amount</th>
                <th>Tax</th>
                <th>Total</th>
                <th>Date</th>
                <th>Status</th>
                <th>Submission</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($records as $rec): ?>
                <tr>
                  <td>
                    <?php if ($rec['validation_status'] === 'valid' && $rec['submission_status'] === 'pending'): ?>
                      <input type="checkbox" class="record-check" value="<?= $rec['id'] ?>">
                    <?php endif; ?>
                  </td>
                  <td><b><?= htmlspecialchars($rec['sale_no']) ?></b></td>
                  <td>
                    <?= htmlspecialchars($rec['customer_name']) ?>
                    <div style="font-size:11px;color:var(--faint)"><?= htmlspecialchars($rec['customer_email']) ?></div>
                  </td>
                  <td>RM <?= number_format($rec['sale_amount'], 2) ?></td>
                  <td>RM <?= number_format($rec['sale_tax'], 2) ?></td>
                  <td><b>RM <?= number_format($rec['total_amount'], 2) ?></b></td>
                  <td><?= date('M d, H:i', strtotime($rec['sale_datetime'])) ?></td>
                  <td><span class="badge <?= $rec['validation_status'] ?>"><?= $rec['validation_status'] ?></span></td>
                  <td><span class="badge <?= $rec['submission_status'] ?>"><?= $rec['submission_status'] ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?php if ($currentUpload['valid_records'] > 0): ?>
          <div class="card" style="margin-top:24px;background:#f8fafc">
            <h3 style="font-size:16px;font-weight:700;margin-bottom:12px">Or Submit as Consolidated</h3>
            <p style="font-size:13px;color:var(--muted);margin-bottom:16px">Group all valid records by date into a single consolidated invoice.</p>
            <form method="POST" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
              <input type="hidden" name="action" value="submit_consolidated">
              <div>
                <label style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;color:var(--muted);margin-bottom:6px">Consolidation Date</label>
                <input type="date" name="consolidate_date" value="<?= date('Y-m-d') ?>" required style="padding:9px 14px;border:1px solid var(--line);border-radius:10px;font-size:14px">
              </div>
              <button type="submit" class="btn success">Submit Consolidated</button>
            </form>
          </div>
        <?php endif; ?>

        <div style="margin-top:24px">
          <a href="e-invoice_upload.php" class="btn ghost">← Upload another file</a>
        </div>
      </div>
    <?php endif; ?>

    <!-- Submission history -->
    <?php if (!empty($submissionsList)): ?>
      <div class="card">
        <h2>Submission History</h2>
        <p class="msub">Your recent LHDN API submissions.</p>
        <div style="overflow-x:auto">
          <table>
            <thead>
              <tr>
                <th>Type</th>
                <th>Reference</th>
                <th>LHDN UUID</th>
                <th>Status</th>
                <th>Date</th>
                <th>Response</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($submissionsList as $sub): ?>
                <tr>
                  <td><span class="badge <?= $sub['submission_type'] ?>"><?= $sub['submission_type'] ?></span></td>
                  <td>
                    <?php if ($sub['submission_type'] === 'individual'): ?>
                      <?= htmlspecialchars($sub['sale_no'] ?? '—') ?>
                      <div style="font-size:11px;color:var(--faint)"><?= htmlspecialchars($sub['customer_name'] ?? '') ?></div>
                    <?php else: ?>
                      Consolidated <?= $sub['sale_date'] ?? '' ?>
                    <?php endif; ?>
                  </td>
                  <td style="font-family:monospace;font-size:11px"><?= htmlspecialchars(substr($sub['lhdn_uuid'] ?? '—', 0, 16)) ?>...</td>
                  <td><span class="badge <?= $sub['status'] ?>"><?= $sub['status'] ?></span></td>
                  <td><?= date('M d, H:i', strtotime($sub['created_at'])) ?></td>
                  <td>
                    <?php if ($sub['api_response']): ?>
                      <button class="btn ghost" style="padding:6px 12px;font-size:11px" onclick="showResponse('<?= htmlspecialchars(json_encode($sub['api_response'])) ?>')">View JSON</button>
                    <?php else: ?>
                      —
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  </main>
</div>

<!-- JSON Response Modal -->
<div class="modal" id="responseModal" style="position:fixed;inset:0;z-index:70;display:none;place-items:center;background:rgba(19,19,39,.5);backdrop-filter:blur(4px);padding:16px">
  <div class="modal-card" style="width:100%;max-width:640px;background:#fff;border-radius:20px;padding:28px;box-shadow:0 30px 80px -20px rgba(19,19,39,.4);max-height:80vh;overflow-y:auto">
    <h3 style="font-size:18px;font-weight:800;margin-bottom:12px">API Response</h3>
    <pre id="jsonContent" style="background:#f8fafc;padding:16px;border-radius:10px;font-size:12px;overflow-x:auto;white-space:pre-wrap"></pre>
    <button class="btn ghost" onclick="closeResponse()" style="margin-top:16px;width:100%">Close</button>
  </div>
</div>

<script>
function toggleSidebar(){
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('sidebarOverlay').classList.toggle('open');
}

// File upload
const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');
const fileInfo = document.getElementById('fileInfo');

fileInput?.addEventListener('change', function(e) {
  const file = e.target.files[0];
  if (file) {
    document.getElementById('fileName').textContent = file.name;
    document.getElementById('fileSize').textContent = (file.size / 1024).toFixed(2) + ' KB';
    fileInfo.style.display = 'block';
    dropZone.style.display = 'none';
  }
});

['dragenter', 'dragover'].forEach(evt => {
  dropZone?.addEventListener(evt, e => {
    e.preventDefault();
    dropZone.classList.add('dragover');
  });
});

['dragleave', 'drop'].forEach(evt => {
  dropZone?.addEventListener(evt, e => {
    e.preventDefault();
    dropZone.classList.remove('dragover');
  });
});

dropZone?.addEventListener('drop', e => {
  const files = e.dataTransfer.files;
  if (files.length > 0) {
    fileInput.files = files;
    fileInput.dispatchEvent(new Event('change'));
  }
});

// Select all checkbox
document.getElementById('checkAll')?.addEventListener('change', function(e) {
  document.querySelectorAll('.record-check').forEach(cb => cb.checked = e.target.checked);
  updateSelected();
});

document.querySelectorAll('.record-check').forEach(cb => {
  cb.addEventListener('change', updateSelected);
});

function updateSelected() {
  const checked = document.querySelectorAll('.record-check:checked');
  const ids = Array.from(checked).map(cb => cb.value);
  document.getElementById('selectedIds').innerHTML = ids.map(id => `<input type="hidden" name="record_ids[]" value="${id}">`).join('');
  document.getElementById('submitSelected').disabled = ids.length === 0;
}

// JSON response modal
function showResponse(json) {
  document.getElementById('jsonContent').textContent = JSON.stringify(JSON.parse(json), null, 2);
  document.getElementById('responseModal').style.display = 'grid';
}

function closeResponse() {
  document.getElementById('responseModal').style.display = 'none';
}

// Loading overlay
const overlay = document.getElementById('loadingOverlay');
document.querySelectorAll('form').forEach(form => {
  form.addEventListener('submit', function(e) {
    if (this.onsubmit && !this.onsubmit(e)) return;
    overlay.classList.add('active');
  });
});
</script>
</body>
</html>
