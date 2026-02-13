<?php
session_name('sess_admin'); session_start();

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

include("../connection.php");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $issue_type  = htmlspecialchars($_POST["issue_type"]);
    $description = htmlspecialchars($_POST["description"]);
    $reportData  = [
        "user_email"    => $useremail,
        "issue_type"    => $issue_type,
        "description"   => $description,
        "date_reported" => date("Y-m-d H:i:s"),
    ];
    $database->getReference("support_reports")->push($reportData);
    header("location: help.php?success=1");
    exit();
}

$supportReportsRef     = $database->getReference("support_reports")->orderByChild("user_email")->equalTo($useremail);
$supportReportsSnapshot = $supportReportsRef->getSnapshot();
$supportReports        = $supportReportsSnapshot->exists() ? $supportReportsSnapshot->getValue() : [];

$adminRef       = $database->getReference('admin')->orderByChild('email')->equalTo($useremail);
$adminSnapshot  = $adminRef->getSnapshot();
$adminData      = $adminSnapshot->getValue();

if ($adminData) {
    $adminRecord = array_values($adminData)[0];
    $name  = $adminRecord['name']  ?? '';
    $email = $adminRecord['email'] ?? '';
} else {
    echo "No admin data found.";
    exit();
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
        box-shadow: 0 4px 8px rgba(0,0,0,0.1); 
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

    textarea, select {
         width: 100%; 
         padding: 10px; 
         margin-bottom: 20px;
          border: 1px solid #ddd;
          border-radius: 5px; 
         font-size: 16px; 
        }
    textarea:focus, select:focus { 
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
   MOBILE FIX (HELP SUPPORT)
   Put at VERY BOTTOM
   ========================= */
@media (max-width: 768px){

  html, body{ width:100%; overflow-x:hidden; }

  /* fixed green header */
  .mobile-header{
    display:flex;
    position:fixed;
    top:0; left:0; right:0;
    height:56px;
    background:lightgreen;
    z-index:10050;
    align-items:center;
    justify-content:space-between;
    padding:0 12px;
    box-shadow:0 2px 10px rgba(0,0,0,.12);
  }

  .m-left,.m-right{
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
    padding:0 10px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
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
    background:rgba(255,255,255,.6);
    display:inline-flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    padding:0;
  }
  .m-cal-btn img{ width:18px; height:18px; display:block; }

  /* hamburger button */
  #hamburger-menu{
    width:34px;
    height:34px;
    border-radius:10px;
    background:rgba(255,255,255,.6);
    display:flex;
    flex-direction:column;
    justify-content:center;
    gap:5px;
    padding:8px;
    cursor:pointer;
    user-select:none;
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

  /* space for fixed header */
  .container{
    padding-top:56px !important;
    width:100% !important;
    max-width:100% !important;
  }

  /* hide desktop top header row inside dash-body */
  .dash-body > table > tbody > tr:first-child{
    display:none !important;
  }

  /* use full width for content */
  .dash-body{
    margin:0 !important;
    padding:12px !important;
    width:100% !important;
    border-radius:0 !important;
    box-shadow:none !important;
  }

  /* make form/sections full width */
  form{ margin-top:10px !important; }
  textarea, select{
    font-size:15px !important;
  }
  .btn-primary{
    width:100% !important;
    padding:12px 14px !important;
    border-radius:10px !important;
  }

  /* Drawer menu */
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
  .menu.active{ left:0 !important; }

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

  </style>
  <script>
    function toggleAnswer(id) {
      const answer = document.getElementById(id);
      answer.style.display = (answer.style.display === 'none' || answer.style.display === '') ? 'block' : 'none';
    }
  </script>
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

  <div class="m-title">Help & Support</div>

  <div class="m-right">
    <div class="m-date"><?php date_default_timezone_set('Asia/Manila'); echo date('Y-m-d'); ?></div>
    <button class="m-cal-btn" type="button">
      <img src="../img/calendar.svg" alt="Calendar">
    </button>
  </div>
</div>

<!-- Drawer overlay -->
<div id="menu-overlay"></div>


  <?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
  <script> alert("Your problem has been reported successfully!"); </script>
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
                <td style="padding:0;margin:0;">
                  <p class="profile-title"><?php echo substr($name, 0, 50); ?></p>
                  <p class="profile-subtitle"><?php echo substr($email, 0, 50); ?></p>
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
            <p style="font-size: 23px; padding-left:12px; font-weight: 600;">Help & Support</p>
          </td>
          <td width="15%" style="text-align: right;">
            <p style="font-size: 14px; color: rgb(119,119,119); padding: 0; margin: 0;">Today's Date</p>
            <p class="heading-sub12" style="padding: 0; margin: 0;">
                <?php date_default_timezone_set('Asia/Manila'); echo date('Y-m-d'); ?>
            </p>
          </td>
          <td width="10%">
            <button class="btn-label" style="display: flex; justify-content: center; align-items: center;"><img src="../img/calendar.svg" width="100%">
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

  // optional: close drawer after clicking a menu link
  document.querySelectorAll('.menu a').forEach(a=>{
    a.addEventListener('click', closeMenu);
  });

  // accessibility: Enter key opens/closes too
  hamburgerMenu.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') hamburgerMenu.click();
  });
</script>


</body>
</html>
