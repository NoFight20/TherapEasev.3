<?php
require '../vendor/autoload.php'; // Firebase SDK
include('../connection.php');

$data = json_decode(file_get_contents("php://input"), true);

if (isset($data['specialties']) && is_array($data['specialties'])) {
    try {
        foreach ($data['specialties'] as $specialtyId) {
            $database->getReference('specialties/' . $specialtyId)->remove();
        }
        echo "Selected specialties deleted successfully!";
    } catch (Exception $e) {
        echo "Error deleting specialties.";
    }
} else {
    echo "Invalid request.";
}
?>
