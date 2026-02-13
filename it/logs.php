<?php
session_name('sess_it'); 
session_start();

if(isset($_SESSION["user"])){
    if(($_SESSION["user"]) == "" || $_SESSION['usertype'] != 'it'){
        header("location: ../login.php");
    } else {
        $useremail = $_SESSION["user"];
    }
} else {
    header("location: ../login.php");
}

include("../connection.php");

date_default_timezone_set('Asia/Manila');
$today = date('Y-m-d');
    
try {
    $reference = $database
        ->getReference('it')
        ->orderByChild('email')  
        ->equalTo($useremail)
        ->getSnapshot();
    $userfetch = $reference->getValue();

    if ($userfetch) {
        foreach($userfetch as $key => $value) {
            $username = $value['name']; 
        }
    } else {
        echo "No user found.";
    }
} catch (\Kreait\Firebase\Exception\DatabaseException $e) {
    echo "Error querying the database: " . $e->getMessage();
}

$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : ''; 
$loginAttemptRows = ''; 

$logs = $database->getReference('logs')->getValue();
$logsCount = is_array($logs) ? count($logs) : 0;

if ($logs) {
    $filteredLogs = [];

    if (!empty($searchQuery)) {
        foreach ($logs as $logId => $logData) {
            $email     = isset($logData['email']) ? $logData['email'] : 'Unknown';
            $status    = isset($logData['status']) ? ucfirst($logData['status']) : 'Unknown';
            $timestamp = isset($logData['timestamp']) ? $logData['timestamp'] : 'Unknown';
            $datestamp = isset($logData['datestamp']) ? $logData['datestamp'] : 'Unknown';

            if (
                stripos($email, $searchQuery) !== false ||
                stripos($status, $searchQuery) !== false ||
                stripos($timestamp, $searchQuery) !== false ||
                stripos($datestamp, $searchQuery) !== false
            ) {
                $filteredLogs[$logId] = $logData;
            }
        }
    } else {
        $filteredLogs = $logs; 
    }

    if (!empty($filteredLogs)) {
        uasort($filteredLogs, function($a, $b) {
            $dateA = isset($a['datestamp']) ? $a['datestamp'] : '';
            $dateB = isset($b['datestamp']) ? $b['datestamp'] : '';
            return strcmp($dateA, $dateB);  
        });
    }

    $loginAttemptRows = '';
    $csvData = [];

    foreach ($filteredLogs as $logId => $logData) {
        $email     = isset($logData['email']) ? $logData['email'] : 'Unknown';
        $status    = isset($logData['status']) ? ucfirst($logData['status']) : 'Unknown';
        $timestamp = isset($logData['timestamp']) ? $logData['timestamp'] : 'Unknown';
        $datestamp = isset($logData['datestamp']) ? $logData['datestamp'] : 'Unknown';

        $fullDetails = "Date: $datestamp\nTime: $timestamp\nStatus: $status\nEmail: $email";
        $fullDetailsAttr = htmlspecialchars($fullDetails, ENT_QUOTES, 'UTF-8');

        // Desktop shows all columns, Mobile compresses via CSS (below)
        $loginAttemptRows .= "
          <tr class='log-row' style='text-align:center;vertical-align:middle;'>
            <td class='col-date' style='padding:13px;border-bottom:1px solid #ddd;'>$datestamp</td>
            <td class='col-time' style='padding:13px;border-bottom:1px solid #ddd;'>$timestamp</td>
            <td class='col-status' style='padding:13px;border-bottom:1px solid #ddd;'>$status</td>
            <td class='col-email' style='padding:13px;border-bottom:1px solid #ddd;'>$email</td>

            <!-- ✅ Mobile-only View button -->
            <td class='col-action' style='padding:13px;border-bottom:1px solid #ddd;'>
              <button type='button' class='mobile-view-btn' data-full='$fullDetailsAttr'>View</button>
            </td>
          </tr>
        ";

        $csvData[] = [$datestamp, $timestamp, $status, $email];
    }

    if (empty($filteredLogs)) {
        $loginAttemptRows = '<tr><td colspan="5"><center>No matching logs found.</center></td></tr>';
    }
} else {
    $loginAttemptRows = '<tr><td colspan="5"><center>No logs available.</center></td></tr>';
}

function generateCSV($data)
{
    $output = fopen("php://output", "w");
    fputcsv($output, ["Date", "Time", "Status", "Email"]);
    foreach ($data as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
}

if (isset($_GET['download']) && $_GET['download'] === 'true') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="logs.csv"');
    generateCSV($csvData);
    exit;
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
<title>Logs</title>

<style>
  /* ===== Base / shared ===== */
  .popup {
    animation: transitionIn-Y-bottom .5s;
  }

  .sub-table {
    animation: transitionIn-Y-bottom .5s;
  }

  .popup .close {
    font-size: 30px;
    color: #000;
    text-decoration: none;
  }

  .overlay {
    display: none;
    position: fixed;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, .7);
    z-index: 1000;
  }

  .overlay .popup {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: #fff;
    padding: 20px;
    border-radius: 8px;
  }

  /* Hidden by default on desktop */
  .mobile-header {
    display: none;
  }

  #hamburger-menu {
    display: none;
  }

  #menu-overlay {
    display: none;
  }

  /* View button default hidden on desktop */
  .mobile-view-btn {
    display: none;
  }

  /* ===== Desktop only: hide Action column ===== */
  @media (min-width: 769px) {
    .sub-table th.col-action,
    .sub-table td.col-action {
      display: none !important;
    }
  }

  /* ===== Mobile only ===== */
  @media (max-width: 768px) {

    html,
    body {
      width: 100%;
      overflow-x: hidden;
    }

    /* Mobile header */
    .mobile-header {
      display: flex !important;
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      height: 56px;
      background: lightgreen;
      z-index: 10050;
      align-items: center;
      padding: 0 12px;
      font-weight: 700;
      color: #000;
      box-sizing: border-box;
    }

    .mobile-left,.mobile-right{
    display:flex;
    align-items:center;
    gap:10px;
  }
  .mobile-center{
    flex:1;
    text-align:center;
    font-size:16px;
  }
  .mobile-date{
    font-size:12px;
    font-weight:600;
  }

  .mobile-calendar-btn{
    width:34px;
    height:34px;
    border-radius:10px;
    border:none;
    background:rgba(255, 255, 255, 0);
    display:flex;
    align-items:center;
    justify-content:center;
  }
  .mobile-calendar-btn img{width:18px;height:18px;}

    /* Hamburger button */
    #hamburger-menu {
      display: flex !important;
      position: fixed !important;
      top: 11px !important;
      left: 12px !important;
      width: 34px !important;
      height: 34px !important;
      padding: 8px !important;
      border-radius: 10px !important;
      background:rgba(255, 255, 255, 0.48) !important;
      z-index: 10060 !important;
      cursor: pointer;
      flex-direction: column;
      justify-content: center;
      gap: 5px;
      box-sizing: border-box;
    }

    #hamburger-menu .bar {
      height: 2px !important;
      width: 100% !important;
      background: #111 !important;
      border-radius: 2px;
      transition: .25s;
    }

    #hamburger-menu.active .bar:nth-child(1) {
      transform: rotate(-45deg) translate(-4px, 5px);
    }

    #hamburger-menu.active .bar:nth-child(2) {
      opacity: 0;
    }

    #hamburger-menu.active .bar:nth-child(3) {
      transform: rotate(45deg) translate(-4px, -5px);
    }

    /* Push content below header */
    .container {
      padding-top: 56px !important;
      width: 100% !important;
      max-width: 100% !important;
      box-sizing: border-box;
    }

    /* Hide desktop header row */
    .dash-body > table > tbody > tr:first-child {
      display: none !important;
    }

    .dash-body {
      margin: 0 !important;
      padding: 12px !important;
      width: 100% !important;
      box-sizing: border-box;
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
      z-index: 10040 !important;
      overflow-y: auto !important;
      transition: left .25s ease !important;
      box-shadow: 10px 0 30px rgba(0, 0, 0, .12) !important;
    }

    .menu.active {
      left: 0 !important;
    }

    /* Screen overlay behind drawer */
    #menu-overlay {
      display: block !important;
      position: fixed !important;
      inset: 0 !important;
      background: rgba(0, 0, 0, .35) !important;
      opacity: 0 !important;
      pointer-events: none !important;
      transition: opacity .2s ease !important;
      z-index: 10030 !important;
    }

    body.menu-open #menu-overlay {
      opacity: 1 !important;
      pointer-events: auto !important;
    }

    body.menu-open {
      overflow: hidden;
    }

    /* Utilize space */
    .heading-main12 {
      margin-left: 0 !important;
      padding: 0 4px !important;
    }

    .abc.scroll {
      margin-top: 12px !important;
      width: 100% !important;
      overflow-x: hidden !important;
    }

    /* Compress table for mobile */
    .sub-table {
      width: 100% !important;
      min-width: 0 !important;
      table-layout: fixed;
    }

    .sub-table th,
    .sub-table td {
      padding: 10px !important;
      font-size: 13px !important;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      vertical-align: middle;
    }

    /* Hide extra columns on mobile */
    .col-date,
    .col-time,
    .col-email {
      display: none !important;
    }

    .table-headin.col-date,
    .table-headin.col-time,
    .table-headin.col-email {
      display: none !important;
    }

    /* Show only Status + Action */
    .col-status {
      width: 60% !important;
    }

    .col-action {
      width: 40% !important;
    }

    /* View button only in mobile */
    .mobile-view-btn {
      display: inline-flex !important;
      align-items: center;
      justify-content: center;
      width: 100%;
      padding: 8px 10px;
      border: none;
      border-radius: 10px;
      background: #fff;
      font-weight: 800;
      cursor: pointer;
      box-shadow: 0 1px 3px rgba(0, 0, 0, .15);
    }

    /* Popup fits phone */
    .overlay .popup {
      width: min(520px, 92vw);
      max-height: 80vh;
      overflow: auto;
      box-sizing: border-box;
    }

    /* Download button full width */
    .btn-primary-soft.btn {
      width: 100% !important;
      max-width: 100% !important;
    }

    div[style*="margin-top: 20px;"] {
      width: 100% !important;
      padding: 0 12px !important;
      box-sizing: border-box;
    }
  }
</style>

</head>

<body>

<div id="hamburger-menu">
  <div class="bar"></div>
  <div class="bar"></div>
  <div class="bar"></div>
</div>

<div class="mobile-header">
  <div class="mobile-left"></div>
  <div class="mobile-center">Logs</div>
  <div class="mobile-right">
        <div class="mobile-date"><?php echo $today; ?></div>
        <button class="mobile-calendar-btn" type="button" aria-label="Calendar">
            <img src="../img/calendar.svg" alt="">
        </button>
    </div>
</div>

<div id="menu-overlay"></div>

<div class="container">
  <div class="menu">
    <table class="menu-container" border="0">
      <tr>
        <td style="padding: 10px" colspan="2">
          <table border="0" class="profile-container">
            <tr>
              <td width="30%" style="padding-left:20px">
                <p class="profile-title"><?php echo substr($username,0,50) ?></p>
                <p class="profile-subtitle"><?php echo substr($useremail,0,50) ?></p>
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

      <tr class="menu-row"><td class="menu-btn"><a href="dashboard.php" class="non-style-link-menu"><p class="menu-text">Dashboard</p></a></td></tr>
      <tr class="menu-row"><td class="menu-btn menu-active"><a href="logs.php" class="non-style-link-menu non-style-link-menu-active"><p class="menu-text">Logs</p></a></td></tr>
      <tr class="menu-row"><td class="menu-btn"><a href="clients.php" class="non-style-link-menu"><p class="menu-text">Accounts</p></a></td></tr>
      <tr class="menu-row"><td class="menu-btn"><a href="cms.php" class="non-style-link-menu"><p class="menu-text">CMS</p></a></td></tr>
    </table>
  </div>

  <div class="dash-body" style="margin-top: 15px">
    <table border="0" width="100%" style="border-spacing:0;margin:0;padding:0;margin-top:25px;">
      <tr>
        <td>
          <form action="" method="GET" class="header-search">
            <input type="search" name="search" class="input-text header-searchbar" placeholder="Search Logs" list="logs">&nbsp;&nbsp;
            <?php echo '</datalist>'; ?>
            <input type="Submit" value="Search" class="login-btn btn-primary btn" style="padding-left:25px;padding-right:25px;padding-top:10px;padding-bottom:10px;">
          </form>
        </td>

        <td width="15%">
          <p style="font-size:14px;color:rgb(119,119,119);padding:0;margin:0;text-align:right;">Today's Date</p>
          <p class="heading-sub12" style="padding:0;margin:0;">
            <?php date_default_timezone_set('Asia/Manila'); echo date('Y-m-d'); ?>
          </p>
        </td>

        <td width="10%">
          <button class="btn-label" style="display:flex;justify-content:center;align-items:center;">
            <img src="../img/calendar.svg" width="100%">
          </button>
        </td>
      </tr>

      <td colspan="2" style="padding-top:30px;">
        <p class="heading-main12" style="margin-left:45px;font-size:20px;color:rgb(49,49,49)">Logs (<?php echo $logsCount;?>)</p>
      </td>

      <tr>
        <td colspan="4">
          <center>
            <div class="abc scroll" style="margin-top:60px;">
              <table width="70%" class="sub-table scrolldown" border="0">
                <thead>
                  <tr>
                    <th class="table-headin col-date">Date</th>
                    <th class="table-headin col-time">Time</th>
                    <th class="table-headin col-status">Status</th>
                    <th class="table-headin col-email">Email</th>
                    <th class="table-headin col-action">Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php echo $loginAttemptRows; ?>
                </tbody>
              </table>
            </div>

            <div style="margin-top: 20px;">
              <button onclick="downloadCSV()" class="btn-primary-soft btn">Download Report</button>
            </div>
        </td>
      </tr>
    </table>
  </div>
</div>

<!-- Log details popup -->
<div class="overlay" id="log-overlay">
  <div class="popup">
    <a href="javascript:void(0)" class="close" id="log-close">&times;</a>
    <h3 style="margin:0 0 10px 0;">Log Details</h3>
    <pre id="log-details" style="white-space:pre-wrap;margin:0;"></pre>
  </div>
</div>

<script>
  function downloadCSV() { window.location.href = "?download=true"; }

  // Drawer menu
  const menuButton = document.getElementById('hamburger-menu');
  const menu = document.querySelector('.menu');
  const overlay = document.getElementById('menu-overlay');

  function openMenu(){
    menu.classList.add('active');
    menuButton.classList.add('active');
    document.body.classList.add('menu-open');
  }
  function closeMenu(){
    menu.classList.remove('active');
    menuButton.classList.remove('active');
    document.body.classList.remove('menu-open');
  }

  if(menuButton){
    menuButton.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      if (menu.classList.contains('active')) closeMenu();
      else openMenu();
    });
  }
  if(overlay){ overlay.addEventListener('click', closeMenu); }

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeMenu();
  });

  // Mobile View popup
  const logOverlay = document.getElementById('log-overlay');
  const logClose = document.getElementById('log-close');
  const logDetails = document.getElementById('log-details');

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('.mobile-view-btn');
    if(!btn) return;
    const full = btn.getAttribute('data-full') || '';
    if(logDetails) logDetails.textContent = full;
    if(logOverlay) logOverlay.style.display = 'block';
  });

  function closeLog(){
    if(logOverlay) logOverlay.style.display = 'none';
  }
  if(logClose) logClose.addEventListener('click', closeLog);
  if(logOverlay){
    logOverlay.addEventListener('click', (e) => {
      if(e.target === logOverlay) closeLog();
    });
  }
</script>

</body>
</html>
