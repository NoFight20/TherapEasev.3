<?php
session_name('sess_admin'); session_start();

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
$todayHeader = date('Y-m-d');

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
    <link rel="stylesheet" href="../css/hamburger.css">
    <link rel="stylesheet" href="patient.css">

    <title>Bed Occupancy</title>
<style>
.overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.6);
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 999;
}

.overlay-content {
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    background: white;
    padding: 20px;
    border-radius: 10px;
    width: 400px;
    max-width: 90%;
    box-shadow: 0px 5px 15px rgba(0, 0, 0, 0.3);
}

#addPatientForm input {
    width: 100%;
    height: 30px;
    margin-bottom: 10px;
}

.overlay-btn {
    background-color: #28a745;
    border: none;
    color: white;
    border-radius: 5px;
    cursor: pointer;
    padding: 10px;
    margin: 5px;
}

.cancel-btn {
    background-color: #dc3545;
}

.hidden {
    display: none;
}

#patientDetailsForm label{
    margin-top: 30px;
}

.overlay-btn.cancel-btn {
    background-color: #ff0000; 
}

.overlay-btn:hover {
    opacity: 0.9;
}

.hidden {
    display: none;
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
.tabs {
    display: flex;
    justify-content: center;
    margin: 20px 0;
    gap: 10px;
}

.tab-btn {
    padding: 10px 20px;
    border: none;
    background-color: #f4f4f4;
    color: #333;
    cursor: pointer;
    border-radius: 5px;
    font-size: 14px;
    font-weight: bold;
}

.tab-btn:hover {
    background-color: #ddd;
}

.tab-btn.active {
    background-color: #4CAF50;
    color: white;
}

.bed-grid {
    cursor: pointer;
    z-index: 10; 
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
    gap: 15px;
    margin: 20px auto;
    max-width: 800px;
}



.bed:hover .tooltip {
    display: block;
}

.tooltip {
    position: absolute;
    bottom: 110%;
    left: 50%;
    transform: translateX(-50%);
    background-color: #fff;
    color: #000;
    padding: 10px;
    border-radius: 5px;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
    font-size: 12px;
    z-index: 10;
    display: none;
    width: 220px;
    text-align: left;
}

.tooltip::after {
    content: '';
    position: absolute;
    top: 100%;
    left: 50%;
    transform: translateX(-50%);
    border-width: 5px;
    border-style: solid;
    border-color: #fff transparent transparent transparent;
}

.bed-grid {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.room-header h3, h4 {
    text-align: center;
    margin-bottom: 20px;
}

.beds-container {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 20px;
}

.bed-card {
    background: #fff;
    border: 1px solid #ccc;
    border-radius: 10px;
    padding: 15px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
}

.bed-card.in-use {
    border-color:rgb(255, 18, 18);
}

.bed-card.reserved {
    border-color: orange;
}

.bed-card.empty {
    border-color: #4CAF50;
}

.bed-icon {
    width: 50px;
    height: 50px;
    background: currentColor;
    margin-bottom: 10px;
}

.bed-card.in-use .bed-icon {
    color:rgb(255, 18, 18);
}

.bed-card.reserved .bed-icon {
    color: orange;
}

.bed-card.empty .bed-icon {
    color: #4CAF50;
}

.bed-details p {
    margin: 5px 0;
    font-size: 14px;
}
#noDataMessage {
    font-size: 18px;
    color: #888;
    font-style: italic;
}
select {
    width: 100%; 
    height: 40px; 
    font-size: 14px; 
    padding: 5px; 
    box-sizing: border-box; 
    overflow: hidden; 
}

.details-grid {
    width: 100%;
}

.detail-row {
    display: flex;
    justify-content: space-between; 
    width: 100%;
    margin-bottom: 10px; 
}

.detail-item {
    flex: 1 1 48%; 
    display: flex;
    justify-content: flex-start; 
    padding: 8px;
    background: white; 
    border-radius: 4px;
    margin: 2px; 
    white-space: nowrap; 
    overflow: hidden; 
    text-overflow: ellipsis; 
}

.department {
    margin: 20px 0;
    padding: 15px;
    border: 2px solid #0abf58;
    background-color: #f9f9f9;
    border-radius: 8px;
}

.room-container {
    margin-top: 15px;
    padding: 10px;
    border: 1px solid #ddd;
    background-color: #ffffff;
    border-radius: 6px;
}

.beds-container {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 10px;
}

.bed-card {
    width: 150px;
    padding: 10px;
    border-radius: 6px;
    text-align: center;
    font-size: 14px;
    font-weight: bold;
    transition: 0.3s;
}


.bed-card.available {
    background-color: #dfffd6;
    border: 2px solid #28a745;
}

.bed-card.reserved {
    background-color: #fffaad;
    border: 2px solid #ffc107;
}

.bed-card.in-use {
    background-color: #ffdad6;
    border: 2px solid #dc3545;
}


.bed-card:hover {
    transform: scale(1.05);
}

.bed-icon {
    width: 40px;
    height: 40px;
    margin: auto;
    background-color: #ccc;
    border-radius: 50%;
}


#patientDetailsForm input,
#patientDetailsForm select {
  width: 100%;
  height: 38px;
  padding: 6px 10px;
  margin: 8px 0 14px;
  box-sizing: border-box;
  border: 1px solid #d0d7de;
  border-radius: 6px;
}


/* match label spacing to inputs (remove the big 30px top gap) */
#patientDetailsForm label {
  display: block;
  margin: 6px 0 4px;
  font-weight: 600;
}

/* apply the same polish to the Assign modal for consistency */
#addPatientForm input,
#addPatientForm select {
  width: 100%;
  height: 38px;
  padding: 6px 10px;
  margin: 8px 0 14px;
  box-sizing: border-box;
  border: 1px solid #d0d7de;
  border-radius: 6px;
}

/* give the modal a bit more breathing room */
.overlay-content {
  width: 520px;           /* was 400px */
  max-width: 96vw;
}

/* buttons line up nicely */
.button-group {
  display: flex;
  gap: 10px;
  justify-content: flex-end;
  width: 100%;
}

.overlay-btn { min-width: 110px; }


/* ===== MOBILE HEADER + DRAWER (same style as your other pages) ===== */
.mobile-header{ display:none; }
#menu-overlay{ display:none; }

@media (max-width: 768px){

  /* fixed header */
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
    font-weight:700;
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

  /* content full width */
  .dash-body{
    margin-top:66px !important;
    margin-left:0 !important;
    padding:12px !important;
    width:100% !important;
  }

  /* hide desktop header row (Back/Search/Date/Calendar) */
  .btn-icon-back,
  .header-search,
  td[width="15%"],
  td[width="10%"],
  .btn-label{
    display:none !important;
  }

  /* make Bed Management title tighter */
  h2{
    margin: 6px 0 10px !important;
    font-size: 18px !important;
  }
.mobile-center, .mobile-right{

    color: #000;
}
  /* tabs: full width + scroll if needed */
  .tabs{
    width:100%;
    justify-content:flex-start;
    overflow-x:auto;
    -webkit-overflow-scrolling: touch;
    gap:8px;
    padding-bottom:6px;
    margin: 10px 0 12px;
  }
  .tab-btn{
    white-space:nowrap;
    flex:0 0 auto;
    padding:10px 14px;
    font-size:13px;
  }

  /* room containers take full width and less padding */
  .room-container{
    padding:12px !important;
    margin-top:10px !important;
  }

  /* IMPORTANT: make bed cards maximize space */
  .beds-container{
    display:grid !important;
    grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    gap:10px !important;
  }

  .bed-card{
    width:100% !important;          /* override your fixed 150px */
    min-height:82px;
    padding:10px !important;
    font-size:13px !important;
    border-radius:10px;
  }

  .bed-card p{
    margin:4px 0 !important;
    line-height:1.15;
  }

  /* make modals fit on mobile */
  .overlay-content{
    width:92vw !important;
    max-width:92vw !important;
    padding:16px !important;
    border-radius:12px;
  }

  .button-group{
    flex-wrap:wrap;
    justify-content:flex-end;
  }

  .overlay-btn{
    min-width: 120px;
  }
}

/* slightly bigger phones/tablets */
@media (min-width: 769px) and (max-width: 1024px){
  .beds-container{
    display:grid !important;
    grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
    gap:14px !important;
  }
  .bed-card{ width:100% !important; }
}

@media (max-width: 768px){
  .menu .profile-subtitle{
    font-size: 13px !important;
  }
}

</style>
</head>

<body>
    <div id="menu-overlay"></div>

<!-- Mobile Header -->
<div class="mobile-header">
  <div class="mobile-left">
    <div id="hamburger-menu" aria-label="Open menu" role="button" tabindex="0">
      <div class="bar"></div>
      <div class="bar"></div>
      <div class="bar"></div>
    </div>
  </div>

  <div class="mobile-center">Bed Occupancy</div>

  <div class="mobile-right">
    <!-- hide date if you want: just remove this line -->
    <div class="mobile-date"><?php echo $todayHeader; ?></div>

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
                                <p class="profile-title"><?php echo substr($username,0,50) ?></p>
                                <p class="profile-subtitle"><?php echo substr($useremail,0,50)  ?></p>
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
                    <tr class="menu-row " >
                        <td class="menu-btn menu-active">
                            <a href="bed.php" class="non-style-link-menu non-style-link-menu-active">
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
                        <td class="menu-btn ">
                            <a href="summary.php" class="non-style-link-menu">
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

        <div class="dash-body">
            <table border="0" width="100%" style="border-spacing: 0; margin: 0; padding: 0; margin-top: 25px;">
                <tr>
                    <td width="13%">
                        <a href="bed.php"><button class="login-btn btn-primary-soft btn btn-icon-back" style="padding-top:11px; padding-bottom:11px; margin-left:20px; width:125px"><font class="tn-in-text">Back</font></button></a>
                    </td>
                    <td>
                        <form action="" method="post" class="header-search">
                            <input type="search" name="search" class="input-text header-searchbar" placeholder="Search Patient name or Email" list="patient" disabled>&nbsp;&nbsp;
                            <?php
                               // Retrieve patient data from Firebase
                                $patientsReference = $database->getReference('patients'); 
                                $patientsSnapshot = $patientsReference->getSnapshot();

                                if (!$patientsSnapshot->exists()) {
                                    echo "No patients found.";
                                } else {
                                    $patientsData = $patientsSnapshot->getValue(); 

                                    echo '<datalist id="patient">';
                                    
                                    foreach ($patientsData as $patient) {
                                        $pname = $patient['name']; 
                                        $pemail = $patient['email']; 

                                        echo "<option value='$pname'><br/>";
                                        echo "<option value='$pemail'><br/>";
                                    }
                                    
                                    echo '</datalist>';
                                }
                            ?>
                            <input type="submit" value="Search" class="login-btn btn-primary btn" style="padding-left: 25px; padding-right: 25px; padding-top: 10px; padding-bottom: 10px; width: 15%;">
                        </form>
                    </td>
                    <td width="15%">
                        <p style="font-size: 14px;color: rgb(119, 119, 119);padding: 0;margin: 0;text-align: right;">
                            Today's Date
                        </p>
                        <p class="heading-sub12" style="padding: 0; margin: 0;">
                            <?php
                                 date_default_timezone_set('Asia/Manila');
                                 $date = date('Y-m-d');
                                 echo $date;
                            ?>
                        </p>
                    </td>
                    <td width="10%">
                        <button class="btn-label" style="display: flex; justify-content: center; align-items: center;"><img src="../img/calendar.svg" width="100%"></button>
                    </td>
                </tr>

                <td colspan="4" style="padding-top:30px;">

                <h2 style="text-align: center;">Bed Management</h2>

<!-- Department Tabs -->
<div class="tabs">
 <button class="tab-btn active" onclick="showRoomTab('general')">General Ward</button>
 <button class="tab-btn" onclick="showRoomTab('emergency')">Emergency Room</button>
</div>

<!-- Bed Grid Tabs -->
<div class="bed-grid" id="bedGrid">
<?php
$roomsData = $database->getReference('rooms')->getValue();

if ($roomsData) {
    foreach ($roomsData as $category => $rooms) {
        echo '<div class="tab-content room-category" id="tab-' . htmlspecialchars($category) . '" style="display: none; ">';
        echo '<h2>' . strtoupper($category) . ' ROOMS</h2>';

        foreach ($rooms as $roomId => $room) {
            if (!isset($room['beds']) || !is_array($room['beds'])) continue;

            echo '<div class="room-container" style="padding: 30px;">';
            echo '<h3>Room ' . htmlspecialchars($roomId) . ' (' . htmlspecialchars($room['type']) . ')</h3>';
            echo '<div class="beds-container" style="gap:50px">';

            foreach ($room['beds'] as $bedIndex => $bed) {
                $bedStatus = $bed['status'] ?? 'available';
                $statusClass = ($bedStatus === 'occupied') ? 'in-use' : (($bedStatus === 'reserved') ? 'reserved' : 'available');
                
                $patientName = ($bedStatus === 'occupied') ? htmlspecialchars($bed['patientName'] ?? 'Unknown') : '';
                $bedNumber = htmlspecialchars($bed['bed_number']);
                
               
                $doctorName = 'N/A';
                if ($bedStatus === 'occupied' && isset($bed['doctor'])) {
               
                    if (is_array($bed['doctor']) && isset($bed['doctor']['name'])) {
                        $doctorName = htmlspecialchars($bed['doctor']['name']);
                    } 
         
                    elseif (is_string($bed['doctor'])) {
                        $doctorName = htmlspecialchars($bed['doctor']);
                    }
                }
                
                $dataPatientId = isset($bed['patientId']) ? 'data-patient-id="' . htmlspecialchars($bed['patientId']) . '"' : '';

                $patientName = ($bedStatus === 'occupied') ? htmlspecialchars($bed['patientName'] ?? ($bed['patient']['name'] ?? 'Unknown')) : '';
                $patientIdAttr = '';
                $patientEmailAttr = '';

                if ($bedStatus === 'occupied') {
                    // Prefer new structured shape if exists
                    if (!empty($bed['patient']) && is_array($bed['patient'])) {
                        $patientIdAttr    = ' data-patient-id="'.htmlspecialchars($bed['patient']['id'] ?? '', ENT_QUOTES).'"';
                        $patientEmailAttr = ' data-patient-email="'.htmlspecialchars($bed['patient']['email'] ?? '', ENT_QUOTES).'"';
                    } elseif (!empty($bed['patientId'])) {
                        // legacy flat id
                        $patientIdAttr = ' data-patient-id="'.htmlspecialchars($bed['patientId'], ENT_QUOTES).'"';
                    }
                }

                
             echo '<div class="bed-card ' . $statusClass . '" 
                data-category="' . htmlspecialchars($category) . '" 
                data-room-id="' . htmlspecialchars($roomId) . '" 
                data-room-number="' . htmlspecialchars($room['room_number']) . '" 
                data-bed-number="' . $bedNumber . '" 
                data-status="' . htmlspecialchars($bedStatus) . '" 
                data-patient-name="' . $patientName . '"
                data-doctor="' . $doctorName . '"'
                . $patientIdAttr . $patientEmailAttr . '>';

                
                echo '<p><strong>Bed ' . $bedNumber . '</strong></p>';
                echo '<p>Status: ' . ucfirst($bedStatus) . '</p>';
                if ($bedStatus === 'occupied') {
                    echo '<p>Patient: ' . $patientName . '</p>';
                }
                echo '</div>';
            }
            

            echo '</div>';
            echo '</div>';
        }
        echo '</div>';
    }
} else {
    echo '<p>No rooms found in the system.</p>';
}
?>
</div>


</div>

<?php
  // Build a datalist of patient names/emails for the Assign modal
  $allPatients = $database->getReference('patients')->getValue() ?: [];
?>
<datalist id="patients_datalist">
  <?php foreach ($allPatients as $pid => $p): 
        $pname = trim((string)($p['name']  ?? ''));
        $pemail= trim((string)($p['email'] ?? ''));
        if ($pname !== ''): ?>
    <option value="<?php echo htmlspecialchars($pname, ENT_QUOTES); ?>"></option>
  <?php endif; if ($pemail !== ''): ?>
    <option value="<?php echo htmlspecialchars($pemail, ENT_QUOTES); ?>"></option>
  <?php endif; endforeach; ?>
</datalist>

<!-- Assign Patient Modal -->
<div class="overlay hidden" id="addPatientModal">
    <div class="overlay-content">
        <h2>Assign Patient to Bed</h2>
        <form id="addPatientForm" action="assign_bed.php" method="POST">
            <label>Patient Name:</label>
            <input type="text" id="patientName" name="patientName" required oninput="capitalizeWords(this)" list="patients_datalist">

            <!-- NEW: Email (optional but helps reuse existing patient) -->
            <label>Patient Email (optional):</label>
            <input type="email" id="patientEmail" name="patientEmail" placeholder="juan@email.com">

            <!-- NEW: hidden ID (auto-filled if we detect an existing patient) -->
            <input type="hidden" id="patientId" name="patientId">


            <label>Category:</label>
            <input type="text" id="category" name="category" readonly>

            <label>Room Number:</label>
            <input type="text" id="roomNumber" name="roomNumber" readonly>

            <label>Bed Number:</label>
            <input type="text" id="bedNumber" name="bedNumber" readonly>

            <!-- Doctor Dropdown -->
            <label>Doctor:</label>
            <select name="doctor" id="doctor" required>
                <option value="">Select a doctor</option>
                <?php
// ---- fetch doctors ----
$doctorsData = $database->getReference('doctor')->getValue() ?: [];

// ---- fetch specialties (id -> name) ----
$specSnap = $database->getReference('specialties')->getValue() ?: [];
$specIdToName = [];
foreach ($specSnap as $sid => $row) {
    if (isset($row['sname']) && $row['sname'] !== '') {
        $specIdToName[$sid] = trim((string)$row['sname']);
    }
}

// ---- normalize helper ----
$norm = function($s){ return mb_strtolower(trim((string)$s)); };

// ---- admin specialties (may be comma-separated or array) ----
$adminSpecsRaw = is_array($adminSpecialty)
    ? $adminSpecialty
    : preg_split('/\s*,\s*/', (string)$adminSpecialty, -1, PREG_SPLIT_NO_EMPTY);

$adminSpecsNorm = array_values(array_filter(array_map($norm, (array)$adminSpecsRaw)));

// If admin is non-clinical or no specialty set -> don't filter
$shouldFilter = !empty($adminSpecsNorm)
                && !in_array('information', $adminSpecsNorm, true)
                && !in_array('n/a', $adminSpecsNorm, true);

// ---- build filtered list (supports both schemas) ----
$filteredDoctors = [];
foreach ($doctorsData as $doctorId => $doctor) {
    $docName  = $doctor['name']  ?? 'Unknown';
    $docEmail = $doctor['email'] ?? '';

    // preferred: ID -> name via specialties table
    $docSpecName = '';
    if (!empty($doctor['specialty']) && isset($specIdToName[$doctor['specialty']])) {
        $docSpecName = $specIdToName[$doctor['specialty']];
    }
    // legacy fallback: sname (string or array)
    if ($docSpecName === '' && !empty($doctor['sname'])) {
        $docSpecName = is_array($doctor['sname']) ? implode(', ', $doctor['sname']) : $doctor['sname'];
    }

    // normalize doctor specialties (handle multiple)
    $docSpecsNorm = array_values(array_filter(array_map(
        $norm,
        preg_split('/\s*,\s*/', (string)$docSpecName, -1, PREG_SPLIT_NO_EMPTY)
    )));

    // match if not filtering OR any overlap
    $matches = !$shouldFilter || count(array_intersect($adminSpecsNorm, $docSpecsNorm)) > 0;

    if ($matches) {
        $filteredDoctors[$doctorId] = [
            'name'      => $docName,
            'email'     => $docEmail,
            'specialty' => $docSpecName !== '' ? $docSpecName : 'Unknown',
        ];
    }
}

// ---- render options ----
if (empty($filteredDoctors)) {
    echo '<option value="" disabled>No doctors available</option>';
} else {
    foreach ($filteredDoctors as $doctorId => $doc) {
        echo '<option value="', htmlspecialchars($doctorId, ENT_QUOTES),
             '">', htmlspecialchars($doc['name'].' — '.$doc['specialty'], ENT_QUOTES),
             '</option>';
    }
}
?>

            </select>

            <div class="button-group">
            <button type="submit" class="overlay-btn assign-btn" onclick="return confirm('Are you sure you want to assign?');">
                Assign
                </button>

                <button type="button" class="overlay-btn cancel-btn" onclick="closeAddPatientModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>





<!-- View Patient Modal -->
<div id="patientDetailsModal" class="overlay hidden">
    <div class="overlay-content">
        <h2>Patient Details</h2>
        <form id="patientDetailsForm">
            <label>Bed Number:</label>
            <input type="text" id="modalBedNumber" readonly>

            <label>Room Number:</label>
            <input type="text" id="modalRoomNumber" readonly>

            <label>Department:</label>
            <input type="text" id="modalDepartment" readonly>

            <label>Status:</label>
            <input type="text" id="modalStatus" readonly>

            <label>Patient Name:</label>
            <input type="text" id="modalPatientName" readonly>

            <label>Doctor:</label>
            <input type="text" id="modalDoctor" readonly>

           <div class="button-group" style="margin-top: 20px;">
           <button type="button" class="overlay-btn discharge-btn" onclick="if(confirm('Are you sure you want to discharge the patient?')) { dischargePatient(); }">
            Discharge
            </button>

                <button type="button" class="overlay-btn cancel-btn" onclick="closePatientDetailsModal()">Close</button>
            </div>
        </form>
    </div>
</div>



</td>



<script>
document.addEventListener('DOMContentLoaded', () => {
    showRoomTab('general');
    setupBedCardListeners();
});

function closePatientDetailsModal() {
    document.getElementById('patientDetailsModal').classList.add('hidden');
}

function capitalizeWords(input) {
        let words = input.value.split(" ");
        for (let i = 0; i < words.length; i++) {
            if (words[i].length > 0) {
                words[i] = words[i][0].toUpperCase() + words[i].substring(1).toLowerCase();
            }
        }
        input.value = words.join(" ");
    }
    
function assignPatient(event) {
    const form = document.getElementById('addPatientForm');
    const formData = new FormData(form);

    fetch('assign_bed.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json()) 
    .then(result => {
        if (result.success) {
            alert(result.message); 
            closeAddPatientModal(); 
            location.reload(); 
        } else {
            alert(result.message); 
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert("An unexpected error occurred. Please try again.");
    });
}

document.addEventListener('DOMContentLoaded', () => {
    showRoomTab('general');
    setupBedCardListeners();

    // NEW: initialize name -> ID resolver for Assign modal
    setupPatientNameListener();
});

function debounce(func, delay) {
    let timer;
    return function (...args) {
        clearTimeout(timer);
        timer = setTimeout(() => func.apply(this, args), delay);
    };
}

async function fetchPatientId(patientName) {
    try {
        const response = await fetch('check_patient.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ patientName }),
        });
        if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
        return await response.json();
    } catch (error) {
        console.error('Error fetching patient ID:', error);
        return null;
    }
}

function setupPatientNameListener() {
    const patientNameInput = document.getElementById('patientName');
    if (patientNameInput) {
        patientNameInput.addEventListener(
            'input',
            debounce(async function () {
                const patientName = this.value.trim();
                const patientIdField = document.getElementById('patientId');

                if (patientName.length > 1) {
                    const patientData = await fetchPatientId(patientName);
                    patientIdField.value = patientData?.patientId || '';
                } else {
                    patientIdField.value = '';
                }
            }, 300)
        );
    }
}

function showRoomTab(category) {
    document.querySelectorAll('.tab-content.room-category').forEach(tab => tab.style.display = 'none');
    document.getElementById('tab-' + category).style.display = 'block';

    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelector(`button[onclick="showRoomTab('${category}')"]`).classList.add('active');
}

async function dischargePatient() {
    const bedNumber = document.getElementById('modalBedNumber').value;
    const roomNumber = document.getElementById('modalRoomNumber').value;
    const category = document.getElementById('modalDepartment').value;

    try {
        const response = await fetch('discharge_patient.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ bedNumber, roomNumber, category })
        });

        const result = await response.json();

        alert(result.message);

        if (result.success) {
            closePatientDetailsModal();
            location.reload();
        }

    } catch (error) {
        alert("Error: " + error.message);
    }
}


function setupBedCardListeners() {
    const bedCards = document.querySelectorAll('.bed-card');

    bedCards.forEach(card => {
        card.addEventListener('click', function () {
            const status = card.getAttribute('data-status');

            if (status === 'occupied') {
                const bedData = {
                    bedNumber: card.getAttribute('data-bed-number'),
                    roomNumber: card.getAttribute('data-room-number'),
                    category: card.getAttribute('data-category'),
                    status: status,
                    patientName: card.getAttribute('data-patient-name'),
                    doctor: card.getAttribute('data-doctor') || 'N/A'
                };

                openPatientDetailsModal(bedData);
            } else if (status === 'available') {
                const bedNumber = card.getAttribute('data-bed-number');
                const roomNumber = card.getAttribute('data-room-number');
                const category = card.getAttribute('data-category');

                openAddPatientModal(bedNumber, roomNumber, category);
            }
        });
    });
}


async function submitForm() {
    const form = document.getElementById('addPatientForm');
    const formData = new FormData(form);
    try {
        const response = await fetch(form.action, { method: 'POST', body: formData });
        const result = await response.json();
        alert(result.message);
        if (result.success) closeAddPatientModal();
    } catch (error) {
        console.error('Error submitting form:', error);
    }
}
function openPatientDetailsModal(bedData) {
    document.getElementById('modalBedNumber').value = bedData.bedNumber;
    document.getElementById('modalRoomNumber').value = bedData.roomNumber;
    document.getElementById('modalDepartment').value = bedData.category;
    document.getElementById('modalStatus').value = bedData.status;
    document.getElementById('modalPatientName').value = bedData.patientName;
    document.getElementById('modalDoctor').value = bedData.doctor || 'N/A'; 

    document.getElementById('patientDetailsModal').classList.remove('hidden');
}



    // Function to close the modal
    function closePatientDetailsModal() {
        document.getElementById('patientDetailsModal').classList.add('hidden');
    }

    document.addEventListener("DOMContentLoaded", function () {
    const bedCards = document.querySelectorAll('.bed-card');
    bedCards.forEach(card => {
        card.addEventListener('click', function () {
            const status = card.getAttribute('data-status');
            if (status === 'occupied') {
                const bedData = {
                    bedNumber: card.getAttribute('data-bed-number'),
                    roomNumber: card.getAttribute('data-room-number'),
                    category: card.getAttribute('data-category'),
                    status: status,
                    patientName: card.getAttribute('data-patient-name'),
                   
                    doctor: card.getAttribute('data-doctor') || 'N/A'
                };
                openPatientDetailsModal(bedData);
            }
        });
    });
});


function openAddPatientModal(bedNumber, roomNumber, category) {
    document.getElementById('bedNumber').value = bedNumber;
    document.getElementById('roomNumber').value = roomNumber;
    document.getElementById('category').value = category;
    document.getElementById('addPatientModal').classList.remove('hidden');
}

function closeAddPatientModal() {
    document.getElementById('addPatientModal').classList.add('hidden');
    document.getElementById('addPatientForm').reset();
}

document.addEventListener('DOMContentLoaded', function () {
  const hamburger = document.getElementById('hamburger-menu');
  const overlay   = document.getElementById('menu-overlay');

  function closeMenu(){
    document.body.classList.remove('menu-open');
    hamburger?.classList.remove('active');
  }

  hamburger?.addEventListener('click', function(){
    document.body.classList.toggle('menu-open');
    hamburger.classList.toggle('active');
  });

  overlay?.addEventListener('click', closeMenu);

  // close drawer when clicking a menu link (optional, feels nicer on mobile)
  document.querySelectorAll('.menu a').forEach(a=>{
    a.addEventListener('click', closeMenu);
  });
});
</script>               


 </body>
</html>
                    