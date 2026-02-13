<?php
session_name('sess_patient'); session_start();
if (!isset($_SESSION["user"]) || $_SESSION["usertype"] != "p") {
    header("location: ../login.php");
    exit();
}

$useremail = $_SESSION["user"];

// Include the database connection
include("../connection.php");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Retrieve form data
    $fname = htmlspecialchars($_POST["fname"]);
    $lname = htmlspecialchars($_POST["lname"]);
    $tele = htmlspecialchars($_POST["tele"]);
    $city = htmlspecialchars($_POST["city"]);
    $province = htmlspecialchars($_POST["province"]);
    $gender = htmlspecialchars($_POST["gender"]);
    $dob = htmlspecialchars($_POST["dob"]);
    $civil_status = htmlspecialchars($_POST["civil_status"]);

    // Handle file upload for the profile picture
    $photoBase64 = null;
    if (isset($_FILES["photo"]) && $_FILES["photo"]["error"] == 0) {
        $fileTmpPath = $_FILES["photo"]["tmp_name"];
        $fileType = mime_content_type($fileTmpPath);

        // Validate file type
        $allowedMimeTypes = ["image/jpeg", "image/png"];
        if (in_array($fileType, $allowedMimeTypes)) {
            // Read file content and encode it to Base64
            $fileContent = file_get_contents($fileTmpPath);
            $photoBase64 = 'data:' . $fileType . ';base64,' . base64_encode($fileContent);
        } else {
            echo "Invalid file type. Only JPG and PNG are allowed.";
            exit();
        }
    }

    // Fetch patient data from Firebase to locate the record
    $patientRef = $database->getReference('patients')->orderByChild('email')->equalTo($useremail);
    $patientSnapshot = $patientRef->getSnapshot();
    $patientData = $patientSnapshot->getValue();

    if ($patientData) {
        $patientKey = array_keys($patientData)[0]; // Get the unique key of the patient record

        // Update patient data
        $updateData = [
            "fname" => $fname,
            "lname" => $lname,
            "tele" => $tele,
            "city" => $city,
            "province" => $province,
            "gender" => $gender,
            "dob" => $dob,
            "civil_status" => $civil_status,
        ];

        // Add Base64-encoded photo if uploaded
        if ($photoBase64) {
            $updateData["photo"] = $photoBase64;
        }

        // Save updated data back to Firebase
        $database->getReference('patients/' . $patientKey)->update($updateData);

        // Redirect with success message
        header("location: settings.php?success=1");
        exit();
    } else {
        echo "Patient record not found.";
        exit();
    }
} else {
    echo "Invalid request.";
    exit();
}

?>
