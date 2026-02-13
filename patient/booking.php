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
</head>
<body>
    <!-- Mobile Header -->
    <div class="mobile-header"></div>

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
                                <td width="30%" style="padding-left:20px">
                                <img src="<?php echo htmlspecialchars($photo); ?>" alt="Profile Picture" width="100%" style="border-radius:50%">
                                </td>
                                <td style="padding:0px;margin:0px;">
                                    <p class="profile-title"><?php echo substr($username, 0, 100); ?></p>
                                    <p class="profile-subtitle"><?php echo substr($useremail, 0, 22); ?></p>
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
                <!-- Menu items -->
                <tr class="menu-row">
                    <td class="menu-btn">
                        <a href="index.php" class="non-style-link-menu"><div><p class="menu-text">Home</p></div></a>
                    </td>
                </tr>
                <tr class="menu-row">
                    <td class="menu-btn">
                        <a href="doctors.php" class="non-style-link-menu"><div><p class="menu-text">All Doctors</p></div></a>
                    </td>
                </tr>
                <tr class="menu-row">
                    <td class="menu-btn menu-active">
                        <a href="schedule.php" class="non-style-link-menu non-style-link-menu-active"><div><p class="menu-text">Scheduled Sessions</p></div></a>
                    </td>
                </tr>
                <tr class="menu-row">
                    <td class="menu-btn">
                        <a href="appointment.php" class="non-style-link-menu"><div><p class="menu-text">My Bookings</p></div></a>
                    </td>
                </tr>
                <tr class="menu-row">
                    <td class="menu-btn">
                        <a href="settings.php" class="non-style-link-menu"><div><p class="menu-text">Settings</p></div></a>
                    </td>
                </tr>
            </table>
        </div>
        
        <div class="dash-body">
            <table border="0" width="100%" style="border-spacing: 0; margin: 0; padding: 0; margin-top: 25px;">
                <tr>
                    <td width="13%">
                        <a href="schedule.php"><button class="login-btn btn-primary-soft btn btn-icon-back" style="padding-top: 11px; padding-bottom: 11px; margin-left: 20px; width: 125px"><font class="tn-in-text">Back</font></button></a>
                    </td>
                    <td>
                        <form action="schedule.php" method="post" class="header-search">
                            <input type="search" name="search" class="input-text header-searchbar" placeholder="Search Doctor name or Email or Date (YYYY-MM-DD)" list="doctors">&nbsp;&nbsp;
                            <?php
                            echo '<datalist id="doctors">';
                            $doctors = $database->getReference('doctor')->getValue();
                            $schedules = $database->getReference('schedule')->getValue();

                            if ($doctors) {
                                foreach ($doctors as $doc) {
                                    echo "<option value='{$doc['name']}'><br/>";
                                }
                            }

                            if ($schedules) {
                                foreach ($schedules as $schedule) {
                                    echo "<option value='{$schedule['title']}'><br/>";
                                }
                            }

                            echo '</datalist>';
                            ?>
                            <input type="submit" value="Search" class="login-btn btn-primary btn" style="padding-left: 25px; padding-right: 25px; padding-top: 10px; padding-bottom: 10px;">
                        </form>
                    </td>
                    <td width="15%">
                        <p style="font-size: 14px; color: rgb(119, 119, 119); padding: 0; margin: 0; text-align: right;">
                            Today's Date
                        </p>
                        <p class="heading-sub12" style="padding: 0; margin: 0;">
                            <?php echo date('Y-m-d');  ?>
                        </p>
                    </td>
                    <td width="10%">
                        <button class="btn-label" style="display: flex; justify-content: center; align-items: center;"><img src="../img/calendar.svg" width="100%"></button>
                    </td>
                </tr>
                <tr>
                    <td colspan="4" style="padding-top:10px; width: 100%;">
                        <!-- Placeholder for content -->
                    </td>
                </tr>
                <tr>
                    <td colspan="4">
                       <center>
                        <div class="abc scroll">
                            <table width="100%" class="sub-table scrolldown" border="0" style="padding: 50px; border:none">
                                <tbody>
                                <?php
                                    if (isset($_GET["id"])) {
                                        $id = htmlspecialchars($_GET["id"]); // Sanitize the schedule ID
                                    
                                        // Fetch the schedule by its key
                                        $scheduleRef = $database->getReference("schedule/{$id}")->getValue();
                                    
                                        if (!empty($scheduleRef)) {
                                            $title = htmlspecialchars($scheduleRef["title"] ?? "Unknown");
                                            $docid = $scheduleRef["docid"] ?? null; // Use docid for doctor lookup
                                            $scheduledate = htmlspecialchars($scheduleRef["scheduledate"] ?? "N/A");
                                            $scheduletime = htmlspecialchars($scheduleRef["scheduletime"] ?? "N/A");
                                    
                                            // Fetch doctor details using docid
                                            $doctorName = "Unknown";
                                            $doctorEmail = "Unknown";
                                    
                                            if ($docid) {
                                                // Retrieve doctor details using the docid
                                                $doctorRef = $database->getReference("doctor/{$docid}")->getValue();
                                                if (!empty($doctorRef)) {
                                                    $doctorName = htmlspecialchars($doctorRef["name"] ?? "Unknown");
                                                    $doctorEmail = htmlspecialchars($doctorRef["email"] ?? "Unknown");
                                                } else {
                                                    error_log("Doctor not found for docid: {$docid}");
                                                }
                                            }
                                    
                                            // Fetch existing appointments for this specific schedule ID
                                            $apponum = 1;
                                            $appointmentsRef = $database->getReference("appointment")->orderByChild("scheduleid")->equalTo($id)->getValue();
                                            if (!empty($appointmentsRef) && is_array($appointmentsRef)) {
                                                $apponum = count($appointmentsRef) + 1; // Count existing appointments and increment
                                            }
                                    
                                            // Display the booking form
                                            echo '
                                                <form action="booking-complete.php" method="post">
                                                    <input type="hidden" name="scheduleid" value="' . $id . '">
                                                    <input type="hidden" name="apponum" value="' . $apponum . '">
                                                    <input type="hidden" name="date" value="' . date('Y-m-d') . '">
                                                    <input type="hidden" name="doctorname" value="' . $doctorName . '">
                                                    <input type="hidden" name="doctoremail" value="' . $doctorEmail . '">
                                                    <table width="100%" class="sub-table scrolldown" border="0" style="padding: 50px; border:none">
                                                        <tbody>
                                                            <tr>
                                                                <td style="width: 50%;" rowspan="2">
                                                                    <div class="dashboard-items search-items">
                                                                        <div style="width:100%">
                                                                            <div class="h1-search" style="font-size:25px;">
                                                                                Session Details
                                                                            </div><br><br>
                                                                            <div class="h3-search" style="font-size:18px; line-height:30px;">
                                                                                <strong>Doctor Name:</strong> ' . $doctorName . '<br>
                                                                                <strong>Doctor Email:</strong> ' . $doctorEmail . '<br>
                                                                            </div>
                                                                            <div class="h3-search" style="font-size:18px;">
                                                                                <strong>Session Title:</strong> ' . $title . '<br>
                                                                                <strong>Scheduled Date:</strong> ' . $scheduledate . '<br>
                                                                                <strong>Starts At:</strong> ' . $scheduletime . '
                                                                            </div>
                                                                            <br>
                                                                        </div>
                                                                    </div>
                                                                </td>
                                                                <td style="width: 25%;">
                                                                    <div class="dashboard-items search-items">
                                                                        <div style="width:100%; padding-top: 15px; padding-bottom: 15px;">
                                                                            <div class="h1-search" style="font-size:20px; line-height: 35px; margin-left:8px; text-align:center;">
                                                                                Your Appointment Number
                                                                            </div>
                                                                            <center>
                                                                                <div class="dashboard-icons" style="margin-left: 0px; width:90%; font-size:70px; font-weight:800; text-align:center; color:var(--btnnictext); background-color: var(--btnice);">' . $apponum . '</div>
                                                                            </center>
                                                                        </div><br><br>
                                                                    </div>
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <td>
                                                                    <input type="submit" class="login-btn btn-primary btn btn-book" style="margin-left:10px; padding-left: 25px; padding-right: 25px; padding-top: 10px; padding-bottom: 10px; width:95%; text-align: center;" value="Book now" name="booknow">
                                                                </td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </form>
                                            ';
                                        } else {
                                            echo "<p>No session details found for ID: {$id}</p>";
                                        }
                                    } else {
                                        echo "<p>No session ID provided.</p>";
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