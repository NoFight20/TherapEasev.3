<?php
require '../vendor/autoload.php'; 
include('../connection.php');

header('Content-Type: application/json');

if (isset($_GET['queue_type'])) {
    $queueType = $_GET['queue_type'];

    // Fetch data from the specific queue type node.
    $queueData = $database->getReference('queue/' . $queueType)->getValue();
    $hasOngoing = false;

    if (!empty($queueData)) {
        foreach ($queueData as $id => $patient) {
            if (!isset($patient['queue_status']) || !isset($patient['type'])) {
                continue;
            }
            if ($patient['queue_status'] === 'In Progress') {
                $hasOngoing = true;
                break;
            }
        } 
    }

    echo json_encode(['hasOngoing' => $hasOngoing]);
} else {
    echo json_encode(['error' => 'Invalid request']);
}
?>
