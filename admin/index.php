<?php
session_name('sess_admin'); session_start();

// Check if the user is logged in and is an admin 
if (isset($_SESSION["user"])) {
    if (($_SESSION["user"]) == "" || $_SESSION['usertype'] != 'a') {
        header("location: ../login.php");
        exit;
    } else {
        $useremail = $_SESSION["user"];
    }
} else {
    header("location: ../login.php");
    exit;
}

// Import the database connection
include("../connection.php");

// Timezone + date (for header)
date_default_timezone_set('Asia/Manila');
$todayHeader = date('Y-m-d');

// Retrieve the admin background image from Firebase
$imageUrl = $database->getReference('admin_page/admin_backgroundImage')->getValue();
$todayStr     = date('Y-m-d');
$nextMonthEnd = date('Y-m-t', strtotime('+1 month'));
$nextweek     = date('Y-m-d', strtotime("+1 week"));

// Get admin data from Firebase using the logged-in user email
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

// Retrieve admin data from Firebase 
$adminRef  = $database->getReference('admin')->orderByChild('email')->equalTo($useremail);
$adminData = $adminRef->getValue();

if ($adminData) {
    $adminData = array_shift($adminData);
    $username  = isset($adminData['name']) ? $adminData['name'] : null;
}

$photo = '../img/user.png';
if ($adminData) {
    // NOTE: $adminData is now a record, not an array of records. Keep as-is but safe.
    if (!empty($adminData['photo'])) {
        $photo = $adminData['photo'];
    }
}

// Fetch additional data from Firebase
$patientsRef    = $database->getReference('patients')->getValue();
$secretariesRef = $database->getReference('admin')->getValue();
$doctorsRef     = $database->getReference('doctor')->getValue();
$specialtiesRef = $database->getReference('specialties')->getValue();
$scheduleRef    = $database->getReference('schedule')->getValue();

// Map specialty IDs to names for easy lookup
$specialties = [];
if (!empty($specialtiesRef)) {
    foreach ($specialtiesRef as $id => $specialty) {
        $specialties[$id] = $specialty['sname'] ?? 'Unknown Specialty';
    }
}

// Process patients by city
$patientsByCity = [];
if (!empty($patientsRef)) {
    foreach ($patientsRef as $patient) {
        $city = $patient['city'] ?? 'Unknown';
        $patientsByCity[$city] = ($patientsByCity[$city] ?? 0) + 1;
    }
}

// Set the time window: today until 7 days from now.
$startDateForSchedule = new DateTime();
$endDateForSchedule   = new DateTime('+7 days');

$schedulesCount = [];
$tempDate = clone $startDateForSchedule;
for ($i = 0; $i < 7; $i++) {
    $dateKey = $tempDate->format('Y-m-d');
    $schedulesCount[$dateKey] = 0;
    $tempDate->modify('+1 day');
}

// Loop through each schedule entry from the schedule node.
if (!empty($scheduleRef)) {
    foreach ($scheduleRef as $scheduleId => $schedule) {
        if (isset($schedule['scheduledate'])) {
            $scheduleDateObj = DateTime::createFromFormat('Y-m-d', $schedule['scheduledate']);
            if ($scheduleDateObj && $scheduleDateObj >= new DateTime() && $scheduleDateObj <= $endDateForSchedule) {
                if (isset($schedule['docid']) && !empty($doctorsRef[$schedule['docid']])) {
                    $doctor = $doctorsRef[$schedule['docid']];
                    if (isset($doctor['sname']) && trim($doctor['sname']) === $adminSpecialty) {
                        $dateKey = $scheduleDateObj->format('Y-m-d');
                        if (array_key_exists($dateKey, $schedulesCount)) {
                            $schedulesCount[$dateKey]++;
                        }
                    }
                }
            }
        }
    }
}

$doctorsBySpecialty = [];
if (!empty($doctorsRef)) {
    foreach ($doctorsRef as $doctor) {
        $specialtyId   = $doctor['specialty'] ?? 'Unknown';
        $specialtyName = $specialties[$specialtyId] ?? 'Unknown Specialty';
        $doctorsBySpecialty[$specialtyName] = ($doctorsBySpecialty[$specialtyName] ?? 0) + 1;
    }
}

// ---- Load pending appointments (new schema friendly) ----
$filteredAppointments = [];

// Try fast path: query by status = 'pending'
try {
    $pending = $database->getReference('appointments')
        ->orderByChild('status')
        ->equalTo('pending')
        ->getValue() ?: [];
} catch (\Kreait\Firebase\Exception\Database\UnsupportedQuery $e) {
    $pending = $database->getReference('appointments')->getValue() ?: [];
    $pending = array_filter($pending, function($a){
        return strtolower(trim((string)($a['status'] ?? 'pending'))) === 'pending';
    });
}

foreach ($pending as $appointmentId => $a) {
    $date = $a['date'] ?? $a['scheduledate'] ?? '';
    $time = $a['time'] ?? $a['scheduletime'] ?? '';
    if ($date === '' || $time === '') continue;

    if ($date < $todayStr || $date > $nextMonthEnd) continue;

    $doctor = null;

    if (!empty($a['doctorId'])) {
        try { $doctor = $database->getReference('doctor/'.$a['doctorId'])->getValue(); }
        catch (\Throwable $e) { $doctor = null; }
    }

    if (!$doctor && !empty($a['doctoremail'])) {
        try {
            $dq = $database->getReference('doctor')
                ->orderByChild('email')
                ->equalTo($a['doctoremail'])
                ->getValue();
            if (!empty($dq)) $doctor = array_shift($dq);
        } catch (\Throwable $e) { /* ignore */ }
    }

    if (!$doctor) continue;

    $docSname = is_array($doctor['sname'] ?? null)
        ? trim(implode(', ', $doctor['sname']))
        : trim((string)($doctor['sname'] ?? ''));

    if ($docSname === '' || $docSname !== $adminSpecialty) continue;

    $filteredAppointments[$appointmentId] = [
        'title'        => $a['title'] ?? 'Consultation',
        'patientName'  => $a['patientName'] ?? ($a['name'] ?? 'Unknown'),
        'patientEmail' => $a['patientEmail'] ?? ($a['email'] ?? ''),
        'date'         => $date,
        'time'         => $time,
    ];
}

// Convert processed data to JSON for use in JavaScript charts
$patientsByCityJson     = json_encode($patientsByCity);
$doctorsBySpecialtyJson = json_encode($doctorsBySpecialty);
$schedulesCountJson     = json_encode($schedulesCount);

// Fetch recent patients 
$patientsRefLastFive = $database->getReference('patients')->orderByKey()->limitToLast(5)->getValue();
$recentPatients = [];

if (!empty($patientsRefLastFive)) {
    foreach ($patientsRefLastFive as $key => $patient) {
        if (isset($patient['name'], $patient['email'])) {
            $recentPatients[] = [
                'name'   => $patient['name'] ?? 'N/A',
                'gender' => $patient['gender'] ?? 'N/A',
                'email'  => $patient['email'] ?? 'N/A',
                'tele'   => $patient['tele'] ?? 'N/A',
                'photo'  => $patient['photo'] ?? 'user.png',
            ];
        }
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
        .dashbord-tables {
            width: 50%;
            margin: 0 auto;
            margin-bottom: 2px;
            padding: 0;
        }

        .filter-container { animation: transitionIn-Y-bottom 0.5s; }

        .chart-card {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            border: 1px solid #e0e0e0;
            text-align: center;
            margin-left: -50px;
            margin-right: 100px;
        }
        .chart-card h3 { font-size: 16px; color: #333; margin-bottom: 10px; }
        .chart-card canvas { max-height: 300px; margin: 0 auto; }

        .dashboard-content {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            justify-content: center;
            margin-top: 20px;
        }

        .show-buttons-container {
            margin-top: 5px;
            margin-bottom: 20px;
            padding: 0;
            text-align: center;
        }

        tr, td { padding: 0; margin: 0; }
        .dashbord-tables table { border-spacing: 0; margin: 0; padding: 0; }

        .btn-primary { padding: 8px 16px; font-size: 14px; }

        .dual-container {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            margin-top: 10px;
        }

        .appointment-requests, .recent-patients {
            flex: 1;
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

        .header h3 { font-size: 16px; font-weight: 500; }
        .header a { color: #007BFF; text-decoration: none; font-size: 12px; }

        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 6px 10px; border: 1px solid #ddd; font-size: 12px; }

        .table th {
            background-color: var(--primarycolor);
            color: white;
            text-align: left;
            padding: 10px;
            font-size: 14px;
            font-weight: 600;
            border-bottom: 2px solid #e0e0e0;
            border-right: 1px solid #ffffff;
        }
        .table th:last-child { border-right: none; }
        .table tbody tr:hover { background: #f9f9f9; }

        .btn-approve, .btn-reject {
            background: none;
            border: none;
            font-size: 14px;
            cursor: pointer;
        }
        .btn-approve { color: green; }
        .btn-reject { color: red; }

        .recent-patients .name-container { display: flex; align-items: center; }
        .recent-patients .profile-pic {
            width: 30px; height: 30px; border-radius: 50%;
            object-fit: cover; margin-right: 10px;
        }
        .recent-patients .name-container span { font-size: 14px; color: #333; font-weight: 500; }

        .menu-btn a { display: block; text-decoration: none; padding: 1px; width: 100%; }
        .menu-btn:hover { background-color: #E8F8E8; }

        /* ======== MOBILE HEADER + DRAWER (same pattern you used) ======== */
        .mobile-header{ display:none; }
        #menu-overlay{ display:none; }

        @media (max-width: 768px){

            /* hide desktop top row */
            .desktop-topbar{ display:none !important; }

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
            .mobile-date{ font-size:13px; font-weight:600; color:#000; white-space:nowrap; }
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

            /* drawer */
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

            /* maximize the content */
            .dash-body{
                margin-top:66px !important;
                margin-left:0 !important;
                padding:12px !important;
            }

            /* Remove big left margins that waste space */
            .dashbord-tables{ width:100% !important; }
            .chart-card{
                width:100% !important;
                margin:0 !important;
            }
            .dashboard-content{
                gap:12px !important;
                padding:0 !important;
                margin-top:10px !important;
            }

            /* Make charts readable on small screens */
            .chart-card canvas{
                max-height: 260px !important;
            }

            /* Stack the bottom tables */
            .dual-container{
                flex-direction:column !important;
                margin-left:0 !important;
                gap:12px !important;
            }

            /* Better spacing around the whole charts section */
            .charts-wrap{
                margin-left:0 !important;
                margin-right:0 !important;
                margin-bottom:30px !important;
            }

            /* Mobile table: keep as your responsive block table */
            .table-responsive{ width:100%; }
        }

        /* Your existing table responsive block mode (keep) */
        @media screen and (max-width: 600px) {
          .table-responsive table,
          .table-responsive thead,
          .table-responsive tbody,
          .table-responsive th,
          .table-responsive td,
          .table-responsive tr { display: block; }

          .table-responsive thead tr {
            position: absolute;
            top: -9999px;
            left: -9999px;
          }

          .table-responsive tr {
            margin-bottom: 1rem;
            border: 1px solid #ddd;
          }

          .table-responsive td {
            position: relative;
            padding-left: 50%;
            border: none;
            border-bottom: 1px solid #eee;
            text-align: left;
          }

          .table-responsive td::before {
            position: absolute;
            left: 6px;
            top: 6px;
            width: 45%;
            padding-right: 10px;
            white-space: nowrap;
            content: attr(data-label);
            font-weight: bold;
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

<!-- Mobile Header -->
<div class="mobile-header">
    <div class="mobile-left">
        <div id="hamburger-menu">
            <div class="bar"></div>
            <div class="bar"></div>
            <div class="bar"></div>
        </div>
    </div>

    <div class="mobile-center">Dashboard</div>

    <div class="mobile-right">
        <div class="mobile-date"><?php echo $todayHeader; ?></div>
        <button class="mobile-calendar-btn" type="button" aria-label="Calendar">
            <img src="../img/calendar.svg" alt="">
        </button>
    </div>
</div>

<!-- Overlay -->
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
                    <a href="index.php" class="non-style-link-menu non-style-link-menu-active"><p class="menu-text">Dashboard</p></a>
                </td>
            </tr>
            <tr class="menu-row"><td class="menu-btn"><a href="doctors.php" class="non-style-link-menu"><p class="menu-text">Doctors</p></a></td></tr>
            <tr class="menu-row"><td class="menu-btn"><a href="schedule.php" class="non-style-link-menu"><p class="menu-text">Schedule</p></a></td></tr>
            <tr class="menu-row"><td class="menu-btn"><a href="bed.php" class="non-style-link-menu"><p class="menu-text">Bed Occupancy</p></a></td></tr>
            <tr class="menu-row"><td class="menu-btn"><a href="queing.php" class="non-style-link-menu"><p class="menu-text">Queue</p></a></td></tr>
            <tr class="menu-row"><td class="menu-btn"><a href="patient.php" class="non-style-link-menu"><p class="menu-text">Patients Health Record</p></a></td></tr>
            <tr class="menu-row"><td class="menu-btn"><a href="archive.php" class="non-style-link-menu"><p class="menu-text">Archives</p></a></td></tr>
            <tr class="menu-row"><td class="menu-btn"><a href="summary.php" class="non-style-link-menu"><p class="menu-text">Summary</p></a></td></tr>
            <tr class="menu-row"><td class="menu-btn"><a href="settings.php" class="non-style-link-menu"><p class="menu-text">Settings</p></a></td></tr>
        </table>
    </div>

    <div class="dash-body" style="margin-top: 15px">
        <table border="0" width="100%" style="border-spacing:0;margin:0;padding:0;">
            <tr class="desktop-topbar">
                <td>
                    <p style="font-size: 30px; padding-left: 20px; text-align:left; font-weight: 600;">Dashboard</p>
                </td>

                <td width="15%">
                    <p style="font-size: 14px;color: rgb(119, 119, 119);padding: 0;margin: 0;text-align: right;">Today's Date</p>
                    <p class="heading-sub12" style="padding: 0;margin: 0;">
                        <?php echo $todayHeader; ?>
                    </p>
                </td>
                <td width="10%">
                    <button class="btn-label" style="display:flex;justify-content:center;align-items:center;">
                        <img src="../img/calendar.svg" width="100%">
                    </button>
                </td>
            </tr>

            <tr>
                <td colspan="4">
                    <table width="100%" border="0" class="dashbord-tables">
                        <tr><td></td></tr>
                        <tr><td></td></tr>
                    </table>
                </td>
            </tr>
        </table>

        <!-- WRAP charts section so we can remove fixed margins on mobile -->
        <div class="charts-wrap" style="margin-left: 100px; margin-bottom: 50px;">
            <h3>Charts</h3>

            <div class="dashboard-content" style="display:flex; flex-wrap:wrap; gap:20px; justify-content:center;">
                <div class="chart-card">
                    <h3>Patients by City</h3>
                    <canvas id="patientsChart"></canvas>
                </div>
                <div class="chart-card">
                    <h3>Appointments for the next 7 days</h3>
                    <canvas id="appointmentsChart"></canvas>
                </div>
            </div>

            <div class="dual-container" style="margin-top: 15px; margin-left: -100px;">
                <div class="appointment-requests table-responsive">
                    <div class="header">
                        <h3>Appointment Requests</h3>
                    </div>

                    <table class="table">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Date & Time</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!empty($filteredAppointments)): ?>
                            <?php foreach ($filteredAppointments as $appointmentId => $appointment): ?>
                                <tr>
                                    <td data-label="Title"><?php echo htmlspecialchars($appointment['title']); ?></td>
                                    <td data-label="Name"><?php echo htmlspecialchars($appointment['patientName']); ?></td>
                                    <td data-label="Email"><?php echo htmlspecialchars($appointment['patientEmail']); ?></td>
                                    <td data-label="Date & Time"><?php echo htmlspecialchars($appointment['date']); ?> @ <?php echo htmlspecialchars(substr($appointment['time'], 0, 5)); ?></td>
                                    <td data-label="Actions">
                                        <form method="post" action="confirm-appointment.php" style="display:inline;" onsubmit="return confirm('Approve this appointment?');">
                                            <input type="hidden" name="appointment_id" value="<?php echo htmlspecialchars($appointmentId); ?>">
                                            <button type="submit" name="action" value="approve" class="btn-approve" title="Approve">✔</button>
                                        </form>

                                        <form method="post" action="confirm-appointment.php" style="display:inline;" onsubmit="return confirm('Reject this appointment? This will free the slot.');">
                                            <input type="hidden" name="appointment_id" value="<?php echo htmlspecialchars($appointmentId); ?>">
                                            <button type="submit" name="action" value="reject" class="btn-reject" title="Reject">✖</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5">No pending appointment requests for the next month</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="recent-patients table-responsive">
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
                                <tr>
                                    <td data-label="Name">
                                        <div class="name-container">
                                            <img src="<?php echo htmlspecialchars($patient['photo']); ?>" alt="Profile" class="profile-pic" style="width:40px;height:40px;border-radius:50%;object-fit:cover;">
                                            <span><?php echo htmlspecialchars($patient['name']); ?></span>
                                        </div>
                                    </td>
                                    <td data-label="Gender"><?php echo htmlspecialchars($patient['gender']); ?></td>
                                    <td data-label="Email"><?php echo htmlspecialchars($patient['email']); ?></td>
                                    <td data-label="Contact"><?php echo htmlspecialchars($patient['tele']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="4">No recent patients</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const patientsByCity    = <?php echo $patientsByCityJson; ?>;
const appointmentsData  = <?php echo $schedulesCountJson; ?>;

function renderChart(chartId, label, data) {
    const ctx = document.getElementById(chartId).getContext('2d');
    const dataValues = Object.values(data);
    const maxValue = Math.max(...dataValues);
    const roundedMax = Math.ceil(maxValue / 5) * 10;

    const colors = [
        'rgba(255, 99, 132)',
        'rgba(54, 162, 235)',
        'rgba(255, 206, 86)',
        'rgba(75, 192, 192)',
        'rgba(153, 102, 255)',
        'rgba(255, 159, 64)',
        'rgba(0, 204, 0)'
    ];

    const borderColors = [...colors];

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: Object.keys(data),
            datasets: [{
                label: label,
                data: dataValues,
                backgroundColor: Object.keys(data).map((_, index) => colors[index % colors.length]),
                borderColor: Object.keys(data).map((_, index) => borderColors[index % borderColors.length]),
                borderWidth: 1
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { beginAtZero: true, ticks: { stepSize: 1, max: roundedMax } },
                y: { ticks: { font: { size: 10 } } }
            }
        }
    });
}

renderChart('patientsChart', 'Patients by City', patientsByCity);
renderChart('appointmentsChart', 'Appointments for Next 7 Days', appointmentsData);

// Drawer menu (mobile)
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

hamburgerMenu.addEventListener('click', () => {
    document.body.classList.contains('menu-open') ? closeMenu() : openMenu();
});

overlay.addEventListener('click', closeMenu);

document.querySelectorAll('.menu a').forEach(link=>{
    link.addEventListener('click', () => {
        if(window.innerWidth <= 768) closeMenu();
    });
});
</script>

</body>
</html>
