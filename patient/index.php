<?php

session_name('sess_patient'); session_start();
if (empty($_SESSION['user']) || ($_SESSION['usertype'] ?? '') !== 'p') {
    header('Location: ../login.php'); exit();
}

date_default_timezone_set('Asia/Manila');

// Set today's date
$today = date('Y-m-d');

// Check if the user is logged in and is a patient
if(isset($_SESSION["user"])){
    if(($_SESSION["user"]) == "" || $_SESSION['usertype'] != 'p'){
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

function canonDate(string $d): string {
    $d = trim($d);
    if ($d === '') return '';
    $dt = DateTime::createFromFormat('Y-m-d', $d) ?: new DateTime($d);
    return $dt->format('Y-m-d');
}

function canonTime(string $t): string {
    $t = trim($t);
    if ($t === '') return '';
    $dt = DateTime::createFromFormat('H:i:s', $t); if ($dt) return $dt->format('H:i');
    $dt = DateTime::createFromFormat('H:i',   $t); if ($dt) return $dt->format('H:i');
    $dt = DateTime::createFromFormat('g:i A', strtoupper($t)); if ($dt) return $dt->format('H:i');
    $dt = new DateTime($t); return $dt->format('H:i');
}

// Retrieve the image URL from Firebase Realtime Database
$imageUrl = $database->getReference('cms/banner')->getValue();

// Retrieve font color from Firebase
$fontColor = '#000000';
try {
    $fontColorRef = $database->getReference('settings/fontColor');
    $fontColor = $fontColorRef->getValue() ?: $fontColor;
} catch (Exception $e) {
    echo "<script>console.error('Failed to retrieve font color: " . $e->getMessage() . "');</script>";
}

// Firebase query to get patient data based on email
$username = "Patient";
try {
    $reference = $database
        ->getReference('patients')
        ->orderByChild('email')
        ->equalTo($useremail)
        ->getSnapshot();

    $userfetch = $reference->getValue();

    if ($userfetch) {
        foreach($userfetch as $key => $value) {
            $username = ($value['fname'] ?? '') . ' ' . ($value['lname'] ?? '');
            $username = trim($username) ?: "Patient";
            break;
        }
    }
} catch (\Kreait\Firebase\Exception\DatabaseException $e) {
    // optional: echo "Error querying the database: " . $e->getMessage();
}

// ----------------- UPCOMING APPOINTMENTS (NEXT 24H) FOR NOTIFICATIONS -----------------
$schedulesRef = $database->getReference('appointment')->orderByChild('email')->equalTo($useremail);
$schedules = $schedulesRef->getValue();

$upcomingAppointments = [];
if ($schedules) {
    $currentTimestamp = time();
    $nextDayTimestamp = $currentTimestamp + (24 * 60 * 60);

    foreach ($schedules as $schedule) {
        if (isset($schedule['scheduledate'], $schedule['scheduletime'])) {
            $scheduleTimestamp = strtotime($schedule['scheduledate'] . ' ' . $schedule['scheduletime']);
            if ($scheduleTimestamp > $currentTimestamp && $scheduleTimestamp <= $nextDayTimestamp) {
                $doctorName  = $schedule['doctorname']  ?? 'Unknown';
                $doctorEmail = $schedule['doctoremail'] ?? 'Unknown';

                $upcomingAppointments[] = [
                    'date' => $schedule['scheduledate'],
                    'time' => $schedule['scheduletime'],
                    'title' => $schedule['title'] ?? 'Appointment',
                    'doctor_name' => $doctorName,
                    'doctor_email' => $doctorEmail
                ];
            }
        }
    }
}

// ----------------- LAB RESULTS (READY / WILL BE READY) -----------------
$labNotifications = [];
$labData = [];

try {
    $labRef = $database->getReference('laboratory_results')
        ->orderByChild('patient_email')
        ->equalTo($useremail);

    $labData = $labRef->getValue() ?: [];
    $currentDate = date('Y-m-d');

    if ($labData) {
        foreach ($labData as $labId => $lab) {
            $status            = $lab['status']              ?? 'pending';
            $testType          = $lab['test_type']           ?? 'Laboratory Test';
            $expectedReadyDate = $lab['expected_ready_date'] ?? null;
            $readyTime         = $lab['ready_time']          ?? '';
            $requestingDoctor  = $lab['requesting_doctor']   ?? 'Laboratory';
            $labEmail          = $lab['lab_email']           ?? 'laboratory@example.com';

            if ($status === 'ready') {
                $labNotifications[] = [
                    'date'         => $expectedReadyDate ?: $currentDate,
                    'time'         => $readyTime,
                    'title'        => "Lab Result Ready: {$testType}",
                    'doctor_name'  => $requestingDoctor,
                    'doctor_email' => $labEmail,
                ];
            } elseif ($expectedReadyDate && $expectedReadyDate >= $currentDate) {
                $labNotifications[] = [
                    'date'         => $expectedReadyDate,
                    'time'         => $readyTime,
                    'title'        => "Lab Result (will be ready): {$testType}",
                    'doctor_name'  => $requestingDoctor,
                    'doctor_email' => $labEmail,
                ];
            }
        }
    }
} catch (Exception $e) {
    // optional logging
}

// ----------------- MERGE NOTIFICATIONS -----------------
$allNotifications = array_merge($upcomingAppointments, $labNotifications);

if (!isset($_SESSION['notifications_shown']) || $_SESSION['notifications_shown'] !== true) {
    $_SESSION['notifications'] = $allNotifications;
    $_SESSION['notifications_shown'] = true;
}

// ----------------- PATIENT PHOTO -----------------
$patientRef = $database->getReference('patients')->orderByChild('email')->equalTo($useremail);
$patientSnapshot = $patientRef->getSnapshot();
$patientData = $patientSnapshot->getValue();

$photo = '../img/user.png';
if ($patientData) {
    $patient = reset($patientData);
    if (!empty($patient['photo'])) {
        $photo = $patient['photo'];
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
    .patient-background {
        width: 100%;
        height: 100vh;
        background-size: cover;
        background-repeat: no-repeat;
        background-position: center;
    }
    .dashbord-tables{ animation: transitionIn-Y-over 0.5s; }
    .filter-container{
        animation: transitionIn-Y-bottom  0.5s;
        color: <?php echo $fontColor; ?>;
    }
    .sub-table,.anime{ animation: transitionIn-Y-bottom 0.5s; }

    /* Cards */
    .dashboard-items{
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 25px;
        border: 1px solid #ccc;
        border-radius: 5px;
        background: #fff;
        height: 150px;
    }

    .menu-btn a {
        display: block;
        text-decoration: none;
        padding: 1px;
        width: 100%;
    }
    .menu-btn:hover { background-color: #E8F8E8; }

    /* Upcoming table style */
    .upcoming-table td {
        padding: 18px 14px !important;
        vertical-align: middle;
        font-size: 16px;
        line-height: 1.4;
    }
    .appt-title { font-size: 16px; font-weight: 600; display: block; }
    .appt-status { font-size: 13px; color: #777; margin-top: 4px; display: block; }
    .appt-datetime { font-size: 15px; font-weight: 600; }
    .appt-num { font-size: 26px; font-weight: 700; text-align: center; }

    /* Global safety */
    html, body{ max-width: 100%; overflow-x: hidden; }
    img{ max-width: 100%; height: auto; }
    .container, .dash-body, .menu, .filter-container, .abc, .sub-table{ max-width: 100%; box-sizing: border-box; }
    .menu-container, .profile-container, .dash-body table{ width: 100%; }
    .filter-container h1, .filter-container h3, .filter-container p, .profile-title, .profile-subtitle{
        overflow-wrap: anywhere;
        word-break: break-word;
    }

    /* Scroll wrapper for desktop tables */
    .abc.scroll{
        width: 100% !important;
        max-width: 100% !important;
        overflow-x: auto !important;
        overflow-y: auto !important;
        -webkit-overflow-scrolling: touch;
    }
    .abc.scroll table.sub-table{
        width: max-content !important;
        min-width: 900px !important;
        table-layout: auto !important;
        border-collapse: collapse;
    }
    .sub-table th, .sub-table td{ white-space: nowrap; }
    .sub-table thead th{
        position: sticky;
        top: 0;
        background: #fff;
        z-index: 2;
    }

    @media (max-width: 768px){

  /* Full-width fixed header */
  .mobile-header{
    display:flex !important;
    position:fixed;
    top:0;left:0;right:0;
    height:56px;
    background:lightgreen;
    z-index:10050;
    align-items:center;
    justify-content:space-between;
    padding:0 12px;
    font-weight:700;
    color:#000;
  }

  .mobile-left,.mobile-right{
    display:flex;
    align-items:center;
    gap:10px;
  }
  .mobile-center{
    flex:1;
    text-align:center;
    font-size:16px;
  }

  .mobile-date, .mh-date-label, .mh-date-value {
    font-size:12px !important;
    font-weight:600;
  }

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
  .mobile-calendar-btn img{width:18px;height:18px;}

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
  #hamburger-menu.active .bar:nth-child(1){transform:rotate(-45deg) translate(-4px,5px);}
  #hamburger-menu.active .bar:nth-child(2){opacity:0;}
  #hamburger-menu.active .bar:nth-child(3){transform:rotate(45deg) translate(-4px,-5px);}

        /* menu slide */
        .menu{
            display: none;
            position: fixed;
            left: -250px;
            top: 52px;              /* EXACTLY header height */
            width: 250px;
            height: calc(100% - 52px);
            background: lightgreen;
            transition: left 0.3s ease;
            z-index: 1900;
        }
        .menu.active{ display:block; left: 0; }

        /* push content below header */
        .dash-body{
            margin-top: 70px !important;
            margin-left: 0 !important;
            transition: margin-left 0.3s ease;
            padding: 0 10px;
        }
        .menu.active + .dash-body{ margin-left: 250px !important; }

        /* Hide the desktop "Home + Date" table row on mobile */
        .dash-body > table > tbody > tr:first-child{
            display: none !important;
        }

        /* Stack Status (left td) + Upcoming Booking (right td) */
        .dash-body > table > tbody > tr > td[colspan="4"] > table[width="100%"] > tbody > tr{
            display: block !important;
        }
        .dash-body > table > tbody > tr > td[colspan="4"] > table[width="100%"] > tbody > tr > td{
            display: block !important;
            width: 100% !important;
            max-width: 100% !important;
        }

        /* Welcome panel compact */
        .filter-container{
            width: 100% !important;
            padding: 14px !important;
            border-radius: 10px;
        }
        .filter-container h3{ font-size: 18px !important; }
        .filter-container h1{ font-size: 22px !important; line-height: 1.2; }
        .filter-container p { font-size: 14px !important; line-height: 1.4; }

        /* Cards fit */
        .dashboard-items{
            width: 100% !important;
            height: auto;
            padding: 16px !important;
            gap: 12px;
        }

        /* ========== CARD VIEW TABLES (show ALL columns without side scroll) ========== */
        .abc.scroll{ overflow-x: hidden !important; }
        .abc.scroll table.sub-table{ width: 100% !important; min-width: 0 !important; }
        .sub-table th, .sub-table td{ white-space: normal !important; }

        /* Upcoming booking card view */
        table.booking-table thead{ display: none !important; }
        table.booking-table, table.booking-table tbody, table.booking-table tr, table.booking-table td{
            display: block !important;
            width: 100% !important;
        }
        table.booking-table tr{
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 12px;
            margin: 12px 0;
        }
        table.booking-table td{
            padding: 8px 0 !important;
            text-align: left !important;
            border: 0 !important;
        }
        table.booking-table td:nth-child(1)::before{ content: "Appointment Number: "; font-weight: 800; }
        table.booking-table td:nth-child(2)::before{ content: "Session Title: "; font-weight: 800; }
        table.booking-table td:nth-child(3)::before{ content: "Doctor: "; font-weight: 800; }
        table.booking-table td:nth-child(4)::before{ content: "Scheduled Date & Time: "; font-weight: 800; }
        table.booking-table td.appt-num{
            font-size: 18px !important;
            text-align: left !important;
        }

        /* Lab card view */
        table.lab-table thead{ display: none !important; }
        table.lab-table, table.lab-table tbody, table.lab-table tr, table.lab-table td{
            display: block !important;
            width: 100% !important;
        }
        table.lab-table tr{
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 12px;
            margin: 12px 0;
        }
        table.lab-table td{
            padding: 8px 0 !important;
            text-align: left !important;
            border: 0 !important;
        }
        table.lab-table td:nth-child(1)::before{ content: "Test: "; font-weight: 800; }
        table.lab-table td:nth-child(2)::before{ content: "Status: "; font-weight: 800; }
        table.lab-table td:nth-child(3)::before{ content: "Ready / Expected: "; font-weight: 800; }
        table.lab-table td:nth-child(4)::before{ content: "Requested By: "; font-weight: 800; }
    }

    @media (max-width: 480px){
        .filter-container h1{ font-size: 20px !important; }
        .filter-container h3{ font-size: 16px !important; }
        .filter-container p { font-size: 13px !important; }
        .appt-num{ font-size: 20px; }
    }
    </style>
</head>

<body>

<div id="fallbackNotifications" style="display: none; padding: 10px; border: 1px solid #ccc; background-color: #f9f9f9; border-radius: 5px; margin-top: 20px;">
</div>

<div class="mobile-header" id="mobileHeader">
    <div class="mh-left">
        <div id="hamburger-menu">
            <div class="bar"></div>
            <div class="bar"></div>
            <div class="bar"></div>
        </div>
    </div>

    <div class="mh-center">Home</div>

     <div class="mobile-right">
        <div class="mobile-date"><?php echo $today; ?></div>
        <button class="mobile-calendar-btn" type="button" aria-label="Calendar">
            <img src="../img/calendar.svg" alt="">
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
                            <td width="30%" style="padding-left:20px" >
                                <img src="<?php echo htmlspecialchars($photo); ?>" alt="Profile Picture" width="100%" style="border-radius:50%">
                            </td>
                            <td style="padding:0px;margin:0px;">
                                <p class="profile-title"><?php echo substr($username,0,100)  ?></p>
                                <p class="profile-subtitle"><?php echo substr($useremail,0,50)  ?></p>
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
                <td class="menu-btn ">
                    <a href="doctors.php" class="non-style-link-menu">
                        <p class="menu-text">Service Appointments</p>
                    </a>
                </td>
            </tr>
            <tr class="menu-row">
                <td class="menu-btn ">
                    <a href="appointment.php" class="non-style-link-menu">
                        <p class="menu-text">My Appointments</p>
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

    <div class="dash-body" style="margin-top: 15px">
        <table border="0" width="100%" style="border-spacing:0;margin:0;padding:0;">
            <!-- ✅ Desktop header row (hidden on mobile by CSS) -->
            <tr>
                <td colspan="1" class="nav-bar">
                    <p style="font-size: 23px;padding-left:12px;font-weight: 600;margin-left:20px;">Home</p>
                </td>
                <td width="25%"></td>
                <td width="15%">
                    <p style="font-size: 14px;color: rgb(119, 119, 119);padding: 0;margin: 0;text-align: right;">
                        Today's Date
                    </p>
                    <p class="heading-sub12" style="padding: 0;margin: 0;text-align:right;">
                        <?php echo $today; ?>
                    </p>
                </td>
                <td width="10%">
                    <button class="btn-label" style="display:flex;justify-content:center;align-items:center;">
                        <img src="../img/calendar.svg" width="100%" alt="Calendar">
                    </button>
                </td>
            </tr>

            <tr>
                <td colspan="4">
                    <center>
                        <table class="filter-container" style="border:none;width:95%;background-image:url('<?php echo $imageUrl; ?>');background-size:cover;padding:20px;" border="0">
                            <tr>
                                <td>
                                    <h3>Welcome!</h3>
                                    <h1><?php echo htmlspecialchars($username); ?>.</h1>
                                    <p>
                                        Haven't any idea about doctors? no problem let's jumping to
                                        <a href="doctors.php" class="non-style-link"><b>"All Doctors"</b></a> section or
                                        <a href="appointment.php" class="non-style-link"><b>"Sessions"</b></a><br>
                                        Track your past and future appointments history.<br>
                                        Also find out the expected arrival time of your doctor or medical consultant.<br><br>
                                    </p>
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
                                    <table class="filter-container" style="border:none;" border="0">
                                        <tr>
                                            <td colspan="4">
                                                <p style="font-size:20px;font-weight:600;padding-left:12px;">Status</p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="width: 25%;">
                                                <div class="dashboard-items" style="padding:20px;margin:auto;width:95%;display:flex">
                                                    <div>
                                                        <div class="h1-dashboard">
                                                            <?php
                                                            // Count doctors that have at least one session within the next 7 days (including today)
                                                            $doctorsRef = $database->getReference('doctor');
                                                            $doctors    = $doctorsRef->getValue();

                                                            $tz        = new DateTimeZone('Asia/Manila');
                                                            $todayObj  = new DateTime('today', $tz);
                                                            $endObj    = (clone $todayObj)->modify('+6 days');

                                                            $startDate = $todayObj->format('Y-m-d');
                                                            $endDate   = $endObj->format('Y-m-d');

                                                            $availableDoctors = 0;

                                                            if (is_array($doctors)) {
                                                                foreach ($doctors as $doc) {
                                                                    if (empty($doc['sessions']) || !is_array($doc['sessions'])) continue;

                                                                    $hasWeekSession = false;
                                                                    foreach ($doc['sessions'] as $sess) {
                                                                        $sdRaw = $sess['scheduledate'] ?? ($sess['date'] ?? '');
                                                                        if (is_array($sdRaw)) $sdRaw = reset($sdRaw);
                                                                        $sd = trim((string)$sdRaw);
                                                                        if ($sd === '') continue;

                                                                        $canon = canonDate($sd);
                                                                        if ($canon >= $startDate && $canon <= $endDate) {
                                                                            $hasWeekSession = true;
                                                                            break;
                                                                        }
                                                                    }
                                                                    if ($hasWeekSession) $availableDoctors++;
                                                                }
                                                            }
                                                            echo $availableDoctors;
                                                            ?>
                                                        </div><br>
                                                        <div class="h3-dashboard">All Doctors</div>
                                                    </div>
                                                    <div class="btn-icon-back dashboard-icons" style="background-image:url('../img/icons/doctors-hover.svg');"></div>
                                                </div>
                                            </td>

                                            <td style="width: 25%;">
                                                <div class="dashboard-items" style="padding:20px;margin:auto;width:95%;display:flex;padding-top:21px;padding-bottom:21px;">
                                                    <div>
                                                        <div class="h1-dashboard">
                                                            <?php
                                                            if ($useremail) {
                                                                $scheduleRef  = $database->getReference('schedule');
                                                                $scheduleData = $scheduleRef->getValue();

                                                                if ($scheduleData) {
                                                                    $userSchedules = array_filter($scheduleData, function ($schedule) use ($useremail, $today) {
                                                                        return isset($schedule['email'], $schedule['scheduledate']) &&
                                                                               $schedule['email'] === $useremail &&
                                                                               $schedule['scheduledate'] === $today;
                                                                    });
                                                                    echo count($userSchedules);
                                                                } else {
                                                                    echo "0";
                                                                }
                                                            } else {
                                                                echo "0";
                                                            }
                                                            ?>
                                                        </div><br>
                                                        <div class="h3-dashboard" style="font-size: 15px">Today Sessions</div>
                                                    </div>
                                                    <div class="btn-icon-back dashboard-icons" style="background-image:url('../img/icons/session-iceblue.svg');"></div>
                                                </div>
                                            </td>
                                        </tr>
                                    </table>
                                </center>
                            </td>

                            <td>
                                <p style="font-size:20px;font-weight:600;padding-left:40px;" class="anime">Your Upcoming Booking</p>
                                <center>
                                    <div class="abc scroll" style="height:250px;padding:0;margin:0;">
                                        <table width="85%" class="sub-table scrolldown booking-table" border="0">
                                            <thead>
                                                <tr>
                                                    <th class="table-headin">Appointment Number</th>
                                                    <th class="table-headin">Session Title</th>
                                                    <th class="table-headin">Doctor</th>
                                                    <th class="table-headin">Scheduled Date & Time</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                            <?php
                                            // Prefer "appointments", fallback "appointment"
                                            $appointmentsData = [];
                                            try { $appointmentsData = $database->getReference('appointments')->getValue() ?: []; }
                                            catch (Throwable $e) { $appointmentsData = []; }

                                            if (empty($appointmentsData)) {
                                                try { $appointmentsData = $database->getReference('appointment')->getValue() ?: []; }
                                                catch (Throwable $e) { $appointmentsData = []; }
                                            }

                                            // doctors map
                                            $doctorsMap = [];
                                            try { $doctorsMap = $database->getReference('doctor')->getValue() ?: []; }
                                            catch (Throwable $e) { $doctorsMap = []; }

                                            $results = [];
                                            $nowTs   = time();

                                            if (!empty($appointmentsData) && is_array($appointmentsData)) {
                                                foreach ($appointmentsData as $appointmentId => $appointment) {

                                                    $emailField = strtolower(trim($appointment['patientEmail'] ?? ($appointment['email'] ?? '')));
                                                    if ($emailField !== strtolower($useremail)) continue;

                                                    $status = strtolower(trim($appointment['status'] ?? 'pending'));
                                                    if (!in_array($status, ['pending', 'confirmed'], true)) continue;

                                                    $scheduledDate = trim($appointment['date'] ?? ($appointment['scheduledate'] ?? ''));
                                                    if ($scheduledDate === '') continue;

                                                    $startTime = trim($appointment['start_time'] ?? ($appointment['scheduletime'] ?? ($appointment['time'] ?? '')));
                                                    $endTime   = trim($appointment['end_time']   ?? ($appointment['scheduletime'] ?? ($appointment['time'] ?? '')));

                                                    if ($startTime === '') continue;
                                                    if ($endTime === '') $endTime = $startTime;

                                                    $apptTs = strtotime($scheduledDate . ' ' . $startTime);
                                                    if ($apptTs < $nowTs) continue;

                                                    $doctorName = $appointment['doctorname'] ?? $appointment['doctor_name'] ?? '';
                                                    if ($doctorName === '') {
                                                        $docId = $appointment['doctorId'] ?? ($appointment['docid'] ?? '');
                                                        if ($docId !== '' && isset($doctorsMap[$docId]['name'])) $doctorName = $doctorsMap[$docId]['name'];
                                                        else $doctorName = 'Unknown Doctor';
                                                    }

                                                    $title   = $appointment['title'] ?? 'Consultation';
                                                    $apponum = $appointment['apponum'] ?? $appointment['queueNumber'] ?? '';

                                                    $results[] = [
                                                        'apponum' => $apponum,
                                                        'title'   => $title,
                                                        'doctor'  => $doctorName,
                                                        'date'    => $scheduledDate,
                                                        'start'   => canonTime($startTime),
                                                        'end'     => canonTime($endTime),
                                                        'status'  => ucfirst($status),
                                                        'ts'      => $apptTs,
                                                    ];
                                                }
                                            }

                                            usort($results, fn($a,$b) => $a['ts'] <=> $b['ts']);

                                            if (empty($results)) {
                                                echo '<tr>
                                                        <td colspan="4">
                                                            <br><br><br><br>
                                                            <center>
                                                                <img src="../img/notfound.svg" width="25%">
                                                                <br>
                                                                <p class="heading-main12" style="margin-left:45px;font-size:20px;color:rgb(49, 49, 49)">
                                                                    Nothing to show here!
                                                                </p>
                                                                <a class="non-style-link" href="doctors.php">
                                                                    <button class="login-btn btn-primary-soft btn" style="display:flex;justify-content:center;align-items:center;margin-left:20px;">
                                                                        &nbsp; View Doctors Schedule &nbsp;
                                                                    </button>
                                                                </a>
                                                            </center>
                                                            <br><br><br><br>
                                                        </td>
                                                      </tr>';
                                            } else {
                                                foreach ($results as $row) {
                                                    $timeRange = $row['start'] . ' - ' . $row['end'];

                                                    echo '<tr class="upcoming-table">
                                                            <td class="appt-num">'.htmlspecialchars($row["apponum"]).'</td>
                                                            <td>
                                                                <span class="appt-title">'.htmlspecialchars($row["title"]).'</span>
                                                                <span class="appt-status">('.htmlspecialchars($row["status"]).')</span>
                                                            </td>
                                                            <td>'.htmlspecialchars($row["doctor"]).'</td>
                                                            <td class="appt-datetime" style="text-align:center;">
                                                                '.htmlspecialchars($row["date"]).'<br>
                                                                '.htmlspecialchars($timeRange).'
                                                            </td>
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
                </td>
            </tr>

            <!-- LABORATORY RESULTS SECTION -->
            <tr>
                <td colspan="4">
                    <p style="font-size:20px;font-weight:600;padding-left:40px;" class="anime">Laboratory Results</p>
                    <center>
                        <div class="abc scroll" style="height:250px;padding:0;margin:0;">
                            <table width="85%" class="sub-table scrolldown lab-table" border="0">
                                <thead>
                                    <tr>
                                        <th class="table-headin">Test</th>
                                        <th class="table-headin">Status</th>
                                        <th class="table-headin">Ready / Expected Date</th>
                                        <th class="table-headin">Requested By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php
                                if (!empty($labData)) {
                                    foreach ($labData as $lab) {
                                        $testType          = $lab['test_type']           ?? 'Laboratory Test';
                                        $status            = $lab['status']              ?? 'pending';
                                        $expectedReadyDate = $lab['expected_ready_date'] ?? 'N/A';
                                        $readyTime         = $lab['ready_time']          ?? '';
                                        $requestingDoctor  = $lab['requesting_doctor']   ?? 'Laboratory';

                                        if ($status === 'ready') $statusText = 'Ready to pick up';
                                        elseif ($status === 'processing') $statusText = 'Processing';
                                        elseif ($status === 'pending') $statusText = 'Pending';
                                        else $statusText = ucfirst($status);

                                        echo '<tr>';
                                        echo '<td>'.htmlspecialchars($testType).'</td>';
                                        echo '<td>'.htmlspecialchars($statusText).'</td>';
                                        echo '<td>'.htmlspecialchars(trim($expectedReadyDate.' '.$readyTime)).'</td>';
                                        echo '<td>'.htmlspecialchars($requestingDoctor).'</td>';
                                        echo '</tr>';
                                    }
                                } else {
                                    echo '<tr>
                                            <td colspan="4" style="text-align:center;padding:20px;">
                                                No laboratory results yet.
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

    <script>
    const hamburgerMenu = document.getElementById('hamburger-menu');
    const menu = document.querySelector('.menu');
    const dashBody = document.querySelector('.dash-body');

    hamburgerMenu.addEventListener('click', () => {
        hamburgerMenu.classList.toggle('active');
        menu.classList.toggle('active');
        dashBody.classList.toggle('active');
    });

    document.addEventListener('DOMContentLoaded', () => {
        const notifications = <?php echo json_encode($_SESSION['notifications'] ?? []); ?>;

        if (Array.isArray(notifications) && notifications.length > 0) {
            if (sessionStorage.getItem('notificationsShown') !== 'true') {
                if (Notification.permission === 'default') {
                    Notification.requestPermission()
                        .then((permission) => {
                            if (permission === 'granted') displayNotifications(notifications);
                        })
                        .catch((error) => console.error('Error requesting notification permission:', error));
                } else if (Notification.permission === 'granted') {
                    displayNotifications(notifications);
                }
                sessionStorage.setItem('notificationsShown', 'true');
            }
        }
    });

    function displayNotifications(notifications) {
        notifications.forEach((notification) => {
            const title = `Upcoming Appointment: ${notification.title}`;
            const options = {
                body: `Date: ${notification.date}\nTime: ${notification.time}\nDoctor: ${notification.doctor_name}\nEmail: ${notification.doctor_email}`,
                icon: '../img/notification-icon.png',
            };
            new Notification(title, options);
        });
    }
    </script>

</div>
</body>
</html>
