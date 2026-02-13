<?php

include("../connection.php");

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['department'], $data['room'], $data['bed'])) {
    echo json_encode(["success" => false, "error" => "Invalid input data"]);
    exit;
}

$department = $data['department'];
$room = $data['room'];
$bedNumber = intval($data['bed']); // Convert bed number to an integer

try {
    $roomPath = "rooms/$department/$room";
    $roomData = $database->getReference($roomPath)->getValue();

    if (!isset($roomData['beds']) || !is_array($roomData['beds'])) {
        echo json_encode(["success" => false, "error" => "No beds found in the specified room."]);
        exit;
    }

    // Filter out the bed to be removed
    $updatedBeds = array_filter($roomData['beds'], function ($bed) use ($bedNumber) {
        return $bed['bed_number'] !== $bedNumber;
    });

    // Update the Firebase database
    $database->getReference("$roomPath/beds")->set(array_values($updatedBeds));

    echo json_encode(["success" => true, "message" => "Bed removed successfully."]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}