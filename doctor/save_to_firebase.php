<?php
header('Access-Control-Allow-Origin: *'); 
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

require '../vendor/autoload.php'; // Firebase SDK
include('../connection.php');

use PhpOffice\PhpWord\IOFactory;

// Debugging: Log raw POST data
$rawData = file_get_contents('php://input');
file_put_contents('debug_log.txt', "Raw Data:\n" . print_r($rawData, true) . "\n\n", FILE_APPEND);

// Decode incoming JSON
$data = json_decode($rawData, true);

function extractData($text) {
    $fields = [
        'lname' => '/LAST NAME\s*:\s*([A-Za-z\s]+?)(?=\s*FIRST NAME|$)/i',
        'fname' => '/FIRST NAME\s*:\s*([A-Za-z\s]+?)(?=\s*MIDDLE NAME|$)/i',
        'mname' => '/MIDDLE NAME\s*:\s*([A-Za-z\s]+?)(?=\s*AGE|$)/i',
        'age' => '/AGE\s*:\s*(\d{1,3})(?=\s*SEX|$)/i',
        'gender' => '/\b(?:SEX|Gender)\s*:\s*(Male|Female|Other)\b/i',
        'dob' => '/BIRTHDAY\s*:\s*(\d{2}\/\d{2}\/\d{4})(?=\s*CONTACT NUMBER|$)/i',
        'email' => '/EMAIL\s*:\s*([\w.%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})(?=\s*DOCTOR\'?S NAME|$)/i',
        'tele' => '/(?:CONTACT NUMBER|Phone Number)\s*:\s*(\d{10,11})(?=\s*CITY|$)/i',
        'civil_status' => '/(?:CIVIL STATUS|Marital Status)\s*:\s*([A-Za-z\s]+?)(?=\s*BIRTHDAY|$)/i',
        'barangay' => '/BARANGAY\s*:\s*([A-Za-z\s]+?)(?=\s*PROVINCE|$)/i',
        'city' => '/CITY\s*:\s*([A-Za-z\s]+?)(?=\s*PROVINCE|$)/i',
        'province' => '/PROVINCE\s*:\s*([A-Za-z\s]+?)(?=\s*EMAIL|$)/i',
        'doctorName' => '/DOCTOR\'?S NAME\s*:\s*([A-Za-z\s]+)/i'
    ];

    $extractedData = [];
    foreach ($fields as $key => $pattern) {
        if (preg_match($pattern, $text, $matches)) {
            $extractedData[$key] = trim($matches[1]);
        }
    }

    // Ensure additional required fields
    $extractedData['type'] = 'p'; // Default patient type
    $extractedData['name'] = isset($extractedData['fname']) && isset($extractedData['lname']) 
        ? trim($extractedData['fname'] . ' ' . $extractedData['lname']) 
        : '';

    // Convert DOB to YYYY-MM-DD format if it exists
    if (isset($extractedData['dob']) && preg_match('/(\d{2})\/(\d{2})\/(\d{4})/', $extractedData['dob'], $dobMatch)) {
        $extractedData['dob'] = "{$dobMatch[3]}-{$dobMatch[1]}-{$dobMatch[2]}";
    }

    return $extractedData;
}

if (isset($data['extractedText']) && !empty($data['extractedText'])) {
    $extractedText = trim($data['extractedText']);
    $parsedData = extractData($extractedText);
    
    if (!empty($parsedData)) {
        // Generate a unique key to store in both nodes
        $uniqueKey = $database->getReference('ocr_results')->push()->getKey();
        
        // Store the extracted data under the generated key
        $database->getReference('ocr_results/' . $uniqueKey)->set($parsedData);
        $database->getReference('patients/' . $uniqueKey)->set($parsedData);

        file_put_contents('debug_log.txt', "Stored in Firebase with ID: $uniqueKey - Data: " . json_encode($parsedData) . "\n\n", FILE_APPEND);

        echo json_encode([
            'success' => true,
            'message' => 'Data saved to Firebase.',
            'id' => $uniqueKey,
            'data' => $parsedData
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Could not extract structured data.']);
    }
} else if (!empty($_FILES['docxFile'])) {
    // Handle DOCX file upload
    $uploadedFile = $_FILES['docxFile']['tmp_name'];

    if (file_exists($uploadedFile)) {
        $phpWord = IOFactory::load($uploadedFile);
        $text = '';

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if (method_exists($element, 'getText')) {
                    $text .= $element->getText() . " ";
                }
            }
        }

        // Extract structured data
        $parsedData = extractData(trim($text));

        if (!empty($parsedData)) {
            // Generate a unique key to store in both nodes
            $uniqueKey = $database->getReference('ocr_results')->push()->getKey();
            
            // Store the extracted data under the generated key
            $database->getReference('ocr_results/' . $uniqueKey)->set($parsedData);
            $database->getReference('patients/' . $uniqueKey)->set($parsedData);

            echo json_encode([
                'success' => true,
                'message' => 'DOCX processed and saved',
                'id' => $uniqueKey,
                'data' => $parsedData
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to extract structured data from DOCX.']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to read DOCX file.']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'No extracted text received.']);
}
?>
