<?php
include("../connection.php");

$database = $factory->createDatabase();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Decode the incoming JSON data
    $data = json_decode(file_get_contents('php://input'), true);

    if ($data) {
        // Write the data to Firebase
        $newReference = $database->getReference('ocr_results')->push($data);
        // Store in 'patients'
    $database->getReference('patients')->push($data);

        // Respond with success
        echo json_encode([
            'status' => 'success',
            'message' => 'Data stored successfully!',
            'data' => $data
        ]);
    } else {
        // Respond with an error
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid data received.'
        ]);
    }
} else {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid request method.'
    ]);
}
?>
