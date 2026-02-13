<?php
session_name('sess_doctor'); session_start();
if (!isset($_SESSION["user"]) || $_SESSION["user"] === "" || ($_SESSION['usertype'] ?? '') !== 'd') {
    header("location: ../login.php");
    exit();
}
$useremail = $_SESSION["user"];

// Import database connection
include("../connection.php");
date_default_timezone_set('Asia/Manila');

// ---- Load logged-in doctor by email, keep the Firebase key
$doctorSnap = $database->getReference('doctor')->orderByChild('email')->equalTo($useremail)->getSnapshot();
$doctorData = $doctorSnap->getValue();

if (!$doctorData) { echo "Error: Doctor not found."; exit(); }

$doctorKey     = array_key_first($doctorData);         // Firebase key for the doctor
$doctorDetails = $doctorData[$doctorKey];
$username      = $doctorDetails['name']  ?? '';
$photo         = !empty($doctorDetails['photo']) ? $doctorDetails['photo'] : '../img/user.png';

// Persist the Firebase key as the unique id for later queries
$_SESSION['userid'] = $doctorKey;

// ---- Preload all appointments (plural path) and index by sessionKey for quick filtering
$allAppointments = $database->getReference('appointments')->getValue() ?: [];

// ---- Build a map of scheduleId -> sessionKey(s) using the doctor’s sessions
$scheduleIdToSessionKeys = [];
if (!empty($doctorDetails['sessions']) && is_array($doctorDetails['sessions'])) {
    foreach ($doctorDetails['sessions'] as $sessionKey => $session) {
        $sid = $session['scheduleid'] ?? null;
        if ($sid) {
            $scheduleIdToSessionKeys[$sid] = $scheduleIdToSessionKeys[$sid] ?? [];
            $scheduleIdToSessionKeys[$sid][] = $sessionKey;
        }
    }
}

function countAppointmentsForSchedule($scheduleId, $scheduleIdToSessionKeys, $allAppointments) {
    $count = 0;
    if (!empty($scheduleIdToSessionKeys[$scheduleId])) {
        $keys = $scheduleIdToSessionKeys[$scheduleId];
        foreach ($allAppointments as $appt) {
            if (isset($appt['sessionKey']) && in_array($appt['sessionKey'], $keys, true)) {
                $count++;
            }
        }
    }
    return $count;
}

function filterAppointmentsForSchedule($scheduleId, $scheduleIdToSessionKeys, $allAppointments) {
    $out = [];
    if (!empty($scheduleIdToSessionKeys[$scheduleId])) {
        $keys = $scheduleIdToSessionKeys[$scheduleId];
        foreach ($allAppointments as $id => $appt) {
            if (isset($appt['sessionKey']) && in_array($appt['sessionKey'], $keys, true)) {
                $out[$id] = $appt;
            }
        }
    }
    return $out;
}

function buildScheduleTimeLabel(array $schedule): string {
    $start = trim((string)($schedule['start_time'] ?? ''));
    $end   = trim((string)($schedule['end_time']   ?? ''));
    $old   = trim((string)($schedule['scheduletime']       ?? ''));

    if ($start !== '' && $end !== '') return $start . ' - ' . $end;
    if ($start !== '') return $start;
    if ($end !== '') return $end;
    return $old !== '' ? $old : 'N/A';
}

$todayYmd = (new DateTime('today'))->format('Y-m-d');

// ---- Handle POST actions (queue control + search)
$searchKeyword = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['appt_action'])) {
        $actionType    = $_POST['appt_action'];
        $scheduleId    = trim($_POST['schedule_id'] ?? '');
        $appointmentId = trim($_POST['appointment_id'] ?? '');

        if ($actionType === 'set_consulting' && $appointmentId !== '') {
            $apptRef = $database->getReference('appointments/' . $appointmentId);
            $appt    = $apptRef->getValue();
            if ($appt && ($appt['doctorId'] ?? '') === $doctorKey) $apptRef->update(['status' => 'consulting']);
            if ($scheduleId !== '') { header('Location: schedule.php?action=view&id=' . urlencode($scheduleId)); exit(); }

        } elseif ($actionType === 'set_done' && $appointmentId !== '') {
            $apptRef = $database->getReference('appointments/' . $appointmentId);
            $appt    = $apptRef->getValue();
            if ($appt && ($appt['doctorId'] ?? '') === $doctorKey) $apptRef->update(['status' => 'done']);
            if ($scheduleId !== '') { header('Location: schedule.php?action=view&id=' . urlencode($scheduleId)); exit(); }

        } elseif ($actionType === 'set_no_show' && $appointmentId !== '') {
            $apptRef = $database->getReference('appointments/' . $appointmentId);
            $appt    = $apptRef->getValue();
            if ($appt && ($appt['doctorId'] ?? '') === $doctorKey) $apptRef->update(['status' => 'no_show']);
            if ($scheduleId !== '') { header('Location: schedule.php?action=view&id=' . urlencode($scheduleId)); exit(); }

        } elseif ($actionType === 'next_patient' && $scheduleId !== '') {
            $sessionKeys = $scheduleIdToSessionKeys[$scheduleId] ?? [];
            if (!empty($sessionKeys)) {
                $list = [];
                foreach ($allAppointments as $id => $appt) {
                    if (isset($appt['sessionKey']) && in_array($appt['sessionKey'], $sessionKeys, true)
                        && ($appt['doctorId'] ?? '') === $doctorKey) {
                        $list[] = [
                            'id'      => $id,
                            'apponum' => (int)($appt['apponum'] ?? 0),
                            'status'  => $appt['status'] ?? 'pending'
                        ];
                    }
                }

                if (!empty($list)) {
                    usort($list, fn($a,$b) => $a['apponum'] <=> $b['apponum']);

                    $currentIndex = null;
                    foreach ($list as $idx => $row) {
                        if ($row['status'] === 'consulting') { $currentIndex = $idx; break; }
                    }

                    if ($currentIndex !== null) {
                        $currentId = $list[$currentIndex]['id'];
                        $database->getReference('appointments/' . $currentId)->update(['status' => 'done']);
                    }

                    $nextId = null;
                    if ($currentIndex !== null) {
                        for ($i = $currentIndex + 1; $i < count($list); $i++) {
                            if ($list[$i]['status'] === 'pending') { $nextId = $list[$i]['id']; break; }
                        }
                    }
                    if ($nextId === null) {
                        foreach ($list as $row) {
                            if ($row['status'] === 'pending') { $nextId = $row['id']; break; }
                        }
                    }
                    if ($nextId !== null) $database->getReference('appointments/' . $nextId)->update(['status' => 'consulting']);
                }
            }
            header('Location: schedule.php?action=view&id=' . urlencode($scheduleId));
            exit();
        }
    } else {
        $searchKeyword = trim($_POST['search12'] ?? '');
    }
}

// ---- Fetch schedules for this doctor
$schedulesRef  = $database->getReference('schedule')->orderByChild('docid')->equalTo($doctorKey);
$schedulesData = $schedulesRef->getValue() ?: [];
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
    <title>Schedule</title>
    <style>
        .popup{ animation: transitionIn-Y-bottom 0.5s; }
        .sub-table{ animation: transitionIn-Y-bottom 0.5s; }
        .menu-btn a { display:block; text-decoration:none; padding:1px; width:100%; }
        .menu-btn:hover { background-color:#E8F8E8; }

        .btn-no-show {
            background:#fee2e2;
            border:1px solid #fecaca;
            color:#b91c1c;
        }
        .btn-primary-soft.btn-no-show:hover {
            background:#fecaca !important;
            border-color:#fca5a5 !important;
            color:#7f1d1d !important;
        }

        /* =========================
           MOBILE HEADER + DRAWER (Schedule)
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

          /* sidebar drawer */
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

          /* overlay */
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
            margin-top:70px !important;
            margin-left:0 !important;
            padding:12px !important;
            box-sizing:border-box !important;
          }

          /* hide desktop header row on mobile */
          .dash-body > table > tbody > tr:first-child{
            display:none !important;
          }

          html, body{ overflow-x:hidden !important; }

          /* search bar full width */
          .header-search{
            display:flex !important;
            flex-direction:column !important;
            gap:8px !important;
            width:100% !important;
          }
          .header-searchbar{
            width:100% !important;
            box-sizing:border-box !important;
          }

          /* make table scrollable */
          .abc.scroll{ overflow-x:auto !important; overflow-y:auto !important;-webkit-overflow-scrolling:touch; }
          .sub-table{ min-width:720px; }
        }

        /* ✅ overlay popup fit on mobile */
        @media (max-width: 768px){
          .overlay{ padding-top:70px !important; }
          .popup{
            width:92% !important;
            max-width:92% !important;
            margin:0 auto !important;
          }
          .popup .abc.scroll{ overflow-x:auto !important; }
          .popup table.sub-table{ min-width:720px; }
        }

        @media (max-width: 768px){
  .menu .profile-subtitle{
    font-size: 13px !important;
  }
}

    </style>
</head>
<body>

<?php $todayHeader = $todayYmd; ?>
<!-- ✅ MOBILE HEADER -->
<div class="mobile-header">
  <div class="mh-left">
    <div id="hamburger-menu" aria-label="Menu">
      <div class="bar"></div>
      <div class="bar"></div>
      <div class="bar"></div>
    </div>
  </div>

  <div class="mh-title">My Sessions</div>

  <div class="mh-right">
    <div class="mh-date">
      <div class="mh-date-label">Date</div>
      <div class="mh-date-value"><?php echo htmlspecialchars($todayHeader); ?></div>
    </div>
    <button class="mh-cal btn-label" type="button" aria-label="Calendar">
      <img src="../img/calendar.svg" alt="">
    </button>
  </div>
</div>

<!-- ✅ overlay -->
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
                                <p class="profile-title"><?php echo htmlspecialchars(substr($username,0,50)); ?></p>
                                <p class="profile-subtitle"><?php echo htmlspecialchars(substr($useremail,0,50)); ?></p>
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
                <td class="menu-btn"><a href="index.php" class="non-style-link-menu"><p class="menu-text">Dashboard</p></a></td>
            </tr>
            <tr class="menu-row">
                <td class="menu-btn menu-active"><a href="schedule.php" class="non-style-link-menu non-style-link-menu-active"><p class="menu-text">My Sessions</p></a></td>
            </tr>
            <tr class="menu-row">
                <td class="menu-btn "><a href="bed.php" class="non-style-link-menu "><p class="menu-text">Bed Occupancy</p></a></td>
            </tr>
            <tr class="menu-row">
                <td class="menu-btn "><a href="patient.php" class="non-style-link-menu"><p class="menu-text">Patient Health Records</p></a></td>
            </tr>
            <tr class="menu-row">
                <td class="menu-btn "><a href="summary.php" class="non-style-link-menu"><p class="menu-text">Summary</p></a></td>
            </tr>
            <tr class="menu-row">
                <td class="menu-btn "><a href="settings.php" class="non-style-link-menu"><p class="menu-text">Settings</p></a></td>
            </tr>
        </table>
    </div>

    <div class="dash-body">
        <table border="0" width="100%" style="border-spacing:0;margin:0;padding:0;margin-top:25px;">
            <tr>
                <td>
                    <form action="" method="post" class="header-search">
                        <input type="search" name="search12" class="input-text header-searchbar"
                               placeholder="Search Schedule Title or Date (YYYY-MM-DD)" list="schedule"
                               value="<?php echo htmlspecialchars($searchKeyword); ?>">&nbsp;&nbsp;
                        <input type="submit" value="Search" name="search"
                               class="login-btn btn-primary btn"
                               style="padding-left:25px;padding-right:25px;padding-top:10px;padding-bottom:10px;">
                    </form>
                </td>
                <td width="15%">
                    <p style="font-size:14px;color:rgb(119,119,119);padding:0;margin:0;text-align:right;">Today's Date</p>
                    <p class="heading-sub12" style="padding:0;margin:0;"><?php echo $todayYmd; ?></p>
                </td>
                <td width="10%">
                    <button class="btn-label" style="display:flex;justify-content:center;align-items:center;">
                        <img src="../img/calendar.svg" width="100%">
                    </button>
                </td>
            </tr>

            <tr>
                <td colspan="4" style="padding-top:10px;width:100%;">
                    <p class="heading-main12" style="margin-left:45px;font-size:18px;color:rgb(49,49,49)">
                        My Sessions
                        (<?php
                            $upcomingSessions = 0;
                            if (!empty($doctorDetails['sessions']) && is_array($doctorDetails['sessions'])) {
                                $today = new DateTime('today');
                                foreach ($doctorDetails['sessions'] as $s) {
                                    if (!empty($s['scheduledate'])) {
                                        $d = DateTime::createFromFormat('Y-m-d', $s['scheduledate']);
                                        if ($d && $d >= $today) { $upcomingSessions++; }
                                    }
                                }
                            }
                            echo (int)$upcomingSessions;
                        ?>)
                    </p>
                </td>
            </tr>

            <?php
            $filteredSchedules = [];
            if (!empty($schedulesData)) {
                foreach ($schedulesData as $scheduleId => $schedule) {
                    $title = $schedule['title'] ?? '';
                    $date  = $schedule['scheduledate'] ?? '';
                    if (!empty($date) && $date < $todayYmd) continue;

                    if ($searchKeyword !== '') {
                        if (stripos($title, $searchKeyword) === false && stripos($date, $searchKeyword) === false) continue;
                    }
                    $filteredSchedules[$scheduleId] = $schedule;
                }
            }
            ?>

            <tr>
                <td colspan="4">
                    <center>
                        <div class="abc scroll">
                            <table width="93%" class="sub-table scrolldown" border="0">
                                <thead>
                                <tr>
                                    <th class="table-headin">Session Title</th>
                                    <th class="table-headin">Scheduled Date &amp; Time</th>
                                    <th class="table-headin">Number of Patients Scheduled</th>
                                    <th class="table-headin">Actions</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php
                                if (empty($filteredSchedules)) {
                                    echo '<tr>
                                            <td colspan="4">
                                                <br><br><br><br>
                                                <center>
                                                    <img src="../img/notfound.svg" width="25%">
                                                    <br>
                                                    <p class="heading-main12" style="font-size:20px;color:rgb(49,49,49)">No schedules found!</p>
                                                    <a class="non-style-link" href="schedule.php">
                                                        <button class="login-btn btn-primary-soft btn" style="display:flex;justify-content:center;align-items:center;margin-left:20px;">&nbsp; Show all Sessions &nbsp;</button>
                                                    </a>
                                                </center>
                                                <br><br><br><br>
                                            </td>
                                        </tr>';
                                } else {
                                    foreach ($filteredSchedules as $scheduleId => $schedule) {
                                        $title        = $schedule['title']        ?? 'No Title';
                                        $scheduledate = $schedule['scheduledate'] ?? 'N/A';
                                        $timeLabel    = buildScheduleTimeLabel($schedule);

                                        $appointmentCount = countAppointmentsForSchedule($scheduleId, $scheduleIdToSessionKeys, $allAppointments);

                                        echo '<tr style="text-align:center; vertical-align:middle;">
                                                <td style="border-bottom:1px solid #ddd;">' . htmlspecialchars(substr($title, 0, 60)) . '</td>
                                                <td style="text-align:center;border-bottom:1px solid #ddd;">' . htmlspecialchars($scheduledate . ' ' . $timeLabel) . '</td>
                                                <td style="text-align:center;border-bottom:1px solid #ddd;">' . (int)$appointmentCount . '</td>
                                                <td>
                                                    <div style="display:flex;justify-content:center;border-bottom:1px solid #ddd;">
                                                        <a href="?action=view&id=' . htmlspecialchars($scheduleId) . '" class="non-style-link">
                                                            <button class="btn-primary-soft btn button-icon btn-view" style="padding-left:40px;padding-top:12px;padding-bottom:12px;margin-top:10px;">
                                                                <font class="tn-in-text">View</font>
                                                            </button>
                                                        </a>
                                                        &nbsp;&nbsp;&nbsp;
                                                    </div>
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
    </div>
</div>

<script>
  // ✅ ONE working hamburger script (overlay drawer; does not push content)
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
</script>

<?php
// ---------- Overlay actions ----------
if (!empty($_GET)) {
    $id     = $_GET["id"]     ?? '';
    $action = $_GET["action"] ?? '';

    if ($action === 'drop') {
        $nameget = $_GET["name"] ?? '';
        echo '
        <div id="popup1" class="overlay">
            <div class="popup">
                <center>
                    <h2>Are you sure?</h2>
                    <a class="close" href="schedule.php">&times;</a>
                    <div class="content">
                        You want to delete this record<br>(' . htmlspecialchars(substr($nameget, 0, 40)) . ').
                    </div>
                    <div style="display:flex;justify-content:center;">
                        <a href="delete-session.php?id=' . htmlspecialchars($id) . '" class="non-style-link">
                            <button class="btn-primary btn" style="padding:10px;margin:10px;">Yes</button>
                        </a>&nbsp;&nbsp;&nbsp;
                        <a href="schedule.php" class="non-style-link">
                            <button class="btn-primary btn" style="padding:10px;margin:10px;">No</button>
                        </a>
                    </div>
                </center>
            </div>
        </div>';
    } elseif ($action === 'view' && $id !== '') {
        $scheduleData = $database->getReference('schedule/' . $id)->getValue();
        if (!$scheduleData) { echo 'No schedule found with the given ID.'; exit; }

        $docid    = $scheduleData['docid'] ?? '';
        $docname  = 'Unknown Doctor';
        if ($docid) {
            $docData = $database->getReference('doctor/' . $docid)->getValue();
            if ($docData) { $docname = $docData['name'] ?? $docname; }
        }

        $title        = $scheduleData['title']        ?? 'No Title';
        $scheduledate = $scheduleData['scheduledate'] ?? 'N/A';
        $timeLabel    = buildScheduleTimeLabel($scheduleData);
        $nop          = $scheduleData['nop'] ?? ($scheduleData['totalPatients'] ?? 'N/A');

        $filteredAppointments = filterAppointmentsForSchedule($id, $scheduleIdToSessionKeys, $allAppointments);

        echo '
        <div id="popup1" class="overlay">
            <div class="popup" style="width:70%;">
                <center>
                    <h2>Session Details</h2>
                    <a class="close" href="schedule.php">&times;</a>
                    <div class="content"></div>

                    <div style="width:80%; text-align:right; margin-bottom:10px;">
                        <form method="post" action="schedule.php" style="display:inline-block;">
                            <input type="hidden" name="appt_action" value="next_patient">
                            <input type="hidden" name="schedule_id" value="' . htmlspecialchars($id) . '">
                            <button type="submit" class="btn-primary btn" style="padding:8px 16px;">Next Patient</button>
                        </form>
                    </div>

                    <div class="abc scroll" style="display:flex;justify-content:center;">
                        <table width="80%" class="sub-table scrolldown add-doc-form-container" border="0">
                            <tr><td><p style="font-size:25px;font-weight:500;text-align:left;">View Details</p><br><br></td></tr>
                            <tr><td class="label-td" colspan="2"><label class="form-label">Session Title: </label></td></tr>
                            <tr><td class="label-td" colspan="2">' . htmlspecialchars($title) . '<br><br></td></tr>
                            <tr><td class="label-td" colspan="2"><label class="form-label">Doctor of this session: </label></td></tr>
                            <tr><td class="label-td" colspan="2">' . htmlspecialchars($docname) . '<br><br></td></tr>
                            <tr><td class="label-td" colspan="2"><label class="form-label">Scheduled Date: </label></td></tr>
                            <tr><td class="label-td" colspan="2">' . htmlspecialchars($scheduledate) . '<br><br></td></tr>
                            <tr><td class="label-td" colspan="2"><label class="form-label">Scheduled Time: </label></td></tr>
                            <tr><td class="label-td" colspan="2">' . htmlspecialchars($timeLabel) . '<br><br></td></tr>
                            <tr>
                                <td class="label-td" colspan="2">
                                    <label class="form-label"><b>Patients Registered for this session:</b> (' . count($filteredAppointments) . ')</label><br><br>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="4">
                                    <center>
                                        <div class="abc scroll">
                                            <table width="100%" class="sub-table scrolldown" border="0">
                                                <thead>
                                                    <tr>
                                                        <th class="table-headin">Patient Name</th>
                                                        <th class="table-headin">Appointment Number</th>
                                                        <th class="table-headin">Patient Email</th>
                                                        <th class="table-headin">Status / Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>';
                                                if (empty($filteredAppointments)) {
                                                    echo '<tr>
                                                            <td colspan="4">
                                                                <br><br><br><br>
                                                                <center>
                                                                    <img src="../img/notfound.svg" width="25%">
                                                                    <br>
                                                                    <p class="heading-main12" style="font-size:20px;color:rgb(49,49,49)">No appointments found for this session!</p>
                                                                </center>
                                                                <br><br><br><br>
                                                            </td>
                                                        </tr>';
                                                } else {
                                                    foreach ($filteredAppointments as $apptId => $appointment) {
                                                        $apponum     = $appointment['apponum']      ?? '';
                                                        $pname       = $appointment['patientName']  ?? '';
                                                        $email       = $appointment['patientEmail'] ?? '';
                                                        $status      = $appointment['status']       ?? 'pending';

                                                        $statusLabel = ucfirst(str_replace('_', ' ', $status));

                                                        echo '<tr style="text-align:center;">
                                                                <td style="font-weight:600;padding:25px">' . htmlspecialchars($pname) . '</td>
                                                                <td style="text-align:center;font-size:23px;font-weight:500;">' . htmlspecialchars($apponum) . '</td>
                                                                <td>' . htmlspecialchars($email) . '</td>
                                                                <td>
                                                                    <div style="display:flex;flex-direction:column;gap:4px;align-items:center;">
                                                                        <span style="font-size:13px;margin-bottom:4px;">Status: <b>' . htmlspecialchars($statusLabel) . '</b></span>';

                                                        if ($status !== 'consulting' && $status !== 'done' && $status !== 'no_show') {
                                                            echo '
                                                            <form method="post" action="schedule.php" style="display:inline;">
                                                                <input type="hidden" name="appt_action" value="set_consulting">
                                                                <input type="hidden" name="appointment_id" value="' . htmlspecialchars($apptId) . '">
                                                                <input type="hidden" name="schedule_id" value="' . htmlspecialchars($id) . '">
                                                                <button type="submit" class="btn-primary btn" style="padding:4px 8px;margin-bottom:4px;">Currently Consulting</button>
                                                            </form>';
                                                        }

                                                        if ($status !== 'done') {
                                                            echo '
                                                            <form method="post" action="schedule.php" style="display:inline;">
                                                                <input type="hidden" name="appt_action" value="set_done">
                                                                <input type="hidden" name="appointment_id" value="' . htmlspecialchars($apptId) . '">
                                                                <input type="hidden" name="schedule_id" value="' . htmlspecialchars($id) . '">
                                                                <button type="submit" class="btn-primary-soft btn" style="padding:4px 8px;margin-top:2px;">Done</button>
                                                            </form>';
                                                        }

                                                        if ($status !== 'no_show') {
                                                            echo '
                                                            <form method="post" action="schedule.php" style="display:inline;">
                                                                <input type="hidden" name="appt_action" value="set_no_show">
                                                                <input type="hidden" name="appointment_id" value="' . htmlspecialchars($apptId) . '">
                                                                <input type="hidden" name="schedule_id" value="' . htmlspecialchars($id) . '">
                                                                <button type="submit" class="btn-primary-soft btn btn-no-show" style="padding:4px 8px;margin-top:2px;">No Show</button>
                                                            </form>';
                                                        }

                                                        echo '          </div>
                                                                </td>
                                                              </tr>';
                                                    }
                                                }
                                        echo '   </tbody>
                                            </table>
                                        </div>
                                    </center>
                                </td>
                            </tr>
                        </table>
                    </div>
                </center>
                <br><br>
            </div>
        </div>';
    }
}
?>
</body>
</html>
