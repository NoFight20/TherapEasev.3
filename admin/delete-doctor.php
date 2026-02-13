<?php

session_name('sess_admin'); session_start();

// Import Firebase database connection
include("../connection.php");

$message = "An error occurred during deletion.";
$redirectUrl = "doctors.php";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Check if the 'id' parameter is provided
    if (isset($_POST['id']) && !empty($_POST['id'])) {
        // Sanitize the ID
        $id = htmlspecialchars($_POST['id'], ENT_QUOTES, 'UTF-8');

        try {
            // Reference to the doctor record
            $doctorRef = $database->getReference('doctor/' . $id);

            // Check if the doctor record exists
            $doctorData = $doctorRef->getValue();
            if ($doctorData) {
                // Delete doctor record
                $doctorRef->remove();

                // Delete user record from the users node
                $database->getReference('users/' . $id)->remove();

                // Attempt to delete the user from Firebase Authentication
                try {
                    $auth->deleteUser($id);
                } catch (Exception $authException) {
                    // Log error but don't interrupt the deletion process
                    error_log("Error deleting user from Auth: " . $authException->getMessage());
                }

                // Log the deletion action
                $logsRef = $database->getReference('logs');
                $logsRef->push([
                    'action'    => 'Delete Doctor',
                    'doctor_id' => $id,
                    'timestamp' => date('H:i:s'),
                    'datestamp' => date('Y-m-d'),
                ]);

                $message = "Doctor record successfully deleted.";
            } else {
                $message = "Record not found. Deletion failed.";
            }
        } catch (Exception $e) {
            $message = "An error occurred: " . $e->getMessage();
        }
    } else {
        $message = "Invalid request. No record ID provided.";
    }
} else {
    $message = "Invalid request method.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Delete Doctor</title>
</head>
<body>
    <script>
        alert("<?php echo addslashes($message); ?>");
        window.location.href = "<?php echo $redirectUrl; ?>";
    </script>
</body>
</html>
