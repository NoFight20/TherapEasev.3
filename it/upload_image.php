<?php
session_name('sess_it'); 
session_start();

// Check if the user is logged in and of the correct type
if(isset($_SESSION["user"])){
    if(($_SESSION["user"]) == "" || $_SESSION['usertype'] != 'it'){
        header("location: ../login.php");
    } else {
        $useremail = $_SESSION["user"];
    }
} else {
    header("location: ../login.php");
}

// Import Firebase connection
include("../connection.php");

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['image'])) {
    $file = $_FILES['image'];
    $fileName = $file['name'];
    $fileTmpName = $file['tmp_name'];

    // Upload the image to Firebase Storage
    $bucket = $storage->getBucket();
    $firebaseStoragePath = 'client_page/' . $fileName;
    $fileUpload = fopen($fileTmpName, 'r');
    $bucket->upload($fileUpload, [
        'name' => $firebaseStoragePath
    ]);

    // Get the public URL of the uploaded file
    $imageUrl = $bucket->object($firebaseStoragePath)->signedUrl(new \DateTime('+1 year'));

    // Store the URL in Firebase Realtime Database
    $database->getReference('client_page/backgroundImage')->set($imageUrl);

    // Set a success message in the session
    $_SESSION['success_message'] = "Image uploaded successfully. Background image URL updated.";

    // Redirect to cms.php
    header("location: cms.php");
    exit();
} else {
    // Set an error message in the session
    $_SESSION['error_message'] = "Please upload a valid image.";
    header("location: cms.php");
    exit();
}
?>
