<?php
session_name('sess_admin'); session_start();
// Check if user is logged in is an admin 
if (isset($_SESSION["user"])) {
    if (($_SESSION["user"]) == "" || $_SESSION['usertype'] != 'a') {
        header("location: ../login.php");
    } else {
        $useremail = $_SESSION["user"];
    }
} else {
    header("location: ../login.php");
}

include("../connection.php");

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
    <title>Settings</title>
    <style>
        .dashbord-tables {
            animation: transitionIn-Y-over 0.5s;
        }
        .filter-container {
            animation: transitionIn-X 0.5s;
        }
        .sub-table {
            animation: transitionIn-Y-bottom 0.5s;
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
        /* =========================
   MOBILE SETTINGS FIX
   Put at VERY BOTTOM
   ========================= */
@media (max-width: 768px){

  /* full width layout */
  html, body{ width:100%; overflow-x:hidden; }
  .container{
    width:100% !important;
    max-width:100% !important;
    padding-top:56px !important; /* space for fixed header */
  }

  /* Mobile header */
  .mobile-header{
    display:flex !important;
    position:fixed !important;
    top:0; left:0; right:0;
    height:56px;
    background:lightgreen;
    z-index:10050;
    align-items:center;
    justify-content:space-between;
    padding:0 12px;
    box-shadow:0 2px 10px rgba(0,0,0,.12);
  }

  .m-left, .m-right{
    display:flex;
    align-items:center;
    gap:10px;
  }

  .m-title{
    flex:1;
    text-align:center;
    font-weight:700;
    font-size:16px;
    color:#000;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
    padding:0 10px;
  }

  .m-date{
    font-size:12px;
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
    display:inline-flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    padding:0;
  }
  .m-cal-btn img{ width:18px; height:18px; display:block; }

  /* Hamburger */
  #hamburger-menu{
    width:34px;
    height:34px;
    border-radius:10px;
    background:rgba(255,255,255,.48);
    display:flex;
    flex-direction:column;
    justify-content:center;
    gap:5px;
    padding:8px;
    cursor:pointer;
  }
  #hamburger-menu .bar{
    height:2px;
    width:100%;
    background:#111;
    border-radius:2px;
    transition:all .25s ease;
  }
  #hamburger-menu.active .bar:nth-child(1){
    transform:rotate(-45deg) translate(-4px, 5px);
  }
  #hamburger-menu.active .bar:nth-child(2){
    opacity:0;
  }
  #hamburger-menu.active .bar:nth-child(3){
    transform:rotate(45deg) translate(-4px, -5px);
  }

  /* Hide the DESKTOP header row inside dash-body (Settings + Today's date + big calendar) */
  .dash-body > table > tbody > tr:first-child{
    display:none !important;
  }

  /* Make dash-body use all space */
  .dash-body{
    margin:0 !important;
    padding:12px !important;
    width:100% !important;
  }

  /* Make your Settings cards full width stacked */
  .filter-container{
    width:100% !important;
    margin:0 !important;
  }
  .filter-container td{
    width:100% !important;
    display:block !important;
  }

  .setting-tabs{
    width:100% !important;
    margin:0 0 12px 0 !important;
    border-radius:14px;
  }

  /* Drawer menu (sidebar) */
  .menu{
    display:block !important;
    position:fixed !important;
    top:56px !important;
    left:-280px !important;
    width:280px !important;
    max-width:86vw !important;
    height:calc(100vh - 56px) !important;
    background:lightgreen !important;
    transition:left .25s ease !important;
    z-index:10040 !important;
    overflow-y:auto !important;
    -webkit-overflow-scrolling:touch !important;
    box-shadow:10px 0 30px rgba(0,0,0,.12) !important;
  }
  .menu.active{
    left:0 !important;
  }

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

  body.menu-open{ overflow:hidden; }
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
  <div class="m-left">
    <div id="hamburger-menu">
      <div class="bar"></div>
      <div class="bar"></div>
      <div class="bar"></div>
    </div>
  </div>

  <div class="m-title">Settings</div>

  <div class="m-right">
    <div class="m-date"><?php echo date('Y-m-d'); ?></div>
    <button class="m-cal-btn" type="button">
      <img src="../img/calendar.svg" alt="Calendar">
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
                                <td style="padding:0;margin:0;">
                                    <p class="profile-title"><?php echo substr($username,0,100); ?></p>
                                    <p class="profile-subtitle"><?php echo substr($useremail,0,100); ?></p>
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
                <!-- Navigation Menu -->
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
                        <td class="menu-btn ">
                            <a href="bed.php" class="non-style-link-menu">
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
                    <td class="menu-btn menu-active">
                        <a href="settings.php" class="non-style-link-menu non-style-link-menu-active">
                            <p class="menu-text">Settings</p>
                        </a>
                    </td>
                </tr>
            </table>
        </div>
        <div class="dash-body" style="margin-top: 15px">
            <table border="0" width="100%" style="border-spacing: 0;margin:0;padding:0;">
                <tr>
                    <p style="font-size: 23px;padding-left:12px;font-weight: 600;">Settings</p>
                    <td></td>
                    <td width="15%">
                        <p style="font-size: 14px;color: rgb(119, 119, 119);padding: 0;margin: 0;text-align: right;">Today's Date</p>
                        <p class="heading-sub12" style="padding: 0;margin: 0;">
                            <?php 
                            date_default_timezone_set('Asia/Manila');
                            $today = date('Y-m-d');
                            echo $today;
                            ?>
                        </p>
                    </td>
                    <td width="10%">
                        <button class="btn-label" style="display: flex;justify-content: center;align-items: center;"><img src="../img/calendar.svg" width="100%"></button>
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
                                        <div class="dashboard-items setting-tabs" style="padding:20px;margin:auto;width:95%;display: flex">
                                            <div class="btn-icon-back dashboard-icons-setting" style="background-image: url('../img/icons/doctors-hover.svg');"></div>
                                            <div>
                                                <div class="h1-dashboard">
                                                    View Account Details &nbsp;
                                                </div><br>
                                                <div class="h3-dashboard" style="font-size: 15px;">
                                                    View personal information about your account
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
                                        <div class="dashboard-items setting-tabs" style="padding:20px;margin:auto;width:95%;display: flex;">
                                            <div class="btn-icon-back dashboard-icons-setting" style="background-image: url('../img/icons/view-iceblue.svg');"></div>
                                            <div>
                                                <div class="h1-dashboard">
                                                    Help Support
                                                </div><br>
                                                <div class="h3-dashboard" style="font-size: 15px;">
                                                    Report a problem
                                                </div>
                                            </div>
                                        </div>
                                    </a>
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
  const overlay = document.getElementById('menu-overlay');

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

  hamburgerMenu.addEventListener('click', () => {
    if (menu.classList.contains('active')) closeMenu();
    else openMenu();
  });

  overlay.addEventListener('click', closeMenu);

  // close drawer when clicking a menu link
  document.querySelectorAll('.menu a').forEach(a=>{
    a.addEventListener('click', closeMenu);
  });
</script>

</body>
</html>
