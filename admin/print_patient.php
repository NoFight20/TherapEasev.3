<?php

// Import Firebase database connection
include("../connection.php");

if (isset($_GET['key'])) {
    $key = htmlspecialchars($_GET['key'], ENT_QUOTES, 'UTF-8');
    $patientRef = $database->getReference('patients/' . $key);
    $patient = $patientRef->getValue();

    try {
        // Fetch the patient data using the key
        $patientRef = $database->getReference('patients/' . $key);
        $patient = $patientRef->getValue();

        if ($patient) {
            // Extract patient details
            $fname = $patient['fname'] ?? '';
            $lname = $patient['lname'] ?? '';
            $name = $patient['name'] ?? trim($fname . ' ' . $lname);
            $email = $patient['email'] ?? 'N/A';
            $age = $patient['age'] ?? 'N/A';
            $gender = $patient['gender'] ?? 'N/A';
            $dob = $patient['dob'] ?? 'N/A';
            $tele = $patient['tele'] ?? 'N/A';
            $civil = $patient['civil_status'] ?? 'N/A';
            $barangay = $patient['barangay'] ?? '';
            $city = $patient['city'] ?? 'N/A';
            $province = $patient['province'] ?? 'N/A';

            // Build the address
            $addressParts = array_filter([$barangay, $city, $province]);
            $address = implode(', ', $addressParts);

            // Display the printable patient details
            echo '
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Print Patient Details</title>
                <style>
                    body {
                        font-family: Arial, sans-serif;
                        margin: 20px;
                    }
                    .container {
                        width: 80%;
                        margin: auto;
                        border: 1px solid #ccc;
                        padding: 20px;
                        box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
                    }
                    h1 {
                        text-align: center;
                        margin-bottom: 20px;
                    }
                    table {
                        width: 100%;
                        border-collapse: collapse;
                        margin-bottom: 20px;
                    }
                    th, td {
                        border: 1px solid #ddd;
                        padding: 8px;
                    }
                    th {
                        text-align: left;
                        background-color: #f4f4f4;
                    }
                    .print-btn {
                        display: block;
                        width: 100px;
                        margin: 20px auto;
                        padding: 10px;
                        background-color: #28a745;
                        color: white;
                        text-align: center;
                        border: none;
                        border-radius: 5px;
                        cursor: pointer;
                    }
                    .print-btn:hover {
                        background-color: #218838;
                    }
                    .back-btn {
                        display: block;
                        width: 80px;
                        margin: 10px auto;
                        padding: 9px;
                        background-color: #007bff;
                        color: white;
                        text-align: center;
                        border: none;
                        border-radius: 5px;
                        text-decoration: none;
                    }
                    .back-btn:hover {
                        background-color: #0056b3;
                    }
.medical-history-form {
    width: 100%;
    max-width: 900px;
    margin: 20px auto;
    padding: 20px;
    border: 1px solid #ddd;
    border-radius: 8px;
    background: #fdfdfd;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    overflow-y: auto;
}

/* Form Headings */
.medical-history-form h3, 
.medical-history-form h4 {
    font-family: "Roboto", sans-serif;
    font-weight: bold;
    color: #4CAF50;
    text-align: center;
    margin-bottom: 20px;
}


.medical-history-form input[type="text"], 
.medical-history-form input[type="date"] {
    width: 98%; 
    padding: 10px;
    margin: 5px 0;
    border: 1px solid #ccc;
    border-radius: 4px;
    font-size: 14px;
    display: block; 
}


.medical-history-form table {
    width: 100%;
    border-collapse: collapse;
    margin: 20px 0;
    font-size: 14px;
}

.medical-history-form table th, 
.medical-history-form table td {
    border: 1px solid #ddd;
    padding: 10px;
    text-align: center;
}

.medical-history-form table th {
    background-color: #4CAF50;
    color: white;
    text-align: center;
}

.medical-history-form table td input {
    width: 97%; /* Input fields inside table cells are longer */
    padding: 8px;
    margin: 5px 0;
    border: 1px solid #ccc;
    border-radius: 4px;
    font-size: 14px;
    text-align: left;
}


.medical-history-form button {
    display: block;
    width: 50%; 
    margin: 15px auto;
    padding: 8px 20px; 
    font-size: 14px; 
    color: white;
    background-color: #4CAF50;
    border: none;
    border-radius: 6px; 
    cursor: pointer;
    font-weight: bold;
    transition: background-color 0.3s ease;
    text-align: center;
}

.medical-history-form button:hover {
    background-color: #45a049;
}


.medical-history-form input[type="submit"] {
    display: block;
    width: 60%; 
    margin: 20px auto;
    padding: 10px 20px;
    font-size: 16px;
    color: white;
    background-color: #4CAF50;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: bold;
    transition: background-color 0.3s ease;
}

.medical-history-form input[type="submit"]:hover {
    background-color: #45a049;
}
                </style>
            </head>
            <body>
               <div id="popup1" class="overlay">
                    <div class="popup">
                        <a class="close" href="#">&times;</a>
                        <div class="content">
                            <!-- Medical History Form -->
                            <div class="medical-history-form">
                                <h3 style="text-align: center;">Medical History Form</h3>
                                <form action="save_medical_history.php" method="POST">
                                    <!-- General Information -->
                                    <div>
                                        <label for="patient_name">Patient Name:</label>
                                        <input type="text" id="patient_name" name="patient_name"  required>
                                        
                                        <label for="last_update">Date of Last Update:</label>
                                        <input type="date" id="last_update" name="last_update"  required>
                                    </div>
                                    <div>
                                        <label for="age">Age:</label>
                                        <input type="text" id="age" name="age"  readonly>   
                                        
                                        <label for="gender">Gender:</label>
                                        <input type="text" id="gender" name="gender"  readonly>
                                    </div>
                                    <div>
                                        <label for="dob">Date of Birth:</label>
                                        <input type="date" id="dob" name="dob"  readonly>
                                        
                                        <label for="civil_status">Civil Status:</label>
                                        <input type="text" id="civil_status" name="civil_status" readonly>
                                    </div>
                                    <div>
                                        <label  for="email">Email:</label>
                                        <input type="text" id="email" name="email" required>
                                        
                                        <label for="tele">Phone:</label>
                                        <input type="text" id="tele" name="tele" readonly>
                                    </div>
                                    <div>
                                        <label for="barangay">Barangay:</label>
                                        <input type="text" id="barangay" name="barangay"  readonly>
                                        
                                        <label for="city">City:</label> 
                                        <input type="text" id="city" name="city" readonly>
                                    </div>
                                    <div>
                                        <label for="province">Province:</label>
                                        <input type="text" id="province" name="province" readonly>
                                    </div>

                                    <div>
                                        <label for="physician_name">Current Physician Name:</label>
                                        <input type="text" id="physician_name" name="physician_name" required>
                                        <label for="physician_phone">Physician\'s Contact: </label>
                                        <input type="text" id="physician_phone" name="physician_phone">
                                    </div>
                                    <div>
                                        <label for="pharmacy_name">Current Pharmacy Name:</label>
                                        <input type="text" id="pharmacy_name" name="pharmacy_name">
                                        <label for="pharmacy_phone">Pharmacy\'s Contact:</label>
                                        <input type="text" id="pharmacy_phone" name="pharmacy_phone">
                                    </div>

                                    <!-- Current and Past Medications -->
                                    <h4>Current and Past Medications</h4>
                                    <table border="1" style="width: 100%;">
                                        <thead>
                                            <tr>
                                                <th>Medication Name</th>
                                                <th>Dosage</th>
                                                <th>Frequency</th>
                                                <th>Physician</th>
                                                <th>Start Date</th>
                                                <th>End Date</th>
                                                <th>Purpose</th>
                                            </tr>
                                        </thead>
                                        <tbody id="medication-rows">
                                            <tr>
                                                <td><input type="text" name="medication_name[]" required></td>
                                                <td><input type="text" name="dosage[]" required></td>
                                                <td><input type="text" name="frequency[]"></td>
                                                <td><input type="text" name="med_physician[]"></td>
                                                <td><input type="date" name="med_start_date[]"></td>
                                                <td><input type="date" name="med_end_date[]"></td>
                                                <td><input type="text" name="med_purpose[]"></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    

                                    <!-- Surgical Procedures -->
                                    <h4>Surgical Procedures</h4>
                                    <table border="1" style="width: 100%;">
                                        <thead>
                                            <tr>
                                                <th>Procedure</th>
                                                <th>Physician</th>
                                                <th>Hospital</th>
                                                <th>Date</th>
                                                <th>Notes</th>
                                            </tr>
                                        </thead>
                                        <tbody id="surgical-rows">
                                            <tr>
                                                <td><input type="text" name="procedure[]"></td>
                                                <td><input type="text" name="surg_physician[]"></td>
                                                <td><input type="text" name="hospital[]"></td>
                                                <td><input type="date" name="surg_date[]"></td>
                                                <td><input type="text" name="surg_notes[]"></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                  
                                    <!-- Major Illnesses -->
                                    <h4>Major Illnesses</h4>
                                    <table border="1" style="width: 100%;">
                                        <thead>
                                            <tr>
                                                <th>Illness</th>
                                                <th>Start Date</th>
                                                <th>End Date</th>
                                                <th>Physician</th>
                                                <th>Treatment Notes</th>
                                            </tr>
                                        </thead>
                                        <tbody id="illness-rows">
                                            <tr>
                                                <td><input type="text" name="illness[]"></td>
                                                <td><input type="date" name="ill_start_date[]"></td>
                                                <td><input type="date" name="ill_end_date[]"></td>
                                                <td><input type="text" name="ill_physician[]"></td>
                                                <td><input type="text" name="treatment_notes[]"></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                  

                                    <!-- Vaccinations -->
                                    <h4>Vaccinations</h4>
                                    <table border="1" style="width: 100%;">
                                        <thead>
                                            <tr>
                                                <th>Name</th>
                                                <th>Date</th>
                                            </tr>
                                        </thead>
                                        <tbody id="vaccine-rows">
                                            <tr>
                                                <td><input type="text" name="vaccine_name[]"></td>
                                                <td><input type="date" name="vaccine_date[]"></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    

                                    <br><br>
                                </form>
                            </div>
                        </div>
                    </div>
                     <button class="print-btn" onclick="window.print()">Print</button>
                    <a href="patient.php" class="back-btn">Back</a>
                </div>
            </body>
            </html>';
        } else {
            echo '<p>Patient not found.</p>';
        }
    } catch (Exception $e) {
        echo '<p>Error retrieving patient data: ' . htmlspecialchars($e->getMessage()) . '</p>';
    }
} else {
    echo '<p>Invalid request. No patient key provided.</p>';
}

?>
<script>
            function openModal(button) {
           // Get data from the clicked button
        const name = button.getAttribute('data-name');
        const gender = button.getAttribute('data-gender');
        const tel = button.getAttribute('data-tel');
        const email = button.getAttribute('data-email');
        const dob = button.getAttribute('data-dob');
        const age = button.getAttribute('data-age');
        const barangay = button.getAttribute('data-barangay');
        const city = button.getAttribute('data-city');
        const province = button.getAttribute('data-province');
        const civilStatus = button.getAttribute('data-civil-status');
        
            // Populate the modal fields
            document.getElementById('patient_name').value = name;
        document.getElementById('gender').value = gender;
        document.getElementById('tele').value = tel;
        document.getElementById('email').value = email;
        document.getElementById('dob').value = dob;
        document.getElementById('age').value = age;
        document.getElementById('barangay').value = barangay;
        document.getElementById('city').value = city;
        document.getElementById('province').value = province;
        document.getElementById('civil_status').value = civilStatus;
        
            // Display the modal
            document.getElementById('medicalHistoryModal').style.display = 'block';
        }
        
        </script>