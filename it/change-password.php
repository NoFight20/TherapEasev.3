<?php
session_name('sess_it'); session_start();
if(isset($_SESSION["user"])){
    if(($_SESSION["user"]) == "" || $_SESSION['usertype'] != 'it'){
        header("location: ../login.php");
    } else {
        $useremail = $_SESSION["user"];
    }
} else {
    header("location: ../login.php");
}
include("../connection.php");

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["userId"]) && isset($_POST["newPassword"])) {
    $userId = $_POST["userId"];
    $newPassword = $_POST["newPassword"];

    // Hash the new password
    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

    // Update the password in the database
    $stmt = $database->prepare("UPDATE patient SET ppassword = ? WHERE pid = ?");
    $stmt->bind_param("si", $hashedPassword, $userId);

    if ($stmt->execute()) {
        echo "<script>alert('Password updated successfully'); window.location.href='clients.php';</script>";
    } else {
        echo "<script>alert('Password update failed'); window.location.href='clients.php';</script>";
    }

    $stmt->close();
}
?>
