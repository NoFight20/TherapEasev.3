<?php
session_name('sess_admin'); session_start();

if(isset($_SESSION["user"])){
    if(($_SESSION["user"]) == "" || $_SESSION['usertype'] != 'a'){
        header("location: ../login.php");
        exit();
    } else {
        $useremail = $_SESSION["user"];
    }
} else {
    header("location: ../login.php");
    exit();
}

// Import database
include("../connection.php");

date_default_timezone_set('Asia/Manila');
$date = date('Y-m-d');

function findPatientKeyByUID($database, string $patientUid): ?string {
    if ($patientUid === '') return null;

    $snap = $database->getReference('patients')
        ->orderByChild('patient_uid')
        ->equalTo($patientUid)
        ->getSnapshot();

    $val = $snap->getValue();
    if (!$val || !is_array($val)) return null;

    $keys = array_keys($val);
    return $keys[0] ?? null;
}

try {
    $reference = $database
        ->getReference('admin')
        ->orderByChild('email')
        ->equalTo($useremail)
        ->getSnapshot();

    $userfetch = $reference->getValue();

    if ($userfetch) {
        foreach ($userfetch as $key => $value) {
            $username = $value['name'];
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

$isInformation = (strcasecmp($adminSpecialty, 'Information') === 0);
$canEdit = !$isInformation;
// ensure we always have a defined string
if (!isset($adminSpecialty) || !is_string($adminSpecialty)) {
    $adminSpecialty = '';
}


$_SESSION['canEditMedicalForm'] = $canEdit;
$attrReadonly = $canEdit ? '' : 'readonly';
$attrDisabled = $canEdit ? '' : 'disabled';
$submitBlocked = $canEdit ? '' : 'onsubmit="return false"';

// Archive action 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST["action"]) && $_POST["action"] === 'archive') {
    if (!$canEdit) {
        http_response_code(403);
        echo '<script>alert("You do not have permission to archive medical records."); window.location.href="patient.php";</script>';
        exit();
    }

    $date = date('Y-m-d');

    // NEW: take patient_uid as the identifier (fallback to old 'key' for backward-compat)
    $patientUid = htmlspecialchars($_POST["patient_uid"] ?? '');
    $legacyKey  = htmlspecialchars($_POST["key"] ?? '');

    if ($patientUid === '' && $legacyKey === '') {
        echo '<script>alert("No patient identifier provided."); window.location.href="patient.php";</script>';
        exit();
    }

    // Resolve node key
    $nodeKey = $patientUid !== '' ? findPatientKeyByUID($database, $patientUid) : $legacyKey;

    if (!$nodeKey) {
        echo '<script>alert("Patient not found (invalid UID)."); window.location.href="patient.php";</script>';
        exit();
    }

    // Load patient (by node key)
    $patientRef  = $database->getReference('patients/' . $nodeKey);
    $patientData = $patientRef->getValue();
    if (!$patientData) {
        echo '<script>alert("Patient not found."); window.location.href="patient.php";</script>';
        exit();
    }

    // NEW schema medical record: patients/{nodeKey}/medical_record
    $newMedRef   = $database->getReference('patients/' . $nodeKey . '/medical_record');
    $newMedData  = $newMedRef->getValue();

    // LEGACY schema medical record: medical_records/{nodeKey}
    $legacyMedRef  = $database->getReference('medical_records/' . $nodeKey);
    $legacyMedData = $legacyMedRef->getValue();

    // Merge
    $medicalRecordData = [];
    if (is_array($legacyMedData)) $medicalRecordData = $legacyMedData;
    if (is_array($newMedData))    $medicalRecordData = array_replace_recursive($medicalRecordData, $newMedData);

    // Build archive payload
    $combinedArchiveData = [
        'patient' => [
            'patient_uid'  => $patientData['patient_uid'] ?? $patientUid,
            'email'        => $patientData['email'] ?? '',
            'password'     => $patientData['password'] ?? '',
            'fname'        => $patientData['fname'] ?? '',
            'lname'        => $patientData['lname'] ?? '',
            'name'         => $patientData['name'] ?? (trim(($patientData['fname'] ?? '') . ' ' . ($patientData['lname'] ?? ''))),
            'gender'       => $patientData['gender'] ?? '',
            'barangay'     => $patientData['barangay'] ?? '',
            'city'         => $patientData['city'] ?? '',
            'cases'        => $patientData['cases'] ?? [],
            'province'     => $patientData['province'] ?? '',
            'dob'          => $patientData['dob'] ?? '',
            'age'          => $patientData['age'] ?? '',
            'tele'         => $patientData['tele'] ?? '',
            'type'         => $patientData['type'] ?? '',
            'sname'        => $adminSpecialty ?? '',
            'civil_status' => $patientData['civil_status'] ?? ''
        ],
        'medical_record' => $medicalRecordData ?: [],
        'archived_at'    => $date,
        'archived_by'    => $_SESSION['user'] ?? '',
    ];

    // Use patient_uid as the archive key
    $archiveKey = $patientData['patient_uid'] ?? $patientUid ?? $nodeKey;
    $database->getReference('archive/' . $archiveKey)->set($combinedArchiveData);

    // Remove source data
    $patientRef->remove();
    if ($legacyMedData !== null) $legacyMedRef->remove();

    // Log
    $database->getReference('logs')->push([
        'action'      => 'archive',
        'email'       => $useremail,
        'patientId'   => $archiveKey, // now logging the UID
        'patientName' => $combinedArchiveData['patient']['name'] ?? '',
        'timestamp'   => date("H:i:s"),
        'datestamp'   => $date,
        'status'      => 'Archived patient data.',
    ]);

    echo '<script>alert("Patient and associated medical record archived successfully!"); window.location.href="patient.php";</script>';
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST["action"]) && $_POST["action"] === 'view') {
    $patientUid = trim($_POST["patient_uid"] ?? '');
    $legacyKey  = $_POST["key"] ?? null; // backward-compat

    try {
        $nodeKey = $patientUid ? findPatientKeyByUID($database, $patientUid) : $legacyKey;
        if (!$nodeKey) { echo '<p>Patient not found.</p>'; exit; }

        // Your legacy data fetch (kept as-is)
        $patientRef = $database->getReference('medical_records/' . $nodeKey);
        $patient = $patientRef->getValue();

        if ($patient) {
            $fname    = $patient['patient_fname'] ?? '';
            $lname    = $patient['patient_lname'] ?? '';
            $name     = $patient['patient_name'] ?? trim($fname . ' ' . $lname);
            $email    = $patient['email'] ?? '';
            $age      = $patient['age'] ?? 'N/A';
            $gender   = $patient['gender'] ?? 'N/A';
            $dob      = $patient['dob'] ?? 'N/A';
            $tele     = $patient['tele'] ?? 'N/A';
            $civil    = $patient['civil_status'] ?? 'N/A';
            $barangay = $patient['address']['barangay'] ?? '';
            $city     = $patient['address']['city'] ?? 'N/A';
            $province = $patient['address']['province'] ?? 'N/A';

            if (empty($email)) {
                $_SESSION['personal'] = [
                    'fname'         => $fname,
                    'lname'         => $lname,
                    'gender'        => $gender,
                    'dob'           => $dob,
                    'civil_status'  => $civil,
                    'province'      => $province,
                    'city'          => $city,
                    'barangay'      => $barangay,
                    'tele'          => $tele
                ];
                header('Location: create-account.php');
                exit();
            } else {
                echo '<p>Patient details:</p>';
                echo '<ul>';
                echo '<li>Name: ' . htmlspecialchars($name) . '</li>';
                echo '<li>Email: ' . htmlspecialchars($email) . '</li>';
                echo '<li>Age: ' . htmlspecialchars($age) . '</li>';
                echo '<li>Gender: ' . htmlspecialchars($gender) . '</li>';
                echo '<li>Date of Birth: ' . htmlspecialchars($dob) . '</li>';
                echo '<li>Phone: ' . htmlspecialchars($tele) . '</li>';
                echo '<li>Civil Status: ' . htmlspecialchars($civil) . '</li>';
                echo '<li>Address: ' . htmlspecialchars($barangay . ', ' . $city . ', ' . $province) . '</li>';
                echo '</ul>';
            }
        } else {
            echo '<p>Patient not found.</p>';
        }
    } catch (Exception $e) {
        echo '<p>Error retrieving patient data: ' . htmlspecialchars($e->getMessage()) . '</p>';
    }
}



// --- helper (safe to keep near top of file once) ---
if (!function_exists('generatePatientUID')) {
    function generatePatientUID(): string {
        try {
            return 'PUID-' . strtoupper(bin2hex(random_bytes(4))); // e.g. PUID-3FA9B12C
        } catch (Throwable $e) {
            return 'PUID-' . strtoupper(dechex(mt_rand(0, 0xFFFFFFF)));
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_account') {

    // Posted fields
    $postedId  = trim($_POST['patientId'] ?? '');   
    $email     = trim($_POST['email'] ?? '');
    $password  = $_POST['password'] ?? '';
    $useremail = $_SESSION['user'] ?? '';
    $date      = date('Y-m-d');

    if ($email === '' || $password === '') {
        echo "<script>alert('Missing fields. Email and Password are required.');</script>";
        return;
    }

    $nodeKey    = null;
    $currentUid = null;

    // 1) Try postedId as an existing patient_uid
    if ($postedId !== '') {
        $snap = $database->getReference('patients')
            ->orderByChild('patient_uid')
            ->equalTo($postedId)
            ->getSnapshot();

        $val = $snap->getValue();
        if ($val && is_array($val)) {
            $nodeKey    = array_key_first($val);
            $currentUid = $postedId;
        }
    }

    // 2) If not found, try postedId as the actual node key
    if ($nodeKey === null && $postedId !== '') {
        $maybe = $database->getReference('patients/'.$postedId)->getValue();
        if (is_array($maybe)) {
            $nodeKey    = $postedId;
            $currentUid = $maybe['patient_uid'] ?? null;
        }
    }

    // 3) If we still don't have a node, bail
    if ($nodeKey === null) {
        echo "<script>alert('Patient record not found. Please open the patient and try again.');</script>";
        return;
    }

    // 4) Ensure we have a patient UID; create a NEW one if missing
    if (!$currentUid || !is_string($currentUid) || trim($currentUid) === '') {
        // Generate a unique ID
        $uniqueId   = 'P-' . uniqid();
        $currentUid = $uniqueId;
    }

    // Fetch patient (for logging)
    $p = $database->getReference('patients/'.$nodeKey)->getValue() ?: [];
    $patientName = $p['name'] ?? trim(($p['fname'] ?? '').' '.($p['lname'] ?? ''));

   try {
    // A) Create Firebase Auth user
    $userRecord = $auth->createUser([
        'email'    => $email,
        'password' => $password,
    ]);

    // A.1) Send the default Firebase verification email (no custom ActionCodeSettings)
    $verificationSent = false;
    try {
        $auth->sendEmailVerificationLink($email); // uses your console template & default handler
        $verificationSent = true;

        // (optional) mark that we sent it
        $database->getReference('patients/'.$nodeKey)->update([
            'email_verification' => [
                'sent_at' => date('c'),
                'by'      => $useremail,
            ],
        ]);
    } catch (\Throwable $e) {
        // Don’t fail account creation if email send hiccups; just log it
        error_log('Verification email send failed: '.$e->getMessage());
    }

    // B) Store safe password hash + link auth_uid/email/UID in patients/{nodeKey}
    $database->getReference('patients/'.$nodeKey)->update([
        'email'       => $email,
        'patient_uid' => $currentUid,
        'auth_uid'    => $userRecord->uid,
        'password'    => password_hash($password, PASSWORD_BCRYPT),
    ]);

    // C) Optional cross-role index
    try {
        $database->getReference('users/'.$nodeKey)->update([
            'email'       => $email,
            'role'        => 'p',
            'createdAt'   => (int) (microtime(true) * 1000),
            'patient_uid' => $currentUid,
            'auth_uid'    => $userRecord->uid,
        ]);
    } catch (\Throwable $inner) {
        error_log('users/ index update failed: '.$inner->getMessage());
    }

    // D) LOG success (+ whether verification mail was sent)
    $database->getReference('logs')->push([
        'action'        => 'create_account',
        'email'         => $useremail,
        'patientId'     => $currentUid,
        'patientName'   => $patientName,
        'timestamp'     => date("H:i:s"),
        'datestamp'     => $date,
        'status'        => 'Created patient auth account;',
        'verification'  => $verificationSent ? 'sent' : 'not_sent',
    ]);

    // E) Success (PRG)
    $_SESSION['success_message'] =
        'Account created successfully.' .
        ($verificationSent ? ' A verification link was sent to '.$email.'.' : ' (Verification email could not be sent right now.)');

    header('Location: patient.php');
    exit;

} catch (\Kreait\Firebase\Exception\Auth\EmailExists $e) {
    $database->getReference('logs')->push([
        'action'      => 'create_account',
        'email'       => $useremail,
        'patientId'   => $currentUid ?? ($postedId ?: 'unknown'),
        'patientName' => $patientName ?? '',
        'timestamp'   => date("H:i:s"),
        'datestamp'   => $date,
        'status'      => 'Failed: email already in use.',
    ]);
    echo "<script>alert('Failed to create account: Email already in use.');</script>";
} catch (\Throwable $e) {
    $database->getReference('logs')->push([
        'action'      => 'create_account',
        'email'       => $useremail,
        'patientId'   => $currentUid ?? ($postedId ?: 'unknown'),
        'patientName' => $patientName ?? '',
        'timestamp'   => date("H:i:s"),
        'datestamp'   => $date,
        'status'      => 'Failed: '.$e->getMessage(),
    ]);
    $msg = addslashes($e->getMessage());
    echo "<script>alert('Failed to create account: {$msg}');</script>";
}

}



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

$allPatientsInit = $database->getReference('patients')->getValue() ?: [];
$patientCount    = is_array($allPatientsInit) ? count($allPatientsInit) : 0;

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- <link rel="stylesheet" href="../css/animations.css">   -->
    <link rel="stylesheet" href="../css/main.css">  
    <link rel="stylesheet" href="../css/admin.css">
    <link rel="stylesheet" href="patient.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <title>Patients</title>
<style>
/* =========================
   BASE / UTILITIES
   ========================= */
*{box-sizing:border-box;}
img{max-width:100%;height:auto;}
.hidden{display:none!important;}
.dash-body{overflow-x:hidden;}

.menu-btn a{
  display:block;
  text-decoration:none;
  padding:1px;
  width:100%;
}
.menu-btn:hover{background-color:#E8F8E8;}

.icon-btn{
  background:#f1f1f1;
  border:none;
  padding:8px 12px;
  margin:5px;
  border-radius:50%;
  cursor:pointer;
  font-size:14px;
  transition:.3s;
}
.icon-btn:hover{background:#45a049;color:#fff;}
.icon-btn:disabled,
.login-btn:disabled,
.floating-btn:disabled{
  opacity:.5;
  cursor:not-allowed;
}

/* =========================
   OVERLAY (GLOBAL)
   ========================= */
.overlay{
  position:fixed;
  inset:0;
  background:rgba(0,0,0,.7);
  z-index:1000;
  display:flex;
  align-items:center;
  justify-content:center;
  padding:24px;
}
.overlay-content{
  background:#fff;
  padding:20px;
  border-radius:5px;
  width:50%;
  text-align:center;
}

/* =========================
   CREATE ACCOUNT MODAL
   ========================= */
#createAccountModal{
  display:none;
  position:fixed;
  inset:0;
  background:rgba(0,0,0,.45);
  z-index:9999;
  align-items:center;
  justify-content:center;
  padding:16px;
}
#createAccountModal .modal-content{
  background:#fff;
  width:100%;
  max-width:520px;
  border-radius:14px;
  box-shadow:0 10px 30px rgba(0,0,0,.25);
  padding:20px;
  position:relative;
  animation:scaleIn .2s ease;
}
#createAccountModal .close{
  position:absolute;
  top:10px;
  right:15px;
  font-size:24px;
  font-weight:700;
  cursor:pointer;
  color:#555;
  transition:color .2s ease;
}
#createAccountModal .close:hover{color:#000;}
#createAccountModal form label{
  display:block;
  font-weight:600;
  margin-bottom:4px;
}
#createAccountModal form input{
  width:100%;
  padding:8px 10px;
  margin-bottom:12px;
  border:1px solid #ccc;
  border-radius:6px;
  font-size:14px;
}
#createAccountModal .modal-actions{
  display:flex;
  gap:10px;
  justify-content:flex-end;
}

/* Confirmation overlay sizing */
#confirmation-overlay .overlay-content{
  width:20vw;
  max-width:700px;
}
@media (max-width:640px){
  #confirmation-overlay .overlay-content{width:90vw;}
}

@keyframes scaleIn{
  from{transform:scale(.95);opacity:0;}
  to{transform:scale(1);opacity:1;}
}

/* =========================
   MEDICAL HISTORY MODAL
   ========================= */
#medicalHistoryModal{display:none;}
#medicalHistoryModal .overlay-content--wide{
  background:#fff;
  width:min(95vw,1400px);
  max-height:92vh;
  overflow:auto;
  border-radius:12px;
  box-shadow:0 10px 30px rgba(0,0,0,.25);
  padding:20px;
}
#medicalHistoryModal .overlay-content--wide h3{margin:8px 0 16px;}
#medicalHistoryModal .modal-actions-center{
  display:flex;
  justify-content:center;
  align-items:center;
  gap:20px;
  margin:16px 0 0;
}
#medicalHistoryModal table.medical-history-table{
  width:100%;
  border-collapse:collapse;
}
#medicalHistoryModal table.medical-history-table th,
#medicalHistoryModal table.medical-history-table td{
  padding:8px;
  border:1px solid #ddd;
}

/* =========================
   LOADING SCREEN
   ========================= */
.loading-screen{
  display:none;
  position:fixed;
  inset:0;
  background:rgba(255,255,255,.8);
  z-index:9999;
  align-items:center;
  justify-content:center;
  flex-direction:column;
}
.spinner{
  border:8px solid #f3f3f3;
  border-top:8px solid #4CAF50;
  border-radius:50%;
  width:50px;
  height:50px;
  animation:spin 1s linear infinite;
}
.loading-text{
  margin-top:15px;
  font-size:18px;
  color:#4CAF50;
  font-weight:700;
}
@keyframes spin{
  0%{transform:rotate(0);}
  100%{transform:rotate(360deg);}
}

/* =========================
   LAB RESULT MODAL Z-INDEX
   ========================= */
#labResultModal{z-index:1100!important;}
#labResultModal .overlay-content{z-index:1101!important;}

/* =========================
   HEADER + TABLE RESPONSIVE
   ========================= */
.header-search{
  display:flex;
  gap:10px;
  align-items:center;
  flex-wrap:wrap;
}
.header-searchbar{min-width:220px;}

.abc.scroll{
  width:100%;
  overflow-x:auto;
  -webkit-overflow-scrolling:touch;
}
.sub-table.scrolldown{min-width:900px;}

.floating-btn-container{
  position:none;
  right:16px;
  bottom:16px;
  z-index:1200;
}

/* =========================
   MOBILE DRAWER SUPPORT
   ========================= */
:root{--mHeaderH:56px;}
.mobile-header{display:none;}

/* menu overlay (single definition) */
#menu-overlay{
  display:block;
  position:fixed;
  inset:0;
  background:rgba(0,0,0,.45);
  opacity:0;
  pointer-events:none;
  transition:opacity .2s ease;
  z-index:10030;
}

/* =========================
   MOBILE VIEW
   ========================= */
@media (max-width:768px){

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

  .mobile-left{width:44px;display:flex;align-items:center;}
  .mobile-center{
    flex:1;
    text-align:center;
    font-weight:700;
    font-size:16px;
    color:#000;
  }
  .mobile-right{display:flex;align-items:center;gap:10px;}
  .mobile-date{
    font-size:13px;
    font-weight:600;
    color:#000;
    white-space:nowrap;
  }

  /* calendar button (kept your final behavior) */
  .mobile-header .mobile-calendar-btn{
    all:unset;
    width:34px;
    height:34px;
    border-radius:10px;
    background:rgba(255, 255, 255, 0);
    display:inline-flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    box-sizing:border-box;
  }
  .mobile-header .mobile-calendar-btn img{width:18px;height:18px;display:block;}
  .mobile-header .mobile-calendar-btn:focus{outline:none;}

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

  body.menu-open #menu-overlay{
    opacity:1!important;
    pointer-events:auto!important;
  }

  .menu{
    position:fixed!important;
    top:var(--mHeaderH)!important;
    left:0!important;
    width:270px!important;
    max-width:86vw!important;
    height:calc(100vh - var(--mHeaderH))!important;
    background:lightgreen!important;
    transform:translateX(-105%)!important;
    transition:transform .25s ease!important;
    z-index:10040!important;
    overflow-y:auto!important;
    overflow-x:hidden!important;
    -webkit-overflow-scrolling:touch!important;
    box-shadow:10px 0 30px rgba(0,0,0,.12)!important;
  }
  body.menu-open .menu{transform:translateX(0)!important;}
  body.menu-open{overflow:hidden;}

  .dash-body{
    margin-left:0!important;
    width:100%!important;
    padding:12px!important;
  }

  .dash-body > table > tbody > tr:first-child > td:nth-child(2),
  .dash-body > table > tbody > tr:first-child > td:nth-child(3){
    display:none!important;
  }

  .header-search{
    width:100%!important;
    flex-wrap:wrap!important;
    gap:8px!important;
    margin-top: 30px;
  }
  .header-searchbar{
    width:100%!important;
    min-width:0!important;
  }
  #searchBtn{width:100%!important;}

  .abc.scroll{width:100%!important;overflow:hidden!important;}
  .sub-table.scrolldown{min-width:0!important;width:100%!important;}
  .sub-table thead{display:none!important;}

  .sub-table tbody tr{
    text-align:left!important;
    display:grid!important;
    grid-template-columns:1fr auto!important;
    grid-template-rows:auto auto auto auto auto auto auto!important;
    gap:4px 12px!important;
    padding:12px!important;
    border:1px solid #ddd!important;
    border-radius:12px!important;
    background:#fff!important;
    margin-bottom:12px!important;
  }

  .sub-table tbody td{
    display:block!important;
    padding:0!important;
    border:none!important;
    margin:0!important;
    text-align:left!important;
  }

  .sub-table tbody tr td:nth-child(1){grid-column:1;grid-row:1;font-weight:800;}
  .sub-table tbody tr td:nth-child(2){grid-column:1;grid-row:2;font-size:13px;}
  .sub-table tbody tr td:nth-child(3){grid-column:1;grid-row:3;font-size:13px;}
  .sub-table tbody tr td:nth-child(4){grid-column:1;grid-row:4;font-size:13px;}
  .sub-table tbody tr td:nth-child(5){grid-column:1;grid-row:5;font-size:13px;}
  .sub-table tbody tr td:nth-child(6){grid-column:1;grid-row:6;font-size:13px;}
  .sub-table tbody tr td:nth-child(7){grid-column:1;grid-row:7;font-size:13px;}

  .sub-table tbody tr td:nth-child(8){
    grid-column:2;
    grid-row:1 / span 7;
    align-self:center;
    justify-self:end;
  }

  .btn-view{
    background-image:none!important;
    padding:10px 14px!important;
    border-radius:10px!important;
    min-width:88px!important;
    display:inline-flex!important;
    align-items:center!important;
    justify-content:center!important;
    white-space:nowrap!important;
    font-weight:700!important;
    line-height:1!important;
  }

  .btn-edit,.btn-delete{display:none!important;}
  .floating-btn-container{z-index:900!important;}
}

/* ===== hamburger X animation (single place, forced) ===== */
@media (max-width:900px){
  #hamburger-menu.active .bar:nth-child(1){
    transform:rotate(-45deg) translate(-4px,5px)!important;
  }
  #hamburger-menu.active .bar:nth-child(2){
    opacity:0!important;
  }
  #hamburger-menu.active .bar:nth-child(3){
    transform:rotate(45deg) translate(-4px,-5px)!important;
  }
}

/* ============================================================
   MOBILE VIEW FIX — Medical History Modal tables (VIEW modal)
   ============================================================ */
@media (max-width: 768px){

  /* Modal container padding & width */
  #medicalHistoryModal .overlay-content--wide{
    width: 96vw !important;
    max-height: 92vh !important;
    padding: 12px !important;
    border-radius: 14px !important;
  }

  /* Make tables scrollable horizontally if needed */
  #medicalHistoryModal .table-scroll{
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    border-radius: 12px;
  }

  /* Base table fixes */
  #medicalHistoryModal table{
    width: 100% !important;
    border-collapse: collapse;
    font-size: 14px;
  }

  /* ============== TOP INFO TABLE (medical-history-table) ============== */
  #medicalHistoryModal table.medical-history-table,
  #medicalHistoryModal table.medical-history-table tbody,
  #medicalHistoryModal table.medical-history-table tr,
  #medicalHistoryModal table.medical-history-table th,
  #medicalHistoryModal table.medical-history-table td{
    display: block !important;
    width: 100% !important;
  }

  #medicalHistoryModal table.medical-history-table tr{
    border: 1px solid #e5e5e5;
    border-radius: 12px;
    padding: 10px;
    margin-bottom: 12px;
    background: #fff;
  }

  #medicalHistoryModal table.medical-history-table th{
    font-weight: 800;
    padding: 6px 0 4px;
    border: none !important;
  }

  #medicalHistoryModal table.medical-history-table td{
    padding: 0 0 8px;
    border: none !important;
  }

  /* Inputs should fill width */
  #medicalHistoryModal input,
  #medicalHistoryModal select,
  #medicalHistoryModal textarea{
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box;
  }

  /* ============== SECTION TABLES (med/surg/ill/vax/lab) ============== */
  /* Turn each row into a card layout */
  #medicalHistoryModal table:not(.medical-history-table) thead{
    display:none !important;
  }

  #medicalHistoryModal table:not(.medical-history-table) tbody tr{
    display:block !important;
    border: 1px solid #e5e5e5;
    border-radius: 12px;
    padding: 10px;
    margin-bottom: 12px;
    background:#fff;
  }

  #medicalHistoryModal table:not(.medical-history-table) tbody td{
    display:block !important;
    border:none !important;
    padding: 6px 0 !important;
  }

  /* Label each field using data-label (we'll add via JS below) */
  #medicalHistoryModal table:not(.medical-history-table) tbody td::before{
    content: attr(data-label);
    display:block;
    font-weight:800;
    margin-bottom:4px;
    color:#111;
  }

  /* Actions row: align buttons nicely */
  #medicalHistoryModal .row-actions{
    display:flex !important;
    gap:10px;
    justify-content:flex-start;
    padding-top: 8px !important;
  }

  /* Modal buttons stack */
  #medicalHistoryModal .modal-actions-center{

    gap: 10px !important;
  }

  #medicalHistoryModal .modal-actions-center button{
    width: 100% !important;
  }
}

@media (max-width: 768px){
  #medicalHistoryModal .modal-actions-center{
    display:flex !important;
    flex-direction:row !important;
    gap:10px !important;
    flex-wrap:wrap !important;        /* allow wrap */
    justify-content:center !important;
  }
  #medicalHistoryModal .modal-actions-center button{
    flex: 1 1 140px !important;       /* 2 per row on small screens */
    width:auto !important;
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

  <div class="mobile-center">Patient Health Records</div>

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
                                <p class="profile-title"><?php echo substr($username,0,100) ?></p>
                                <p class="profile-subtitle"><?php echo substr($useremail,0,100)  ?></p>
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
                <tr class="menu-row">
                        <td class="menu-btn ">
                            <a href="index.php" class="non-style-link-menu ">
                                <p class="menu-text">Dashboard</p>
                            </a>
                        </td>
                    </tr>
                    <tr class="menu-row">
                        <td class="menu-btn ">
                            <a href="doctors.php" class="non-style-link-menu">
                                <p class="menu-text">Doctors</p>
                            </a>
                        </td>
                    </tr>
                    <tr class="menu-row">
                        <td class="menu-btn ">
                            <a href="schedule.php" class="non-style-link-menu">
                                <p class="menu-text">Schedule</p>
                            </a>
                        </td>
                    </tr>   
                    <tr class="menu-row">
                        <td class="menu-btn ">
                            <a href="bed.php" class="non-style-link-menu ">
                                <p class="menu-text">Bed Occupancy</p>
                            </a>
                        </td>
                    </tr>
                    <tr class="menu-row">
                        <td class="menu-btn ">
                            <a href="queing.php" class="non-style-link-menu ">
                                <p class="menu-text">Queue</p>
                            </a>
                        </td>
                      </tr>

                    <tr class="menu-row">
                        <td class="menu-btn menu-active">
                            <a href="patient.php" class="non-style-link-menu non-style-link-menu-active">
                                <p class="menu-text">Patients Health Record</p>
                            </a>
                        </td>
                    </tr>
                    <tr class="menu-row">
                        <td class="menu-btn ">
                            <a href="archive.php" class="non-style-link-menu">
                                <p class="menu-text">Archives</p>
                            </a>
                        </td>
                    </tr>
                    <tr class="menu-row">
                        <td class="menu-btn ">
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
            </table>
        </div>


        <div class="dash-body">
            <table border="0" width="100%" style="border-spacing: 0; margin: 0; padding: 0; margin-top: 25px;">
                <tr>
                    <td>
                        <form action="" method="post" class="header-search" id="patientSearchForm">
                          <input id="searchInput" type="search" name="search" class="input-text header-searchbar"
                                placeholder="Search Patient name or Email" list="patient">&nbsp;&nbsp;
                          <input id="searchBtn" type="submit" value="Search" class="login-btn btn-primary btn"
                                style="padding-left:25px;padding-right:25px;padding-top:10px;padding-bottom:10px;width:15%;">
                        </form>
                    </td>
                    <td width="15%">
                        <p style="font-size: 14px;color: rgb(119, 119, 119);padding: 0;margin: 0;text-align: right;">
                            Today's Date
                        </p>
                        <p class="heading-sub12" style="padding: 0; margin: 0;">
                            <?php
                                 echo $date;
                            ?>
                        </p>
                    </td>
                    <td width="10%">
                        <button class="btn-label" style="display: flex; justify-content: center; align-items: center;"><img src="../img/calendar.svg" width="100%"></button>
                    </td>
                </tr>
                <tr>
                    <td colspan="4" style="padding-top:10px;">
                        <p class="heading-main12" style="margin-left:45px;font-size:18px;color:rgb(49,49,49)">
                          All Patients (<span id="patientCount"><?= $patientCount ?></span>)
                        </p>
                    </td>
                </tr>
               <?php
/* ---------- BUILD RESULT SET: $rows ---------- */
$allPatients = $allPatientsInit;
$rows        = $allPatients; // default: show all


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search'])) {
    $keyword = trim((string)$_POST['search']);
    if ($keyword !== '') {
        $kw   = strtolower($keyword);
        $rows = []; // filtered

        foreach ($allPatients as $pid => $patient) {
            // Collect searchable fields safely
            $fname   = (string)($patient['fname'] ?? '');
            $lname   = (string)($patient['lname'] ?? '');
            $name    = (string)($patient['name'] ?? trim($fname . ' ' . $lname));
            $email   = (string)($patient['email'] ?? '');
            $tele    = (string)($patient['tele'] ?? '');
            $dob     = (string)($patient['dob'] ?? '');
            $gender  = (string)($patient['gender'] ?? '');
            $civil   = (string)($patient['civil_status'] ?? '');
            $barangay= (string)($patient['barangay'] ?? '');
            $city    = (string)($patient['city'] ?? '');
            $province= (string)($patient['province'] ?? '');
            $addr    = trim("$barangay $city $province");

            // Case-insensitive contains match on any field
            $haystack = [$name, $fname, $lname, $email, $tele, $dob, $gender, $civil, $addr];
            foreach ($haystack as $field) {
                if ($field !== '' && stripos($field, $kw) !== false) {
                    $rows[$pid] = $patient;
                    break;
                }
            }
        }
    }
}
?>

                <tr>
                    <td colspan="4">
                        <center>
                            <div class="abc scroll">
                                <table width="93%" class="sub-table scrolldown" style="border-spacing:0;">
                                    <thead>
                                        <tr>
                                            <th class="table-headin">Name</th>
                                            <th class="table-headin">Age</th>
                                            <th class="table-headin">Phone No.</th>
                                            <th class="table-headin">Email</th>
                                            <th class="table-headin">Date of Birth</th>
                                            <th class="table-headin">Gender</th>
                                            <th class="table-headin">Civil Status</th>
                                            <th class="table-headin">Events</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                               <?php
if (empty($rows)) {
    echo '<tr>
        <td colspan="8" style="text-align:center; vertical-align:middle;">
            <br><br><br><br>
            <center>
                <img src="../img/notfound.svg" width="25%">
                <br>
                <p class="heading-main12" style="font-size:20px; color:rgb(49, 49, 49);">
                    We couldn\'t find anything related to your search.
                </p>
                <a class="non-style-link" href="patient.php">
                    <button class="login-btn btn-primary-soft btn" style="display:flex;justify-content:center;align-items:center;margin-left:auto;margin-right:auto;">
                        &nbsp; Show all Patients &nbsp;
                    </button>
                </a>
            </center>
            <br><br><br><br>
        </td>
    </tr>';
} else {
    foreach ($rows as $id => $patient) {
        $fname     = (string)($patient['fname'] ?? '');
        $lname     = (string)($patient['lname'] ?? '');
        $name      = (string)($patient['name'] ?? trim($fname . ' ' . $lname));
        $age       = (string)($patient['age'] ?? '');
        $email     = (string)($patient['email'] ?? 'No Account Yet');
        $gender    = (string)($patient['gender'] ?? '');
        $civil     = (string)($patient['civil_status'] ?? '');
        $dob       = (string)($patient['dob'] ??'');
        $tele      = (string)($patient['tele'] ?? '');
        $barangay  = (string)($patient['barangay'] ?? '');
        $barangay  = ($barangay === '0') ? '' : $barangay;
        $city      = (string)($patient['city'] ?? '');
        $province  = (string)($patient['province'] ?? '');
        $type      = (string)($patient['type'] ?? '');

        $patientUid = (string)($patient['patient_uid'] ?? '');

        $rawEmail = (string)($patient['email'] ?? '');
        $hasEmail = strlen(trim($rawEmail)) > 0 && filter_var(trim($rawEmail), FILTER_VALIDATE_EMAIL);
        $eventsTd =
  '<button 
      class="viewButton btn button-icon btn-view"
      style="padding-left:40px; padding-top:12px; padding-bottom:12px; margin-top:10px;"
      data-key="' . htmlspecialchars($id) . '"
      data-puid="' . htmlspecialchars($patientUid) . '"
      data-name="' . htmlspecialchars($name) . '"
      data-fname="' . htmlspecialchars($fname) . '"
      data-lname="' . htmlspecialchars($lname) . '"
      data-gender="' . htmlspecialchars($gender) . '"
      data-tele="' . htmlspecialchars($tele) . '"
      data-email="' . htmlspecialchars($email) . '"
      data-dob="' . htmlspecialchars($dob) . '"
      data-age="' . htmlspecialchars($age) . '"
      data-barangay="' . htmlspecialchars($barangay) . '"
      data-city="' . htmlspecialchars($city) . '"
      data-province="' . htmlspecialchars($province) . '"
      data-civil-status="' . htmlspecialchars($civil) . '"
      data-type="' . htmlspecialchars($type) . '"
      onclick="openModal(this)">
      <font class="tn-in-text">View</font>
   </button>';


        echo '<tr style="text-align:center; vertical-align:middle;">
            <td style="border-bottom:1px solid #ddd;">' . htmlspecialchars(substr($name, 0, 50)) . '</td>
            <td style="border-bottom:1px solid #ddd;">' . htmlspecialchars(substr($age, 0, 50)) . '</td>
            <td style="border-bottom:1px solid #ddd;">' . htmlspecialchars(substr($tele, 0, 50)) . '</td>
            <td style="border-bottom:1px solid #ddd;">' . htmlspecialchars(substr($email, 0, 50)) . '</td>
            <td style="border-bottom:1px solid #ddd;">' . htmlspecialchars(substr($dob, 0, 50)) . '</td>
            <td style="border-bottom:1px solid #ddd;">' . htmlspecialchars(substr($gender, 0, 50)) . '</td>
            <td style="border-bottom:1px solid #ddd;">' . htmlspecialchars(substr($civil, 0, 50)) . '</td>
            <td><div style="display:flex;justify-content:center;">' . $eventsTd . '</div></td>
        </tr>';
    }
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

<?php if (isset($_SESSION['success_message'])): ?>
    <script>
        alert("<?php echo addslashes($_SESSION['success_message']); ?>");
    </script>
    <?php unset($_SESSION['success_message']); ?>
<?php endif; ?>

<!-- Create Account Modal -->
<div id="createAccountModal">
  <div class="modal-content">
    <span class="close" onclick="closeCreateAccountModal()">&times;</span>
    <h2>Create Account</h2>

    <form method="POST" action="" onsubmit="return confirm('Create account for this patient?');">
      <input type="hidden" name="action" value="create_account">
      <input type="hidden" id="ca_patientId" name="patientId">

      <div class="form-row" style="margin-bottom:10px;">
        <label>Patient</label>
        <input id="ca_patientName" type="text" readonly>
      </div>

      <div class="form-row" style="margin-bottom:10px;">
        <label for="ca_email">Email</label>
        <input id="ca_email" name="email" type="email" required placeholder="patient@example.com">
      </div>

      <!-- Password with reveal icon -->
      <div class="form-row" style="margin-bottom:10px; position:relative;">
        <label for="ca_password">Temporary Password</label>
        <input id="ca_password" name="password" type="password" required minlength="6" placeholder="min 6 characters" style="padding-right:30px;">
        
        <!-- Eye icon -->
        <span onclick="togglePassword()" 
              style="position:absolute; right:8px; top:10px; cursor:pointer; font-size:40px; color:#555;">
          👁
        </span>
      </div>

      <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:16px;">
        <button type="button" class="btn" onclick="closeCreateAccountModal()">Cancel</button>
        <button type="submit" class="btn btn-primary-soft">Create</button>
      </div>
    </form>
  </div>
</div>


<!-- Add Laboratory Result Modal -->
<div id="labResultModal" class="overlay" style="display:none;">
  <div class="overlay-content" style="max-width:500px; width:90%;">
    <span style="float:right; font-size:24px; cursor:pointer;" onclick="closeLabResultModal()">&times;</span>
    <h3 style="margin-top:5px; text-align:center;">Add Laboratory Result</h3>

    <form id="labResultForm">
      <!-- Hidden identifiers -->
      <input type="hidden" id="lab_patient_uid" name="patient_uid">
      <input type="hidden" id="lab_patient_email" name="patient_email">

      <div style="margin-bottom:10px;">
        <label>Patient</label>
        <input type="text" id="lab_patient_name" class="input-text" readonly>
      </div>

      <div style="margin-bottom:10px;">
        <label>Test Type</label>
        <input type="text" name="test_type" id="lab_test_type" class="input-text" required placeholder="CBC, X-Ray, etc.">
      </div>

      <div style="margin-bottom:10px;">
        <label>Status</label>
        <select name="status" id="lab_status" class="input-text">
          <option value="pending">Pending</option>
          <option value="processing">Processing</option>
          <option value="ready">Ready</option>
        </select>
      </div>

      <div style="margin-bottom:10px;">
        <label>Requested Date</label>
        <input type="date" name="requested_date" id="lab_requested_date" class="input-text">
      </div>

      <div style="margin-bottom:10px;">
        <label>Expected Ready Date</label>
        <input type="date" name="expected_ready_date" id="lab_expected_ready_date" class="input-text">
      </div>

      <div style="margin-bottom:10px;">
        <label>Ready Time (optional)</label>
        <input type="time" name="ready_time" id="lab_ready_time" class="input-text">
      </div>

      <div style="margin-bottom:10px;">
        <label>Requesting Doctor</label>
        <input type="text" name="requesting_doctor" id="lab_requesting_doctor" class="input-text"
               placeholder="Dr. Name">
      </div>

      <div style="margin-bottom:10px;">
        <label>Lab Email</label>
        <input type="email" name="lab_email" id="lab_email" class="input-text"
               placeholder="lab@example.com">
      </div>

      <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:15px;">
        <button type="button" class="login-btn btn-primary-soft small-btn" onclick="closeLabResultModal()">Cancel</button>
        <button type="submit" class="login-btn btn-primary-soft small-btn">Save</button>
      </div>
    </form>
  </div>
</div>


<!-- Medical History Modal  -->
<div id="medicalHistoryModal" class="overlay" style="display:none;">
  <div class="overlay-content overlay-content--wide">

    <span id="closeModal" style="cursor:pointer;">&times;</span>
    <h3 style="text-align: center;">Medical History Form</h3>

    <form id="save-history-form" action="save_medical_history.php" method="POST" <?= $submitBlocked ?>>
      <input type="hidden" id="patient_uid" name="patient_uid" value="">
      <input type="hidden" id="patient_fname" name="patient_fname">
      <input type="hidden" id="patient_lname" name="patient_lname">

      <table class="medical-history-table">
        <tr>
          <div id="record-status" style="text-align:center;font-weight:bold;margin-bottom:10px;" <?= $attrReadonly ?>></div>
          <th>Patient Name:</th>
          <td><input type="text" id="patient_name" name="patient_name" <?= $attrReadonly ?> required></td>
          <th>Date of Last Update:</th>
          <td><input type="date" id="last_update" name="last_update" readonly required></td>
        </tr>
        <tr>
          <th>Age:</th>
          <td><input type="text" id="age" name="age" readonly></td>
          <th>Gender:</th>
          <td><input type="text" id="gender" name="gender" readonly></td>
        </tr>
        <tr>
          <th>Date of Birth:</th>
          <td><input type="date" id="dob" name="dob" ></td>
          <th>Civil Status:</th>
          <td><input type="text" id="civil_status" name="civil_status" readonly></td>
        </tr>
        <tr>
          <th>Email:</th>
          <td><input type="text" id="email" name="email" <?= $attrReadonly ?> required></td>
          <th>Phone:</th>
          <td><input type="text" id="tele" name="tele" readonly></td>
        </tr>
        <tr>
          <th>Barangay:</th>
          <td><input type="text" id="barangay" name="barangay" readonly></td>
          <th>City:</th>
          <td><input type="text" id="city" name="city" readonly></td>
        </tr>
        <tr>
          <th>Province:</th>
          <td><input type="text" id="province" name="province" readonly></td>
          <th>Current Physician Name:</th>
          <td>
            <select id="physician_name" name="physician_name" <?= $attrDisabled ?> onchange="updatePhysicianPhone()">
              <option value="">Select a Physician</option>
           <?php
try {
    // Normalize admin specialties (comma-separated) for matching
    $adminSpecs = array_filter(array_map(
        fn($s) => trim(mb_strtolower($s)),
        explode(',', (string)$adminSpecialty)
    ));

    // Fetch all doctors once
    $all = $database->getReference('doctor')->getValue() ?: [];

    // If no specialty (or view-only), just show everyone
    $filtered = [];
    if (empty($adminSpecs) || !$canEdit) {
        $filtered = $all;
    } else {
        // 1) Exact membership match (handles string OR array fields)
        foreach ($all as $k => $doc) {
            $docSpecs = [];
            if (isset($doc['sname'])) {
                $docSpecs = is_array($doc['sname']) ? $doc['sname'] : [$doc['sname']];
            } elseif (isset($doc['specialty'])) {
                $docSpecs = is_array($doc['specialty']) ? $doc['specialty'] : [$doc['specialty']];
            }
            $docSpecs = array_map(fn($s) => trim(mb_strtolower((string)$s)), $docSpecs);

            if (!empty(array_intersect($adminSpecs, $docSpecs))) {
                $filtered[$k] = $doc;
            }
        }

        // 2) If still empty, try a lenient contains match (trailing spaces, variants)
        if (empty($filtered)) {
            foreach ($all as $k => $doc) {
                $cand = mb_strtolower(trim((string)($doc['sname'] ?? $doc['specialty'] ?? '')));
                foreach ($adminSpecs as $s) {
                    if ($s !== '' && $cand !== '' && mb_strpos($cand, $s) !== false) {
                        $filtered[$k] = $doc; break;
                    }
                }
            }
        }
    }

    if ($filtered) {
        foreach ($filtered as $key => $doctor) {
            $doctorName  = $doctor['name'] ?? trim(($doctor['fname'] ?? '').' '.($doctor['lname'] ?? '')) ?: 'Unknown Doctor';
            $doctorPhone = $doctor['tele'] ?? '';
            echo "<option value='" . htmlspecialchars($doctorName, ENT_QUOTES) . "' data-phone='" . htmlspecialchars($doctorPhone, ENT_QUOTES) . "'>"
               . htmlspecialchars($doctorName) . "</option>";
        }
    } else {
        echo "<option value=''>No doctors available</option>";
    }
} catch (\Throwable $e) {
    error_log('Doctors select failed: '.$e->getMessage());
    echo "<option value=''>Error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES) . "</option>";
}
?>


            </select>
          </td>
        </tr>
        <tr>
          <th>Physician's Contact:</th>
          <td colspan="3"><input type="tel" id="physician_phone" name="physician_phone" maxlength="11" readonly></td>
        </tr>
      </table>

      <!-- Current and Past Medications -->
      <h4>Current and Past Medications</h4>
      <table border="1" style="width:100%;">
        <thead>
          <tr>
            <th>Medication Name</th>
            <th>Dosage</th>
            <th>Frequency</th>
            <th>Physician</th>
            <th>Start Date</th>
            <th>End Date</th>
            <th style="width:92px;">Actions</th>
          </tr>
        </thead>
        <tbody id="medication-rows"></tbody>
      </table>

      <!-- Surgical Procedures -->
      <h4>Surgical Procedures</h4>
      <table border="1" style="width:100%;">
        <thead>
          <tr>
            <th>Procedure</th>
            <th>Physician</th>
            <th>Hospital</th>
            <th>Date</th>
            <th>Notes</th>
            <th style="width:92px;">Actions</th>
          </tr>
        </thead>
        <tbody id="surgical-rows"></tbody>
      </table>

      <!-- Major Illnesses -->
      <h4>Major Illnesses</h4>
      <table border="1" style="width:100%;">
        <thead>
          <tr>
            <th>Illness</th>
            <th>Start Date</th>
            <th>End Date</th>
            <th>Physician</th>
            <th>Treatment Notes</th>
            <th style="width:92px;">Actions</th>
          </tr>
        </thead>
        <tbody id="illness-rows"></tbody>
      </table>

      <!-- Vaccinations -->
      <h4>Vaccinations</h4>
      <table border="1" style="width:100%;">
        <thead>
          <tr>
            <th>Name</th>
            <th>Date</th>
            <th style="width:92px;">Actions</th>
          </tr>
        </thead>
        <tbody id="vaccine-rows"></tbody>
      </table>


      <!-- Laboratory Results (new node) -->
      <h4>Laboratory Results</h4>
      <table border="1" style="width:100%;">
        <thead>
          <tr>
            <th>Test</th>
            <th>Status</th>
            <th>Ready / Expected Date</th>
            <th>Requested By</th>
          </tr>
        </thead>
        <tbody id="lab-rows"></tbody>
      </table>
      <?php if ($canEdit): ?>
      <div style="text-align:left; margin-top:8px;">
        <button type="button" class="login-btn btn-primary-soft small-btn" onclick="openAddLabResult()">
          Add Lab Result
        </button>
      </div>
      <?php endif; ?>

      <br><br>
      <div class="modal-actions-center">

  <?php if ($canEdit): ?>
    <button type="submit" class="login-btn btn-primary-soft small-btn">Save</button>
    <button id="archive-btn" class="login-btn btn-primary-soft small-btn" type="button">Archive</button>
    <button id="print-btn" class="login-btn btn-primary-soft small-btn" type="button" onclick="printMedicalHistory()">Print</button>
    <button id="create-account-inline" type="button" class="login-btn btn-primary-soft small-btn" onclick="openCreateAccountFromModal()" style="display:none;">Create Account</button>
  <?php endif; ?>
</div>

    </form>
  </div>
</div>

<div class="floating-btn-container">
<input type="file" id="file-input" accept=".pdf,.png,.jpg,.jpeg,.docx" style="display: none;" />
  <button id="floating-btn2" class="floating-btn">
    <img src="../img/icons/camera.svg" alt="Camera Icon">
  </button>

<div id="confirmation-overlay" class="overlay hidden">
  <div class="overlay-content">
    <p id="confirmation-message"></p>
    <div style="display: flex; gap: 20px; margin-left: 90px">
        <button  class="login-btn btn-primary-soft small-btn"  type="button" onclick="executePendingAction()">Confirm</button>
        <button class="login-btn btn-primary-soft small-btn" style="background-color:#ff0000;" type="button" onclick="closeOverlay()">Cancel</button>
    </div>
  </div>
</div>

<?php if (!$canEdit): ?>
<div id="viewOnlyPopup" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 2000; display: flex; align-items: center; justify-content: center;">
  <div style=" background: #fff; padding: 25px 40px; border-radius: 8px; box-shadow: 0 4px 8px rgba(0,0,0,0.2); text-align: center; max-width: 400px;">
    <p style="font-weight:bold; color:#b91c1c; margin-bottom:20px;">
      You have view-only access to this medical form.
    </p>
    <button onclick="document.getElementById('viewOnlyPopup').style.display='none';"
            style="padding: 8px 16px; background:#45a049; color:#fff; border:none; border-radius:4px; cursor:pointer;">
      OK
    </button>
  </div>
</div>
<?php endif; ?>

<div class="loading-screen" id="loadingScreen">
    <div class="loading-content">
        <div class="spinner"></div>
        <p class="loading-text">Loading...</p>
    </div>
</div>



<script>
  // Server-provided edit flag
  const CAN_EDIT = <?= $canEdit ? 'true' : 'false' ?>;
  let pendingAction = null;

  document.addEventListener('DOMContentLoaded', () => {
  const burger  = document.getElementById('hamburger-menu');
  const overlay = document.getElementById('menu-overlay');
  const body    = document.body;

  if (!burger) return;

  const closeMenu = () => {
    body.classList.remove('menu-open');
    burger.classList.remove('active');
  };

  burger.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    body.classList.toggle('menu-open');
    burger.classList.toggle('active'); // ✅ this is what changes the icon
  });

  overlay?.addEventListener('click', (e) => {
    e.preventDefault();
    closeMenu();
  });

  document.querySelectorAll('.menu a').forEach(a => {
    a.addEventListener('click', closeMenu);
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeMenu();
  });
});


// Lazy-load OCR deps only when needed
async function loadOCRDeps() {
  if (window._ocrDepsLoaded) return;
  const inject = (src) => new Promise((res, rej) => {
    const s = document.createElement('script'); s.src = src; s.defer = true;
    s.onload = res; s.onerror = () => rej(new Error('Failed to load ' + src));
    document.head.appendChild(s);
  });
  await inject('https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js');
  await inject('https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js');
  await inject('https://cdn.jsdelivr.net/npm/tesseract.js@2.1.1/dist/tesseract.min.js');
  await inject('https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.4.2/mammoth.browser.min.js');

  if (window.pdfjsLib) {
    pdfjsLib.GlobalWorkerOptions.workerSrc =
      'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';
  }
  window._ocrDepsLoaded = true;
}
async function ensureOCRReady() { await loadOCRDeps(); }

  /************************************************************
   * LOADING OVERLAY — only for OCR, SAVE, ARCHIVE, PRINT
   ************************************************************/
  function ensureLoaderDom() {
    let overlay = document.getElementById('loadingScreen');
    if (!overlay) {
      overlay = document.createElement('div');
      overlay.id = 'loadingScreen';
      overlay.className = 'loading-screen';
      overlay.innerHTML = `
        <div class="loading-content">
          <div class="spinner"></div>
          <p class="loading-text">Loading...</p>
        </div>`;
      document.body.appendChild(overlay);
    } else {
      document.body.appendChild(overlay);
    }
    return overlay;
  }

  function nextPaint() {
    return new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));
  }

  async function showLoader() {
    const overlay = ensureLoaderDom();
    const t = overlay.querySelector('.loading-text');
    if (t) t.textContent = 'Loading...';
    overlay.style.display = 'flex';
    void overlay.offsetHeight;
    await nextPaint();
  }

  function updateLoader(_msg = '') {}
  function hideLoader() {
    const overlay = document.getElementById('loadingScreen');
    if (!overlay) return;
    overlay.style.display = 'none';
  }

  /************************************************************
   * OCR/Text Post-Processing Helpers
   ************************************************************/
  const KNOWN_CORRECTIONS = [
    { pattern: /\bDr\s*exther\b/gi, replace: 'Drexther' },
    { pattern: /\b([A-Z])\s+([a-z]{3,20})\b/g, replace: (_m, a, b) => `${a}${b}` }
  ];

  function fixDoctorSplit(text) {
    return text.replace(/\bDr\s+([a-z][a-z]+)\b/g, (_m, next) => `Dr${next}`);
  }

  function normalizeWhitespace(text) {
    return text
      .replace(/\s+:\s+/g, ': ')
      .replace(/[ \t]+\n/g, '\n')
      .replace(/\n[ \t]+/g, '\n')
      .replace(/[ \t]{2,}/g, ' ')
      .replace(/\n{3,}/g, '\n\n')
      .trim();
  }

  function applyKnownCorrections(text) {
    let out = text;
    for (const rule of KNOWN_CORRECTIONS) out = out.replace(rule.pattern, rule.replace);
    return out;
  }

  function postProcessOCRText(raw) {
    let t = normalizeWhitespace(raw);
    t = fixDoctorSplit(t);
    t = applyKnownCorrections(t);
    return t;
  }

  /***********************
   * File Upload (OCR ONLY)
   ***********************/
  document.addEventListener("DOMContentLoaded", function () {
    const fileInput = document.getElementById("file-input");
    const floatingBtn = document.getElementById("floating-btn2");

    if (floatingBtn && fileInput) {
      floatingBtn.addEventListener("click", function () {
        if (!CAN_EDIT) return;
        fileInput.click();
      });
    }

    if (fileInput) {
      fileInput.addEventListener("change", async (event) => {
        const file = event.target.files[0];
        if (!file) return;

        // NEW: load heavy libs on demand
        await ensureOCRReady();

        const fileType = (file.name.split('.').pop() || '').toLowerCase();

        try {
          showLoader('Reading file…');
          await nextPaint();

          if (file.type.includes('image')) {
            await processImage(file);
          } else if (file.type === 'application/pdf' || fileType === 'pdf') {
            await processPDF(file);
          } else if (fileType === 'docx') {
            await processDocx(file);
          } else {
            alert('Unsupported file type. Please upload an image, PDF, or Word document.');
          }
        } catch (err) {
          console.error(err);
          alert("Failed to process the file.");
        } finally {
          hideLoader();
        }
      });
    }
  });

  // Image OCR
  function processImage(file) {
    return new Promise((resolve, reject) => {
      updateLoader('Optimizing image for OCR…');
      const reader = new FileReader();
      reader.onerror = reject;
      reader.onload = async (e) => {
        const imgSrc = e.target.result;
        try {
          const preprocessed = await preprocessImage(imgSrc);
          updateLoader('Running OCR on image…');
          await nextPaint();
          const { data: { text } } = await Tesseract.recognize(preprocessed, 'eng', {
            tessedit_pageseg_mode: 6,
            user_defined_dpi: '300'
          });
          updateLoader('Saving extracted text…');
          await sendToBackend(postProcessOCRText(text));
          resolve();
        } catch (err) {
          console.error(err);
          reject(err);
        }
      };
      reader.readAsDataURL(file);
    });
  }

  function preprocessImage(dataUrl) {
    return new Promise((resolve) => {
      const canvas = document.createElement("canvas");
      const ctx = canvas.getContext("2d");
      const img = new Image();
      img.onload = () => {
        canvas.width = img.width;
        canvas.height = img.height;
        ctx.filter = 'contrast(200%) brightness(150%) grayscale(100%)';
        ctx.drawImage(img, 0, 0);
        resolve(canvas.toDataURL());
      };
      img.src = dataUrl;
    });
  }

  // DOCX
  function processDocx(file) {
    return new Promise((resolve, reject) => {
      updateLoader('Parsing DOCX…');
      const reader = new FileReader();
      reader.onerror = reject;
      reader.onload = async (event) => {
        try {
          const result = await mammoth.extractRawText({ arrayBuffer: event.target.result });
          updateLoader('Saving extracted text…');
          await sendToBackend(postProcessOCRText(result.value || ''));
          resolve();
        } catch (err) {
          console.error("DOCX Error:", err);
          reject(err);
        }
      };
      reader.readAsArrayBuffer(file);
    });
  }

  /************************************************************
   * PDF hybrid extraction
   ************************************************************/
  function processPDF(file) {
    return new Promise((resolve, reject) => {
      if (!window.pdfjsLib) {
        reject(new Error('PDF engine is not available. Please confirm pdf.js scripts are included.'));
        return;
      }

      const reader = new FileReader();
      reader.onerror = reject;
      reader.onload = async (e) => {
        try {
          const typedArray = new Uint8Array(e.target.result);

          updateLoader('Loading PDF…');
          await nextPaint();
          const loadingTask = pdfjsLib.getDocument({
            data: typedArray,
            useWorker: true,
            disableRange: true,
            disableStream: true,
            disableAutoFetch: true
          });

          const pdf = await loadingTask.promise;

          let finalText = '';
          for (let i = 1; i <= pdf.numPages; i++) {
            updateLoader(`Reading PDF page ${i} of ${pdf.numPages}…`);
            const page = await pdf.getPage(i);

            let pageText = await extractTextLayer(page);
            const layerLooksBad = looksSuspicious(pageText);

            if (!pageText || layerLooksBad) {
              updateLoader(`OCR on page ${i} of ${pdf.numPages}…`);
              await nextPaint();
              const ocrText = await ocrPdfPage(page);
              if (ocrText && ocrText.trim().length >= (pageText || '').trim().length) {
                pageText = ocrText;
              }
            }

            finalText += (pageText || '') + '\n';
          }

          updateLoader('Saving extracted text…');
          await sendToBackend(postProcessOCRText(finalText));
          resolve();
        } catch (err) {
          console.error("PDF Error:", err);
          (async () => {
            try {
              await ocrEntirePdfViaPages(file);
              resolve();
            } catch (e2) {
              reject(e2);
            }
          })();
        }
      };
      reader.readAsArrayBuffer(file);
    });
  }

  async function extractTextLayer(page) {
    try {
      const textContent = await page.getTextContent();
      const text = textContent.items.map(item => item.str).join(' ');
      return normalizeWhitespace(text);
    } catch {
      return '';
    }
  }

  function looksSuspicious(text) {
    if (!text) return true;
    const tooShort = text.trim().length < 20;
    const drSplit  = /\bDr\s+[a-z]/.test(text);
    const letterSplit = /\b[A-Z]\s+[a-z]{3,20}\b/.test(text);
    return tooShort || drSplit || letterSplit;
  }

  async function ocrPdfPage(page) {
    const scale = Math.max(2.5, Math.min(4.0, 300 / 96));
    const viewport = page.getViewport({ scale });

    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    canvas.width = Math.ceil(viewport.width);
    canvas.height = Math.ceil(viewport.height);

    await page.render({ canvasContext: ctx, viewport }).promise;

    try {
      const imgData = ctx.getImageData(0, 0, canvas.width, canvas.height);
      const data = imgData.data;
      for (let i = 0; i < data.length; i += 4) {
        const r = data[i], g = data[i+1], b = data[i+2];
        let y = 0.299*r + 0.587*g + 0.114*b;
        y = (y - 128) * 1.4 + 128;
        y = Math.min(255, Math.max(0, y + 20));
        data[i] = data[i+1] = data[i+2] = y;
      }
      ctx.putImageData(imgData, 0, 0);
    } catch (e) {
      console.warn('Canvas preprocessing skipped:', e);
    }

    try {
      const { data: { text } } = await Tesseract.recognize(canvas, 'eng', {
        tessedit_pageseg_mode: 6,
        user_defined_dpi: '300'
      });
      return normalizeWhitespace(text);
    } catch (e) {
      console.error('OCR fallback failed:', e);
      return '';
    }
  }

  async function ocrEntirePdfViaPages(file) {
    updateLoader('OCR fallback: rendering PDF pages…');
    const buf = await file.arrayBuffer();
    const pdf = await pdfjsLib.getDocument({
      data: new Uint8Array(buf),
      useWorker: true,
      disableRange: true,
      disableStream: true,
      disableAutoFetch: true
    }).promise;

    let text = '';
    for (let p = 1; p <= pdf.numPages; p++) {
      updateLoader(`OCR fallback: page ${p} of ${pdf.numPages}…`);
      const page = await pdf.getPage(p);
      const t = await ocrPdfPage(page);
      text += (t || '') + '\n';
    }
    updateLoader('Saving extracted text…');
    await sendToBackend(postProcessOCRText(text));
  }

  async function sendToBackend(text) {
    updateLoader('Saving to database…');
    await nextPaint();
    const normalized = postProcessOCRText(text);
    try {
      const res = await fetch('save_to_firebase.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ extractedText: normalized })
      });
      const data = await res.json();
      hideLoader();
      if (data.success) {
        alert("Data saved successfully!");
        location.reload();
      } else {
        alert("Error saving data: " + (data.error || 'Unknown error'));
      }
    } catch (err) {
      console.error("Fetch error:", err);
      hideLoader();
      alert("Failed to read the data. Please try again.");
      throw err;
    }
  }

  /*************************
   * Modal open/close etc.
   *************************/
  document.getElementById('closeModal').addEventListener('click', function () {
    document.getElementById('medicalHistoryModal').style.display = 'none';
  });

  function showConfirmationOverlay(message) {
    var overlay = document.getElementById('confirmation-overlay');
    document.getElementById('confirmation-message').textContent = message || "Are you sure?";
    overlay.classList.remove('hidden');
  }
  function closeOverlay() { document.getElementById('confirmation-overlay').classList.add('hidden'); }
  function executePendingAction() {
    if (pendingAction) { pendingAction(); pendingAction = null; closeOverlay(); }
  }
  window.executePendingAction = executePendingAction;

  // ARCHIVE ONLY
  document.getElementById('archive-btn')?.addEventListener('click', function () {
    if (!CAN_EDIT) return;
    const puid = (document.getElementById('patient_uid')?.value || '').trim();
    if (!puid) {
      alert("No patient selected for archiving.");
    } else {
      pendingAction = async function () {
        showLoader('Archiving patient…');
        await nextPaint();
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'patient.php';
        form.innerHTML = `
          <input type="hidden" name="action" value="archive">
          <input type="hidden" name="patient_uid" value="${puid}">
        `;
        document.body.appendChild(form);
        form.submit();
      };
      showConfirmationOverlay("Are you sure you want to archive?");
    }
  });

  /****************
   * PRINT ONLY
   ****************/
  window.addEventListener('message', (e) => {
    if (e && e.data && e.data.type === 'PRINT_READY') hideLoader();
  });

  async function printMedicalHistory() {
    showLoader('Preparing print…');
    await nextPaint();

    const form = document.getElementById('save-history-form');

    const inputs = form.querySelectorAll('input, textarea, select');
    inputs.forEach(input => {
      if (input.tagName === 'INPUT' || input.tagName === 'TEXTAREA') {
        input.setAttribute('value', input.value);
      } else if (input.tagName === 'SELECT') {
        const options = input.querySelectorAll('option');
        options.forEach(option => {
          if (option.selected) option.setAttribute('selected', 'selected');
          else option.removeAttribute('selected');
        });
      }
    });

    const formClone = form.cloneNode(true);
    const buttons = formClone.querySelectorAll('button, input[type="button"], input[type="submit"]');
    buttons.forEach(btn => btn.remove());

    const tables = formClone.querySelectorAll('table');
    tables.forEach(table => {
      const tbody = table.querySelector('tbody');

      if (tbody) {
        const dataRows = Array.from(tbody.querySelectorAll('tr'));
        let hasData = false;

        dataRows.forEach(row => {
          const cells = row.querySelectorAll('td');
          const rowHasData = Array.from(cells).some(cell => (cell.textContent || '').trim() !== '');
          if (rowHasData) hasData = true;
        });

        if (!hasData) {
          dataRows.forEach(row => row.remove());
          const headerCells = table.querySelectorAll('thead th');
          const emptyRow = document.createElement('tr');
          headerCells.forEach(() => {
            const td = document.createElement('td');
            td.innerHTML = '&nbsp;';
            emptyRow.appendChild(td);
          });
          tbody.appendChild(emptyRow);
        }
      } else {
        const rows = Array.from(table.querySelectorAll('tr'));
        const headerRow = rows[0];
        const dataRows = rows.slice(1);

        let hasData = false;
        dataRows.forEach(row => {
          const cells = row.querySelectorAll('td');
          const rowHasData = Array.from(cells).some(cell => (cell.textContent || '').trim() !== '');
          if (rowHasData) hasData = true;
        });

        if (!hasData) {
          dataRows.forEach(row => row.remove());
          const headerCells = headerRow.querySelectorAll('th');
          const emptyRow = document.createElement('tr');
          headerCells.forEach(() => {
            const td = document.createElement('td');
            td.innerHTML = '&nbsp;';
            emptyRow.appendChild(td);
          });
          table.appendChild(emptyRow);
        }
      }
    });

    const style = `
      <style>
        body { font-family: Arial, sans-serif; padding: 20px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; }
        th { background-color: #f2f2f2; text-align: left; }
        h3, h4 { text-align: center; margin-top: 20px; }
        input, select { border: none; background: none; width: 100%; }
        .section-title { margin-top: 30px; font-weight: bold; }
      </style>
    `;

    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
      <html>
        <head>
          <title>Medical History</title>
          ${style}
        </head>
        <body>
          <h3>Medical History Form</h3>
          ${formClone.outerHTML}
          <script>
            window.onload = function() {
              try { window.opener && window.opener.postMessage({type:'PRINT_READY'}, '*'); } catch(e) {}
              window.print();
              window.onafterprint = function() { window.close(); };
            }
          <\/script>
        </body>
      </html>
    `);
    printWindow.document.close();
  }
  window.printMedicalHistory = printMedicalHistory;

  /**************************************
   * View button -> open & populate (NO loader)
   **************************************/
  document.addEventListener("DOMContentLoaded", function () {
    const viewButtons = document.querySelectorAll(".viewButton");
    const modal = document.getElementById("medicalHistoryModal");
    const closeModal = document.getElementById("closeModal");

    viewButtons.forEach(button => {
      button.addEventListener("click", function (e) {
        e.preventDefault();
        openModal(this);
      });
    });

    if (closeModal) {
      closeModal.addEventListener("click", function () {
        modal.style.display = "none";
      });
    }

    window.addEventListener("click", function (event) {
      if (event.target === modal) modal.style.display = "none";
    });
  });

  function setVal(id, value) {
    const el = document.getElementById(id);
    if (el) el.value = value ?? '';
    else console.warn('Missing element #' + id);
  }

  function ensureHiddenUid(puid) {
    const form = document.getElementById('save-history-form');
    let hid = document.getElementById('patient_uid');
    if (!hid) {
      hid = document.createElement('input');
      hid.type = 'hidden';
      hid.id   = 'patient_uid';
      hid.name = 'patient_uid';
      form.appendChild(hid);
    }
    hid.value = puid || '';
  }

  // Keep track of current patient (for Create Account inline)
  // NOTE: now stores the Firebase node key too.
  let CURRENT_PATIENT = { key: '', puid: '', fname: '', lname: '', name: '', email: '' };

  function emailLooksMissing(s) {
    if (!s) return true;
    const t = String(s).trim().toLowerCase();
    if (t === '' || t === 'no account yet') return true;
    return !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(t);
  }

  async function openModal(button) {
    const key        = button.getAttribute('data-key') || '';
    const puid       = button.getAttribute('data-puid') || '';
    const name       = button.getAttribute('data-name') || '';
    const fname      = button.getAttribute('data-fname') || '';
    const lname      = button.getAttribute('data-lname') || '';
    const gender     = button.getAttribute('data-gender') || '';
    const tel        = button.getAttribute('data-tele') || '';
    const email      = (button.getAttribute('data-email') || '').trim();
    const dob        = button.getAttribute('data-dob') || '';
    const age        = button.getAttribute('data-age') || '';
    const barangay   = button.getAttribute('data-barangay') || '';
    const city       = button.getAttribute('data-city') || '';
    const province   = button.getAttribute('data-province') || '';
    const civilStatus= button.getAttribute('data-civil-status') || '';

    // Store BOTH key and puid for create-account fallback
    CURRENT_PATIENT = { key, puid, fname, lname, name, email };

    // Show inline Create Account only when email missing/invalid
    const createInlineBtn = document.getElementById('create-account-inline');
    if (createInlineBtn) {
      const shouldShow = CAN_EDIT && emailLooksMissing(email);
      createInlineBtn.style.display = shouldShow ? 'inline-block' : 'none';
    }

    setVal('patient_uid', key);     // legacy field (immediately overwritten below)
    ensureHiddenUid(puid);          // set real UID in hidden field when present

    setVal('patient_name',  name);
    setVal('patient_fname', fname);
    setVal('patient_lname', lname);
    setVal('gender',        gender);
    setVal('tele',          tel);
    setVal('email',         email);
    setVal('dob',           dob);
    setVal('age',           age);
    setVal('barangay',      barangay);
    setVal('city',          city);
    setVal('province',      province);
    setVal('civil_status',  civilStatus);

   try {
  const r = await fetch(`get_medical_record.php?puid=${encodeURIComponent(puid)}`);
  const data = await r.json();
  console.log('Medical record data:', data); // optional, for debugging

  if (data && !data.error) {
    setVal('last_update', data.last_update || '');
    setVal('physician_name',  (data.physician && data.physician.name) || '');
    setVal('physician_phone', (data.physician && data.physician.contact) || '');
    defaultLastUpdateIfEmpty();

    populateMedTable(data.medications || []);
    populateSurgTable(data.surgical_procedures || []);
    populateIllTable(data.illnesses || []);
    populateVaxTable(data.vaccinations || []);

    // NEW: lab results
    populateLabTable(data.lab_results || []);
  } else {
    setVal('last_update', '');
    setVal('physician_name', '');
    setVal('physician_phone', '');
    defaultLastUpdateIfEmpty();

    populateMedTable([]);
    populateSurgTable([]);
    populateIllTable([]);
    populateVaxTable([]);
    populateLabTable([]); // NEW
  }
} catch (err) {
  console.error('Error fetching medical record:', err);
  setVal('last_update', '');
  setVal('physician_name', '');
  setVal('physician_phone', '');
  defaultLastUpdateIfEmpty();

  populateMedTable([]);
  populateSurgTable([]);
  populateIllTable([]);
  populateVaxTable([]);
  populateLabTable([]); // NEW
}


    const modal = document.getElementById('medicalHistoryModal');
    if (modal) modal.style.display = 'block';
  }
  window.openModal = openModal;

  /*****************************************
   * Dynamic rows
   *****************************************/
  function actionButtons(section) {
    if (!CAN_EDIT) return '';
    return `
      <button type="button" class="icon-btn plus-btn" onclick="addRowAfter('${section}', this)">
        <i class="fa fa-plus"></i>
      </button>
      <button type="button" class="icon-btn" onclick="removeRow(this)">
        <i class="fa fa-minus"></i>
      </button>`;
  }

  function medRowHtml(d = {}) {
    const id = d._id || '';
    return `
      <tr>
        <td style="display:none;"><input type="hidden" name="med_id[]" value="${id}"></td>
        <td><input type="text" name="medication_name[]" value="${d.name ?? ''}" <?= $attrReadonly ?>></td>
        <td><input type="text" name="dosage[]" value="${d.dosage ?? ''}" <?= $attrReadonly ?>></td>
        <td><input type="text" name="frequency[]" value="${d.frequency ?? ''}" <?= $attrReadonly ?>></td>
        <td><input type="text" name="med_physician[]" class="physician-field" value="${d.physician ?? ''}" <?= $attrReadonly ?>></td>
        <td><input type="date" name="med_start_date[]" value="${d.start_date ?? ''}" onchange="updateMinEndDate(this)" <?= $attrReadonly ?>></td>
        <td><input type="date" name="med_end_date[]" value="${d.end_date ?? ''}" <?= $attrReadonly ?>></td>
        <td class="row-actions" style="white-space:nowrap;" >${actionButtons('med')}</td>
      </tr>`;
  }

  function surgRowHtml(d = {}) {
    const id = d._id || '';
    return `
      <tr>
        <td style="display:none;"><input type="hidden" name="surg_id[]" value="${id}"></td>
        <td><input type="text" name="procedure[]" value="${d.procedure ?? ''}" <?= $attrReadonly ?>></td>
        <td><input type="text" name="surg_physician[]" class="physician-field" value="${d.physician ?? ''}" <?= $attrReadonly ?>></td>
        <td><input type="text" name="hospital[]" value="${d.hospital ?? ''}" <?= $attrReadonly ?>></td>
        <td><input type="date" name="surg_date[]" value="${d.date ?? ''}" <?= $attrReadonly ?>></td>
        <td><input type="text" name="surg_notes[]" value="${d.notes ?? ''}" <?= $attrReadonly ?>></td>
        <td class="row-actions" style="white-space:nowrap;">${actionButtons('surg')}</td>
      </tr>`;
  }

  function illRowHtml(d = {}) {
    const id = d._id || '';
    return `
      <tr>
        <td style="display:none;"><input type="hidden" name="ill_id[]" value="${id}"></td>
        <td><input type="text" name="illness[]" value="${d.illness ?? ''}" <?= $attrReadonly ?>></td>
        <td><input type="date" name="ill_start_date[]" value="${d.start_date ?? ''}" onchange="updateMinEndDate(this)" <?= $attrReadonly ?>></td>
        <td><input type="date" name="ill_end_date[]" value="${d.end_date ?? ''}" <?= $attrReadonly ?>></td>
        <td><input type="text" name="ill_physician[]" class="physician-field" value="${d.physician ?? ''}" <?= $attrReadonly ?>></td>
        <td><input type="text" name="treatment_notes[]" value="${d.treatment_notes ?? ''}" <?= $attrReadonly ?>></td>
        <td class="row-actions" style="white-space:nowrap;">${actionButtons('ill')}</td>
      </tr>`;
  }

  function vaxRowHtml(d = {}) {
    const id = d._id || '';
    return `
      <tr>
        <td style="display:none;"><input type="hidden" name="vax_id[]" value="${id}"></td>
        <td><input type="text" name="vaccine_name[]" value="${d.name ?? ''}" <?= $attrReadonly ?>></td>
        <td><input type="date" name="vaccine_date[]" value="${d.date ?? ''}" <?= $attrReadonly ?>></td>
        <td class="row-actions" style="white-space:nowrap;">${actionButtons('vax')}</td>
      </tr>`;
  }

  function refreshPlusVisibility(tbodyId) {
    const tbody = document.getElementById(tbodyId);
    if (!tbody) return;
    const rows = Array.from(tbody.querySelectorAll('tr'));
    rows.forEach((tr, idx) => {
      const plusBtn = tr.querySelector('.plus-btn');
      if (!plusBtn) return;
      plusBtn.style.display = (idx === rows.length - 1 && CAN_EDIT) ? 'inline-flex' : 'none';
    });
  }

  function populateMedTable(rows) {
    const tbody = document.getElementById('medication-rows');
    tbody.innerHTML = '';
    if (Array.isArray(rows) && rows.length) rows.forEach(r => tbody.insertAdjacentHTML('beforeend', medRowHtml(r)));
    else tbody.insertAdjacentHTML('beforeend', medRowHtml({}));
    refreshPlusVisibility('medication-rows');
  }
  function populateSurgTable(rows) {
    const tbody = document.getElementById('surgical-rows');
    tbody.innerHTML = '';
    if (Array.isArray(rows) && rows.length) rows.forEach(r => tbody.insertAdjacentHTML('beforeend', surgRowHtml(r)));
    else tbody.insertAdjacentHTML('beforeend', surgRowHtml({}));
    refreshPlusVisibility('surgical-rows');
  }
  function populateIllTable(rows) {
    const tbody = document.getElementById('illness-rows');
    tbody.innerHTML = '';
    if (Array.isArray(rows) && rows.length) rows.forEach(r => tbody.insertAdjacentHTML('beforeend', illRowHtml(r)));
    else tbody.insertAdjacentHTML('beforeend', illRowHtml({}));
    refreshPlusVisibility('illness-rows');
  }
  function populateVaxTable(rows) {
    const tbody = document.getElementById('vaccine-rows');
    tbody.innerHTML = '';
    if (Array.isArray(rows) && rows.length) rows.forEach(r => tbody.insertAdjacentHTML('beforeend', vaxRowHtml(r)));
    else tbody.insertAdjacentHTML('beforeend', vaxRowHtml({}));
    refreshPlusVisibility('vaccine-rows');
  }

  function addRowAfter(section, btn) {
    if (!CAN_EDIT) return;
    const tr = btn.closest('tr');
    let html = '';
    if (section === 'med')  html = medRowHtml({});
    if (section === 'surg') html = surgRowHtml({});
    if (section === 'ill')  html = illRowHtml({});
    if (section === 'vax')  html = vaxRowHtml({});
    tr.insertAdjacentHTML('afterend', html);
    const tbodyId = tr.parentElement.id;
    refreshPlusVisibility(tbodyId);
  }
  window.addRowAfter = addRowAfter;

  function sectionMetaFromTbody(tbody) {
    switch (tbody.id) {
      case 'medication-rows': return { section: 'medications', blankHtml: medRowHtml({}), idInputName: 'med_id[]' };
      case 'surgical-rows':   return { section: 'surgical_procedures', blankHtml: surgRowHtml({}), idInputName: 'surg_id[]' };
      case 'illness-rows':    return { section: 'illnesses', blankHtml: illRowHtml({}), idInputName: 'ill_id[]' };
      case 'vaccine-rows':    return { section: 'vaccinations', blankHtml: vaxRowHtml({}), idInputName: 'vax_id[]' };
      default: return null;
    }
  }

  async function archiveExistingRow(patientUid, section, rowId, patientName) {
    const res = await fetch('archive_medical_row.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ patient_uid: patientUid, section, row_id: rowId, patient_name: patientName || '' })
    });
    return res.json();
  }

function removeRow(btn) {
  if (!CAN_EDIT) return;

  const tr = btn.closest('tr');
  if (!tr) return;

  const tbody = tr.parentElement;
  if (!tbody) return;

  // Remove the row from the DOM
  tr.remove();

  // If table became empty, insert one blank row for that section
  if (!tbody.querySelector('tr')) {
    let html = '';

    switch (tbody.id) {
      case 'medication-rows':
        html = medRowHtml({});
        break;
      case 'surgical-rows':
        html = surgRowHtml({});
        break;
      case 'illness-rows':
        html = illRowHtml({});
        break;
      case 'vaccine-rows':
        html = vaxRowHtml({});
        break;
      default:
        break;
    }

    if (html) {
      tbody.insertAdjacentHTML('beforeend', html);
    }
  }


  // Fix which row shows the + button
  refreshPlusVisibility(tbody.id);
  relabelAllSections();
}

// make sure it's accessible from the inline onclick
window.removeRow = removeRow;

  function openAddLabResult() {
  if (!CAN_EDIT) return;

  const puid  = (CURRENT_PATIENT.puid  || '').trim();
  const email = (CURRENT_PATIENT.email || '').trim();
  const name  = CURRENT_PATIENT.name || ( (CURRENT_PATIENT.fname || '') + ' ' + (CURRENT_PATIENT.lname || '') ).trim();

  if (!puid) {
    alert('Please open a patient first.');
    return;
  }

  // Set hidden / readonly fields
  document.getElementById('lab_patient_uid').value   = puid;
  document.getElementById('lab_patient_email').value = email;
  document.getElementById('lab_patient_name').value  = name;

  // Default dates
  const today = new Date().toISOString().split('T')[0];
  const reqDateInput  = document.getElementById('lab_requested_date');
  const expDateInput  = document.getElementById('lab_expected_ready_date');

  if (reqDateInput && !reqDateInput.value) reqDateInput.value = today;
  if (expDateInput && !expDateInput.value) expDateInput.value = today;

  // Optional: default requesting doctor/lab email
  const docInput = document.getElementById('lab_requesting_doctor');
  const labEmailInput = document.getElementById('lab_email');

  if (docInput && !docInput.value) {
    // you can prefill with current physician or admin name/email if you want
    docInput.value = '';
  }
  if (labEmailInput && !labEmailInput.value) {
    labEmailInput.value = '';
  }

  const modal = document.getElementById('labResultModal');
  if (modal) modal.style.display = 'flex';
}

function closeLabResultModal() {
  const modal = document.getElementById('labResultModal');
  if (modal) modal.style.display = 'none';
}

document.addEventListener('click', function (e) {
  const modal = document.getElementById('labResultModal');
  if (!modal) return;
  if (e.target === modal) {
    closeLabResultModal();
  }
});

document.addEventListener('DOMContentLoaded', function () {
  const labForm = document.getElementById('labResultForm');
  if (!labForm) return;

  labForm.addEventListener('submit', async function (e) {
    e.preventDefault();
    if (!CAN_EDIT) return;

    const formData = new FormData(labForm);

    try {
      await showLoader(); // you already have showLoader/hideLoader
      const res = await fetch('save_lab_result.php', {
        method: 'POST',
        body: formData
      });
      const data = await res.json();
      hideLoader();

      if (data.success) {
        alert('Laboratory result saved.');
        closeLabResultModal();
        // easiest: reload full page so that when you re-open "View", lab results are updated
        location.reload();
      } else {
        alert('Failed to save lab result: ' + (data.error || 'Unknown error'));
      }
    } catch (err) {
      console.error(err);
      hideLoader();
      alert('Error while saving lab result.');
    }
  });
});

function populateLabTable(rows) {
  const tbody = document.getElementById('lab-rows');
  if (!tbody) return;

  // Clear existing rows
  tbody.innerHTML = '';

  if (!Array.isArray(rows) || rows.length === 0) {
    // Show a simple "no results" row
    const tr = document.createElement('tr');
    const td = document.createElement('td');
    td.colSpan = 4;
    td.style.textAlign = 'center';
    td.textContent = 'No laboratory results found.';
    tr.appendChild(td);
    tbody.appendChild(tr);
    return;
  }

  rows.forEach(lab => {
    const tr = document.createElement('tr');

    const tdTest    = document.createElement('td');
    const tdStatus  = document.createElement('td');
    const tdDate    = document.createElement('td');
    const tdDoctor  = document.createElement('td');

    tdTest.textContent   = lab.test_type || 'Laboratory Test';
    tdStatus.textContent = lab.status || 'pending';

    // Prefer expected_ready_date, fallback to requested_date or empty
    const readyDate = lab.expected_ready_date || lab.ready_date || '';
    const readyTime = lab.ready_time || '';
    tdDate.textContent = readyDate
      ? (readyTime ? `${readyDate} ${readyTime}` : readyDate)
      : '';

    tdDoctor.textContent = lab.requesting_doctor || 'Laboratory';

    tr.appendChild(tdTest);
    tr.appendChild(tdStatus);
    tr.appendChild(tdDate);
    tr.appendChild(tdDoctor);

    tbody.appendChild(tr);
  });
}

  function addMedicationRow() { if (CAN_EDIT) document.getElementById('medication-rows').insertAdjacentHTML('beforeend', medRowHtml({}, false)); }
  function addSurgicalRow()   { if (CAN_EDIT) document.getElementById('surgical-rows').insertAdjacentHTML('beforeend',   surgRowHtml({}, false)); }
  function addIllnessRow()    { if (CAN_EDIT) document.getElementById('illness-rows').insertAdjacentHTML('beforeend',    illRowHtml({}, false)); }
  function addVaccineRow()    { if (CAN_EDIT) document.getElementById('vaccine-rows').insertAdjacentHTML('beforeend',    vaxRowHtml({}, false)); }
  window.addMedicationRow = addMedicationRow;
  window.addSurgicalRow   = addSurgicalRow;
  window.addIllnessRow    = addIllnessRow;
  window.addVaccineRow    = addVaccineRow;

  /*******************************
   * Physician & Date utilities
   *******************************/
  function updatePhysicianPhone() {
    const physicianSelect = document.getElementById('physician_name');
    const selectedOption = physicianSelect.options[physicianSelect.selectedIndex];
    const physicianPhone = selectedOption.getAttribute('data-phone') || '';
    const physicianName = physicianSelect.value;

    document.getElementById('physician_phone').value = physicianPhone;
    document.querySelectorAll('.physician-field').forEach(field => field.value = physicianName);
  }
  window.updatePhysicianPhone = updatePhysicianPhone;

  function updateMinEndDate(startDateInput) {
    const row = startDateInput.closest('tr');
    const endDateInput = row.querySelector('input[type="date"][name*="end_date"]');
    const startDate = startDateInput.value;
    if (!endDateInput) return;

    if (startDate) {
      endDateInput.min = startDate;
      if (endDateInput.value && endDateInput.value < startDate) {
        endDateInput.value = '';
      }
    } else {
      endDateInput.removeAttribute('min');
    }
  }
  window.updateMinEndDate = updateMinEndDate;

  function defaultLastUpdateIfEmpty() {
    const lastUpdateField = document.getElementById('last_update');
    if (!lastUpdateField.value) {
      lastUpdateField.value = new Date().toISOString().split('T')[0];
    }
  }

  document.getElementById("physician_phone").addEventListener("input", function () {
    this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11);
  });

  // Optional popups (unchanged)
  document.addEventListener('DOMContentLoaded', function () {
    const openPopupButton = document.querySelector('#openPopup');
    const popup = document.getElementById('popup1');
    const closePopupButton = document.querySelector('.popup .close');

    if (openPopupButton && popup) {
      openPopupButton.addEventListener('click', function () {
        popup.style.display = 'block';
      });
    }
    if (closePopupButton && popup) {
      closePopupButton.addEventListener('click', function () {
        popup.style.display = 'none';
      });
    }
    window.addEventListener('click', function (event) {
      if (event.target === popup) popup.style.display = 'none';
    });
  });

  // Date limits (unchanged)
  const dobInput = document.getElementById("dob");
  (function setMaxDob(){
    if (!dobInput) return;
    const today = new Date();
    const yyyy = today.getFullYear();
    const mm = String(today.getMonth() + 1).padStart(2, '0');
    const dd = String(today.getDate()).padStart(2, '0');
    dobInput.setAttribute("max", `${yyyy}-${mm}-${dd}`);
  })();

  /* ===========================================================
   * CREATE ACCOUNT MODAL — FIXED: use patient_uid OR node key
   * ===========================================================
   */
  function openCreateAccountModal(btn){
    const puid  = btn.getAttribute('data-puid') || '';
    const key   = btn.getAttribute('data-key')  || '';
    const fname = btn.getAttribute('data-fname')  || '';
    const lname = btn.getAttribute('data-lname')  || '';
    const name  = (fname + ' ' + lname).trim() || btn.getAttribute('data-name') || 'Unknown';

    // Prefer UID; fallback to node key so PHP can still resolve
    setVal('ca_patientId', puid || key);
    setVal('ca_patientName', name);

    const emailEl = document.getElementById('ca_email');
    if (emailEl && !emailEl.value) emailEl.value = '';

    const pwd = Math.random().toString(36).slice(-8);
    setVal('ca_password', pwd);

    document.getElementById('createAccountModal').style.display = 'block';
  }
  function closeCreateAccountModal(){ document.getElementById('createAccountModal').style.display = 'none'; }
  function togglePassword() { const pwd = document.getElementById("ca_password"); pwd.type = (pwd.type === "password") ? "text" : "password"; }

  function openCreateAccountFromModal() {
    const puid = (CURRENT_PATIENT.puid || '').trim();
    const key  = (CURRENT_PATIENT.key  || '').trim();
    const fname = CURRENT_PATIENT.fname || '';
    const lname = CURRENT_PATIENT.lname || '';
    const name  = CURRENT_PATIENT.name || (fname + ' ' + lname).trim();

    // Prefer UID; fallback to node key (so PHP can find/update)
    const idForPost = puid || key;

    setVal('ca_patientId', idForPost);
    setVal('ca_patientName', name);

    const emailInput = document.getElementById('ca_email');
    if (emailInput) emailInput.value = '';

    const pwd = Math.random().toString(36).slice(-8);
    setVal('ca_password', pwd);

    if (!idForPost) {
      console.warn('Create Account: no UID or node key available; open a patient first.');
      alert('Please open a patient first.');
      return;
    }

    document.getElementById('createAccountModal').style.display = 'block';
  }
  window.openCreateAccountFromModal = openCreateAccountFromModal;

  function applyMobileLabels(tbodyId, labels) {
  const tbody = document.getElementById(tbodyId);
  if (!tbody) return;

  const rows = tbody.querySelectorAll("tr");
  rows.forEach(tr => {
    const tds = Array.from(tr.querySelectorAll("td"));

    // visible TDs only (skip hidden ID td like display:none)
    const visibleTds = tds.filter(td => {
      const style = window.getComputedStyle(td);
      return style.display !== "none";
    });

    visibleTds.forEach((td, i) => {
      td.setAttribute("data-label", labels[i] || "");
    });
  });
}


function populateMedTable(rows) {
  const tbody = document.getElementById('medication-rows');
  tbody.innerHTML = '';
  if (Array.isArray(rows) && rows.length) rows.forEach(r => tbody.insertAdjacentHTML('beforeend', medRowHtml(r)));
  else tbody.insertAdjacentHTML('beforeend', medRowHtml({}));
  refreshPlusVisibility('medication-rows');

  applyMobileLabels("medication-rows", [
    "Medication Name","Dosage","Frequency","Physician","Start Date","End Date","Actions"
  ]);
}
function populateSurgTable(rows) {
  const tbody = document.getElementById('surgical-rows');
  tbody.innerHTML = '';
  if (Array.isArray(rows) && rows.length) rows.forEach(r => tbody.insertAdjacentHTML('beforeend', surgRowHtml(r)));
  else tbody.insertAdjacentHTML('beforeend', surgRowHtml({}));
  refreshPlusVisibility('surgical-rows');

  applyMobileLabels("surgical-rows", [
    "Procedure","Physician","Hospital","Date","Notes","Actions"
  ]);
}

function populateIllTable(rows) {
  const tbody = document.getElementById('illness-rows');
  tbody.innerHTML = '';
  if (Array.isArray(rows) && rows.length) rows.forEach(r => tbody.insertAdjacentHTML('beforeend', illRowHtml(r)));
  else tbody.insertAdjacentHTML('beforeend', illRowHtml({}));
  refreshPlusVisibility('illness-rows');

  applyMobileLabels("illness-rows", [
    "Illness","Start Date","End Date","Physician","Treatment Notes","Actions"
  ]);
}

function populateVaxTable(rows) {
  const tbody = document.getElementById('vaccine-rows');
  tbody.innerHTML = '';
  if (Array.isArray(rows) && rows.length) rows.forEach(r => tbody.insertAdjacentHTML('beforeend', vaxRowHtml(r)));
  else tbody.insertAdjacentHTML('beforeend', vaxRowHtml({}));
  refreshPlusVisibility('vaccine-rows');

  applyMobileLabels("vaccine-rows", [
    "Vaccine Name","Date","Actions"
  ]);
}

function populateLabTable(rows) {
  const tbody = document.getElementById('lab-rows');
  if (!tbody) return;
  tbody.innerHTML = '';

  if (!Array.isArray(rows) || rows.length === 0) {
    const tr = document.createElement('tr');
    const td = document.createElement('td');
    td.colSpan = 4;
    td.style.textAlign = 'center';
    td.textContent = 'No laboratory results found.';
    tr.appendChild(td);
    tbody.appendChild(tr);
    return;
  }

  rows.forEach(lab => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${lab.test_type || 'Laboratory Test'}</td>
      <td>${lab.status || 'pending'}</td>
      <td>${(lab.expected_ready_date || lab.ready_date || '') + (lab.ready_time ? (' ' + lab.ready_time) : '')}</td>
      <td>${lab.requesting_doctor || 'Laboratory'}</td>
    `;
    tbody.appendChild(tr);
  });

  applyMobileLabels("lab-rows", [
    "Test","Status","Ready / Expected","Requested By"
  ]);
}
function relabelAllSections() {
  applyMobileLabels("medication-rows", [
    "Medication Name","Dosage","Frequency","Physician","Start Date","End Date","Actions"
  ]);

  applyMobileLabels("surgical-rows", [
    "Procedure","Physician","Hospital","Date","Notes","Actions"
  ]);

  applyMobileLabels("illness-rows", [
    "Illness","Start Date","End Date","Physician","Treatment Notes","Actions"
  ]);

  applyMobileLabels("vaccine-rows", [
    "Vaccine Name","Date","Actions"
  ]);

  applyMobileLabels("lab-rows", [
    "Test","Status","Ready / Expected","Requested By"
  ]);

  relabelAllSections();
}
function addRowAfter(section, btn) {
  if (!CAN_EDIT) return;
  const tr = btn.closest('tr');
  let html = '';
  if (section === 'med')  html = medRowHtml({});
  if (section === 'surg') html = surgRowHtml({});
  if (section === 'ill')  html = illRowHtml({});
  if (section === 'vax')  html = vaxRowHtml({});

  tr.insertAdjacentHTML('afterend', html);

  const tbodyId = tr.parentElement.id;
  refreshPlusVisibility(tbodyId);

  // ✅ IMPORTANT: apply labels for the new row
  relabelAllSections();
}
window.addRowAfter = addRowAfter;

</script>



</body>
</html>
