<?php

// Include Firebase connection (adjust the path as needed)
include("connection.php");

// Set timezone to Manila
date_default_timezone_set('Asia/Manila');

// Define the expiration period as one year
$expirationPeriod = '+1 year';

// List of archive nodes to process
$archiveNodes = [
    'archive',
    'archive_doctor',
    'archive_queue',
    'archive_schedule',
    'archive_users'
];

foreach ($archiveNodes as $nodeName) {
    // Get a reference to the current archive node
    $nodeRef = $database->getReference($nodeName);
    $records = $nodeRef->getValue();

    if ($records) {
        foreach ($records as $key => $record) {
            // Check if archived_at field exists
            if (isset($record['archived_at'])) {
                // Convert archived_at date to a timestamp
                $archivedAtTimestamp = strtotime($record['archived_at']);
                // Calculate expiration timestamp based on the defined expiration period
                $expirationTimestamp = strtotime($expirationPeriod, $archivedAtTimestamp);

                // If current time is past the expiration timestamp, remove the record
                if (time() > $expirationTimestamp) {
                    $nodeRef->getChild($key)->remove();
                    echo "Removed record with key: $key from $nodeName\n";
                }
            }
        }
    } else {
        echo "No records found in $nodeName.\n";
    }
}
?>
