<?php
require '../vendor/autoload.php'; 
include('../connection.php');

header('Content-Type: application/json');

if (isset($_GET['id'], $_GET['status'], $_GET['queue_type'])) {
    $patientId = $_GET['id'];
    $newStatus = $_GET['status'];
    $queueType = trim($_GET['queue_type']); 

    // Attempt to retrieve the patient record from the provided department.
    $refPath = "queue/{$queueType}/{$patientId}";
    $patientData = $database->getReference($refPath)->getValue();

    // If the record is not found, search across all departments.
    if (!$patientData) {
        $patientsData = $database->getReference('queue')->getValue();
        $found = false;
        if ($patientsData) {
            foreach ($patientsData as $dept => $patients) {
                // Use case-insensitive comparison for department names.
                if (isset($patients[$patientId])) {
                    $queueType = trim($dept); // update queueType to the department where found
                    $refPath = "queue/{$queueType}/{$patientId}";
                    $patientData = $patients[$patientId];
                    $found = true;
                    break;
                }
            }
        }
        if (!$found) {
            echo json_encode([
                'success' => false,
                'message' => 'Patient record not found in any department.',
                'debug'   => [
                    'patientId' => $patientId,
                    'queueTypeProvided' => $_GET['queue_type'],
                    'availableDepartments' => array_keys($patientsData ?? [])
                ]
            ]);
            exit;
        }
    }

    $patientName = isset($patientData['name']) ? $patientData['name'] : "Unknown";

    // Check for another patient in "In Progress" within the same department.
    $deptData = $database->getReference("queue/{$queueType}")->getValue();
    $hasOngoing = false;
    if ($deptData !== null) {
        foreach ($deptData as $id => $patient) {
            if ($id !== $patientId && isset($patient['queue_status']) && $patient['queue_status'] === 'In Progress') {
                $hasOngoing = true;
                break;
            }
        }
    }

    // Prevent switching to "In Progress" if another patient is already in progress.
    if ($hasOngoing && $newStatus === 'In Progress') {
        echo json_encode([
            'success' => false,
            'message' => "Another patient is already in progress for {$queueType}. Complete that one first."
        ]);
        exit;
    }

    // Only allow marking "Done" if the current status is "In Progress".
    if ($newStatus === 'Done' && (!isset($patientData['queue_status']) || $patientData['queue_status'] !== 'In Progress')) {
        echo json_encode([
            'success' => false,
            'message' => 'Only patients in progress can be marked as done.'
        ]);
        exit;
    }

    // Update the patient's queue status.
    try {
        $database->getReference($refPath)->update(['queue_status' => $newStatus]);

        // Log the activity.
        $logEntry = [
            'timestamp'    => date('Y-m-d H:i:s'),
            'action'       => 'Queue Status Updated',
            'patient_id'   => $patientId,
            'patient_name' => $patientName,
            'queue_type'   => $queueType,
            'new_status'   => $newStatus
        ];
        $database->getReference('logs')->push($logEntry);

        echo json_encode([
            'success' => true,
            'message' => 'Patient status updated successfully.',
            'refPath' => $refPath
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to update queue status. ' . $e->getMessage()
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request.'
    ]);
}
?>
