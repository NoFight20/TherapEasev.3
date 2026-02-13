<?php
// Start the session
session_start();

// Import Firebase connection
include("../connection.php");

// Check if the user is logged in and of the correct type
if (isset($_SESSION["user"])) {
    if (empty($_SESSION["user"]) || $_SESSION['usertype'] != 'it') {
        header("location: ../login.php");
        exit();
    } else {
        $useremail = $_SESSION["user"];
    }
} else {
    header("location: ../login.php");
    exit();
}

try {
    $reference = $database
        ->getReference('it')
        ->orderByChild('email')
        ->equalTo($useremail)
        ->getSnapshot();

    $userfetch = $reference->getValue();

    if ($userfetch) {
        foreach ($userfetch as $key => $value) {
            $username = $value['name'];
        }
    } else {
        echo "No user found.";
    }
} catch (\Kreait\Firebase\Exception\DatabaseException $e) {
    echo "Error querying the database: " . $e->getMessage();
    exit();
}

if (isset($_POST['fname'], $_POST['lname'], $_POST['adminEmail'], $_POST['password'], $_POST['confirm_password'], $_POST['specialty'])) {
    $fname            = $_POST['fname'];
    $lname            = $_POST['lname'];
    $adminEmail       = $_POST['adminEmail'];
    $adminPassword    = $_POST['password'];
    $admin_Password   = $_POST['confirm_password'];
    $specialty_input  = $_POST['specialty'];  
    $created_at       = date('Y-m-d');
    $created_at2      = date('H:i:s');

    // Check if password and confirm password match
    if ($adminPassword !== $admin_Password) {
        echo "<script>
                alert('Passwords do not match!');
                window.history.back();
              </script>";
        exit();
    }

    // Retrieve specialties from Firebase RTDB
    $specialtiesRef = $database->getReference('specialties')->getValue();

    $specialty_key = $specialty_input;
    $sname = "Unknown Specialty";

    if ($specialtiesRef && is_array($specialtiesRef)) {
        foreach ($specialtiesRef as $key => $specialtyInfo) {
            // If both the submitted value and the specialty id from RTDB are numeric,
            // cast them to integers for proper comparison.
            if (is_numeric($specialty_input) && isset($specialtyInfo['id']) && is_numeric($specialtyInfo['id'])) {
                if ((int)$specialty_input === (int)$specialtyInfo['id']) {
                    // Instead of storing the id, store the unique key from RTDB
                    $specialty_key = $key;
                    $sname = $specialtyInfo['sname'];
                    break;
                }
            } else {
                // Otherwise, compare as strings
                if ($specialty_input === $key || (isset($specialtyInfo['id']) && (string)$specialtyInfo['id'] === $specialty_input)) {
                    $specialty_key = $key;
                    $sname = $specialtyInfo['sname'];
                    break;
                }
            }
        }
    }

    $userType = ($specialty_input === '21') ? 'ph' : 'a';
    $nodePath = ($specialty_input === '21') ? 'pharmacy' : 'admin';

    // Hash the admin password before storing it in the database
    $hashedPassword = password_hash($adminPassword, PASSWORD_DEFAULT);

    $newUser = [
        'name'         => "$fname $lname",
        'fname'        => $fname,
        'lname'        => $lname,
        'email'        => $adminEmail,
        'password'     => $hashedPassword,
        'type'         => $userType,
        'specialty'    => $specialty_key,
        'sname'        => $sname,
        'date_created' => $created_at,
        'time_created' => $created_at2
    ];

    $webUserData = [
        'email'     => $adminEmail,
        'type'      => $userType,
        'specialty' => $specialty_key,
        'sname'     => $sname
    ];

    try {
        // Create user in Firebase Authentication (requires plaintext password)
        $createdUser = $auth->createUser([
            'email'    => $adminEmail,
            'password' => $adminPassword
        ]);

        $uid = $createdUser->uid;

        // Save user data in Firebase Realtime Database under the appropriate node
        $database->getReference("$nodePath/$uid")->set($newUser);
        $database->getReference("users/$uid")->set($webUserData);

        // Log the account creation, including both specialty unique key and name
        $logEntry = [
            'status'         => 'Account Created',
            'created_by'     => $_SESSION['user'],
            'created_for'    => $adminEmail,
            'timestamp'      => $created_at2,
            'datestamp'      => $created_at,
            'specialty_key'  => $specialty_key,
            'specialty_name' => $sname
        ];
        $database->getReference("logs")->push($logEntry);

        // Redirect upon success
        echo "<script>
                alert('Admin account added successfully!');
                window.location.href = 'secretary.php';
              </script>";
        exit();

    } catch (Exception $e) {
        echo "Error creating admin account: " . $e->getMessage();
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
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">

        
    <title>Doctors</title>
    <style>
       
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
            background: rgba(0, 0, 0, 0.7);
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
            margin-top: -60px;
            margin-left: 100px;
        }
		/* Mobile Header */
        .mobile-header {
            display: none;
            background-color: lightgreen; /* Set the background color for the header */
            color: white;
            padding: 15px;
            text-align: center;
            font-size: 18px;
            position: fixed;
            top: 0;
            width: 100%;
            z-index: 1002;
        }
		/* Hamburger Menu */
        #hamburger-menu {
            display: none;
            width: 30px;
            height: 30px;
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 1003;
            cursor: pointer;
            flex-direction: column;
            justify-content: space-between;
            align-items: center;
        }

        #hamburger-menu .bar {
            width: 100%;
            height: 3px;
            background-color: #333;
            transition: all 0.4s ease;
        }

        #hamburger-menu.active .bar:nth-child(1) {
            transform: rotate(-45deg) translate(-5px, 5px);
        }

        #hamburger-menu.active .bar:nth-child(2) {
            opacity: 0;
        }

        #hamburger-menu.active .bar:nth-child(3) {
            transform: rotate(45deg) translate(-5px, -5px);
        }

        /* Responsive Design */
        @media screen and (max-width: 768px) {
            #hamburger-menu {
                display: flex;
            }

            .mobile-header {
                display: block;
            }

            .menu {
                display: none;
                position: fixed;
                left: -250px;
                top: 50px; /* Adjust to account for mobile header */
                width: 250px;
                height: 100%;
                background: lightgreen;
                transition: left 0.3s ease;
                z-index: 1001;
            }

            .menu.active {
                display: block;
                left: 0;
            }

            .dash-body {
                margin-top: 50px; /* Adjust to account for mobile header */
                margin-left: 0;
                transition: margin-left 0.3s ease;
            }

            .menu.active + .dash-body {
                margin-left: 250px;
            }
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

.form-group {
  margin-bottom: 1rem; /* Adjust as needed for more or less space */
}

.form-group label {
  display: block;       /* Ensures label is on its own line */
  margin-bottom: 0.25rem;
}

.form-group input,
.form-group select {
  width: 100%;          /* Optional: makes inputs stretch full width */
  box-sizing: border-box;
  padding: 8px;         /* Some padding for a nicer look */
}

        
</style>
</head>

<body>
    <!-- Mobile Header -->
    <div class="mobile-header">Doctors</div>

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
                                    <p class="profile-title"><?php echo substr($username,0,50) ?></p>
                                <p class="profile-subtitle"><?php echo substr($useremail,0,50)  ?></p>
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
                            <a href="dashboard.php" class="non-style-link-menu ">
                                <p class="menu-text">Dashboard</p>
                            </a>
                        </td>
                    </tr>
                    <tr class="menu-row">
                        <td class="menu-btn ">
                            <a href="logs.php" class="non-style-link-menu">
                                <p class="menu-text">Logs</p>
                            </a>
                        </td>
                    </tr>
                    <tr class="menu-row">
                        <td class="menu-btn ">
                            <a href="clients.php" class="non-style-link-menu">
                                <p class="menu-text">Patient Accounts</p>
                            </a>
                        </td>
                    </tr>
                    <tr class="menu-row">
                        <td class="menu-btn ">
                            <a href="it.php" class="non-style-link-menu">
                                <p class="menu-text">IT Accounts</p>
                            </a>
                        </td>
                    </tr>
                    <tr class="menu-row">
                        <td class="menu-btn ">
                            <a href="doctor.php" class="non-style-link-menu">
                                <p class="menu-text">Doctors Account</p>
                            </a>
                        </td>
                    </tr>
                    <tr class="menu-row">
                        <td class="menu-btn menu-active">
                            <a href="secretary.php" class="non-style-link-menu non-style-link-menu-active">
                                <p class="menu-text">Secretary Accounts</p>
                            </a>
                        </td>
                    </tr>
                    <tr class="menu-row">
                        <td class="menu-btn ">
                            <a href="cms.php" class="non-style-link-menu">
                                <p class="menu-text">CMS</p>
                            </a>
                        </td>
                    </tr>
            </table>
        </div>
		
		<div class="dash-body">
			<table border="0" width="100%" style=" border-spacing: 0;margin:0;padding:0;margin-top:25px; ">
			<tr >
            <td width="13%" >
					<a href="secretary.php" ><button  class="login-btn btn-primary-soft btn btn-icon-back"  style="padding-top:11px;padding-bottom:11px;margin-left:20px;width:125px"><font class="tn-in-text">Back</font></button></a>
                </td>
				<td>
                <form action="secretary.php" method="POST" class="header-search">
                    <input type="search" name="search" class="input-text header-searchbar" placeholder="Search Secretary name or Email" list="secretary">&nbsp;&nbsp;
                    <input type="Submit" value="Search" class="login-btn btn-primary btn" style="padding-left: 25px;padding-right: 25px;padding-top: 10px;padding-bottom: 10px;">
                </form> 

				</td>

                
                 <td width="15%">
                    <p style="font-size: 14px;color: rgb(119, 119, 119);padding: 0;margin: 0;text-align: right;">
                        Today's Date
                    </p>
                    <p class="heading-sub12" style="padding: 0;margin: 0;">
                    <?php 
                    // Set timezone
                    date_default_timezone_set('Asia/Kolkata');

                    // Get today's date
                    $today = date('Y-m-d');
                    echo $today;
                    ?>
                    </p>
                </td>
                <td width="10%">
                    <button  class="btn-label"  style="display: flex;justify-content: center;align-items: center;"><img src="../img/calendar.svg" width="100%"></button>
                </td>
            </tr >
			            <?php
                            try {
                                // Reference to the 'secretaries' node in Firebase
                                $secretaryRef = $database->getReference('admin');
                                $secretaries = $secretaryRef->getValue();

                                // Count the number of secretaries
                                $secretaryCount = $secretaries ? count($secretaries) : 0;
                            } catch (Exception $e) {
                                echo "Error retrieving secretary data: " . $e->getMessage();
                                $secretaryCount = 0; 
                            }
                            ?>
                            <tr >
                                <td colspan="2" style="padding-top:30px;">
                                    <p class="heading-main12" style="margin-left: 45px;font-size:20px;color:rgb(49, 49, 49)">Secretary (<?php echo $secretaryCount; ?>)</p>
                                </td>
                                <td colspan="2">
                                <a href="#" class="non-style-link" onclick="openAddAdminPopup()">
                                    <button class="login-btn btn-primary btn button-icon" style="display: flex; justify-content: center; align-items: center; margin-left: 75px; background-image: url('../img/icons/add.svg');">
                                        Add New
                                    </button>
                                </a>
                            </tr>
                            
			
                            <?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get current datestamp and timestamp for logs
    $datestamp = date('Y-m-d');
    $timestamp = date('H:i:s');

    // Handle search query
    if (isset($_POST['search'])) {
        $searchTerm = $_POST['search'];

        try {
            $adminRef = $database->getReference('admin');
            $admins = $adminRef->getValue();

            $searchResults = [];
            if ($admins) {
                foreach ($admins as $key => $admin) {
                    if (
                        stripos($admin['name'], $searchTerm) !== false || 
                        stripos($admin['email'], $searchTerm) !== false
                    ) {
                        $searchResults[$key] = $admin;
                    }
                }
            }

            $displayAccounts = $searchResults;

            // Log the search action
            $logEntry = [
                'status'         => 'Search Performed',
                'created_by'     => $_SESSION['user'] ?? 'unknown',
                'created_for'    => $searchTerm,
                'timestamp'      => $timestamp,
                'datestamp'      => $datestamp,
                'specialty_key'  => '',
                'specialty_name' => ''
            ];
            $database->getReference('logs')->push($logEntry);
        } catch (Exception $e) {
            echo "Error retrieving admin data: " . $e->getMessage();
            $displayAccounts = [];
        }
    }
    elseif (isset($_POST['update_password'])) {
        $adminKey        = $_POST['admin_key'];
        $newPassword     = $_POST['new_password'];
        $confirmPassword = $_POST['confirm_password'];
    
        // Check if the new password and confirm password match
        if ($newPassword !== $confirmPassword) {
            echo "<script>
                    alert('Passwords do not match!');
                    window.history.back();
                  </script>";
            exit();
        }
    
        // Hash the new password before updating
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    
        try {
            // Update password in Firebase Realtime Database with the hashed password
            $database->getReference("admin/$adminKey")->update([
                'password' => $hashedPassword,
            ]);

            // Retrieve the admin data to log details
            $adminData = $database->getReference("admin/$adminKey")->getValue();
            $adminEmail = $adminData['email'] ?? 'unknown';
            $specialtyKey = isset($adminData['specialty']) ? $adminData['specialty'] : '';
            $specialtyName = ($specialtyKey && isset($specialtiesList[$specialtyKey])) ? $specialtiesList[$specialtyKey] : '';

            // Log the password update action
            $logEntry = [
                'status'         => 'Password Updated',
                'created_by'     => $_SESSION['user'] ?? 'unknown',
                'created_for'    => $adminEmail,
                'timestamp'      => $timestamp,
                'datestamp'      => $datestamp,
                'specialty_key'  => $specialtyKey,
                'specialty_name' => $specialtyName
            ];
            $database->getReference('logs')->push($logEntry);

            echo "<script>
                    alert('Password updated successfully!');
                    window.location.href = 'secretary.php';
                  </script>";
            exit();
        } catch (Exception $e) {
            echo "Error updating password: " . $e->getMessage();
        }
    }
    // Handle remove admin
    elseif (isset($_POST['remove_admin'])) {
        $removeKey = $_POST['remove_key'];

        try {
            // Retrieve admin data before removal to log details
            $adminData = $database->getReference("admin/$removeKey")->getValue();
            $adminEmail = $adminData['email'] ?? 'unknown';
            $specialtyKey = isset($adminData['specialty']) ? $adminData['specialty'] : '';
            $specialtyName = ($specialtyKey && isset($specialtiesList[$specialtyKey])) ? $specialtiesList[$specialtyKey] : '';

            // Remove admin from Firebase
            $database->getReference("admin/$removeKey")->remove();
            $database->getReference("users/$removeKey")->remove();

            // Log the admin removal action
            $logEntry = [
                'status'         => 'Admin Removed',
                'created_by'     => $_SESSION['user'] ?? 'unknown',
                'created_for'    => $adminEmail,
                'timestamp'      => $timestamp,
                'datestamp'      => $datestamp,
                'specialty_key'  => $specialtyKey,
                'specialty_name' => $specialtyName
            ];
            $database->getReference('logs')->push($logEntry);

            echo "<script>alert('Admin account removed successfully!');</script>";

            // Refetch data after removal
            $adminRef = $database->getReference('admin');
            $admins = $adminRef->getValue();
            $displayAccounts = $admins ? $admins : [];
        } catch (Exception $e) {
            echo "Error removing admin: " . $e->getMessage();
            $displayAccounts = [];
        }
    }
} else {
    // Default: Display all accounts
    try {
        $adminRef = $database->getReference('admin');
        $admins = $adminRef->getValue();
        $displayAccounts = $admins ? $admins : [];
    } catch (Exception $e) {
        echo "Error retrieving admin data: " . $e->getMessage();
        $displayAccounts = [];
    }
}

// Fetch Specialties from Firebase
$specialtiesRef = $database->getReference('specialties')->getValue();
$specialtiesList = [];
if ($specialtiesRef) {
    foreach ($specialtiesRef as $id => $specialty) {
        $specialtiesList[$id] = $specialty['sname']; // Store specialty names
    }
}

// Display accounts without sorting
if (!empty($displayAccounts)) {
    echo '<table width="93%" class="sub-table scrolldown" border="0" style="margin: 50px 0 0 50px">';
    echo '<thead>
            <tr>
                <th class="table-headin">Admin Name</th>
                <th class="table-headin">Email</th>
                <th class="table-headin">Date Created</th>
                <th class="table-headin">Specialty</th>
                <th class="table-headin">Change Password</th>
                <th class="table-headin">Remove Admin</th>
            </tr>
        </thead>';

    foreach ($displayAccounts as $key => $admin) {
        $specialtyName = 'N/A';
        if (isset($admin['specialty']) && isset($specialtiesList[$admin['specialty']])) {
            $specialtyName = htmlspecialchars($specialtiesList[$admin['specialty']]);
        }

        echo "<tr style='text-align: center; vertical-align: middle;'>
                <td style='padding: 10px 0; border-bottom: 1px solid #ddd;'>" . htmlspecialchars($admin['name']) . "</td>
                <td style='padding: 10px 0; border-bottom: 1px solid #ddd;'>" . htmlspecialchars($admin['email']) . "</td>
                <td style='padding: 10px 0; border-bottom: 1px solid #ddd;'>" . htmlspecialchars($admin['date_created'] ?? 'N/A') . "</td>
                <td style='padding: 10px 0; border-bottom: 1px solid #ddd;'>" . $specialtyName . "</td>
                <td style='padding: 10px 0; border-bottom: 1px solid #ddd;'>
                    <!-- Existing Edit button -->
                    <button onclick=\"showEditPopup('$key', '" . htmlspecialchars($admin['name']) . "')\" 
                            class=\"login-btn btn-primary btn\">
                        <i class='fas fa-edit' style='margin-right: 8px;'></i> Edit
                    </button>
                </td>
                <td style='padding: 10px 0; border-bottom: 1px solid #ddd;'>
                    <!-- New Remove button -->
                    <form method='POST' onsubmit=\"return confirm('Are you sure you want to remove this admin?');\" style='display:inline;'>
                        <input type='hidden' name='remove_key' value='$key'>
                        <button type='submit' name='remove_admin' class='login-btn btn-danger btn'>
                            <i class='fas fa-trash' style='margin-right: 8px;'></i> Remove
                        </button>
                    </form>
                </td>
            </tr>";
    }
    echo '</table>';
}
?>

<!-- Password Edit Popup -->
<div class="overlay" id="editPasswordPopup" style="display: none;">
    <div class="popup">
        <a href="#" class="close" onclick="closeEditPopup()">&times;</a>
        <h2>Edit Password</h2>
        <form action="secretary.php" method="POST">
            <input type="hidden" id="adminKey" name="admin_key">

            <div class="form-group">
                <label for="adminName">Admin Name:</label>
                <input type="text" id="adminName" name="admin_name" readonly>
            </div>

            <div class="form-group">
                <label for="newPassword">New Password:</label>
                <input type="password" id="newPassword" name="new_password" required>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm Password:</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
            </div>

            <button type="submit" name="update_password" class="login-btn btn-primary btn">Update Password</button>
        </form>
    </div>
</div>


<div class="overlay" id="addAdminPopup">
  <div class="popup">
    <a href="#" class="close" onclick="closePopup()">&times;</a>
    <h2>Add New Admin</h2>
    
    <form action="secretary.php" method="POST">
      <div class="form-group">
        <label for="fname">First Name:</label>
        <input type="text" id="fname" name="fname" required>
      </div>

      <div class="form-group">
        <label for="lname">Last Name:</label>
        <input type="text" id="lname" name="lname" required>
      </div>

      <div class="form-group">
        <label for="adminEmail">Email:</label>
        <input type="email" id="adminEmail" name="adminEmail" required>
      </div>

      <div class="form-group">
        <label for="password">Password:</label>
        <input type="password" id="password" name="password" required>
      </div>

      <div class="form-group">
        <label for="password">Confirm Password:</label>
        <input type="password" id="confirm_password" name="confirm_password" required>
      </div>

      <div class="form-group">
        <label for="specialty">Select Specialty:</label>
        <select id="specialty" name="specialty" required>
          <option value="" disabled selected>Select A Specialty</option>
          <?php
             try {
                // Fetch specialties from Firebase
                $specialtiesRef = $database->getReference('specialties');
                $specialties = $specialtiesRef->getValue();

                if ($specialties) {
                    foreach ($specialties as $specialty) {
                        // Output each specialty as a dropdown option
                        echo '<option value="' . htmlspecialchars($specialty['id']) . '">' . htmlspecialchars($specialty['sname']) . '</option>';
                    }
                } else {
                    echo '<option value="" disabled>No specialties available</option>';
                }
            } catch (Exception $e) {
                // Handle errors gracefully
                echo '<option value="" disabled>Error loading specialties</option>';
            }
          ?>
        </select>
      </div>

      <button type="submit" class="login-btn btn-primary btn">Add Admin</button>
    </form>
  </div>
</div>


<script>
    function showEditPopup(adminKey, adminName) {
    document.getElementById('editPasswordPopup').style.display = 'block';
    document.getElementById('adminKey').value = adminKey;
    document.getElementById('adminName').value = adminName;
}
  // Fetch specialties from Firebase and populate the dropdown
  const specialtyDropdown = document.getElementById('specialty');

// Load specialties when the popup is opened
function openAddAdminPopup() {
    document.getElementById('addAdminPopup').style.display = 'block';
    loadSpecialties(); // Fetch specialties dynamically
}

function closeEditPopup() {
    document.getElementById('editPasswordPopup').style.display = 'none';
}

    document.getElementById('hamburger-menu').addEventListener('click', function () {
    document.querySelector('.menu').classList.toggle('active');
    this.classList.toggle('active');
});

function closePopup() {
    document.getElementById('addAdminPopup').style.display = 'none';
}


</script>
    </div>
</body>
</html>	
			