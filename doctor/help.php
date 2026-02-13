<?php
session_name('sess_doctor'); session_start();
if (isset($_SESSION["user"])) {
    if (empty($_SESSION["user"]) || $_SESSION['usertype'] != 'd') {
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

// If the form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $issue_type = htmlspecialchars($_POST["issue_type"]);
    $description = htmlspecialchars($_POST["description"]);

    $reportData = [
        "user_email" => $useremail,
        "issue_type" => $issue_type,
        "description" => $description,
        "date_reported" => date("Y-m-d H:i:s"),
    ];

    $database->getReference("support_reports")->push($reportData);

    header("location: help.php?success=1");
    exit();
}

// Fetch recent reports for the logged-in doctor
$supportReportsRef = $database->getReference("support_reports")->orderByChild("user_email")->equalTo($useremail);
$supportReportsSnapshot = $supportReportsRef->getSnapshot();
$supportReports = $supportReportsSnapshot->exists() ? $supportReportsSnapshot->getValue() : [];

// Fetch doctor data
$doctorRef = $database->getReference('doctor')->orderByChild('email')->equalTo($useremail);
$doctorSnapshot = $doctorRef->getSnapshot();
$doctorData = $doctorSnapshot->getValue();

if ($doctorData) {
    $doctorRecord = array_values($doctorData)[0];
    $name = $doctorRecord['name'] ?? '';
    $email = $doctorRecord['email'] ?? '';
} else {
    echo "No doctor data found.";
    exit();
}

// Fetch the doctor's photo from Firebase
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
    <title>Help Support</title>

    <style>
        .dashbord-tables { animation: transitionIn-Y-over 0.5s; }
        .filter-container { animation: transitionIn-X 0.5s; }
        .sub-table { animation: transitionIn-Y-bottom 0.5s; }

        /* Desktop design (keep) */
        .dash-body {
            background: #fff;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        form { margin-top: 20px; }
        fieldset { border: none; padding: 10px 0; }
        legend { font-size: 18px; color: #4CAF50; font-weight: bold; margin-bottom: 10px; }
        label { font-weight: bold; color: #555; margin-bottom: 5px; display: block; }

        textarea, select {
            width: 100%;
            padding: 10px;
            margin-bottom: 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            box-sizing: border-box;
        }
        textarea:focus, select:focus { border-color: #4CAF50; outline: none; }

        .btn-primary {
            background-color: #4CAF50;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }
        .btn-primary:hover { background-color: #45a049; }

        .faq-section { margin-top: 40px; }
        .faq-question { font-weight: bold; color: #333; cursor: pointer; margin-bottom: 10px; }
        .faq-answer { display: none; margin-bottom: 20px; color: #555; }

        /* Desktop default: hide mobile header + overlay */
        .mobile-header { display:none; }
        #menu-overlay { display:none; }

        /* ========= MOBILE HEADER (same style as your Patient Health Records) ========= */
        @media (max-width: 768px){

            /* hide desktop top row on mobile */
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
            .mobile-left{
                width:44px;
                display:flex;
                align-items:center;
                justify-content:flex-start;
            }
            .mobile-center{
                flex:1;
                text-align:center;
                font-weight:700;
                font-size:16px;
                color:#000;
            }
            .mobile-right{
                display:flex;
                align-items:center;
                gap:10px;
            }
            .mobile-date{
                font-size:13px;
                font-weight:600;
                color:#000;
                white-space:nowrap;
            }
            .mobile-calendar-btn{
                background: rgba(255,255,255,0.5);
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

            /* Hamburger inside header */
            #hamburger-menu{
                display:flex;
                position:static;
                width:26px;
                height:20px;
                cursor:pointer;
                flex-direction:column;
                justify-content:space-between;
            }
            #hamburger-menu .bar{
                width:100%;
                height:3px;
                background:#333;
                transition:all .3s ease;
            }
            #hamburger-menu.active .bar:nth-child(1){ transform: rotate(-45deg) translate(-4px, 5px); }
            #hamburger-menu.active .bar:nth-child(2){ opacity: 0; }
            #hamburger-menu.active .bar:nth-child(3){ transform: rotate(45deg) translate(-4px, -5px); }

            /* Overlay */
            #menu-overlay{
                display:none;
                position:fixed;
                inset:0;
                background: rgba(0,0,0,0.45);
                z-index:1100;
            }
            body.menu-open #menu-overlay{ display:block; }

            /* Drawer sidebar */
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

            /* Maximize content space */
            .dash-body{
                margin-top:66px !important;   /* under header */
                margin-left:0 !important;
                padding:12px !important;
            }

            /* Improve form spacing on small screens */
            textarea, select{
                font-size:14px;
            }
            .btn-primary{
                width:100%;
                padding:10px 14px;
            }
        }
    </style>

    <script>
        function toggleAnswer(id) {
            const answer = document.getElementById(id);
            answer.style.display = (answer.style.display === 'none' || answer.style.display === '') ? 'block' : 'none';
        }
    </script>
</head>

<body>
<?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
    <script>alert("Your problem has been reported successfully!");</script>
<?php endif; ?>

<!-- Mobile Header -->
<div class="mobile-header">
    <div class="mobile-left">
        <div id="hamburger-menu">
            <div class="bar"></div>
            <div class="bar"></div>
            <div class="bar"></div>
        </div>
    </div>

    <div class="mobile-center">Help Support</div>

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
                                <p class="profile-title"><?php echo substr($name, 0, 50) ?></p>
                                <p class="profile-subtitle"><?php echo substr($email, 0, 50) ?></p>
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
        <table border="0" width="100%" style="border-spacing: 0; margin: 0; padding: 0;">
            <tr class="desktop-topbar">
                <td width="13%">
                    <a href="settings.php">
                        <button class="login-btn btn-primary-soft btn btn-icon-back" style="padding-top:11px; padding-bottom:11px; margin-left:20px; width:125px">
                            <font class="tn-in-text">Back</font>
                        </button>
                    </a>
                </td>
                <td>
                    <p style="font-size: 23px; padding-left:12px; font-weight: 600;">Help & Support</p>
                </td>
                <td width="15%" style="text-align: right;">
                    <p style="font-size: 14px; color: rgb(119, 119, 119); padding: 0; margin: 0;">Today's Date</p>
                    <p class="heading-sub12" style="padding: 0; margin: 0;"><?php echo $today; ?></p>
                </td>
                <td width="10%">
                    <button class="btn-label" style="display: flex; justify-content: center; align-items: center;">
                        <img src="../img/calendar.svg" width="100%">
                    </button>
                </td>
            </tr>
        </table>

        <form action="help.php" method="POST">
            <fieldset>
                <legend>Report a Problem</legend>

                <label for="issue_type">Issue Type:</label>
                <select id="issue_type" name="issue_type" required>
                    <option value="">Select an issue type</option>
                    <option value="Login Issues">Login Issues</option>
                    <option value="Bug">Bug</option>
                    <option value="Feature Request">Feature Request</option>
                    <option value="Other">Other</option>
                </select>

                <label for="description">Description:</label>
                <textarea id="description" name="description" rows="5" placeholder="Describe the issue..." required></textarea>
            </fieldset>

            <button type="submit" class="btn-primary">Submit Report</button>
        </form>

        <div class="faq-section">
            <h3>Frequently Asked Questions</h3>
            <div>
                <p class="faq-question" onclick="toggleAnswer('faq1')">How can I reset my password?</p>
                <p class="faq-answer" id="faq1">To reset your password, Submit a ticket with "Login Issues" and wait for the confirmation email from the IT. Follow the instructions sent to your email.</p>

                <p class="faq-question" onclick="toggleAnswer('faq2')">What should I do if the app crashes?</p>
                <p class="faq-answer" id="faq2">If the app crashes, try restarting it. If the issue persists, report the problem here with details.</p>
            </div>
        </div>
    </div>
</div>

<script>
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

    // optional: close menu when clicking any sidebar link (mobile)
    document.querySelectorAll('.menu a').forEach(link=>{
        link.addEventListener('click', () => {
            if(window.innerWidth <= 768) closeMenu();
        });
    });
</script>
</body>
</html>
