<?php
session_name('sess_it'); 
session_start();
include("../connection.php");

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Initialize an array to hold fields to update
    $dataToUpdate = [];

    // Check each field and add to $dataToUpdate if not empty
    if (!empty($_POST['about_us'])) {
        $dataToUpdate['about_us'] = $_POST['about_us'];
    }
    if (!empty($_POST['mission'])) {
        $dataToUpdate['mission'] = $_POST['mission'];
    }
    if (!empty($_POST['vision'])) {
        $dataToUpdate['vision'] = $_POST['vision'];
    }
    if (!empty($_POST['services'])) {
        $dataToUpdate['services'] = $_POST['services'];
    }
    if (!empty($_POST['announcement_doctor'])) {
        $dataToUpdate['announcement_doctor'] = $_POST['announcement_doctor'];
    }
    if (!empty($_POST['announcement_patient'])) {
        $dataToUpdate['announcement_patient'] = $_POST['announcement_patient'];
    }

    try {
        // Only update if there is data to change
        if (!empty($dataToUpdate)) {
            // Update the content in Firebase Realtime Database
            $database->getReference('homepage_content')->update($dataToUpdate);
            
            // Redirect to confirm success
            header("Location: cms.php?update=success");
            exit();
        } else {
            // No fields to update
            header("Location: cms.php?update=nochange");
            exit();
        }

    } catch (\Kreait\Firebase\Exception\DatabaseException $e) {
        // Handle error
        echo "Error updating content: " . $e->getMessage();
    }
} else {
    echo "Invalid request method.";
}
?>
