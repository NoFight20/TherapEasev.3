<?php
session_name('sess_patient'); session_start();

// Check if the user is logged in and of type 'p' (patient)
if (!isset($_SESSION["user"]) || empty($_SESSION["user"]) || $_SESSION['usertype'] != 'p') {
    header("location: ../login.php");
    exit;
}

$useremail = $_SESSION["user"];

// Include Firebase configuration
include("../connection.php");

// Set up logs reference
$logsRef = $database->getReference('logs');
$timestamp = date('H:i:s');
$datestamp = date('Y-m-d');

// Fetch patient details
$userRef = $database->getReference('patients')->orderByChild('email')->equalTo($useremail);
$userData = $userRef->getValue();

if (!empty($userData)) {
    $userfetch = array_values($userData)[0]; // Get the first matched record
    $username = $userfetch['fname'] ?? '';
    $lastname = $userfetch['lname'] ?? '';
    $fullName = trim($username . ' ' . $lastname);

    // Check if the form was submitted
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST["booknow"])) {
        // Retrieve POST data
        $apponum = htmlspecialchars($_POST["apponum"] ?? ''); // Sanitize input
        $scheduleId = htmlspecialchars($_POST["scheduleid"] ?? ''); // Sanitize input
        $bookingDate = htmlspecialchars($_POST["date"] ?? ''); // Sanitize input
        $doctorName = htmlspecialchars($_POST["doctorname"] ?? 'Unknown'); // Get the doctor name
        $doctorEmail = htmlspecialchars($_POST["doctoremail"] ?? 'Unknown'); // Get the doctor email

        // Fetch schedule details
        $scheduleRef = $database->getReference("schedule/{$scheduleId}");
        $scheduleData = $scheduleRef->getValue();

        if (!empty($scheduleData)) {
            // Check if the user already booked this session
            $appointmentsRef = $database->getReference('appointment')->orderByChild('scheduleid')->equalTo($scheduleId)->getValue();
            $alreadyBooked = false;

            if (!empty($appointmentsRef)) {
                foreach ($appointmentsRef as $appointment) {
                    if ($appointment['email'] === $useremail) {
                        $alreadyBooked = true;
                        break;
                    }
                }
            }

            if ($alreadyBooked) {
                // Log booking error
                $logsRef->push([
                    'email' => $useremail,
                    'action' => 'Booking Attempt',
                    'schedule_id' => $scheduleId,
                    'status' => 'Failed: Already booked',
                    'timestamp' => $timestamp,
                    'datestamp' => $datestamp
                ]);

                // Set a session variable to store the message
                $_SESSION['booking_error'] = "You have already booked this session.";
                header("location: schedule.php");
                exit;
            }

            $totalPatients = !empty($appointmentsRef) ? count($appointmentsRef) : 0;

            // Increment the patient count
            $totalPatients++;

            $newAppointment = [
                'name' => $fullName,
                'email' => $useremail,
                'apponum' => $apponum,
                'title' => $scheduleData['title'],
                'appodate' => $bookingDate,
                'doctorid' => $scheduleData['doctorid'] ?? null, // Include doctor ID from schedule
                'doctorname' => $doctorName, // Use doctor name from form
                'doctoremail' => $doctorEmail, // Use doctor email from form
                'scheduledate' => $scheduleData['scheduledate'],
                'scheduletime' => $scheduleData['scheduletime'],
                'scheduleid' => $scheduleId,
                'totalPatients' => $totalPatients,
                'status' => 'pending' // Add status as 'pending'
            ];
            
            // Save appointment to Firebase
            $database->getReference('appointment')->push($newAppointment);

            // Log successful booking
            $logsRef->push([
                'email' => $useremail,
                'action' => 'Booking',
                'schedule_id' => $scheduleId,
                'status' => 'Booking Success',
                'appointment_number' => $apponum,
                'timestamp' => $timestamp,
                'datestamp' => $datestamp
            ]);

            // Update the total patient count in the schedule node
            $database->getReference("schedule/{$scheduleId}/totalPatients")->set($totalPatients);

            // Redirect after booking
            header("location: appointment.php?action=booking-added&id=" . $apponum);
            exit;
        } else {
            // Log schedule not found error
            $logsRef->push([
                'email' => $useremail,
                'action' => 'Retrieve Schedule',
                'schedule_id' => $scheduleId,
                'status' => 'Failed: Schedule not found',
                'timestamp' => $timestamp,
                'datestamp' => $datestamp
            ]);

            echo "Schedule not found.";
        }
    }
} else {
    // Log user not found error
    $logsRef->push([
        'email' => $useremail,
        'action' => 'Retrieve User Details',
        'status' => 'Failed: User not found',
        'timestamp' => $timestamp,
        'datestamp' => $datestamp
    ]);

    echo "User not found.";
}
?>
