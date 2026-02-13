<?php
session_name('sess_admin'); session_start();

if (!isset($_SESSION["user"]) || $_SESSION["user"] == "" || $_SESSION['usertype'] != 'a') {
    header("location: ../login.php");
    exit();
} else {
    $useremail = $_SESSION["user"];
}

// Import Firebase connection
include("../connection.php");

$message = "";
$searchResults = [];

$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
   if ($action === 'add') {
    // ---- Inputs ----
    $fname      = isset($_POST['fname']) ? trim($_POST['fname']) : 'N/A';
    $lname      = isset($_POST['lname']) ? trim($_POST['lname']) : 'N/A';
    $queue_type = isset($_POST['queue_type']) ? trim($_POST['queue_type']) : 'Consultation';
    $purpose    = isset($_POST['purpose']) ? $_POST['purpose'] : [];
    $priority   = isset($_POST['priority']) ? trim($_POST['priority']) : 'Regular';
    $email = isset($_POST['email']) ? strtolower(trim($_POST['email'])) : '';

      // AUTO PURPOSE FOR PAYMENT DEPTS
    $paymentDepts = ['HMO', 'Cashier', 'PhilHealth'];

    if (in_array($queue_type, $paymentDepts, true)) {
        $purpose = ['Payment']; // force purpose no matter what user sends
    }

    $purposeString = implode(', ', $purpose);

    // ---- Token / counter ----
    $today = date('ymd');
    $counterRef = $database->getReference('counters/' . $today);
    $currentCount = $counterRef->getValue();
    $order = ($currentCount) ? $currentCount + 1 : 1;
    $counterRef->set($order);
    $formatted_token = 'Q-' . $today . '-' . sprintf('%03d', $order);

// ---- Find/Create patient by (email preferred), then legacy (email+name), then fname+lname ----
$patientsRef = $database->getReference('patients');

$patient_id  = null;
$patient_uid = null;

// 1) Prefer exact email match when email is provided
if ($email !== '') {
    $byEmail = $patientsRef->orderByChild('email')->equalTo($email)->getSnapshot()->getValue() ?? [];
    if (!empty($byEmail) && is_array($byEmail)) {
        $patient_id = array_key_first($byEmail);
        $rec = $byEmail[$patient_id] ?? [];

        // hydrate existing uid or create one if missing
        $patient_uid = isset($rec['patient_uid']) && $rec['patient_uid'] !== '' ? $rec['patient_uid'] : null;
        if (!$patient_uid) {
            $patient_uid = 'P-' . uniqid();
            $database->getReference("patients/{$patient_id}/patient_uid")->set($patient_uid);
        }

        // fill missing fname/lname/name if needed
        $needNameUpdate = false;
        $newPatch = [];
        if (empty(trim((string)($rec['fname'] ?? '')))) { $newPatch['fname'] = $fname; $needNameUpdate = true; }
        if (empty(trim((string)($rec['lname'] ?? '')))) { $newPatch['lname'] = $lname; $needNameUpdate = true; }
        $fullName = trim(($newPatch['fname'] ?? $rec['fname'] ?? '') . ' ' . ($newPatch['lname'] ?? $rec['lname'] ?? ''));
        if ($fullName !== '' && empty(trim((string)($rec['name'] ?? '')))) { $newPatch['name'] = $fullName; $needNameUpdate = true; }
        if ($needNameUpdate) {
            $database->getReference("patients/{$patient_id}")->update($newPatch);
        }
    }
}

// 2) If still not found but we have email, try legacy rows that may have wrong indexing
if ($patient_id === null && $email !== '') {
    $all = $patientsRef->getSnapshot()->getValue() ?? [];
    if ($all) {
        foreach ($all as $pid => $p) {
            $pEmail = strtolower(trim((string)($p['email'] ?? '')));
            $pF     = trim((string)($p['fname'] ?? ''));
            $pL     = trim((string)($p['lname'] ?? ''));
            if ($pEmail === $email && strcasecmp($pF, $fname) === 0 && strcasecmp($pL, $lname) === 0) {
                $patient_id = $pid;
                $patient_uid = isset($p['patient_uid']) && $p['patient_uid'] !== '' ? $p['patient_uid'] : null;
                if (!$patient_uid) {
                    $patient_uid = 'P-' . uniqid();
                    $database->getReference("patients/{$patient_id}/patient_uid")->set($patient_uid);
                }
                break;
            }
        }
    }
}

// 3) If no email or still not found, fall back to fname+lname exact match
if ($patient_id === null) {
    $matches = $patientsRef->orderByChild('fname')->equalTo($fname)->getSnapshot()->getValue();
    if (!empty($matches)) {
        foreach ($matches as $pid => $p) {
            $plname = isset($p['lname']) ? trim($p['lname']) : '';
            if (strcasecmp($plname, $lname) === 0) {
                $patient_id  = $pid;
                $patient_uid = isset($p['patient_uid']) && $p['patient_uid'] !== '' ? $p['patient_uid'] : null;

                // attach email if missing and provided
                if ($email !== '' && empty(trim((string)($p['email'] ?? '')))) {
                    $database->getReference("patients/{$patient_id}/email")->set($email);
                }
                if (!$patient_uid) {
                    $patient_uid = 'P-' . uniqid();
                    $database->getReference("patients/{$patient_id}/patient_uid")->set($patient_uid);
                }
                break;
            }
        }
    }
}

// 4) Still not found? Create a new patient node (store fname/lname/name/email)
if ($patient_id === null) {
    $patient_uid = 'P-' . uniqid();
    $newPatient = [
        'fname'         => $fname,
        'lname'         => $lname,
        'name'          => trim($fname . ' ' . $lname),
        'email'         => $email,          // may be ''
        'patient_uid'   => $patient_uid,
        'date_created'  => date('Y-m-d'),
    ];
    $patient_id = $patientsRef->push($newPatient)->getKey();
}

  // ---- Queue payload  ----
$patientData = [
    'token_number'    => $order,
    'formatted_token' => $formatted_token,
    'fname'           => $fname,
    'lname'           => $lname,
    'name'            => trim($fname . ' ' . $lname),   
    'email'           => $email,                        
    'patient_uid'     => $patient_uid,
    'reg_number'      => uniqid(),
    'date'            => date('Y-m-d H:i'),
    'type'            => $queue_type,
    'queue_status'    => 'Waiting',
    'purpose'         => $purposeString,
    'priority'        => $priority
];

    // ---- Log entry ----
    $useremail = isset($_SESSION["user"]) ? $_SESSION["user"] : 'unknown';
    $logData = [
        'timestamp'       => date('H:i:s'),
        'datestamp'       => date('Y-m-d '),
        'status'          => 'Added to queue',
        'fname'           => $fname,
        'lname'           => $lname,
        'patient_id'      => $patient_id,
        'patient_uid'     => $patient_uid,
        'queue_type'      => $queue_type,
        'formatted_token' => $formatted_token,
        'email'           => $useremail,
        'priority'        => $priority
    ];

    // ---- Patient queue history append ----
    $queueHistory = [
        'timestamp'       => date('H:i:s'),
        'datestamp'       => date('Y-m-d '),
        'queue_type'      => $queue_type,
        'formatted_token' => $formatted_token,
        'priority'        => $priority
    ];

    try {
        // Push queue item under department
        $database->getReference('queue/' . $queue_type)->push($patientData);

        // Append queue history under the patient
        $database->getReference('patients/'.$patient_id.'/queue_history')->push($queueHistory);

        // Write log
        $database->getReference('logs')->push($logData);

        $message = "Successfully added to queue!";
        header("Location: queing.php");
        exit();
    } catch (Exception $e) {
        $message = "Failed to add to queue. Try again.";
    }
}

    if ($action === 'search') {
        $keyword = trim($_POST["search"]);
        $patientsData = $database->getReference('queue')->getValue();

        $adminSpecialty = isset($_SESSION['specialty']) ? $_SESSION['specialty'] : '';
        if ($patientsData && isset($patientsData[ucfirst($adminSpecialty)])) {
            $deptPatients = $patientsData[ucfirst($adminSpecialty)];
        } else {
            $deptPatients = [];
            if ($patientsData && is_array($patientsData)) {
                foreach ($patientsData as $department => $patients) {
                    $normalizedDept = strtolower(str_replace(' ', '_', trim($department)));
                    if ($normalizedDept === $adminSpecialty) {
                        $deptPatients = $patients;
                        break;
                    }
                }
            }
        }

        $searchResults = [];
        if (!empty($deptPatients) && is_array($deptPatients)) {
            foreach ($deptPatients as $patientId => $patient) {
                $archived = isset($patient['archived']) ? $patient['archived'] : 0;
                if ($archived == 0) {
                    if (stripos($patient['name'], $keyword) !== false ||
                        (isset($patient['email']) && stripos($patient['email'], $keyword) !== false)) {
                        $patient['department'] = ucfirst($adminSpecialty);
                        $patient['id'] = $patientId;
                        $searchResults[] = $patient;
                    }
                }
            }
        }
    }
}

// ---------------------
// Retrieve Admin Data to Set Specialty
// ---------------------
try {
    $reference = $database->getReference('admin')->orderByChild('email')->equalTo($useremail)->getSnapshot();
    $userfetch = $reference->getValue();
    if ($userfetch) {
        foreach ($userfetch as $key => $value) {
            $username = $value['name'];
            if (isset($value['sname'])) {
                $specialty = is_array($value['sname']) ? implode(", ", $value['sname']) : $value['sname'];
            } else {
                $specialty = 'N/A';
            }
            $admin2 = $specialty;
            $adminSpecialty = strtolower(str_replace(' ', '_', trim($specialty)));
            $_SESSION['specialty'] = $adminSpecialty;
        }
    } else {
        echo "No user found.";
    }
} catch (\Kreait\Firebase\Exception\DatabaseException $e) {
    echo "Error querying the database: " . $e->getMessage();
}

// Retrieve specialties data from Firebase
$specialtiesSnapshot = $database->getReference('specialties')->getSnapshot();
$specialtiesData = $specialtiesSnapshot->getValue();

$allowedQueueTypes = [
    "Industrial Clinic",
    "Clinical Laboratory",
    "Radiology",
    "Cardio Pulmonology",
    "Blood Bank",
    "Molecular Pathology Laboratory",
    "Physical Therapy",
    "Rehabilitation Medicine Specialist",
    "Rehabilitation Medicine Specialist",
    "Pharmacy",
    "HMO",
    "Cashier",
    "PhilHealth"
];

// helper: normalize for matching (lowercase, trim, remove extra symbols)
function normalizeDeptName($s) {
    $s = strtolower(trim((string)$s));
    $s = preg_replace('/\s+/', ' ', $s);
    $s = preg_replace('/[^\p{L}\p{N}\s]/u', '', $s);
    return $s;
}

// Create an indexed array from the associative array and sort it by 'sname'
if (!empty($specialtiesData) && is_array($specialtiesData)) {
    $specialtiesSorted = array_values($specialtiesData);
    usort($specialtiesSorted, function($a, $b) {
        return strcasecmp($a['sname'], $b['sname']);
    });
} else {
    $specialtiesSorted = [];
}

$allowedNormalized = array_map('normalizeDeptName', $allowedQueueTypes);

$specialtiesFiltered = array_values(array_filter($specialtiesSorted, function($spec) use ($allowedNormalized){
    $name = normalizeDeptName($spec['sname'] ?? '');
    return in_array($name, $allowedNormalized, true);
}));

// Retrieve admin data from Firebase 
$adminRef = $database->getReference('admin')->orderByChild('email')->equalTo($useremail);
$adminData = $adminRef->getValue();

if ($adminData) {
    $adminData = array_shift($adminData); 
    $username = isset($adminData['name']) ? $adminData['name'] : null;
} 
$photo = '../img/user.png'; 
if ($adminData) {
    $admin = reset($adminData); 
    if (!empty($admin['photo'])) {
        $photo = $admin['photo']; 
    }
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
    
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">

    <title>Laboratory Queuing</title>
<style>
/* =========================
   OVERLAYS / MODALS
   ========================= */
.overlay{
  display:none;
  position:fixed;
  top:0; left:0;
  width:100%; height:100%;
  background-color:rgba(0,0,0,0.7);
  justify-content:center;
  align-items:center;
  z-index:1000;
}
.overlay.active{ display:flex; }

.overlay-content{
  background-color:#fff;
  padding:30px;
  border-radius:10px;
  width:400px;
  max-width:90%;
  position:relative;
  text-align:left;
  max-height:85vh;
  overflow-y:auto;
}

.close-btn{
  position:absolute;
  top:10px;
  right:15px;
  font-size:24px;
  background:none;
  border:none;
  cursor:pointer;
}
.close-btn:hover{ color:red; }

/* Labels */
label{
  font-weight:bold;
  display:block;
  margin-bottom:5px;
  color:#333;
}

/* Input Fields */
input[type="text"], select{
  width:100%;
  padding:10px;
  margin-bottom:15px;
  border:1px solid #ccc;
  border-radius:5px;
  box-sizing:border-box;
  font-size:14px;
}

select#priority{
  width:50%;
  height:auto;
  padding:10px;
  font-size:14px;
  border:1px solid #ccc;
  border-radius:5px;
  box-sizing:border-box;
}

/* Purpose Checkboxes */
.checkbox-group{
  display:flex;
  flex-direction:column;
  gap:5px;
  margin-bottom:15px;
}
.checkbox-group label{
  display:flex;
  align-items:center;
  gap:10px;
  font-size:14px;
}
.checkbox-group input{
  width:16px;
  height:16px;
}

/* Unique Overlay Background */
#uniqueOverlay, #editPatientOverlay{
  display:none;
  position:fixed;
  top:0; left:0;
  width:100%; height:100%;
  background-color:rgba(0,0,0,0.7);
  z-index:9999;
  justify-content:center;
  align-items:center;
}

#uniqueOverlay .btn, #editPatientOverlay .btn, #transferOverlay .btn{
  background-color:#4caf50;
  color:#fff;
  border:none;
  padding:10px 20px;
  font-size:14px;
  border-radius:5px;
  cursor:pointer;
  transition:background-color 0.3s ease-in-out;
  width:100%;
}
#uniqueOverlay .btn:hover, #editPatientOverlay .btn:hover{
  background-color:#388e3c;
}

#uniqueOverlay .close-btn, #editPatientOverlay .close-btn{
  background:none;
  border:none;
  font-size:24px;
  font-weight:bold;
  color:#000;
  cursor:pointer;
  position:absolute;
  top:10px;
  right:20px;
  padding:0;
}
#uniqueOverlay .close-btn:hover, #editPatientOverlay .close-btn:hover{
  color:#f44336;
}

/* =========================
   MENU / BUTTONS
   ========================= */
.menu-btn a{
  display:block;
  text-decoration:none;
  padding:1px;
  width:100%;
}
.menu-btn:hover{ background-color:#E8F8E8; }

.btn-action{
  background:none;
  border:1px solid transparent;
  padding:5px 10px;
  border-radius:3px;
  font-size:14px;
  color:inherit;
}
.btn-action i{ background:none; }

.action-buttons{
  display:flex;
  justify-content:center;
  gap:6px;
  background:none;
  flex-wrap:wrap;
}

/* =========================
   PURPOSE CHIPS + MODAL
   ========================= */
.purpose-cell{
  max-width:260px;
  text-align:center;
  vertical-align:middle;
}
.purpose-chips{
  display:flex;
  flex-wrap:wrap;            /* ✅ allow next line instead of warping/cutting */
  gap:6px;
  justify-content:center;
  align-items:center;
  overflow:visible;
  max-height:none;
}
.purpose-chip{
  background:#eaf7ee;
  border:1px solid #0abf58;
  color:#0a7a3a;
  font-size:12px;
  padding:3px 8px;
  border-radius:999px;
  white-space:normal;        /* ✅ wrap if long */
  word-break:break-word;
}
.purpose-more{
  display:inline-block;
  margin-top:6px;
  font-size:12px;
  color:#0abf58;
  cursor:pointer;
  font-weight:600;
}

.purpose-modal{
  position:fixed;
  inset:0;
  background:rgba(0,0,0,0.5);
  display:none;
  align-items:center;
  justify-content:center;
  z-index:99999;
}
.purpose-modal.active{ display:flex; }

.purpose-modal-content{
  width:420px;
  max-width:92vw;
  background:#fff;
  border-radius:12px;
  padding:18px 20px;
  max-height:70vh;
  overflow-y:auto;
}
.purpose-modal-content h3{
  margin:0 0 10px 0;
  font-size:16px;
}
.purpose-list{
  margin:0;
  padding-left:18px;
  font-size:14px;
  line-height:1.6;
}

/* =========================
   TABLE (NO CUTTING, WRAP ON OVERFLOW)
   ========================= */
.sub-table{
  width:100% !important;
  border-spacing:0 !important;
  border-collapse:collapse !important;
  table-layout:auto !important;     /* ✅ prevents column crushing/warping */
}
.sub-table th,
.sub-table td{
  padding:12px 10px;
  vertical-align:top !important;
  white-space:normal !important;    /* ✅ go next line */
  overflow:visible !important;      /* ✅ show full */
  text-overflow:unset !important;   /* ✅ no ellipsis */
  word-break:break-word !important; /* ✅ break long strings */
}

/* =========================
   MOBILE VIEW ONLY
   ========================= */
.mobile-header{ display:none; }
#menu-overlay{ display:none; }

@media (max-width:768px){

  /* fixed header */
  .mobile-header{
    display:flex;
    position:fixed;
    top:0; left:0; right:0;
    height:56px;
    background:lightgreen;
    z-index:1200;
    align-items:center;
    justify-content:space-between;
    padding:0 10px;
    box-shadow:0 2px 10px rgba(0,0,0,0.12);
  }
  .mobile-left{ width:44px; display:flex; align-items:center; }
  .mobile-center{
    flex:1;
    text-align:center;
    font-weight:700;
    font-size:16px;
    color:#000;
  }
  .mobile-right{ display:flex; align-items:center; gap:10px; }
  .mobile-date{
    font-size:13px;
    font-weight:600;
    color:#000;
    white-space:nowrap;
  }
  .mobile-calendar-btn{
    background:rgba(255, 255, 255, 0);
    border:none;
    width:34px;
    height:34px;
    border-radius:8px;
    display:flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
  }
  .mobile-calendar-btn img{ width:18px; height:18px; }

  /* hamburger */
             #hamburger-menu{
                display:flex !important;
                width:34px !important;
                height:34px !important;
                padding:8px !important;
                background:rgba(255, 255, 255, 0.48) !important;
                border-radius:10px !important;
                flex-direction:column;
                justify-content:center;
                gap:5px;
            }
             #hamburger-menu .bar{
    height:2px !important;
    width:100% !important;
    background:#111 !important;
    border-radius:2px;
    transition:.25s;
  }
  #hamburger-menu.active .bar:nth-child(1){ transform:rotate(-45deg) translate(-4px, 5px); }
  #hamburger-menu.active .bar:nth-child(2){ opacity:0; }
  #hamburger-menu.active .bar:nth-child(3){ transform:rotate(45deg) translate(-4px, -5px); }

  /* overlay */
  #menu-overlay{
    display:none;
    position:fixed;
    inset:0;
    background:rgba(0,0,0,0.45);
    z-index:1100;
  }
  body.menu-open #menu-overlay{ display:block; }

  /* drawer */
  .menu{
    position:fixed;
    top:56px;
    left:0;
    width:270px;
    height:calc(100vh - 56px);
    background:lightgreen;
    transform:translateX(-100%);
    transition:transform .25s ease;
    z-index:1150;
    overflow-y:auto;
    -webkit-overflow-scrolling:touch;
    display:block;
  }
  body.menu-open .menu{ transform:translateX(0); }
  body.menu-open{ overflow:hidden; }

  /* content full width */
  .dash-body{
    margin-top:66px !important;
    margin-left:0 !important;
    padding:12px !important;
    width:100% !important;
  }

  /* hide desktop date + calendar cells */
  td[width="15%"],
  td[width="10%"],
  .btn-label{
    display:none !important;
  }

  /* Search becomes full width */
  .header-search{
    width:100% !important;
    display:flex !important;
    flex-direction:column !important;
    gap:10px !important;
    padding:0 !important;
    margin:0 !important;
  }
  .header-searchbar{
    width:100% !important;
    max-width:100% !important;
  }
  .header-search input[type="submit"]{ width:100% !important; }

  /* Remove huge padding-top row */
  td[colspan="2"][style*="padding-top: 70px"]{ padding-top:10px !important; }

  /* Fix Add New + Queue Window row */
  td[colspan="2"] > div[style*="margin-left: -250px"]{
    margin-left:0 !important;
    width:100% !important;
    justify-content:flex-start !important;
    flex-wrap:wrap;
  }
  #addNewButton, #openQueueButton{ width:100% !important; }

  /* keep wrapper from adding extra top margin */
  .dash-body table[width="100%"]{ margin-top:0 !important; }

  /* table wrapper for horizontal scroll if needed */
  .table-wrap{
    width:100% !important;
    overflow-x:auto !important;
    overflow-y:hidden !important;
    -webkit-overflow-scrolling:touch;
    margin:12px 0 28px;
    border-radius:10px;
  }

  /* optional minimum width so columns stay readable */
  .sub-table{
    min-width:900px;
  }

  /* sticky header on mobile */
  .sub-table thead th{
    position:sticky !important;
    top:0 !important;
    z-index:5 !important;
    background:#fff !important;
  }
}

/* =========================
   FIX ACTION COLUMN WRAP
   ========================= */

/* Keep Action header on ONE line */
.sub-table th:last-child{
  white-space: nowrap !important;
  text-align: center;
}

/* Keep Action cell content on ONE line */
.sub-table td.col-action{
  white-space: nowrap !important;
  text-align: center;
  vertical-align: middle !important;
}

/* Force buttons to stay side-by-side */
.sub-table td.col-action .action-buttons{
  display: flex !important;
  flex-direction: row !important;
  flex-wrap: nowrap !important;     
  gap: 6px !important;
  justify-content: center !important;
  align-items: center !important;
}

/* Prevent buttons themselves from breaking */
.sub-table td.col-action .btn-action{
  white-space: nowrap !important;
  flex-shrink: 0 !important;
}

/* Give Action column a stable width */
.sub-table col:last-child{
  width: 90px !important;
  min-width: 90px !important;
}

/* =========================
   FIX ADD NEW + QUEUE WINDOW ALIGNMENT
   ========================= */

/* Button container */
.queue-actions{
  display: flex !important;
  justify-content: flex-end !important;  /* move to left */
  align-items: center !important;
  gap: 10px;
  padding: 20px 150px 0 0;                      /* ⬅ move slightly left */
}

/* Desktop fine-tune */
@media (min-width: 769px){
  .queue-actions{
    padding-left: 30px;                    /* adjust if needed */
  }
}

/* Mobile view */
@media (max-width: 768px){
  .queue-actions{
    padding-left: 10px;
  }
}

/* =========================
   MOBILE BUTTON FIXES
   ========================= */
@media (max-width: 768px){

  /* Hide Queue Window button */
  #openQueueButton{
    display: none !important;
  }

  /* Hide Search button */
  .header-search input[type="submit"]{
    display: none !important;
  }

  /* Move Add New button to the LEFT */
  .queue-actions{
    justify-content: flex-start !important;
    padding-left: 12px !important;
  }

  /* Make Add New button smaller */
  #addNewButton{
    width: auto !important;
    min-width: unset !important;
    padding: 0 10px 0 40px !important;
    font-size: 13px !important;
    height: 36px !important;
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
    <div id="menu-overlay"></div>

   <div class="mobile-header">
  <div class="mobile-left">
    <div id="hamburger-menu">
      <div class="bar"></div>
      <div class="bar"></div>
      <div class="bar"></div>
    </div>
  </div>

  <div class="mobile-center">Queue</div>

  <div class="mobile-right">
    <span class="mobile-date"><?php echo date('Y-m-d'); ?></span>
    <button class="mobile-calendar-btn" type="button">
      <img src="../img/calendar.svg" alt="calendar">
    </button>
  </div>
</div>

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
                                <a href="../logout.php" ><input type="button" value="Log out" class="logout-btn btn-primary-soft btn"></a>
                                </td>
                            </tr>
                    </table>
                    </td>
                </tr>
                <?php
$specialty = $_SESSION['specialty'] ?? '';

if ($specialty === 'hmo' || $specialty === 'cashier' || $specialty === 'blood bank' || $specialty === 'philhealth') {
    ?>
    <tr class="menu-row">
        <td class="menu-btn menu-active">
            <a href="queing.php" class="non-style-link-menu non-style-link-menu-active">
                <p class="menu-text">Queue</p>
            </a>
        </td>
    </tr>
    <?php
} else {
    ?>
    <tr class="menu-row">
        <td class="menu-btn">
            <a href="index.php" class="non-style-link-menu">
                <p class="menu-text">Dashboard</p>
            </a>
        </td>
    </tr>
    <tr class="menu-row">
        <td class="menu-btn">
            <a href="doctors.php" class="non-style-link-menu">
                <p class="menu-text">Doctors</p>
            </a>
        </td>
    </tr>
    <tr class="menu-row">
        <td class="menu-btn">
            <a href="schedule.php" class="non-style-link-menu">
                <p class="menu-text">Schedule</p>
            </a>
        </td>
    </tr>
    <tr class="menu-row">
        <td class="menu-btn">
            <a href="bed.php" class="non-style-link-menu">
                <p class="menu-text">Bed Occupancy</p>
            </a>
        </td>
    </tr>
    <tr class="menu-row">
        <td class="menu-btn menu-active">
            <a href="queing.php" class="non-style-link-menu non-style-link-menu-active">
                <p class="menu-text">Queue</p>
            </a>
        </td>
    </tr>
    <tr class="menu-row">
        <td class="menu-btn">
            <a href="patient.php" class="non-style-link-menu">
                <p class="menu-text">Patients Health Record</p>
            </a>
        </td>
    </tr>
    <tr class="menu-row">
        <td class="menu-btn">
            <a href="archive.php" class="non-style-link-menu">
                <p class="menu-text">Archives</p>
            </a>
        </td>
    </tr>
    <tr class="menu-row">
        <td class="menu-btn">
            <a href="summary.php" class="non-style-link-menu">
                <p class="menu-text">Summary</p>
            </a>
        </td>
    </tr>
    <tr class="menu-row">
        <td class="menu-btn ">
            <a href="settings.php" class="non-style-link-menu">
                <p class="menu-text">Settings</p>
            </a>
        </td>
    </tr> 
    <?php
}
?>
            </table>
        </div>

        <div class="dash-body">
            <table border="0" width="100%" style="border-spacing: 0; margin: 0; padding: 0; margin-top: 25px;">
                <tr>
                    <td>
                    <form action="" method="post" class="header-search">
                        <input type="hidden" name="action" value="search">
                        <input type="search" name="search" class="input-text header-searchbar" placeholder="Search Patient name or Email" list="patient">&nbsp;&nbsp;
                        <?php
                           $adminSpecialty = $_SESSION['specialty'];
                           $queueReference = $database->getReference('queue');
                           $queueSnapshot = $queueReference->getSnapshot();
                           if ($queueSnapshot->exists()) {
                               $queueData = $queueSnapshot->getValue();
                               echo '<datalist id="patient">';
                               $namesUsed = [];
                               foreach ($queueData as $department => $deptPatients) {
                                   $normalizedDept = strtolower(str_replace(' ', '_', trim($department)));
                                   if ($normalizedDept === $adminSpecialty && is_array($deptPatients)) {
                                       foreach ($deptPatients as $patient) {
                                           if (isset($patient['name'])) {
                                               $patientName = trim($patient['name']);
                                               $normalizedName = strtolower($patientName);
                                               if (!in_array($normalizedName, $namesUsed)) {
                                                   echo "<option value='" . htmlspecialchars($patientName, ENT_QUOTES, 'UTF-8') . "'>";
                                                   $namesUsed[] = $normalizedName;
                                               }
                                           }
                                       }
                                   }
                               }
                               echo '</datalist>';
                           }
                        ?>
                        <input type="submit" value="Search" class="login-btn btn-primary btn" style="padding: 10px 25px;">
                    </form>
                    </td>
                    <td width="15%">
                        <p style="font-size: 14px;color: rgb(119, 119, 119);padding: 0;margin: 0;text-align: right;">
                            Today's Date
                        </p>
                        <p class="heading-sub12" style="padding: 0; margin: 0;">
                            <?php
                                 date_default_timezone_set('Asia/Manila');
                                 $date = date('Y-m-d');
                                 echo $date;
                            ?>
                        </p>
                    </td>
                    <td width="10%">
                        <button class="btn-label" style="display: flex; justify-content: center; align-items: center;"><img src="../img/calendar.svg" width="100%"></button>
                    </td>
                </tr>

                <tr>
  <td colspan="4" style="padding: 0;">
    <div class="queue-actions">
      <?php if ($_SESSION['specialty'] == 'information'): ?>
        <button type="button" class="login-btn btn-primary btn button-icon" id="addNewButton"
          style="display:flex; justify-content:center; align-items:center; background-image:url('../img/icons/add.svg');">
          Add New
        </button>
      <?php endif; ?>

      <button type="button" class="login-btn btn-primary btn" id="openQueueButton"
        style="display:flex; justify-content:center; align-items:center;"
        onclick="window.open('tv_display.php', '_blank')">
        Queue Window
      </button>
    </div>
  </td>
</tr>


                <!-- Single Overlay -->
                <div class="overlay" id="uniqueOverlay">
                  <div class="overlay-content">
                    <button class="close-btn" id="closeUniqueOverlay">&times;</button>
                    <h2 style="margin-bottom: 20px;">Add New Patient</h2>

                    <form action="queing.php" method="POST" onsubmit="return confirmAddPatient();">
                    <input type="hidden" name="action" value="add">
                    <label for="fname">First Name:</label>
                    <input type="text" id="fname" name="fname" placeholder="Enter First Name" required>

                    <label for="lname">Last Name:</label>
                    <input type="text" id="lname" name="lname" placeholder="Enter Last Name" required>

                    <label for="email">Email (optional but recommended):</label>
                    <input type="text" id="email" name="email" placeholder="Enter Email (e.g., juan@email.com)">

                    <label for="queue_type">Assign to:</label>
                    <?php if (isset($specialty) && strcasecmp($specialty, "Information") !== 0): ?>
                    <select id="queue_type_display" disabled>
                        <option value="<?php echo htmlspecialchars($specialty, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars($specialty, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    </select>
                    <input type="hidden" id="queue_type" name="queue_type" 
                            value="<?php echo htmlspecialchars($specialty, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php else: ?>
                    <select id="queue_type" name="queue_type" required onchange="togglePurposeSections()">
                        <option value="" disabled selected>Select Queue Type</option>

                        <?php if (!empty($specialtiesFiltered)): ?>
                            <?php foreach ($specialtiesFiltered as $spec): 
                                $sname = trim($spec['sname'] ?? '');
                            ?>
                                <option value="<?php echo htmlspecialchars($sname, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars($sname, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option value="">No allowed specialties found</option>
                        <?php endif; ?>
                    </select>
                    <?php endif; ?>

                    <label for="priority">Priority:</label>
                    <select id="priority" name="priority" placeholder="Select Priority" required>
                        <option value="" disabled selected>Select Priority</option>
                        <option value="PWD">PWD</option>
                        <option value="Senior">Senior</option>
                        <option value="Emergency">Emergency</option>
                        <option value="Regular">Regular</option>
                    </select>

                    <!-- Industrial Clinic Section -->
                    <div id="industrialClinicSection" class="purpose-section">
                        <label>Industrial Clinic Services:</label>
                        <div class="checkbox-group">
                            <label><input type="checkbox" name="purpose[]" value="Urinalysis"> Urinalysis</label>
                            <label><input type="checkbox" name="purpose[]" value="Fecalysis"> Fecal Analysis</label>
                            <label><input type="checkbox" name="purpose[]" value="Blood Tests"> Blood Tests</label>
                            <label><input type="checkbox" name="purpose[]" value="B5 + Hepa">Basic 5 + Hepa</label>
                            <label><input type="checkbox" name="purpose[]" value="B5 + DT">Basic 5 + DT</label>
                            <label><input type="checkbox" name="purpose[]" value="B5 + PT">Basic 5 + PT</label>
                            <label><input type="checkbox" name="purpose[]" value="B5 + HEPA B + DT">Basic 5 + HEPA B + DT</label>
                            <label><input type="checkbox" name="purpose[]" value="B5 + HEPA B + PT">Basic 5 + HEPA B + PT</label>
                            <label><input type="checkbox" name="purpose[]" value="B5 + PT + DT">Basic 5 + PT + DT</label>
                            <label><input type="checkbox" name="purpose[]" value="B5 +  WIDAL'S TEST + HEPA B">Basic 5 + WIDAL'S TEST + HEPA B</label>
                            <label><input type="checkbox" name="purpose[]" value="B5 + WIDAL'S TEST + HEPA B + DT">Basic 5 + WIDAL'S TEST + HEPA B + DT</label>
                            <label><input type="checkbox" name="purpose[]" value="B5 + Blood Typing">Basic 5 + BLOOD TYPING</label>
                            <label><input type="checkbox" name="purpose[]" value="B5 + Blood Typing + HEPA B">Basic 5 + BLOOD TYPING + HEPA B</label>
                            <label><input type="checkbox" name="purpose[]" value="B5 + Blood Typing + DT">Basic 5 + BLOOD TYPING + DT</label>
                            <label><input type="checkbox" name="purpose[]" value="B5 + ECG">Basic 5 + ECG</label>
                            <label><input type="checkbox" name="purpose[]" value="B5 + ECG + HEPA B">Basic 5 + ECG + HEPA B</label>
                            <label><input type="checkbox" name="purpose[]" value="B5 + ECG + DT">Basic 5 + ECG + DT</label>
                            <label><input type="checkbox" name="purpose[]" value="B5 + DT + HEPA B + ECG">Basic 5 + DT + HEPA B + ECG</label>
                        </div>
                    </div>

                    <!-- Radiology Section -->
                    <div id="radiologySection" class="purpose-section">
                        <label>Radiology Services:</label>
                        <div class="checkbox-group">
                            <label><input type="checkbox" name="purpose[]" value="X-ray"> X-ray</label>
                            <label><input type="checkbox" name="purpose[]" value="Ultrasound"> Ultrasound</label>
                            <label><input type="checkbox" name="purpose[]" value="MRI"> MRI</label>
                            <label><input type="checkbox" name="purpose[]" value="CT Scan"> CT Scan</label>
                        </div>
                    </div>

                    <!-- Cardio Pulmonology Section -->
                    <div id="cardioPulmonologySection" class="purpose-section">
                        <label>Cardio Pulmonology Services:</label>
                        <div class="checkbox-group">
                            <label><input type="checkbox" name="purpose[]" value="ECG"> ECG</label>
                            <label><input type="checkbox" name="purpose[]" value="2D Echo"> 2D Echo</label>
                            <label><input type="checkbox" name="purpose[]" value="Pulmonary Function Test"> Pulmonary Function Test</label>
                        </div>
                    </div>

                    <!-- Physical Therapy Section -->
                    <div id="physicalTherapySection" class="purpose-section">
                        <label>Physical Therapy & Rehabilitation Services:</label>
                        <div class="checkbox-group">
                            <label><input type="checkbox" name="purpose[]" value="Physical Therapy"> Physical Therapy</label>
                            <label><input type="checkbox" name="purpose[]" value="Occupational Therapy"> Occupational Therapy</label>
                            <label><input type="checkbox" name="purpose[]" value="Rehabilitation Medicine"> Rehabilitation Medicine</label>
                        </div>
                    </div>

                    <!-- Obstetrics and Gynecology Section -->
                    <div id="obgynSection" class="purpose-section">
                        <label>Obstetrics and Gynecology Services:</label>
                        <div class="checkbox-group">
                            <label><input type="checkbox" name="purpose[]" value="Prenatal Check-up"> Prenatal Check-up</label>
                            <label><input type="checkbox" name="purpose[]" value="Gynecological Consultation"> Gynecological Consultation</label>
                            <label><input type="checkbox" name="purpose[]" value="Family Planning"> Family Planning</label>
                            <label><input type="checkbox" name="purpose[]" value="Menstrual Disorder Evaluation"> Menstrual Disorder Evaluation</label>
                        </div>
                    </div>

                    <!-- Rehabilitation Medicine Specialist Section -->
                    <div id="rehabSpecialistSection" class="purpose-section">
                        <label>Physical Therapy and Rehabilitation Medicine:</label>
                        <div class="checkbox-group">
                            <label><input type="checkbox" name="purpose[]" value="Rehabilitation Consultation"> Rehabilitation Consultation</label>
                            <label><input type="checkbox" name="purpose[]" value="Chronic Pain Management"> Chronic Pain Management</label>
                            <label><input type="checkbox" name="purpose[]" value="Disability Assessment"> Disability Assessment</label>
                        </div>
                    </div>

                    <button type="submit" class="btn">Submit</button>
                    </form>
                  </div>
                </div>

<tr>
    <td colspan="4" >
        <center>
        <div class="abc scroll" style=" width: 100%;  max-width: 1200px; height: auto; min-height: 500px;  overflow-y: auto;">
            <?php
                $patientsData = $database->getReference('queue')->getValue();

                if (!is_array($patientsData)) {
                    $patientsData = [];
                }

                $inProgressPatients = [];
                if (!empty($patientsData)) {
                    foreach ($patientsData as $dept => $queuePatients) {
                        if (is_array($queuePatients)) {
                            foreach ($queuePatients as $id => $patient) {
                                if (isset($patient['queue_status']) && $patient['queue_status'] === 'In Progress') {
                                    if (!isset($inProgressPatients[$dept])) {
                                        $inProgressPatients[$dept] = $id;
                                    }
                                }
                            }
                        }
                    }
                }

                function sortQueue(&$queue) {
                    if (!empty($queue) && is_array($queue)) {
                        uasort($queue, function($a, $b) {
                            return $a['token_number'] <=> $b['token_number'];
                        });
                    }
                }

                // ==========================
                //  displayTable()
                // ==========================
               function displayTable($patients, $title, $prefix, $inProgressPatients, $deptName) {

    echo "<h2 style='text-align: center; margin-top: 50px;'>".htmlspecialchars($title)."</h2>";

    echo "<div class='table-wrap'>";

    echo '<table width="100%" class="sub-table scrolldown"
        style="border-spacing:0; border: 1px solid #0abf58; margin-bottom: 100px;">
  <colgroup>
    <col style="width:14%;">  <!-- Token -->
    <col style="width:16%;">  <!-- Name -->
    <col style="width:18%;">  <!-- Reg -->
    <col style="width:14%;">  <!-- Date -->
    <col style="width:17%;">  <!-- Purpose -->
    <col style="width:10%;">  <!-- Status -->
    <col style="width:13%;">   <!-- Priority -->
    <col style="width:12%;">   <!-- Action -->
  </colgroup>
  <thead>
    <tr>
      <th class="table-headin">Token Number</th>
      <th class="table-headin">Name</th>
      <th class="table-headin">Registration Number</th>
      <th class="table-headin">Date/Time</th>
      <th class="table-headin">Purpose</th>
      <th class="table-headin">Queue Status</th>
      <th class="table-headin">Priority</th>
      <th class="table-headin">Action</th>
    </tr>
  </thead>
  <tbody>';

    if (empty($patients)) {
        echo '<tr>
                <td colspan="8">
                    <center>
                        <img src="../img/notfound.svg" width="25%">
                        <p class="heading-main12">No queue data found!</p>
                    </center>
                </td>
            </tr>';

        echo '</tbody></table></div>'; // ✅ close table + table-wrap
        return;
    }

    $tokenCounter = 1;

    foreach ($patients as $id => $patient) {

        $formattedToken = $patient['formatted_token'] ?? ($prefix . $tokenCounter);
        $tokenCounter++;

        $name        = $patient['name'] ?? trim(($patient['fname'] ?? '').' '.($patient['lname'] ?? ''));
        $regNum      = $patient['reg_number'] ?? 'N/A';
        $date        = $patient['date'] ?? 'N/A';
        $purposeRaw  = trim((string)($patient['purpose'] ?? ''));
        $queueStatus = $patient['queue_status'] ?? 'Waiting';
        $priorityVal = strtolower(trim((string)($patient['priority'] ?? '')));

        // Priority label
        $identifier = '';
        if ($priorityVal === 'pwd') {
            $identifier = "PWD";
        } elseif ($priorityVal === 'senior') {
            $identifier = "Senior Citizen";
        } elseif ($priorityVal === 'emergency') {
            $identifier = "Emergency";
        }

        // Purpose chips
        $purposes = array_filter(array_map('trim', explode(',', $purposeRaw)));
        $visibleMax = 2;
        $visible = array_slice($purposes, 0, $visibleMax);
        $hiddenCount = max(count($purposes) - $visibleMax, 0);

        echo "<tr style='text-align: center; vertical-align: middle;'>
                <td class='col-token' style='border-bottom:1px solid #ddd;'>".htmlspecialchars($formattedToken)."</td>
                <td class='col-name'  style='border-bottom:1px solid #ddd;'>".htmlspecialchars($name)."</td>
                <td class='col-reg'   style='border-bottom:1px solid #ddd;'>".htmlspecialchars($regNum)."</td>
                <td class='col-date'  style='border-bottom:1px solid #ddd;'>".htmlspecialchars($date)."</td>

                <td class='purpose-cell' style='border-bottom:1px solid #ddd;'>
                    <div class='purpose-chips'>";

        if (empty($purposes)) {
            echo "<span class='purpose-chip'>—</span>";
        } else {
            foreach ($visible as $p) {
                echo "<span class='purpose-chip'>".htmlspecialchars($p)."</span>";
            }

            if ($hiddenCount > 0) {
                $pid = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
                echo "<span class='purpose-more' onclick=\"openPurposeModal('$pid')\">+$hiddenCount more</span>";
            }
        }

        echo "      </div>";

        // Hidden full-purpose list for modal
        $fullList = htmlspecialchars(json_encode($purposes), ENT_QUOTES, 'UTF-8');
        echo "      <input type='hidden' id='purposes-$id' value='$fullList'>
                </td>

                <td class='col-status'   style='border-bottom:1px solid #ddd;'>".htmlspecialchars($queueStatus)."</td>
                <td class='col-priority' style='border-bottom:1px solid #ddd;'>".htmlspecialchars($identifier)."</td>

                <td class='col-action' style='border-bottom:1px solid #ddd;'>
                    <div class='action-buttons'>";

        // ✅ buttons are NOW INSIDE action-buttons

        if ($queueStatus !== 'Done') {
            if ($queueStatus === 'Waiting') {
                echo "<button class='btn-action btn-serve' title='Mark as In Progress'
                        onclick=\"if(confirm('Mark patient as In Progress?')) {
                            toggleServing('".htmlspecialchars($id,ENT_QUOTES)."','Waiting','".htmlspecialchars($deptName,ENT_QUOTES)."');
                        }\"
                        style='background:#e0f7fa; border:1px solid #00bcd4; border-radius:6px;
                               padding:5px; width:36px; height:36px; display:flex; align-items:center; justify-content:center;'>
                        <i class='fas fa-play-circle' style='font-size:16px; color:#00bcd4;'></i>
                      </button>";
            } elseif ($queueStatus === 'In Progress') {
                echo "<button class='btn-action btn-serve' title='Mark as Done'
                        onclick=\"if(confirm('Mark patient as Done?')) {
                            toggleServing('".htmlspecialchars($id,ENT_QUOTES)."','In Progress','".htmlspecialchars($deptName,ENT_QUOTES)."');
                        }\"
                        style='background:#ffcccc; border:1px solid #f44336; border-radius:6px;
                               padding:5px; width:36px; height:36px; display:flex; align-items:center; justify-content:center;'>
                        <i class='fas fa-stop-circle' style='font-size:16px; color:#f44336;'></i>
                      </button>";
            }
        }

        echo "<button class='btn-action btn-transfer' title='Transfer Patient'
                onclick=\"if(confirm('Transfer this patient?')) {
                    openTransferOverlay('".htmlspecialchars($id,ENT_QUOTES)."','".htmlspecialchars($deptName,ENT_QUOTES)."','".htmlspecialchars($name,ENT_QUOTES)."');
                }\"
                style='background:#fff3e0; border:1px solid #ff9800; border-radius:6px;
                       padding:5px; width:36px; height:36px; display:flex; align-items:center; justify-content:center;'>
                <i class='fas fa-exchange-alt' style='font-size:16px; color:#ff9800;'></i>
              </button>";

        echo "      </div>
                </td>
             </tr>";
    }

    echo '</tbody></table></div>'; // ✅ close table + table-wrap
}



                // Set admin department 
                $adminDepartment = ucfirst($_SESSION['specialty']);

                if ($action === 'search') {
                    if (!empty($searchResults)) {
                        $searchResultsAssoc = [];
                        foreach ($searchResults as $patient) {
                            $searchResultsAssoc[$patient['id']] = $patient;
                        }
                        displayTable($searchResultsAssoc, "Search Results for '" . htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8') . "'", "", [], $adminDepartment);
                    } else {
                        echo "<p>No patients found matching your search criteria.</p>";
                    }
                } else {
                    if (!empty($adminDepartment)) {
                        if (strtolower($_SESSION['specialty']) === 'information') {
                            if (is_array($patientsData)) {
                                foreach ($patientsData as $department => $deptPatients) {
                                    displayTable($deptPatients, $department . " Queue", "", $inProgressPatients, $department);
                                }
                            } else {
                                echo "<p>No patient data available.</p>";
                            }
                        } else {
                            if (is_array($patientsData)) {
                                $found = false;
                                foreach ($patientsData as $department => $deptPatients) {
                                    $normalizedDept = strtolower(str_replace(' ', '_', trim($department)));
                                    if ($normalizedDept === strtolower($_SESSION['specialty'])) {
                                        $found = true;
                                        displayTable($deptPatients, $department . " Queue", "", $inProgressPatients, $department);
                                    }
                                }
                                if (!$found) {
                                    displayTable([], $admin2 . " Queue", "", $inProgressPatients, $admin2);
                                }
                            } else {
                                echo "<p>No patient data available.</p>";
                            }
                        }
                    } else {
                        echo "<p>No specialty defined for this admin.</p>";
                    }
                }
            ?>

                <!-- Transfer Overlay -->
                <div class="overlay" id="transferOverlay">
                    <div class="overlay-content">
                        <button class="close-btn" id="closeTransferOverlay" onclick="closeTransferOverlay()">&times;</button>
                        <h2 style="margin-bottom: 20px;">Transfer Patient</h2>
                        <form action="transfer_patient.php" method="POST">
                            <input type="hidden" id="transferPatientId" name="patient_id">
                            <input type="hidden" id="transferPatientName" name="patient_name">
                        
                            <label for="new_queue_type">Transfer to:</label>
                            <select id="new_queue_type" name="new_queue_type" required onchange="toggleTransferPurposeSections()">
                                <option value="" disabled selected>Select Department</option>
                                <?php if (!empty($specialtiesSorted)): ?>
                                    <?php foreach ($specialtiesSorted as $spec): 
                                        $sname = trim($spec['sname'] ?? '');
                                        if (!in_array($sname, $allowedQueueTypes, true)) continue; 
                                    ?>
                                        <option value="<?php echo htmlspecialchars($sname, ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars($sname, ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="">No specialties found</option>
                                <?php endif; ?>
                            </select>

                            <!-- Consultation Section -->
                            <div id="transferConsultationSection" class="transfer-purpose-section" style="display:none;">
                                <label>Consultation Type:</label>
                                <div class="checkbox-group">
                                    <label><input type="checkbox" name="transfer_purpose[]" value="General Consultation"> General Consultation</label>
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Follow-up"> Follow-up</label>
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Specialist Referral"> Specialist Referral</label>
                                </div>
                            </div>
                            <!-- Industrial Clinic Section -->
                            <div id="transferIndustrialClinicSection" class="transfer-purpose-section" style="display:none;">
                                <label>Industrial Clinic Services:</label>
                                <div class="checkbox-group">
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Urinalysis"> Urinalysis</label>
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Fecalysis"> Fecal Analysis</label>
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Blood Tests"> Blood Tests</label>
                                </div>
                            </div>
                            <!-- Radiology Section -->
                            <div id="transferRadiologySection" class="transfer-purpose-section" style="display:none;">
                                <label>Radiology Services:</label>
                                <div class="checkbox-group">
                                    <label><input type="checkbox" name="transfer_purpose[]" value="X-ray"> X-ray</label>
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Ultrasound"> Ultrasound</label>
                                    <label><input type="checkbox" name="transfer_purpose[]" value="MRI"> MRI</label>
                                    <label><input type="checkbox" name="transfer_purpose[]" value="CT Scan"> CT Scan</label>
                                </div>
                            </div>
                            <!-- Cardio Pulmonology Section -->
                            <div id="transferCardioPulmonologySection" class="transfer-purpose-section" style="display:none;">
                                <label>Cardio Pulmonology Services:</label>
                                <div class="checkbox-group">
                                    <label><input type="checkbox" name="transfer_purpose[]" value="ECG"> ECG</label>
                                    <label><input type="checkbox" name="transfer_purpose[]" value="2D Echo"> 2D Echo</label>
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Pulmonary Function Test"> Pulmonary Function Test</label>
                                </div>
                            </div>
                            <!-- Physical Therapy Section -->
                            <div id="transferPhysicalTherapySection" class="transfer-purpose-section" style="display:none;">
                                <label>Physical Therapy & Rehabilitation Services:</label>
                                <div class="checkbox-group">
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Physical Therapy"> Physical Therapy</label>
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Occupational Therapy"> Occupational Therapy</label>
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Rehabilitation Medicine"> Rehabilitation Medicine</label>
                                </div>
                            </div>
                            <!-- Obstetrics and Gynecology Section -->
                            <div id="transferObgynSection" class="transfer-purpose-section" style="display:none;">
                                <label>Obstetrics and Gynecology Services:</label>
                                <div class="checkbox-group">
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Prenatal Check-up"> Prenatal Check-up</label>
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Gynecological Consultation"> Gynecological Consultation</label>
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Family Planning"> Family Planning</label>
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Menstrual Disorder Evaluation"> Menstrual Disorder Evaluation</label>
                                </div>
                            </div>
                            <!-- General Surgeon Section -->
                            <div id="transferGeneralSurgeonSection" class="transfer-purpose-section" style="display:none;">
                                <label>General Surgery Services:</label>
                                <div class="checkbox-group">
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Pre-Surgical Consultation"> Pre-Surgical Consultation</label>
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Post-Surgical Follow-up"> Post-Surgical Follow-up</label>
                                    <label><input type="checkbox" name="transfer_purpose[]" value="General Surgery Consultation"> General Surgery Consultation</label>
                                </div>
                            </div>
                            <!-- Pediatrics Section -->
                            <div id="transferPediatricsSection" class="transfer-purpose-section" style="display:none;">
                                <label>Pediatrics Services:</label>
                                <div class="checkbox-group">
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Well-Child Visit"> Well-Child Visit</label>
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Immunization"> Immunization</label>
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Pediatric Consultation"> Pediatric Consultation</label>
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Growth and Development Assessment"> Growth and Development Assessment</label>
                                </div>
                            </div>
                            <!-- ENT Section -->
                            <div id="transferEntSection" class="transfer-purpose-section" style="display:none;">
                                <label>Ear, Nose, and Throat (ENT) Services:</label>
                                <div class="checkbox-group">
                                    <label><input type="checkbox" name="transfer_purpose[]" value="ENT Consultation"> ENT Consultation</label>
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Hearing Test"> Hearing Test</label>
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Sinus Evaluation"> Sinus Evaluation</label>
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Allergy Testing"> Allergy Testing</label>
                                </div>
                            </div>
                            <!-- Opthalmology Section -->
                            <div id="transferOpthalmologySection" class="transfer-purpose-section" style="display:none;">
                                <label>Opthalmology Services:</label>
                                <div class="checkbox-group">
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Eye Examination"> Eye Examination</label>
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Glaucoma Evaluation"> Glaucoma Evaluation</label>
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Cataract Consultation"> Cataract Consultation</label>
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Vision Correction Advice"> Vision Correction Advice</label>
                                </div>
                            </div>
                            <!-- Dentist Section -->
                            <div id="transferDentistSection" class="transfer-purpose-section" style="display:none;">
                                <label>Dentistry Services:</label>
                                <div class="checkbox-group">
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Dental Check-Up"> Dental Check-Up</label>
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Teeth Cleaning"> Teeth Cleaning</label>
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Oral Surgery Consultation"> Oral Surgery Consultation</label>
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Cosmetic Dentistry"> Cosmetic Dentistry</label>
                                </div>
                            </div>
                            <!-- Rehab Specialist Section -->
                            <div id="transferRehabSpecialistSection" class="transfer-purpose-section" style="display:none;">
                                <label>Rehabilitation Medicine Specialist Services:</label>
                                <div class="checkbox-group">
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Rehabilitation Consultation"> Rehabilitation Consultation</label>
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Chronic Pain Management"> Chronic Pain Management</label>
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Disability Assessment"> Disability Assessment</label>
                                </div>
                            </div>
                            <!-- Urology Section -->
                            <div id="transferUrologistSection" class="transfer-purpose-section" style="display:none;">
                                <label>Urology Services:</label>
                                <div class="checkbox-group">
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Urology Consultation"> Urology Consultation</label>
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Kidney Stone Evaluation"> Kidney Stone Evaluation</label>
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Urinary Tract Infection Assessment"> Urinary Tract Infection Assessment</label>
                                </div>
                            </div>
                            <!-- Family Medicine Section -->
                            <div id="transferFamilyMedicineSection" class="transfer-purpose-section" style="display:none;">
                                <label>Family Medicine Services:</label>
                                <div class="checkbox-group">
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Family Health Check-Up"> Family Health Check-Up</label>
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Chronic Disease Management"> Chronic Disease Management</label>
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Preventive Care"> Preventive Care</label>
                                </div>
                            </div>
                            <!-- Internal Medicine Section -->
                            <div id="transferInternalMedicineSection" class="transfer-purpose-section" style="display:none;">
                                <label>Internal Medicine Services:</label>
                                <div class="checkbox-group">
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Internal Medicine Consultation"> Internal Medicine Consultation</label>
                                    <label><input type="checkbox" name="transfer_purpose[]" value="General Health Assessment"> General Health Assessment</label>
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Chronic Condition Management"> Chronic Condition Management</label>
                                </div>
                            </div>
                            <!-- HMO Section -->
                            <div id="transferHMOSection" class="transfer-purpose-section" style="display:none;">
                                <label>HMO Payment:</label>
                                <div class="checkbox-group">
                                    <label><input type="checkbox" name="transfer_purpose[]" value="HMO Payment"> HMO Payment</label>
                                </div>
                            </div>
                            <!-- Cashier Section -->
                            <div id="transferCashierSection" class="transfer-purpose-section" style="display:none;">
                                <label>Cashier Payment:</label>
                                <div class="checkbox-group">
                                    <label><input type="checkbox" name="transfer_purpose[]" value="Cashier Payment"> Cashier Payment</label>
                                </div>
                            </div>

                            <button type="submit" class="btn">Transfer</button>
                        </form>
                    </div>
                </div>
            </div>
        </center>
    </td>
</tr>
            </table>
        </div>

<!-- ✅ Purpose Modal (NEW HTML) -->
<div id="purposeModal" class="purpose-modal" onclick="closePurposeModal()">
  <div class="purpose-modal-content" onclick="event.stopPropagation()">
    <h3>Purpose</h3>
    <ul id="purposeModalList" class="purpose-list"></ul>
  </div>
</div>

<script>
    const addNewButton = document.getElementById('addNewButton');
    const uniqueOverlay = document.getElementById('uniqueOverlay');
    const closeUniqueOverlay = document.getElementById('closeUniqueOverlay');

    if (addNewButton) {
        addNewButton.addEventListener('click', function () {
            uniqueOverlay.style.display = 'flex';
        });
    }

    if (closeUniqueOverlay) {
        closeUniqueOverlay.addEventListener('click', function () {
            uniqueOverlay.style.display = 'none';
        });
    }

    function openPurposeModal(patientId){
  const hidden = document.getElementById('purposes-' + patientId);
  if(!hidden) return;

  let purposes = [];
  try { purposes = JSON.parse(hidden.value || "[]"); } catch(e){}

  const list = document.getElementById('purposeModalList');
  list.innerHTML = '';

  purposes.forEach(p => {
    const li = document.createElement('li');
    li.textContent = p;
    list.appendChild(li);
  });

  document.getElementById('purposeModal').classList.add('active');
}

function closePurposeModal(){
  document.getElementById('purposeModal').classList.remove('active');
}

function deletePatient(patientId) {
    if (confirm("Are you sure you want to delete this patient from the queue?")) {
        fetch('delete_patientQ.php?id=' + patientId, { method: 'GET' })
            .then(response => response.text())
            .then(data => {
                alert(data);
                location.reload();
            })
            .catch(error => console.error('Error:', error));
    }
}

function transferPatient(id, currentType) {
    var newDepartment = prompt("Enter new department for patient " + id + " (current: " + currentType + "):");
    if (newDepartment !== null && newDepartment.trim() !== "") {
        fetch('transfer_patient.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json'
            },
            body: JSON.stringify({ id: id, newDepartment: newDepartment })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert("Patient transferred successfully!");
            } else {
                alert("Transfer failed. Please try again.");
            }
        })
        .catch(error => {
            console.error('Error during transfer:', error);
            alert("Transfer failed. Please try again.");
        });
    }
}

function toggleServing(patientId, currentStatus, queueType) {
    if (currentStatus === 'Done') {
        alert("This patient is already marked as done.");
        return;
    }

    fetch(`check_ongoing_progress.php?queue_type=${encodeURIComponent(queueType)}`)
        .then(response => response.json())
        .then(data => {
            if (data.hasOngoing && currentStatus !== 'In Progress') {
                alert(`Another patient is already in progress for ${queueType}.\nFinish that patient first.`);
                return;
            }

            let newStatus;
            if (currentStatus === 'In Progress') {
                newStatus = confirm("The status is currently 'In Progress'.\nDo you want to mark it as 'Done'? Click 'OK' to proceed or 'Cancel' to keep it in progress.")
                    ? 'Done'
                    : currentStatus;
            } else if (currentStatus === 'Waiting') {
                newStatus = 'In Progress';
            }

            if (newStatus !== currentStatus) {
                fetch(`toggle_servingQ.php?id=${encodeURIComponent(patientId)}&status=${encodeURIComponent(newStatus)}&queue_type=${encodeURIComponent(queueType)}`, {
                    method: 'GET'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        location.reload();
                    } else {
                        alert(data.message);
                    }
                })
                .catch(error => console.error('Error:', error));
            }
        })
        .catch(error => console.error('Error:', error));
}

function openTransferOverlay(patientId, queueType, patientName) {
    document.getElementById('transferPatientId').value = patientId;
    document.getElementById('transferPatientName').value = patientName;
    document.getElementById('transferOverlay').classList.add('active');
}

function closeTransferOverlay() {
    document.getElementById('transferOverlay').classList.remove('active');
}

function togglePurposeSections() {
    document.querySelectorAll('.purpose-section').forEach(section => {
        section.style.display = 'none';
    });

    const queueType = document.getElementById('queue_type').value;

    if (queueType === 'Industrial Clinic') {
        document.getElementById('industrialClinicSection').style.display = 'block';
    } else if (queueType === 'Radiology') {
        document.getElementById('radiologySection').style.display = 'block';
    } else if (queueType === 'Cardio Pulmonology') {
        document.getElementById('cardioPulmonologySection').style.display = 'block';
    } else if (queueType === 'Physical Therapy') {
        document.getElementById('physicalTherapySection').style.display = 'block';
    } else if (queueType === 'Obstetrics and Gynecology') {
        document.getElementById('obgynSection').style.display = 'block';
    } else if (queueType === 'Rehabilitation Medicine Specialist') {
        document.getElementById('rehabSpecialistSection').style.display = 'block';
    }
}

window.onload = function() {
    togglePurposeSections(); 
};

function toggleTransferPurposeSections() {
  document.querySelectorAll('.transfer-purpose-section').forEach(section => {
    section.style.display = 'none';
  });
  
  const queueType = document.getElementById('new_queue_type').value;
  
  if (queueType === 'Consultation') {
    document.getElementById('transferConsultationSection').style.display = 'block';
  } else if (queueType === 'Industrial Clinic') {
    document.getElementById('transferIndustrialClinicSection').style.display = 'block';
  } else if (queueType === 'Radiology') {
    document.getElementById('transferRadiologySection').style.display = 'block';
  } else if (queueType === 'Cardio Pulmonology') {
    document.getElementById('transferCardioPulmonologySection').style.display = 'block';
  } else if (queueType === 'Physical Therapy') {
    document.getElementById('transferPhysicalTherapySection').style.display = 'block';
  } else if (queueType === 'Obstetrics and Gynecology') {
    document.getElementById('transferObgynSection').style.display = 'block';
  } else if (queueType === 'General Surgeon') {
    document.getElementById('transferGeneralSurgeonSection').style.display = 'block';
  } else if (queueType === 'Pediatrics') {
    document.getElementById('transferPediatricsSection').style.display = 'block';
  } else if (queueType === 'Ear, Nose, Throat (ENT)') {
    document.getElementById('transferEntSection').style.display = 'block';
  } else if (queueType === 'Opthalmologist') {
    document.getElementById('transferOpthalmologySection').style.display = 'block';
  } else if (queueType === 'Dentist') {
    document.getElementById('transferDentistSection').style.display = 'block';
  } else if (queueType === 'Rehabilitation Medicine Specialist') {
    document.getElementById('transferRehabSpecialistSection').style.display = 'block';
  } else if (queueType === 'Urologist') {
    document.getElementById('transferUrologistSection').style.display = 'block';
  } else if (queueType === 'Family Medicine') {
    document.getElementById('transferFamilyMedicineSection').style.display = 'block';
  } else if (queueType === 'Internal Medicine') {
    document.getElementById('transferInternalMedicineSection').style.display = 'block';
  } else if (queueType === 'HMO') {
    document.getElementById('transferHMOSection').style.display = 'block';
  } else if (queueType === 'Cashier' || queueType === 'PhilHealth') {
    document.getElementById('transferCashierSection').style.display = 'block';
  }
}

['fname','lname'].forEach(function(id){
  var el = document.getElementById(id);
  if (!el) return;
  el.addEventListener('blur', function(e){
    var v = e.target.value.trim();
    if (!v) return;
    e.target.value = v.split(' ').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
  });
});

function confirmAddPatient() {
    var fname = document.getElementById('fname')?.value?.trim() || '';
    var lname = document.getElementById('lname')?.value?.trim() || '';
    var full  = (fname + ' ' + lname).trim();
    return confirm("Do you want to add the patient: " + (full || 'Unnamed') + "?");
}

document.addEventListener('DOMContentLoaded', function () {
  const hamburger = document.getElementById('hamburger-menu');
  const overlay   = document.getElementById('menu-overlay');

  function closeMenu(){
    document.body.classList.remove('menu-open');
    hamburger?.classList.remove('active');
  }

  hamburger?.addEventListener('click', function(){
    document.body.classList.toggle('menu-open');
    hamburger.classList.toggle('active');
  });

  overlay?.addEventListener('click', closeMenu);

  document.querySelectorAll('.menu a').forEach(a=>{
    a.addEventListener('click', closeMenu);
  });
});
</script>
</body>
</html>
