<?php
// Import database
include("../connection.php");

if (isset($_GET['id'])) {
    $patientId = $_GET['id'];

    // Delete patient record from Firebase
    $database->getReference('queue/' . $patientId)->remove();

    echo "Patient successfully removed!";
} else {
    echo "Invalid request.";
}
?>
