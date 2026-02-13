<?php
session_name('sess_admin'); session_start();

// Check if the user is logged in and has the correct user type
if (isset($_SESSION["user"])) {
    if ($_SESSION["user"] == "" || ($_SESSION['usertype'] ?? '') != 'a') {
        header("location: ../login.php");
        exit;
    }
} else {
    header("location: ../login.php");
    exit;
}

// Include Firebase configuration
include("../connection.php");

/* =========================
   Canonical helpers (same style as schedule.php)
   ========================= */
function canonDate(string $d): string {
    $d = trim($d);
    $dt = DateTime::createFromFormat('Y-m-d', $d) ?: new DateTime($d);
    return $dt->format('Y-m-d');
}
function canonTime(string $t): string {
    $t = trim($t);
    $dt = DateTime::createFromFormat('H:i:s', $t); if ($dt) return $dt->format('H:i');
    $dt = DateTime::createFromFormat('H:i',   $t); if ($dt) return $dt->format('H:i');
    $dt = DateTime::createFromFormat('g:i A', strtoupper($t)); if ($dt) return $dt->format('H:i');
    $dt = new DateTime($t); return $dt->format('H:i');
}
function rangesOverlap(string $startA, string $endA, string $startB, string $endB): bool {
    $a1 = strtotime($startA);
    $a2 = strtotime($endA);
    $b1 = strtotime($startB);
    $b2 = strtotime($endB);
    // overlap if A starts before B ends AND B starts before A ends
    return ($a1 < $b2) && ($b1 < $a2);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $title      = trim($_POST["title"] ?? '');
    $docid      = trim($_POST["docid"] ?? '');
    $dateRaw    = trim($_POST["date"] ?? '');

    // NEW inputs
    $startRaw   = trim($_POST["start_time"] ?? '');
    $endRaw     = trim($_POST["end_time"] ?? '');

    // Basic validation
    if ($title === '' || $docid === '' || $dateRaw === '' || $startRaw === '' || $endRaw === '') {
        echo "Missing required fields.";
        exit;
    }

    $date       = canonDate($dateRaw);
    $start_time = canonTime($startRaw);
    $end_time   = canonTime($endRaw);

    // Extra safety: end must be after start
    if (strtotime($end_time) <= strtotime($start_time)) {
        header("location: schedule.php?action=error&message=duplicate-session");
        exit;
    }

    try {
        // Check if the doctor already has an overlapping session on same date
        $doctorSessions = $database->getReference("doctor/$docid/sessions")->getValue() ?? [];
        $duplicate = false;

        if (!empty($doctorSessions) && is_array($doctorSessions)) {
            foreach ($doctorSessions as $session) {
                $sessDate = canonDate((string)($session['scheduledate'] ?? $session['date'] ?? ''));
                if ($sessDate !== $date) continue;

                // Existing session may be old (single time) or new (start/end)
                $sessStart = $session['start_time'] ?? $session['scheduletime'] ?? $session['time'] ?? '';
                $sessEnd   = $session['end_time']   ?? $session['scheduletime'] ?? $session['time'] ?? '';

                if ($sessStart === '' || $sessEnd === '') continue;

                $sessStart = canonTime((string)$sessStart);
                $sessEnd   = canonTime((string)$sessEnd);

                // If old data has same start=end, treat as a 30-min block for overlap purposes
                if (strtotime($sessEnd) <= strtotime($sessStart)) {
                    $sessEnd = date('H:i', strtotime($sessStart . ' +30 minutes'));
                }

                if (rangesOverlap($start_time, $end_time, $sessStart, $sessEnd)) {
                    $duplicate = true;
                    break;
                }
            }
        }

        if ($duplicate) {
            header("location: schedule.php?action=error&message=duplicate-session");
            exit;
        }

        // Prepare data to be inserted for the schedule (NEW fields)
        $scheduleData = [
            'docid'         => $docid,
            'title'         => $title,
            'scheduledate'  => $date,
            'start_time'    => $start_time,
            'end_time'      => $end_time,

            // legacy compatibility (optional, helps old screens)
            'scheduletime'  => $start_time
        ];

        // Add schedule to the database
        $newScheduleRef = $database->getReference("schedule")->push($scheduleData);
        $scheduleId = $newScheduleRef->getKey();

        // Insert the new session into the doctor's sessions (mirror fields)
        $doctorSessionData = [
            'title'         => $title,
            'scheduledate'  => $date,
            'start_time'    => $start_time,
            'end_time'      => $end_time,
            'scheduleid'    => $scheduleId,

            // legacy compatibility
            'scheduletime'  => $start_time
        ];

        $doctorPath = "doctor/$docid/sessions";
        $database->getReference($doctorPath)->push($doctorSessionData);

        // Redirect after adding the session
        header("location: schedule.php?action=session-added&title=" . urlencode($title));
        exit;

    } catch (Exception $e) {
        echo "Error adding schedule: " . htmlspecialchars($e->getMessage());
        exit;
    }
}
?>
