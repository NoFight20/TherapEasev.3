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

    $sqlmain = "SELECT * FROM patient WHERE pemail=?";
    $stmt = $database->prepare($sqlmain);
    $stmt->bind_param("s", $useremail);
    $stmt->execute();
    $result = $stmt->get_result();

    // Check if user was found
    if ($result && $userfetch = $result->fetch_assoc()) {
        $userid = $userfetch["pid"];
        $username = $userfetch["pname"];
    } else {
        // Handle the case where no user is found
        $username = "Unknown";
    }

    date_default_timezone_set('Asia/Manila');
    $today = date('Y-m-d');

    ?>