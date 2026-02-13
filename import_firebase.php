<?php
// Database connection settings for phpMyAdmin (XAMPP)
$host = "localhost";  // Default XAMPP MySQL host
$username = "root";   // Default XAMPP MySQL user
$password = "";       // Default password is empty
$database = "firebase"; // Change this to your database name

// Create MySQL connection
$conn = new mysqli($host, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Path to the Firebase JSON file
$jsonFile = 'C:/xampp/htdocs/edoc77/database.json'; // Update with correct path

// Read JSON file
$jsonData = file_get_contents($jsonFile);
$dataArray = json_decode($jsonData, true); // Convert JSON to PHP array

// Check if JSON is valid
if ($dataArray === null) {
    die("Error decoding JSON data.");
}

// Function to create tables dynamically
function createTable($conn, $tableName, $sampleData) {
    $columns = [];
    foreach ($sampleData as $key => $value) {
        if (is_int($value)) {
            $columns[] = "`$key` INT";
        } elseif (is_float($value)) {
            $columns[] = "`$key` FLOAT";
        } elseif (is_array($value)) {
            continue; // Skip arrays for now
        } else {
            $columns[] = "`$key` TEXT";
        }
    }
    if (!empty($columns)) {
        $columns[] = "PRIMARY KEY (`id`)";
        $sql = "CREATE TABLE IF NOT EXISTS `$tableName` (`id` VARCHAR(255) NOT NULL, " . implode(", ", $columns) . ")";
        $conn->query($sql);
    }
}

// Function to insert data dynamically
function insertData($conn, $tableName, $data) {
    foreach ($data as $id => $row) {
        if (!is_array($row)) continue;
        $columns = [];
        $values = [];
        foreach ($row as $key => $value) {
            if (is_array($value)) continue; // Skip nested arrays
            $columns[] = "`$key`";
            $values[] = "'" . $conn->real_escape_string($value) . "'";
        }
        if (!empty($columns)) {
            $columns = implode(", ", $columns);
            $values = implode(", ", $values);
            $sql = "INSERT INTO `$tableName` (`id`, $columns) VALUES ('$id', $values) ON DUPLICATE KEY UPDATE id='$id'";
            $conn->query($sql);
        }
    }
}

// Loop through each collection in Firebase JSON
foreach ($dataArray as $collection => $records) {
    if (!is_array($records)) continue;
    $firstRecord = reset($records);
    if (is_array($firstRecord)) {
        createTable($conn, $collection, $firstRecord);
        insertData($conn, $collection, $records);
    }
}

// Close connection
$conn->close();

echo "Firebase data inserted into MySQL successfully!";
?>
