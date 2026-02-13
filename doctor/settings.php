<?php
session_name('sess_doctor'); session_start();

if(isset($_SESSION["user"])){
    if(($_SESSION["user"])=="" or $_SESSION['usertype']!='d'){
        header("location: ../login.php");
        exit();
    }else{
        $useremail=$_SESSION["user"];
    }
}else{
    header("location: ../login.php");
    exit();
}

include("../connection.php");

$doctorRef = $database->getReference('doctor')->orderByChild('email')->equalTo($useremail);
$doctorData = $doctorRef->getValue();

if ($doctorData) {
    $doctorData = array_shift($doctorData);
    $username = isset($doctorData['name']) ? $doctorData['name'] : null;
}

// Fetch the doctor's photo from Firebase (default image if none exists)
$doctorRef = $database->getReference('doctor')->orderByChild('email')->equalTo($useremail);
$doctorData = $doctorRef->getValue();

$photo = '../img/user.png';
if ($doctorData) {
    $doctor = reset($doctorData);
    if (!empty($doctor['photo'])) {
        $photo = $doctor['photo'];
    }
}

date_default_timezone_set('Asia/Manila');
$today = date('Y-m-d');
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
        .dashbord-tables{ animation: transitionIn-Y-over 0.5s; }
        .filter-container{ animation: transitionIn-X 0.5s; }
        .sub-table{ animation: transitionIn-Y-bottom 0.5s; }

        .menu-btn a{
            display:block;
            text-decoration:none;
            padding:1px;
            width:100%;
        }
        .menu-btn:hover{ background-color:#E8F8E8; }

        /* ===== Desktop: hide mobile header/overlay ===== */
        .mobile-header{ display:none; }
        #menu-overlay{ display:none; }

        /* ===== Mobile Header  ===== */
        @media screen and (max-width: 768px){

            /* Show mobile header */
            .mobile-header{
                display:flex;
                align-items:center;
                justify-content:space-between;
                height:56px;
                background:lightgreen;
                padding:0 14px;
                position:fixed;
                top:0; left:0; right:0;
                z-index:1100;
                box-shadow:0 2px 10px rgba(0,0,0,0.12);
            }

            .mobile-left{ width:30px; } /* spacer */
            .mobile-center{
                flex:1;
                text-align:center;
                font-size:16px;
                font-weight:700;
                color:#000;
            }
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
                padding:0;
            }
            .mobile-calendar-btn img{
                width:18px;
                height:18px;
            }

            /* Hamburger position above header */
            #hamburger-menu{
                display:flex;
                width:30px;
                height:30px;
                position:fixed;
                top:14px;
                left:14px;
                z-index:1200;
                cursor:pointer;
                flex-direction:column;
                justify-content:space-between;
                align-items:center;
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
            /* Overlay */
            #menu-overlay{
                display:none;
                position:fixed;
                inset:0;
                background:rgba(0,0,0,0.45);
                z-index:1090;
            }
            body.menu-open #menu-overlay{ display:block; }

            /* Drawer menu */
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
                display:block; /* override any display none */
            }
            body.menu-open .menu{ transform:translateX(0); }

            /* Content spacing */
            .dash-body{
                margin-top:70px !important;
                margin-left:0 !important;
                padding:0 12px !important;
            }

            /* Hide the desktop top date row (your current one is messy on mobile) */
            .desktop-topbar{ display:none !important; }

            /* Fix cards layout: prevent text overlapping on small screens */
            .dashboard-items.setting-tabs{
                width:100% !important;
                margin:0 auto !important;
                gap:12px;
            }
            .dashboard-items.setting-tabs .h1-dashboard{
                font-size:18px !important;
                line-height:1.2 !important;
                word-break:break-word;
            }
            .dashboard-items.setting-tabs .h3-dashboard{
                font-size:14px !important;
                line-height:1.2 !important;
                word-break:break-word;
            }

            /* Make container inside table full width */
            table.filter-container{
                width:100% !important;
            }
            table.filter-container td{
                width:100% !important;
                display:block;
            }
        }
    </style>
</head>

<body>

    <!-- Mobile Header -->
    <div class="mobile-header">
        <div class="mobile-left"></div>
        <div class="mobile-center">Settings</div>
        <div class="mobile-right">
            <div class="mobile-date"><?php echo $today; ?></div>
            <button class="mobile-calendar-btn" type="button">
                <img src="../img/calendar.svg" alt="Calendar">
            </button>
        </div>
    </div>

    <!-- Hamburger Menu -->
    <div id="hamburger-menu">
        <div class="bar"></div>
        <div class="bar"></div>
        <div class="bar"></div>
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
                <tr class="menu-row"><td class="menu-btn"><a href="summary.php" class="non-style-link-menu"><p class="menu-text">Summary</p></a></td></tr>
                <tr class="menu-row"><td class="menu-btn menu-active"><a href="settings.php" class="non-style-link-menu non-style-link-menu-active"><p class="menu-text">Settings</p></a></td></tr>
            </table>
        </div>

        <div class="dash-body" style="margin-top: 15px">
            <table border="0" width="100%" style="border-spacing:0;margin:0;padding:0;">
                
                <!-- Desktop top bar (keep as-is for desktop) -->
                <tr class="desktop-topbar">
                    <td>
                        <p style="font-size: 23px;padding-left:12px;font-weight: 600;">Settings</p>
                    </td>
                    <td width="15%">
                        <p style="font-size: 14px;color: rgb(119, 119, 119);padding: 0;margin: 0;text-align: right;">
                            Today's Date
                        </p>
                        <p class="heading-sub12" style="padding: 0;margin: 0;text-align:right;">
                            <?php echo $today; ?>
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
                        <table class="filter-container" style="border:none; width:100%;" border="0">
                            <tr>
                                <td colspan="4"><p style="font-size: 20px">&nbsp;</p></td>
                            </tr>

                            <tr>
                                <td style="width: 25%;">
                                    <a href="account.php" class="non-style-link">
                                        <div class="dashboard-items setting-tabs" style="padding:20px;margin:auto;width:95%;display:flex;">
                                            <div class="btn-icon-back dashboard-icons-setting" style="background-image: url('../img/icons/doctors-hover.svg');"></div>
                                            <div>
                                                <div class="h1-dashboard">View Account Details &nbsp;</div><br>
                                                <div class="h3-dashboard" style="font-size: 15px;">
                                                    View Personal information About Your Account
                                                </div>
                                            </div>
                                        </div>
                                    </a>
                                </td>
                            </tr>

                            <tr>
                                <td colspan="4"><p style="font-size: 5px">&nbsp;</p></td>
                            </tr>

                            <tr>
                                <td style="width: 25%;">
                                    <a href="help.php" class="non-style-link">
                                        <div class="dashboard-items setting-tabs" style="padding:20px;margin:auto;width:95%;display:flex;">
                                            <div class="btn-icon-back dashboard-icons-setting" style="background-image: url('../img/icons/view-iceblue.svg');"></div>
                                            <div>
                                                <div class="h1-dashboard">Help Support</div><br>
                                                <div class="h3-dashboard" style="font-size: 15px;">
                                                    Report a Problem
                                                </div>
                                            </div>
                                        </div>
                                    </a>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

            </table>
        </div>
    </div>

<?php
// KEEP ALL YOUR POPUP LOGIC EXACTLY AS IT IS (unchanged)
if($_GET){
    $id=$_GET["id"];
    $action=$_GET["action"];
    // ... your existing popup code remains here exactly the same ...
}
?>

<script>
    // Drawer menu (same behavior as Summary)
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
