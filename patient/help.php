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

// If the form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $issue_type = htmlspecialchars($_POST["issue_type"]);
    $description = htmlspecialchars($_POST["description"]);

    // Save the problem report to Firebase
    $reportData = [
        "user_email" => $useremail,
        "issue_type" => $issue_type,
        "description" => $description,
        "date_reported" => date("Y-m-d H:i:s"),
    ];

    // Check if "support_reports" node exists, create it if not
    $supportReportsNode = $database->getReference("support_reports");
    if (!$supportReportsNode->getSnapshot()->exists()) {
        $supportReportsNode->set([]);
    }

    $supportReportsNode->push($reportData);

    // Redirect with a success message
    header("location: help.php?success=1");
    exit();
}

// Fetch recent reports
$supportReportsRef = $database->getReference("support_reports")->orderByChild("user_email")->equalTo($useremail);
$supportReportsSnapshot = $supportReportsRef->getSnapshot();
$supportReports = $supportReportsSnapshot->exists() ? $supportReportsSnapshot->getValue() : [];

// Fetch patient data
$patientRef = $database->getReference('patients')->orderByChild('email')->equalTo($useremail);
$patientSnapshot = $patientRef->getSnapshot();
$patientData = $patientSnapshot->getValue();

if ($patientData) {
    $patientRecord = array_values($patientData)[0]; // Get the first record
    $fname = $patientRecord['fname'] ?? '';
    $lname = $patientRecord['lname'] ?? '';
    $email = $patientRecord['email'] ?? '';
} else {
    echo "No patient data found.";
    exit();
}

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
    <title>Help Support</title>
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
        .dash-body {
            background: #fff;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        form {
            margin-top: 20px;
        }
        fieldset {
            border: none;
            padding: 10px 0;
        }
        legend {
            font-size: 18px;
            color: #4CAF50;
            font-weight: bold;
            margin-bottom: 10px;
        }
        label {
            font-weight: bold;
            color: #555;
            margin-bottom: 5px;
            display: block;
        }
        textarea,
        select {
            width: 100%;
            padding: 10px;
            margin-bottom: 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
        }
        textarea:focus,
        select:focus {
            border-color: #4CAF50;
            outline: none;
        }
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
        .btn-primary:hover {
            background-color: #45a049;
        }
        .faq-section {
            margin-top: 40px;
        }
        .faq-question {
            font-weight: bold;
            color: #333;
            cursor: pointer;
            margin-bottom: 10px;
        }
        .faq-answer {
            display: none;
            margin-bottom: 20px;
            color: #555;
        }
        .reports-section {
            margin-top: 40px;
        }
        .report-item {
            border-bottom: 1px solid #ddd;
            padding: 10px 0;
        }
        .report-item:last-child {
            border-bottom: none;
        }
        .report-item h4 {
            font-size: 16px;
            color: #4CAF50;
            margin: 0;
        }
        .report-item p {
            margin: 5px 0;
            font-size: 14px;
            color: #555;
        }

        /* =========================
   MOBILE HEADER + DRAWER (Help)
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

  /* hamburger inside header */
  #hamburger-menu{
    display:flex !important;
    position:static !important;
    width:28px;
    height:22px;
    cursor:pointer;
    flex-direction:column;
    justify-content:space-between;
    align-items:center;
    z-index:auto;
  }
  #hamburger-menu .bar{
    width:100%;
    height:3px;
    background:#333;
    border-radius:2px;
    transition:all .3s ease;
  }
  #hamburger-menu.active .bar:nth-child(1){ transform:rotate(-45deg) translate(-5px,5px); }
  #hamburger-menu.active .bar:nth-child(2){ opacity:0; }
  #hamburger-menu.active .bar:nth-child(3){ transform:rotate(45deg) translate(-5px,-5px); }

  /* sidebar = overlay drawer */
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
  .menu.active{ display:block; left:0; }

  /* ✅ DO NOT PUSH CONTENT (fixes the right-side gap issue) */
  .menu.active + .dash-body{ margin-left:0 !important; }

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
    margin-top:70px !important; /* space below header */
    margin-left:0 !important;
    padding:12px !important;
    box-sizing:border-box !important;
    border-radius:0 !important; /* remove boxed look on mobile */
  }

  /* hide desktop header row (Back/Title/Date row) */
  .dash-body > table > tbody > tr:first-child{
    display:none !important;
  }

  /* form + sections full width */
  form{ width:100% !important; margin:0 !important; }
  textarea, select{ font-size:15px; }

  .faq-section{ width:100% !important; margin-top:24px !important; }

  /* avoid horizontal scroll */
  html, body{ overflow-x:hidden !important; }
}

    </style>
    <script>
        function toggleAnswer(id) {
            const answer = document.getElementById(id);
            answer.style.display = answer.style.display === 'none' || answer.style.display === '' ? 'block' : 'none';
        }
    </script>
</head>
<body>
    <!-- ✅ MOBILE HEADER -->
<div class="mobile-header">
  <div class="mh-left">
    <div id="hamburger-menu" aria-label="Menu">
      <div class="bar"></div>
      <div class="bar"></div>
      <div class="bar"></div>
    </div>
  </div>

  <div class="mh-title">Help & Support</div>

  <div class="mh-right">
    <div class="mh-date">
      <div class="mh-date-label">Date</div>
      <div class="mh-date-value"><?php echo date('Y-m-d'); ?></div>
    </div>
    <button class="mh-cal btn-label" type="button" aria-label="Calendar">
      <img src="../img/calendar.svg" alt="">
    </button>
  </div>
</div>

<!-- ✅ overlay so menu doesn't push content -->
<div class="menu-overlay" id="menuOverlay"></div>


    <?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
        <script>
            alert("Your problem has been reported successfully!");
        </script>
    <?php endif; ?>
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
                                    <p class="profile-title"><?php echo substr($fname, 0, 100) ?></p>
                                    <p class="profile-subtitle"><?php echo substr($email, 0, 22) ?></p>
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
            <table border="0" width="100%" style="border-spacing: 0; margin: 0; padding: 0;">
                <tr>
                    <td width="13%">
                        <a href="settings.php"><button class="login-btn btn-primary-soft btn btn-icon-back" style="padding-top:11px; padding-bottom:11px; margin-left:20px; width:125px"><font class="tn-in-text">Back</font></button></a>
                    </td>
                    <td>
                        <p style="font-size: 23px; padding-left:12px; font-weight: 600;">Help & Support</p>
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
                        <button class="btn-label" style="display: flex; justify-content: center; align-items: center;"><img src="../img/calendar.svg" width="100%"></button>
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
                    <p class="faq-answer" id="faq1">To reset your password, submit a ticket with "Login Issues" and wait for the confirmation email from the IT. Follow the instructions sent to your email.</p>

                    <p class="faq-question" onclick="toggleAnswer('faq2')">What should I do if the app crashes?</p>
                    <p class="faq-answer" id="faq2">If the app crashes, try restarting it. If the issue persists, report the problem here with details.</p>
                </div>
            </div>
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
