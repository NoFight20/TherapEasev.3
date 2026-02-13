<?php
include("../connection.php");

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['department'], $data['room'])) {
    echo json_encode(["success" => false, "error" => "Invalid input data"]);
    exit;
}

$department = $data['department'];
$room = $data['room'];

try {
    $roomPath = "rooms/$department/$room";

    // Remove the room from Firebase
    $database->getReference($roomPath)->remove();

    echo json_encode(["success" => true, "message" => "Room removed successfully."]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
