<?php
session_name('sess_patient'); session_start();
if (isset($_SESSION["user"])) {
    if (empty($_SESSION["user"]) || $_SESSION['usertype'] != 'p') {
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

/* =========================
   Canonical slot helpers
   ========================= */
function canonDate(string $d): string {
    $d = trim($d);
    $dt = DateTime::createFromFormat('Y-m-d', $d) ?: new DateTime($d);
    return $dt->format('Y-m-d');
}
function canonTime(string $t): string {
    $t = trim($t);
    $dt = DateTime::createFromFormat('H:i:s', $t); if ($dt) return $dt->format('H:i');
    $dt = DateTime::createFromFormat('H:i',   $t); if ($dt) return $dt->format('H:i');
    $dt = DateTime::createFromFormat('g:i A', strtoupper($t)); if ($dt) return $dt->format('H:i');
    $dt = new DateTime($t); return $dt->format('H:i');
}
function canonSlotKey(string $docId, string $date, string $time): string {
    return trim($docId) . '|' . canonDate($date) . '|' . canonTime($time);
}
function canonSlotKeyRange(string $docId, string $date, string $start, string $end): string {
    return trim($docId) . '|' . canonDate($date) . '|' . canonTime($start) . '-' . canonTime($end);
}

/* =========================
   Today
   ========================= */
date_default_timezone_set('Asia/Manila');
$today = date('Y-m-d');

/* =========================
   Fetch patient data (single query, reuse)
   ========================= */
$patientSnap = $database->getReference('patients')->orderByChild('email')->equalTo($useremail)->getSnapshot();
$patientData = $patientSnap->getValue();

if ($patientData) {
    $firstRecord  = array_values($patientData)[0];
    $username     = trim(($firstRecord["fname"] ?? '').' '.($firstRecord["lname"] ?? ''));
    if ($username === '') {
        $username = $firstRecord['name'] ?? 'Patient';
    }
} else {
    echo "No patient data found.";
    exit();
}

// Photo
$photo = '../img/user.png';
if (!empty($firstRecord['photo'])) {
    $photo = $firstRecord['photo'];
}

// Search query
$searchQuery = "";
if (isset($_GET['search'])) {
    $searchQuery = trim($_GET['search']);
}

// patient info for booking
$patientId    = $patientData ? array_key_first($patientData) : null;
$patient      = $patientData ? reset($patientData) : null;
$patientName  = $patient ? trim(($patient['fname'] ?? '').' '.($patient['lname'] ?? '')) : $username;
$patientEmail = $useremail;

/* =========================
   BOOKING HANDLER
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'book_session') {
    $docId   = trim($_POST['doctor_id'] ?? '');
    $sessKey = trim($_POST['session_key'] ?? '');
    $sDate   = trim($_POST['schedule_date'] ?? '');

    $sStart  = trim($_POST['schedule_start'] ?? ($_POST['schedule_time'] ?? ''));
    $sEnd    = trim($_POST['schedule_end']   ?? ($_POST['schedule_time'] ?? ''));
    $sTitle  = trim($_POST['schedule_title'] ?? 'Consultation');

    if (!$patientId || !$docId || !$sessKey || !$sDate || !$sStart || !$sEnd) {
        $_SESSION['error_message'] = 'Missing data to book this schedule.';
        header('Location: doctors.php'); exit();
    }

    $slotKeyNew = canonSlotKeyRange($docId, $sDate, $sStart, $sEnd);
    $slotKeyOld = canonSlotKey($docId, $sDate, $sStart);

    try {
        $sessionPath = "doctor/{$docId}/sessions/{$sessKey}";
        $sessionSnap = $database->getReference($sessionPath)->getSnapshot();
        if (!$sessionSnap->exists()) {
            $_SESSION['error_message'] = 'This schedule no longer exists.';
            header('Location: doctors.php'); exit();
        }

        $existingForUserNew = $database->getReference("patients/{$patientId}/slots/{$slotKeyNew}")->getValue();
        $existingForUserOld = $database->getReference("patients/{$patientId}/slots/{$slotKeyOld}")->getValue();
        if (!empty($existingForUserNew) || !empty($existingForUserOld)) {
            $_SESSION['error_message'] = 'You already have a booking for this schedule.';
            header('Location: doctors.php'); exit();
        }

        // Smallest available queue number
        $slotQueueAppointmentsPath = "doctor/{$docId}/queues/{$slotKeyNew}/appointments";
        $existingAppointmentsMap   = $database->getReference($slotQueueAppointmentsPath)->getValue() ?: [];

        $usedNumbers = [];
        if (is_array($existingAppointmentsMap)) {
            foreach ($existingAppointmentsMap as $aid => $num) {
                if (is_numeric($num)) $usedNumbers[] = (int)$num;
            }
        }
        sort($usedNumbers);
        $apponum = 1;
        foreach ($usedNumbers as $n) {
            if ($n === $apponum) $apponum++;
            elseif ($n > $apponum) break;
        }

        $tsNow = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
        $appointment = [
            'doctorId'     => $docId,
            'patientId'    => $patientId,
            'patientName'  => $patientName,
            'patientEmail' => $patientEmail,
            'date'         => canonDate($sDate),

            'start_time'   => canonTime($sStart),
            'end_time'     => canonTime($sEnd),
            'time'         => canonTime($sStart),

            'title'        => $sTitle,
            'status'       => 'pending',
            'createdAt'    => $tsNow,

            'slotKey'      => $slotKeyNew,
            'sessionKey'   => $sessKey,

            'apponum'      => $apponum,
            'queueNumber'  => $apponum,
            'source'       => 'patient'
        ];

        $apptRef = $database->getReference('appointments')->push($appointment);
        $apptId  = $apptRef->getKey();

        $database->getReference("{$sessionPath}/bookings/{$apptId}")->set($patientId);
        $database->getReference("doctor/{$docId}/queues/{$slotKeyNew}/appointments/{$apptId}")->set($apponum);

        $database->getReference("patients/{$patientId}/appointments/{$apptId}")->set(true);
        $database->getReference("doctor/{$docId}/appointments/{$apptId}")->set(true);

        $database->getReference("patients/{$patientId}/slots/{$slotKeyNew}")->set($apptId);

        $_SESSION['success_message'] = 'Your appointment request has been submitted. Your queue number is #'.$apponum.'.';
        header('Location: doctors.php'); exit();

    } catch (Throwable $e) {
        $_SESSION['error_message'] = 'Booking failed: ' . $e->getMessage();
        header('Location: doctors.php'); exit();
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

    <title>Doctors</title>

    <style>
        .menu-btn a { display:block; text-decoration:none; padding:1px; width:100%; }
        .menu-btn:hover { background-color:#E8F8E8; }

        /* ===== Doctor overlay modals ===== */
        .overlay{
          position: fixed; inset: 0;
          background: rgba(0,0,0,.45);
          z-index: 9990;
          display: flex; align-items: center; justify-content: center;
          padding: 24px;
        }
        .overlay .popup{
          background:#fff; width:100%; max-width:540px;
          border-radius:14px;
          box-shadow:0 12px 32px rgba(0,0,0,.25);
          padding:22px 24px;
          position:relative;
        }
        .overlay .close{
          position:absolute; top:10px; right:14px;
          font-size:24px; text-decoration:none; color:#555;
        }
        .overlay .close:hover{ color:#000; }

        /* =========================
           MOBILE HEADER (same behavior as dashboard)
           ========================= */
        .mobile-header{
            display:none;
            background: lightgreen;
            position: fixed;
            top: 0; left: 0; right: 0;
            z-index: 2000;
            box-sizing: border-box;
            height: 52px;
            padding: 10px 12px;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        #hamburger-menu{
            display:none;
            width:28px;
            height:22px;
            cursor:pointer;
            flex-direction: column;
            justify-content: space-between;
            align-items:center;
        }
        #hamburger-menu .bar{
            width:100%;
            height:3px;
            background:#333;
            border-radius:2px;
            transition: all .3s ease;
        }
        #hamburger-menu.active .bar:nth-child(1){ transform: rotate(-45deg) translate(-5px, 5px); }
        #hamburger-menu.active .bar:nth-child(2){ opacity:0; }
        #hamburger-menu.active .bar:nth-child(3){ transform: rotate(45deg) translate(-5px, -5px); }

        .mh-left{ width:40px; display:flex; align-items:center; }
        .mh-center{ flex:1; font-weight:800; font-size:16px; color:#000; }
        .mh-right{ display:flex; align-items:center; gap:10px; }
        .mh-date{ display:flex; flex-direction:column; align-items:flex-end; line-height:1.1; }
        .mh-date-label{ font-size:12px; color: rgba(0,0,0,.75); }
        .mh-date-value{ font-size:14px; font-weight:800; white-space:nowrap; }

        .mh-calbtn{
            width:40px; height:40px;
            display:flex; align-items:center; justify-content:center;
            border: none;
            background: rgba(255,255,255,.35);
            border-radius: 10px;
            cursor: pointer;
        }
        .mh-calbtn img{ width:22px; height:22px; }

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

            /* slide menu */
            .menu{
                display:none;
                position:fixed;
                left:-250px;
                top:52px;
                width:250px;
                height:calc(100% - 52px);
                background:lightgreen;
                transition:left .3s ease;
                z-index:1900;
            }
            .menu.active{ display:block; left:0; }

            /* push body below header */
            .dash-body{
                margin-top: 70px !important;
                margin-left: 0 !important;
                padding: 0 10px;
                transition: margin-left .3s ease;
            }
            .menu.active + .dash-body{ margin-left:250px !important; }

            /* hide desktop header row (Back/Search/Date row) on mobile */
            .dash-body > table > tbody > tr:first-child{
                display:none !important;
            }
        }

        /* =========================
   MOBILE FIX: DOCTORS TABLE TOO SMALL
   ========================= */
@media (max-width: 768px) {

  /* Let the wrapper use full width */
  .abc.scroll{
    width: 100% !important;
    padding: 0 !important;
  }

  /* Remove desktop centering behavior */
  .abc.scroll > table{
    margin: 0 !important;
  }

  /* Force table to fill screen */
  table.sub-table{
    width: 100% !important;
    min-width: 0 !important;
  }

  /* Reduce excessive side padding */
  .dash-body{
    padding-left: 10px !important;
    padding-right: 10px !important;
  }

  /* Make empty-state card full width */
  .sub-table td[colspan]{
    padding-left: 0 !important;
    padding-right: 0 !important;
  }
}

/* =========================
   MOBILE: MAXIMIZE DOCTORS TABLE SPACE
   ========================= */
@media (max-width: 768px){

  /* Use full screen width */
  .container{
    width:100% !important;
    max-width:100% !important;
    margin:0 !important;
    padding:0 !important;
  }

  /* push content below fixed header + use padding */
  .dash-body{
    width:100% !important;
    max-width:100% !important;
    margin-top:70px !important;   /* below your 52px header */
    padding:10px 12px !important; /* nice side padding */
    box-sizing:border-box !important;
  }

  /* center tag can restrict sizing; neutralize it */
  .dash-body center{
    display:block !important;
    width:100% !important;
  }

  /* wrapper should be full width */
  .abc.scroll{
    width:100% !important;
    max-width:100% !important;
    margin:0 !important;
    padding:0 !important;
    overflow:visible !important;
  }

  /* FORCE the doctors table to full width (overrides width="93%") */
  .abc.scroll table.sub-table{
    width:100% !important;
    max-width:100% !important;
    min-width:0 !important;
    margin:0 !important;
  }

  /* Remove extra spacing that makes it look boxed */
  .sub-table{
    border-radius:12px;
  }

  /* Optional: make headers readable on small screens */
  .table-headin{
    font-size:14px !important;
    padding:12px 10px !important;
  }

  /* Optional: reduce cell padding so it fits better */
  .sub-table td{
    padding:12px 10px !important;
  }

  /* Make the View button not push layout */
  .btn-view{
    width:100% !important;
    padding-left:0 !important;
    justify-content:center !important;
  }
}

    </style>
</head>

<body>

<div class="mobile-header" id="mobileHeader">
    <div class="mh-left">
        <div id="hamburger-menu">
            <div class="bar"></div>
            <div class="bar"></div>
            <div class="bar"></div>
        </div>
    </div>

    <div class="mh-center">Doctors</div>

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
                            <td width="30%" style="padding-left:20px">
                                <img src="<?php echo htmlspecialchars($photo); ?>" alt="Profile Picture" width="100%" style="border-radius:50%">
                            </td>
                            <td style="padding:0px;margin:0px;">
                                <p class="profile-title"><?php echo substr($username, 0, 100); ?></p>
                                <p class="profile-subtitle"><?php echo substr($useremail, 0, 100); ?></p>
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
                    <a href="index.php" class="non-style-link-menu">
                        <p class="menu-text">Dashboard</p>
                    </a>
                </td>
            </tr>
            <tr class="menu-row">
                <td class="menu-btn menu-active">
                    <a href="doctors.php" class="non-style-link-menu non-style-link-menu-active">
                        <p class="menu-text">Service Appointments</p>
                    </a>
                </td>
            </tr>
            <tr class="menu-row">
                <td class="menu-btn">
                    <a href="appointment.php" class="non-style-link-menu">
                        <p class="menu-text">My Appointments</p>
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
        <table border="0" width="100%" style="border-spacing:0; margin:0; padding:0; margin-top:25px;">

            <!-- ✅ DESKTOP HEADER ROW (hidden on mobile by CSS) -->
            <tr>
                <td width="13%">
                    <a href="doctors.php">
                        <button class="login-btn btn-primary-soft btn btn-icon-back" style="padding-top:11px; padding-bottom:11px; margin-left:20px; width:125px">
                            <font class="tn-in-text">Back</font>
                        </button>
                    </a>
                </td>
                <td>
                    <form action="" method="GET" class="header-search">
                        <input type="search" name="search" class="input-text header-searchbar"
                               placeholder="Search Doctor name"
                               value="<?php echo htmlspecialchars($searchQuery); ?>" list="doctors">
                        &nbsp;&nbsp;
                        <datalist id="doctors">
                            <?php
                            $reference = $database->getReference('doctor');
                            $doctorData = $reference->getValue();
                            if (!empty($doctorData)) {
                                foreach ($doctorData as $doctor) {
                                    $docname = $doctor['name'] ?? '';
                                    if ($docname !== '') echo "<option value='".htmlspecialchars($docname)."'>";
                                }
                            }
                            ?>
                        </datalist>
                        <input type="submit" value="Search" class="login-btn btn-primary btn"
                               style="padding-left:25px; padding-right:25px; padding-top:10px; padding-bottom:10px;">
                    </form>
                </td>
                <td width="15%">
                    <p style="font-size:14px; color:rgb(119,119,119); margin:0; text-align:right;">Today's Date</p>
                    <p class="heading-sub12" style="margin:0; text-align:right;"><?php echo $today; ?></p>
                </td>
                <td width="10%">
                    <button class="btn-label" style="display:flex; justify-content:center; align-items:center;">
                        <img src="../img/calendar.svg" width="100%" alt="Calendar">
                    </button>
                </td>
            </tr>

            <!-- Doctors Count Row -->
            <tr>
                <td colspan="4" style="padding-top:10px; width:100%;">
                    <p class="heading-main12" style="margin-left:45px; font-size:18px; color:rgb(49,49,49)">
                        All Doctors (<?php
                            $doctorsRef = $database->getReference('doctor');
                            $doctors    = $doctorsRef->getValue();

                            $tz        = new DateTimeZone('Asia/Manila');
                            $todayObj  = new DateTime('today', $tz);
                            $endObj    = (clone $todayObj)->modify('+6 days');

                            $startDate = $todayObj->format('Y-m-d');
                            $endDate   = $endObj->format('Y-m-d');

                            $upcomingCount = 0;

                            if (is_array($doctors)) {
                                foreach ($doctors as $doc) {
                                    if (empty($doc['sessions']) || !is_array($doc['sessions'])) continue;

                                    $hasUpcomingSession = false;
                                    foreach ($doc['sessions'] as $sess) {
                                        $sdRaw = $sess['scheduledate'] ?? ($sess['date'] ?? '');
                                        if (is_array($sdRaw)) $sdRaw = reset($sdRaw);
                                        $sd = trim((string)$sdRaw);
                                        if ($sd === '') continue;

                                        $canon = canonDate($sd);
                                        if ($canon >= $startDate && $canon <= $endDate) {
                                            $hasUpcomingSession = true;
                                            break;
                                        }
                                    }
                                    if ($hasUpcomingSession) $upcomingCount++;
                                }
                            }
                            echo $upcomingCount;
                        ?>)
                    </p>
                </td>
            </tr>

            <!-- Doctors List Table -->
            <tr>
                <td colspan="4">
                    <center>
                        <div class="abc scroll">
                           <table class="sub-table scrolldown doctors-table" border="0">
                                <thead>
                                    <tr>
                                        <th class="table-headin">Doctor Name</th>
                                        <th class="table-headin">Specialties</th>
                                        <th class="table-headin">Events</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php
                                $doctorsRef = $database->getReference('doctor');
                                $result     = $doctorsRef->getValue();

                                $tz        = new DateTimeZone('Asia/Manila');
                                $todayObj  = new DateTime('today', $tz);
                                $endObj    = (clone $todayObj)->modify('+6 days');

                                $startDate = $todayObj->format('Y-m-d');
                                $endDate   = $endObj->format('Y-m-d');

                                if (empty($result)) {
                                    echo '<tr>
                                            <td colspan="3">
                                                <br><br><br><br>
                                                <center>
                                                    <img src="../img/notfound.svg" width="25%">
                                                    <br>
                                                    <p class="heading-main12" style="margin-left:45px; font-size:20px; color:rgb(49,49,49)">
                                                        We couldn\'t find anything related to your keywords!
                                                    </p>
                                                    <a class="non-style-link" href="doctors.php">
                                                        <button class="login-btn btn-primary-soft btn" style="display:flex; justify-content:center; align-items:center; margin-left:20px;">
                                                            &nbsp; Show all Doctors &nbsp;
                                                        </button>
                                                    </a>
                                                </center>
                                                <br><br><br><br>
                                            </td>
                                          </tr>';
                                } else {
                                    $doctorsList = [];

                                    foreach ($result as $docKey => $doctor) {
                                        if (empty($doctor['sessions']) || !is_array($doctor['sessions'])) continue;

                                        $hasUpcomingSession = false;
                                        foreach ($doctor['sessions'] as $sess) {
                                            $sdRaw = $sess['scheduledate'] ?? ($sess['date'] ?? '');
                                            if (is_array($sdRaw)) $sdRaw = reset($sdRaw);
                                            $sd = trim((string)$sdRaw);
                                            if ($sd === '') continue;

                                            $canon = canonDate($sd);
                                            if ($canon >= $startDate && $canon <= $endDate) {
                                                $hasUpcomingSession = true;
                                                break;
                                            }
                                        }
                                        if (!$hasUpcomingSession) continue;

                                        if ($searchQuery !== '') {
                                            $nm = $doctor['name'] ?? '';
                                            if (stripos($nm, $searchQuery) === false) continue;
                                        }

                                        $name        = $doctor['name'] ?? '';
                                        $specialtyID = $doctor['specialty'] ?? '';
                                        $sname       = $doctor['sname'] ?? '';

                                        $specialtyName = !empty($sname) ? $sname : "Unknown Specialty";

                                        if (!empty($specialtyID) && $specialtyID !== $sname) {
                                            $specialtiesRef = $database->getReference('specialties/' . $specialtyID);
                                            $specialtyData  = $specialtiesRef->getValue();
                                            if ($specialtyData && isset($specialtyData['sname'])) {
                                                $specialtyName = $specialtyData['sname'];
                                            }
                                        }

                                        $doctorsList[] = [
                                            'docKey'        => $docKey,
                                            'name'          => $name,
                                            'specialtyName' => $specialtyName
                                        ];
                                    }

                                    usort($doctorsList, function($a, $b) {
                                        return strcmp($a['specialtyName'], $b['specialtyName']);
                                    });

                                    if (empty($doctorsList)) {
                                        echo '<tr>
                                                <td colspan="3">
                                                    <br><br><br><br>
                                                    <center>
                                                        <img src="../img/notfound.svg" width="25%">
                                                        <br>
                                                        <p class="heading-main12" style="margin-left:45px; font-size:20px; color:rgb(49,49,49)">
                                                            No doctors found for this week matching the query!
                                                        </p>
                                                        <a class="non-style-link" href="doctors.php">
                                                            <button class="login-btn btn-primary-soft btn" style="display:flex; justify-content:center; align-items:center; margin-left:20px;">
                                                                &nbsp; Show all Doctors &nbsp;
                                                            </button>
                                                        </a>
                                                    </center>
                                                    <br><br><br><br>
                                                </td>
                                              </tr>';
                                    } else {
                                        foreach ($doctorsList as $doctor) {
                                            echo '<tr style="text-align:center; vertical-align:middle;">
                                                    <td style="border-bottom: 1px solid #ddd;">' . substr(htmlspecialchars($doctor['name']), 0, 30) . '</td>
                                                    <td style="border-bottom: 1px solid #ddd;">' . substr(htmlspecialchars($doctor['specialtyName']), 0, 100) . '</td>
                                                    <td style="border-bottom: 1px solid #ddd;">
                                                        <div style="display:flex; justify-content:center;">
                                                            <a href="?action=view&id=' . urlencode($doctor['docKey']) . '" class="non-style-link">
                                                                <button class="btn-primary-soft btn button-icon btn-view" style="padding-left:40px; padding-top:12px; padding-bottom:12px; margin-top:10px;">
                                                                    <font class="tn-in-text">View</font>
                                                                </button>
                                                            </a>
                                                        </div>
                                                    </td>
                                                  </tr>';
                                        }
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

<?php if (!empty($_SESSION['success_message'])): ?>
  <div style="margin:10px 20px; padding:10px 14px; background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; border-radius:8px;">
    <?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
  </div>
<?php endif; ?>

<?php if (!empty($_SESSION['error_message'])): ?>
  <div style="margin:10px 20px; padding:10px 14px; background:#fef2f2; color:#991b1b; border:1px solid #fecaca; border-radius:8px;">
    <?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?>
  </div>
<?php endif; ?>

<!-- Modal Popup for actions -->
<?php
if (isset($_GET['action'])) {
    $action = $_GET['action'];

    $toStr = function($v){
        if (is_array($v)) return trim(implode(', ', array_map('strval', $v)));
        return trim((string)$v);
    };

    $specialtiesMap = [];
    if ($action === 'view' && isset($_GET['id'])) {
        try { $specialtiesMap = $database->getReference('specialties')->getValue() ?: []; }
        catch (Throwable $e) { $specialtiesMap = []; }
    }

    $resolveSpecialtyName = function(array $doctor) use ($specialtiesMap, $toStr) : string {
        $sname     = $toStr($doctor['sname']     ?? '');
        $specialty = $toStr($doctor['specialty'] ?? '');

        if ($sname !== '') return $sname;

        if ($specialty !== '' && isset($specialtiesMap[$specialty])) {
            $node = $specialtiesMap[$specialty];
            if (is_array($node)) {
                $mapped = $toStr($node['sname'] ?? '');
                if ($mapped !== '') return $mapped;
            }
        }

        if ($specialty !== '') {
            foreach ($specialtiesMap as $sid => $node) {
                if (!is_array($node)) continue;
                $label = $toStr($node['sname'] ?? '');
                if ($label !== '' && strcasecmp($label, $specialty) === 0) return $label;
            }
            return $specialty;
        }
        return 'Unknown Specialty';
    };

    if ($action === 'drop' && isset($_GET['id']) && isset($_GET['name'])) {
        $id      = $_GET['id'];
        $nameget = $_GET['name'];

        echo '
        <div id="popup1" class="overlay">
            <div class="popup">
                <a class="close" href="doctors.php" aria-label="Close">&times;</a>
                <h2 style="margin-top:0;">Are you sure?</h2>
                <div class="content" style="margin:10px 0 16px;">
                    You want to delete this record<br>(' . htmlspecialchars(mb_strimwidth($nameget, 0, 60, '…'), ENT_QUOTES, 'UTF-8') . ').
                </div>
                <div style="display:flex; gap:10px; justify-content:center;">
                    <a href="delete-doctor.php?id=' . urlencode($id) . '" class="non-style-link">
                        <button class="btn-primary btn" style="padding:10px 14px;">Yes</button>
                    </a>
                    <a href="doctors.php" class="non-style-link">
                        <button class="btn-primary btn" style="padding:10px 14px;">No</button>
                    </a>
                </div>
            </div>
        </div>';

    } elseif ($action === 'view' && isset($_GET['id'])) {
        $id = $_GET['id'];
        try { $doctor = $database->getReference('doctor/' . $id)->getValue(); }
        catch (Throwable $e) { $doctor = null; }

        if ($doctor) {
            $name  = $toStr($doctor['name']  ?? 'Unknown');
            $nic   = $toStr($doctor['nic']   ?? 'Unknown');
            $tele  = $toStr($doctor['tele']  ?? 'Unknown');
            $specLabel = $resolveSpecialtyName($doctor);

            $patientSlots = [];
            if (!empty($patientId)) {
                try { $patientSlots = $database->getReference("patients/{$patientId}/slots")->getValue() ?: []; }
                catch (Throwable $e) { $patientSlots = []; }
            }

            $scheduleRows = [];
            $now = new DateTime('now', new DateTimeZone('Asia/Manila'));

            if (!empty($doctor['sessions']) && is_array($doctor['sessions'])) {
                foreach ($doctor['sessions'] as $sessKey => $sess) {
                    $sd = $toStr($sess['scheduledate'] ?? ($sess['date'] ?? ''));
                    $stStart = $toStr($sess['start_time'] ?? ($sess['scheduletime'] ?? ($sess['time'] ?? '')));
                    $stEnd   = $toStr($sess['end_time']   ?? ($sess['scheduletime'] ?? ($sess['time'] ?? '')));
                    $ti = $toStr($sess['title'] ?? 'Consultation');

                    if ($sd === '' || $stStart === '') continue;
                    if ($stEnd === '') $stEnd = $stStart;

                    $canonSd    = canonDate($sd);
                    $canonStart = canonTime($stStart);
                    $canonEnd   = canonTime($stEnd);

                    $slotDT = DateTime::createFromFormat('Y-m-d H:i', $canonSd.' '.$canonStart, new DateTimeZone('Asia/Manila'));
                    if ($slotDT && $slotDT < $now) continue;

                    $bookings = (isset($sess['bookings']) && is_array($sess['bookings'])) ? $sess['bookings'] : [];
                    $joined   = count($bookings);
                    $capacity = isset($sess['capacity']) ? (int)$sess['capacity'] : 0;
                    $isFull   = ($capacity > 0 && $joined >= $capacity);

                    $slotKeyNew = canonSlotKeyRange($id, $canonSd, $canonStart, $canonEnd);
                    $slotKeyOld = canonSlotKey($id, $canonSd, $canonStart);
                    $userAlreadyBooked = !empty($patientSlots[$slotKeyNew]) || !empty($patientSlots[$slotKeyOld]);

                    $scheduleRows[] = [
                        'sessKey'  => $sessKey,
                        'date'     => $canonSd,
                        'start'    => $canonStart,
                        'end'      => $canonEnd,
                        'title'    => $ti,
                        'joined'   => $joined,
                        'capacity' => $capacity,
                        'isFull'   => $isFull,
                        'userHas'  => $userAlreadyBooked,
                    ];
                }

                usort($scheduleRows, function($a,$b){
                    if ($a['date'] === $b['date']) return strcmp($a['start'], $b['start']);
                    return strcmp($a['date'], $b['date']);
                });
            }

            echo '
            <div id="popup1" class="overlay">
                <div class="popup" style="max-width:720px;">
                    <a class="close" href="doctors.php" aria-label="Close">&times;</a>
                    <h2 style="margin-top:0;">Doctor Details</h2>
                    <div class="content" style="display:grid; row-gap:6px; margin-bottom:10px;">
                        <p><strong>Name:</strong> ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</p>
                        <p><strong>NIC:</strong> ' . htmlspecialchars($nic, ENT_QUOTES, 'UTF-8') . '</p>
                        <p><strong>Telephone:</strong> ' . htmlspecialchars($tele, ENT_QUOTES, 'UTF-8') . '</p>
                        <p><strong>Specialty:</strong> ' . htmlspecialchars($specLabel, ENT_QUOTES, 'UTF-8') . '</p>
                    </div>';

            if (!empty($scheduleRows)) {
                echo '
                    <h3 style="margin:12px 0 8px;">Available Schedule</h3>
                    <div style="overflow:auto; max-height:50vh;">
                    <table style="width:100%; border-collapse:collapse;">
                        <thead>
                            <tr>
                                <th style="text-align:left; border-bottom:1px solid #e5e7eb; padding:8px;">Title</th>
                                <th style="text-align:left; border-bottom:1px solid #e5e7eb; padding:8px;">Date</th>
                                <th style="text-align:left; border-bottom:1px solid #e5e7eb; padding:8px;">Time</th>
                                <th style="text-align:center; border-bottom:1px solid #e5e7eb; padding:8px;">Joined</th>
                                <th style="text-align:center; border-bottom:1px solid #e5e7eb; padding:8px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>';

                foreach ($scheduleRows as $row) {
                    $capText = $row['capacity'] > 0 ? ($row['joined'].' / '.$row['capacity']) : (string)$row['joined'];
                    $timeRange = $row['start'].' - '.$row['end'];

                    echo '
                            <tr>
                                <td style="border-bottom:1px solid #f3f4f6; padding:8px;">'.htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8').'</td>
                                <td style="border-bottom:1px solid #f3f4f6; padding:8px;">'.htmlspecialchars($row['date'], ENT_QUOTES, 'UTF-8').'</td>
                                <td style="border-bottom:1px solid #f3f4f6; padding:8px;">'.htmlspecialchars($timeRange, ENT_QUOTES, 'UTF-8').'</td>
                                <td style="border-bottom:1px solid #f3f4f6; padding:8px; text-align:center;">'.htmlspecialchars($capText, ENT_QUOTES, 'UTF-8').'</td>
                                <td style="border-bottom:1px solid #f3f4f6; padding:8px; text-align:center;">';

                    if ($row['userHas']) {
                        echo '<button class="btn" style="padding:8px 12px;" disabled>Already Booked</button>';
                    } elseif ($row['isFull']) {
                        echo '<button class="btn" style="padding:8px 12px;" disabled>Full</button>';
                    } else {
                        echo '
                                    <form method="POST" action="doctors.php" style="display:inline;">
                                        <input type="hidden" name="action" value="book_session">
                                        <input type="hidden" name="doctor_id" value="'.htmlspecialchars($id, ENT_QUOTES, 'UTF-8').'">
                                        <input type="hidden" name="session_key" value="'.htmlspecialchars($row['sessKey'], ENT_QUOTES, 'UTF-8').'">
                                        <input type="hidden" name="schedule_date" value="'.htmlspecialchars($row['date'], ENT_QUOTES, 'UTF-8').'">
                                        <input type="hidden" name="schedule_start" value="'.htmlspecialchars($row['start'], ENT_QUOTES, 'UTF-8').'">
                                        <input type="hidden" name="schedule_end" value="'.htmlspecialchars($row['end'], ENT_QUOTES, 'UTF-8').'">
                                        <input type="hidden" name="schedule_title" value="'.htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8').'">
                                        <button type="submit" class="btn-primary btn" style="padding:8px 12px;">Book</button>
                                    </form>';
                    }

                    echo '          </td>
                            </tr>';
                }

                echo '      </tbody></table></div>';
            } else {
                echo '<p style="margin-top:8px; color:#6b7280;">No upcoming schedules available for booking.</p>';
            }

            echo '
                    <div style="display:flex; justify-content:center; margin-top:14px;">
                        <a href="doctors.php"><button class="btn-primary btn">OK</button></a>
                    </div>
                </div>
            </div>';
        } else {
            echo '<p>Doctor not found.</p>';
        }
    }
}
?>

<script>
    const hamburgerMenu = document.getElementById('hamburger-menu');
    const menu = document.querySelector('.menu');
    const dashBody = document.querySelector('.dash-body');

    if (hamburgerMenu) {
        hamburgerMenu.addEventListener('click', () => {
            hamburgerMenu.classList.toggle('active');
            menu.classList.toggle('active');
            dashBody.classList.toggle('active');
        });
    }
</script>

</body>
</html>
