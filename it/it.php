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

// Fetch logged-in IT user details
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

// New branch: Process IT account creation
if (isset($_POST['add_it_account'])) {
    $fname_it       = $_POST['fname_it'];
    $lname_it       = $_POST['lname_it'];
    $itEmail        = $_POST['itEmail'];
    $itPassword     = $_POST['itPassword'];
    $itConfirm      = $_POST['itConfirm'];
    $created_at     = date('Y-m-d');
    $created_at2    = date('H:i:s');

    // Check if passwords match
    if ($itPassword !== $itConfirm) {
        echo "<script>
                alert('Passwords do not match!');
                window.history.back();
              </script>";
        exit();
    }

    // Hash the IT password
    $hashedPassword = password_hash($itPassword, PASSWORD_DEFAULT);

    // Prepare the new IT account data
    $newUserIt = [
        'name'         => "$fname_it $lname_it",
        'fname'        => $fname_it,
        'lname'        => $lname_it,
        'email'        => $itEmail,
        'password'     => $hashedPassword,
        'type'         => 'it',
        'date_created' => $created_at,
        'time_created' => $created_at2
    ];

    $webUserDataIt = [
        'email' => $itEmail,
        'type'  => 'it'
    ];

    try {
        // Create IT user in Firebase Authentication (requires plaintext password)
        $createdUser = $auth->createUser([
            'email'    => $itEmail,
            'password' => $itPassword
        ]);
        $uid = $createdUser->uid;

        // Save user data in Firebase Realtime Database under the "it" node and in "users"
        $database->getReference("it/$uid")->set($newUserIt);
        $database->getReference("users/$uid")->set($webUserDataIt);

        // Log the account creation action
        $logEntry = [
            'status'      => 'IT Account Created',
            'email'       => $useremail,
            'created_for' => $itEmail,
            'timestamp'   => $created_at2,
            'datestamp'   => $created_at
        ];
        $database->getReference("logs")->push($logEntry);

        // Redirect or inform on success
        echo "<script>
                alert('IT account added successfully!');
                window.location.href = 'secretary.php';
              </script>";
        exit();

    } catch (Exception $e) {
        echo "Error creating IT account: " . $e->getMessage();
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
    <title>IT</title>
    <style>
        /* Your existing CSS styles remain unchanged */
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
        }
		/* Mobile Header */
        .mobile-header {
            display: none;
            background-color: lightgreen;
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
                top: 50px;
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
                margin-top: 50px;
                margin-left: 0;
                transition: margin-left 0.3s ease;
            }
            .menu.active + .dash-body {
                margin-left: 250px;
            }
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
        .form-group {
            margin-bottom: 1rem; 
        }
        .form-group label {
            display: block;
            margin-bottom: 0.25rem;
        }
        .form-group input,
        .form-group select {
            width: 100%;
            box-sizing: border-box;
            padding: 8px;
        }
    </style>
</head>
<body>
    <!-- Mobile Header -->
    <div class="mobile-header">IT</div>
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
                                    <img src="../img/user.png" alt="" width="100%" style="border-radius:50%">
                                </td>
                                <td style="padding:0;margin:0;">
                                    <p class="profile-title"><?php echo substr($username,0,50) ?></p>
                                    <p class="profile-subtitle"><?php echo substr($useremail,0,50)  ?></p>
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
                <tr class="menu-row menu-active">
                    <td class="menu-btn menu-active">
                        <a href="it.php" class="non-style-link-menu non-style-link-menu-active">
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
                    <td class="menu-btn">
                        <a href="secretary.php" class="non-style-link-menu">
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
			<table border="0" width="100%" style="border-spacing: 0;margin:0;padding:0;margin-top:25px;">
				<tr>
                    <td width="13%">
						<a href="it.php"><button class="login-btn btn-primary-soft btn btn-icon-back" style="padding-top:11px;padding-bottom:11px;margin-left:20px;width:125px"><font class="tn-in-text">Back</font></button></a>
                    </td>
					<td>
                        <!-- Search Form -->
                        <form action="it.php" method="POST" class="header-search">
                            <input type="search" name="search" class="input-text header-searchbar" placeholder="Search IT name or Email" list="it">&nbsp;&nbsp;
                            <input type="Submit" value="Search" class="login-btn btn-primary btn" style="padding:10px 25px;">
                        </form> 
					</td>
                    <td width="15%">
                        <p style="font-size: 14px;color: rgb(119, 119, 119);text-align: right;">Today's Date</p>
                        <p class="heading-sub12" style="margin:0; text-align: right;">
                            <?php 
                                date_default_timezone_set('Asia/Kolkata');
                                $today = date('Y-m-d');
                                echo $today;
                            ?>
                        </p>
                    </td>
                    <td width="10%">
                        <button class="btn-label" style="display: flex;justify-content: center;align-items: center;"><img src="../img/calendar.svg" width="100%"></button>
                    </td>
                </tr>
			    <?php
                    try {
                        // Reference to the 'it' node in Firebase for IT accounts
                        $itRef = $database->getReference('it');
                        $itAccounts = $itRef->getValue();
                        $displayAccounts = $itAccounts ? $itAccounts : [];
                    } catch (Exception $e) {
                        echo "Error retrieving IT data: " . $e->getMessage();
                        $displayAccounts = [];
                    }
                    
                    // --- Search Functionality Start ---
                    if (isset($_POST['search']) && !empty(trim($_POST['search']))) {
                        $searchQuery = trim($_POST['search']);
                        $filteredAccounts = [];
                        foreach ($displayAccounts as $key => $it) {
                            // Check if the account name or email contains the search query (case-insensitive)
                            if (
                                (isset($it['name']) && stripos($it['name'], $searchQuery) !== false) ||
                                (isset($it['email']) && stripos($it['email'], $searchQuery) !== false)
                            ) {
                                $filteredAccounts[$key] = $it;
                            }
                        }
                        $displayAccounts = $filteredAccounts;
                    }
                    // --- Search Functionality End ---
                ?>
                <tr>
                    <td colspan="2" style="padding-top:30px;">
                        <p class="heading-main12" style="margin-left: 45px;font-size:20px;color:rgb(49, 49, 49)">IT (<?php echo count($displayAccounts); ?>)</p>
                    </td>
                    <td colspan="2">
                        <!-- New "Create IT Account" button -->
                        <a href="#" class="non-style-link" onclick="openAddItAccountPopup()">
                            <button class="login-btn btn-primary btn button-icon" style="display: flex; justify-content: center; align-items: center; margin-left: 20px; background-image: url('../img/icons/add.svg');">
                                Create IT Account
                            </button>
                        </a>
                    </td>
                </tr>
                
                <?php
                // Display IT accounts in a table
                if (!empty($displayAccounts)) {
                    echo '<table width="93%" class="sub-table scrolldown" border="0" style="margin: 50px 0 0 50px">';
                    echo '<thead>
                            <tr>
                                <th class="table-headin">IT Name</th>
                                <th class="table-headin">Email</th>
                                <th class="table-headin">Date Created</th>
                                <th class="table-headin">Edit</th>
                                <th class="table-headin">Remove IT Account</th>
                            </tr>
                        </thead>';
                    foreach ($displayAccounts as $key => $it) {
                        echo "<tr style='text-align: center; vertical-align: middle;'>
                                <td style='padding: 10px 0; border-bottom: 1px solid #ddd;'>" . htmlspecialchars($it['name']) . "</td>
                                <td style='padding: 10px 0; border-bottom: 1px solid #ddd;'>" . htmlspecialchars($it['email']) . "</td>
                                <td style='padding: 10px 0; border-bottom: 1px solid #ddd;'>" . htmlspecialchars($it['date_created'] ?? 'N/A') . "</td>
                                <td style='padding: 10px 0; border-bottom: 1px solid #ddd;'>
                                    <!-- Edit button for updating password -->
                                    <button onclick=\"showEditPopup('$key', '" . htmlspecialchars($it['name']) . "', 'it')\"
                                            class=\"login-btn btn-primary btn\">
                                        <i class='fas fa-edit' style='margin-right: 8px;'></i> Edit
                                    </button>
                                </td>
                                <td style='padding: 10px 0; border-bottom: 1px solid #ddd;'>
                                    <!-- Remove button -->
                                    <form method='POST' onsubmit=\"return confirm('Are you sure you want to remove this IT account?');\" style='display:inline;'>
                                        <input type='hidden' name='remove_key' value='$key'>
                                        <button type='submit' name='remove_it' class='login-btn btn-danger btn'>
                                            <i class='fas fa-trash' style='margin-right: 8px;'></i> Remove
                                        </button>
                                    </form>
                                </td>
                            </tr>";
                    }
                    echo '</table>';
                } else {
                    echo '<table width="93%" class="sub-table scrolldown" border="0" style="margin: 50px 0 0 50px">';
                    echo '<tr><td colspan="5"><center>No IT accounts found.</center></td></tr>';
                    echo '</table>';
                }
                ?>
            </table>
        </div>
    </div>

    <!-- Password Edit Popup -->
    <div class="overlay" id="editPasswordPopup" style="display: none;">
        <div class="popup">
            <a href="#" class="close" onclick="closeEditPopup()">&times;</a>
            <h2>Edit Password</h2>
            <form action="it.php" method="POST">
                <input type="hidden" id="adminKey" name="admin_key">
                <input type="hidden" id="accountType" name="account_type">
                <div class="form-group">
                    <label for="adminName">Account Name:</label>
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

    <!-- IT Account Creation Modal -->
    <div class="overlay" id="addItAccountPopup" style="display: none;">
      <div class="popup" style="margin-top: -50px; margin-left: 100px;">
        <a href="#" class="close" onclick="closeItAccountPopup()">&times;</a>
        <h2>Create IT Account</h2>
        <form action="it.php" method="POST">
          <div class="form-group">
            <label for="fname_it">First Name:</label>
            <input type="text" id="fname_it" name="fname_it" required>
          </div>
          <div class="form-group">
            <label for="lname_it">Last Name:</label>
            <input type="text" id="lname_it" name="lname_it" required>
          </div>
          <div class="form-group">
            <label for="itEmail">Email:</label>
            <input type="email" id="itEmail" name="itEmail" required>
          </div>
          <div class="form-group">
            <label for="itPassword">Password:</label>
            <input type="password" id="itPassword" name="itPassword" required>
          </div>
          <div class="form-group">
            <label for="itConfirm">Confirm Password:</label>
            <input type="password" id="itConfirm" name="itConfirm" required>
          </div>
          <button type="submit" name="add_it_account" class="login-btn btn-primary btn">Create IT Account</button>
        </form>
      </div>
    </div>

    <script>
        function closeEditPopup() {
            document.getElementById('editPasswordPopup').style.display = 'none';
        }
        document.getElementById('hamburger-menu').addEventListener('click', function () {
            document.querySelector('.menu').classList.toggle('active');
            this.classList.toggle('active');
        });
        function showEditPopup(accountKey, accountName, accountType = 'admin') {
            document.getElementById('editPasswordPopup').style.display = 'block';
            document.getElementById('adminKey').value = accountKey;
            document.getElementById('adminName').value = accountName;
            document.getElementById('accountType').value = accountType;
        }
        function closeItAccountPopup() {
            document.getElementById('addItAccountPopup').style.display = 'none';
        }
        function openAddItAccountPopup() {
            document.getElementById('addItAccountPopup').style.display = 'block';
        }
        // Hamburger menu toggle (existing)
        document.getElementById('hamburger-menu').addEventListener('click', function () {
            document.querySelector('.menu').classList.toggle('active');
            this.classList.toggle('active');
        });
    </script>
</body>
</html>
