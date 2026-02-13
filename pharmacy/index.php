<?php


session_start();

// Check if the user is logged in and is a pharmacist
if(isset($_SESSION["user"])){
    if(($_SESSION["user"]) == "" || $_SESSION['usertype'] != 'ph'){
        header("location: ../login.php");
    } else {
        $useremail = $_SESSION["user"];
    }
} else {
    header("location: ../login.php");
}

// Import database
include("../connection.php");

try {
    $reference = $database
        ->getReference('pharmacy')
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
        // Handle the case where no user is found
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
        
    <title>Dashboard</title>
    <style>
        /* Adjust table container to make it smaller */
    </style>
    
</head>

<body>
    <!-- Mobile Header -->
    <div class="mobile-header">Dashboard</div>

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
                        <td class="menu-btn menu-active">
                            <a href="index.php" class="non-style-link-menu non-style-link-menu-active">
                                <p class="menu-text">Dashboard</p>
                            </a>
                        </td>
                    </tr>
            </table>
        </div>
        


        <div class="dash-body" style="margin-top: 15px">
            <table border="0" width="100%" style=" border-spacing: 0;margin:0;padding:0;" >
                            <td width="15%">
                                <p style="font-size: 14px;color: rgb(119, 119, 119);padding: 0;margin: 0;text-align: right;">
                                    Today's Date
                                </p>
                                <p class="heading-sub12" style="padding: 0;margin: 0;">
                                    <?php 
                              
                                    // Set the timezone
                                    date_default_timezone_set('Asia/Manila');

                                    // Get today's date
                                    $today = date('Y-m-d');
                                    echo $today;
                                ?>
                                </p>
                            </td>
                            <td width="10%">
                                <button  class="btn-label"  style="display: flex;justify-content: center;align-items: center;"><img src="../img/calendar.svg" width="100%"></button>
                            </td> 
                        </tr>         
        </div>

                

        
<script>
</script>

    
</body>
</html>