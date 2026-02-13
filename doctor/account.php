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

// Import database connection
include("../connection.php");

// Fetch doctor data
$doctorRef = $database->getReference('doctor')->orderByChild('email')->equalTo($useremail);
$doctorSnapshot = $doctorRef->getSnapshot();
$doctorData = $doctorSnapshot->getValue();

if ($doctorData) {
    $doctorRecord = array_values($doctorData)[0];

    if (isset($doctorRecord['fname']) && isset($doctorRecord['lname'])) {
        $fname = $doctorRecord['fname'];
        $lname = $doctorRecord['lname'];
    } else {
        $fullName = $doctorRecord['name'] ?? '';
        $parts = explode(' ', trim($fullName));
        $fname = $parts[0] ?? '';
        $lname = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';
    }

    $email = $doctorRecord['email'] ?? '';
    $specialty = $doctorRecord['sname'] ?? ($doctorRecord['specialty'] ?? '');
} else {
    echo "No doctor data found.";
    exit();
}

// If the form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $fnameInput = htmlspecialchars($_POST['fname']);
    $lnameInput = htmlspecialchars($_POST['lname']);
    $specialtyInput = htmlspecialchars($_POST['ssname']);
    $password = htmlspecialchars($_POST['password']);

    $updatedData = [
        'fname' => $fnameInput,
        'lname' => $lnameInput,
        'name'  => $fnameInput . " " . $lnameInput,
        'specialty' => $specialtyInput,
        'sname' => $specialtyInput, // keep compatibility if you use sname elsewhere
    ];

    if (!empty($password)) {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $updatedData['password'] = $hashedPassword;
    }

    // Handle file upload for profile picture
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
        $target_dir = "../img/";
        $fileName = time() . "_" . basename($_FILES["photo"]["name"]);
        $target_file = $target_dir . $fileName;
        $fileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
        $allowedTypes = array("jpg", "jpeg", "png", "gif");

        if (in_array($fileType, $allowedTypes)) {
            if (move_uploaded_file($_FILES["photo"]["tmp_name"], $target_file)) {
                $updatedData['photo'] = $target_file;
            }
        }
    }

    $doctorKey = array_keys($doctorData)[0];
    $database->getReference('doctor/' . $doctorKey)->update($updatedData);

    header("location: account.php?success=1");
    exit();
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
    <title>Account Settings</title>

    <style>
        .dashbord-tables { animation: transitionIn-Y-over 0.5s; }
        .filter-container { animation: transitionIn-X 0.5s; }
        .sub-table { animation: transitionIn-Y-bottom 0.5s; }

        /* Keep your desktop design */
        form {
            background-color: #f9f9f9;
            box-shadow: 0px 4px 8px rgba(0, 0, 0, 0.1);
            padding: 20px;
            border-radius: 12px;
            margin-left: 200px;
            margin-top: 70px;
            width: 700px;
            max-width: calc(100% - 240px);
        }

        fieldset {
            background-color: #ffffff;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
        }
        legend {
            font-size: 18px;
            font-weight: bold;
            color: #333;
            padding: 0 10px;
            text-transform: uppercase;
        }

        input[type="text"], input[type="email"], input[type="password"], select, input[type="file"] {
            width: 100%;
            padding: 10px;
            margin: 5px 0 15px;
            border: 1px solid #ccc;
            border-radius: 8px;
            box-sizing: border-box;
            font-size: 14px;
            font-family: Arial, sans-serif;
        }
        input:focus {
            border-color: #4caf50;
            outline: none;
            box-shadow: 0 0 5px rgba(76, 175, 80, 0.5);
        }
        button.btn {
            font-size: 16px;
            padding: 10px 20px;
            margin: 10px 10px 0 0;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        button.btn-primary { background-color: #4caf50; color: #fff; }
        button.btn-primary:hover { background-color: #45a049; }
        button.btn-secondary { background-color: #f44336; color: #fff; }
        button.btn-secondary:hover { background-color: #d32f2f; }

        label {
            font-weight: bold;
            font-size: 14px;
            color: #333;
            margin-bottom: 5px;
            display: inline-block;
        }

        /* Desktop default: hide mobile header + overlay */
        .mobile-header { display: none; }
        #menu-overlay { display: none; }

        /* =========================================================
           MOBILE VIEW (maximize spaces + header like your screenshot)
           ========================================================= */
        @media (max-width: 768px) {

            /* hide desktop top row on mobile */
            .desktop-topbar { display: none !important; }

            /* Mobile header layout: left burger, center title, right date + icon */
            .mobile-header{
                display: flex;
                position: fixed;
                top: 0; left: 0; right: 0;
                height: 56px;
                background: lightgreen;
                z-index: 1200;
                align-items: center;
                justify-content: space-between;
                padding: 0 10px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.12);
            }
            .mobile-left{
                width: 44px;
                display: flex;
                align-items: center;
                justify-content: flex-start;
            }
            .mobile-center{
                flex: 1;
                text-align: center;
                font-weight: 700;
                font-size: 16px;
                color: #000;
            }
            .mobile-right{
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .mobile-date{
                font-size: 13px;
                font-weight: 600;
                color: #000;
                white-space: nowrap;
            }
            .mobile-calendar-btn{
                background: rgba(255,255,255,0.5);
                border: none;
                width: 34px;
                height: 34px;
                border-radius: 8px;
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
            }
            .mobile-calendar-btn img{
                width: 18px;
                height: 18px;
            }

            /* Place the hamburger inside header (not floating weird) */
            #hamburger-menu{
                display: flex;
                position: static;
                width: 26px;
                height: 20px;
                cursor: pointer;
                flex-direction: column;
                justify-content: space-between;
            }
            #hamburger-menu .bar{
                width: 100%;
                height: 3px;
                background: #333;
                transition: all .3s ease;
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
                z-index: 1100;
            }
            body.menu-open #menu-overlay{ display:block; }

            /* Drawer sidebar */
            .menu{
                position: fixed;
                top: 56px;
                left: 0;
                width: 270px;
                height: calc(100vh - 56px);
                background: lightgreen;
                transform: translateX(-100%);
                transition: transform .25s ease;
                z-index: 1150;
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
                display: block; /* override anything that hides it */
            }
            body.menu-open .menu{ transform: translateX(0); }

            body.menu-open{ overflow: hidden; }

            /* Maximize content space */
            .dash-body{
                margin-top: 66px !important; /* space under header */
                margin-left: 0 !important;
                padding: 50px 0 0 90px !important;
            }

            /* Form full width on mobile */
            form{
                margin: 12px auto 20px auto !important;
                width: 100% !important;
                max-width: 560px !important;
                margin-left: 0 !important;
                margin-top: 0 !important;
                padding: 16px !important;
                box-sizing: border-box;
            }

            input[type="text"], input[type="email"], input[type="password"], select, input[type="file"]{
                font-size: 13px;
                padding: 10px;
            }

            button.btn{
                width: 100%;
                margin: 10px 0 0 0;
                padding: 10px 14px;
                font-size: 14px;
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

        <div class="mobile-center">Account Settings</div>

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
                                    <p class="profile-title"><?php echo htmlspecialchars($fname . " " . $lname); ?></p>
                                    <p class="profile-subtitle"><?php echo substr($email, 0, 50); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="2">
                                    <a href="../logout.php">
                                        <input type="button" value="Log out" class="logout-btn btn-primary-soft btn">
                                    </a>
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
                        <p style="font-size: 23px; padding-left:12px; font-weight: 600;">Account Settings</p>
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

            <!-- Trigger alert on successful update -->
            <?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
                <script>
                    alert("Account updated successfully!");
                </script>
            <?php endif; ?>

            <!-- Form (maximized for mobile in CSS) -->
            <form action="account.php" method="POST" enctype="multipart/form-data">
                <fieldset>
                    <label for="fname">First Name:</label>
                    <input type="text" id="fname" name="fname" value="<?php echo htmlspecialchars($fname); ?>" required>

                    <label for="lname">Last Name:</label>
                    <input type="text" id="lname" name="lname" value="<?php echo htmlspecialchars($lname); ?>" required>

                    <label for="specialty">Specialty:</label>
                    <input type="text" id="specialty" name="ssname" value="<?php echo htmlspecialchars($specialty); ?>">

                    <label for="password">New Password:</label>
                    <input type="password" id="password" name="password">

                    <label for="photo">Profile Picture:</label>
                    <input type="file" id="photo" name="photo">
                </fieldset>

                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="settings.php" style="text-decoration:none;">
                    <button type="button" class="btn btn-secondary">Cancel</button>
                </a>
            </form>
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

        // optional: close menu when clicking a sidebar link on mobile
        document.querySelectorAll('.menu a').forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth <= 768) closeMenu();
            });
        });
    </script>
</body>
</html>
