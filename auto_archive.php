<?php

// Include Firebase connection (adjust the path as needed)
include("connection.php");

// Set timezone (adjust if necessary)
date_default_timezone_set('Asia/Manila');

// Get today's date in YYYY-MM-DD format
$today = date('Y-m-d');

// -------- Archive Schedule Entries --------
$schedules = $database->getReference('schedule')->getValue();

if ($schedules) {
    foreach ($schedules as $scheduleId => $schedule) {
        // Check if scheduledate is set and is before today
        if (!empty($schedule['scheduledate']) && $schedule['scheduledate'] < $today) {
            // Prepare archive data and add an archived timestamp
            $archiveData = $schedule;
            $archiveData['archived_at'] = date('Y-m-d H:i:s');

            // Push to archive_schedule node
            $database->getReference('archive_schedule')->push($archiveData);

            // Remove the schedule from the original node
            $database->getReference('schedule/' . $scheduleId)->remove();
        }
    }
}

// -------- Archive Appointment Entries --------
$appointments = $database->getReference('appointment')->getValue();

if ($appointments) {
    foreach ($appointments as $appointmentId => $appointment) {
        // Check if appodate is set and is before today
        if (!empty($appointment['appodate']) && $appointment['appodate'] < $today) {
            // Prepare archive data and add an archived timestamp
            $archiveData = $appointment;
            $archiveData['archived_at'] = date('Y-m-d H:i:s');

            // Push to archive_appointment node
            $database->getReference('archive_appointment')->push($archiveData);

            // Remove the appointment from the original node
            $database->getReference('appointment/' . $appointmentId)->remove();
        }
    }
}

// -------- Archive Queue Entries (nested by department) --------
date_default_timezone_set('Asia/Manila');

$queues = $database->getReference('queue')->getValue();

if (!empty($queues) && is_array($queues)) {
    $updates = []; // multi-location atomic update

    foreach ($queues as $department => $items) {
        // Skip anything that isn't an array of items
        if (!is_array($items)) {
            continue;
        }

        foreach ($items as $pushId => $item) {
            // If item is not an array, skip
            if (!is_array($item)) {
                continue;
            }

            // Only archive those explicitly marked "Done"
            $status = isset($item['queue_status']) ? (string)$item['queue_status'] : '';
            if ($status !== 'Done') {
                continue;
            }

            // Prepare archive payload
            $archiveData = $item;
            $archiveData['archived_at'] = date('Y-m-d H:i:s');
            // Ensure department/type are preserved/consistent
            $archiveData['department'] = $department;
            if (empty($archiveData['type'])) {
                $archiveData['type'] = $department;
            }

            // (Optional) normalize name if you sometimes store fname/lname
            if (empty($archiveData['name'])) {
                $fname = $archiveData['fname'] ?? '';
                $lname = $archiveData['lname'] ?? '';
                $full  = trim($fname . ' ' . $lname);
                if ($full !== '') {
                    $archiveData['name'] = $full;
                }
            }

            // Build atomic multi-location update:
            // - Set archive node
            // - Delete original node
            $updates["/archive_queue/{$department}/{$pushId}"] = $archiveData;
            $updates["/queue/{$department}/{$pushId}"] = null;
        }
    }

    if (!empty($updates)) {
        // Single atomic write to avoid partial moves
        $database->getReference()->update($updates);
    }
}
?>
