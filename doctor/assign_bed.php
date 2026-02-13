<?php
session_name('sess_doctor'); session_start();

include('../connection.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo "<script>alert('Direct access is not allowed!'); window.location.href = 'bed.php';</script>";
    exit;
}

$timestamp = date('H:i:s'); 
$datestamp = date('Y-m-d'); 
$useremail = $_SESSION['user'] ?? 'system';

// Get POST data
$patientName = $_POST['patientName'] ?? '';
$bedNumber = $_POST['bedNumber'] ?? '';
$roomNumber = $_POST['roomNumber'] ?? '';
$category = $_POST['category'] ?? ''; 

// Validate required fields
if (!$patientName || !$bedNumber || !$roomNumber || !$category) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
    exit;
}

try {
    $roomsRef = $database->getReference("rooms/$category");
    $roomsSnapshot = $roomsRef->getValue();

    if (!$roomsSnapshot) {
        echo json_encode(['success' => false, 'message' => "Category '$category' not found."]);
        exit;
    }

    $bedAssigned = false;

    foreach ($roomsSnapshot as $roomId => $roomData) {
        if (isset($roomData['room_number']) && strval($roomData['room_number']) === strval($roomNumber)) {
            if (isset($roomData['beds']) && is_array($roomData['beds'])) {
                foreach ($roomData['beds'] as $index => $bed) {
                    if (isset($bed['bed_number']) && strval($bed['bed_number']) === strval($bedNumber) && $bed['status'] === 'available') {
                        $database->getReference("rooms/$category/$roomId/beds/$index")->update([
                            'status' => 'occupied',
                            'patientName' => $patientName,
                            'date_admitted' => $datestamp,
                        ]);

                        $database->getReference('logs')->push([
                            'email' => $useremail,
                            'status' => "Assigned patient '$patientName' to Bed $bedNumber in Room $roomNumber ($category).",
                            'timestamp' => $timestamp,
                            'datestamp' => $datestamp
                        ]);

                        echo "<script>alert('Patient assigned to Bed $bedNumber in Room $roomNumber ($category).'); window.location.href = 'bed.php';</script>";
                        exit;
                    }
                }
            }
            elseif (!isset($roomData['beds']) || empty($roomData['beds'])) {
                $database->getReference("rooms/$category/$roomId")->update([
                    'beds' => [[
                        'bed_number' => 1,
                        'status' => 'occupied',
                        'patientName' => $patientName,
                        'date_admitted' => $datestamp,
                    ]]
                ]);

                $database->getReference('logs')->push([
                    'email' => $useremail,
                    'status' => "Assigned patient '$patientName' to Single-Bed Room $roomNumber ($category).",
                    'timestamp' => $timestamp,
                    'datestamp' => $datestamp
                ]);

                echo "<script>alert('Patient assigned to Single-Bed Room $roomNumber ($category).'); window.location.href = 'bed.php';</script>";
                exit;
            }
        }
    }

    echo "<script>alert('Bed $bedNumber in Room $roomNumber not found or already occupied.'); window.location.href = 'bed.php';</script>";
} catch (Exception $e) {
    echo "<script>alert('An error occurred: " . addslashes($e->getMessage()) . "'); window.location.href = 'bed.php';</script>";
}
?>
