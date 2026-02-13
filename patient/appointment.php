<?php
session_name('sess_patient');
session_start();

if (empty($_SESSION["user"]) || ($_SESSION['usertype'] ?? '') !== 'p') {
    header("location: ../login.php");
    exit();
}
$useremail = $_SESSION["user"];

// Import database
include("../connection.php");

date_default_timezone_set('Asia/Manila');
$today = date('Y-m-d');

/* =========================
   Canonical helpers (safe)
   ========================= */
function canonTime(string $t): string {
    $t = trim($t);
    if ($t === '') return '';
    $dt = DateTime::createFromFormat('H:i:s', $t); if ($dt) return $dt->format('H:i');
    $dt = DateTime::createFromFormat('H:i',   $t); if ($dt) return $dt->format('H:i');
    $dt = DateTime::createFromFormat('g:i A', strtoupper($t)); if ($dt) return $dt->format('H:i');
    $dt = new DateTime($t);
    return $dt->format('H:i');
}

// Fetch patient data (single query)
$patientRef      = $database->getReference('patients')->orderByChild('email')->equalTo($useremail);
$patientSnapshot = $patientRef->getSnapshot();
$patientData     = $patientSnapshot->getValue();

if (!$patientData) {
    echo "No patient data found.";
    exit();
}

$userfetch = array_values($patientData)[0];
$userid    = $userfetch["type"] ?? '';
$username  = trim(($userfetch["fname"] ?? '').' '.($userfetch["lname"] ?? ''));
if ($username === '') $username = $userfetch["name"] ?? 'Patient';

// Photo
$photo = '../img/user.png';
$firstPatient = reset($patientData);
if (!empty($firstPatient['photo'])) {
    $photo = $firstPatient['photo'];
}

$patientKey = array_key_first($patientData);

// === Fetch ONLY this user's appointments ===
$myAppointments = [];
try {
    $snap = $database->getReference('appointments')
        ->orderByChild('patientEmail')
        ->equalTo($useremail)
        ->getSnapshot();

    $myAppointments = $snap->getValue() ?: [];

} catch (\Kreait\Firebase\Exception\Database\UnsupportedQuery $e) {
    // Fallback if index missing: use the patient's appointment index
    $index = $database->getReference("patients/{$patientKey}/appointments")->getValue() ?: [];
    foreach ($index as $apptId => $trueVal) {
        $a = $database->getReference("appointments/{$apptId}")->getValue();
        if ($a) $myAppointments[$apptId] = $a;
    }
}

// Optional: date filter (use 'date' now)
if (!empty($_POST['sheduledate'])) {
    $filterDate    = trim($_POST['sheduledate']);
    $myAppointments = array_filter($myAppointments, function($a) use ($filterDate){
        $d = $a['date'] ?? ($a['scheduledate'] ?? '');
        return $d === $filterDate;
    });
}

// Normalize & sort
$apptRows = [];
foreach ($myAppointments as $appoid => $a) {
    // Support both schemas
    $sd    = $a['date'] ?? ($a['scheduledate'] ?? '');
    $stRaw = $a['start_time'] ?? ($a['time'] ?? ($a['scheduletime'] ?? ''));
    $enRaw = $a['end_time']   ?? '';

    $st = canonTime((string)$stRaw);
    $en = canonTime((string)$enRaw);
    if ($en === '') $en = $st;

    $title = $a['title']  ?? 'Consultation';
    $stat  = strtolower($a['status'] ?? 'pending');

    // doctor name: use provided or fetch by doctorId
    $docName = $a['doctorname'] ?? ($a['doctor_name'] ?? '');
    $docId   = $a['doctorId']   ?? ($a['docid'] ?? '');
    if ($docName === '' && $docId !== '') {
        try {
            $doc = $database->getReference('doctor/'.$docId)->getValue();
            if ($doc && !empty($doc['name'])) $docName = $doc['name'];
        } catch (\Throwable $e) { /* ignore */ }
    }
    if ($docName === '') $docName = 'Unknown Doctor';

    // compute timestamp for sorting (use start time)
    $dt = ($sd && $st)
        ? DateTime::createFromFormat('Y-m-d H:i', "$sd $st", new DateTimeZone('Asia/Manila'))
        : false;
    $ts = $dt ? $dt->getTimestamp() : 0;

    // augment data so renderer can use consistent keys
    $a['scheduledate'] = $sd;
    $a['scheduletime'] = $st;        // keep old key as START time
    $a['start_time']   = $st;
    $a['end_time']     = $en;
    $a['doctorname']   = $docName;
    $a['title']        = $title;
    $a['status']       = $stat;

    $apptRows[] = ['key' => $appoid, 'data' => $a, 'ts' => $ts];
}

usort($apptRows, fn($a,$b) => $a['ts'] <=> $b['ts']);
$myApptCount = count($apptRows);
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

    <title>Appointments</title>
<style>
/* =========================
   BASE ANIMATIONS
   ========================= */
.popup,
.sub-table{
  animation: transitionIn-Y-bottom 0.5s;
}

/* =========================
   MOBILE HEADER (BASE)
   ========================= */
.mobile-header{
  display:none;
  background-color:lightgreen;
  color:#000;
  padding:15px;
  text-align:center;
  font-size:18px;
  position:fixed;
  top:0;
  width:100%;
  z-index:10050;
}

/* =========================
   POPUP / OVERLAY
   ========================= */
.popup .close{
  font-size:30px;
  color:#000;
  text-decoration:none;
}

.overlay{
  display:none;
  position:fixed;
  left:0; top:0;
  width:100%; height:100%;
  background:rgba(0,0,0,0.7);
  z-index:1000;
}

.overlay .popup{
  position:absolute;
  top:50%; left:50%;
  transform:translate(-50%,-50%);
  background:#fff;
  padding:20px;
  border-radius:8px;
}

/* =========================
   HAMBURGER MENU (BASE)
   ========================= */
#hamburger-menu{
  display:none;
  width:30px;
  height:30px;
  position:fixed;
  top:15px;
  left:15px;
  z-index:1003;
  cursor:pointer;
  flex-direction:column;
  justify-content:space-between;
  align-items:center;
}

#hamburger-menu .bar{
  width:100%;
  height:3px;
  background:#333;
  transition:all .4s ease;
}

#hamburger-menu.active .bar:nth-child(1){
  transform:rotate(-45deg) translate(-5px,5px);
}
#hamburger-menu.active .bar:nth-child(2){
  opacity:0;
}
#hamburger-menu.active .bar:nth-child(3){
  transform:rotate(45deg) translate(-5px,-5px);
}

/* =========================
   MODAL
   ========================= */
.modal{
  display:none;
  position:fixed;
  z-index:1;
  left:0; top:0;
  width:100%; height:100%;
  background:rgba(0,0,0,0.4);
}

.modal-content{
  background:#fff;
  margin:15% auto;
  padding:20px;
  border:1px solid #888;
  width:40%;
  text-align:center;
  border-radius:8px;
}

.modal-buttons{
  display:flex;
  justify-content:space-evenly;
  margin-top:20px;
}

.btn-confirm,
.btn-cancel{
  padding:10px 20px;
  border:none;
  border-radius:5px;
  cursor:pointer;
}

.btn-confirm{background:#28a745;color:#fff;}
.btn-cancel{background:#dc3545;color:#fff;}

/* =========================
   MENU BUTTON
   ========================= */
.menu-btn a{
  display:block;
  text-decoration:none;
  padding:1px;
  width:100%;
}

.menu-btn:hover{
  background:#E8F8E8;
}

/* =========================
   MOBILE RESPONSIVE
   ========================= */
@media (max-width:768px){

  /* HEADER */
  .mobile-header{
    display:flex !important;
    height:56px;
    align-items:center;
    justify-content:space-between;
    padding:0 12px;
    font-weight:700;
  }

  .mobile-left,
  .mobile-right{
    display:flex;
    align-items:center;
    gap:10px;
  }

  .mobile-center{
    flex:1;
    text-align:center;
    font-size:16px;
  }

  .mobile-date,
  .mh-date-label,
  .mh-date-value{
    font-size:12px !important;
    font-weight:600;
  }

  .mobile-calendar-btn{
    width:34px;
    height:34px;
    border-radius:10px;
    border:none;
    background:rgba(255,255,255,0);
    display:flex;
    align-items:center;
    justify-content:center;
  }

  .mobile-calendar-btn img{
    width:18px;
    height:18px;
  }

  /* HAMBURGER */
  #hamburger-menu{
    display:flex !important;
    width:34px !important;
    height:34px !important;
    padding:8px !important;
    background:rgba(255,255,255,.48) !important;
    border-radius:10px !important;
    justify-content:center;
    gap:5px;
  }

  #hamburger-menu .bar{
    height:2px !important;
    background:#111 !important;
    border-radius:2px;
    transition:.25s;
  }

  #hamburger-menu.active .bar:nth-child(1){
    transform:rotate(-45deg) translate(-4px,5px);
  }
  #hamburger-menu.active .bar:nth-child(2){
    opacity:0;
  }
  #hamburger-menu.active .bar:nth-child(3){
    transform:rotate(45deg) translate(-4px,-5px);
  }

  /* SIDEBAR */
  .menu{
    display:none;
    position:fixed;
    left:-250px;
    top:56px;
    width:250px;
    height:100%;
    background:lightgreen;
    transition:left .3s ease;
    z-index:1001;
  }

  .menu.active{
    display:block;
    left:0;
  }

  /* CONTENT */
  .dash-body{
    margin-top:70px !important;
    padding:10px 12px !important;
    width:100% !important;
    height:calc(100vh - 70px) !important;
    overflow-y:auto !important;
    -webkit-overflow-scrolling:touch;
  }

  /* TABLE → CARDS */
  table.appt-table,
  table.appt-table tbody,
  table.appt-table tr,
  table.appt-table td{
    display:block !important;
    width:100% !important;
  }

  table.appt-table td{
    padding:0 !important;
  }

  .dashboard-items.search-items{
    width:100% !important;
    max-width:520px;
    margin:12px auto !important;
  }

  /* HIDE DESKTOP HEADER */
  .dash-body > table > tbody > tr:first-child{
    display:none !important;
  }

  /* SCROLL CONTROL */
  html, body{
    height:100%;
    overflow:hidden !important;
  }

  /* SIDEBAR OVERLAY FIX */
  .menu.active + .dash-body{
    margin-left:0 !important;
  }

  html, body{
    overflow-x:hidden !important;
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

    <div class="mh-center">My Appointments</div>

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
                                <p class="profile-title"><?php echo substr($username,0,100) ?></p>
                                <p class="profile-subtitle"><?php echo substr($useremail,0,100) ?></p>
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
            <tr class="menu-row">
                <td class="menu-btn">
                    <a href="doctors.php" class="non-style-link-menu"><p class="menu-text">Service Appointments</p></a>
                </td>
            </tr>
            <tr class="menu-row">
                <td class="menu-btn menu-active">
                    <a href="appointment.php" class="non-style-link-menu non-style-link-menu-active"><p class="menu-text">My Appointments</p></a>
                </td>
            </tr>
            <tr class="menu-row">
                <td class="menu-btn">
                    <a href="settings.php" class="non-style-link-menu"><p class="menu-text">Settings</p></a>
                </td>
            </tr>
        </table>
    </div>

    <div class="dash-body">
        <table border="0" width="100%" style="border-spacing:0;margin:0;padding:0;margin-top:25px;">
            <tr>
                <td width="13%">
                    <a href="appointment.php">
                        <button class="login-btn btn-primary-soft btn btn-icon-back" style="padding-top:11px;padding-bottom:11px;margin-left:20px;width:125px">
                            <font class="tn-in-text">Back</font>
                        </button>
                    </a>
                </td>
                <td>
                    <p style="font-size:23px;padding-left:12px;font-weight:600;">My Appointments</p>
                </td>
                <td width="15%">
                    <p style="font-size:14px;color:rgb(119,119,119);padding:0;margin:0;text-align:right;">Today's Date</p>
                    <p class="heading-sub12" style="padding:0;margin:0;">
                        <?php echo date('Y-m-d'); ?>
                    </p>
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
                        My Bookings (<?php echo (int)$myApptCount; ?>)
                    </p>
                </td>
            </tr>

            <tr>
                <td colspan="4">
                    <center>
                        <div class="abc scroll">
                            <table width="93%" class="sub-table scrolldown appt-table" border="0" style="border:none">

                                <tbody>
                                <?php
                                if (empty($apptRows)) {
                                    echo '
                                    <tr>
                                        <td colspan="7">
                                            <br><br><br><br>
                                            <center>
                                                <img src="../img/notfound.svg" width="25%"><br>
                                                <p class="heading-main12" style="margin-left:45px;font-size:20px;color:rgb(49,49,49)">
                                                    You have no bookings!
                                                </p>
                                                <a class="non-style-link" href="doctors.php">
                                                    <button class="login-btn btn-primary-soft btn" style="display:flex;justify-content:center;align-items:center;margin-left:20px;">
                                                        &nbsp; Book an Appointment &nbsp;
                                                    </button>
                                                </a>
                                            </center>
                                            <br><br><br><br>
                                        </td>
                                    </tr>';
                                } else {
                                    $now = time();
                                    $i = 0;
                                    echo '<tr>';

                                    foreach ($apptRows as $row) {
                                        $appoid = $row['key'];
                                        $a      = $row['data'];

                                        $title        = $a['title']        ?? 'Consultation';
                                        $docname      = $a['doctorname']   ?? 'Unknown Doctor';
                                        $scheduledate = $a['scheduledate'] ?? '';
                                        $startTime    = $a['start_time']   ?? ($a['scheduletime'] ?? '');
                                        $endTime      = $a['end_time']     ?? $startTime;
                                        $apponum      = $a['apponum']      ?? '';
                                        $appodate     = $a['createdAt']    ?? ($a['appodate'] ?? '');
                                        $status       = strtolower($a['status'] ?? 'pending');
                                        // Hide cancelled bookings from list
                                        if (in_array($status, ['cancelled','canceled'], true)) {
                                            continue;
                                        }


                                        // build start timestamp
                                        $tsObj = ($scheduledate && $startTime)
                                            ? DateTime::createFromFormat('Y-m-d H:i', "$scheduledate $startTime", new DateTimeZone('Asia/Manila'))
                                            : false;
                                        $isFuture = $tsObj ? ($tsObj->getTimestamp() >= $now) : false;

                                        // Status badge color
                                        $badgeColor = '#f59e0b'; // pending
                                        if ($status === 'confirmed') $badgeColor = '#10b981';
                                        if ($status === 'cancelled' || $status === 'canceled') $badgeColor = '#ef4444';

                                        echo '<td style="width:25%;">';
                                        echo '  <div class="dashboard-items search-items">';
                                        echo '      <div style="width:100%;">';

                                        echo '          <div class="h3-search" style="display:flex;justify-content:space-between;align-items:center;">';
                                        echo '              <span>Booking Date: ' . htmlspecialchars(substr($appodate, 0, 30)) . '</span>';
                                        echo '              <span style="padding:2px 8px;border-radius:999px;background:' . $badgeColor . ';color:#fff;font-size:12px;text-transform:capitalize;">' . htmlspecialchars($status) . '</span>';
                                        echo '          </div>';

                                        echo '          <div class="h3-search" style="margin-top:2px;">Ref: OC-000-' . htmlspecialchars($appoid) . '</div>';
                                        echo '          <div class="h1-search" style="margin-top:6px;">' . htmlspecialchars(substr($title, 0, 40)) . '</div>';

                                        echo '          <div class="h3-search" style="margin-top:6px;">';
                                        echo '              Appointment Number: <div class="h1-search">0' . htmlspecialchars($apponum) . '</div>';
                                        echo '          </div>';

                                        echo '          <div class="h3-search" style="margin-top:4px;">' . htmlspecialchars(substr($docname, 0, 60)) . '</div>';

                                        echo '          <div class="h4-search" style="margin-top:4px;">';
                                        echo '              Scheduled Date: ' . htmlspecialchars($scheduledate) . '<br>';
                                        echo '              Time: <b>' . htmlspecialchars(substr($startTime, 0, 5)) . ' - ' . htmlspecialchars(substr($endTime, 0, 5)) . '</b>';
                                        echo '          </div>';

                                        // ✅ Show Cancel for future PENDING or CONFIRMED
                                        if ($isFuture && in_array($status, ['confirmed','pending'], true)) {
                                            echo '      <br>';
                                            echo '      <a href="#" class="non-style-link" onclick="showPopup(\'delete-appointment.php?id=' . urlencode($appoid) . '\');">';
                                            echo '          <button class="login-btn btn-primary-soft btn" style="padding-top:11px;padding-bottom:11px;width:100%">';
                                            echo '              <font class="tn-in-text">Cancel Booking</font>';
                                            echo '          </button>';
                                            echo '      </a>';
                                        }

                                        echo '      </div>';
                                        echo '  </div>';
                                        echo '</td>';

                                        $i++;
                                        if ($i % 4 === 0) echo '</tr><tr>';
                                    }
                                    echo '</tr>';
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

<!-- Modal Popup HTML -->
<div id="confirmationModal" class="modal">
    <div class="modal-content">
        <p>Are you sure you want to cancel this booking?</p>
        <div class="modal-buttons">
            <button id="confirmButton" class="btn-confirm">Yes, Cancel my booking</button>
            <button id="cancelButton" class="btn-cancel">No, I don't want to Cancel My booking</button>
        </div>
    </div>
</div>

<script>
function showPopup(deleteUrl) {
    const modal = document.getElementById("confirmationModal");
    modal.style.display = "block";

    document.getElementById("confirmButton").onclick = function () {
        window.location.href = deleteUrl;
    };
    document.getElementById("cancelButton").onclick = function () {
        modal.style.display = "none";
    };
}

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
