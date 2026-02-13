<?php
session_name('sess_admin'); session_start();

if (isset($_SESSION["user"])) {
    if (($_SESSION["user"]) == "" || ($_SESSION['usertype'] ?? '') != 'a') {
        header("location: ../login.php"); exit;
    }
} else {
    header("location: ../login.php"); exit;
}

include("../connection.php");

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // ✅ NOW USING id INSTEAD OF title
    $id = isset($_GET['id']) ? trim((string)$_GET['id']) : '';

    if ($id === '') {
        http_response_code(400);
        echo "Missing required parameter: id";
        exit;
    }

    try {
        // Get the specific schedule by its key
        $schedSnap = $database->getReference("schedule/{$id}")->getSnapshot();

        if (!$schedSnap->exists()) {
            echo "No schedule found with the given id: " . h($id);
            exit;
        }

        $sched       = $schedSnap->getValue();
        $schedTitle  = isset($sched['title']) ? (string)$sched['title'] : '';
        $docid       = $sched['docid'] ?? null;

        $deletedScheduleCount   = 0;
        $deletedDoctorSessions  = 0;
        $deletedAppointments    = 0;

        // ✅ Remove this schedule node
        $database->getReference("schedule/{$id}")->remove();
        $deletedScheduleCount++;

        // ✅ If docid exists, remove matching doctor sessions by same title
        if ($docid) {
            $doctorSessions = $database->getReference("doctor/{$docid}/sessions")->getValue();
            if ($doctorSessions && is_array($doctorSessions)) {
                foreach ($doctorSessions as $sessionKey => $session) {
                    $sessionTitle = $session['title'] ?? '';
                    if ($sessionTitle === $schedTitle) {
                        $database->getReference("doctor/{$docid}/sessions/{$sessionKey}")->remove();
                        $deletedDoctorSessions++;
                    }
                }
            }
        }

        // ✅ Remove appointments that have the same title
        $appointments = $database->getReference("appointment")->getValue();
        if ($appointments && is_array($appointments)) {
            foreach ($appointments as $appointmentKey => $appointment) {
                $apptTitle = $appointment['title'] ?? '';
                if ($apptTitle === $schedTitle) {
                    $database->getReference("appointment/{$appointmentKey}")->remove();
                    $deletedAppointments++;
                }
            }
        }

        // ✅ Redirect back to schedule list on success
        header("location: schedule.php");
        exit;

    } catch (Exception $e) {
        http_response_code(500);
        echo "Error deleting schedule: " . h($e->getMessage());
        exit;
    }
}
?>
