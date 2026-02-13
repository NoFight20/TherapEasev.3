<?php
session_name('sess_patient'); session_start();
if (empty($_SESSION['user']) || ($_SESSION['usertype'] ?? '') !== 'p') {
    header('Location: ../login.php'); exit();
}

date_default_timezone_set('Asia/Manila');
include("../connection.php");

$useremail = $_SESSION['user'];
$today = date('Y-m-d');

// Retrieve patient data
$patientSnap = $database->getReference('patients')
    ->orderByChild('email')
    ->equalTo($useremail)
    ->getSnapshot();
$patientData = $patientSnap->getValue();
$username = 'Patient';
$photo = '../img/user.png';

if ($patientData) {
    $patient = reset($patientData);
    $username = $patient['fname'] . ' ' . $patient['lname'];
    if (!empty($patient['photo'])) {
        $photo = $patient['photo'];
    }
}

// Fetch laboratory results
$labRef = $database->getReference('laboratory_results')
    ->orderByChild('patient_email')
    ->equalTo($useremail);
$labData = $labRef->getValue();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laboratory Results</title>
    <link rel="stylesheet" href="../css/animations.css">
    <link rel="stylesheet" href="../css/main.css">
    <link rel="stylesheet" href="../css/admin.css">
    <link rel="stylesheet" href="../css/hamburger.css">
    <style>
        .lab-results-table {
            width: 85%;
            border-collapse: collapse;
            margin-top: 30px;
        }
        .lab-results-table th, .lab-results-table td {
            padding: 12px;
            text-align: center;
            border-bottom: 1px solid #ccc;
        }
        .lab-results-table th {
            background-color: #e7f8e7;
            font-size: 16px;
        }
        .status-ready {
            color: green;
            font-weight: bold;
        }
        .status-processing {
            color: orange;
        }
        .status-pending {
            color: gray;
        }
    </style>
</head>
<body>
    <!-- Hamburger menu + sidebar reused -->
    <div id="hamburger-menu">
        <div class="bar"></div>
        <div class="bar"></div>
        <div class="bar"></div>
    </div>
    <div class="container">
        <div class="menu">
            <table class="menu-container">
                <tr>
                    <td colspan="2" style="padding:10px">
                        <table class="profile-container">
                            <tr>
                                <td width="30%" style="padding-left:20px">
                                    <img src="<?php echo htmlspecialchars($photo); ?>" alt="Profile Picture" width="100%" style="border-radius:50%">
                                </td>
                                <td>
                                    <p class="profile-title"><?php echo htmlspecialchars($username); ?></p>
                                    <p class="profile-subtitle"><?php echo htmlspecialchars($useremail); ?></p>
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
                    <td class="menu-btn"><a href="index.php" class="non-style-link-menu"><p class="menu-text">Dashboard</p></a></td>
                </tr>
                <tr class="menu-row">
                    <td class="menu-btn"><a href="doctors.php" class="non-style-link-menu"><p class="menu-text">All Doctors</p></a></td>
                </tr>
                <tr class="menu-row">
                    <td class="menu-btn"><a href="appointment.php" class="non-style-link-menu"><p class="menu-text">My Appointments</p></a></td>
                </tr>
                <tr class="menu-row">
                    <td class="menu-btn menu-active">
                        <a href="laboratory.php" class="non-style-link-menu non-style-link-menu-active">
                            <p class="menu-text">Laboratory Results</p>
                        </a>
                    </td>
                </tr>
                <tr class="menu-row">
                    <td class="menu-btn"><a href="settings.php" class="non-style-link-menu"><p class="menu-text">Settings</p></a></td>
                </tr>
            </table>
        </div>

        <div class="dash-body" style="margin-top:20px;">
            <table border="0" width="100%">
                <tr>
                    <td class="nav-bar">
                        <p style="font-size:23px;padding-left:12px;font-weight:600;margin-left:20px;">Laboratory Results</p>
                    </td>
                    <td width="15%" style="text-align:right;padding-right:20px;">
                        <p style="font-size:14px;color:#777;margin:0;">Today's Date</p>
                        <p class="heading-sub12" style="margin:0;"><?php echo $today; ?></p>
                    </td>
                    <td width="10%">
                        <button class="btn-label"><img src="../img/calendar.svg" width="100%"></button>
                    </td>
                </tr>
            </table>

            <center>
                <table class="lab-results-table">
                    <thead>
                        <tr>
                            <th>Test Type</th>
                            <th>Status</th>
                            <th>Ready / Expected Date</th>
                            <th>Requested By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if ($labData) {
                            foreach ($labData as $lab) {
                                $testType = $lab['test_type'] ?? 'Laboratory Test';
                                $status = $lab['status'] ?? 'pending';
                                $expectedDate = $lab['expected_ready_date'] ?? 'N/A';
                                $readyTime = $lab['ready_time'] ?? '';
                                $doctor = $lab['requesting_doctor'] ?? 'Laboratory';

                                $statusText = ucfirst($status);
                                $statusClass = 'status-pending';
                                if ($status === 'ready') $statusClass = 'status-ready';
                                elseif ($status === 'processing') $statusClass = 'status-processing';

                                echo "<tr>
                                        <td>{$testType}</td>
                                        <td class='{$statusClass}'>{$statusText}</td>
                                        <td>{$expectedDate} {$readyTime}</td>
                                        <td>{$doctor}</td>
                                      </tr>";
                            }
                        } else {
                            echo "<tr><td colspan='4'>No laboratory results found.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </center>
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
</body>
</html>
