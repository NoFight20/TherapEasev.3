<?php
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
session_name('sess_doctor'); session_start();

    if(isset($_SESSION["user"])){
        if(($_SESSION["user"])=="" or $_SESSION['usertype']!='d'){
            header("location: ../login.php");
        }else{
            $useremail=$_SESSION["user"];
        }
    }else{
        header("location: ../login.php");
    }

    // Import database
    include("../connection.php");


$bannerData = $database->getReference('site/banner')->getValue();
$imageUrl = $database->getReference('doctor_page/doctor_backgroundImage')->getValue();

if ($bannerData && isset($bannerData['url'])) {
    $timestamp = isset($bannerData['uploaded_at']) ? strtotime($bannerData['uploaded_at']) : time();
    $bannerUrl = $bannerData['url'] . '?v=' . $timestamp;
} else {
    $bannerUrl = $imageUrl;
}

// Announcements (supports both old/new schemas)
$annNode = $database->getReference('site/announcements')->getValue();
$announcementText = 'No announcements';

if (is_string($annNode)) {
    // when node is just a plain string
    $announcementText = $annNode;
} elseif (is_array($annNode)) {
    // prefer role-specific, fall back to generic "announcement"
    $announcementText = $annNode['doctor']
        ?? $annNode['announcement']
        ?? $annNode['text']
        ?? 'No announcements';
}



    // Fetch the logged-in doctor's data from Firebase
    $doctorRef = $database->getReference('doctor')->orderByChild('email')->equalTo($useremail);
    $doctorRecords = $doctorRef->getValue();

    if ($doctorRecords) {
        // Get the first doctor record's key and data
        $doctorKey = key($doctorRecords);
        $doctorData = current($doctorRecords);
    

        $userid = isset($doctorData['id']) ? $doctorData['id'] : $doctorKey;
        $username = isset($doctorData['name']) ? $doctorData['name'] : null;
        $sessions = $doctorData['sessions'] ?? [];
    } else {
        die("Error: Doctor data not found. Please contact support.");
    }

    // Fetch data from Firebase
    $patientsRef = $database->getReference('patients')->getValue();
    $secretariesRef = $database->getReference('admin')->getValue();
    $doctorsRef = $database->getReference('doctor')->getValue();
    $specialtiesRef = $database->getReference('specialties')->getValue();

    // ==== NEW: build datasets for charts ====
$patientsByCity = [];
if (!empty($patientsRef) && is_array($patientsRef)) {
    foreach ($patientsRef as $p) {
        $city = $p['city'] ?? $p['address_city'] ?? $p['city_of_residence'] ?? 'Unknown';
        $city = trim($city) !== '' ? $city : 'Unknown';
        $patientsByCity[$city] = ($patientsByCity[$city] ?? 0) + 1;
    }
}
$patientsByCityJson = json_encode($patientsByCity, JSON_UNESCAPED_UNICODE);

// Secretaries (admins) by specialty
$secretariesBySpecialty = [];
if (!empty($secretariesRef) && is_array($secretariesRef)) {
    foreach ($secretariesRef as $sec) {
        $secSpec = $sec['specialty'] ?? $sec['sname'] ?? 'Unassigned';
        $secSpec = trim($secSpec) !== '' ? $secSpec : 'Unassigned';
        $secretariesBySpecialty[$secSpec] = ($secretariesBySpecialty[$secSpec] ?? 0) + 1;
    }
}
$secretariesBySpecialtyJson = json_encode($secretariesBySpecialty, JSON_UNESCAPED_UNICODE);


    // Map specialty IDs to names
    $specialties = [];
    if (!empty($specialtiesRef)) {
        foreach ($specialtiesRef as $id => $specialty) {
            $specialties[$id] = $specialty['sname'] ?? 'Unknown Specialty';
        }
    }

    // Process doctors by specialty
    $doctorsBySpecialty = [];
    if (!empty($doctorsRef)) {
        foreach ($doctorsRef as $doctor) {
            $specialtyId = $doctor['specialty'] ?? 'Unknown';
            $specialtyName = $specialties[$specialtyId] ?? 'Unknown Specialty';
            $doctorsBySpecialty[$specialtyName] = ($doctorsBySpecialty[$specialtyName] ?? 0) + 1;
        }
    }
    // Convert processed data to JSON for JavaScript
    $doctorsBySpecialtyJson = json_encode($doctorsBySpecialty);

    // Fetch counts from Firebase
    try {
        $doctorsRef = $database->getReference('doctor');
        $doctorsData = $doctorsRef->getValue();
        $doctorsCount = $doctorsData ? count($doctorsData) : 0;
    } catch (Exception $e) {
        $doctorsCount = 0;
        error_log("Error fetching data from Firebase: " . $e->getMessage());
    }

function patientHasDoctorInRecords(array $patient, string $doctorName): bool {
    // Normalize doctorName defensively in case something weird is passed
    if (is_array($doctorName)) {
        $doctorName = implode(' ', $doctorName);
    }

    $doctorName = trim((string)$doctorName);
    if ($doctorName === '') return false;

    // Normalize doctorName for comparison
    $needle = strtolower($doctorName);

    // 1) Exact schema in your screenshot
    if (isset($patient['medical_record']['physician'])) {
        $phys = $patient['medical_record']['physician'];

        // a) physician is an object with 'name'
        if (is_array($phys) && isset($phys['name'])) {
            $name = strtolower(trim((string)$phys['name']));
            if ($name !== '' && ($name === $needle || strpos($name, $needle) !== false)) return true;
        }

        // b) physician might be a list of objects with 'name'
        if (is_array($phys) && array_is_list($phys)) {
            foreach ($phys as $entry) {
                if (!is_array($entry)) continue;
                $name = strtolower(trim((string)($entry['name'] ?? '')));
                if ($name !== '' && ($name === $needle || strpos($name, $needle) !== false)) return true;
            }
        }
    }

    // 2) Optional fallbacks if other fields exist in some records
    $fallbacks = [
        $patient['medical_record']['updated_by']  ?? null,
        $patient['medical_record']['doctor']      ?? null,
        $patient['medical_record']['doctor_name'] ?? null,
    ];

    foreach ($fallbacks as $v) {
        if (!is_string($v)) continue; // skip arrays/objects safely
        $vNorm = strtolower(trim($v));
        if ($vNorm !== '' && ($vNorm === $needle || strpos($vNorm, $needle) !== false)) return true;
    }

    return false;
}



function mostRecentRecordTs(array $patient): int {
    // Prefer medical_record.last_update if present
    $best = 0;
    if (isset($patient['medical_record']['last_update'])) {
        $t = strtotime((string)$patient['medical_record']['last_update']);
        if ($t) $best = $t;
    }

    // Fallback: any obvious timestamps under medical_record.* we might have
    if ($best === 0 && isset($patient['medical_record']) && is_array($patient['medical_record'])) {
        foreach ($patient['medical_record'] as $k => $v) {
            if (is_string($v)) {
                $t = strtotime($v);
                if ($t && $t > $best) $best = $t;
            }
        }
    }

    // Final fallback: patient's date_created
    if ($best === 0 && !empty($patient['date_created'])) {
        $t = strtotime((string)$patient['date_created']);
        if ($t) $best = $t;
    }

    return $best ?: 0;
}

   // === Filtered Recent Patients: only those linked to the logged-in doctor in medical_records ===
$allPatients = $database->getReference('patients')->getValue();
$filtered = [];
if ($allPatients && is_array($allPatients)) {
    foreach ($allPatients as $pKey => $patient) {
        if (!is_array($patient)) continue;

        // Safely derive doctor's name for matching
        $rawName = $username ?? (
            $doctorData['name'] ??
            ( ($doctorData['fname'] ?? '') . ' ' . ($doctorData['lname'] ?? '') )
        );

        if (is_array($rawName)) {
            // If somehow stored as ['fname' => ..., 'lname' => ...] or similar
            $doctorNameForMatch = trim(implode(' ', $rawName));
        } else {
            $doctorNameForMatch = trim((string)$rawName);
        }

        if ($doctorNameForMatch !== '' && patientHasDoctorInRecords($patient, $doctorNameForMatch)) {
            $patient['_key']       = $pKey;
            $patient['_recent_ts'] = mostRecentRecordTs($patient);
            $filtered[] = $patient;
        }
    }

    usort($filtered, function($a, $b){
        return ($b['_recent_ts'] ?? 0) <=> ($a['_recent_ts'] ?? 0);
    });

    $filtered = array_slice($filtered, 0, 5);
}

$recentPatients = $filtered;



    // Calculate tomorrow's date
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $tomorrowSchedules = [];
    // Fetch the doctor's schedules using the logged-in doctor's id ($userid)
    $schedulesRef = $database->getReference('schedule')
        ->orderByChild('docid')
        ->equalTo($userid);
    $allSchedules = $schedulesRef->getValue();
    if ($allSchedules) {
        foreach ($allSchedules as $sch) {
            if (isset($sch['scheduledate']) && $sch['scheduledate'] === $tomorrow) {
                $tomorrowSchedules[] = $sch;
            }
        }
    }


$photo = '../img/user.png'; // Default image
if ($doctorData) {
    $doctor = reset($doctorData); // Get the first record
    if (!empty($doctor['photo'])) {
        $photo = $doctor['photo']; // Use the photo from Firebase
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
    <title>Dashboard</title>
    <style>
        .dashbord-tables,.doctor-heade{
            animation: transitionIn-Y-over 0.5s;
        }
        .filter-container{
            animation: transitionIn-Y-bottom  0.5s;
        }
        .sub-table,#anim{
            animation: transitionIn-Y-bottom 0.5s;
        }
        .doctor-header{
            animation: transitionIn-Y-over 0.5s;
        }
        .dashboard-items {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 25px;
            border: 1px solid #ccc;
            border-radius: 5px;
            background: white;
            height: 150px;
        }
        .chart-card {
            width: 100%;
            max-width: 700px;
            height: 500px;
            background-color: rgba(200, 200, 200, 0.1);
            padding: 10px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            text-align: center;
        }
        .chart-card h3 {
            font-size: 14px;
            color: #333;
            margin-bottom: 10px;
        }
        .chart-card canvas {
            display: block;
            width: 100%;
            height: 100%;
            max-height: 250px;
            margin: 0 auto;
        }
        .dashboard-content {
            gap: 10px;
        }
        #upcoming-sessions {
            margin-top: -170px;
            padding-top: 0;
            text-align: left;
        }
        .appointment-requests, .recent-patients {
            flex: 1;
            background: #fff;
            border-radius: 6px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            padding: 15px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        .header h3 {
            font-size: 16px;
            font-weight: 500;
        }
        .header a {
            color: #007BFF;
            text-decoration: none;
            font-size: 12px;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        .table th, .table td {
            padding: 6px 10px;
            border: 1px solid #ddd;
            font-size: 12px;
        }
        .table th {
            background: #f8f9fa;
            font-weight: 500;
        }
        .table tbody tr:hover {
            background: #f9f9f9;
        }
        .btn-approve {
            color: green;
            background: none;
            border: none;
            font-size: 14px;
            cursor: pointer;
        }
        .btn-reject {
            color: red;
            background: none;
            border: none;
            font-size: 14px;
            cursor: pointer;
        }
        .section-container {
            width: 95%;
            margin: 0 auto;
            padding: 0;
        }
        .doctor-schedule {
            margin-bottom: 20px;
            margin-left: -20px;
            width: 96%;
        }
        .recent-patients {
            margin-top: 0;
            background: none;
            border: none;
            box-shadow: none;
            width: 100%;
        }
        .doctor-schedule .header h3, .recent-patients .header h3 {
            margin: 0;
            padding: 0;
            font-size: 16px;
            font-weight: bold;
        }
        .doctor-schedule .table, .recent-patients .table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }
        .doctor-schedule .table th, .recent-patients .table th {
            background-color: #28a745;
            color: white;
            text-align: left;
            padding: 8px;
            font-size: 14px;
        }
        .doctor-schedule .table td, .recent-patients .table td {
            text-align: left;
            padding: 8px;
            font-size: 14px;
        }
        .doctor-schedule {
            margin-bottom: 10px;
        }
        .recent-patients .name-container {
            display: flex;
            align-items: center;
        }
        .recent-patients .profile-pic {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 10px;
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

        /* =========================
   MOBILE HEADER + DRAWER (Doctor Dashboard)
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
  }
  .menu.active{ display:block; left:0; }

  /* ✅ DO NOT PUSH CONTENT (fix right-side gap) */
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

 .mobile-calendar-btn{
    width:34px;
    height:34px;
    border-radius:10px;
    border:none;
    background:rgba(255, 255, 255, 0);
    display:flex;
    align-items:center;
    justify-content:center;
  }
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

  /* hide desktop header row on mobile */
  .dash-body > table > tbody > tr:first-child{
    display:none !important;
  }

  /* stop horizontal scroll */
  html, body{ overflow-x:hidden !important; }

  /* OPTIONAL: make banner full width on mobile */
  table.filter-container{
    width:100% !important;
    max-width:100% !important;
    margin:0 !important;
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

  <div class="mh-title">Dashboard</div>

  <div class="mh-right">
    <div class="mh-date">
      <div class="mh-date-label">Date</div>
      <div class="mh-date-value"><?php echo $today; ?></div>
    </div>
     <button class="mobile-calendar-btn" type="button" aria-label="Calendar">
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
                                <td style="padding:0;margin:0;">
                                    <p class="profile-title"><?php echo substr($username,0,50) ?></p>
                                    <p class="profile-subtitle"><?php echo substr($useremail,0,50) ?></p>
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
                    <td class="menu-btn menu-active">
                        <a href="index.php" class="non-style-link-menu non-style-link-menu-active">
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
                    <td class="menu-btn">
                        <a href="patient.php" class="non-style-link-menu">
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
        <div class="dash-body" style="margin-top: 15px">
            <table border="0" width="100%" style="border-spacing: 0;margin:0;padding:0;">
                <tr>
                    <td colspan="1" class="nav-bar">
                        <p style="font-size: 23px;padding-left:12px;font-weight: 600;margin-left:20px;">Dashboard</p>
                    </td>
                    <td width="25%"></td>
                    <td width="15%">
                        <p style="font-size: 14px;color: rgb(119, 119, 119);padding: 0;margin: 0;text-align: right;">Today's Date</p>
                        <p class="heading-sub12" style="padding: 0;margin: 0;">
                            <?php 
                                date_default_timezone_set('Asia/Manila');
                                $today = date('Y-m-d');
                                echo $today;
                            ?>
                        </p>
                    </td>
                    <td width="10%">
                        <button class="btn-label" style="display: flex;justify-content: center;align-items: center;">
                            <img src="../img/calendar.svg" width="100%">
                        </button>
                    </td>
                </tr>
                <tr>
                    <td colspan="4">
                        <center>
                            <table class="filter-container" style="border: none; width: 95%; background-image: url('<?php echo $bannerUrl; ?>'); background-size: cover; padding-left: 30px;" border="0">
                                <tr>
                                    <td>
                                        <h3>Welcome!</h3>
                                        <h1><?php echo $username ?>.</h1>
                                        <p>Thanks for joining with us. We are always trying to get you a complete service<br>
                                        You can view your daily schedule, reach patients' appointments at home!<br><br></p>
                                        <a href="schedule.php" class="non-style-link">
                                            <button class="btn-primary btn" style="width:30%">View My Sessions</button>
                                        </a>
                                        <br><br>
                                    </td>
                                </tr>
                            </table>
                        </center>
                    </td>
                </tr>
                <tr>
                    <td colspan="4">
                        <table border="0" width="100%">
                            <tr>
                                <td width="50%">
                                    <center>
                                        <table class="filter-container" style="border: none;" border="0">
                                            <div class="chart-card" style="width:100%; background-size: cover; padding: 20px; border-radius: 8px; margin: auto; text-align: start;">
                                                <h2>Announcements</h2>
                                                <p style="padding-left: 30px;"><?php echo nl2br(htmlspecialchars($announcementText)); ?></p>
                                            </div>
                                        </table>
                                    </center>
                                </td>
                                <td>
                                    <div class="section-container">
                                        <!-- Doctor's Schedule -->
                                        <div class="doctor-schedule">
                                            <div class="header">
                                                <h3>Doctor's Schedule</h3>
                                                <a href="schedule.php">See All</a>
                                            </div>
                                            <table class="table">
                                                <thead>
                                                    <tr>
                                                        <th>Date</th>
                                                        <th>Time</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php
                                                        // Define the date range for the next 7 days.
                                                        $today = date('Y-m-d');
                                                        $nextWeek = date('Y-m-d', strtotime('+7 days'));
                                                        $doctorQuery = $database->getReference('doctor')->orderByChild('email')->equalTo($useremail);
                                                        $doctorData = $doctorQuery->getValue();

                                                        if ($doctorData) {
                                                            $doctor = reset($doctorData);
                                                            $doctorId = key($doctorData);
                                                            $schedulesRef = $database->getReference('schedule')->orderByChild('docid')->equalTo($doctorId);
                                                            $allSchedules = $schedulesRef->getValue();
                                                            $schedules = array();
                                                            if ($allSchedules) {
                                                                foreach ($allSchedules as $sch) {
                                                                    if (isset($sch['scheduledate']) && $sch['scheduledate'] >= $today && $sch['scheduledate'] <= $nextWeek) {
                                                                        $schedules[] = $sch;
                                                                    }
                                                                }
                                                            }
                                                            usort($schedules, function($a, $b) {
                                                                return strtotime($a['scheduledate']) - strtotime($b['scheduledate']);
                                                            });
                                                        } else {
                                                            echo "Doctor record not found.";
                                                            exit();
                                                        }
                                                        if (!empty($schedules)):
                                                            foreach ($schedules as $schedule): ?>
                                                                <tr>
                                                                    <td><?php echo htmlspecialchars($schedule['scheduledate']); ?></td>
                                                                    <td><?php echo htmlspecialchars($schedule['scheduletime']); ?></td>
                                                                </tr>
                                                            <?php endforeach;
                                                        else: ?>
                                                            <tr>
                                                                <td colspan="2">No schedules available</td>
                                                            </tr>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <!-- Recent Patients -->
                                    <div class="recent-patients" style="margin-left: -15px">
                                        <div class="header">
                                            <h3>Recent Patients</h3>
                                            <a href="patient.php">See All</a>
                                        </div>
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Name</th>
                                                    <th>Gender</th>
                                                    <th>Email</th>
                                                    <th>Contact</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                               <?php if (!empty($recentPatients)): ?>
                                                    <?php foreach ($recentPatients as $patient): ?>
                                                        <?php
                                                            // Safe fallbacks for potentially missing keys
                                                            $name = $patient['name'] ?? trim(($patient['fname'] ?? '') . ' ' . ($patient['lname'] ?? ''));
                                                            if ($name === '') { $name = 'Unnamed Patient'; }

                                                            $gender  = $patient['gender'] ?? '—';
                                                            $email   = isset($patient['email']) && $patient['email'] !== '' ? $patient['email'] : null;
                                                            $contact = $patient['tele'] ?? $patient['contact'] ?? $patient['phone'] ?? '—';

                                                            $photoUrl = !empty($patient['photo']) ? $patient['photo'] : '../img/user.png';
                                                        ?>
                                                        <tr>
                                                            <td>
                                                                <div class="name-container">
                                                                    <img src="<?php echo htmlspecialchars($photoUrl); ?>" alt="Profile" class="profile-pic">
                                                                    <span><?php echo htmlspecialchars($name); ?></span>
                                                                </div>
                                                            </td>
                                                            <td><?php echo htmlspecialchars($gender); ?></td>
                                                            <td><?php echo $email ? htmlspecialchars($email) : 'No account yet'; ?></td>
                                                            <td><?php echo htmlspecialchars($contact); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr><td colspan="4">No recent patients</td></tr>
                                                <?php endif; ?>

                                            </tbody>
                                        </table>
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </td>
                <tr>
            </table>
        </div>
        
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
             const hamburgerMenu = document.getElementById('hamburger-menu');
  const menu = document.querySelector('.menu');
  const menuOverlay = document.getElementById('menuOverlay');

  function openCloseMenu(){
    hamburgerMenu.classList.toggle('active');
    menu.classList.toggle('active');
    if (menuOverlay) menuOverlay.classList.toggle('active');
  }

  if (hamburgerMenu) hamburgerMenu.addEventListener('click', openCloseMenu);

  if (menuOverlay){
    menuOverlay.addEventListener('click', () => {
      hamburgerMenu.classList.remove('active');
      menu.classList.remove('active');
      menuOverlay.classList.remove('active');
    });
  }
            // Get data from PHP
            const patientsByCity = <?php echo $patientsByCityJson; ?>;
            const secretariesBySpecialty = <?php echo $secretariesBySpecialtyJson; ?>;
            const doctorsBySpecialty = <?php echo $doctorsBySpecialtyJson; ?>;
            
            // Example datasets for additional charts (if needed)
            const appointmentsData = {
                Monday: 10,
                Tuesday: 15,
                Wednesday: 20,
                Thursday: 18,
                Friday: 25
            };

            const ratingsData = {
                Excellent: 50,
                Good: 30,
                Average: 15,
                Poor: 5
            };

            function renderChart(chartId, label, data) {
                const ctx = document.getElementById(chartId).getContext('2d');
                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: Object.keys(data),
                        datasets: [{
                            label: label,
                            data: Object.values(data),
                            backgroundColor: [
                                'rgba(75, 192, 192, 0.7)',
                                'rgba(255, 99, 132, 0.7)',
                                'rgba(255, 206, 86, 0.7)',
                                'rgba(54, 162, 235, 0.7)',
                                'rgba(153, 102, 255, 0.7)'
                            ],
                            borderColor: [
                                'rgba(75, 192, 192, 1)',
                                'rgba(255, 99, 132, 1)',
                                'rgba(255, 206, 86, 1)',
                                'rgba(54, 162, 235, 1)',
                                'rgba(153, 102, 255, 1)'
                            ],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: { display: false }
                        },
                        scales: {
                            x: {
                                beginAtZero: true,
                                ticks: { font: { size: 10 } },
                                grid: { drawBorder: false, display: true }
                            },
                            y: {
                                ticks: { font: { size: 10 } },
                                grid: { display: false }
                            }
                        },
                        layout: { padding: { top: 10, bottom: 10 } }
                    }
                });
            }

            // Render all charts
            renderChart('patientsChart', 'Patients by City', patientsByCity);
            renderChart('waitingTimeChart', 'Waiting Time (min)', appointmentsData);
            renderChart('ratingsChart', 'Satisfaction Ratings', ratingsData);

            // Notes handling
            function loadNotes() {
                const notesTextarea = document.getElementById('notes');
                const savedNotes = localStorage.getItem('dashboardNotes');
                if (savedNotes) {
                    notesTextarea.value = savedNotes;
                }
            }

            function saveNotes() {
                const notesTextarea = document.getElementById('notes');
                localStorage.setItem('dashboardNotes', notesTextarea.value);
            }

            

            // Pass the PHP array to JavaScript as a JSON object
            const tomorrowSchedules = <?php echo json_encode($tomorrowSchedules); ?>;

            // Function to show notification if there is any schedule for tomorrow
            function showTomorrowScheduleNotification() {
                if (!("Notification" in window)) {
                    console.log("This browser does not support desktop notifications.");
                    return;
                }
                Notification.requestPermission().then(permission => {
                    if (permission === "granted" && tomorrowSchedules.length > 0) {
                        let scheduleText = "Tomorrow's Schedule:\n";
                        tomorrowSchedules.forEach(sch => {
                            scheduleText += `${sch.scheduledate} at ${sch.scheduletime}\n`;
                        });
                        new Notification("Upcoming Schedule", {
                            body: scheduleText,
                            icon: "../img/notification.png"
                        });
                    }
                });
            }

            // Combined window.onload event to initialize notes and show notifications
            window.onload = () => {
                loadNotes();
                const notesTextarea = document.getElementById('notes');
                if (notesTextarea) {
                    notesTextarea.addEventListener('input', saveNotes);
                }
                showTomorrowScheduleNotification();
            };

           
        </script>
    </div>
</body>
</html>
