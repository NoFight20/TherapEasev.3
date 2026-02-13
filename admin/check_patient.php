<?php
header('Content-Type: application/json');
include("../connection.php");

// Decode incoming JSON payload
$input = json_decode(file_get_contents('php://input'), true);
$patientName = $input['patientName'] ?? '';

if (!$patientName) {
    echo json_encode(['error' => 'Patient name is required']);
    exit;
}

try {
    // Fetch patients from Firebase
    $patientsRef = $database->getReference('patients');
    $snapshot = $patientsRef->orderByChild('name')->equalTo($patientName)->getSnapshot();

    // Debugging: Log the request and response
    error_log("Searching for patient name: $patientName");

    if ($snapshot->hasChildren()) {
        $patients = $snapshot->getValue();
        $firstKey = key($patients);
        $patientData = $patients[$firstKey];
        echo json_encode(['patientId' => $firstKey, 'name' => $patientData['name']]);
    } else {
        echo json_encode(['error' => 'No patient found']);
    }
} catch (Exception $e) {
    // Log error and return
    error_log('Error: ' . $e->getMessage());
    echo json_encode(['error' => 'Failed to fetch patient data']);
}
?>
