<?php
session_name('sess_admin'); session_start();

// Check if the user is logged in and is a patient
if(isset($_SESSION["user"])){
    if(($_SESSION["user"]) == "" || $_SESSION['usertype'] != 'a'){
        header("location: ../login.php");
    } else {
        $useremail = $_SESSION["user"];
    }
} else {
    header("location: ../login.php");
}

// Import database
include("../connection.php");

date_default_timezone_set('Asia/Manila');
$today = date('Y-m-d');

// Firebase query to get admin data based on email
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

// Get patients data
$patientsRef = $database->getReference('patients');
$patientsData = $patientsRef->getSnapshot()->getValue();


if (!$patientsData) {
    $patientsData = array();
}

// ----------------- General Trias Data (City) -----------------
$generalTriasData = [
    'labels' => ['General Trias'],
    'data' => [0]
];
$generalTriasTableRows = '';

foreach ($patientsData as $patient) {
    $city = $patient['city'] ?? null; 
    if ($city === 'General Trias') {
        $generalTriasData['data'][0]++;
    }
}

// Generate a single row for the table
$generalTriasTableRows = "<tr><td>General Trias</td><td>{$generalTriasData['data'][0]}</td></tr>";

// Convert to JSON for charts
$generalTriasLabels = json_encode($generalTriasData['labels']);
$generalTriasCounts = json_encode($generalTriasData['data']);

// ----------------- General Trias Barangay Data -----------------
$barangayData = [
    'labels' => [],
    'data' => []
];
$barangayTableRows = '';

foreach ($patientsData as $patient) {
    $city = $patient['city'] ?? null; 
    if ($city === 'General Trias') {
        $barangay = $patient['barangay'] ?? 'Unknown'; 
        if (isset($barangayData['data'][$barangay])) {
            $barangayData['data'][$barangay]++;
        } else {
            $barangayData['data'][$barangay] = 1;
            $barangayData['labels'][] = $barangay;
        }
    }
}

// Generate table rows for unique barangays
foreach ($barangayData['data'] as $barangay => $count) {
    $barangayTableRows .= "<tr><td>$barangay</td><td>$count</td></tr>";
}

// Convert to JSON for charts
$barangayLabels = json_encode($barangayData['labels']);
$barangayCounts = json_encode(array_values($barangayData['data']));

// ----------------- Within Cavite Data (Excluding General Trias) -----------------
$withinCaviteData = [
    'labels' => [],
    'data' => []
];
$withinCaviteTableRows = '';

foreach ($patientsData as $patient) {
    // Use null coalescing to avoid undefined keys and exclude General Trias
    if ((($patient['province'] ?? '') === 'Cavite') && (($patient['city'] ?? '') !== 'General Trias')) {
        $city = $patient['city'] ?? 'Unknown';
        if (isset($withinCaviteData['data'][$city])) {
            $withinCaviteData['data'][$city]++;
        } else {
            $withinCaviteData['data'][$city] = 1;
            $withinCaviteData['labels'][] = $city;
        }
    }
}

// Generate table rows for unique cities within Cavite
foreach ($withinCaviteData['data'] as $city => $count) {
    $withinCaviteTableRows .= "<tr><td>$city</td><td>$count</td></tr>";
}

// Convert to JSON for charts
$withinCaviteLabels = json_encode($withinCaviteData['labels']);
$withinCaviteCounts = json_encode(array_values($withinCaviteData['data']));

// ----------------- Outside Cavite Data -----------------
$outsideCaviteData = [
    'labels' => [],
    'data' => []
];
$outsideCaviteTableRows = '';

foreach ($patientsData as $patient) {
    if (($patient['province'] ?? '') !== 'Cavite') {
        $city = $patient['city'] ?? 'Unknown';
        if (isset($outsideCaviteData['data'][$city])) {
            $outsideCaviteData['data'][$city]++;
        } else {
            $outsideCaviteData['data'][$city] = 1;
            $outsideCaviteData['labels'][] = $city;
        }
    }
}

// Generate table rows for unique cities outside Cavite
foreach ($outsideCaviteData['data'] as $city => $count) {
    $outsideCaviteTableRows .= "<tr><td>$city</td><td>$count</td></tr>";
}

// Convert to JSON for charts
$outsideCaviteLabels = json_encode($outsideCaviteData['labels']);
$outsideCaviteCounts = json_encode(array_values($outsideCaviteData['data']));

// ----------------- Cases Data -----------------
$caseData = [
    'labels' => [],
    'data' => []
];
$caseTableRows = '';

$conditionsRef = $database->getReference('conditions');

foreach ($patientsData as $patientId => $patient) {
    if (isset($patient['cases']) && is_array($patient['cases'])) {
        foreach ($patient['cases'] as $caseId => $case) {
            $conditionId = $case['condition_id'] ?? null;
            if ($conditionId) {
                $condition = $conditionsRef->getChild($conditionId)->getValue();
                if ($condition && isset($condition['condition_name'])) {
                    $caseName = $condition['condition_name'];
                    if (isset($caseData['data'][$caseName])) {
                        $caseData['data'][$caseName]++;
                    } else {
                        $caseData['data'][$caseName] = 1;
                        $caseData['labels'][] = $caseName;
                    }
                }
            }
        }
    }
}

// Generate table rows for unique cases
foreach ($caseData['data'] as $caseName => $count) {
    $caseTableRows .= "<tr><td>$caseName</td><td>$count</td></tr>";
}

// Convert to JSON for charts
$caseLabels = json_encode($caseData['labels']);
$caseCounts = json_encode(array_values($caseData['data']));

// ----------------- Age Groups Data -----------------
$ageData = [
    'labels' => [], 
    'data' => []    
];
$ageTableRows = '';

foreach ($patientsData as $patient) {
    $dob = $patient['dob'] ?? ''; 
    $age = calculateAge($dob);
    if ($age < 17) {
        $ageGroup = 'Under 17';
    } else {
        $ageGroup = '17 and above';
    }
    if (isset($ageData['data'][$ageGroup])) {
        $ageData['data'][$ageGroup]++;
    } else {
        $ageData['data'][$ageGroup] = 1;
        $ageData['labels'][] = $ageGroup;
    }
}

foreach ($ageData['data'] as $ageGroup => $count) {
    $ageTableRows .= "<tr><td>$ageGroup</td><td>$count</td></tr>";
}

$ageLabels = json_encode($ageData['labels']);
$ageCounts = json_encode(array_values($ageData['data']));


$genderData = [
    'labels' => [],
    'data' => []
];
$genderTableRows = '';

foreach ($patientsData as $patient) {
    $gender = isset($patient['gender']) && !empty($patient['gender']) 
        ? ucfirst(strtolower($patient['gender'])) 
        : 'Unknown';
    if (isset($genderData['data'][$gender])) {
        $genderData['data'][$gender]++;
    } else {
        $genderData['data'][$gender] = 1;
        $genderData['labels'][] = $gender;
    }
}

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
        
    <title>Dashboard</title>
   <style>
  .chart-container{
    display:flex;
    flex-direction:column;
    gap:20px;
  }

  .row{
    display:flex;
    justify-content:space-between;
    gap:20px;
    flex-wrap:wrap;
  }

  canvas{
    width:100%;
    height:auto;
  }

  .dashbord-tables{ animation: transitionIn-Y-over 0.5s; }
  .filter-container{ animation: transitionIn-Y-bottom 0.5s; }
  .sub-table{ animation: transitionIn-Y-bottom 0.5s; }

  .bar-charts{
    display:flex;
    justify-content:space-around;
    margin-bottom:20px;
  }

  .bar-chart{
    text-align:center;
    width:30%;
  }

  .bar-chart canvas{
    display:block;
    margin:0 auto;
    margin-top:30px;
  }

  /* Mobile Header */
  .mobile-header{
    display:none;
    background-color:lightgreen;
    color:white;
    padding:15px;
    text-align:center;
    font-size:18px;
    position:fixed;
    top:0;
    width:100%;
    z-index:1002;
  }

  .popup .close{
    font-size:30px;
    color:#000;
    text-decoration:none;
  }

  /* Hamburger Menu */
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
    background-color:#333;
    transition:all 0.4s ease;
  }

  #hamburger-menu.active .bar:nth-child(1){
    transform:rotate(-45deg) translate(-5px, 5px);
  }
  #hamburger-menu.active .bar:nth-child(2){
    opacity:0;
  }
  #hamburger-menu.active .bar:nth-child(3){
    transform:rotate(45deg) translate(-5px, -5px);
  }

  .filters button{
    background-color:#a4e2a4;
    color:#027148;
    border:none;
    padding:10px 20px;
    font-size:16px;
    cursor:pointer;
    border-radius:8px;
    margin:5px;
    transition:background-color 0.3s ease, color 0.3s ease;
  }
  .filters button:hover{
    background-color:#4CAF50;
    color:white;
  }

  .menu-btn a{
    display:block;
    text-decoration:none;
    padding:1px;
    width:100%;
  }
  .menu-btn:hover{
    background-color:#E8F8E8;
  }

  /* =========================
     MOBILE FIX (SUMMARY PAGE)
     Put at VERY BOTTOM
     ========================= */
  @media (max-width: 768px){

    /* real mobile header bar */
    .mobile-header{
      display:flex !important;
      position:fixed !important;
      top:0; left:0; right:0;
      height:56px;
      background:lightgreen;
      z-index:5000;
      align-items:center;
      justify-content:space-between;
      padding:0 12px;
      box-shadow:0 2px 10px rgba(0,0,0,.12);
    }

    /* hamburger INSIDE header (not fixed) */
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

    /* title centered */
    .m-title{
      flex:1;
      text-align:center;
      font-weight:700;
      font-size:16px;
      color:#000;
      padding:0 10px;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
    }

    /* right side (date + calendar) */
    .m-right{
      display:flex;
      align-items:center;
      gap:10px;
    }
    .m-date{
      font-size:13px;
      font-weight:600;
      color:#000;
      white-space:nowrap;
    }
    .m-cal-btn{
      width:34px;
      height:34px;
      border-radius:10px;
      border:none;
      background:rgba(255, 255, 255, 0);
      display:flex;
      align-items:center;
      justify-content:center;
      cursor:pointer;
      padding:0;
    }
    .m-cal-btn img{
      width:18px;
      height:18px;
      display:block;
    }

    /* give the page space under fixed header */
    .container{
      padding-top:56px !important;
    }

    /* hide the DESKTOP header row */
    .dash-body > table > tbody > tr:first-child{
      display:none !important;
    }

    /* remove wasted gaps */
    .dash-body{
      margin-top:0 !important;
      padding:12px !important;
      width:100% !important;
      margin-left:0 !important;
    }
    .dash-body > table{
      margin-top:0 !important;
    }

    /* chart better fit */
    .bar-charts{
      height:auto !important;
      padding:0 !important;
    }
    #unifiedChart{
      width:100% !important;
      height:auto !important;
    }
    #combinedCanvas{
      width:100% !important;
      height:320px !important;
    }

    /* filters full width */
    .filters{
      display:flex;
      flex-wrap:wrap;
      gap:8px;
      justify-content:center;
      padding:10px 0 0;
    }
    .filters button{
      width:48%;
      max-width:220px;
    }

    /* table container full width */
    .sub-table.scrolldown{
      width:100% !important;
      min-width:0 !important;
    }

    /* sidebar drawer */
    .menu{
      display:block !important;
      position:fixed !important;
      top:56px !important;
      left:-270px;
      width:270px;
      height:calc(100vh - 56px);
      background:lightgreen;
      transition:left .25s ease;
      z-index:6000;
      overflow-y:auto;
    }
    .menu.active{
      left:0;
    }

    /* overlay behind drawer */
    #menu-overlay{
      display:block;
      position:fixed;
      inset:0;
      background:rgba(0,0,0,.35);
      opacity:0;
      pointer-events:none;
      transition:opacity .2s ease;
      z-index:5500;
    }
    body.menu-open #menu-overlay{
      opacity:1;
      pointer-events:auto;
    }

    body.menu-open{
      overflow:hidden;
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
  <div id="hamburger-menu">
      <div class="bar"></div>
      <div class="bar"></div>
      <div class="bar"></div>
  </div>

  <div class="m-title" id="mobileTitle">Summary</div>

  <div class="m-right">
    <div class="m-date" id="mobileDate"><?php echo $today; ?></div>
    <button class="m-cal-btn" type="button">
      <img src="../img/calendar.svg" alt="calendar">
    </button>
  </div>
</div>

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
                        <td class="menu-btn ">
                            <a href="patient.php" class="non-style-link-menu">
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
                        <td class="menu-btn menu-active">
                            <a href="summary.php" class="non-style-link-menu non-style-link-menu-active">
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
	
		<div class="dash-body" style="margin-top: 15px">
            <table border="0" width="100%" style=" border-spacing: 0;margin:0;padding:0;margin-top:25px; ">
                <tr >
                    <td width="13%" >
                    <a href="schedule.php" ><button  class="login-btn btn-primary-soft btn btn-icon-back"  style="padding-top:11px;padding-bottom:11px;margin-left:20px;width:125px"><font class="tn-in-text">Back</font></button></a>
                    </td>
                    <td>
                        <p style="font-size: 23px;padding-left:12px;font-weight: 600;">Summary</p>
                    </td>
                    <td width="15%">
                        <p style="font-size: 14px;color: rgb(119, 119, 119);padding: 0;margin: 0;text-align: right;">
                            Today's Date
                        </p>
                        <p class="heading-sub12" style="padding: 0;margin: 0;">
                            <?php 
                        echo $today;
                        ?>
                        </p>
                    </td>
                    <td width="10%">
                        <button  class="btn-label"  style="display: flex;justify-content: center;align-items: center;"><img src="../img/calendar.svg" width="100%"></button>
                    </td>
                </tr>
				
				<tr>
    <td colspan="4">
    <div class="bar-charts" style="display: flex; justify-content: center; align-items: center; width: 100%; height: 500px;">
        <div class="bar-chart" id="unifiedChart" style="width: 90%; height: 100%;">
            <canvas id="combinedCanvas" style="width: 100%; height: 100%;"></canvas>
        </div>
    </div>

          <!-- Filter Buttons -->
          <div class="filters" style="text-align: center; margin-top: 20px;">
            <button onclick="filterChart('generalTrias')">General Trias [City]</button>
            <button onclick="filterChart('withinCavite')">Within Cavite</button>
            <button onclick="filterChart('age')">Age</button>
            <button onclick="filterChart('gender')">Gender</button>
        </div>

        <center>
            <div class="abc scroll">
            <table width="50%" class="sub-table scrolldown" border="0">
            <thead>
                <tr>
                    <th class="table-headin">Location / Category</th>
                    <th class="table-headin">Number of Clients</th>
                </tr>
            </thead>
            <tbody id="tableContent">
                <!-- Table rows will be dynamically updated -->
            </tbody>
        </table>


            </div>
        </center>
    </td>
</tr>
<script  src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
        const generalTriasLabels = <?php echo $generalTriasLabels; ?>;
        const generalTriasCounts = <?php echo $generalTriasCounts; ?>;
        const barangayLabels = <?php echo $barangayLabels; ?>;
        const barangayCounts = <?php echo $barangayCounts; ?>;
        const withinCaviteLabels = <?php echo $withinCaviteLabels; ?>;
        const withinCaviteCounts = <?php echo $withinCaviteCounts; ?>;
        const outsideCaviteLabels = <?php echo $outsideCaviteLabels; ?>;
        const outsideCaviteCounts = <?php echo $outsideCaviteCounts; ?>;
        const caseLabels = <?php echo $caseLabels; ?>;
        const caseCounts = <?php echo $caseCounts; ?>;
        const ageLabels = <?php echo $ageLabels; ?>;
        const ageCounts = <?php echo $ageCounts; ?>;
        const genderLabels = <?php echo $genderLabels; ?>;
        const genderCounts = <?php echo $genderCounts; ?>;
        
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

        window.onload = function() {
            filterChart('generalTrias'); 
        };

        let currentChart = null;

        function filterChart(filterType) {
            const data = allData[filterType];
            updateTable(data.tableRows);
            createChart(data);
        }

        function createChart(data) {
            const ctx = document.getElementById('combinedCanvas').getContext('2d');
            if (currentChart) {
                currentChart.destroy();
            }
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
                        borderWidth: 1,
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        tooltip: {
                            callbacks: {
                                label: function(tooltipItem) {
                                    return tooltipItem.raw + ' Clients';
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            title: {
                                display: true,
                                text: 'Category'
                            }
                        },
                        y: {
                            title: {
                                display: true,
                                text: 'Number of Clients'
                            },
                            beginAtZero: true,
                            max: yAxisMax,
                            ticks: {
                                stepSize: 1,
                                callback: function(value) {
                                    return value;
                                }
                            }
                        }
                    }
                }
            });
        }

        function updateTable(rows) {
            const tableContent = document.getElementById('tableContent');
            tableContent.innerHTML = rows;
        }


document.addEventListener('DOMContentLoaded', () => {
  const hamburgerMenu = document.getElementById('hamburger-menu');
  const menu = document.querySelector('.menu');
  const overlay = document.getElementById('menu-overlay');

  if (!hamburgerMenu || !menu) return;

  function openMenu(){
    hamburgerMenu.classList.add('active');
    menu.classList.add('active');
    document.body.classList.add('menu-open');
  }

  function closeMenu(){
    hamburgerMenu.classList.remove('active');
    menu.classList.remove('active');
    document.body.classList.remove('menu-open');
  }

  function toggleMenu(){
    if (menu.classList.contains('active')) closeMenu();
    else openMenu();
  }

  hamburgerMenu.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    toggleMenu();
  });

  if (overlay) {
    overlay.addEventListener('click', closeMenu);
  }

  // optional: close on ESC
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeMenu();
  });
});

    </script>



	</body>
</html>