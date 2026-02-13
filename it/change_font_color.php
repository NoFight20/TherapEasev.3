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

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["font_color"])) {
    $fontColor = $_POST["font_color"];

    try {
        $database->getReference('settings/fontColor')->set($fontColor);

        echo "<script>alert('Font color updated successfully!');</script>";
    } catch (Exception $e) {
        echo "<script>alert('Failed to update font color: " . $e->getMessage() . "');</script>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Font Color</title>
    <link rel="stylesheet" href="../css/animations.css">
    <link rel="stylesheet" href="../css/main.css">
    <link rel="stylesheet" href="../css/admin.css">
    <style>
        .form-container {
            width: 100%;
            max-width: 400px;
            margin: 0 auto;
            text-align: center;
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0px 0px 10px rgba(0, 0, 0, 0.1);
        }

        .form-container h2 {
            margin-bottom: 20px;
        }

        .form-container input[type="color"] {
            margin: 10px 0;
            width: 100%;
            height: 40px;
            border: none;
        }

        .form-container button {
            margin-top: 20px;
            padding: 10px 20px;
            background-color: #4CAF50;
            color: #fff;
            border: none;
            cursor: pointer;
            border-radius: 4px;
        }
    </style>
</head>

<body>
    <div class="form-container">
        <h2>Change Font Color</h2>
        <form action="change_font_color.php" method="POST">
            <label for="font_color">Select Font Color:</label>
            <input type="color" name="font_color" id="font_color" required>
            <button type="submit">Save Color</button>
        </form>
    </div>
</body>
</html>
