<?php
session_name('sess_doctor'); session_start();

if(isset($_SESSION["user"])) {
    if(($_SESSION["user"]) == "" || $_SESSION['usertype'] != 'd') {
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

// Retrieve doctor information
$doctorRef = $database->getReference('doctor')
                      ->orderByChild('email')
                      ->equalTo($useremail)
                      ->limitToFirst(1);

$doctorSnapshot = $doctorRef->getSnapshot();

if ($doctorSnapshot->exists()) {
    $userfetch = $doctorSnapshot->getValue();
    $userfetch = reset($userfetch);
    $username = $userfetch['name'];
} else {
    header("location: ../login.php");
    exit();
}

// Get patients data
$patientsRef = $database->getReference('patients');
$patientsData = $patientsRef->getSnapshot()->getValue();
if (!$patientsData) $patientsData = array();

// ----------------- General Trias Data (City) -----------------
$generalTriasData = ['labels' => ['General Trias'], 'data' => [0]];
foreach ($patientsData as $patient) {
    $city = $patient['city'] ?? null;
    if ($city === 'General Trias') $generalTriasData['data'][0]++;
}
$generalTriasTableRows = "<tr><td>General Trias</td><td>{$generalTriasData['data'][0]}</td></tr>";
$generalTriasLabels = json_encode($generalTriasData['labels']);
$generalTriasCounts = json_encode($generalTriasData['data']);

// ----------------- General Trias Barangay Data -----------------
$barangayData = ['labels' => [], 'data' => []];
foreach ($patientsData as $patient) {
    $city = $patient['city'] ?? null;
    if ($city === 'General Trias') {
        $barangay = $patient['barangay'] ?? 'Unknown';
        if (isset($barangayData['data'][$barangay])) $barangayData['data'][$barangay]++;
        else {
            $barangayData['data'][$barangay] = 1;
            $barangayData['labels'][] = $barangay;
        }
    }
}
$barangayTableRows = '';
foreach ($barangayData['data'] as $barangay => $count) {
    $barangayTableRows .= "<tr><td>$barangay</td><td>$count</td></tr>";
}
$barangayLabels = json_encode($barangayData['labels']);
$barangayCounts = json_encode(array_values($barangayData['data']));

// ----------------- Within Cavite Data (Excluding General Trias) -----------------
$withinCaviteData = ['labels' => [], 'data' => []];
foreach ($patientsData as $patient) {
    if ((($patient['province'] ?? '') === 'Cavite') && (($patient['city'] ?? '') !== 'General Trias')) {
        $city = $patient['city'] ?? 'Unknown';
        if (isset($withinCaviteData['data'][$city])) $withinCaviteData['data'][$city]++;
        else {
            $withinCaviteData['data'][$city] = 1;
            $withinCaviteData['labels'][] = $city;
        }
    }
}
$withinCaviteTableRows = '';
foreach ($withinCaviteData['data'] as $city => $count) {
    $withinCaviteTableRows .= "<tr><td>$city</td><td>$count</td></tr>";
}
$withinCaviteLabels = json_encode($withinCaviteData['labels']);
$withinCaviteCounts = json_encode(array_values($withinCaviteData['data']));

// ----------------- Outside Cavite Data -----------------
$outsideCaviteData = ['labels' => [], 'data' => []];
foreach ($patientsData as $patient) {
    if (($patient['province'] ?? '') !== 'Cavite') {
        $city = $patient['city'] ?? 'Unknown';
        if (isset($outsideCaviteData['data'][$city])) $outsideCaviteData['data'][$city]++;
        else {
            $outsideCaviteData['data'][$city] = 1;
            $outsideCaviteData['labels'][] = $city;
        }
    }
}
$outsideCaviteTableRows = '';
foreach ($outsideCaviteData['data'] as $city => $count) {
    $outsideCaviteTableRows .= "<tr><td>$city</td><td>$count</td></tr>";
}
$outsideCaviteLabels = json_encode($outsideCaviteData['labels']);
$outsideCaviteCounts = json_encode(array_values($outsideCaviteData['data']));

// ----------------- Cases Data -----------------
$caseData = ['labels' => [], 'data' => []];
$conditionsRef = $database->getReference('conditions');
foreach ($patientsData as $patientId => $patient) {
    if (isset($patient['cases']) && is_array($patient['cases'])) {
        foreach ($patient['cases'] as $caseId => $case) {
            $conditionId = $case['condition_id'] ?? null;
            if ($conditionId) {
                $condition = $conditionsRef->getChild($conditionId)->getValue();
                if ($condition && isset($condition['condition_name'])) {
                    $caseName = $condition['condition_name'];
                    if (isset($caseData['data'][$caseName])) $caseData['data'][$caseName]++;
                    else {
                        $caseData['data'][$caseName] = 1;
                        $caseData['labels'][] = $caseName;
                    }
                }
            }
        }
    }
}
$caseTableRows = '';
foreach ($caseData['data'] as $caseName => $count) {
    $caseTableRows .= "<tr><td>$caseName</td><td>$count</td></tr>";
}
$caseLabels = json_encode($caseData['labels']);
$caseCounts = json_encode(array_values($caseData['data']));

// ----------------- Age Groups Data -----------------
$ageData = ['labels' => [], 'data' => []];
foreach ($patientsData as $patient) {
    $dob = $patient['dob'] ?? '';
    $age = calculateAge($dob);
    $ageGroup = ($age < 17) ? 'Under 17' : '17 and above';
    if (isset($ageData['data'][$ageGroup])) $ageData['data'][$ageGroup]++;
    else {
        $ageData['data'][$ageGroup] = 1;
        $ageData['labels'][] = $ageGroup;
    }
}
$ageTableRows = '';
foreach ($ageData['data'] as $ageGroup => $count) {
    $ageTableRows .= "<tr><td>$ageGroup</td><td>$count</td></tr>";
}
$ageLabels = json_encode($ageData['labels']);
$ageCounts = json_encode(array_values($ageData['data']));

// ----------------- Gender Data -----------------
$genderData = ['labels' => [], 'data' => []];
foreach ($patientsData as $patient) {
    $gender = isset($patient['gender']) && !empty($patient['gender'])
        ? ucfirst(strtolower($patient['gender']))
        : 'Unknown';
    if (isset($genderData['data'][$gender])) $genderData['data'][$gender]++;
    else {
        $genderData['data'][$gender] = 1;
        $genderData['labels'][] = $gender;
    }
}
$genderTableRows = '';
foreach ($genderData['data'] as $gender => $count) {
    $genderTableRows .= "<tr><td>$gender</td><td>$count</td></tr>";
}
$genderLabels = json_encode($genderData['labels']);
$genderCounts = json_encode(array_values($genderData['data']));

function calculateAge($birthdate) {
    $birthdate = str_replace(' / ', '-', $birthdate);
    try {
        $dob = new DateTime($birthdate);
        $now = new DateTime();
        return $dob->diff($now)->y;
    } catch (Exception $e) {
        return 0;
    }
}

// Fetch the doctor's photo from Firebase (default image if none exists)
$doctorRef = $database->getReference('doctor')->orderByChild('email')->equalTo($useremail);
$doctorData = $doctorRef->getValue();

$photo = '../img/user.png';
if ($doctorData) {
    $doctor = reset($doctorData);
    if (!empty($doctor['photo'])) $photo = $doctor['photo'];
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
    <title>Summary</title>

    <style>
        .chart-container { display:flex; flex-direction:column; gap:20px; }
        .row { display:flex; justify-content:space-between; gap:20px; flex-wrap:wrap; }
        .bar-chart { flex:1; min-width:200px; max-width:300px; text-align:center; }
        canvas { width:100%; height:auto; }

        .dashbord-tables{ animation: transitionIn-Y-over 0.5s; }
        .filter-container{ animation: transitionIn-Y-bottom 0.5s; }
        .sub-table{ animation: transitionIn-Y-bottom 0.5s; }

        .bar-charts { display:flex; justify-content:space-around; margin-bottom:20px; }
        .bar-chart { text-align:center; width:30%; }
        .bar-chart canvas { display:block; margin:0 auto; margin-top:30px; }

        .filters button {
            background-color:#a4e2a4;
            color:#027148;
            border:none;
            padding:10px 20px;
            font-size:16px;
            cursor:pointer;
            border-radius:8px;
            margin:5px;
            transition:background-color .3s ease, color .3s ease;
        }
        .filters button:hover { background-color:#4CAF50; color:white; }

        .menu-btn a { display:block; text-decoration:none; padding:1px; width:100%; }
        .menu-btn:hover { background-color:#E8F8E8; }

        /* Desktop default: mobile UI hidden */
        .mobile-header { display:none; }
        #hamburger-menu { display:none; }
        #menu-overlay { display:none; }

        /* ===== MOBILE ONLY FIXES ===== */
        @media screen and (max-width: 768px){

            /* hide the desktop top row (Back/Date) because mobile header exists */
            .desktop-topbar{ display:none !important; }

            /* show mobile header + hamburger */
            .mobile-header{
                display:flex;
                position:fixed;
                top:0; left:0; right:0;
                height:56px;
                background: lightgreen;
                z-index:1100;
                align-items:center;
                justify-content:center;
                font-weight:700;
                color:#000;
                box-shadow:0 2px 10px rgba(0,0,0,0.12);
            }
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
            #hamburger-menu.active .bar:nth-child(1){ transform:rotate(-45deg) translate(-5px, 6px); }
            #hamburger-menu.active .bar:nth-child(2){ opacity:0; }
            #hamburger-menu.active .bar:nth-child(3){ transform:rotate(45deg) translate(-5px, -6px); }

            /* overlay */
            #menu-overlay{
                display:none;
                position:fixed;
                inset:0;
                background:rgba(0,0,0,0.45);
                z-index:1090;
            }
            body.menu-open #menu-overlay{ display:block; }

            /* drawer sidebar */
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
                -webkit-overflow-scrolling: touch;
                display:block;
            }
            body.menu-open .menu{ transform:translateX(0); }

            /* push content down */
            .dash-body{
                margin-top:70px !important;
                margin-left:0 !important;
                padding:0 0 0 90px !important;
            }

            /* Chart full width on mobile (override desktop 30% and max-width) */
            .bar-charts{
                display:flex !important;
                justify-content:center !important;
                align-items:center !important;
                width:100% !important;
                height:auto !important;
                padding:0 !important;
                margin:0 !important;
            }
            .bar-chart{
                width:100% !important;
                max-width:none !important;
                min-width:0 !important;
            }
            #unifiedChart{ width:100% !important; }
            #combinedCanvas{
                width:100% !important;
                height:260px !important;
            }

            /* buttons stacked */
            .filters{
                display:flex !important;
                flex-direction:column !important;
                gap:10px !important;
                align-items:center !important;
                padding:0 !important;
            }
            .filters button{
                width:100% !important;
                margin:0 !important;
                font-size:14px !important;
                padding:10px 12px !important;
            }

            /* table full width */
            .abc.scroll{ width:100% !important; overflow-x:auto !important; }
            .summary-table{ width:100% !important; }
            .summary-table th, .summary-table td{
                font-size:13px;
                padding:10px 8px;
                word-break:break-word;
            }
        }

        @media screen and (max-width: 480px){
            #combinedCanvas{ height:240px !important; }
        }
        
        /* ===================== MOBILE FULL SPACE FIX ===================== */
@media screen and (max-width: 768px){

    /* chart container full width */
    .bar-charts{
        display:flex !important;
        justify-content:center !important;
        align-items:center !important;
        width:100% !important;
        margin:0 !important;
        padding:0 !important;
    }

    /* remove desktop limits */
    .bar-chart{
        width:100% !important;
        max-width:none !important;
        min-width:0 !important;
    }

    /* canvas uses most of the screen */
    #combinedCanvas{
        width:100% !important;
        height:60vh !important;  
        max-height:420px;
    }

    /* filters full width */
    .filters{
        width:100% !important;
        padding:0 !important;
    }
    .filters button{
        width:100% !important;
        margin:0 !important;
        font-size:14px;
    }

    /* table full width */
    .abc.scroll{
        width:100% !important;
        overflow-x:auto;
    }
    .summary-table{
        width:100% !important;
    }

    /* reduce wasted vertical spacing */
    .dash-body{
        padding-bottom:20px !important;
    }
}

/* very small phones */
@media screen and (max-width: 480px){
    #combinedCanvas{
        height:55vh !important;
    }
}

/* ================= MOBILE HEADER (MATCH PATIENT HEALTH RECORDS) ================= */
.mobile-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    height:56px;
    background:lightgreen;
    padding:0 14px;
    position:fixed;
    top:0;
    left:0;
    right:0;
    z-index:1100;
    box-shadow:0 2px 10px rgba(0,0,0,0.12);
}

/* Left spacer (hamburger already positioned fixed) */
.mobile-left{
    width:30px;
}

/* Center title */
.mobile-center{
    flex:1;
    text-align:center;
    font-size:16px;
    font-weight:700;
    color:#000;
}

/* Right date + icon */
.mobile-right{
    display:flex;
    align-items:center;
    gap:8px;
}

.mobile-date{
    font-size:13px;
    font-weight:600;
    color:#000;
}

/* calendar button */
.mobile-calendar-btn{
    background:#eaffea;
    border:none;
    width:32px;
    height:32px;
    border-radius:8px;
    display:flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
}

.mobile-calendar-btn img{
    width:18px;
    height:18px;
}

/* Show only on mobile */
@media screen and (min-width: 769px){
    .mobile-header{ display:none; }
}
@media (max-width: 768px){
  .menu .profile-subtitle{
    font-size: 13px !important;
  }
}

    </style>
</head>

<body>
<div class="mobile-header">

    <!-- Hamburger -->
    <div id="hamburger-menu">
        <div class="bar"></div>
        <div class="bar"></div>
        <div class="bar"></div>
    </div>

    <div class="mobile-center">
        Summary
    </div>

    <div class="mobile-right">
        <div class="mobile-date">
            <?php
            date_default_timezone_set('Asia/Manila');
            echo date('Y-m-d');
            ?>
        </div>
        <button class="mobile-calendar-btn">
            <img src="../img/calendar.svg" alt="Calendar">
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

                <tr class="menu-row"><td class="menu-btn"><a href="index.php" class="non-style-link-menu"><p class="menu-text">Dashboard</p></a></td></tr>
                <tr class="menu-row"><td class="menu-btn"><a href="schedule.php" class="non-style-link-menu"><p class="menu-text">My Sessions</p></a></td></tr>
                <tr class="menu-row"><td class="menu-btn"><a href="bed.php" class="non-style-link-menu"><p class="menu-text">Bed Occupancy</p></a></td></tr>
                <tr class="menu-row"><td class="menu-btn"><a href="patient.php" class="non-style-link-menu"><p class="menu-text">Patient Health Records</p></a></td></tr>
                <tr class="menu-row"><td class="menu-btn menu-active"><a href="summary.php" class="non-style-link-menu non-style-link-menu-active"><p class="menu-text">Summary</p></a></td></tr>
                <tr class="menu-row"><td class="menu-btn"><a href="settings.php" class="non-style-link-menu"><p class="menu-text">Settings</p></a></td></tr>
            </table>
        </div>

        <div class="dash-body" style="margin-top: 15px">
            <table border="0" width="100%" style="border-spacing:0;margin:0;padding:0;margin-top:25px;">
                <tr class="desktop-topbar">
                    <td width="13%">
                        <a href="schedule.php">
                            <button class="login-btn btn-primary-soft btn btn-icon-back" style="padding-top:11px;padding-bottom:11px;margin-left:20px;width:125px">
                                <font class="tn-in-text">Back</font>
                            </button>
                        </a>
                    </td>
                    <td>
                        <p style="font-size:23px;padding-left:12px;font-weight:600;">Summary</p>
                    </td>
                    <td width="15%">
                        <p style="font-size:14px;color:rgb(119,119,119);padding:0;margin:0;text-align:right;">Today's Date</p>
                        <p class="heading-sub12" style="padding:0;margin:0;">
                            <?php
                            date_default_timezone_set('Asia/Manila');
                            echo date('Y-m-d');
                            ?>
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
                       <div class="bar-charts">
                            <div class="bar-chart" id="unifiedChart">
                                <canvas id="combinedCanvas"></canvas>
                            </div>
                        </div>


                        <div class="filters" style="text-align:center; margin-top:20px;">
                            <button onclick="filterChart('generalTrias')">General Trias [City]</button>
                            <button onclick="filterChart('withinCavite')">Within Cavite</button>
                            <button onclick="filterChart('age')">Age</button>
                            <button onclick="filterChart('gender')">Gender</button>
                        </div>

                        <center>
                            <div class="abc scroll">
                                <table class="sub-table scrolldown summary-table" border="0">
                                    <thead>
                                        <tr>
                                            <th class="table-headin">Location / Category</th>
                                            <th class="table-headin">Number of Clients</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tableContent"></tbody>
                                </table>
                            </div>
                        </center>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        const generalTriasLabels = <?php echo $generalTriasLabels; ?>;
        const generalTriasCounts = <?php echo $generalTriasCounts; ?>;
        const withinCaviteLabels  = <?php echo $withinCaviteLabels; ?>;
        const withinCaviteCounts  = <?php echo $withinCaviteCounts; ?>;
        const ageLabels           = <?php echo $ageLabels; ?>;
        const ageCounts           = <?php echo $ageCounts; ?>;
        const genderLabels        = <?php echo $genderLabels; ?>;
        const genderCounts        = <?php echo $genderCounts; ?>;

        const allData = {
            generalTrias: {
                labels: generalTriasLabels,
                data: generalTriasCounts,
                backgroundColor: '#4CAF50',
                label: 'Within General Trias [City]',
                tableRows: `<?php echo $generalTriasTableRows; ?>`
            },
            withinCavite: {
                labels: withinCaviteLabels,
                data: withinCaviteCounts,
                backgroundColor: '#F44336',
                label: 'Within Cavite excluding General Trias',
                tableRows: `<?php echo $withinCaviteTableRows; ?>`
            },
            age: {
                labels: ageLabels,
                data: ageCounts,
                backgroundColor: ['#FF8761', '#FFB7A0'],
                label: 'Age Distribution',
                tableRows: `<?php echo $ageTableRows; ?>`
            },
            gender: {
                labels: genderLabels,
                data: genderCounts,
                backgroundColor: ['#2196F3', '#4CAF50'],
                label: 'Gender Distribution',
                tableRows: `<?php echo $genderTableRows; ?>`
            }
        };

        let currentChart = null;

        window.onload = function() {
            filterChart('generalTrias');
        };

        function filterChart(filterType) {
            const data = allData[filterType];
            updateTable(data.tableRows);
            createChart(data);
        }

        function createChart(data) {
            const ctx = document.getElementById('combinedCanvas').getContext('2d');
            if (currentChart) currentChart.destroy();

            const maxValue = Math.max(...data.data);
            const yAxisMax = Math.ceil(maxValue / 5) * 10;

            currentChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: data.labels,
                    datasets: [{
                        label: data.label,
                        data: data.data,
                        backgroundColor: data.backgroundColor,
                        borderColor: data.backgroundColor,
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false, // ✅ IMPORTANT for mobile height
                    plugins: {
                        legend: { position: 'top' },
                        tooltip: {
                            callbacks: {
                                label: function(tooltipItem) {
                                    return tooltipItem.raw + ' Clients';
                                }
                            }
                        }
                    },
                    scales: {
                        x: { title: { display: true, text: 'Category' } },
                        y: {
                            title: { display: true, text: 'Number of Clients' },
                            beginAtZero: true,
                            max: yAxisMax,
                            ticks: { stepSize: 1 }
                        }
                    }
                }
            });
        }

        function updateTable(rows) {
            document.getElementById('tableContent').innerHTML = rows;
        }

        // Drawer menu
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
