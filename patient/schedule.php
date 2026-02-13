<?php
include("../connection.php");
session_name('sess_patient'); session_start();

if (isset($_SESSION["user"])) {
    if (empty($_SESSION["user"]) || $_SESSION['usertype'] != 'p') {
        header("location: ../login.php");
    } else {
        $useremail = $_SESSION["user"];
    }
} else {
    header("location: ../login.php");
    exit;
}

// Get user details from session
$patientRef = $database->getReference('patients')->orderByChild('email')->equalTo($useremail)->getValue();
$userfetch = reset($patientRef); // Get the first matching patient
$username = isset($userfetch["fname"]);
$userid = isset($userfetch["id"]) ? $userfetch["id"] : '';

// Get today's date and the end of the next month
date_default_timezone_set('Asia/Manila');
$today = date('Y-m-d'); // Today's date
$endOfNextMonth = date('Y-m-t', strtotime('+1 month')); 

// Fetch sessions from Firebase starting today until the end of the next month
$scheduleRef = $database->getReference('schedule')
    ->orderByChild('scheduledate')
    ->startAt($today)
    ->endAt($endOfNextMonth)
    ->getValue();

$sessions = [];
if (!empty($scheduleRef) && is_array($scheduleRef)) {
    foreach ($scheduleRef as $scheduleId => $scheduleData) {
        // Fetch doctor details
        $doctorRef = $database->getReference('doctor/' . $scheduleData['docid'])->getValue();
        $scheduleData['name'] = isset($doctorRef['name']) ? $doctorRef['name'] : 'Unknown Doctor';
        $sessions[$scheduleId] = $scheduleData;
    }
}

// Debugging to ensure `$sessions` has data
if (empty($sessions)) {
    echo "<script>console.log('No sessions found from today until the end of next month.');</script>";
} else {
    echo "<script>console.log('Sessions loaded successfully:', " . json_encode($sessions) . ");</script>";
}

// Fetch user's booked sessions
$bookedRef = $database->getReference('bookings')->orderByChild('pid')->equalTo($userid)->getValue();
$booked_sessions = [];
if ($bookedRef) {
    foreach ($bookedRef as $bookingId => $bookingData) {
        $booked_sessions[] = $bookingData['scheduleid'];
    }
}

// Firebase query to get patient data based on email
    try {
        $reference = $database
            ->getReference('patients')
            ->orderByChild('email')  
            ->equalTo($useremail)
            ->getSnapshot();

        // Fetch the first result
        $userfetch = $reference->getValue();

        if ($userfetch) {
            // Since Firebase returns an associative array, we take the first item
            foreach($userfetch as $key => $value) {
                $username = $value['fname'] . ' ' . $value['lname'];
            }
        } else {
            // Handle the case where no user is found
            echo "No user found.";
        }

    } catch (\Kreait\Firebase\Exception\DatabaseException $e) {
        echo "Error querying the database: " . $e->getMessage();
    }

    $searchtype = "All";
    $insertkey = "";
    $sessions = [];
    
    $nextMonth = date('Y-m-01', strtotime('+1 month'));
    
    if ($_POST) {
        if (!empty($_POST["search"])) {
            $keyword = $_POST["search"];
            $searchtype = "Search Result: ";
    
            // Fetch sessions from Firebase starting today until the end of the next month
$scheduleRef = $database->getReference('schedule')
->orderByChild('scheduledate')
->startAt($today)
->endAt($endOfNextMonth)
->getValue();
    
            // Fetch doctors to map their IDs to names
            $doctorRef = $database->getReference('doctor')->getValue();
    
            // Create a mapping of doctor IDs to names
            $doctorNames = [];
            if (!empty($doctorRef) && is_array($doctorRef)) {
                foreach ($doctorRef as $docId => $doctor) {
                    $doctorNames[$docId] = $doctor['name'] ?? 'Unknown';
                }
            }
    
            // Filter sessions based on the search keyword
            foreach ($scheduleRef as $scheduleId => $scheduleData) {
                $doctorName = $doctorNames[$scheduleData['docid']] ?? 'Unknown';
    
                // Check if the search keyword matches the doctor name, schedule title, or date
                if (
                    stripos($doctorName, $keyword) !== false ||
                    stripos($scheduleData['title'], $keyword) !== false ||
                    stripos($scheduleData['scheduledate'], $keyword) !== false
                ) {
                    $scheduleData['name'] = $doctorName; 
                    $sessions[$scheduleId] = $scheduleData;
                }
            }
        } else {
         // Fetch sessions from Firebase starting today until the end of the next month
$scheduleRef = $database->getReference('schedule')
->orderByChild('scheduledate')
->startAt($today)
->endAt($endOfNextMonth)
->getValue();
    
            $doctorRef = $database->getReference('doctor')->getValue();
    
            foreach ($scheduleRef as $scheduleId => $scheduleData) {
                $doctorName = $doctorRef[$scheduleData['docid']]['name'] ?? 'Unknown';
                $scheduleData['name'] = $doctorName;
                $sessions[$scheduleId] = $scheduleData;
            }
        }
    } else {
        // Fetch sessions from Firebase starting today until the end of the next month
$scheduleRef = $database->getReference('schedule')
->orderByChild('scheduledate')
->startAt($today)
->endAt($endOfNextMonth)
->getValue();
    
        $doctorRef = $database->getReference('doctor')->getValue();
    
        foreach ($scheduleRef as $scheduleId => $scheduleData) {
            $doctorName = $doctorRef[$scheduleData['docid']]['name'] ?? 'Unknown';
            $scheduleData['name'] = $doctorName;
            $sessions[$scheduleId] = $scheduleData;
        }
    }
    
    
    // Check if there is an error message in the session
    if (isset($_SESSION['booking_error'])) {
        echo "<script>alert('" . $_SESSION['booking_error'] . "');</script>";
        // Remove the error message after displaying it
        unset($_SESSION['booking_error']);
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
        
    <title>Sessions</title>
    <style>
        .popup{
            animation: transitionIn-Y-bottom 0.5s;
        }
        .sub-table{
            animation: transitionIn-Y-bottom 0.5s;
        }
        .menu-btn a {
    display: block;
    text-decoration: none; /* Remove underline from links */
    padding: 1px; /* Add padding to make the area bigger */
    width: 100%; /* Make the link take the full width of the button */
}

.menu-btn:hover {
    background-color: #E8F8E8; /* Optional: Hover effect */
}
</style>
</head>
<body>

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
                        <td class="menu-btn ">
                            <a href="doctors.php"  class="non-style-link-menu ">
                                <p class="menu-text">Service Appointments</p>
                            </a>
                        </td>
                    </tr>
                    <tr class="menu-row">
                        <td class="menu-btn  menu-active">
                            <a href="schedule.php" class="non-style-link-menu non-style-link-menu-active">
                                <p class="menu-text">Scheduled Sessions</p>
                            </a>
                        </td>
                    </tr>
                    <tr class="menu-row">
                        <td class="menu-btn ">
                            <a href="appointment.php" class="non-style-link-menu">
                                <p class="menu-text">My Bookings</p>
                            </a>
                        </td>
                    </tr>
                    <tr class="menu-row">
                        <td class="menu-btn ">
                            <a href="settings.php" class="non-style-link-menu">
                                <p class="menu-text">Settings</p>
                            </a>
                        </td>
                    </tr>
                
            </table>
        </div>                  
        <div class="dash-body">
            <table border="0" width="100%" style=" border-spacing: 0;margin:0;padding:0;margin-top:25px; ">
                <tr >
                    <td width="13%" >
                    <a href="schedule.php" ><button  class="login-btn btn-primary-soft btn btn-icon-back"  style="padding-top:11px;padding-bottom:11px;margin-left:20px;width:125px"><font class="tn-in-text">Back</font></button></a>
                    </td>
                    <td >
                    <form action="" method="post" class="header-search">
                        <input type="search" name="search" class="input-text header-searchbar" placeholder="Search Doctor name or Title or Date (YYYY-MM-DD)" list="doctor" value="<?php echo $insertkey; ?>">&nbsp;&nbsp;

                        <datalist id="doctor">
                            <?php
                            foreach ($doctorOptions as $doctorName) {
                                echo "<option value='$doctorName'><br/>";
                            }
                            foreach ($titleOptions as $title) {
                                echo "<option value='$title'><br/>";
                            }
                            ?>
                        </datalist>
                        <input type="submit" value="Search" class="login-btn btn-primary btn" style="padding-left: 25px;padding-right: 25px;padding-top: 10px;padding-bottom: 10px;">
                    </form>


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
                    <td colspan="4" style="padding-top:10px;width: 100%;" >
                        <p class="heading-main12" style="margin-left: 45px;font-size:18px;color:rgb(49, 49, 49)">
                            <?php 
                               

                                // Fetch sessions from Firebase starting from the next month
                                $sessionsRef = $database->getReference('schedule')
                                ->orderByChild('scheduledate')
                                ->startAt($today)
                                ->endAt($endOfNextMonth)
                                ->getValue();
                                // Initialize $numRows to 0 by default
                                $numRows = 0;

                                // Check if the data is valid and count the rows
                                if (is_array($sessionsRef)) {
                                    $numRows = count($sessionsRef);
                                    echo "Available Sessions Starting From Next Month: ";
                                } else {
                                    $numRows = 0;
                                }

                                echo "(".$numRows.")";
                            ?> 
                        </p>
                    </td>
                    
                </tr>  
                <tr>
                   <td colspan="4">
                       <center>
                        <div class="abc scroll">
                        <table width="100%" class="sub-table scrolldown" border="0" style="padding: 100px;border:none; margin-top:20px">
                        
                        <tbody>
                            <?php
                        // Display Sessions
if (empty($sessions)) {
    echo '<tr>
            <td colspan="4">
                <br><br><br><br>
                <center>
                    <img src="../img/notfound.svg" width="25%">
                    <br>
                    <p class="heading-main12" style="margin-left: 45px;font-size:20px;color:rgb(49, 49, 49)">
                        No sessions found for the next 7 days!
                    </p>
                    <a class="non-style-link" href="schedule.php">
                        <button class="login-btn btn-primary-soft btn" style="display: flex;justify-content: center;align-items: center;margin-left:20px;">
                            &nbsp; Show All Sessions &nbsp;
                        </button>
                    </a>
                </center>
                <br><br><br><br>
            </td>
        </tr>';
} else {
    foreach ($sessions as $sessionId => $session) {
        echo '<tr>
                <td style="width: 25%;">
                    <div class="dashboard-items search-items">
                        <div style="width:100%">
                            <!-- Session Title -->
                            <div class="h1-search">' . htmlspecialchars(substr($session['title'], 0, 21)) . '</div><br>

                            <!-- Doctor Name -->
                            <div class="h3-search">' . htmlspecialchars(substr($session['name'], 0, 30)) . '</div>

                            <!-- Schedule Date and Time -->
                            <div class="h4-search">
                                ' . htmlspecialchars($session['scheduledate']) . '<br>Starts: <b>@' . htmlspecialchars(substr($session['scheduletime'], 0, 5)) . '</b> (24h)
                            </div>
                            <br>';

        // Add Book Now button linking to booking.php with the schedule ID
        echo '<a href="booking.php?id=' . urlencode($sessionId) . '">
                <button class="login-btn btn-primary-soft btn" style="padding-top:11px;padding-bottom:11px;width:100%">
                    <font class="tn-in-text">Book Now</font>
                </button>
            </a>';

        echo '</div>
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

    </div>

</body>
</html>
