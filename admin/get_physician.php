<?php
include('../connection.php');

// Get the specialty from the AJAX request
$specialty = $_GET['specialty'] ?? '';

if ($specialty) {
    $physiciansRef = $database->getReference('doctor');
    $snapshot = $physiciansRef->orderByChild('specialty')->equalTo($specialty)->getSnapshot();

    $physicians = [];
    if ($snapshot->exists()) {
        foreach ($snapshot->getValue() as $key => $value) {
            $physicians[] = [
                'name' => $value['name'],
                'tele' => $value['tele']
            ];
        }
    }

    header('Content-Type: application/json');
    echo json_encode($physicians);
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Specialty is required']);
}
?>
