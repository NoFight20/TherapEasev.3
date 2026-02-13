<?php
require 'vendor/autoload.php'; // Ensure this path is correct

use Kreait\Firebase\Factory;

$serviceAccountPath = __DIR__ . '/therapease.json';

$databaseUri = 'https://therapease-d9525-default-rtdb.asia-southeast1.firebasedatabase.app/';

$factory = (new Factory)
    ->withServiceAccount($serviceAccountPath)
    ->withDatabaseUri($databaseUri);

// Create the services directly, since create() is not available
$database = $factory->createDatabase();
$auth = $factory->createAuth();
$storage = $factory->createStorage(); // Use this variable for Storage operations
?>



