<?php
session_name('sess_patient'); session_start();
include("../connection.php");

if(isset($_SESSION["user"])) {
    $userEmail = $_SESSION["user"];
    $userType = $_SESSION['usertype'];

    if (empty($userEmail) || $userType != 'p') {
        header("location: ../login.php");
        exit();
    }
} else {
    header("location: ../login.php");
    exit();
}

try {
    // Get the user's information from the patients table
    $userRef = $database->getReference('patients')
                        ->orderByChild('email')
                        ->equalTo($userEmail)
                        ->limitToFirst(1);
    $userSnapshot = $userRef->getSnapshot();
    
    if (!$userSnapshot->exists()) {
        throw new Exception('User not found');
    }

    $userData = $userSnapshot->getValue();
    $userId = array_keys($userData)[0];
    $username = $userData[$userId]['name'];

    if ($_GET) {
        $id = $_GET["id"];

        // Get the patient's email based on ID
        $patientRef = $database->getReference('patients/' . $id);
        $patientSnapshot = $patientRef->getSnapshot();
        
        if (!$patientSnapshot->exists()) {
            throw new Exception('Patient not found');
        }

        $patientEmail = $patientSnapshot->getValue()['email'];

        // Delete from the `webusers` and `patients` tables in Firebase
        $database->getReference('webusers/' . $patientEmail)->remove();
        $database->getReference('patients/' . $id)->remove();

        // Redirect to logout (equivalent to logging out the session)
        header("location: ../logout.php");
        exit();
    }

} catch (Exception $e) {
    // Handle any errors (e.g., user not found)
    error_log($e->getMessage());
    header("location: ../login.php");
    exit();
}

?>