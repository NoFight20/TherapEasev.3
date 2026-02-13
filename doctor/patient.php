<?php
session_name('sess_doctor'); session_start();

if(isset($_SESSION["user"])){
    if(($_SESSION["user"]) == "" || $_SESSION['usertype'] != 'd'){
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

$doctorKey = null;
try {
    $reference = $database
        ->getReference('doctor')
        ->orderByChild('email')
        ->equalTo($useremail)
        ->getSnapshot();

    $userfetch = $reference->getValue();

    if ($userfetch) {
        foreach ($userfetch as $key => $value) {
            $doctorKey = $key;
            $username = $value['name'] ?? '';
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

$role = $_SESSION['usertype'] ?? '';
$canEdit = ($role === 'd'); // any doctor can edit

$_SESSION['canEditMedicalForm'] = $canEdit;
$attrReadonly = $canEdit ? '' : 'readonly';
$attrDisabled = $canEdit ? '' : 'disabled';
$submitBlocked = $canEdit ? '' : 'onsubmit="return false"';


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


// Fetch the doctor's photo from Firebase (default image if none exists)
$doctorRef = $database->getReference('doctor')->orderByChild('email')->equalTo($useremail);
$doctorData = $doctorRef->getValue();

$photo = '../img/user.png';
if ($doctorData) {
    $doctor = reset($doctorData);
    if (!empty($doctor['photo'])) {
        $photo = $doctor['photo'];
    }
}

$currentDoctorName  = $doctor['name'] ?? ($username ?? '');
$currentDoctorPhone = $doctor['tele'] ?? '';

/* ---------- BUILD RESULT SET: $rows (ONLY PATIENTS WITH APPOINTMENTS TO THIS DOCTOR) ---------- */

// 1) Get all patients (for demographics)
$patientsRef = $database->getReference('patients');
$allPatients = $patientsRef->getValue() ?: [];

// 2) Get all appointments
$appointmentsRef = $database->getReference('appointments');
$allAppointments = $appointmentsRef->getValue() ?: [];

// 3) Collect patient emails that have an appointment with this doctor
$doctorPatientEmails = [];
$loggedDoctorEmailLc = mb_strtolower(trim($useremail));

if (!empty($allAppointments) && is_array($allAppointments)) {
    foreach ($allAppointments as $apptId => $appt) {
        // --- Match appointment to this doctor ---
        $docId  = $appt['doctorId'] ?? ($appt['docid'] ?? null);
        $docEmailInAppt = isset($appt['doctorEmail'])
            ? mb_strtolower(trim((string)$appt['doctorEmail']))
            : '';

        $matchDoctor = false;

        if ($docId && $doctorKey && $docId === $doctorKey) {
            $matchDoctor = true;
        } elseif ($docEmailInAppt !== '' && $docEmailInAppt === $loggedDoctorEmailLc) {
            $matchDoctor = true;
        }

        if (!$matchDoctor) {
            continue;
        }

        // optionally skip cancelled appointments
        $status = isset($appt['status']) ? mb_strtolower(trim((string)$appt['status'])) : '';
        if ($status === 'cancelled') {
            continue;
        }

        // --- Collect patient email from appointment ---
        $pEmail = $appt['patientEmail'] ?? ($appt['email'] ?? '');
        $pEmail = mb_strtolower(trim((string)$pEmail));
        if ($pEmail !== '') {
            $doctorPatientEmails[$pEmail] = true; // de-duplicate
        }
    }
}

// 4) Build base rows: only those patients whose EMAIL is in $doctorPatientEmails
$rowsBase = [];
if (!empty($doctorPatientEmails) && is_array($allPatients)) {
    foreach ($allPatients as $pid => $patient) {
        $pEmail = isset($patient['email']) ? mb_strtolower(trim((string)$patient['email'])) : '';
        if ($pEmail !== '' && isset($doctorPatientEmails[$pEmail])) {
            $rowsBase[$pid] = $patient;
        }
    }
}

// 5) Apply search on top of that subset
$rows = $rowsBase;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search'])) {
    $keyword = trim((string)$_POST['search']);
    if ($keyword !== '') {
        $kw   = mb_strtolower($keyword);
        $rows = []; // filtered result

        foreach ($rowsBase as $pid => $patient) {
            $fname    = (string)($patient['fname'] ?? '');
            $lname    = (string)($patient['lname'] ?? '');
            $name     = (string)($patient['name'] ?? trim($fname . ' ' . $lname));
            $email    = (string)($patient['email'] ?? '');
            $tele     = (string)($patient['tele'] ?? '');
            $dob      = (string)($patient['dob'] ?? '');
            $gender   = (string)($patient['gender'] ?? '');
            $civil    = (string)($patient['civil_status'] ?? '');
            $barangay = (string)($patient['barangay'] ?? '');
            $city     = (string)($patient['city'] ?? '');
            $province = (string)($patient['province'] ?? '');
            $addr     = trim("$barangay $city $province");

            foreach ([$name, $fname, $lname, $email, $tele, $dob, $gender, $civil, $addr] as $field) {
                if ($field !== '' && mb_stripos($field, $kw) !== false) {
                    $rows[$pid] = $patient;
                    break;
                }
            }
        }
    }
}

// Count only unique patients with appointments to this doctor
$patientCount = count($rowsBase);
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
    <link rel="stylesheet" href="patient.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <title>Patients</title>
<style>
    .hidden {
        display: none !important;
    }
    .overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.7);
        z-index: 1000;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .overlay-content {
        background: #fff;
        padding: 20px;
        border-radius: 5px;
        width: 50%;
        text-align: center;
    }

    .menu-btn a {
        display: block;
        text-decoration: none;
        padding: 1px;
        width: 100%;
    }

    .menu-btn:hover {
        background-color: #E8F8E8;
    }

    .icon-btn {
        background: #f1f1f1;
        border: none;
        padding: 8px 12px;
        margin: 5px;
        border-radius: 50%;
        cursor: pointer;
        font-size: 14px;
        transition: 0.3s;
    }
    .icon-btn:hover {
        background: #45a049;
        color: white;
    }

    .icon-btn:disabled,
    .login-btn:disabled,
    .floating-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    /* put this after the existing .overlay/.overlay-content styles */
    #confirmation-overlay .overlay-content{
        width: 20vw;           /* half the viewport */
        max-width: 700px;      /* optional cap */
    }

    @media (max-width: 640px){
        #confirmation-overlay .overlay-content{ width: 90vw; }
    }

    @keyframes scaleIn {
        from { transform: scale(0.95); opacity: 0; }
        to   { transform: scale(1);    opacity: 1; }
    }

    /* ---------- RESPONSIVE LAYOUT ---------- */
    @media (max-width: 992px) {
        .container {
            flex-direction: column;
        }

        .menu {
            width: 250px;
            position: fixed;
            top: 60px;
            left: -260px;
            height: calc(100% - 60px);
            z-index: 999;
            transition: left 0.3s ease;
        }

        .menu.active {
            left: 0;
        }

        .dash-body {
            margin-left: 0;
            padding: 15px;
        }

        .header-search {
            flex-direction: column;
            align-items: stretch;
            gap: 10px;
        }

        .header-searchbar {
            width: 100%;
        }

        #searchBtn {
            width: 100% !important;
        }

        table.sub-table {
            font-size: 12px;
        }

        .sub-table thead th,
        .sub-table tbody td {
            padding: 4px;
        }

        .abc.scroll {
            max-width: 100%;
            overflow-x: auto;
        }
    }

    @media (max-width: 600px) {
        .profile-container td {
            display: block;
            width: 100%;
            text-align: center;
        }

        .profile-container img {
            max-width: 100px;
            margin-bottom: 10px;
        }

        .heading-main12 {
            margin-left: 10px !important;
            text-align: left;
        }

        .dash-body table {
            margin-top: 15px !important;
        }

        /* Modal width on small screens */
        #medicalHistoryModal .modal-content {
            width: 95% !important;
            margin: 30px auto !important;
        }
    }

    /* =========================
   MOBILE HEADER + DRAWER (Doctor Patients)
   ========================= */
.mobile-header{ display:none; }
.menu-overlay{ display:none; }

@media (max-width: 768px){

  /* fixed header */
  .mobile-header{
    display:flex !important;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    padding:10px 12px !important;
    height:56px;
    box-sizing:border-box;
    position:fixed;
    top:0; left:0; right:0;
    z-index:2000;
    background:lightgreen;
    color:#000;
  }

  .mh-left{ width:44px; display:flex; align-items:center; }
  .mh-title{
    flex:1;
    text-align:center;
    font-weight:800;
    font-size:16px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
  }
  .mh-right{ display:flex; align-items:center; gap:10px; }
  .mh-date{ text-align:right; line-height:1.1; }
  .mh-date-label{ font-size:12px; opacity:.85; }
  .mh-date-value{ font-size:13px; font-weight:800; white-space:nowrap; }

  .mh-cal{
    width:40px !important;
    height:40px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    padding:0 !important;
    border-radius:10px;
  }
  .mh-cal img{ width:22px !important; height:22px !important; }
  /* Hamburger */
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
  #hamburger-menu.active .bar:nth-child(1){ transform:rotate(-45deg) translate(-5px,5px); }
  #hamburger-menu.active .bar:nth-child(2){ opacity:0; }
  #hamburger-menu.active .bar:nth-child(3){ transform:rotate(45deg) translate(-5px,-5px); }

  /* sidebar becomes overlay drawer */
  .menu{
    display:none;
    position:fixed;
    left:-250px;
    top:56px;
    width:250px;
    height:calc(100% - 56px);
    background:lightgreen;
    transition:left .3s ease;
    z-index:1900;
    overflow:auto;
  }
  .menu.active{ display:block; left:0; }

  /* ✅ DO NOT PUSH CONTENT */
  .menu.active + .dash-body{
    margin-left:0 !important;
  }

  /* dim overlay */
  .menu-overlay{
    display:none;
    position:fixed;
    inset:56px 0 0 0;
    background:rgba(0,0,0,.25);
    z-index:1850;
  }
  .menu-overlay.active{ display:block; }

  /* maximize space */
  .container{
    width:100% !important;
    max-width:100% !important;
    margin:0 !important;
    padding:0 !important;
  }

  .dash-body{
    width:100% !important;
    max-width:100% !important;
    margin-top:70px !important; /* below header */
    margin-left:0 !important;
    padding:12px !important;
    box-sizing:border-box !important;
  }

  /* hide desktop header row (search/date/calendar) */
  .dash-body > table > tbody > tr:first-child{
    display:none !important;
  }

  /* allow table to scroll horizontally (so whole table can be seen) */
  .abc.scroll{
    max-width:100% !important;
    overflow-x:auto !important;
    -webkit-overflow-scrolling:touch;
  }
  .sub-table{
    min-width:980px; /* keeps columns readable; adjust if needed */
  }

  /* modal on mobile */
  #medicalHistoryModal .modal-content{
    width:95% !important;
    margin:80px auto 20px auto !important;
    left:auto !important;
  }
}

/* ✅ MOBILE: make the Medical History top-to-bottom */
@media (max-width: 768px){

  /* modal should scroll vertically */
  #medicalHistoryModal .modal-content{
    width: 95% !important;
    margin: 70px auto 20px auto !important;
    left: auto !important;
    max-height: 85vh;
    overflow-y: auto;
    box-sizing: border-box;
  }

  /* ✅ STACK the "Medical History Form" main table */
  #medicalHistoryModal .medical-history-table,
  #medicalHistoryModal .medical-history-table tbody,
  #medicalHistoryModal .medical-history-table tr,
  #medicalHistoryModal .medical-history-table th,
  #medicalHistoryModal .medical-history-table td{
    display: block !important;
    width: 100% !important;
  }

  /* each row becomes a card/section */
  #medicalHistoryModal .medical-history-table tr{
    border: 1px solid #e5e5e5;
    border-radius: 8px;
    padding: 10px;
    margin-bottom: 12px;
    background: #fff;
  }

  /* labels */
  #medicalHistoryModal .medical-history-table th{
    margin: 0 0 6px 0;
    padding: 0;
    font-weight: 700;
  }

  /* fields */
  #medicalHistoryModal .medical-history-table td{
    padding: 0;
    margin: 0 0 8px 0;
  }

  #medicalHistoryModal .medical-history-table input,
  #medicalHistoryModal .medical-history-table select{
    width: 100% !important;
    box-sizing: border-box;
  }

  /* ✅ tables under it (meds/surgery/etc) should scroll horizontally if too wide */
  #medicalHistoryModal table[border="1"]{
    display: block;
    width: 100%;

    -webkit-overflow-scrolling: touch;
  }

  #medicalHistoryModal table[border="1"] thead,
  #medicalHistoryModal table[border="1"] tbody{
    display: table;
    width: max-content;
    min-width: 900px; /* adjust if needed */
  }
  /* ✅ inside the modal: allow horizontal scroll for the meds/surgery/etc tables */
#medicalHistoryModal table[border="1"]{
  display: block;
  width: 100%;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}

#medicalHistoryModal table[border="1"] thead,
#medicalHistoryModal table[border="1"] tbody{
  display: table;
  width: max-content;
  min-width: 900px;                     /* adjust */
}

}

</style>

</head>

<body>
    <?php
date_default_timezone_set('Asia/Manila');
$today = date('Y-m-d');
?>
<!-- ✅ MOBILE HEADER -->
<div class="mobile-header">
  <div class="mh-left">
    <div id="hamburger-menu" aria-label="Menu">
      <div class="bar"></div>
      <div class="bar"></div>
      <div class="bar"></div>
    </div>
  </div>

  <div class="mh-title">Patient Health Records</div>

  <div class="mh-right">
    <div class="mh-date">
      <div class="mh-date-label">Date</div>
      <div class="mh-date-value"><?php echo $today; ?></div>
    </div>
    <button class="mh-cal btn-label" type="button" aria-label="Calendar">
      <img src="../img/calendar.svg" alt="">
    </button>
  </div>
</div>

<!-- ✅ overlay (menu won't push content) -->
<div class="menu-overlay" id="menuOverlay"></div>


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
                        <a href="index.php" class="non-style-link-menu">
                            <p class="menu-text">Dashboard</p>
                        </a>
                    </td>
                </tr>
                <tr class="menu-row">
                    <td class="menu-btn">
                        <a href="schedule.php" class="non-style-link-menu">
                            <p class="menu-text">My Sessions</p>
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
                        <a href="patient.php" class="non-style-link-menu  non-style-link-menu-active">
                            <p class="menu-text">Patient Health Records</p>
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
                    <td class="menu-btn">
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
                            <?php echo $date; ?>
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
                                            $dob       = (string)($patient['dob'] ?? '');
                                            $tele      = (string)($patient['tele'] ?? '');
                                            $barangay  = (string)($patient['barangay'] ?? '');
                                            $barangay  = ($barangay === '0') ? '' : $barangay;
                                            $city      = (string)($patient['city'] ?? '');
                                            $province  = (string)($patient['province'] ?? '');
                                            $type      = (string)($patient['type'] ??'');

                                            // NEW: fetch patient_uid (primary identifier for UI/actions)
                                            $patientUid = (string)($patient['patient_uid'] ?? '');

                                            $eventsTd = '
                                              <button 
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
    </div>


<!-- Medical History Modal  -->
<div id="medicalHistoryModal" style="display: none;">
  <div class="modal-content" style="margin-top: 50px; margin-left: 350px">
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
                  $doctorsRef = $database->getReference('doctor')->getSnapshot();
                  if ($doctorsRef->exists()) {
                      foreach ($doctorsRef->getValue() as $key => $doc) {
                          $doctorName  = $doc['name'] ?? 'Unknown Doctor';
                          $doctorPhone = $doc['tele'] ?? 'N/A';
                          echo "<option value='" . htmlspecialchars($doctorName) . "' data-phone='" . htmlspecialchars($doctorPhone) . "'"
                              . (($doctorName === $currentDoctorName) ? " selected" : "")
                              . ">" . htmlspecialchars($doctorName) . "</option>";
                      }
                  } else {
                      echo "<option value=''>No doctors available</option>";
                  }
              } catch (\Kreait\Firebase\Exception\DatabaseException $e) {
                  echo "<option value=''>Error fetching doctors: " . htmlspecialchars($e->getMessage()) . "</option>";
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

      <br><br>
      <div style="display:flex; gap:20px; margin:10px 0 0 100px;">
        <?php if ($canEdit): ?>
          <button type="submit" class="login-btn btn-primary-soft small-btn">Save</button>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<div id="confirmation-overlay" class="overlay hidden">
  <div class="overlay-content">
    <p id="confirmation-message"></p>
    <div style="display: flex; gap: 20px; margin-left: -308px;">
        <button  class="login-btn btn-primary-soft small-btn"  type="button" onclick="executePendingAction()">Confirm</button>
        <button class="login-btn btn-primary-soft small-btn" style="background-color:#ff0000;" type="button" onclick="closeOverlay()">Cancel</button>
    </div>
  </div>
</div>


<script>
  // ===== Server-provided flags & current doctor =====
  const CAN_EDIT = <?= $canEdit ? 'true' : 'false' ?>;
  const CURRENT_DOCTOR_NAME  = <?= json_encode($currentDoctorName ?? ($username ?? '')) ?>;
  const CURRENT_DOCTOR_PHONE = <?= json_encode($currentDoctorPhone ?? '') ?>;

  let pendingAction = null;

  // ===== Utilities =====
  function setVal(id, value) {
    const el = document.getElementById(id);
    if (el) el.value = value ?? '';
  }

  function defaultLastUpdateIfEmpty() {
    const lastUpdateField = document.getElementById('last_update');
    if (lastUpdateField && !lastUpdateField.value) {
      lastUpdateField.value = new Date().toISOString().split('T')[0];
    }
  }

  // Keep phone numeric + max 11
  function validatePhoneNumber(input) {
    input.value = input.value.replace(/[^0-9]/g, '');
    if (input.value.length > 11) input.value = input.value.slice(0, 11);
  }

  // ===== Modal open/close =====
  document.addEventListener('DOMContentLoaded', () => {
    const closeModalBtn = document.getElementById('closeModal');
    const modal = document.getElementById('medicalHistoryModal');

    // Bind "View" buttons (using event delegation for robustness)
    document.body.addEventListener('click', (e) => {
      const btn = e.target.closest('.viewButton');
      if (btn) {
        e.preventDefault();
        openModal(btn);
      }
    });

    if (closeModalBtn) {
      closeModalBtn.addEventListener('click', () => {
        if (modal) modal.style.display = 'none';
      });
    }

    window.addEventListener('click', (event) => {
      if (event.target === modal) {
        modal.style.display = 'none';
      }
    });
  });

  // ===== Archive confirmation overlay =====
  function showConfirmationOverlay(message) {
    const overlay = document.getElementById('confirmation-overlay');
    document.getElementById('confirmation-message').textContent = message || 'Are you sure?';
    overlay.classList.remove('hidden');
  }

  function closeOverlay() {
    document.getElementById('confirmation-overlay').classList.add('hidden');
  }

  function executePendingAction() {
    if (pendingAction) {
      pendingAction();
      pendingAction = null;
      closeOverlay();
    }
  }
  window.executePendingAction = executePendingAction;


  // ===== Printing =====
  function printMedicalHistory() {
    const form = document.getElementById('save-history-form');

    // Persist values in attributes so they appear in the print clone
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
    // Remove buttons from print
    formClone.querySelectorAll('button, input[type="button"], input[type="submit"]').forEach(btn => btn.remove());

    // Ensure empty tables still render a blank row
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
        <head><title>Medical History</title>${style}</head>
        <body>
          <h3>Medical History Form</h3>
          ${formClone.outerHTML}
          <script>
            window.onload = function(){ window.print(); window.onafterprint = function(){ window.close(); }; }
          <\/script>
        </body>
      </html>
    `);
    printWindow.document.close();
  }
  window.printMedicalHistory = printMedicalHistory;

  // ===== Row action buttons: + / - =====
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

  // ===== Row templates =====
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
        <td class="row-actions" style="white-space:nowrap;">${actionButtons('med')}</td>
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

  // Show + only on the last row of each section
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

  // Populate helpers
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

  // Add / Remove rows
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
      case 'medication-rows': return { section: 'medications',          blankHtml: medRowHtml({}), idInputName: 'med_id[]'  };
      case 'surgical-rows':   return { section: 'surgical_procedures',   blankHtml: surgRowHtml({}), idInputName: 'surg_id[]' };
      case 'illness-rows':    return { section: 'illnesses',             blankHtml: illRowHtml({}), idInputName: 'ill_id[]'  };
      case 'vaccine-rows':    return { section: 'vaccinations',          blankHtml: vaxRowHtml({}), idInputName: 'vax_id[]'  };
      default: return null;
    }
  }

  async function archiveExistingRow(patientUid, section, rowId, patientName) {
    const res = await fetch('archive_medical_row.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        patient_uid: patientUid,
        section,
        row_id: rowId,
        patient_name: patientName || ''
      })
    });
    return res.json();
  }

  function removeRow(btn) {
    if (!CAN_EDIT) return;

    const tr = btn.closest('tr');
    const tbody = tr.parentElement;
    const meta = sectionMetaFromTbody(tbody);
    const idInput = tr.querySelector('input[type="hidden"][name$="_id[]"]');
    const rowId = idInput ? (idInput.value || '').trim() : '';
    const isExisting = !!rowId;

    const msg = isExisting
      ? 'This looks like a saved record. Do you want to remove it? It will be archived.'
      : 'Remove this (unsaved) row?';

    if (!confirm(msg)) return;

    const patientUid  = (document.getElementById('patient_uid')?.value || '').trim();
    const patientName = (document.getElementById('patient_name')?.value || '').trim();

    if (isExisting && meta && patientUid) {
      tr.querySelectorAll('button').forEach(b => b.disabled = true);

      archiveExistingRow(patientUid, meta.section, rowId, patientName)
        .then(data => {
          if (!data || !data.success) {
            alert(data?.error || 'Failed to archive row.');
            tr.querySelectorAll('button').forEach(b => b.disabled = false);
            return;
          }
          tr.remove();
          if (!tbody.querySelector('tr')) tbody.insertAdjacentHTML('beforeend', meta.blankHtml);
          refreshPlusVisibility(tbody.id);
        })
        .catch(() => {
          alert('Network error while archiving row.');
          tr.querySelectorAll('button').forEach(b => b.disabled = false);
        });

    } else {
      tr.remove();
      if (meta && !tbody.querySelector('tr')) tbody.insertAdjacentHTML('beforeend', meta.blankHtml);
      refreshPlusVisibility(tbody.id);
    }
  }

  // Convenience wrappers (kept if you call them elsewhere)
  function addMedicationRow() { if (CAN_EDIT) document.getElementById('medication-rows').insertAdjacentHTML('beforeend', medRowHtml({})); }
  function addSurgicalRow()   { if (CAN_EDIT) document.getElementById('surgical-rows').insertAdjacentHTML('beforeend',   surgRowHtml({})); }
  function addIllnessRow()    { if (CAN_EDIT) document.getElementById('illness-rows').insertAdjacentHTML('beforeend',    illRowHtml({})); }
  function addVaccineRow()    { if (CAN_EDIT) document.getElementById('vaccine-rows').insertAdjacentHTML('beforeend',    vaxRowHtml({})); }
  window.addMedicationRow = addMedicationRow;
  window.addSurgicalRow   = addSurgicalRow;
  window.addIllnessRow    = addIllnessRow;
  window.addVaccineRow    = addVaccineRow;

  function deleteLastRow(tbodyId) {
    if (!CAN_EDIT) return;
    const tbody = document.getElementById(tbodyId);
    if (!tbody) return;
    const trs = tbody.querySelectorAll('tr');
    if (!trs.length) return;
    trs[trs.length - 1].remove();

    if (!tbody.querySelector('tr')) {
      if (tbody.id === 'medication-rows') tbody.insertAdjacentHTML('beforeend', medRowHtml({}));
      if (tbody.id === 'surgical-rows')   tbody.insertAdjacentHTML('beforeend', surgRowHtml({}));
      if (tbody.id === 'illness-rows')    tbody.insertAdjacentHTML('beforeend', illRowHtml({}));
      if (tbody.id === 'vaccine-rows')    tbody.insertAdjacentHTML('beforeend', vaxRowHtml({}));
    }
    refreshPlusVisibility(tbody.id);
  }
  window.deleteLastRow = deleteLastRow;

  // ===== Physician & dates =====
  function updatePhysicianPhone() {
    const physicianSelect = document.getElementById('physician_name');
    const selectedOption = physicianSelect?.options[physicianSelect.selectedIndex];
    const physicianPhone = selectedOption ? (selectedOption.getAttribute('data-phone') || '') : '';
    const phoneEl = document.getElementById('physician_phone');
    if (phoneEl) phoneEl.value = physicianPhone;
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

  // Keep physician phone numeric
  document.addEventListener('DOMContentLoaded', () => {
    const phoneEl = document.getElementById('physician_phone');
    if (phoneEl) {
      phoneEl.addEventListener('input', function () {
        validatePhoneNumber(this);
      });
    }
  });

  function openModal(button) {
    const puid       = button.getAttribute('data-puid') || '';
    const nodeKey    = button.getAttribute('data-key')  || '';
    const name       = button.getAttribute('data-name') || '';
    const fname      = button.getAttribute('data-fname') || '';
    const lname      = button.getAttribute('data-lname') || '';
    const gender     = button.getAttribute('data-gender') || '';
    const tel        = button.getAttribute('data-tele') || '';
    const email      = button.getAttribute('data-email') || '';
    const dob        = button.getAttribute('data-dob') || '';
    const age        = button.getAttribute('data-age') || '';
    const barangay   = button.getAttribute('data-barangay') || '';
    const city       = button.getAttribute('data-city') || '';
    const province   = button.getAttribute('data-province') || '';
    const civilStatus= button.getAttribute('data-civil-status') || '';

    // Use whichever identifier we have
    const idForFetch = puid || nodeKey;
    const qs = puid
      ? `puid=${encodeURIComponent(puid)}`
      : `key=${encodeURIComponent(nodeKey)}`;

    // Hidden + header fields (store something non-empty so Save works)
    setVal('patient_uid',   puid || nodeKey);
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

    const physSelect = document.getElementById('physician_name');
    if (physSelect) {
      physSelect.value = CURRENT_DOCTOR_NAME || '';
      updatePhysicianPhone();
      const phoneEl = document.getElementById('physician_phone');
      if (phoneEl && !phoneEl.value && CURRENT_DOCTOR_PHONE) {
        phoneEl.value = CURRENT_DOCTOR_PHONE;
      }
    }

    // Load medical record by UID or node key
    fetch(`get_medical_record.php?${qs}`)
      .then(r => r.json())
      .then(data => {
        if (data && !data.error) {
          setVal('last_update', data.last_update || '');

          const fromName  = data.physician && data.physician.name ? String(data.physician.name).trim() : '';
          const fromPhone = data.physician && data.physician.contact ? String(data.physician.contact).trim() : '';

          if (fromName) {
            const sel = document.getElementById('physician_name');
            if (sel) {
              // If the fetched doctor isn’t in the list, append it so it can be selected
              const exists = Array.from(sel.options).some(o => o.value === fromName);
              if (!exists) {
                const opt = document.createElement('option');
                opt.value = fromName;
                opt.textContent = fromName;
                if (fromPhone) opt.setAttribute('data-phone', fromPhone);
                sel.appendChild(opt);
              }
              sel.value = fromName;
              // sync phone
              const chosenPhone = sel.selectedOptions[0]?.getAttribute('data-phone') || '';
              setVal('physician_phone', fromPhone || chosenPhone || '');
            }
          } else {
            // keep default to logged-in doctor
            setVal('physician_name', CURRENT_DOCTOR_NAME || '');
            updatePhysicianPhone();
            const phoneEl = document.getElementById('physician_phone');
            if (phoneEl && !phoneEl.value && CURRENT_DOCTOR_PHONE) {
              phoneEl.value = CURRENT_DOCTOR_PHONE;
            }
          }

          // Populate tables
          populateMedTable(data.medications || []);
          populateSurgTable(data.surgical_procedures || []);
          populateIllTable(data.illnesses || []);
          populateVaxTable(data.vaccinations || []);
          defaultLastUpdateIfEmpty();
        } else {
          // no record → clear tables but still show modal with defaults
          setVal('last_update', '');
          setVal('physician_name', CURRENT_DOCTOR_NAME || '');
          updatePhysicianPhone();
          const phoneEl = document.getElementById('physician_phone');
          if (phoneEl && !phoneEl.value && CURRENT_DOCTOR_PHONE) {
            phoneEl.value = CURRENT_DOCTOR_PHONE;
          }
          populateMedTable([]); populateSurgTable([]); populateIllTable([]); populateVaxTable([]);
          defaultLastUpdateIfEmpty();
        }
      })
      .catch(err => {
        console.error('Error fetching medical record:', err);
        setVal('last_update', '');
        setVal('physician_name', CURRENT_DOCTOR_NAME || '');
        updatePhysicianPhone();
        const phoneEl = document.getElementById('physician_phone');
        if (phoneEl && !phoneEl.value && CURRENT_DOCTOR_PHONE) {
          phoneEl.value = CURRENT_DOCTOR_PHONE;
        }
        populateMedTable([]); populateSurgTable([]); populateIllTable([]); populateVaxTable([]);
        defaultLastUpdateIfEmpty();
      });

    // Show modal
    const modal = document.getElementById('medicalHistoryModal');
    if (modal) modal.style.display = 'block';
  }
  window.openModal = openModal;

  // ===== Date constraints =====
  document.addEventListener('DOMContentLoaded', () => {
    // limit med_end_date, ill_end_date, surg_date, vaccine_date to +3 years
    const maxDate = new Date();
    maxDate.setFullYear(new Date().getFullYear() + 3);
    const maxDateFormatted = maxDate.toISOString().split('T')[0];

    function setMaxDates(scope=document) {
      scope.querySelectorAll('input[name="med_end_date[]"], input[name="ill_end_date[]"], input[name="surg_date[]"], input[name="vaccine_date[]"]').forEach(input => {
        input.setAttribute('max', maxDateFormatted);
      });
    }
    setMaxDates(document);

    // Observe dynamic row insertions with MutationObserver
    ['medication-rows','surgical-rows','illness-rows','vaccine-rows'].forEach(id => {
      const tbody = document.getElementById(id);
      if (!tbody) return;
      const observer = new MutationObserver(muts => {
        muts.forEach(m => {
          m.addedNodes.forEach(node => {
            if (node.nodeType === 1) setMaxDates(node);
          });
        });
      });
      observer.observe(tbody, { childList: true, subtree: true });
    });

    // DOB max = today
    const dobInput = document.getElementById('dob');
    if (dobInput) {
      const today = new Date();
      const yyyy = today.getFullYear();
      const mm = String(today.getMonth() + 1).padStart(2, '0');
      const dd = String(today.getDate()).padStart(2, '0');
      dobInput.setAttribute('max', `${yyyy}-${mm}-${dd}`);
    }
  });

  document.addEventListener('DOMContentLoaded', function () {
    const count = document.querySelectorAll('.viewButton').length;
    const badge = document.getElementById('patientCount');
    if (badge) badge.textContent = String(count);
  });

   const hamburgerMenu = document.getElementById('hamburger-menu');
  const menu = document.querySelector('.menu');
  const menuOverlay = document.getElementById('menuOverlay');

  function toggleMenu(){
    hamburgerMenu.classList.toggle('active');
    menu.classList.toggle('active');
    if (menuOverlay) menuOverlay.classList.toggle('active');
  }

  if (hamburgerMenu) hamburgerMenu.addEventListener('click', toggleMenu);

  if (menuOverlay){
    menuOverlay.addEventListener('click', () => {
      hamburgerMenu.classList.remove('active');
      menu.classList.remove('active');
      menuOverlay.classList.remove('active');
    });
  }
</script>

</body>
</html>
