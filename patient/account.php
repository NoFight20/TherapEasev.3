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

// Fetch patient data
$patientRef = $database->getReference('patients')->orderByChild('email')->equalTo($useremail);
$patientSnapshot = $patientRef->getSnapshot();
$patientData = $patientSnapshot->getValue();

if ($patientData) {
    $patientRecord = array_values($patientData)[0]; // Get the first record
    $fname = $patientRecord['fname'] ?? '';
    $lname = $patientRecord['lname'] ?? '';
    $email = $patientRecord['email'] ?? '';
    $tele = $patientRecord['tele'] ?? '';
    $city = $patientRecord['city'] ?? '';
    $province = $patientRecord['province'] ?? '';
    $gender = $patientRecord['gender'] ?? '';
    $dob = $patientRecord['dob'] ?? '';

    $formattedDob = date('Y-m-d', strtotime($dob));

    $civil_status = $patientRecord['civil_status'] ?? '';
} else {
    echo "No patient data found.";
    exit();
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
    <title>Settings</title>
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
        /* Style for the form */
form {
    background-color: #f9f9f9;
    box-shadow: 0px 4px 8px rgba(0, 0, 0, 0.1);
    padding: 20px;
    border-radius: 12px;
}

/* Fieldset Styling */
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
input[type="text"], input[type="email"], input[type="date"], select, input[type="file"] {
    width: 100%;
    padding: 10px;
    margin: 5px 0 15px;
    border: 1px solid #ccc;
    border-radius: 8px;
    box-sizing: border-box;
    font-size: 14px;
    font-family: Arial, sans-serif;
}

input[type="text"]:focus, input[type="email"]:focus, input[type="date"]:focus, select:focus, input[type="file"]:focus {
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

button.btn-primary:hover {
    background-color: #45a049;
}

button.btn-secondary {
    background-color: #f44336;
    color: #fff;
}

button.btn-secondary:hover {
    background-color: #d32f2f;
}

/* Labels */
label {
    font-weight: bold;
    font-size: 14px;
    color: #333;
    margin-bottom: 5px;
    display: inline-block;
}

/* Add spacing and alignment */
form div {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 20px;
}

form div button {
    width: auto;
}

/* Add responsiveness */
@media (max-width: 768px) {
    form {
        width: 90%;
    }

    input[type="text"], input[type="email"], input[type="date"], select, input[type="file"] {
        font-size: 12px;
    }

    button.btn {
        padding: 8px 15px;
        font-size: 14px;
    }
}

/* =========================
   MOBILE HEADER (Account Settings)
   ========================= */
.mobile-header{ display:none; }

@media (max-width: 768px){

  /* Header */
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

  /* Hamburger inside header */
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

  /* Sidebar slide */
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

  /* Use whole screen */
  .container{
    width:100% !important;
    max-width:100% !important;
    margin:0 !important;
    padding:0 !important;
  }

  /* push content below header */
  .dash-body{
    width:100% !important;
    max-width:100% !important;
    margin-top:70px !important;
    margin-left:0 !important;
    padding:10px 12px !important;
    box-sizing:border-box !important;
  }
  .menu.active + .dash-body{ margin-left:250px !important; }

  /* ✅ Hide desktop header row (Back / Account Settings / Today's Date) */
  .dash-body > table > tbody > tr:first-child{
    display:none !important;
  }

  /* ✅ Remove the “60% width” on mobile (maximize space) */
  form{
    width:100% !important;
    max-width:100% !important;
    margin:0 !important;
  }

  /* Remove extra padding from your nested dash-body */
  .dash-body .dash-body{
    padding:0 !important;
    margin:0 !important;
  }

  /* Make fieldset fill width nicely */
  fieldset{
    width:100% !important;
    box-sizing:border-box !important;
    padding:14px !important;
  }

  /* Buttons full width on mobile */
  .btn{
    width:100% !important;
    margin:8px 0 !important;
  }
}

/* ===== FORCE SAME BUTTON SIZE ON MOBILE (NO DESIGN CHANGE) ===== */
@media (max-width: 768px) {

  /* target the button container */
  form > div[style*="text-align: center"]{
    display: flex !important;
    gap: 10px;
  }

  /* force both Save and Cancel to same width */
  form > div[style*="text-align: center"] button,
  form > div[style*="text-align: center"] a{
    flex: 1 !important;
  }

  /* make anchor behave exactly like button */
  form > div[style*="text-align: center"] a{
    display: flex !important;
    justify-content: center;
    align-items: center;
  }

}

    </style>
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

  <div class="mh-title">Account Settings</div>

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
                                    <p class="profile-title"><?php echo substr($username, 0, 100) ?></p>
                                    <p class="profile-subtitle"><?php echo substr($email, 0, 100) ?></p>
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
                            <p class="menu-text">All Doctors</p>
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
                        <button class="btn-label" style="display: flex; justify-content: center; align-items: center;"><img src="../img/calendar.svg" width="100%"></button>
                    </td>
                </tr>
            </table>

            <div class="dash-body" style="padding: 20px;">
                
                <form action="update_account.php" method="POST" enctype="multipart/form-data" style="width: 60%; margin: auto;">
                    <fieldset style="border: 1px solid #ccc; padding: 20px; border-radius: 10px;">
                        <legend>Profile</legend>
                        <label for="fname">First Name:</label><br>
                        <input type="text" id="fname" name="fname" value="<?php echo htmlspecialchars($fname); ?>" required><br><br>
                        
                        <label for="lname">Last Name:</label><br>
                        <input type="text" id="lname" name="lname" value="<?php echo htmlspecialchars($lname); ?>" required><br><br>
                        
                        <label for="email">Email Address:</label><br>
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" readonly><br><br>
                        
                        <label for="tele">Phone Number:</label><br>
                        <input type="text" id="tele" name="tele" value="<?php echo htmlspecialchars($tele); ?>"><br><br>
                        
                        <label for="city">City:</label><br>
                        <input type="text" id="city" name="city" value="<?php echo htmlspecialchars($city); ?>"><br><br>
                        
                        <label for="province">Province:</label><br>
                        <input type="text" id="province" name="province" value="<?php echo htmlspecialchars($province); ?>"><br><br>
                        
                        <label for="gender">Gender:</label><br>
                        <select id="gender" name="gender" required>
                            <option value="Male" <?php echo ($gender == 'Male') ? 'selected' : ''; ?>>Male</option>
                            <option value="Female" <?php echo ($gender == 'Female') ? 'selected' : ''; ?>>Female</option>
                        </select><br><br>
                        
                        <label for="dob">Date of Birth:</label><br>
                        <input type="date" id="dob" name="dob" value="<?php echo htmlspecialchars($formattedDob); ?>" required>

                        
                        <label for="civil_status">Civil Status:</label><br>
                        <select id="civil_status" name="civil_status" required>
                            <option value="Single" <?php echo ($civil_status == 'Single') ? 'selected' : ''; ?>>Single</option>
                            <option value="Married" <?php echo ($civil_status == 'Married') ? 'selected' : ''; ?>>Married</option>
                            <option value="Widowed" <?php echo ($civil_status == 'Widowed') ? 'selected' : ''; ?>>Widowed</option>
                        </select><br><br>
                        
                        <label for="photo">Profile Picture:</label><br>
                        <input type="file" id="photo" name="photo"><br><br>
                    </fieldset>
                    <br>
                    <div style="text-align: center;">
                        <button type="button" class="btn btn-primary" onclick="confirmSave()">Save Changes</button>
                        <a href="account.php"><button type="button" class="btn btn-secondary">Cancel</button></a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    function confirmSave() {
        const confirmation = confirm("Are you sure you want to save the changes?");
        if (confirmation) {
            // If user confirms, submit the form
            document.querySelector('form').submit(); // Replace with your specific form selector if needed
        }
    }

    const hamburgerMenu = document.getElementById('hamburger-menu');
  const menu = document.querySelector('.menu');
  const dashBody = document.querySelector('.dash-body');

  if (hamburgerMenu) {
    hamburgerMenu.addEventListener('click', () => {
      hamburgerMenu.classList.toggle('active');
      menu.classList.toggle('active');
      dashBody.classList.toggle('active');
    });
  }
</script>
</body>
</html>
