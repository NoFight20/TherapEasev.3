<?php
session_name('sess_admin'); session_start();

// Check if the user is logged in and is a admin
if (isset($_SESSION["user"])) {
    if (empty($_SESSION["user"]) || $_SESSION['usertype'] != 'a') {
        header("location: ../login.php");
        exit();
    } else {
        $useremail = $_SESSION["user"];
    }
} else {
    header("location: ../login.php");
    exit();
}

include("../connection.php");

$DENY_SPECIALTIES = ['information', 'hmo', 'philhealth', 'cashier'];

/**
 * Get admin specialties (sname) for the current user as a lowercase array.
 * - Supports sname as string, array, or comma-separated string.
 */
function getAdminSpecialtiesByEmail($database, string $email): array {
    try {
        $snap = $database->getReference('admin')
            ->orderByChild('email')
            ->equalTo($email)
            ->getSnapshot();
        $data = $snap->getValue();

        if (!$data || !is_array($data)) return [];

        // Use the first matched admin record
        $first = array_shift($data);
        if (!isset($first['sname'])) return [];

        $raw = $first['sname'];

        // Normalize to array
        if (is_array($raw)) {
            $list = $raw;
        } else {
            // string; possibly comma-separated
            $list = array_map('trim', explode(',', (string)$raw));
        }

        // Lowercase, remove empty
        $list = array_values(array_filter(array_map(fn($s) => mb_strtolower(trim((string)$s)), $list)));
        return $list;
    } catch (\Throwable $e) {
        return [];
    }
}

$adminSpecialties = getAdminSpecialtiesByEmail($database, $useremail);

// Intersect (case-insensitive already normalized)
$denyLower = $DENY_SPECIALTIES;
$blocked = count(array_intersect($adminSpecialties, $denyLower)) > 0;

if ($blocked) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Access Denied</title>
        <link rel="stylesheet" href="../css/main.css">
        <style>
            :root { --green:#008000; --green-soft:#C4F0C5; }
            body { display:flex; align-items:center; justify-content:center; min-height:100vh; background:#f7f7f7; margin:0; }
            .card { background:#fff; padding:28px 32px; border-radius:10px; box-shadow:0 6px 24px rgba(0,0,0,.08); text-align:center; max-width:560px; }
            .card h1 { margin:0 0 8px; font-size:22px; color:#222; }
            .card p { margin:0 0 18px; color:#555; }
            .btn { display:inline-block; padding:10px 16px; border-radius:6px; text-decoration:none; border:1px solid var(--green); color:var(--green); }
            .btn:hover { background:var(--green-soft); }
            .muted { color:#777; font-size:13px; }
            .pill { display:inline-block; padding:4px 8px; border:1px solid #ddd; border-radius:999px; margin:2px; font-size:12px; background:#fafafa; }
        </style>
    </head>
    <body>
        <div class="card">
            <h1>You cannot view this page</h1>
            <p>This content isn’t available for your specialty.</p>
            <p><strong>Restricted specialties:</strong> HMO, PhilHealth, Cashier</p>
            <?php if (!empty($adminSpecialties)): ?>
                <p class="muted">
                    Your specialties:
                    <?php foreach ($adminSpecialties as $s): ?>
                        <span class="pill"><?php echo htmlspecialchars($s); ?></span>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>
            <a class="btn" href="index.php">Go back</a>
        </div>
    </body>
    </html>
    <?php
    exit();
}

$isDeniedSpecialty = $blocked;

// ---------- Utility: safe getter ----------
function val($arr, $key, $fallback='') {
    return isset($arr[$key]) ? $arr[$key] : $fallback;
}


// ---------- View mode (patients | queue) ----------
$view = isset($_GET['view']) && in_array($_GET['view'], ['patients','queue'], true) ? $_GET['view'] : 'patients';

// ---------- Patients Retrieve by fname (kept as-is) ----------
if ($view === 'patients' && isset($_GET['action']) && $_GET['action'] === 'retrieve' && isset($_GET['fname'])) {
    $fname = trim((string) $_GET['fname']);

    if ($fname === '') {
        echo '<script>alert("Invalid first name."); window.location.href="archive.php?view=patients";</script>';
        exit();
    }

    $archiveSnap = $database->getReference('archive')->getSnapshot();
    if (!$archiveSnap->exists()) {
        echo '<script>alert("Error: No archived patients found."); window.location.href="archive.php?view=patients";</script>';
        exit();
    }

    $archivedPatients = $archiveSnap->getValue();
    error_log("Archived Patients: " . print_r($archivedPatients, true));

    $patientFound = false;
    date_default_timezone_set('Asia/Manila');
    $date = date('Y-m-d');
    $time = date('H:i:s');

    foreach ($archivedPatients as $pid => $payload) {
        error_log("Checking Patient ID: $pid, Data: " . print_r($payload, true));
        $patientDetails = $payload['patient'] ?? [];
        if (($patientDetails['fname'] ?? '') !== $fname) {
            continue;
        }

        $medicalRecordDetails = $payload['medical_record'] ?? ($payload['medical_records'] ?? []);

        if (!isset($patientDetails['email']) || $patientDetails['email'] === '') {
            $patientDetails['email'] = "No Account Yet";
        }

        try {
            // 1) Restore patient 
            $database->getReference('patients/' . $pid)->set($patientDetails);

            // 2) Restore medical record to NEW schema path
            $database->getReference('patients/' . $pid . '/medical_record')->set($medicalRecordDetails);

            // 3) Optionally restore to legacy path for compatibility
            $database->getReference('medical_records/' . $pid)->set($medicalRecordDetails);

            // 4) Remove from archive
            $database->getReference('archive/' . $pid)->remove();

            // 5) Log
            $logData = [
                'action'      => 'retrieve',
                'email'       => $useremail,
                'patientId'   => $pid,
                'patientName' => ($patientDetails['name'] ?? trim(($patientDetails['fname'] ?? '').' '.($patientDetails['lname'] ?? ''))),
                'timestamp'   => $time,
                'datestamp'   => $date,
                'status'      => 'Restored patient and medical records.',
            ];
            $database->getReference('logs')->push($logData);

            $patientFound = true;
            break;

        } catch (Exception $e) {
            error_log("Retrieve failed for $pid: " . $e->getMessage());
            echo '<script>alert("An error occurred while restoring the patient: ' . htmlspecialchars($e->getMessage()) . '"); window.location.href="archive.php?view=patients";</script>';
            exit();
        }
    }

    if ($patientFound) {
        echo '<script>alert("Patient retrieved successfully!"); window.location.href="archive.php?view=patients&message=Patient retrieved successfully";</script>';
    } else {
        echo '<script>alert("Error: No archived patient found \'' . htmlspecialchars($fname, ENT_QUOTES, 'UTF-8') . '\'"); window.location.href="archive.php?view=patients";</script>';
    }
    exit();
}

// ---------- Fetch admin info (unchanged, with minor robustness) ----------
try {
    $reference = $database
        ->getReference('admin')
        ->orderByChild('email')
        ->equalTo($useremail)
        ->getSnapshot();

    $userfetch = $reference->getValue();

    if ($userfetch) {
        foreach ($userfetch as $key => $value) {
            $username = val($value, 'name');
            if (isset($value['sname'])) {
                if (is_array($value['sname'])) {
                    $specialty = implode(", ", $value['sname']);
                } else { 
                    $specialty = $value['sname']; 
                } 
            } else { 
                $specialty = 'N/A'; 
            } 
            $adminSpecialty = trim((string)$specialty); 
        } 
    } else { 
        echo "No user found."; 
    } 
} catch (\Kreait\Firebase\Exception\DatabaseException $e) { 
    echo "Error querying the database: " . $e->getMessage(); 
} 

// Retrieve admin data from Firebase (to get photo)
$adminRef = $database->getReference('admin')->orderByChild('email')->equalTo($useremail);
$adminData = $adminRef->getValue();
if ($adminData) {
    $adminData = array_shift($adminData); 
    $username = isset($adminData['name']) ? $adminData['name'] : (isset($username) ? $username : null);
} 
$photo = '../img/user.png'; 
if ($adminData && !empty($adminData['photo'])) {
    $photo = $adminData['photo']; 
}

// ---------- Helpers: data sources & counts ----------
function countPatientsBySpecialty($database, $adminSpecialty) {
    $archiveData = $database->getReference('archive')->getValue();
    $count = 0;
    if (is_array($archiveData)) {
        foreach ($archiveData as $record) {
            $recordSname = '';
            if (isset($record['patient']['sname'])) {
                $recordSname = $record['patient']['sname'];
            } elseif (isset($record['medical_records']['sname'])) {
                $recordSname = $record['medical_records']['sname'];
            }
            if (!empty($recordSname) && strcasecmp($recordSname, $adminSpecialty) === 0) {
                $count++;
            }
        }
    }
    return $count;
}


function fetchQueueArchiveBySpecialty($database, $adminSpecialty) {
    $data = $database->getReference('archive_queue')->getValue();
    $out = [];
    if (!is_array($data)) return $out;

    $want = mb_strtolower(trim((string)$adminSpecialty));
    foreach ($data as $key => $node) {
        // Case 1: This node is a single item (has formatted_token/date etc.)
        if (is_array($node) && (isset($node['formatted_token']) || isset($node['reg_number']) || isset($node['token_number']))) {
            $dept = mb_strtolower(trim((string)($node['department'] ?? $node['type'] ?? '')));
            if ($dept !== '' && $dept === $want) {
                $out[] = [
                    'id'         => $key,
                    'archived_at'=> val($node,'archived_at'),
                    'date'       => val($node,'date'),
                    'token'      => val($node,'formatted_token'),
                    'name'       => val($node,'name'),
                    'priority'   => val($node,'priority'),
                    'purpose'    => val($node,'purpose'),
                    'status'     => val($node,'queue_status'),
                    'reg_number' => val($node,'reg_number'),
                    'token_num'  => val($node,'token_number'),
                    'dept'       => val($node,'department', val($node,'type')),
                ];
            }
            continue;
        }

        // Case 2: This node is a department bucket
        if (is_array($node)) {
            foreach ($node as $childId => $item) {
                if (!is_array($item)) continue;
                $dept = mb_strtolower(trim((string)($item['department'] ?? $item['type'] ?? '')));
                if ($dept !== '' && $dept === $want) {
                    $out[] = [=
                        'id'         => $childId,
                        'archived_at'=> val($item,'archived_at'),
                        'date'       => val($item,'date'),
                        'token'      => val($item,'formatted_token'),
                        'name'       => val($item,'name', trim(val($item,'fname').' '.val($item,'lname'))),
                        'priority'   => val($item,'priority'),
                        'purpose'    => val($item,'purpose'),
                        'status'     => val($item,'queue_status'),
                        'reg_number' => val($item,'reg_number'),
                        'token_num'  => val($item,'token_number'),
                        'dept'       => val($item,'department', val($item,'type')),
                    ];
                }
            }
        }
    }

    // Sort newest archived_at first, fallback to date then token
    usort($out, function($a,$b){
        $aKey = $a['archived_at'] ?: $a['date'] ?: $a['token'];
        $bKey = $b['archived_at'] ?: $b['date'] ?: $b['token'];
        return strcmp($bKey, $aKey);
    });

    return $out;
}

function countQueueBySpecialty($database, $adminSpecialty) {
    return count(fetchQueueArchiveBySpecialty($database, $adminSpecialty));
}

// ---------- Build search helpers ----------
$today_date_display = (function(){
    date_default_timezone_set('Asia/Manila');
    return date('Y-m-d');
})();

// For datalist in current view
$patientsData = null;
$queueItems   = null;
if ($view === 'patients') {
    $patientsData = $database->getReference('archive')->getValue();
} else {
    $queueItems = fetchQueueArchiveBySpecialty($database, $adminSpecialty);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../css/animations.css">  
    <link rel="stylesheet" href="../css/main.css">  
    <link rel="stylesheet" href="../css/admin.css">
    <link rel="stylesheet" href="../css/hamburger.css">
    <title>Archive</title>
    <style>
        .dashbord-tables{ animation: transitionIn-Y-over 0.5s; }
        .filter-container{ animation: transitionIn-Y-bottom 0.5s; }
        .sub-table{ animation: transitionIn-Y-bottom 0.5s; }
        .menu-btn a { display: block; text-decoration: none; padding: px; width: 100%; }
        .menu-btn:hover { background-color: #E8F8E8; }
        .menu-item.active { background-color: #C4F0C5; color: #006400; font-weight: bold; }
        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4); }
        .modal-content { background: #fff; padding: 20px; border-radius: 5px; width: 50%; text-align: center; margin: 15% auto; }
        .close { color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; }
        .overlay-btn { background-color: #008000; color: white; border: none; padding: 10px 20px; margin: 10px; border-radius: 4px; cursor: pointer; }
        .overlay-btn.cancel-btn { background-color: #ff0000; }
        .overlay-btn:hover { opacity: 0.9; }

        /* View toggle */
        .view-toggle { display:flex; gap:8px; align-items:center; }
        .toggle-link { 
            border:1px solid #008000; 
            padding:8px 12px; 
            border-radius:6px; 
            text-decoration:none; 
            font-size:14px; 
        }
        .toggle-link.active { background:#C4F0C5; color:#006400; font-weight:600; }
        .toggle-link.inactive { background:#fff; color:#008000; }
        .header-search .input-text { width: 60%; }
        @media (max-width: 900px) { .header-search .input-text { width: 100%; } }

        /* =========================
   MOBILE HEADER + DRAWER
   ========================= */
.mobile-header{ display:none; }
#menu-overlay{ display:none; }

/* keep desktop unchanged */
@media (min-width: 769px){
  .menu{ position:relative; transform:none !important; }
  #hamburger-menu{ display:none; }
}

/* Desktop: hide the email that is placed under the name (mobile-only) */
.name-email-wrap .email{
  display:none;
}

/* ✅ MOBILE */
@media (max-width: 768px){

  /* prevent horizontal scroll */
  html, body{ max-width:100%; overflow-x:hidden; }

  /* Fixed header */
  .mobile-header{
    display:flex !important;
    position:fixed;
    top:0; left:0; right:0;
    height:56px;
    background: lightgreen;
    z-index:10050;
    align-items:center;
    justify-content:space-between;
    padding:0 12px;
    box-sizing:border-box;
    box-shadow:0 2px 10px rgba(0,0,0,.12);
  }

  .mh-left{ width:44px; display:flex; align-items:center; }
  .mh-center{
    flex:1;
    text-align:center;
    font-weight:800;
    font-size:16px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
    color:#000;
  }
  .mh-right{ display:flex; align-items:center; gap:8px; }

  .mh-date{
    text-align:right;
    line-height:1.1;
    font-weight:700;
    color:#000;
  }
  .mh-date-label{ display:block; font-size:11px; opacity:.75; }
  .mh-date-value{ display:block; font-size:12px; }

  .mh-cal{
    display:flex;
    align-items:center;
    justify-content:center;
    width:34px;
    height:34px;
    border-radius:10px;
    background: rgba(255, 255, 255, 0);
    border:none;
    padding:0;
    cursor:pointer;
  }
  .mh-cal img{ width:18px; height:18px; }

  /* Hamburger */
  #hamburger-menu{
    display:flex !important;
    width:34px !important;
    height:34px !important;
    padding:8px !important;
    border-radius:10px !important;
    background: rgba(255,255,255,.48) !important;
    cursor:pointer;
    flex-direction:column;
    justify-content:center;
    gap:5px;
    box-sizing:border-box;
  }
  #hamburger-menu .bar{
    height:2px !important;
    width:100% !important;
    background:#111 !important;
    border-radius:2px;
    transition:.25s;
  }
  #hamburger-menu.active .bar:nth-child(1){
    transform: rotate(-45deg) translate(-4px, 5px);
  }
  #hamburger-menu.active .bar:nth-child(2){ opacity:0; }
  #hamburger-menu.active .bar:nth-child(3){
    transform: rotate(45deg) translate(-4px, -5px);
  }

  /* Overlay */
  #menu-overlay{
    display:block !important;
    position:fixed !important;
    inset:0 !important;
    background: rgba(0,0,0,.35) !important;
    opacity:0 !important;
    pointer-events:none !important;
    transition: opacity .2s ease !important;
    z-index:10030 !important;
  }
  body.menu-open #menu-overlay{
    opacity:1 !important;
    pointer-events:auto !important;
  }
  body.menu-open{ overflow:hidden; }

  /* Drawer menu */
  .menu{
    display:block !important;
    position:fixed !important;
    top:56px !important;
    left:-280px !important;
    width:280px !important;
    max-width:86vw !important;
    height:calc(100vh - 56px) !important;
    background: lightgreen !important;
    z-index:10040 !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
    transition:left .25s ease !important;
    box-shadow:10px 0 30px rgba(0,0,0,.12) !important;
  }
  .menu.active{ left:0 !important; }

  /* Push page content below header */
  .container{
    padding-top:56px !important;
    width:100% !important;
    max-width:100% !important;
    box-sizing:border-box;
  }

  .dash-body{
    margin:0 !important;
    padding:12px !important;
    width:100% !important;
    box-sizing:border-box;
  }

  /* Hide desktop date + calendar in table header (since we show it in mobile header) */
  td[width="15%"],
  td[width="10%"]{
    display:none !important;
  }

  /* Search bar row compact */
  .header-search{
    display:flex !important;
    flex-wrap:wrap !important;
    gap:8px !important;
    align-items:center !important;
  }
  .header-search .header-searchbar{
    flex:1 1 200px !important;
    width:auto !important;
  }
  .header-search input[type="submit"]{
    flex:0 0 120px !important;
    width:120px !important;
  }

  /* show only Name (with email under it) + Events */
  .col-age,
  .col-address,
  .col-phone,
  .col-email,
  .col-dob,
  .col-gender,
  .col-civil{
    display:none !important;
  }

  /* table sizing */
  .abc.scroll{ overflow-x:hidden !important; }
  .sub-table{
    width:100% !important;
    table-layout:fixed !important;
    min-width:0 !important;
  }

  .sub-table th,
  .sub-table td{
    padding:10px 8px !important;
    font-size:13px !important;
    white-space:normal !important;
    vertical-align:top !important;
  }

  /* make columns align: Name takes space, Events fixed */
  .sub-table th.col-name,
  .sub-table td.col-name{
    width:auto !important;
    text-align:left !important;
    font-weight:600;
  }

  .sub-table th.col-action,
  .sub-table td.col-action{
    width:110px !important;
    text-align:right !important;
    vertical-align:middle !important;
  }

  /* stacked name + email */
  .name-email-wrap{
    display:flex;
    flex-direction:column;
    gap:4px;
    align-items:flex-start;
  }

  .name-email-wrap .name{
    font-weight:700;
    line-height:1.2;
  }

  /* ✅ email wraps DOWN and stays aligned */
  .name-email-wrap .email{
    display:block;
    font-size:12px;
    color:#555;
    line-height:1.3;
    max-width:100%;
    word-break:break-word;
    overflow-wrap:anywhere;
  }

  /* Retrieve button nicer */
  .btn-retrieve{
    padding:10px 14px !important;
    border-radius:10px !important;
    white-space: nowrap;
  }

  /* View toggle looks good on mobile */
  .view-toggle{
    margin-left:0 !important;
    justify-content:flex-start;
    flex-wrap:wrap;
  }
}

/* =========================
   FIX: Queue Archive mobile layout
   - Only apply compact layout to PATIENTS table
   ========================= */
@media (max-width: 768px){

  /* For QUEUE table: allow horizontal scroll + keep text readable */
  .abc.scroll{
    overflow-x:auto !important;
    -webkit-overflow-scrolling: touch;
  }

  /* Queue headers/rows should not wrap per word */
  .sub-table th,
  .sub-table td{
    white-space: nowrap;
    word-break: normal;
    overflow-wrap: normal;
  }

  /* ✅ Apply the compact "Name + Events" layout ONLY when patient columns exist */
  .sub-table th.col-name,
  .sub-table td.col-name{
    white-space: normal !important;
    overflow-wrap:anywhere !important;
    word-break:break-word !important;
  }

  /* If this is QUEUE table (no .col-*), keep wide min-width so it scrolls nicely */
  .sub-table{
    min-width: 900px;
    table-layout: auto !important;
  }
}

/* =========================
   QUEUE ARCHIVE (MOBILE) - patient-like compact design
   ========================= */
@media (max-width: 768px){

  /* Only affect queue view */
  .queue-table{
    width:100% !important;
    table-layout: fixed !important;
    min-width:0 !important;
  }

  /* Hide the queue header to look like compact cards */
  .queue-table thead{ display:none !important; }

  /* Turn each row into a "card row" */
  .queue-table tbody tr{
    display:grid !important;
    grid-template-columns: 1fr 110px; /* left details | right status */
    gap:10px !important;
    padding:12px 10px !important;
    border:1px solid #000000 !important;
    border-radius:10px !important;
    margin-bottom:10px !important;
    background:#fff !important;
  }

  /* Remove table borders inside */
  .queue-table td{
    border:none !important;
    padding:0 !important;
    text-align:left !important;
    vertical-align:top !important;
    white-space:normal !important;
    word-break:break-word !important;
    overflow-wrap:anywhere !important;
  }

  /* LEFT SIDE: show only important details */
  .queue-table td.q-token,
  .queue-table td.q-name,
  .queue-table td.q-reg,
  .queue-table td.q-purpose,
  .queue-table td.q-date,
  .queue-table td.q-dept{
    display:block !important;
  }

  /* HIDE less important columns on mobile (you can remove any if you want shown) */
  .queue-table td.q-priority,
  .queue-table td.q-archived{
    display:none !important;
  }

  /* Make left-side text look like patient archive stack */
  .queue-table td.q-name{
    font-weight:700 !important;
    font-size:14px !important;
    line-height:1.2 !important;
  }

  .queue-table td.q-token{
    font-size:12px !important;
    color:#555 !important;
    margin-top:4px !important;
  }

  .queue-table td.q-reg,
  .queue-table td.q-dept,
  .queue-table td.q-date,
  .queue-table td.q-purpose{
    font-size:12px !important;
    color:#555 !important;
    margin-top:4px !important;
    line-height:1.3 !important;
  }

  /* RIGHT SIDE: status as a badge, aligned like the retrieve button area */
  .queue-table td.q-status{
    grid-column:2 !important;
    grid-row:1 / span 10 !important;
    align-self:center !important;
    justify-self:end !important;
    text-align:right !important;
    font-weight:700 !important;
    padding:8px 10px !important;
    border-radius:10px !important;
    background:#E8F8E8 !important;
    color:#006400 !important;
    white-space:nowrap !important;
  }
}
@media (max-width: 768px){
  .menu .profile-subtitle{
    font-size: 13px !important;
  }
}

    </style>

</head>
<body>

   <!-- ✅ MOBILE HEADER -->
<div class="mobile-header" id="mobileHeader">
  <div class="mh-left">
    <div id="hamburger-menu" aria-label="Open menu" role="button" tabindex="0">
      <div class="bar"></div>
      <div class="bar"></div>
      <div class="bar"></div>
    </div>
  </div>

  <div class="mh-center">Archive</div>

  <div class="mh-right">
    <div class="mh-date">
      <span class="mh-date-label">Date</span>
      <span class="mh-date-value"><?php echo $today_date_display; ?></span>
    </div>

    <button class="mh-cal btn-label" type="button" aria-label="Calendar">
      <img src="../img/calendar.svg" alt="">
    </button>
  </div>
</div>

<!-- ✅ overlay (tap to close menu) -->
<div id="menu-overlay"></div>


    <div class="container">
        <div class="menu">
            <table class="menu-container" border="0">
                <tr>
                    <td style="padding:10px" colspan="2">
                        <table border="0" class="profile-container">
                            <tr>
                                <td width="30%" style="padding-left:20px">
                                    <img src="<?php echo htmlspecialchars($photo); ?>" alt="Profile Picture" width="100%" style="border-radius:50%">
                                </td>
                                <td style="padding:0px;margin:0px;">
                                    <p class="profile-title"><?php echo substr($username,0,50) ?></p>
                                    <p class="profile-subtitle"><?php echo substr($useremail,0,50)  ?></p>
                                    <p class="profile-subtitle" style="margin-top: 10px">(<?php echo isset($specialty) ? substr($specialty, 0, 50) : ' '; ?>)</p>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="2">
                                    <a href="../logout.php"><input type="button" value="Log out" class="logout-btn btn-primary-soft btn"></a>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr class="menu-row">
                    <td class="menu-btn">
                        <a href="index.php" class="non-style-link-menu"><p class="menu-text">Dashboard</p></a>
                    </td>
                </tr>
                <tr class="menu-row"><td class="menu-btn"><a href="doctors.php" class="non-style-link-menu"><p class="menu-text">Doctors</p></a></td></tr>
                <tr class="menu-row"><td class="menu-btn"><a href="schedule.php" class="non-style-link-menu"><p class="menu-text">Schedule</p></a></td></tr>
                <tr class="menu-row"><td class="menu-btn"><a href="bed.php" class="non-style-link-menu"><p class="menu-text">Bed Occupancy</p></a></td></tr>
                <tr class="menu-row"><td class="menu-btn "><a href="queing.php" class="non-style-link-menu "><p class="menu-text">Queue</p></a></td></tr>
                <tr class="menu-row"><td class="menu-btn"><a href="patient.php" class="non-style-link-menu"><p class="menu-text">Patients Health Record</p></a></td></tr>
                <tr class="menu-row">
                    <td class="menu-btn menu-active">
                        <a href="archive.php" class="non-style-link-menu non-style-link-menu-active"><p class="menu-text">Archives</p></a>
                    </td>
                </tr>
                <tr class="menu-row"><td class="menu-btn"><a href="summary.php" class="non-style-link-menu"><p class="menu-text">Summary</p></a></td></tr>
                <tr class="menu-row"><td class="menu-btn "><a href="settings.php" class="non-style-link-menu"><p class="menu-text">Settings</p></a></td></tr>
            </table>
        </div>

        <div class="dash-body">
            <table border="0" width="100%" style="border-spacing: 0;margin:0;padding:0;margin-top:25px;">
                <tr>
                    <td>
                        <form action="archive.php?view=<?php echo $view; ?>" method="post" class="header-search">
                            <input 
                                type="search" 
                                name="search" 
                                class="input-text header-searchbar" 
                                placeholder="<?php echo $view==='patients' ? 'Search Patient name or Email' : 'Search Token, Name, or Reg #'; ?>"
                                list="<?php echo $view==='patients' ? 'archive' : 'archive_queue'; ?>">
                            &nbsp;&nbsp;

                            <?php
                                if ($view === 'patients') {
                                    echo '<datalist id="archive">';
                                    if ($patientsData) {
                                        foreach ($patientsData as $key => $record) {
                                            // Only offer options for this admin’s specialty
                                            $recordSname = '';
                                            if (isset($record['patient']['sname'])) $recordSname = $record['patient']['sname'];
                                            elseif (isset($record['medical_records']['sname'])) $recordSname = $record['medical_records']['sname'];
                                            if (empty($recordSname) || strcasecmp($recordSname, $adminSpecialty) !== 0) continue;

                                            $patientData = $record['patient'] ?? [];
                                            $medicalData = $record['medical_records'] ?? [];

                                            $name  = $medicalData['patient_name'] ?? ($patientData['name'] ?? '');
                                            $email = $medicalData['email'] ?? ($patientData['email'] ?? '');

                                            if (!empty($name))  echo "<option value='".htmlspecialchars($name, ENT_QUOTES, 'UTF-8')."'>";
                                            if (!empty($email)) echo "<option value='".htmlspecialchars($email, ENT_QUOTES, 'UTF-8')."'>";
                                        }
                                    }
                                    echo '</datalist>';
                                } else {
                                    echo '<datalist id="archive_queue">';
                                    if ($queueItems) {
                                        foreach ($queueItems as $item) {
                                            if (!empty($item['token'])) echo "<option value='".htmlspecialchars($item['token'], ENT_QUOTES, 'UTF-8')."'>";
                                            if (!empty($item['name']))  echo "<option value='".htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8')."'>";
                                            if (!empty($item['reg_number'])) echo "<option value='".htmlspecialchars($item['reg_number'], ENT_QUOTES, 'UTF-8')."'>";
                                        }
                                    }
                                    echo '</datalist>';
                                }
                            ?>

                            <input type="Submit" value="Search" class="login-btn btn-primary btn" style="padding-left: 25px;padding-right: 25px;padding-top: 10px;padding-bottom: 10px; width: 15%;">
                        </form>
                    </td>

                    <td width="15%">
                        <p style="font-size: 14px;color: rgb(119, 119, 119);padding: 0;margin: 0;text-align: right;">Today's Date</p>
                        <p class="heading-sub12" style="padding: 0;margin: 0;"><?php echo $today_date_display; ?></p>
                    </td>
                    <td width="10%">
                        <button class="btn-label" style="display: flex;justify-content: center;align-items: center;">
                            <img src="../img/calendar.svg" width="100%">
                        </button>
                    </td>
                </tr>

                <tr>
                    <td colspan="4" style="padding-top:10px;">
                        <p class="heading-main12" style="margin-left: 45px;font-size:18px;color:rgb(49, 49, 49)">
                            <?php echo $view==='patients' ? 'All Archived Patients' : 'All Archived Queue'; ?>
                            (<?php 
                                    echo $view==='patients' 
                                        ? countPatientsBySpecialty($database, $adminSpecialty)
                                        : countQueueBySpecialty($database, $adminSpecialty);
                                ?>)
                        </p>
                    </td>
                </tr>

                <?php
                    // ------- Simple search behaviour (client-side filter by exact match on a few fields) -------
                    $keyword = '';
                    if ($_POST && isset($_POST['search'])) {
                        $keyword = trim((string)$_POST['search']);
                    }
                ?>

                <tr>
                    <td colspan="4">
                        <div class="view-toggle" style="margin-left: 45px; margin-bottom: 8px;">
                            <a class="toggle-link <?php echo $view==='patients'?'active':'inactive'; ?>" href="archive.php?view=patients">Patients Archive</a>
                            <a class="toggle-link <?php echo $view==='queue'?'active':'inactive'; ?>" href="archive.php?view=queue">Queue Archive</a>
                        </div>
                        <center>
                            <div class="abc scroll">
                                <table width="93%" class="sub-table scrolldown <?php echo $view==='queue' ? 'queue-table' : ''; ?>" style="border-spacing:0;">
                                    <thead>
                                    <?php if ($view === 'patients'): ?>
                                        <tr>
                                            <th class="table-headin col-name">Name</th>
                                            <th class="table-headin col-age">Age</th>
                                            <th class="table-headin col-address">Address</th>
                                            <th class="table-headin col-phone">Phone No.</th>
                                            <th class="table-headin col-email">Email</th>
                                            <th class="table-headin col-dob">Date of Birth</th>
                                            <th class="table-headin col-gender">Gender</th>
                                            <th class="table-headin col-civil">Civil Status</th>
                                            <th class="table-headin col-action">Events</th>

                                        </tr>
                                    <?php else: ?>
                                        <tr>
  <th class="table-headin q-token">Token</th>
  <th class="table-headin q-name">Name</th>
  <th class="table-headin q-priority">Priority</th>
  <th class="table-headin q-purpose">Purpose</th>
  <th class="table-headin q-date">Date</th>
  <th class="table-headin q-status">Status</th>
  <th class="table-headin q-reg">Reg #</th>
  <th class="table-headin q-dept">Department</th>
  <th class="table-headin q-archived">Archived At</th>
</tr>
                                    <?php endif; ?>
                                    </thead>
                                    <tbody>
                                    <?php
                                    $rowsRendered = 0;

                                    if ($view === 'patients') {
                                        // Retrieve all archived patients
                                        $patientsData = $database->getReference('archive')->getValue();

                                        if (is_array($patientsData) && !empty($patientsData)) {
                                            foreach ($patientsData as $pid => $patient) {
                                                // Only this admin's specialty
                                                $recordSpecialty = '';
                                                if (isset($patient['patient']['sname'])) {
                                                    $recordSpecialty = $patient['patient']['sname'];
                                                } elseif (isset($patient['medical_records']['sname'])) {
                                                    $recordSpecialty = $patient['medical_records']['sname'];
                                                }
                                                if (empty($recordSpecialty) || strcasecmp($recordSpecialty, $adminSpecialty) !== 0) {
                                                    continue;
                                                }

                                                // Use medical_records first, fallback to patient
                                                $fname   = val($patient['medical_records'] ?? [], 'patient_fname', val($patient['patient'] ?? [], 'fname'));
                                                $lname   = val($patient['medical_records'] ?? [], 'patient_lname', val($patient['patient'] ?? [], 'lname'));
                                                $name    = val($patient['medical_records'] ?? [], 'patient_name', val($patient['patient'] ?? [], 'name', trim($fname . ' ' . $lname)));
                                                $email   = val($patient['medical_records'] ?? [], 'email', val($patient['patient'] ?? [], 'email'));
                                                $gender  = val($patient['medical_records'] ?? [], 'gender', val($patient['patient'] ?? [], 'gender'));
                                                $age     = val($patient['medical_records'] ?? [], 'age', val($patient['patient'] ?? [], 'age'));
                                                $dob     = val($patient['medical_records'] ?? [], 'dob', val($patient['patient'] ?? [], 'dob'));
                                                $tele    = val($patient['medical_records'] ?? [], 'tele', val($patient['patient'] ?? [], 'tele'));
                                                $civil   = val($patient['medical_records'] ?? [], 'civil_status', val($patient['patient'] ?? [], 'civil_status'));

                                                if (isset(($patient['medical_records'] ?? [])['address'])) {
                                                    $barangay = val($patient['medical_records']['address'],'barangay',' ');
                                                    $city     = val($patient['medical_records']['address'],'city','N/A');
                                                    $province = val($patient['medical_records']['address'],'province','N/A');
                                                } else {
                                                    $barangay = val($patient['patient'] ?? [],'barangay',' ');
                                                    $city     = val($patient['patient'] ?? [],'city','N/A');
                                                    $province = val($patient['patient'] ?? [],'province','N/A');
                                                }
                                                $address = trim("$city, $province");

                                                // Simple keyword filter (optional)
                                                if ($keyword !== '') {
                                                    $cand = strtolower($name.' '.$email.' '.$tele.' '.$address);
                                                    if (strpos($cand, strtolower($keyword)) === false) continue;
                                                }

                                               echo '<tr style="text-align: center;">
        <td style="border-bottom: 1px solid #ddd;" class="col-name">
            <div class="name-email-wrap">
                <div class="name">'.htmlspecialchars(substr($name, 0, 100)).'</div>
                <div class="email">'.htmlspecialchars($email).'</div>
            </div>
        </td>

        <td style="border-bottom: 1px solid #ddd;" class="col-age">' . htmlspecialchars(substr((string)$age, 0, 2)) . '</td>
        <td style="border-bottom: 1px solid #ddd;" class="col-address">' . htmlspecialchars(substr($address, 0, 100)) . '</td>
        <td style="border-bottom: 1px solid #ddd;" class="col-phone">' . htmlspecialchars(substr($tele, 0, 20)) . '</td>

        <td style="border-bottom: 1px solid #ddd;" class="col-email">' . htmlspecialchars($email) . '</td>

        <td style="border-bottom: 1px solid #ddd;" class="col-dob">' . htmlspecialchars(substr($dob, 0, 10)) . '</td>
        <td style="border-bottom: 1px solid #ddd;" class="col-gender">' . htmlspecialchars(substr($gender, 0, 12)) . '</td>
        <td style="border-bottom: 1px solid #ddd;" class="col-civil">' . htmlspecialchars(substr($civil, 0, 20)) . '</td>

        <td style="border-bottom: 1px solid #ddd;" class="col-action">
            <div style="display:flex;justify-content:center;">
                <a href="archive.php?view=patients&action=retrieve&fname=' . urlencode($fname) . '" class="non-style-link">
                    <button class="btn-primary-soft btn btn-retrieve" style="padding-top:12px; padding-bottom:12px; margin-top:10px;">
                        <font class="tn-in-text">Retrieve</font>
                    </button>
                </a>
            </div>
        </td>
      </tr>';

                                                $rowsRendered++;
                                            }
                                        }

                                    } else {
                                        // -------- Queue Archive view --------
                                        $queueItems = $queueItems ?? fetchQueueArchiveBySpecialty($database, $adminSpecialty);

                                        foreach ($queueItems as $item) {
                                            if ($keyword !== '') {
                                                $cand = strtolower(($item['token'] ?? '').' '.($item['name'] ?? '').' '.($item['reg_number'] ?? '').' '.($item['dept'] ?? ''));
                                                if (strpos($cand, strtolower($keyword)) === false) continue;
                                            }

                                            echo '<tr style="text-align:center; vertical-align:middle;">
  <td class="q-token" style="border-bottom:1px solid #ddd;">'.htmlspecialchars(substr((string)$item['token'],0,30)).'</td>
  <td class="q-name" style="border-bottom:1px solid #ddd;">'.htmlspecialchars(substr((string)$item['name'],0,60)).'</td>
  <td class="q-priority" style="border-bottom:1px solid #ddd;">'.htmlspecialchars(substr((string)$item['priority'],0,20)).'</td>
  <td class="q-purpose" style="border-bottom:1px solid #ddd;">'.htmlspecialchars(substr((string)$item['purpose'],0,60)).'</td>
  <td class="q-date" style="border-bottom:1px solid #ddd;">'.htmlspecialchars(substr((string)$item['date'],0,16)).'</td>
  <td class="q-status" style="border-bottom:1px solid #ddd;">'.htmlspecialchars(substr((string)$item['status'],0,20)).'</td>
  <td class="q-reg" style="border-bottom:1px solid #ddd;">'.htmlspecialchars(substr((string)$item['reg_number'],0,30)).'</td>
  <td class="q-dept" style="border-bottom:1px solid #ddd;">'.htmlspecialchars(substr((string)$item['dept'],0,40)).'</td>
  <td class="q-archived" style="border-bottom:1px solid #ddd;">'.htmlspecialchars(substr((string)$item['archived_at'],0,19)).'</td>
</tr>';

                                            $rowsRendered++;
                                        }
                                    }

                                    if ($rowsRendered === 0) {
                                        echo '<tr>
                                                <td colspan="9" style="text-align:center; padding:40px 10px; color:#555;">
                                                    No Archive yet
                                                </td>
                                            </tr>';
                                    }
                                    ?>
                                    </tbody>
                                </table>
                            </div>
                        </center>
                    </td> 
                </tr>
            </table>
        </div>

        <!-- Patients retrieve modal (only used in patients view) -->
        <div id="confirmation-modal" class="modal"<?php echo $view==='patients' ? '' : ' style="display:none"'; ?>>
            <div class="modal-content">
                <span id="close-modal" class="close">&times;</span>
                <h3>Confirm Retrieval</h3>
                <p>Are you sure you want to retrieve this patient?</p>
                <button id="confirm-btn" class="login-btn btn-primary-soft btn ">Yes</button>
                <button id="cancel-btn" class="login-btn btn-primary-soft btn " style="background-color:rgb(248, 93, 93);">No</button>
            </div>
        </div>
    </div>

<script>
const hamburgerMenu = document.getElementById('hamburger-menu');
const menu = document.querySelector('.menu');
const dashBody = document.querySelector('.dash-body');
const overlay = document.getElementById('menu-overlay');

function openMenu(){
  document.body.classList.add('menu-open');
  hamburgerMenu.classList.add('active');
  menu.classList.add('active');
  dashBody.classList.add('active');
}
function closeMenu(){
  document.body.classList.remove('menu-open');
  hamburgerMenu.classList.remove('active');
  menu.classList.remove('active');
  dashBody.classList.remove('active');
}

if (hamburgerMenu) {
  hamburgerMenu.addEventListener('click', () => {
    document.body.classList.contains('menu-open') ? closeMenu() : openMenu();
  });

  hamburgerMenu.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      document.body.classList.contains('menu-open') ? closeMenu() : openMenu();
    }
  });
}

if (overlay) overlay.addEventListener('click', closeMenu);

document.querySelectorAll('.menu a').forEach(link=>{
  link.addEventListener('click', () => {
    if (window.innerWidth <= 768) closeMenu();
  });
});

document.addEventListener('DOMContentLoaded', function(){
    // Only wire modal logic in patients view
    <?php if ($view === 'patients'): ?>
    let retrieveUrl = '';
    const modal = document.getElementById('confirmation-modal');
    const confirmBtn = document.getElementById('confirm-btn');
    const cancelBtn = document.getElementById('cancel-btn');
    const closeModalSpan = document.getElementById('close-modal');

    document.querySelectorAll('.btn-retrieve').forEach(btn => {
        btn.addEventListener('click', function(e){
            e.preventDefault();
            retrieveUrl = this.closest('a').getAttribute('href');
            modal.style.display = 'block';
        });
    });

    confirmBtn.addEventListener('click', function(){
        if (retrieveUrl) window.location.href = retrieveUrl;
    });
    cancelBtn.addEventListener('click', function(){ modal.style.display = 'none'; });
    closeModalSpan.addEventListener('click', function(){ modal.style.display = 'none'; });

    window.addEventListener('click', function(event){
        if (event.target == modal) modal.style.display = 'none';
    });
    <?php endif; ?>
});
</script>
</body>
</html>
