<?php
session_name('sess_admin'); session_start();

include("../connection.php"); 

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Retrieve POST data using dedicated fields for patient id and patient name.
    $patientName = isset($_POST['patient_name']) ? trim($_POST['patient_name']) : '';
    $newQueueType = isset($_POST['new_queue_type']) ? trim($_POST['new_queue_type']) : '';
    $transferPurposes = isset($_POST['transfer_purpose']) ? $_POST['transfer_purpose'] : [];
    $transferPurposeString = implode(', ', $transferPurposes);
    
    // Build an array of missing fields.
    $missing_fields = [];
    if (empty($patientName)) {
        $missing_fields[] = "Patient Name";
    }
    if (empty($newQueueType)) {
        $missing_fields[] = "New Queue Type";
    }
    
    // If any required data is missing, display an error with all missing fields.
    if (!empty($missing_fields)) {
        $errorMsg = "Missing required data: " . implode(", ", $missing_fields);
        echo "<script>
                alert('{$errorMsg}');
                window.history.back();
              </script>";
        exit();
    }
    
    // Define a list of departments exactly matching your Firebase 'queue' node keys.
    $departments = [
        "Consultation",
        "Laboratory",
        "Industrial Clinic",
        "Radiology",
        "Cardio Pulmonology",
        "Physical Therapy",
        "Obstetrics and Gynecology",
        "General Surgeon",
        "Pediatrics",
        "ENT",
        "Opthalmologist",
        "Dentist",
        "Rehabilitation Medicine Specialist",
        "Urologist",
        "Family Medicine",
        "Internal Medicine",
        "HMO",         
        "Cashier"      
    ];
    
    $oldDepartment = null;
    $oldPatientKey = null;
    $oldPatientData = null;
    
    // Search for the patient record in all departments by matching the patient name 
    foreach ($departments as $dept) {
        $ref = $database->getReference('queue/' . $dept);
        $data = $ref->getValue();
        if ($data) {
            foreach ($data as $key => $record) {
                if (isset($record['name']) && strtolower(trim($record['name'])) === strtolower($patientName)) {
                    $oldDepartment = $dept;
                    $oldPatientKey = $key;
                    $oldPatientData = $record;
                    break 2; // Stop searching once the record is found.
                }
            }
        }
    }
    
    if (!$oldDepartment || !$oldPatientData) {
        echo "<script>alert('Patient record not found in any department.'); window.history.back();</script>";
        exit();
    }
    
    // Retrieve the old token data from the original record.
    $oldToken = isset($oldPatientData['token_number']) ? $oldPatientData['token_number'] : null;
    $oldFormattedToken = isset($oldPatientData['formatted_token']) ? $oldPatientData['formatted_token'] : null;
    
    if ($oldToken === null || $oldFormattedToken === null) {
        echo "<script>alert('Original token data not found.'); window.history.back();</script>";
        exit();
    }
    
    // Set the purpose based on transfer purposes, but override for HMO and Cashier.
    $purpose = $transferPurposeString ? $transferPurposeString : (isset($oldPatientData['purpose']) ? $oldPatientData['purpose'] : '');
    if ($newQueueType === "HMO" || $newQueueType === "Cashier") {
        $purpose = "payment";
    }
    
    // Prepare new patient data for the new department.
    // The transferred patient retains the original token values.
    $newPatientData = [
        'token_number'      => $oldToken,
        'formatted_token'   => $oldFormattedToken,
        'name'              => $patientName,
        'reg_number'        => isset($oldPatientData['reg_number']) ? $oldPatientData['reg_number'] : uniqid(),
        'date'              => date('Y-m-d H:i'),
        'type'              => $newQueueType,
        'queue_status'      => 'Waiting',
        'purpose'           => $purpose
    ];
    
    // Prepare a log entry for the transfer event.
    $useremail = isset($_SESSION["user"]) ? $_SESSION["user"] : 'unknown';
    $logData = [
        'timestamp'         => date('Y-m-d H:i:s'),
        'status'            => 'Transferred',
        'user'              => $newPatientData['name'],
        'from_queue'        => $oldDepartment,
        'to_queue'          => $newQueueType,
        'formatted_token'   => $oldFormattedToken,
        'email'             => $useremail
    ];
    
    // Insert the new patient record into the new department and log the transfer.
    try {
        $database->getReference('queue/' . $newQueueType)->push($newPatientData);
        $database->getReference('logs')->push($logData);
        echo "<script>alert('Patient transferred successfully!'); window.location.href='queing.php';</script>";
        exit();
    } catch (Exception $e) {
        echo "<script>alert('Failed to transfer patient: " . $e->getMessage() . "'); window.history.back();</script>";
        exit();
    }
    
} else {
    header("Location: queing.php");
    exit();
}
?>
