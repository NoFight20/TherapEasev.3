<?php
header('Content-Type: application/json');
ini_set('display_errors', 1);
error_reporting(E_ALL);

include('../connection.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents("php://input"), true);
    $bedNumber = $input['bedNumber'] ?? '';
    $roomNumber = $input['roomNumber'] ?? '';
    $category = $input['category'] ?? '';

    try {
        $roomsRef = $database->getReference("rooms/$category");
        $roomsSnapshot = $roomsRef->getValue();

        if (!$roomsSnapshot) {
            echo json_encode(['success' => false, 'message' => "No rooms found in '$category'."]);
            exit();
        }

        $bedUpdated = false;

        foreach ($roomsSnapshot as $roomId => $roomData) {
            if (!isset($roomData['room_number']) || intval($roomData['room_number']) !== intval($roomNumber)) {
                continue;
            }

            if (!isset($roomData['beds']) || !is_array($roomData['beds'])) {
                continue;
            }

            foreach ($roomData['beds'] as $index => $bed) {
                if ($bedNumber === '' || intval($bed['bed_number']) === intval($bedNumber)) {
                    // Update the bed to mark it available
                    $database->getReference("rooms/$category/$roomId/beds/$index")->update([
                        'status' => 'available',
                        'patientName' => null,
                        'date_admitted' => null
                    ]);

                    // Log the action
                    $database->getReference('logs')->push([
                        'email' => $_SESSION['user'] ?? 'system',
                        'status' => "Discharged patient from Bed {$bed['bed_number']} in Room {$roomData['room_number']} ($category).",
                        'timestamp' => date('H:i:s'),
                        'datestamp' => date('Y-m-d')
                    ]);

                    $bedUpdated = true;
                    break;
                }
            }

            if ($bedUpdated) {
                break;
            }
        }

        echo json_encode([
            'success' => $bedUpdated,
            'message' => $bedUpdated
                ? "Patient successfully discharged."
                : "No matching bed found. Check room/bed numbers."
        ]);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
}
?>
