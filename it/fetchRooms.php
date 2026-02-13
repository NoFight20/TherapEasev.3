<?php
include("../connection.php");

$department = $_GET['department'] ?? '';

if (!$department) {
    echo json_encode([]);
    exit;
}

try {
    $rooms = $database->getReference("rooms/{$department}")->getValue();
    echo json_encode($rooms ?? []);
} catch (Exception $e) {
    echo json_encode([]);
}
?>
