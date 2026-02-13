<?php
session_name('sess_admin'); 
session_start();

// Check if the user is logged in and is an admin (usertype 'a')
if (isset($_SESSION["user"])) {
    if (empty($_SESSION["user"]) || $_SESSION['usertype'] != 'a') {
        header("location: ../login.php");
        exit();
    } else {
        $useremail = $_SESSION["user"];
    }
} else {
    header("location: ../login.php");
    exit();
}

// Import the database connection 
include("../connection.php");

// Fetch admin data from the Firebase "admin"
$adminRef = $database->getReference('admin')->orderByChild('email')->equalTo($useremail);
$adminSnapshot = $adminRef->getSnapshot();
$adminData = $adminSnapshot->getValue();

if ($adminData) {
    $adminRecord = array_values($adminData)[0];

    if (isset($adminRecord['fname']) && isset($adminRecord['lname'])) {
        $fname = $adminRecord['fname'];
        $lname = $adminRecord['lname'];
    } else {
        $fullName = $adminRecord['name'] ?? '';
        $parts = explode(' ', trim($fullName));
        $fname = $parts[0] ?? '';
        $lname = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';
    }
    
    $email = $adminRecord['email'] ?? '';
    $sname = $adminRecord['sname'] ?? '';  
} else {
    echo "No admin data found.";
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $fnameInput = htmlspecialchars($_POST['fname']);
    $lnameInput = htmlspecialchars($_POST['lname']);
    $department = htmlspecialchars($_POST['ssname']);
    $password = htmlspecialchars($_POST['password']);
    $updatedData = [
        'fname' => $fnameInput,
        'lname' => $lnameInput,
        'name'  => $fnameInput . " " . $lnameInput,
        'sname' => $department,
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

   
    $adminKey = array_keys($adminData)[0];
    $database->getReference('admin/' . $adminKey)->update($updatedData);

 
    header("location: account.php?success=1");
    exit();
}

// Fetch the admin's photo from Firebase 
$adminRef = $database->getReference('admin')->orderByChild('email')->equalTo($useremail);
$adminData = $adminRef->getValue();

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
    <title>Account Settings - Admin</title>
   <style>
  .dashbord-tables { animation: transitionIn-Y-over 0.5s; }
  .filter-container { animation: transitionIn-X 0.5s; }
  .sub-table { animation: transitionIn-Y-bottom 0.5s; }

  /* Style for the form */
  form {
    background-color: #f9f9f9;
    box-shadow: 0px 4px 8px rgba(0, 0, 0, 0.1);
    padding: 20px;
    border-radius: 12px;
    margin-left: 200px;
    margin-top: 70px;
    max-width: 600px;
  }

  /* Fieldset styling */
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

  /* Input fields */
  input[type="text"],
  input[type="email"],
  input[type="password"],
  select,
  input[type="file"] {
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

  /* Buttons */
  button.btn {
    font-size: 16px;
    padding: 10px 20px;
    margin: 10px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s ease;
  }

  button.btn-primary {
    background-color: #4caf50;
    color: #fff;
  }
  button.btn-primary:hover { background-color: #45a049; }

  button.btn-secondary {
    background-color: #f44336;
    color: #fff;
  }
  button.btn-secondary:hover { background-color: #d32f2f; }

  /* Labels */
  label {
    font-weight: bold;
    font-size: 14px;
    color: #333;
    margin-bottom: 5px;
    display: inline-block;
  }

  /* Spacing and alignment */
  form div {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 20px;
  }
  form div button { width: auto; }

  /* =========================
     MOBILE FIX (ACCOUNT PAGE)
     ========================= */
  @media (max-width: 768px) {

    html, body { width: 100%; overflow-x: hidden; }

    /* fixed green header */
    .mobile-header {
      display: flex !important;
      position: fixed !important;
      top: 0; left: 0; right: 0;
      height: 56px;
      background: lightgreen;
      z-index: 10050;
      align-items: center;
      justify-content: space-between;
      padding: 0 12px;
      box-shadow: 0 2px 10px rgba(0,0,0,.12);
    }

    .m-left, .m-right {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .m-title {
      flex: 1;
      text-align: center;
      font-weight: 700;
      font-size: 16px;
      color: #000;
      padding: 0 10px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .m-date {
      font-size: 12px;
      font-weight: 600;
      color: #000;
      white-space: nowrap;
    }

    .m-cal-btn {
      width: 34px;
      height: 34px;
      border-radius: 10px;
      border: none;
      background: rgba(255,255,255,.6);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      padding: 0;
    }
    .m-cal-btn img { width: 18px; height: 18px; display: block; }

    /* hamburger */
    #hamburger-menu {
      width: 34px !important;
      height: 34px !important;
      border-radius: 10px !important;
      background: rgba(255,255,255,.6) !important;
      display: flex !important;
      flex-direction: column !important;
      justify-content: center !important;
      gap: 5px !important;
      padding: 8px !important;
      cursor: pointer !important;
      user-select: none !important;
      position: static !important;
    }
    #hamburger-menu .bar {
      height: 2px !important;
      width: 100% !important;
      background: #111 !important;
      border-radius: 2px !important;
      transition: all .25s ease !important;
    }
    #hamburger-menu.active .bar:nth-child(1) {
      transform: rotate(-45deg) translate(-4px, 5px) !important;
    }
    #hamburger-menu.active .bar:nth-child(2) { opacity: 0 !important; }
    #hamburger-menu.active .bar:nth-child(3) {
      transform: rotate(45deg) translate(-4px, -5px) !important;
    }

    /* space for fixed header */
    .container {
      padding-top: 56px !important;
      width: 100% !important;
      max-width: 100% !important;
    }

    /* hide desktop top header row */
    .dash-body > table > tbody > tr:first-child {
      display: none !important;
    }

    /* use full width */
    .dash-body {
      margin: 0 !important;
      padding: 12px !important;
      width: 100% !important;
      border-radius: 0 !important;
      box-shadow: none !important;
    }

    /* form full width on mobile */
    form {
      margin: 0 !important;
      margin-top: 10px !important;
      width: 100% !important;
      max-width: 100% !important;
      border-radius: 12px !important;
    }

    /* inputs slightly smaller */
    input[type="text"],
    input[type="email"],
    input[type="password"],
    select,
    input[type="file"] {
      font-size: 12px;
    }

    /* buttons full width */
    .btn.btn-primary,
    .btn.btn-secondary {
      width: 100% !important;
      margin: 8px 0 !important;
      padding: 8px 15px;
      font-size: 14px;
    }

    /* Drawer menu */
    .menu {
      display: block !important;
      position: fixed !important;
      top: 56px !important;
      left: -280px !important;
      width: 280px !important;
      max-width: 86vw !important;
      height: calc(100vh - 56px) !important;
      background: lightgreen !important;
      transition: left .25s ease !important;
      z-index: 10040 !important;
      overflow-y: auto !important;
      -webkit-overflow-scrolling: touch !important;
      box-shadow: 10px 0 30px rgba(0,0,0,.12) !important;
    }
    .menu.active { left: 0 !important; }

    /* Overlay */
    #menu-overlay {
      display: block !important;
      position: fixed !important;
      inset: 0 !important;
      background: rgba(0,0,0,.35) !important;
      opacity: 0 !important;
      pointer-events: none !important;
      transition: opacity .2s ease !important;
      z-index: 10030 !important;
    }
    body.menu-open #menu-overlay {
      opacity: 1 !important;
      pointer-events: auto !important;
    }

    body.menu-open { overflow: hidden; }
  }
</style>

</head>
<body>
    <!-- Mobile Header -->
<div class="mobile-header">
  <div class="m-left">
    <div id="hamburger-menu" aria-label="Open menu" role="button" tabindex="0">
      <div class="bar"></div>
      <div class="bar"></div>
      <div class="bar"></div>
    </div>
  </div>

  <div class="m-title">Account Settings</div>

  <div class="m-right">
    <div class="m-date"><?php date_default_timezone_set('Asia/Manila'); echo date('Y-m-d'); ?></div>
    <button class="m-cal-btn" type="button">
      <img src="../img/calendar.svg" alt="Calendar">
    </button>
  </div>
</div>

<!-- Drawer overlay -->
<div id="menu-overlay"></div>


    <div class="container">
        <!-- Sidebar Menu -->
        <div class="menu">
            <table class="menu-container" border="0">
                <tr>
                    <td style="padding:10px" colspan="2">
                        <table border="0" class="profile-container">
                            <tr>
                                <td width="30%" style="padding-left:20px">
                                    <img src="<?php echo htmlspecialchars($photo); ?>" alt="Profile Picture" width="100%" style="border-radius:50%">
                                </td>
                                <td style="padding:0; margin:0;">
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
            <table border="0" width="100%" style="border-spacing: 0; margin: 0; padding: 0;">
                <tr>
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
                        <p class="heading-sub12" style="padding: 0; margin: 0;">
                            <?php
                            date_default_timezone_set('Asia/Manila');
                            echo date('Y-m-d');
                            ?>
                        </p>
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

            <div class="dash-body">
                <form action="account.php" method="POST" enctype="multipart/form-data">
                    <fieldset>
                        <label for="fname">First Name:</label>
                        <input type="text" id="fname" name="fname" value="<?php echo htmlspecialchars($fname); ?>" required>

                        <label for="lname">Last Name:</label>
                        <input type="text" id="lname" name="lname" value="<?php echo htmlspecialchars($lname); ?>" required>

                        <label for="ssname">Department:</label>
                        <input type="text" id="ssname" name="ssname" value="<?php echo htmlspecialchars($sname); ?>">

                        <label for="password">New Password:</label>
                        <input type="password" id="password" name="password">

                        <label for="photo">Profile Picture:</label>
                        <input type="file" id="photo" name="photo">
                    </fieldset>

                    <button type="submit" class="btn btn-primary">Save Changes</button>
                    <a href="settings.php">
                        <button type="button" class="btn btn-secondary">Cancel</button>
                    </a>
                </form>
            </div>
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

  // close drawer after tapping a link
  document.querySelectorAll('.menu a').forEach(a=>{
    a.addEventListener('click', closeMenu);
  });

  // Enter key support
  hamburgerMenu.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') hamburgerMenu.click();
  });
</script>


</body>
</html>
