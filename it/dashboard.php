<?php

session_name('sess_it'); 
session_start();

    if(isset($_SESSION["user"])){
        if(($_SESSION["user"])=="" || $_SESSION['usertype']!='it'){
            header("location: ../login.php");
        }else{
            $useremail=$_SESSION["user"];
        }

    }else{
        header("location: ../login.php");
    }

    //import database
    include("../connection.php");

    date_default_timezone_set('Asia/Manila');
    $today = date('Y-m-d');

    try {
        $reference = $database
            ->getReference('it')
            ->orderByChild('email')  
            ->equalTo($useremail)
            ->getSnapshot();

        // Fetch the first result
        $userfetch = $reference->getValue();

        if ($userfetch) {
            // Since Firebase returns an associative array, we take the first item
            foreach($userfetch as $key => $value) {
                $username = $value['name'];
            }
        } else {
            // Handle the case where no user is found
            echo "No user found.";
        }

    } catch (\Kreait\Firebase\Exception\DatabaseException $e) {
        echo "Error querying the database: " . $e->getMessage();
    }

// Get the current date and calculate the next 7 days
$currentDate = new DateTime();
$next7Days = [];
for ($i = 0; $i < 7; $i++) {
    $next7Days[$currentDate->format('Y-m-d')] = 0; // Initialize count for each day
    $currentDate->modify('+1 day');
}

// Fetch data from Firebase
$patientsRef = $database->getReference('patients')->getValue();
$secretariesRef = $database->getReference('admin')->getValue();
$doctorsRef = $database->getReference('doctor')->getValue();
$specialtiesRef = $database->getReference('specialties')->getValue();
$appointmentsRef = $database->getReference('appointment')->getValue();

// Map specialty IDs to names
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

// Process secretaries by specialty
$secretariesBySpecialty = [];
if (!empty($secretariesRef)) {
    foreach ($secretariesRef as $secretary) {
        $specialtyId = $secretary['specialty'] ?? 'Unknown';
        $specialtyName = $specialties[$specialtyId] ?? 'Unknown Specialty';
        $secretariesBySpecialty[$specialtyName] = ($secretariesBySpecialty[$specialtyName] ?? 0) + 1;
    }
}

$doctorsBySpecialty = [];
if (!empty($doctorsRef)) {
    foreach ($doctorsRef as $doctor) {
        $specialtyName = isset($doctor['sname']) ? $doctor['sname'] : 'Unknown Specialty';
        $doctorsBySpecialty[$specialtyName] = ($doctorsBySpecialty[$specialtyName] ?? 0) + 1;
    }
}

// Process appointments
if (!empty($appointmentsRef)) {
    foreach ($appointmentsRef as $appointment) {
        if (isset($appointment['scheduledate'])) {
            $scheduledDate = $appointment['scheduledate'];
            if (array_key_exists($scheduledDate, $next7Days)) {
                $next7Days[$scheduledDate]++;
            }
        }
    }
}

// Convert processed data to JSON for JavaScript
$patientsByCityJson = json_encode($patientsByCity);
$secretariesBySpecialtyJson = json_encode($secretariesBySpecialty);
$doctorsBySpecialtyJson = json_encode($doctorsBySpecialty);
$appointmentsDataJson = json_encode($next7Days);



// Fetch counts from Firebase
try {
    // Count Doctors
    $doctorsRef = $database->getReference('doctor');
    $doctorsData = $doctorsRef->getValue();
    $doctorsCount = $doctorsData ? count($doctorsData) : 0;

    // Count Nurses
    $nursesRef = $database->getReference('admin');
    $nursesData = $nursesRef->getValue();
    $nursesCount = $nursesData ? count($nursesData) : 0;

    // Count Patients
    $patientsRef = $database->getReference('patients');
    $patientsData = $patientsRef->getValue();
    $patientsCount = $patientsData ? count($patientsData) : 0;

    // Count Pharmacists
    $pharmacistsRef = $database->getReference('pharmacists');
    $pharmacistsData = $pharmacistsRef->getValue();
    $pharmacistsCount = $pharmacistsData ? count($pharmacistsData) : 0;
} catch (Exception $e) {
    // Handle Firebase exceptions
    $doctorsCount = $nursesCount = $patientsCount = $pharmacistsCount = 0;
    error_log("Error fetching data from Firebase: " . $e->getMessage());
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
    <link rel="stylesheet" href="dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

    <title>IT</title>
<style>
body{background:#F5F5F5;}
.popup{animation:transitionIn-Y-bottom .5s;}
.sub-table{animation:transitionIn-Y-bottom .5s;}
.popup .close{font-size:30px;color:#000;text-decoration:none;}
.overlay{display:none;position:fixed;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,.7);z-index:1000;}
.overlay .popup{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;padding:20px;border-radius:8px;}

/* Mobile Header  */
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

.overview-container{
  display:grid;
  grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
  gap:15px;
  padding:25px;
  margin:50px auto;
  max-width:100%;
  box-sizing:border-box;
}
.overview-box{
  background-color:#f8f9fa;
  padding:15px;
  border-radius:8px;
  box-shadow:0 2px 4px rgba(0,0,0,.1);
  text-align:center;
  border:1px solid #e0e0e0;
}
.overview-box h3{font-size:20px;color:#333;margin:10px 0;}
.overview-box p{font-size:16px;color:#555;}
.overview-box i{font-size:35px;color:lightgreen;}

/* Header Styling */
.header{
  background-color:lightgreen;
  color:white;
  padding:15px;
  border-radius:5px;
  box-shadow:0 2px 5px rgba(0,0,0,.2);
  margin-bottom:20px;
  text-align:center;
}

/* Quick Links and Editable Notes styling */
.quick-links,.notes{
  background-color:#f2f2f2;
  padding:10px;
  margin-top:20px;
  border-radius:8px;
  width:100%;
  box-shadow:0 2px 5px rgba(0,0,0,.1);
}
.quick-links h3,.notes h3{
  color:#e67e22;
  font-size:18px;
  margin-bottom:10px;
}
.notes{margin-right:15px;}
.link-btn{
  display:block;
  width:100%;
  background-color:#3498db;
  color:#fff;
  padding:8px;
  text-align:left;
  margin:5px 0;
  text-decoration:none;
  border-radius:5px;
}
.link-btn:hover{background-color:#2980b9;}
.editable-notes{
  width:100%;
  border:1px solid #ccc;
  border-radius:5px;
  padding:8px;
  resize:none;
  height:400px;
}

.dashboard-container{
  display:grid;
  grid-template-columns:repeat(6,1fr);
  gap:20px;
  box-sizing:border-box;
}
.dashboard-card{
  background-color:#fff;
  border:1px solid #ddd;
  border-radius:8px;
  padding:30px;
  text-align:center;
  transition:transform .2s ease;
  cursor:pointer;
  box-shadow:0 2px 5px rgba(0,0,0,.1);
}
.dashboard-card:hover{transform:scale(1.05);}
.dashboard-icon{font-size:70px;margin-bottom:10px;color:#32CD32;}
.dashboard-card a{text-decoration:none;color:inherit;}
.dashboard-label{font-size:16px;font-weight:bold;color:#333;}

/* Flex container for Quick Links and Editable Notes */
.sidebar-container{
  display:flex;
  justify-content:space-between;
  margin-top:20px;
}
.sidebar-container>div{
  margin-left:20px;
  width:48%;
}

.header-date{
  font-size:16px;
  font-weight:bold;
  color:#ffffff;
  text-align:right;
  margin:0 20px;
}

.dashboard-items{
  display:flex;
  align-items:center;
  justify-content:space-between;
  padding:25px;
  border:1px solid #ccc;
  border-radius:5px;
  background:white;
  height:150px;
}

.chart-card{
  background-color:#f8f9fa;
  padding:15px;
  border-radius:8px;
  box-shadow:0 2px 4px rgba(0,0,0,.1);
  border:1px solid #e0e0e0;
  text-align:center;
}
.chart-card h3{
  font-size:16px;
  color:#333;
  margin-bottom:10px;
}
.chart-card canvas{
  max-height:300px;
  margin:0 auto;
}
.dashboard-content{gap:10px;}

.header{
  display:flex;
  justify-content:center;
  align-items:center;
  background-color:#fff;
  padding:10px 20px;
  box-shadow:0 2px 4px rgba(0,0,0,.1);
}
.header-container{
  display:flex;
  align-items:center;
  justify-content:space-between;
  width:100%;
  max-width:1200px;
  margin-bottom:20px;
}
.back-button{
  background-color:#e6f9ee;
  color:#007bff;
  border:none;
  border-radius:5px;
  padding:8px 15px;
  font-size:14px;
  display:flex;
  align-items:center;
  gap:5px;
  cursor:pointer;
}
.header-title{font-size:18px;font-weight:bold;color:#000;margin:0;}
.header-search{display:flex;align-items:center;gap:5px;}
.header-search input{
  width:200px;
  padding:8px;
  border:1px solid #ccc;
  border-radius:5px;
  font-size:14px;
}
.search-button{
  background-color:#32CD32;
  color:#fff;
  border:none;
  padding:8px 15px;
  border-radius:5px;
  font-size:14px;
  cursor:pointer;
  transition:background-color .3s;
}
.search-button:hover{background-color:#28a745;}

.search-input{
  width:250px;
  padding:8px 12px;
  border:1px solid #ddd;
  border-radius:5px;
  font-size:14px;
  outline:none;
  transition:border-color .3s;
}
.search-input:focus{border-color:#32CD32;}

.header-date{
  display:flex;
  align-items:center;
  gap:8px;
  font-size:14px;
  color:#000;
}
.header-date .date{font-weight:bold;}
.header-date i{color:#007bff;}

.menu-btn a{
  display:block;
  text-decoration:none;
  padding:1px;
  width:100%;
}
.menu-btn:hover{background-color:#E8F8E8;}

/* =========================
   MOBILE FIX – IT DASHBOARD
   ========================= */
#menu-overlay{display:none;}

@media (max-width:768px){

  html,body{width:100%;overflow-x:hidden;}

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
  .mobile-date{
    font-size:12px;
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

  /* Page spacing under fixed header */
  .container{
    padding-top:56px !important;
    width:100% !important;
    max-width:100% !important;
  }

  /* Hide desktop header row */
  .dash-body > table{margin-top:0 !important;}
  .dash-body > table > tbody > tr:first-child{display:none !important;}

  /* Full width content */
  .dash-body{
    margin:0 !important;
    padding:12px !important;
    width:100% !important;
  }

  /* Drawer sidebar */
  .menu{
    display:block !important;
    position:fixed !important;
    top:56px !important;
    left:-280px !important;
    width:280px !important;
    max-width:86vw !important;
    height:calc(100vh - 56px) !important;
    background:lightgreen !important;
    z-index:10040 !important;
    overflow-y:auto !important;
    -webkit-overflow-scrolling:touch !important;
    transition:left .25s ease !important;
    box-shadow:10px 0 30px rgba(0,0,0,.12) !important;
  }
  .menu.active{left:0 !important;}

  /* Overlay */
  #menu-overlay{
    display:block !important;
    position:fixed !important;
    inset:0 !important;
    background:rgba(0,0,0,.35) !important;
    opacity:0 !important;
    pointer-events:none !important;
    transition:opacity .2s ease !important;
    z-index:10030 !important;
  }
  body.menu-open #menu-overlay{
    opacity:1 !important;
    pointer-events:auto !important;
  }
  body.menu-open{overflow:hidden;}

  /* Reduce big margins so it uses space */
  .overview-container{
    padding:12px !important;
    margin:12px 0 !important;
    gap:10px !important;
  }
  .dashboard-content{
    margin:12px 0 !important;
    padding:0 12px !important;
    gap:12px !important;
  }

  /* Full width cards/charts */
  .chart-card{width:100% !important;max-width:100% !important;}
  .chart-card canvas{width:100% !important;height:260px !important;}
  .overview-box{width:100% !important;}

  /* Tighten big title if present */
  .dash-body p[style*="font-size: 30px"]{
    font-size:22px !important;
    padding-left:0 !important;
    margin:0 0 10px 0 !important;
    text-align:left !important;
  }

  /* IMPORTANT: remove content shifting (utilize all spaces) */
  .menu.active + .dash-body{margin-left:0 !important;}
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
        <div class="mobile-date"><?php echo $today; ?></div>
        <button class="mobile-calendar-btn" type="button" aria-label="Calendar">
            <img src="../img/calendar.svg" alt="">
        </button>
    </div>
</div>

<!-- Overlay -->
<div id="menu-overlay"></div>

    <div class="container">
        <!-- Sidebar Menu -->
        <div class="menu">
            <table class="menu-container" border="0">
                <tr>
                    <td style="padding:10px" colspan="2">
                        <table border="0" class="profile-container">
                            <tr>
                                <td width="30%" style="padding-left:20px" >
                                <p class="profile-title"><?php echo substr($username,0,50) ?></p>
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
                            <a href="dashboard.php" class="non-style-link-menu non-style-link-menu-active">
                                <p class="menu-text">Dashboard</p>
                            </a>
                        </td>
                    </tr>
                    <tr class="menu-row">
                        <td class="menu-btn ">
                            <a href="logs.php" class="non-style-link-menu">
                                <p class="menu-text">Logs</p>
                            </a>
                        </td>
                    </tr>
                    <tr class="menu-row">
                        <td class="menu-btn ">
                            <a href="clients.php" class="non-style-link-menu">
                                <p class="menu-text">Accounts</p>
                            </a>
                        </td>
                    </tr>
                    <tr class="menu-row">
                        <td class="menu-btn ">
                            <a href="cms.php" class="non-style-link-menu">
                                <p class="menu-text">CMS</p>
                            </a>
                        </td>
                    </tr>
                    
            </table>
        </div>

        
        <div class="dash-body" style="margin-top: 15px">
            <table border="0" width="100%" style=" border-spacing: 0;margin:0;padding:0;margin-top:25px; ">
                <tr >
                    <td>
                        <p style="font-size: 30px;padding-left: 12px; text-align: left;font-weight: 600;">Dashboard</p>
                                           
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
            </table>
            
            <div class="overview-container">
    <div class="overview-box">
        <h3>Doctors</h3>
        <p><?php echo $doctorsCount; ?></p>
        <i class="fas fa-user-md"></i>
    </div>
    <div class="overview-box">
        <h3>Admin</h3>
        <p><?php echo $nursesCount; ?></p>
        <i class="fas fa-user-nurse"></i>
    </div>
    <div class="overview-box">
        <h3>Patients</h3>
        <p><?php echo $patientsCount; ?></p>
        <i class="fas fa-heartbeat"></i>
    </div>
</div>


<div class="dashboard-content" style="display: flex; flex-wrap: wrap; gap: 20px; justify-content: center;margin: 50px 25px 30px 25px; ">
    <div class="chart-card">
        <h3>Patients by City</h3>
        <canvas id="patientsChart"></canvas>
    </div>
    <div class="chart-card">
        <h3>Secretaries per Department</h3>
        <canvas id="secretariesChart"></canvas>
    </div>
    <div class="chart-card">
        <h3>Doctors per Department</h3>
        <canvas id="doctorsChart"></canvas>
    </div>
    
</div>

</div>
</div>
    </div>
         

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
    // Get data from PHP
    const patientsByCity = <?php echo $patientsByCityJson; ?>;
    const secretariesBySpecialty = <?php echo $secretariesBySpecialtyJson; ?>;
    const doctorsBySpecialty = <?php echo $doctorsBySpecialtyJson; ?>;
    const appointmentsData = <?php echo $appointmentsDataJson; ?>; 

    function renderChart(chartId, label, data) {
    const ctx = document.getElementById(chartId).getContext('2d');
    const dataValues = Object.values(data);
    const maxValue = Math.max(...dataValues); // Get the highest value in the dataset
    const roundedMax = Math.ceil(maxValue / 5) * 10; // Round up to the nearest multiple of 10

    const colors = [
        'rgba(255, 99, 132)',  // Red
        'rgba(54, 162, 235)',  // Blue
        'rgba(255, 206, 86)',  // Yellow
        'rgba(75, 192, 192)',  // Teal
        'rgba(153, 102, 255)', // Purple
        'rgba(255, 159, 64)',  // Orange
        'rgba(0, 204, 0)'      // Green
    ];

    const borderColors = [
        'rgba(255, 99, 132)',  // Red
        'rgba(54, 162, 235)',  // Blue
        'rgba(255, 206, 86)',  // Yellow
        'rgba(75, 192, 192)',  // Teal
        'rgba(153, 102, 255)', // Purple
        'rgba(255, 159, 64 )',  // Orange
        'rgba(0, 204, 0 )'      // Green
    ];

    new Chart(ctx, {
        type: 'bar', // Bar chart type
        data: {
            labels: Object.keys(data), // Labels on the y-axis
            datasets: [{
                label: label,
                data: dataValues,
                backgroundColor: Object.keys(data).map((_, index) => colors[index % colors.length]),
                borderColor: Object.keys(data).map((_, index) => borderColors[index % borderColors.length]),
                borderWidth: 1
            }]
        },
        options: {
            indexAxis: 'y', // Make the bars horizontal
            responsive: true,
            maintainAspectRatio: false, // Ensures the chart adjusts to the container
            plugins: {
                legend: {
                    display: false // Hide legend for a cleaner look
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1,
                        max: roundedMax // Dynamically set the max value
                    }
                },
                y: {
                    ticks: {
                        font: {
                            size: 10 // Adjust font size for the y-axis labels
                        }
                    }
                }
            }
        }
    });
}


      // Render all charts
    renderChart('patientsChart', 'Patients by City', patientsByCity);
    renderChart('secretariesChart', 'Secretaries per Department', secretariesBySpecialty);
    renderChart('doctorsChart', 'Doctors by Specialty', doctorsBySpecialty);
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

    // Initialize notes on page load
    window.onload = () => {
        loadNotes();

        // Attach event listener for saving notes
        const notesTextarea = document.getElementById('notes');
        if (notesTextarea) {
            notesTextarea.addEventListener('input', saveNotes);
        }
    };

  const menuButton = document.getElementById('hamburger-menu');
  const menu = document.querySelector('.menu');
  const overlay = document.getElementById('menu-overlay');

  function openMenu(){
    menu.classList.add('active');
    menuButton.classList.add('active');
    document.body.classList.add('menu-open');
  }

  function closeMenu(){
    menu.classList.remove('active');
    menuButton.classList.remove('active');
    document.body.classList.remove('menu-open');
  }

  // Toggle
  menuButton.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    if (menu.classList.contains('active')) closeMenu();
    else openMenu();
  });

  // Click overlay to close
  overlay.addEventListener('click', closeMenu);

  // Optional: close on ESC
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeMenu();
  });
</script>

</body>
</html>
