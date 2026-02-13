<?php

session_name('sess_admin'); session_start();

// Check if the user is logged in and is a patient
if(isset($_SESSION["user"])){
    if(($_SESSION["user"]) == "" || $_SESSION['usertype'] != 'a'){
        header("location: ../login.php");
    } else {
        $useremail = $_SESSION["user"];
    }
} else {
    header("location: ../login.php");
}

//import database
include("../connection.php");

// Firebase query to get patient data based on email
try {
    $reference = $database
        ->getReference('admin')
        ->orderByChild('email')  
        ->equalTo($useremail)
        ->getSnapshot();

    // Fetch the first result
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
        
    <title>Appointments</title>
    <style>
        .popup{
            animation: transitionIn-Y-bottom 0.5s;
        }
        .sub-table{
            animation: transitionIn-Y-bottom 0.5s;
        }
		.popup .close {
            font-size: 30px;
            color: #000;
            text-decoration: none;
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
</style>

</head>
<body>
    <!-- Mobile Header -->
    <div class="mobile-header">Appointment</div>

    <!-- Hamburger Menu -->
    <div id="hamburger-menu">
        <div class="bar"></div>
        <div class="bar"></div>
        <div class="bar"></div>
    </div>
    <div class="container">
        <div class="menu">
            <table class="menu-container" border="0">
                <tr>
                    <td style="padding:10px" colspan="2">
                        <table border="0" class="profile-container">
                            <tr>
                                <td width="30%" style="padding-left:20px" >
                                    <img src="../img/user.png" alt="" width="100%" style="border-radius:50%">
                                </td>
                                <td style="padding:0px;margin:0px;">
                                    <p class="profile-title"><?php echo substr($username,0,13) ?></p>
                                    <p class="profile-subtitle"><?php echo substr($useremail,0,22)  ?></p>
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
                        <td class="menu-btn ">
                            <a href="index.php" class="non-style-link-menu">
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
                    <tr class="menu-row">
                        <td class="menu-btn menu-active">
                            <a href="appointment.php" class="non-style-link-menu non-style-link-menu-active">
                                <p class="menu-text">Appointment</p>
                            </a>
                        </td>
                    </tr>
                    <tr class="menu-row">
                        <td class="menu-btn ">
                            <a href="bed.php" class="non-style-link-menu ">
                                <p class="menu-text">Bed Occupancy</p>
                            </a>
                        </td>
                    </tr>
                    <tr class="menu-row">
                        <td class="menu-btn ">
                            <a href="queing.php" class="non-style-link-menu ">
                                <p class="menu-text">Laboratory Queing</p>
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
            </table>
        </div>
        <div class="dash-body">
            <table border="0" width="100%" style=" border-spacing: 0;margin:0;padding:0;margin-top:25px; ">
                <tr >
                    <td width="13%" >
                    <a href="appointment.php" ><button  class="login-btn btn-primary-soft btn btn-icon-back"  style="padding-top:11px;padding-bottom:11px;margin-left:20px;width:125px"><font class="tn-in-text">Back</font></button></a>
                    </td>
                    <td>
                        <form action="" method="post" class="header-search">
                            <input type="search" name="search" class="input-text header-searchbar" placeholder="Search Patient name or Doctor name" list="patient">&nbsp;&nbsp;
                            <input type="submit" value="Search" class="login-btn btn-primary btn" style="padding-left: 25px; padding-right: 25px; padding-top: 10px; padding-bottom: 10px; width: 15%;">
                        </form>

                        <?php
                        // Process search request
                        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search'])) {
                            $searchQuery = trim($_POST['search']); 
                            $matchedAppointments = [];

                            // Fetch appointments from Firebase
                            $appointments = $database->getReference('appointment')->getValue();

                            // Check for matches
                            if (!empty($appointments)) {
                                foreach ($appointments as $appointmentId => $appointmentData) {
                                    $patientName = $appointmentData['name'] ?? '';
                                    $patientEmail = $appointmentData['email'] ?? '';

                                    // Check if the search query matches the patient name or email 
                                    if (stripos($patientName, $searchQuery) !== false || stripos($patientEmail, $searchQuery) !== false) {
                                        $matchedAppointments[$appointmentId] = $appointmentData;
                                    }
                                }
                            }
                        }
                        ?>
                    </td>
                    <td width="15%">
                        <p style="font-size: 14px;color: rgb(119, 119, 119);padding: 0;margin: 0;text-align: right;">
                            Today's Date
                        </p>
                        <p class="heading-sub12" style="padding: 0;margin: 0;">
                            <?php 
                                date_default_timezone_set('Asia/Manila');
                                $today = date('Y-m-d');
                                echo $today;

                            ?>
                        </p>
                    </td>
                    <td width="10%">
                        <button  class="btn-label"  style="display: flex;justify-content: center;align-items: center;"><img src="../img/calendar.svg" width="100%"></button>
                    </td>
                </tr>
                <tr>
                    <td colspan="4" style="padding-top:10px;width: 100%;" >
                    
                        <p class="heading-main12" style="margin-left: 45px;font-size:18px;color:rgb(49, 49, 49)">All Appointments 
                            (<?php 
                               // Get today's date and the first day of the next month
                               $today = date('Y-m-d'); 
                               $nextMonthEnd = date('Y-m-t', strtotime('+1 month')); 

                                // Fetch all appointments from Firebase
                                $list110 = $database->getReference('appointment')->getValue();

                                // Initialize count
                                $appointmentCount = 0;

                                if ($list110) {
                                    // Filter appointments
                                    foreach ($list110 as $appointment) {
                                        if (
                                            isset($appointment['status']) && $appointment['status'] === 'confirmed' && 
                                            isset($appointment['scheduledate']) && 
                                            $appointment['scheduledate'] >=  $today && 
                                            $appointment['scheduledate'] <= $nextMonthEnd
                                        ) {
                                            $appointmentCount++;
                                        }
                                    }
                                }

                                // Output the count of filtered appointments
                                echo $appointmentCount;

                            ?>)
                            </p>
                    </td>
                    
                </tr>
                            </table>

                        </center>
                    </td>
                    
                </tr>
                <tr>
                   <td colspan="4">
                       <center>
                        <div class="abc scroll">
                        <table width="93%" class="sub-table scrolldown" border="0">
                        <thead>
                        <tr>
                                <th class="table-headin">
                                    Patient name
                                </th>
                                <th class="table-headin">
                                    
                                    Appointment number
                                    
                                </th>
                               
                                
                                <th class="table-headin">
                                    Doctor
                                </th>
                                <th class="table-headin">
                                    
                                
                                    Session Title
                                    
                                    </th>
                                
                                <th class="table-headin" style="font-size:10px">
                                    
                                    Session Date & Time
                                    
                                </th>
                                
                                <th class="table-headin">
                                    
                                    Appointment Date
                                    
                                </th>
                                
                                <th class="table-headin">
                                    
                                    Events
                                    
                                </tr>
                        </thead>
                        <tbody>
                        
                            <?php
$appointments = $database->getReference('appointment')->getValue() ?? [];
$today = date('Y-m-d'); // Today's date
$nextMonthEnd = date('Y-m-t', strtotime('+1 month')); // Last day of the next month

$filteredAppointments = [];

if (!empty($appointments)) {
    foreach ($appointments as $appointmentId => $appointmentData) {
        // Ensure all required fields exist and match the filters
        if (
            isset($appointmentData['status'], $appointmentData['scheduledate']) &&
            $appointmentData['status'] === 'confirmed' && 
            $appointmentData['scheduledate'] >= $today && 
            $appointmentData['scheduledate'] <= $nextMonthEnd 
        ) {
            $filteredAppointments[] = [
                'appoid' => $appointmentId,
                'apponum' => $appointmentData['apponum'] ?? 'N/A',
                'docname' => $appointmentData['doctorname'] ?? 'Unknown Doctor',
                'pname' => $appointmentData['name'] ?? 'Unknown Patient',
                'title' => $appointmentData['title'] ?? 'Unknown Session',
                'scheduledate' => $appointmentData['scheduledate'] ?? 'Unknown Date',
                'scheduletime' => $appointmentData['scheduletime'] ?? 'Unknown Time',
                'appodate' => $appointmentData['appodate'] ?? 'Unknown Date',
            ];
        }
    }
}


// Display Results
if (count($filteredAppointments) === 0) {
    echo '<tr>
        <td colspan="7">
            <br><br><br><br>
            <center>
                <img src="../img/notfound.svg" width="25%">
                <br>
                <p class="heading-main12" style="margin-left: 45px;font-size:20px;color:rgb(49, 49, 49)">
                    No approved appointments found from today to next month!
                </p>
                <a class="non-style-link" href="appointment.php">
                    <button class="login-btn btn-primary-soft btn" style="display: flex;justify-content: center;align-items: center;margin-left:20px;">
                        &nbsp; Show all Appointments &nbsp;
                    </button>
                </a>
            </center>
            <br><br><br><br>
        </td>
    </tr>';
} else {
    foreach ($filteredAppointments as $appointment) {
        echo '<tr style="text-align: center; vertical-align: middle;">
            <td style="font-weight:600; border-bottom: 1px solid #ddd;"> &nbsp;' . htmlspecialchars($appointment['pname']) . '</td>
            <td style="border-bottom: 1px solid #ddd; text-align:center; font-size:23px; font-weight:500; color: var(--btnnicetext);">' . htmlspecialchars($appointment['apponum']) . '</td>
            <td style="border-bottom: 1px solid #ddd;">' . htmlspecialchars($appointment['docname']) . '</td>
            <td style="border-bottom: 1px solid #ddd;">' . htmlspecialchars($appointment['title']) . '</td>
            <td style="text-align:center; font-size:12px; border-bottom: 1px solid #ddd;">' . htmlspecialchars($appointment['scheduledate']) . '<br>' . htmlspecialchars($appointment['scheduletime']) . '</td>
            <td style="text-align:center; border-bottom: 1px solid #ddd;">' . htmlspecialchars($appointment['appodate']) . '</td>
            <td>
                <div style="display:flex;justify-content: center;">
                    <a href="?action=drop&id=' . htmlspecialchars($appointment['appoid']) . '&name=' . htmlspecialchars($appointment['pname']) . '&session=' . htmlspecialchars($appointment['title']) . '&apponum=' . htmlspecialchars($appointment['apponum']) . '" class="non-style-link">
                        <button class="btn-primary-soft btn button-icon btn-delete" style="padding-left: 40px;padding-top: 12px;padding-bottom: 12px;margin-top: 10px;">
                            <font class="tn-in-text">Cancel</font>
                        </button>
                    </a>
                    &nbsp;&nbsp;&nbsp;
                </div>
            </td>
        </tr>';
    }
}

            ?>
                            </tbody>

                        </table>
                        </div>
                        </center>
                   </td> 
                </tr>
                       
                        
                        
            </table>
        </div>
    </div>
	<script>
        const hamburgerMenu = document.getElementById('hamburger-menu');
        const menu = document.querySelector('.menu');
        const dashBody = document.querySelector('.dash-body');

        hamburgerMenu.addEventListener('click', () => {
            hamburgerMenu.classList.toggle('active');
            menu.classList.toggle('active');
            dashBody.classList.toggle('active');
        });
    </script>
    </div>

</body>
</html>