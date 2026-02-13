<?php
session_name('sess_admin'); session_start();

if (!isset($_SESSION["user"]) || empty($_SESSION["user"])) {
    header("location: ../login.php");
    exit();
}

include("../connection.php");

// Ensure the session (i.e. the session title) is provided in the URL
if (!isset($_GET['session']) && $_SERVER['REQUEST_METHOD'] != 'POST') {
    echo "Error: No session specified.";
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    // Get the posted session title (hidden field)
    $sessionTitle = trim($_POST['session_title'] ?? '');
    $patientName  = trim($_POST['patient_name'] ?? '');
    $patientEmail = trim($_POST['patient_email'] ?? '');
    
    if (empty($sessionTitle) || empty($patientName) || empty($patientEmail)) {
        echo "<script>alert('All fields are required.'); window.history.back();</script>";
        exit();
    }
    
    // Lookup the schedule details for the session by title 
    $schedules = $database->getReference('schedule')->getValue() ?? [];
    $scheduleDetails = null;
    foreach ($schedules as $schedId => $sched) {
        if (isset($sched['title']) && strtolower($sched['title']) == strtolower($sessionTitle)) {
            $scheduleDetails = $sched;
            // Save the schedule ID if needed 
            $scheduleDetails['scheduleid'] = $schedId;
            break;
        }
    }
    
    if (!$scheduleDetails) {
        echo "<script>alert('Session not found.'); window.history.back();</script>";
        exit();
    }
    // Get all appointments for this session
    $existingAppointments = $database->getReference('appointment')
        ->orderByChild('title')
        ->equalTo($sessionTitle)
        ->getValue();
    $appointmentCount = $existingAppointments ? count($existingAppointments) : 0;
    $newAppointmentNumber = $appointmentCount + 1;
    
    // Set additional appointment fields using schedule details:
    $docid = $scheduleDetails['docid'] ?? '';
    // Optionally, look up the doctor details:
    $doctorData = $database->getReference('doctor/' . $docid)->getValue();
    $doctorName = $doctorData['name'] ?? 'Unknown';
    $doctorEmail = $doctorData['email'] ?? 'Unknown';
    
    // Set the appointment date to the current date (or you can use schedule date)
    $appoDate = date('Y-m-d');
    
    // Build the appointment data array. You can customize field names as needed.
    $newAppointment = [
        'title'         => $sessionTitle,
        'apponum'       => (string)$newAppointmentNumber,
        'docid'         => $docid,
        'doctorname'    => $doctorName,
        'doctoremail'   => $doctorEmail,
        'name'          => $patientName,
        'email'         => $patientEmail,
        'appodate'      => $appoDate,
        'scheduledate'  => $scheduleDetails['scheduledate'] ?? '',
        'scheduletime'  => $scheduleDetails['scheduletime'] ?? '',
        'status'        => 'pending',  
        'totalPatients' => 1  
    ];
    
    // Push the new appointment to Firebase under the 'appointment' node.
    try {
        $newAppRef = $database->getReference('appointment')->push($newAppointment);
        if ($newAppRef) {
            echo "<script>
                    alert('Patient added to queue successfully!');
                    window.location.href = 'schedule.php';
                  </script>";
            exit();
        } else {
            echo "<script>alert('Error adding patient to queue. Please try again.'); window.history.back();</script>";
            exit();
        }
    } catch (Exception $e) {
        echo "<script>alert('Error: " . $e->getMessage() . "'); window.history.back();</script>";
        exit();
    }
    
} else {
    // Get the session title from GET parameter.
    $sessionTitle = trim($_GET['session']);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Patient to Queue</title>
    <link rel="stylesheet" href="../css/main.css">
    <style>
        /* Basic styling for form */
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 20px;
        }
        .form-container {
            background: #fff;
            max-width: 500px;
            margin: 40px auto;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .form-container h2 {
            margin-top: 0;
            text-align: center;
            color: #333;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            font-weight: bold;
            margin-bottom: 5px;
            color: #555;
        }
        .form-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
        }
        .btn-submit {
            width: 100%;
            padding: 12px;
            background-color: #40916c;
            border: none;
            border-radius: 5px;
            color: #fff;
            font-size: 16px;
            cursor: pointer;
            margin-top: 10px;
        }
        .btn-submit:hover {
            background-color: #2d6a4f;
        }
    </style>
</head>
<body>
    <div class="form-container">
        <h2>Add Patient to Queue</h2>
        <form action="add-to-queue.php" method="POST">
            <input type="hidden" name="session_title" value="<?php echo htmlspecialchars($sessionTitle); ?>">
            <div class="form-group">
                <label for="patient_name">Patient Name:</label>
                <input type="text" id="patient_name" name="patient_name" placeholder="Enter patient's name" required>
            </div>
            <div class="form-group">
                <label for="patient_email">Patient Email:</label>
                <input type="email" id="patient_email" name="patient_email" placeholder="Enter patient's email" required>
            </div>
            <button type="submit" class="btn-submit">Add to Queue</button>
        </form>
    </div>
</body>
</html>
<?php
}
?>
