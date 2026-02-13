<?php
session_name('sess_admin'); session_start();

// Check if the user is logged in and is an admin
if (isset($_SESSION["user"])) {
    if (($_SESSION["user"]) == "" || $_SESSION['usertype'] != 'a') {
        header("location: ../login.php"); exit;
    } else {
        $useremail = $_SESSION["user"];
    }
} else {
    header("location: ../login.php"); exit;
}

// import database
include("../connection.php");

date_default_timezone_set('Asia/Manila'); 
$todayHeader = date('Y-m-d');

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
// legacy single-time key (kept for old data)
function canonSlotKey(string $docId, string $date, string $time): string {
    return trim($docId) . '|' . canonDate($date) . '|' . canonTime($time);
}
// NEW range key
function canonSlotKeyRange(string $docId, string $date, string $start, string $end): string {
    return trim($docId) . '|' . canonDate($date) . '|' . canonTime($start) . '-' . canonTime($end);
}

// ---------------- Admin profile / specialty ----------------
try {
    $reference = $database
        ->getReference('admin')
        ->orderByChild('email')
        ->equalTo($useremail)
        ->getSnapshot();

    $userfetch = $reference->getValue();

    if ($userfetch) {
        foreach ($userfetch as $key => $value) {
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

// Retrieve admin data (photo) — SAFE + capture exact sname list
$photo = '../img/user.png';
$adminRef  = $database->getReference('admin')->orderByChild('email')->equalTo($useremail);
$adminRows = $adminRef->getValue() ?: [];

$adminRow = null;
if ($adminRows && is_array($adminRows)) {
    $adminRow = array_shift($adminRows);
    if (!empty($adminRow['name']))  { $username = $adminRow['name']; }
    if (!empty($adminRow['photo'])) { $photo    = $adminRow['photo']; }
}

// Build admin specialty labels (keep FULL labels; if single string, wrap to array)
$adminSnameRaw = $adminRow['sname'] ?? ($adminSpecialty ?? '');
$adminSpecLabels = is_array($adminSnameRaw) ? $adminSnameRaw : [$adminSnameRaw];


// --- NEW: specialties map and helpers used across the page ---
$specialtiesData = $database->getReference('specialties')->getValue() ?: [];
$specialtyMap = [];
foreach ($specialtiesData as $firebaseId => $spec) {
    if (is_array($spec) && isset($spec['sname'])) {
        $specialtyMap[(string)$firebaseId] = (string)$spec['sname'];
    }
}

/** Resolve a doctor's specialty label from either 'sname' or specialties/<id>.sname */
function resolveDocSpecLabel(array $doctor, array $specialtyMap): string {
    $sname = trim((string)($doctor['sname'] ?? ''));
    if ($sname !== '') return $sname;
    $sid = trim((string)($doctor['specialty'] ?? ''));
    if ($sid !== '' && isset($specialtyMap[$sid])) return (string)$specialtyMap[$sid];
    return '';
}

/** Normalize string for case/space-insensitive FULL-label compare (keeps full text) */
function norm_spec(string $s): string {
    $s = mb_strtolower(trim($s));
    $s = preg_replace('/\s+/', ' ', $s);
    return $s;
}

// Admin’s allowed specialty set (FULL labels)
$adminSpecSet = [];
foreach ($adminSpecLabels as $lbl) {
    if ($lbl !== '' && $lbl !== null) $adminSpecSet[] = norm_spec((string)$lbl);
}
// Information dept flag (match if "information" occurs anywhere in the label)
$isInfoDept = false;
foreach ($adminSpecSet as $lbl) {
    if (strpos($lbl, 'information') !== false) { $isInfoDept = true; break; }
}
// ---- Who can add sessions (restriction rules) ----
$restrictedSet = ['information','cashier','hmo','philhealth'];
$isRestricted = false;
foreach ($adminSpecSet as $lbl) {
    if (in_array($lbl, $restrictedSet, true)) { $isRestricted = true; break; }
    foreach ($restrictedSet as $needle) {
        if ($needle !== '' && strpos($lbl, $needle) !== false) { $isRestricted = true; break 2; }
    }
}
$canManageSessions = !$isRestricted;

// ----------------- SHARED FILTER INPUTS (search + date + docid) -----------------
$searchQuery  = trim($_POST['search']       ?? '');
$selectedDate = trim($_POST['scheduledate'] ?? ''); // FIX: use 'scheduledate'
if ($selectedDate !== '') {
    $selectedDate = canonDate($selectedDate);
}
$docidFilter  = trim($_POST['docid']        ?? '');

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
.overlay {
    position: fixed; top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0, 0, 0, 0.5); display: flex; justify-content: center; align-items: center; z-index: 1000;
}
.overlay-content {
    background: white; padding: 20px; border-radius: 5px; text-align: center; max-width: 400px; box-shadow: 0 4px 10px rgba(0,0,0,0.3);
}
#close-overlay { background-color: #0abf58; color: white; border: none; padding: 10px 20px; margin-top: 20px; cursor: pointer; border-radius: 5px; }
#close-overlay:hover { background-color: #16de69; }
.menu-btn a { display: block; text-decoration: none; padding: 1px; width: 100%;}
.menu-btn:hover { background-color: #E8F8E8; }

.btn-disabled {
    opacity: 0.55;
    cursor: not-allowed !important;
    filter: grayscale(0.25);
}

/* ===== MOBILE HEADER + DRAWER ===== */
.mobile-header{ display:none; }
#menu-overlay{ display:none; }

@media (max-width: 768px){

  /* show fixed header */
  .mobile-header{
    display:flex;
    position:fixed;
    top:0; left:0; right:0;
    height:56px;
    background: lightgreen;
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
    background: rgba(255, 255, 255, 0);
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

            /* hamburger inside header */
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
  #hamburger-menu.active .bar:nth-child(1){ transform: rotate(-45deg) translate(-4px, 5px); }
  #hamburger-menu.active .bar:nth-child(2){ opacity: 0; }
  #hamburger-menu.active .bar:nth-child(3){ transform: rotate(45deg) translate(-4px, -5px); }

  /* overlay */
  #menu-overlay{
    display:none;
    position:fixed;
    inset:0;
    background: rgba(0,0,0,0.45);
    z-index:1100;
  }
  body.menu-open #menu-overlay{ display:block; }

  /* drawer menu */
  .menu{
    position:fixed;
    top:56px;
    left:0;
    width:270px;
    height:calc(100vh - 56px);
    background: lightgreen;
    transform: translateX(-100%);
    transition: transform .25s ease;
    z-index:1150;
    overflow-y:auto;
    -webkit-overflow-scrolling: touch;
    display:block;
  }
  body.menu-open .menu{ transform: translateX(0); }
  body.menu-open{ overflow:hidden; }

  /* content full width */
  .dash-body{
    margin-top:66px !important;
    margin-left:0 !important;
    padding:12px !important;
    width:100% !important;
  }

  /* Hide desktop header row items: back + date block + calendar */
  .btn-icon-back,
  td[width="15%"],
  td[width="10%"],
  .btn-label{
    display:none !important;
  }

   /* Search/filter row (use space, not stacked) */
  .header-search{
    width:100% !important;
    display:flex !important;
    flex-direction:row !important;
    flex-wrap:wrap !important;
    gap:8px !important;
    padding:0 0 10px 0 !important;
    align-items:center !important;
  }

  .header-searchbar{
    flex: 1 1 220px !important;
    min-width: 180px !important;
    width:auto !important;
  }

  .header-search input[type="date"]{
    flex: 0 0 160px !important;
    width:160px !important;
    max-width:160px !important;
  }

  .header-search button{
    flex: 0 0 120px !important;
    width:120px !important;
    padding:10px 12px !important;
  }

  /* table container full width */
  .abc.scroll{ width:100% !important; }
  .sub-table{ width:100% !important; }

   /* Keep table as table + show headers */
  .abc.scroll{
    width:100% !important;
    overflow-x:auto !important;       /* horizontal scroll if needed */
    -webkit-overflow-scrolling: touch;
  }

  .sub-table{
    width:100% !important;
    min-width: 780px !important;      /* force table layout, scroll if screen is small */
    border-collapse: collapse;
  }

  .sub-table thead{
    display: table-header-group !important;
  }
  .sub-table tbody tr{
    display: table-row !important;
  }
  .sub-table tbody td{
    display: table-cell !important;
    text-align:left !important;
    padding:10px !important;
  }

  /* action buttons wrap nicely */
  .actions-row{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    justify-content:flex-start;
  }
}

/* Desktop reset (ensures cards don't leak) */
@media (min-width: 769px){
  .sub-table tbody tr{ display:table-row !important; }
  .sub-table tbody td{ display:table-cell !important; }
}

@media (max-width: 768px){
  .menu .profile-subtitle{
    font-size: 13px !important;
  }
}


</style>
</head>
<body>


<!-- Mobile Header -->
<div class="mobile-header">
  <div class="mobile-left">
    <div id="hamburger-menu" aria-label="Open menu" role="button" tabindex="0">
      <div class="bar"></div>
      <div class="bar"></div>
      <div class="bar"></div>
    </div>
  </div>

  <div class="mobile-center">Schedule</div>

  <div class="mobile-right">
    <!-- hide date if you want: just remove this line -->
    <div class="mobile-date"><?php echo $todayHeader; ?></div>

    <button class="mobile-calendar-btn" type="button" aria-label="Calendar">
      <img src="../img/calendar.svg" alt="">
    </button>
  </div>
</div>

<!-- overlay (tap outside to close) -->
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
                                    <p class="profile-title"><?php echo substr($username ?? '',0,50) ?></p>
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
                <table class="menu-container" border="0">
                    <tr class="menu-row">
                        <td class="menu-btn">
                            <a href="index.php" class="non-style-link-menu"><p class="menu-text">Dashboard</p></a>
                        </td>
                    </tr>
                    <tr class="menu-row">
                        <td class="menu-btn">
                            <a href="doctors.php" class="non-style-link-menu"><p class="menu-text">Doctors</p></a>
                        </td>
                    </tr>
                    <tr class="menu-row">
                        <td class="menu-btn menu-active">
                            <a href="schedule.php" class="non-style-link-menu non-style-link-menu-active"><p class="menu-text">Schedule</p></a>
                        </td>
                    </tr>
                    <tr class="menu-row">
                        <td class="menu-btn">
                            <a href="bed.php" class="non-style-link-menu"><p class="menu-text">Bed Occupancy</p></a>
                        </td>
                    </tr>
                    <tr class="menu-row">
                        <td class="menu-btn ">
                            <a href="queing.php" class="non-style-link-menu "><p class="menu-text">Queue</p></a>
                        </td>
                    </tr>
                    <tr class="menu-row">
                        <td class="menu-btn">
                            <a href="patient.php" class="non-style-link-menu"><p class="menu-text">Patients Health Record</p></a>
                        </td>
                    </tr>
                    <tr class="menu-row">
                        <td class="menu-btn">
                            <a href="archive.php" class="non-style-link-menu"><p class="menu-text">Archives</p></a>
                        </td>
                    </tr>
                    <tr class="menu-row">
                        <td class="menu-btn">
                            <a href="summary.php" class="non-style-link-menu"><p class="menu-text">Summary</p></a>
                        </td>
                    </tr>
                    <tr class="menu-row">
                        <td class="menu-btn ">
                            <a href="settings.php" class="non-style-link-menu"><p class="menu-text">Settings</p></a>
                        </td>
                    </tr>
                </table>
            </table>
        </div>

        <div class="dash-body">
            <table border="0" width="100%" style="border-spacing:0;margin:0;padding:0;margin-top:25px;">
                <tr>
                    <td width="13%">
                        <a href="schedule.php"><button class="login-btn btn-primary-soft btn btn-icon-back" style="padding-top:11px;padding-bottom:11px;margin-left:20px;width:125px"><font class="tn-in-text">Back</font></button></a>
                    </td>
                    <td>
                        <!-- SEARCH + DATE FILTER FORM -->
                        <form action="" method="post" class="header-search" style="display:flex;align-items:center;gap:8px;">
                            <input type="search"
                                   name="search"
                                   class="input-text header-searchbar"
                                   placeholder="Search Doctor name or Email"
                                   list="patient"
                                   value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>">

                            <!-- NEW: Date filter -->
                            <input type="date"
                                   name="scheduledate"
                                   class="input-text"
                                   style="max-width:170px;"
                                   value="<?php echo $selectedDate ? htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8') : ''; ?>">

                            <button type="submit" class="login-btn btn-primary btn" style="width: 20%;">Filter</button>

                            <?php
                            // Retrieve doctor data to populate datalist
                            $patientsReference = $database->getReference('doctor');
                            $patientsSnapshot  = $patientsReference->getSnapshot();
                            if (!$patientsSnapshot->exists()) {
                                // silent; optional: echo "No doctors found.";
                            } else {
                                $patientsData = $patientsSnapshot->getValue();
                                echo '<datalist id="patient">';
                                foreach ($patientsData as $patient) {
                                    $pname  = htmlspecialchars($patient['name']  ?? '');
                                    $pemail = htmlspecialchars($patient['email'] ?? '');
                                    if ($pname !== '')  echo "<option value='$pname'>";
                                    if ($pemail !== '') echo "<option value='$pemail'>";
                                }
                                echo '</datalist>';
                            }
                            ?>
                        </form>
                    </td>
                    <td width="15%">
                        <p style="font-size:14px;color:#777;padding:0;margin:0;text-align:right;">Today's Date</p>
                        <p class="heading-sub12" style="padding:0;margin:0;">
                        <?php
                            date_default_timezone_set('Asia/Manila');
                            $today = date('Y-m-d');
                            echo $today;
                        ?>
                        </p>
                    </td>
                    <td width="10%">
                        <button class="btn-label" style="display:flex;justify-content:center;align-items:center;"><img src="../img/calendar.svg" width="100%"></button>
                    </td>
                </tr>

                <tr>
                    <td colspan="2" style="padding-top:30px;">
                        <p class="heading-main12" style="margin-left:45px;font-size:20px;color:rgb(49,49,49)"></p>
                    </td>
                    <td colspan="2">
<div style="display:flex;margin-top:40px;align-items:center;margin-right:50px;">
  <?php if (!empty($canManageSessions) && $canManageSessions === true): ?>
      <a href="?action=add-session&id=none&error=0" class="non-style-link">
          <button class="login-btn btn-primary-soft btn button-icon"
                  style="margin-left:25px;background-image:url('../img/icons/add.svg');">
              Add a Session
          </button>
      </a>
  <?php else: ?>
      <button class="login-btn btn-primary-soft btn button-icon btn-disabled"
              style="margin-left:25px;background-image:url('../img/icons/add.svg');"
              title="Your department is not allowed to add sessions"
              disabled>
          Add a Session
      </button>
  <?php endif; ?>

  <!-- NEW: Open Queue Window button (always enabled) -->
  <button id="openQueueBtn"
          class="login-btn btn-primary btn"
          style="margin-left:12px;background-image:url('../img/icons/display.svg');">
    Queue Window
  </button>
</div>

                    </td>
                </tr>

                <tr>
                    <td colspan="4" style="padding-top:10px;width:100%;">
                        <p class="heading-main12" style="margin-left:45px;font-size:18px;color:rgb(49,49,49)">All Sessions
                        (<?php
$today = date('Y-m-d');
$nextMonthEnd = date('Y-m-t', strtotime('+1 month'));
$schedules = $database->getReference('schedule')->getValue() ?? [];
$doctors   = $database->getReference('doctor')->getValue()   ?? [];
$scheduleCount = 0;

foreach ($schedules as $schedule) {
    if (!isset($schedule['scheduledate'])) continue;
    $schedDate = canonDate($schedule['scheduledate']);

    // If a specific date is selected, only count that date
    if ($selectedDate !== '') {
        if ($schedDate !== $selectedDate) continue;
    } else {
        // Default: count from today up to next month end
        if ($schedDate < $today || $schedDate > $nextMonthEnd) continue;
    }

    $docId = $schedule['docid'] ?? '';
    $doc   = $doctors[$docId] ?? null;
    if (!$doc) continue;

    if ($isInfoDept) { $scheduleCount++; continue; }

    $docSpec = resolveDocSpecLabel($doc, $specialtyMap);
    if ($docSpec !== '' && in_array(norm_spec($docSpec), $adminSpecSet, true)) {
        $scheduleCount++;
    }
}
echo $scheduleCount;
                        ?>)
                        </p>
                    </td>
                </tr>

                <tr>
                    <td colspan="4">
                        <center>
                            <div class="abc scroll">
                                <table width="93%" class="sub-table scrolldown" border="0">
                                    <thead>
                                        <tr>
                                            <th class="table-headin">Session Title</th>
                                            <th class="table-headin">Doctor</th>
                                            <th class="table-headin">Specialty</th> <!-- NEW COLUMN -->
                                            <th class="table-headin">Scheduled Date</th>
                                            <th class="table-headin">Time</th>
                                            <th class="table-headin">Events</th>
                                        </tr>
                                    </thead>
                                    <tbody>
<?php
// ---------------- Session list (filtered by search/date/doc) ----------------
$scheduleReference = $database->getReference('schedule');
$doctorReference   = $database->getReference('doctor');

$schedules = $scheduleReference->getValue() ?? [];
$doctors   = $doctorReference->getValue()   ?? [];

$filteredResults = [];
$todayDate      = date('Y-m-d');
$filtersApplied = ($searchQuery !== '') || ($selectedDate !== '') || ($docidFilter !== '');

foreach ($schedules as $scheduleId => $schedule) {
    if (!isset($schedule['scheduledate'])) continue;
    $schedDate = canonDate($schedule['scheduledate']);
    if ($schedDate < $todayDate) continue;

    $docId = $schedule['docid'] ?? '';
    $doc   = $doctors[$docId] ?? null;
    if (!$doc) continue;

    // Resolve doctor specialty once
    $docSpec = resolveDocSpecLabel($doc, $specialtyMap);

    // Department rule
    if (!$isInfoDept) {
        if ($docSpec === '' || !in_array(norm_spec($docSpec), $adminSpecSet, true)) {
            continue; // not the same full sname as admin’s
        }
    }

    // User filters
    $matchesSearch = ($searchQuery === '') ||
        (stripos($doc['name']  ?? '', $searchQuery) !== false) ||
        (stripos($doc['email'] ?? '', $searchQuery) !== false);

    $matchesDate  = ($selectedDate === '') || ($schedDate === $selectedDate);
    $matchesDocId = ($docidFilter === '')  || ($docId === $docidFilter);

    if (!$filtersApplied || ($matchesSearch && $matchesDate && $matchesDocId)) {
        $schedule['name']      = $doc['name'] ?? 'Unknown';
        $schedule['specialty'] = $docSpec; // NEW: attach specialty/department
        $filteredResults[$scheduleId] = $schedule;
    }
}

uasort($filteredResults, function ($a, $b) {
    $da = isset($a['scheduledate']) ? canonDate($a['scheduledate']) : '';
    $db = isset($b['scheduledate']) ? canonDate($b['scheduledate']) : '';
    $cmp = strcmp($da, $db);
    if ($cmp !== 0) return $cmp;

    $ta = isset($a['start_time']) ? canonTime($a['start_time']) : (isset($a['scheduletime']) ? canonTime($a['scheduletime']) : '');
    $tb = isset($b['start_time']) ? canonTime($b['start_time']) : (isset($b['scheduletime']) ? canonTime($b['scheduletime']) : '');
    return strcmp($ta, $tb);
});

if (empty($filteredResults)) {
    echo '<tr><td colspan="6">
            <br><br><br><br>
            <center>
                <img src="../img/notfound.svg" width="25%"><br>
                <p class="heading-main12" style="margin-left:45px;font-size:20px;color:rgb(49,49,49)">No upcoming schedules available!</p>
            </center>
            <br><br><br><br>
          </td></tr>';
} else {
    foreach ($filteredResults as $scheduleId => $row) {
        $title        = htmlspecialchars($row["title"]        ?? 'Unknown');
        $docname      = htmlspecialchars($row["name"]         ?? 'Unknown');
        $docSpecOut   = htmlspecialchars($row["specialty"]    ?? 'Unknown'); // NEW
        $scheduledate = htmlspecialchars(canonDate($row["scheduledate"] ?? 'Unknown'));

        // NEW time range display with fallback
        $start_time_raw = $row["start_time"] ?? ($row["scheduletime"] ?? 'Unknown');
        $end_time_raw   = $row["end_time"]   ?? ($row["scheduletime"] ?? 'Unknown');
        $start_time = htmlspecialchars(substr(canonTime($start_time_raw), 0, 5));
        $end_time   = htmlspecialchars(substr(canonTime($end_time_raw),   0, 5));

       echo '<tr style="text-align:center;vertical-align:middle;">
  <td data-label="Session" style="border-bottom:1px solid #ddd;">&nbsp;' . substr($title, 0, 30) . '</td>
  <td data-label="Doctor" style="border-bottom:1px solid #ddd;">' . substr($docname, 0, 20) . '</td>
  <td data-label="Specialty" style="border-bottom:1px solid #ddd;">' . substr($docSpecOut, 0, 25) . '</td>
  <td data-label="Date" style="text-align:center;border-bottom:1px solid #ddd;">' . $scheduledate . '</td>
  <td data-label="Time" style="text-align:center;border-bottom:1px solid #ddd;">'  . $start_time . ' - ' . $end_time . '</td>
  <td data-label="Actions" style="border-bottom:1px solid #ddd;">
      <div class="actions-row">
        <a href="?action=view&id=' . urlencode($scheduleId) . '" class="non-style-link">
          <button class="btn-primary-soft btn button-icon btn-view" style="padding-left:40px;padding-top:12px;padding-bottom:12px;margin-top:10px;">
            <font class="tn-in-text">View</font>
          </button>
        </a>
        <a href="?action=drop&id=' . urlencode($scheduleId) . '" class="non-style-link">
          <button class="btn-primary-soft btn button-icon btn-delete" style="padding-left:40px;padding-top:12px;padding-bottom:12px;margin-top:10px;">
            <font class="tn-in-text">Remove</font>
          </button>
        </a>
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

<?php
// ===================== ACTION HANDLERS (add-session, added, drop, view) =====================
$action = "";
if (!empty($_GET['action'])) {
    $action = $_GET["action"];

    // Block add-session for restricted departments
    if ($action === 'add-session' && (empty($canManageSessions) || $canManageSessions !== true)) {
        echo '
        <div id="popup1" class="overlay">
            <div class="popup">
                <center>
                    <h2>Not Allowed</h2>
                    <a class="close" href="schedule.php">&times;</a>
                    <div class="content">Your department is not permitted to add sessions.</div>
                    <div style="display:flex;justify-content:center;">
                        <a href="schedule.php"><button class="btn-primary btn">OK</button></a>
                    </div>
                </center>
            </div>
        </div>';
        exit;
    }

    // ---------------- Add Session (popup + handler) ----------------
    if ($action == 'add-session') {
        echo '
        <div id="popup1" class="overlay">
            <div class="popup">
                <center>
                    <a class="close" href="schedule.php">&times;</a>
                    <div style="display:flex;justify-content:center;">
                        <div class="abc">
                            <table width="80%" class="sub-table scrolldown add-doc-form-container" border="0">
                                <tr><td><p style="padding:0;margin:0;text-align:left;font-size:25px;font-weight:500;">Add New Session.</p><br></td></tr>
                                <tr><td class="label-td" colspan="2">
                                    <form action="add-session.php" method="POST" class="add-new-form">
                                        <label for="title" class="form-label">Session Title : </label>
                                </td></tr>
                                <tr><td class="label-td" colspan="2">
                                    <input type="text" name="title" class="input-text" placeholder="Name of this Session" required><br>
                                </td></tr>
                                <tr><td class="label-td" colspan="2"><label for="docid" class="form-label" required>Select Doctor: </label></td></tr>
                                <tr><td class="label-td" colspan="2">
                                    <select name="docid" class="box">
                                        <option value="" disabled selected hidden>Choose Doctor Name from the list</option>';
        // === Filter doctors by the logged-in admin's specialty ===
        $doctors = $database->getReference('doctor')->getValue() ?: [];
        $specialtiesData = $database->getReference('specialties')->getValue() ?: [];

        // Build map id -> sname for specialty ids
        $specialtyMap = [];
        foreach ($specialtiesData as $firebaseId => $spec) {
            if (is_array($spec) && isset($spec['sname'])) {
                $specialtyMap[$firebaseId] = (string)$spec['sname'];
            }
        }

        $allDocs = $database->getReference('doctor')->getValue() ?: [];
        foreach ($allDocs as $docid => $doctor) {
            $docname = trim((string)($doctor['name'] ?? 'Unknown'));
            $docSpec = resolveDocSpecLabel($doctor, $specialtyMap); // FULL label
            $docSpecNorm = norm_spec($docSpec);

            if (!$isInfoDept) {
                if ($docSpec === '' || !in_array($docSpecNorm, $adminSpecSet, true)) continue;
            }

            $label = $docname . ($docSpec ? " — {$docSpec}" : '');
            echo "<option value='" . htmlspecialchars($docid, ENT_QUOTES, 'UTF-8') . "'>" .
                htmlspecialchars($label, ENT_QUOTES, 'UTF-8') .
                "</option>";
        }

        echo '                      </select><br><br>
                                </td></tr>
                                <tr><td class="label-td" colspan="2"><label for="date" class="form-label">Session Date: </label></td></tr>
                                <tr><td class="label-td" colspan="2">';
        $today = date("Y-m-d");
        $maxDate = date("Y-m-d", strtotime("+1 month"));
        echo '<input type="date" name="date" class="input-text" min="' . $today . '" max="' . $maxDate . '" required><br>
                                </td></tr>

                                <!-- NEW: Start Time -->
                                <tr><td class="label-td" colspan="2"><label for="start_time" class="form-label">Start Time: </label></td></tr>
                                <tr><td class="label-td" colspan="2">
                                    <select name="start_time" id="start_time" class="box" required>
                                        <option value="" disabled selected hidden>Select Start Time</option>';
        for ($hour = 7; $hour <= 19; $hour++) {
            foreach (['00', '30'] as $minute) {
                $time = sprintf('%02d:%s', $hour, $minute);
                echo "<option value='" . htmlspecialchars($time, ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars($time, ENT_QUOTES, 'UTF-8') . "</option>";
            }
        }
        echo '                          </select><br><br>
                                </td></tr>

                                <!-- NEW: End Time -->
                                <tr><td class="label-td" colspan="2"><label for="end_time" class="form-label">End Time: </label></td></tr>
                                <tr><td class="label-td" colspan="2">
                                    <select name="end_time" id="end_time" class="box" required>
                                        <option value="" disabled selected hidden>Select End Time</option>';
        for ($hour = 7; $hour <= 19; $hour++) {
            foreach (['00', '30'] as $minute) {
                $time = sprintf('%02d:%s', $hour, $minute);
                echo "<option value='" . htmlspecialchars($time, ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars($time, ENT_QUOTES, 'UTF-8') . "</option>";
            }
        }
        echo '                          </select><br><br>
                                </td></tr>

                                <tr><td colspan="2">
                                    <input type="reset" value="Reset" class="login-btn btn-primary-soft btn">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                                    <input type="submit" value="Place this Session" class="login-btn btn-primary btn" onclick="return confirmSave()" name="shedulesubmit">
                                </td></tr>
                                </form>
                            </table>
                        </div>
                    </div>
                </center><br><br>
            </div>
        </div>';

        // NOTE: Form posts to add-session.php.
    }

    // ---------------- Session Added toast ----------------
    if ($action == 'session-added') {
        $titleget = $_GET["title"] ?? '';
        echo '
         <div id="popup1" class="overlay">
             <div class="popup">
                 <center>
                     <br><br>
                     <h2>Session Placed.</h2>
                     <a class="close" href="schedule.php">&times;</a>
                     <div class="content">'. htmlspecialchars(substr($titleget, 0, 40)) .' was scheduled.<br><br></div>
                     <div style="display:flex;justify-content:center;">
                         <a href="schedule.php" class="non-style-link">
                             <button class="btn-primary btn" style="display:flex;justify-content:center;align-items:center;margin:10px;padding:10px;">
                                 <font class="tn-in-text">&nbsp;&nbsp;OK&nbsp;&nbsp;</font>
                             </button>
                         </a>
                         <br><br><br><br>
                     </div>
                 </center>
             </div>
         </div>';
    }

    // ---------------- Drop Session confirm (by id) ----------------
    if ($action == 'drop') {
        $scheduleIdParam = $_GET["id"] ?? '';
        $sessTitle = '';
        if ($scheduleIdParam !== '') {
            $snap = $database->getReference('schedule/' . $scheduleIdParam)->getSnapshot();
            if ($snap->exists()) {
                $sess = $snap->getValue();
                $sessTitle = $sess['title'] ?? '';
            }
        }
        echo '
         <div id="popup1" class="overlay">
             <div class="popup">
                 <center>
                     <h2>Are you sure?</h2>
                     <a class="close" href="schedule.php">&times;</a>
                     <div class="content">You want to delete this record<br>(' . htmlspecialchars(substr($sessTitle, 0, 40)) . ').</div>
                     <div style="display:flex;justify-content:center;">
                         <a href="delete-session.php?id=' . urlencode($scheduleIdParam) . '" class="non-style-link">
                             <button class="btn-primary btn" style="padding-left:40px;padding-top:12px;padding-bottom:12px;margin:10px;"><font class="tn-in-text">&nbsp;Yes&nbsp;</font></button>
                         </a>&nbsp;&nbsp;&nbsp;
                         <a href="schedule.php" class="non-style-link">
                             <button class="btn-primary btn" style="padding-left:40px;padding-top:12px;padding-bottom:12px;margin:10px;"><font class="tn-in-text">&nbsp;&nbsp;No&nbsp;&nbsp;</font></button>
                         </a>
                     </div>
                 </center>
             </div>
         </div>';
    }

    // ---------------- View Session (details + walk-in) ----------------
    if ($action == 'view') {
        // Prefer strong ID
        $scheduleIdParam = trim($_GET['id'] ?? '');
        $titleParam      = trim($_GET['title'] ?? ''); // legacy fallback

        // --- Handle Walk-in form POST (multi-book, no duplicate per user/slot) ---
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'book_session_walkin') {
            $docId   = trim($_POST['doctor_id']       ?? '');
            $sessKey = trim($_POST['session_key']     ?? '');
            $sDate   = trim($_POST['schedule_date']   ?? '');   // Y-m-d
            $sStart  = trim($_POST['schedule_start']  ?? ($_POST['schedule_time'] ?? '')); // NEW
            $sEnd    = trim($_POST['schedule_end']    ?? ($_POST['schedule_time'] ?? '')); // NEW
            $sTitle  = trim($_POST['schedule_title']  ?? 'Consultation');
            $wName   = trim($_POST['walkin_name']     ?? '');
            $wEmail  = trim($_POST['walkin_email']    ?? '');

            if (!$docId || !$sessKey || !$sDate || !$sStart || !$sEnd || !$wName || !$wEmail) {
                $walkInError = 'Please complete all fields for the walk-in patient.';
            } else {
                try {
                    // Ensure session path exists for parity with booking handler
                    $sessionPath = "doctor/{$docId}/sessions/{$sessKey}";
                    $sessionSnap = $database->getReference($sessionPath)->getSnapshot();
                    if (!$sessionSnap->exists()) {
                        $database->getReference($sessionPath)->set([
                            'title'      => $sTitle,
                            'date'       => canonDate($sDate),
                            'start_time' => canonTime($sStart),
                            'end_time'   => canonTime($sEnd),
                            // keep legacy time label if you want
                            'time'       => canonTime($sStart),
                            'createdAt'  => (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s'),
                        ]);
                    }

                    // Find or create patient by (email + name), prefer existing
                    $patientId    = null;
                    $patientName  = trim($wName);
                    $patientEmail = strtolower(trim($wEmail));

                    // 1) Primary: look up by email
                    $byEmail = $database->getReference('patients')
                        ->orderByChild('email')->equalTo($patientEmail)->getValue() ?? [];

                    if (!empty($byEmail) && is_array($byEmail)) {
                        $patientId = array_key_first($byEmail);

                        $current = $byEmail[$patientId] ?? [];
                        $storedName = trim((string)($current['name'] ?? ''));
                        if ($storedName === '' && $patientName !== '') {
                            $database->getReference("patients/{$patientId}/name")->set($patientName);
                        }
                    } else {
                        // 2) Secondary: scan for BOTH name+email together
                        $allPatients = $database->getReference('patients')->getValue() ?? [];
                        if (!empty($allPatients) && is_array($allPatients)) {
                            foreach ($allPatients as $k => $p) {
                                $pEmail = strtolower(trim((string)($p['email'] ?? '')));
                                $pName  = trim((string)($p['name']  ?? ''));
                                if ($pEmail === $patientEmail && $pName === $patientName) {
                                    $patientId = $k;
                                    break;
                                }
                            }
                        }

                        // 3) Still not found? Create a new node (WALK-IN)
                        if (!$patientId) {
                            $newRef = $database->getReference('patients')->push([
                                'name'         => $patientName,
                                'email'        => $patientEmail,
                                'date_created' => date('Y-m-d'),
                                'walkin'       => true
                            ]);
                            $patientId = $newRef->getKey();
                        }
                    }

                    if (!$patientId) {
                        throw new Exception('Unable to resolve patient ID.');
                    }

                    // Duplicate prevention per canonical slot (RANGE)
                    $slotKey = canonSlotKeyRange($docId, $sDate, $sStart, $sEnd);
                    $existingForUser = $database->getReference("patients/{$patientId}/slots/{$slotKey}")->getValue();
                    if (!empty($existingForUser)) {
                        $walkInError = 'This patient already has a booking for this schedule.';
                    } else {
                        // Atomic queue number per slot
                        $queueCounterPath = "doctor/{$docId}/queues/{$slotKey}/next";
                        try {
                            $txn = $database->getReference($queueCounterPath)->runTransaction(function ($current) {
                                $n = is_numeric($current) ? (int)$current : 0;
                                return $n + 1;
                            });
                            $apponum = (int)$txn->snapshot()->getValue();
                        } catch (\Throwable $e) {
                            $current = (int)($database->getReference($queueCounterPath)->getValue() ?? 0) + 1;
                            $database->getReference($queueCounterPath)->set($current);
                            $apponum = $current;
                        }

                        // Decide status based on department:
// - Information Dept: pending (needs secretary confirmation)
// - Others: confirmed immediately
$apptStatus = $isInfoDept ? 'pending' : 'confirmed';

// Create appointment (walk-in)
$tsNow = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
$appointment = [
    'doctorId'        => $docId,
    'patientId'       => $patientId,
    'patientName'     => $patientName,
    'patientEmail'    => $patientEmail,
    'date'            => canonDate($sDate),
    'start_time'      => canonTime($sStart),
    'end_time'        => canonTime($sEnd),
    // legacy field
    'time'            => canonTime($sStart),
    'title'           => $sTitle,

    // NEW: dynamic status
    'status'          => $apptStatus,

    // NEW: metadata to help secretary filter
    'needsConfirmation' => $isInfoDept ? true : false,
    'createdByDept'     => $adminSpecialty ?? '',
    'createdByRole'     => 'admin',         // you can change to 'information' if you have separate roles

    'createdAt'       => $tsNow,
    'slotKey'         => $slotKey,
    'sessionKey'      => $sessKey,
    'apponum'         => $apponum,
    'queueNumber'     => $apponum,
    'source'          => 'walkin'
];

                        $apptRef = $database->getReference('appointments')->push($appointment);
                        $apptId  = $apptRef->getKey();

                        // Session bookings
                        $database->getReference("{$sessionPath}/bookings/{$apptId}")->set($patientId);
                        // Slot queue map
                        $database->getReference("doctor/{$docId}/queues/{$slotKey}/appointments/{$apptId}")->set($apponum);
                        // Denormalized links + duplicate index
                        $database->getReference("patients/{$patientId}/appointments/{$apptId}")->set(true);
                        $database->getReference("doctor/{$docId}/appointments/{$apptId}")->set(true);
                        $database->getReference("patients/{$patientId}/slots/{$slotKey}")->set($apptId);

                        // Reload same view (keep id in query if present)
                        $redir = "schedule.php?action=view";
                        if ($scheduleIdParam !== '') {
                            $redir .= "&id=" . urlencode($scheduleIdParam);
                        } else {
                            $redir .= "&title=" . urlencode($sTitle);
                        }
                        header("Location: {$redir}"); exit();
                    }
                } catch (Throwable $e) {
                    $walkInError = 'Walk-in booking failed: ' . htmlspecialchars($e->getMessage());
                }
            }
        }

        // --- Load session (by ID first, then fallback to title for legacy links) ---
        $session = null;
        $docid = $docname = $scheduledate = $scheduletime = '';
        $start_time = $end_time = '';
        $title = '';

        if ($scheduleIdParam !== '') {
            $sessSnap = $database->getReference('schedule/' . $scheduleIdParam)->getSnapshot();
            if ($sessSnap->exists()) {
                $session      = $sessSnap->getValue();
                $title        = $session['title']        ?? '';
                $docid        = $session['docid']        ?? '';
                $scheduledate = $session['scheduledate'] ?? '';
                $scheduletime = $session['scheduletime'] ?? '';
                $start_time   = $session['start_time']   ?? $scheduletime; // fallback old
                $end_time     = $session['end_time']     ?? $scheduletime; // fallback old
            }
        }

        // Fallback: legacy by title (kept for backwards compatibility)
        if (!$session && $titleParam !== '') {
            $sessions = $database->getReference('schedule')->getValue() ?? [];
            foreach ($sessions as $key => $value) {
                if (isset($value['title']) && strtolower($value['title']) == strtolower($titleParam)) {
                    $session      = $value;
                    $title        = $value['title']        ?? '';
                    $docid        = $value['docid']        ?? '';
                    $scheduledate = $value['scheduledate'] ?? '';
                    $scheduletime = $value['scheduletime'] ?? '';
                    $start_time   = $value['start_time']   ?? $scheduletime;
                    $end_time     = $value['end_time']     ?? $scheduletime;
                    $scheduleIdParam = $key;
                    break;
                }
            }
        }

        if ($session) {
            $docRow  = $database->getReference('doctor/' . $docid)->getValue() ?? [];
            $docname = $docRow['name'] ?? 'Unknown';

          // Stable keys for booking/queueing (canonical RANGE + LEGACY)
$slotKeyRange  = canonSlotKeyRange($docid, $scheduledate, $start_time, $end_time);
// legacy single-time key for older data
$slotKeyLegacy = canonSlotKey($docid, $scheduledate, $start_time);

$slotKey    = $slotKeyRange; // keep for sessionKey, etc.
$sessionKey = substr(sha1($slotKey.'|'.$title), 0, 12);

// Load appointments and match BOTH new + legacy formats
$appointmentsData = $database->getReference('appointments')->getValue() ?? [];

$filteredAppointments = array_filter(
    $appointmentsData,
    function ($appointment) use ($slotKeyRange, $slotKeyLegacy, $docid, $scheduledate, $start_time, $end_time) {
        // 1) Prefer explicit slotKey match (new + old format)
        $appSlot = $appointment['slotKey'] ?? '';
        if ($appSlot !== '') {
            if ($appSlot === $slotKeyRange || $appSlot === $slotKeyLegacy) {
                return true;
            }
        }

        // 2) Fallback: match by doctor + date + time (for very old records)
        $appDoc  = trim((string)($appointment['doctorId'] ?? ''));
        $appDate = isset($appointment['date']) ? canonDate($appointment['date']) : '';

        if ($appDoc === '' || $appDate === '') return false;
        if ($appDoc !== $docid || $appDate !== canonDate($scheduledate)) return false;

        // Compare time (start & end); many old records only have "time"
        $aStartRaw = $appointment['start_time'] ?? ($appointment['time'] ?? '');
        $aEndRaw   = $appointment['end_time']   ?? $aStartRaw;
        if ($aStartRaw === '') return false;

        $aStart = canonTime($aStartRaw);
        $aEnd   = canonTime($aEndRaw);

        $sStart = canonTime($start_time);
        $sEnd   = canonTime($end_time);

        // treat as same session if start & end match
        return ($aStart === $sStart && $aEnd === $sEnd);
    }
);


            // Count confirmed only
            $patientCount = 0;
            foreach ($filteredAppointments as $a) {
                if (strtolower($a['status'] ?? '') === 'confirmed') { $patientCount++; }
            }

            echo '
            <div id="popup1" class="overlay">
                <div class="popup" style="width: 70%;;">
                    <center>
                        <h2>Session Details</h2>
                        <a class="close" href="schedule.php">&times;</a>
                        '. (!empty($walkInError) ? '<div style="background:#ffecec;color:#a94442;border:1px solid #f5c2c2;padding:8px 12px;margin-bottom:12px;border-radius:6px;">'. $walkInError .'</div>' : '') .'
                        <div class="abc scroll" style="display:flex;justify-content:center;">
                            <table width="80%" class="sub-table scrolldown add-doc-form-container" border="0">
                                <tr><td><p style="font-size:25px;font-weight:500;">View Details.</p></td></tr>

                                <!-- Header row with Add Walk-In button -->
                                <tr>
                                  <td style="padding:8px 0 16px 0; display:flex; gap:10px; align-items:center;">
                                    <span class="form-label" style="font-weight:600;">Patients Registered: </span>
                                    <span style="font-weight:600;color:var(--btnnicetext);">'. (int)$patientCount .'</span>
                                    <button id="addWalkInBtn" class="btn-primary btn" style="margin-left:auto;">+ Add Walk-In Patient</button>
                                  </td>
                                </tr>

                                <!-- Walk-in form (hidden by default) -->
                                <tr id="walkInFormRow" style="display:none;">
                                  <td>
                                    <form method="POST" class="add-new-form" style="border:1px solid #e5e5e5;border-radius:10px;padding:16px;">
                                        <input type="hidden" name="action"          value="book_session_walkin">
                                        <input type="hidden" name="doctor_id"       value="'. htmlspecialchars($docid, ENT_QUOTES, "UTF-8") .'">
                                        <input type="hidden" name="session_key"     value="'. htmlspecialchars($sessionKey, ENT_QUOTES, "UTF-8") .'">
                                        <input type="hidden" name="schedule_title"  value="'. htmlspecialchars($title, ENT_QUOTES, "UTF-8") .'">
                                        <input type="hidden" name="schedule_date"   value="'. htmlspecialchars(canonDate($scheduledate), ENT_QUOTES, "UTF-8") .'">
                                        <input type="hidden" name="schedule_start"  value="'. htmlspecialchars(canonTime($start_time), ENT_QUOTES, "UTF-8") .'">
                                        <input type="hidden" name="schedule_end"    value="'. htmlspecialchars(canonTime($end_time), ENT_QUOTES, "UTF-8") .'">

                                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                                            <div>
                                                <label class="form-label">Patient Full Name</label>
                                                <input type="text" name="walkin_name" class="input-text" placeholder="e.g., Juan Dela Cruz" required>
                                            </div>
                                            <div>
                                                <label class="form-label">Patient Email</label>
                                                <input type="email" name="walkin_email" class="input-text" placeholder="e.g., juan@email.com" required>
                                            </div>
                                        </div>

                                        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:12px;">
                                            <button type="button" id="cancelWalkInBtn" class="login-btn btn-primary-soft btn">Cancel</button>
                                            <button type="submit" class="login-btn btn-primary btn">Add Walk-In</button>
                                        </div>
                                    </form>
                                  </td>
                                </tr>

                                <tr><td class="label-td" colspan="2"><label class="form-label">Session Title: </label></td></tr>
                                <tr><td class="label-td" colspan="2">'. htmlspecialchars($title) .'<br><br></td></tr>
                                <tr><td class="label-td" colspan="2"><label class="form-label">Hosted Doctor: </label></td></tr>
                                <tr><td class="label-td" colspan="2">'. htmlspecialchars($docname) .'<br><br></td></tr>
                                <tr><td class="label-td" colspan="2"><label class="form-label">Session Date: </label></td></tr>
                                <tr><td class="label-td" colspan="2">'. htmlspecialchars(canonDate($scheduledate)) .'<br><br></td></tr>
                                <tr><td class="label-td" colspan="2"><label class="form-label">Session Time: </label></td></tr>
                                <tr><td class="label-td" colspan="2">'. htmlspecialchars(canonTime($start_time)) .' - '. htmlspecialchars(canonTime($end_time)) .'<br><br></td></tr>

                                <tr><td class="label-td" colspan="2"><label class="form-label">Patients (by status): </label></td></tr>

                                <tr>
                                    <td colspan="2">
                                        <center>
                                            <div class="abc scroll">
                                                <table width="100%" class="sub-table scrolldown" border="0">
                                                    <thead>
                                                        <tr>
                                                            <th class="table-headin">Patient Name</th>
                                                            <th class="table-headin">Appointment Number</th>
                                                            <th class="table-headin">Patient Email</th>
                                                            <th class="table-headin">Status</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>';
            if (empty($filteredAppointments)) {
                echo '<tr><td colspan="4">
                        <br><br><br><br>
                        <center>
                            <img src="../img/notfound.svg" width="25%">
                            <br>
                            <p class="heading-main12" style="margin-left:45px;font-size:20px;color:rgb(49,49,49)">No appointments found for this session!</p>
                        </center>
                        <br><br><br><br>
                      </td></tr>';
            } else {
                foreach ($filteredAppointments as $appointmentId => $appointment) {
                    $statusRaw = $appointment['status'] ?? 'unknown';
                    $status    = ucfirst(strtolower($statusRaw));

                    $apponum = $appointment['apponum']      ?? '';
                    $pname   = $appointment['patientName']  ?? '';
                    $email   = $appointment['patientEmail'] ?? '';

                    echo '<tr style="text-align:center;">
                            <td style="font-weight:600;padding:25px">
                                <a href="patient.php?name=' . urlencode($pname) . '" style="text-decoration:none;color:inherit;">' . htmlspecialchars($pname) . '</a>
                            </td>
                            <td style="text-align:center;font-size:23px;font-weight:500;color:var(--btnnicetext);">' . htmlspecialchars($apponum) . '</td>
                            <td>' . htmlspecialchars($email) . '</td>
                            <td>' . htmlspecialchars($status) . '</td>
                          </tr>';
                }
            }

            echo '                                  </tbody>
                                                </table>
                                            </div>
                                        </center>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        <br><br>
                    </center>
                </div>
            </div>';
        } else {
            echo '
            <div id="popup1" class="overlay">
                <div class="popup">
                    <center>
                        <h2>Error</h2>
                        <a class="close" href="schedule.php">&times;</a>
                        <div class="content">The session was not found.</div>
                        <div style="display:flex;justify-content:center;">
                            <a href="schedule.php"><button class="btn-primary btn">OK</button></a>
                        </div>
                    </center>
                </div>
            </div>';
        }
    }

    // ---------------- Duplicate-session overlay ----------------
    if ($action == 'error' && ($_GET['message'] ?? '') == 'duplicate-session') {
        echo "<div id='overlay' class='overlay'>
                <div class='overlay-content'>
                    <h3>Schedule Conflict</h3>
                    <p>There is already a session scheduled for this doctor at the selected date and time.</p>
                    <button id='close-overlay'>OK</button>
                </div>
              </div>";
    }
}
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const closeOverlayButton = document.getElementById('close-overlay');
    if (closeOverlayButton) {
        closeOverlayButton.addEventListener('click', function () {
            const overlay = document.getElementById('overlay');
            if (overlay) overlay.style.display = 'none';
        });
    }

    // ✅ FIX: remove nested DOMContentLoaded (hamburger now runs correctly)
    const hamburgerMenu = document.getElementById('hamburger-menu');
    const overlay = document.getElementById('menu-overlay');

    function openMenu(){
      document.body.classList.add('menu-open');
      hamburgerMenu.classList.add('active');
    }
    function closeMenu(){
      document.body.classList.remove('menu-open');
      hamburgerMenu.classList.remove('active');
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

    const dateInput = document.querySelector('input[name="date"]');
    const today = new Date();
    const maxDate = new Date();
    maxDate.setMonth(maxDate.getMonth() + 1);

    const formatDate = (date) => {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    };
    if (dateInput) {
        dateInput.min = formatDate(today);
        dateInput.max = formatDate(maxDate);
    }

    // NEW: End time filtering
    const startSel = document.getElementById('start_time');
    const endSel   = document.getElementById('end_time');

    function toMinutes(t){
      const [h,m] = t.split(':').map(Number);
      return h*60 + m;
    }

    function filterEndTimes(){
      if (!startSel || !endSel) return;
      const startVal = startSel.value;
      if (!startVal) {
        [...endSel.options].forEach(o => o.disabled = false);
        return;
      }
      const sMin = toMinutes(startVal);

      [...endSel.options].forEach(o => {
        if (!o.value) return;
        o.disabled = toMinutes(o.value) <= sMin;
      });

      if (endSel.value && toMinutes(endSel.value) <= sMin) {
        endSel.value = '';
      }
    }

    if (startSel) startSel.addEventListener('change', filterEndTimes);
    filterEndTimes();
});

// confirm reset/save used in add-session form
function confirmSave() {
    return confirm("Are you sure you want to save the changes?");
}

// Walk-in form toggles (Session Details)
(function(){
  const addBtn    = document.getElementById("addWalkInBtn");
  const formRow   = document.getElementById("walkInFormRow");
  const cancelBtn = document.getElementById("cancelWalkInBtn");
  if (addBtn && formRow) {
    addBtn.addEventListener("click", function(){
      formRow.style.display = (formRow.style.display === "none" || formRow.style.display === "") ? "table-row" : "none";
    });
  }
  if (cancelBtn && formRow) {
    cancelBtn.addEventListener("click", function(){
      formRow.style.display = "none";
    });
  }
})();

// --- Open Queue Window ---
(function(){
  const btn = document.getElementById('openQueueBtn');
  if (!btn) return;

  const QUEUE_URL = 'tv_display2.php';

  btn.addEventListener('click', function(e){
    e.preventDefault();
    window.open(
      QUEUE_URL,
      'QueueWindow',
      'width=1280,height=800,menubar=no,toolbar=no,location=no,status=no,scrollbars=yes,resizable=yes'
    );
  });
})();
</script>


</body>
</html>
