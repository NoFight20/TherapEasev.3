<?php
// Import database
include("../connection.php");

header('Content-Type: application/json'); // Ensure JSON response

if (isset($_GET['id'])) {
    $patientId = $_GET['id'];
    $patient = $database->getReference('queue/' . $patientId)->getValue();

    if ($patient) {
        echo json_encode($patient);
    } else {
        echo json_encode(['error' => 'Patient not found']);
    }
} else {
    echo json_encode(['error' => 'Invalid request']);
}

// <!-- View Details Button -->
//                             <button class="btn-action btn-view" title="View Details" 
//                                 onclick="viewPatient(\'' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '\')" 
//                                 style="background-color: #f0f0f0; border: 1px solid #ccc; border-radius: 6px; padding: 5px; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center;">
//                                 <i class="fas fa-info-circle" style="font-size: 16px; color: #000;"></i>
//                             </button>
?>

