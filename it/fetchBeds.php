<?php
include("../connection.php");

$department = $_GET['department'] ?? '';
$room = $_GET['room'] ?? '';

if (!$department || !$room) {
    echo json_encode([]);
    exit;
}

try {
    $beds = $database->getReference("rooms/{$department}/{$room}/beds")->getValue();
    echo json_encode(array_values($beds ?? []));
} catch (Exception $e) {
    echo json_encode([]);
}
?>
