<?php
session_name('sess_doctor'); session_start();

if(isset($_SESSION["user"])){
    if(($_SESSION["user"])=="" or $_SESSION['usertype']!='d'){
        header("location: ../login.php");
    }else{
        $useremail=$_SESSION["user"];
    }

}else{
    header("location: ../login.php");
}


//import database
include("../connection.php");

// Retrieve the doctor's record by email and capture the key as the doctor id.
$doctorRef = $database->getReference('doctor')->orderByChild('email')->equalTo($useremail);
$doctorDataArr = $doctorRef->getValue();

if ($doctorDataArr) {
    // Loop through the returned array to capture the doctor id and the data.
    foreach ($doctorDataArr as $key => $docData) {
        $doctorId = $key; 
        $username = isset($docData['name']) ? $docData['name'] : 'Unknown';
        $photo = !empty($docData['photo']) ? $docData['photo'] : '../img/user.png';
        break; 
    }
} else {
    // Fallback values if no record is found.
    $doctorId = null;
    $username = 'Unknown';
    $photo = '../img/user.png';
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
    <link rel="stylesheet" href="../admin/patient.css">

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



.bed-grid {
      display: flex;
      flex-direction: column;
      gap: 20px;
      margin: 20px auto;
      max-width: 800px;
    }
    .room-container {
      padding: 30px;
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
      background-color: #ffdad6; 
      border: 2px solid #dc3545;
      cursor: pointer;
    }
    .bed-card:hover {
      transform: scale(1.05);
    }
    /* Modal styling */
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
      background: white;
      padding: 20px;
      border-radius: 10px;
      width: 400px;
      max-width: 90%;
      box-shadow: 0px 5px 15px rgba(0, 0, 0, 0.3);
    }
    .hidden {
      display: none;
    }

/* =========================
   MOBILE HEADER + DRAWER (Doctor Bed Occupancy)
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

  /* sidebar becomes overlay drawer */
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

  /* ✅ DO NOT PUSH CONTENT */
  .menu.active + .dash-body{
    margin-left:0 !important;
  }

  /* dim overlay */
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
    margin-top:70px !important; /* below header */
    margin-left:0 !important;
    padding:12px !important;
    box-sizing:border-box !important;
  }

  /* hide the desktop top header row (search/date/calendar row) */
  .dash-body > table > tbody > tr:first-child{
    display:none !important;
  }

  /* stop side scrolling */
  html, body{ overflow-x:hidden !important; }

  /* tabs should wrap nicely */
  .tabs{
    flex-wrap:wrap !important;
    gap:8px !important;
    margin:12px 0 !important;
  }
  .tab-btn{
    flex:1 1 auto !important;
    width:auto !important;
    padding:10px 12px !important;
    font-size:13px !important;
  }

  /* bed layout: keep your design but fit small screens */
  .room-container{ padding:15px !important; }
  .beds-container{ gap:8px !important; }

  .bed-card{
    width:calc(50% - 8px) !important; /* 2 per row */
    min-width:140px !important;
  }

  /* modal fits screen */
  .overlay-content{
    width:92% !important;
    max-width:92% !important;
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
   <?php
date_default_timezone_set('Asia/Manila');
$today = date('Y-m-d');
?>

<!-- ✅ MOBILE HEADER -->
<div class="mobile-header">
  <div class="mh-left">
    <div id="hamburger-menu" aria-label="Menu">
      <div class="bar"></div>
      <div class="bar"></div>
      <div class="bar"></div>
    </div>
  </div>

  <div class="mh-title">Bed Occupancy</div>

  <div class="mh-right">
    <div class="mh-date">
      <div class="mh-date-label">Date</div>
      <div class="mh-date-value"><?php echo $today; ?></div>
    </div>
    <button class="mh-cal btn-label" type="button" aria-label="Calendar">
      <img src="../img/calendar.svg" alt="">
    </button>
  </div>
</div>

<!-- ✅ overlay (menu won't push content) -->
<div class="menu-overlay" id="menuOverlay"></div>

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
                                    <p class="profile-title"><?php echo substr($username,0,50)  ?></p>
                                    <p class="profile-subtitle"><?php echo substr($useremail,0,50)  ?></p>
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
                        <td class="menu-btn">
                            <a href="index.php" class="non-style-link-menu">
                                <p class="menu-text">Dashboard</p>
                            </a>
                        </td>
                    </tr>
                    <tr class="menu-row">
                        <td class="menu-btn ">
                            <a href="schedule.php" class="non-style-link-menu">
                                <p class="menu-text">My Sessions</p>
                            </a>
                        </td>
                    </tr>
                    <tr class="menu-row">
                        <td class="menu-btn menu-active">
                            <a href="bed.php" class="non-style-link-menu non-style-link-menu-active">
                                <p class="menu-text">Bed Occupancy</p>
                            </a>
                        </td>
                    </tr>
                    <tr class="menu-row">
                        <td class="menu-btn ">
                            <a href="patient.php" class="non-style-link-menu">
                                <p class="menu-text">Patient Health Records</p>
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
                    <td>
                        <form action="" method="post" class="header-search">
                            <input type="search" name="search" class="input-text header-searchbar" placeholder="Search Patient name or Email" list="patient">&nbsp;&nbsp;
                            <?php
                               // Retrieve patient data from Firebase
                                $patientsReference = $database->getReference('patients'); 
                                $patientsSnapshot = $patientsReference->getSnapshot();

                                if (!$patientsSnapshot->exists()) {
                                    echo "No patients found.";
                                } else {
                                    $patientsData = $patientsSnapshot->getValue(); 

                                    echo '<datalist id="patient">';
                                    
                                    // Loop through the patient data and populate the datalist
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

<div class="tabs">
  <button class="tab-btn active" data-category="general"        onclick="showRoomTab(this.dataset.category)">General Ward</button>
  <button class="tab-btn"        data-category="emergency-room" onclick="showRoomTab(this.dataset.category)">Emergency Room</button>
</div>


 <!-- Bed Grid: Only display beds assigned to the logged-in doctor -->
 <div class="bed-grid" id="bedGrid">
              <?php
              // Retrieve the rooms node from Firebase.
              $roomsData = $database->getReference('rooms')->getValue();
              $found = false; 
              function slug($s){
  $s = strtolower($s);
  $s = preg_replace('/[^a-z0-9]+/', '-', $s);
  return trim($s, '-');
}

              if ($roomsData && $doctorId) {
                 foreach ($roomsData as $category => $rooms) {
                    $catKey = slug($category);   // normalized key used in IDs & data-attrs
                    $categoryOutput = "";

                      foreach ($rooms as $roomId => $room) {
                          // Only process rooms with a 'beds' array.
                          if (!isset($room['beds']) || !is_array($room['beds'])) continue;
                          
                          $roomOutput = "";
                          foreach ($room['beds'] as $bed) {
                              // Check if the bed is occupied and assigned to this doctor by matching the doctor id.
                              if (
                                  isset($bed['status']) && $bed['status'] === 'occupied' &&
                                  isset($bed['doctor']['id']) && $bed['doctor']['id'] === $doctorId
                              ) {
                                  $bedNumber = htmlspecialchars($bed['bed_number']);
                                  $patientName = isset($bed['patientName']) ? htmlspecialchars($bed['patientName']) : 'Unknown';

                                    // In your PHP loop that generates the bed cards:
                                    $doctorName = isset($bed['doctor']['name']) ? htmlspecialchars($bed['doctor']['name']) : 'Unknown';

                                   $roomOutput .= '<div class="bed-card" 
                                        data-category="' . htmlspecialchars($catKey) . '" 
                                        data-room-id="' . htmlspecialchars($roomId) . '" 
                                        data-room-number="' . htmlspecialchars($room['room_number']) . '" 
                                        data-bed-number="' . $bedNumber . '" 
                                        data-status="occupied" 
                                        data-patient-name="' . $patientName . '"
                                        data-doctor-name="' . $doctorName . '"
                                    >';

                                  $roomOutput .= '<p><strong>Bed ' . $bedNumber . '</strong></p>';
                                  $roomOutput .= '<p>Status: Occupied</p>';
                                  $roomOutput .= '<p>Patient: ' . $patientName . '</p>';
                                  $roomOutput .= '</div>';
                              }
                          }
                          if ($roomOutput !== "") {
                              $categoryOutput .= '<div class="room-container">';
                              $categoryOutput .= '<h3>Room ' . htmlspecialchars($room['room_number']) . ' (' . htmlspecialchars($room['type']) . ')</h3>';
                              $categoryOutput .= '<div class="beds-container">' . $roomOutput . '</div>';
                              $categoryOutput .= '</div>';
                          }
                      }
                     if ($categoryOutput !== "") {
    echo '<div class="tab-content room-category" id="tab-' . htmlspecialchars($catKey) . '" style="display:none;">';
    echo '<h2>' . strtoupper(htmlspecialchars($category)) . ' ROOMS</h2>';
    echo $categoryOutput;
    echo '</div>';
    $found = true;
}
                  }
              }
              if (!$found) {
                  echo '<p>No bed assigned to your patient.</p>';
              }
              ?>
</div>


</div>


  <!-- Patient Details Modal (Read-Only View) -->
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
        <label>Doctor Assigned:</label>
        <input type="text" id="modalDoctorName" readonly>
        <div class="button-group">
          <button type="button" class="overlay-btn cancel-btn" onclick="closePatientDetailsModal()">Close</button>
        </div>
      </form>
    </div>
  </div>


</td>



<script>
document.addEventListener('DOMContentLoaded', () => {
  const firstKey = document.querySelector('.tab-btn')?.dataset.category || 'general';
  showRoomTab(firstKey);
  setupBedCardListeners();
});

function showRoomTab(categoryKey) {
  // hide all
  document.querySelectorAll('.tab-content.room-category').forEach(tab => {
    tab.style.display = 'none';
  });

  // show requested tab if it exists
  const tabEl = document.getElementById('tab-' + categoryKey);
  if (tabEl) tabEl.style.display = 'block';

  // set active button
  document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
  const btnEl = document.querySelector(`.tab-btn[data-category="${categoryKey}"]`);
  if (btnEl) btnEl.classList.add('active');
}

function setupBedCardListeners() {
  const bedCards = document.querySelectorAll('.bed-card');
  bedCards.forEach(card => {
    card.addEventListener('click', function () {
      const bedData = {
        bedNumber:   card.getAttribute('data-bed-number'),
        roomNumber:  card.getAttribute('data-room-number'),
        category:    card.getAttribute('data-category'),
        status:      card.getAttribute('data-status'),
        patientName: card.getAttribute('data-patient-name'),
        doctorName:  card.getAttribute('data-doctor-name')
      };
      openPatientDetailsModal(bedData);
    });
  });
}

function openPatientDetailsModal(bedData) {
  document.getElementById('modalBedNumber').value = bedData.bedNumber;
  document.getElementById('modalRoomNumber').value = bedData.roomNumber;
  document.getElementById('modalDepartment').value = bedData.category;
  document.getElementById('modalStatus').value = bedData.status;
  document.getElementById('modalPatientName').value = bedData.patientName;
  document.getElementById('modalDoctorName').value = bedData.doctorName; 
  document.getElementById('patientDetailsModal').classList.remove('hidden');
}


    function closePatientDetailsModal() {
      document.getElementById('patientDetailsModal').classList.add('hidden');
    }

    const hamburgerMenu = document.getElementById('hamburger-menu');
  const menu = document.querySelector('.menu');
  const menuOverlay = document.getElementById('menuOverlay');

  function toggleMenu(){
    hamburgerMenu.classList.toggle('active');
    menu.classList.toggle('active');
    if (menuOverlay) menuOverlay.classList.toggle('active');
  }

  if (hamburgerMenu) hamburgerMenu.addEventListener('click', toggleMenu);

  if (menuOverlay){
    menuOverlay.addEventListener('click', () => {
      hamburgerMenu.classList.remove('active');
      menu.classList.remove('active');
      menuOverlay.classList.remove('active');
    });
  }
  </script>         


 </body>
</html>