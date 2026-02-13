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
    
    date_default_timezone_set('Asia/Manila');
    $today = date('Y-m-d');

    // Fetch patient data
    $patientRef = $database->getReference('patients')->orderByChild('email')->equalTo($useremail);
    $patientSnapshot = $patientRef->getSnapshot();
    $patientData = $patientSnapshot->getValue();
    
    if ($patientData) {
        $userfetch = array_values($patientData)[0]; 
        $userid = $userfetch["type"];
        $username = $userfetch["fname"] . ' '. $userfetch["lname"];
    } else {
        // Handle the case where no patient data is found
        echo "No patient data found.";
        exit();
    }

    // Fetch the patient's photo from Firebase
$patientRef = $database->getReference('patients')->orderByChild('email')->equalTo($useremail);
$patientSnapshot = $patientRef->getSnapshot();
$patientData = $patientSnapshot->getValue();

$photo = '../img/user.png'; // Default image
if ($patientData) {
    $patient = reset($patientData); // Get the first patient record
    if (!empty($patient['photo'])) {
        $photo = $patient['photo']; // Use the photo from Firebase
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


    <title>Settings</title>
<style>
/* =========================
   ANIMATIONS
   ========================= */
.dashbord-tables{
  animation: transitionIn-Y-over 0.5s;
}
.filter-container{
  animation: transitionIn-X 0.5s;
}
.sub-table{
  animation: transitionIn-Y-bottom 0.5s;
}

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
  background-color:#E8F8E8;
}

/* =========================
   MOBILE HEADER (BASE)
   ========================= */
.mobile-header{
  display:none;
}

/* =========================
   MOBILE RESPONSIVE
   ========================= */
@media (max-width:768px){

  /* =========================
     MOBILE HEADER
     ========================= */
  .mobile-header{
    display:flex !important;
    position:fixed;
    top:0; left:0; right:0;
    height:56px;
    background:lightgreen;
    z-index:10050;
    align-items:center;
    justify-content:space-between;
    padding:0 12px;
    font-weight:700;
    color:#000;
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

  /* =========================
     HAMBURGER
     ========================= */
  #hamburger-menu{
    display:flex !important;
    width:34px !important;
    height:34px !important;
    padding:8px !important;
    background:rgba(255,255,255,.48) !important;
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

  #hamburger-menu.active .bar:nth-child(1){
    transform:rotate(-45deg) translate(-4px,5px);
  }
  #hamburger-menu.active .bar:nth-child(2){
    opacity:0;
  }
  #hamburger-menu.active .bar:nth-child(3){
    transform:rotate(45deg) translate(-4px,-5px);
  }

  /* =========================
     SIDEBAR
     ========================= */
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
  }

  .menu.active{
    display:block;
    left:0;
  }

  /* =========================
     LAYOUT
     ========================= */
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
    padding:10px 12px !important;
    box-sizing:border-box !important;
  }

  /* overlay behavior wins (last rule preserved) */
  .menu.active + .dash-body{
    margin-left:0 !important;
  }

  /* =========================
     HIDE DESKTOP HEADER ROW
     ========================= */
  .dash-body > table > tbody > tr:first-child{
    display:none !important;
  }

  /* =========================
     SETTINGS TABLE
     ========================= */
  .dash-body center{
    display:block !important;
    width:100% !important;
  }

  table.filter-container{
    width:100% !important;
    max-width:100% !important;
    margin:0 !important;
  }

  table.filter-container td{
    width:100% !important;
    display:block !important;
    padding:0 !important;
  }

  .dashboard-items.setting-tabs{
    width:100% !important;
    max-width:100% !important;
    margin:10px 0 !important;
    box-sizing:border-box !important;
  }

  /* =========================
     OVERLAY
     ========================= */
  .menu-overlay{
    display:none;
    position:fixed;
    inset:56px 0 0 0;
    background:rgba(0,0,0,.25);
    z-index:1850;
  }

  .menu-overlay.active{
    display:block;
  }

  /* =========================
     HORIZONTAL SCROLL FIX
     ========================= */
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
                                <td width="30%" style="padding-left:20px" >
                                <img src="<?php echo htmlspecialchars($photo); ?>" alt="Profile Picture" width="100%" style="border-radius:50%">
                                </td>
                                <td style="padding:0px;margin:0px;">
                                    <p class="profile-title"><?php echo substr($username,0,100)  ?></p>
                                    <p class="profile-subtitle"><?php echo substr($useremail,0,100)  ?></p>
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
                    <td class="menu-btn">
                        <a href="doctors.php" class="non-style-link-menu ">
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
                    <td class="menu-btn menu-active">
                        <a href="settings.php" class="non-style-link-menu non-style-link-menu-active">
                            <p class="menu-text">Settings</p>
                        </a>
                    </td>
                </tr>
                
            </table>
        </div>
        <div class="dash-body" style="margin-top: 15px">
            <table border="0" width="100%" style=" border-spacing: 0;margin:0;padding:0;" >
                        
                        <tr >
                            
                        <td width="13%" >
                    <a href="settings.php" ><button  class="login-btn btn-primary-soft btn btn-icon-back"  style="padding-top:11px;padding-bottom:11px;margin-left:20px;width:125px"><font class="tn-in-text">Back</font></button></a>
                    </td>
                    <td>
                        <p style="font-size: 23px;padding-left:12px;font-weight: 600;">Settings</p>
                                           
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
                        
                        <center>
                        <table class="filter-container" style="border: none;" border="0">
                            <tr>
                                <td colspan="4">
                                    <p style="font-size: 20px">&nbsp;</p>
                                </td>
                            </tr>
                            <tr>
                                <td style="width: 25%;">
                                    <a href="account.php" class="non-style-link">
                                    <div  class="dashboard-items setting-tabs"  style="padding:20px;margin:auto;width:95%;display: flex">
                                        <div class="btn-icon-back dashboard-icons-setting" style="background-image: url('../img/icons/doctors-hover.svg');"></div>
                                        <div>
                                                <div class="h1-dashboard">
                                                    View Account Details  &nbsp;

                                                </div><br>
                                                <div class="h3-dashboard" style="font-size: 15px;">
                                                View Personal information About Your Account
                                                </div>
                                        </div>
                                                
                                    </div>
                                    </a>
                                </td>
                                
                                
                            </tr>
                            <tr>
                                <td colspan="4">
                                    <p style="font-size: 5px">&nbsp;</p>
                                </td>
                            </tr>
                            <tr>
                            <td style="width: 25%;">
                                    <a href="help.php" class="non-style-link">
                                    <div  class="dashboard-items setting-tabs"  style="padding:20px;margin:auto;width:95%;display: flex;">
                                        <div class="btn-icon-back dashboard-icons-setting " style="background-image: url('../img/icons/view-iceblue.svg');"></div>
                                        <div>
                                                <div class="h1-dashboard" >
                                                    Help Support
                                                    
                                                </div><br>
                                                <div class="h3-dashboard"  style="font-size: 15px;">
                                                     Report a Problem
                                                </div>
                                        </div>
                                                
                                    </div>
                                    </a>
                                </td>
                                
                            </tr>
                            <tr>
                                <td colspan="4">
                                    <p style="font-size: 5px">&nbsp;</p>
                                </td>
                            </tr>
                            
                        </table>
                    </center>
                    </td>
                </tr>
            
            </table>
        </div>
    </div>
		
	<script>
  const hamburgerMenu = document.getElementById('hamburger-menu');
  const menu = document.querySelector('.menu');
  const menuOverlay = document.getElementById('menuOverlay');

  function toggleMenu(){
    hamburgerMenu.classList.toggle('active');
    menu.classList.toggle('active');
    if (menuOverlay) menuOverlay.classList.toggle('active');
  }

  hamburgerMenu.addEventListener('click', toggleMenu);

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
