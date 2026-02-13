<?php
session_name('sess_admin'); session_start();

if (isset($_SESSION["user"])) {
    if ($_SESSION["user"] == "" || $_SESSION['usertype'] != 'a') {
        header("location: ../login.php");
        exit;
    }
} else {
    header("location: ../login.php");
    exit;
}

if ($_GET) {
    include("../connection.php");

    // Get the Firebase ID for the appointment to be deleted
    $id = $_GET["id"];

    try {
        // Define the path to the appointment node in Firebase
        $databasePath = "appointment/$id";

        // Check if the appointment exists
        $appointment = $database->getReference($databasePath)->getValue();

        if ($appointment) {
            // Remove the appointment if it exists
            $database->getReference($databasePath)->remove();

            // Redirect to the appointments page with a success message
            header("location: appointment.php?status=success&message=" . urlencode("Appointment deleted successfully"));
            exit;
        } else {
            // Redirect with an error message if the appointment does not exist
            header("location: appointment.php?status=error&message=" . urlencode("Appointment not found"));
            exit;
        }
    } catch (Exception $e) {
        // Handle any exceptions and redirect with an error message
        header("location: appointment.php?status=error&message=" . urlencode("Error deleting appointment: " . $e->getMessage()));
        exit;
    }
} else {
    // Redirect if the request is not valid
    header("location: appointment.php");
    exit;
}
