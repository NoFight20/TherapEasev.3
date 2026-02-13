<?php
// Include Firebase connection
include("connection.php");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Retrieve form inputs
    $email = $_POST['email'];
    $phone = $_POST['phone'];

    try {
        // Update the 'contact' node in Firebase
        $database->getReference('contact')->set([
            'email' => $email,
            'number' => $phone,
        ]);

        // Redirect to a success page or show success message
        echo "<script>
                alert('Contact information updated successfully!');
                window.location.href = 'contacts_page.php'; // Change to your contacts page or dashboard
              </script>";
    } catch (Exception $e) {
        // Handle errors
        echo "Error updating contact information: " . $e->getMessage();
    }
} else {
    echo "Invalid request.";
}
?>
