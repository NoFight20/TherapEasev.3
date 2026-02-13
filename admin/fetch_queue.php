<?php
// Include Firebase connection
include("../connection.php");

try {
    // Get the entire "queue" node which now contains subnodes for each department.
    $queueData = $database->getReference('queue')->getValue();

    // Existing departments
    $priorityLane       = isset($queueData['priorityLane']) ? $queueData['priorityLane'] : [];
    $laboratory         = isset($queueData['Laboratory']) ? $queueData['Laboratory'] : [];
    $consultation       = isset($queueData['Consultation']) ? $queueData['Consultation'] : [];
    $industrialClinic   = isset($queueData['Industrial Clinic']) ? $queueData['Industrial Clinic'] : [];
    $radiology          = isset($queueData['Radiology']) ? $queueData['Radiology'] : [];
    $cardioPulmonology  = isset($queueData['Cardio Pulmonology']) ? $queueData['Cardio Pulmonology'] : [];
    $physicalTherapy    = isset($queueData['Physical Therapy']) ? $queueData['Physical Therapy'] : [];

    // Additional specialties
    $obgyn              = isset($queueData['Obstetrics and Gynecology']) ? $queueData['Obstetrics and Gynecology'] : [];
    $generalSurgeon     = isset($queueData['General Surgeon']) ? $queueData['General Surgeon'] : [];
    $pediatrics         = isset($queueData['Pediatrics']) ? $queueData['Pediatrics'] : [];
    $ent                = isset($queueData['Ear, Nose, Throat (ENT)']) ? $queueData['Ear, Nose, Throat (ENT)'] : [];
    $opthalmologist     = isset($queueData['Opthalmologist']) ? $queueData['Opthalmologist'] : [];
    $dentist            = isset($queueData['Dentist']) ? $queueData['Dentist'] : [];
    $rehabSpecialist    = isset($queueData['Rehabilitation Medicine Specialist']) ? $queueData['Rehabilitation Medicine Specialist'] : [];
    $urologist          = isset($queueData['Urologist']) ? $queueData['Urologist'] : [];
    $familyMedicine     = isset($queueData['Family Medicine']) ? $queueData['Family Medicine'] : [];
    $internalMedicine   = isset($queueData['Internal Medicine']) ? $queueData['Internal Medicine'] : [];

    // New departments to add
    $bloodBank          = isset($queueData['Blood Bank']) ? $queueData['Blood Bank'] : [];
    $molecularPathology = isset($queueData['Molecular Pathology Laboratory']) ? $queueData['Molecular Pathology Laboratory'] : [];
    $clinicalLab        = isset($queueData['Clinical Laboratory']) ? $queueData['Clinical Laboratory'] : [];
    $philhealth         = isset($queueData['Philhealth']) ? $queueData['Philhealth'] : [];

    // Sorting function: sort a given queue (array) based on token_number.
    function sortQueue(&$queue) {
        if (!empty($queue)) {
            usort($queue, function($a, $b) {
                return $a['token_number'] <=> $b['token_number'];
            });
        }
    }

    // Apply sorting to each department array
    sortQueue($priorityLane);
    sortQueue($laboratory);
    sortQueue($consultation);
    sortQueue($industrialClinic);
    sortQueue($radiology);
    sortQueue($cardioPulmonology);
    sortQueue($physicalTherapy);
    sortQueue($obgyn);
    sortQueue($generalSurgeon);
    sortQueue($pediatrics);
    sortQueue($ent);
    sortQueue($opthalmologist);
    sortQueue($dentist);
    sortQueue($rehabSpecialist);
    sortQueue($urologist);
    sortQueue($familyMedicine);
    sortQueue($internalMedicine);
    sortQueue($bloodBank);
    sortQueue($molecularPathology);
    sortQueue($clinicalLab);
    sortQueue($philhealth);

    // Function to determine the current "Now Serving" and "Next" patient in a given queue.
    function getQueueStatus($queue) {
        $nowServing = null;
        $nextPatient = null;
        if (!empty($queue)) {
            foreach ($queue as $q) {
                if (isset($q['queue_status'])) {
                    if ($q['queue_status'] === 'In Progress' && !$nowServing) {
                        $nowServing = $q;
                    } elseif ($q['queue_status'] === 'Waiting' && !$nextPatient) {
                        $nextPatient = $q;
                    }
                }
            }
        }
        return [$nowServing, $nextPatient];
    }

    // New helper function: scans the entire queue for a non-"Normal" priority,
    // but ignores any patient that is currently "In Progress".
    // It returns the patient's formatted token number (or token_number) for the first patient found.
    function getQueueNonNormalToken($queue) {
        if (!empty($queue)) {
            foreach ($queue as $q) {
                if (isset($q['priority']) && $q['priority'] !== 'Normal') {
                    // Skip if the patient is in progress
                    if (isset($q['queue_status']) && $q['queue_status'] === 'In Progress') {
                        continue;
                    }
                    return isset($q['formatted_token']) ? $q['formatted_token'] : $q['token_number'];
                }
            }
        }
        return null;
    }

    // Get current serving and next patients for each department.
    [$priorityNow, $priorityNext]         = getQueueStatus($priorityLane);
    [$labNow, $labNext]                     = getQueueStatus($laboratory);
    [$consultNow, $consultNext]             = getQueueStatus($consultation);
    [$industrialNow, $industrialNext]       = getQueueStatus($industrialClinic);
    [$radiologyNow, $radiologyNext]         = getQueueStatus($radiology);
    [$cardioNow, $cardioNext]               = getQueueStatus($cardioPulmonology);
    [$therapyNow, $therapyNext]             = getQueueStatus($physicalTherapy);
    [$obgynNow, $obgynNext]                 = getQueueStatus($obgyn);
    [$generalSurgeonNow, $generalSurgeonNext] = getQueueStatus($generalSurgeon);
    [$pediatricsNow, $pediatricsNext]       = getQueueStatus($pediatrics);
    [$entNow, $entNext]                     = getQueueStatus($ent);
    [$opthalmologistNow, $opthalmologistNext] = getQueueStatus($opthalmologist);
    [$dentistNow, $dentistNext]             = getQueueStatus($dentist);
    [$rehabSpecialistNow, $rehabSpecialistNext] = getQueueStatus($rehabSpecialist);
    [$urologistNow, $urologistNext]         = getQueueStatus($urologist);
    [$familyMedicineNow, $familyMedicineNext] = getQueueStatus($familyMedicine);
    [$internalMedicineNow, $internalMedicineNext] = getQueueStatus($internalMedicine);
    [$bloodBankNow, $bloodBankNext]         = getQueueStatus($bloodBank);
    [$molecularPathologyNow, $molecularPathologyNext] = getQueueStatus($molecularPathology);
    [$clinicalLabNow, $clinicalLabNext]     = getQueueStatus($clinicalLab);
    [$philhealthNow, $philhealthNext]       = getQueueStatus($philhealth);

    // Return JSON response with token information and the non-"Normal" token (from a patient with non-normal priority)
    echo json_encode([
        // Priority Lane (if used)
        'priorityNow'         => $priorityNow ? (isset($priorityNow['formatted_token']) ? $priorityNow['formatted_token'] : $priorityNow['token_number']) : null,
        'priorityNext'        => $priorityNext ? (isset($priorityNext['formatted_token']) ? $priorityNext['formatted_token'] : $priorityNext['token_number']) : null,

        // Laboratory
        'labNow'              => $labNow ? (isset($labNow['formatted_token']) ? $labNow['formatted_token'] : $labNow['token_number']) : null,
        'labNext'             => $labNext ? (isset($labNext['formatted_token']) ? $labNext['formatted_token'] : $labNext['token_number']) : null,
        'labPriorityNow'      => getQueueNonNormalToken($laboratory),
        'labPriorityNext'     => getQueueNonNormalToken($laboratory),

        // Consultation
        'consultNow'          => $consultNow ? (isset($consultNow['formatted_token']) ? $consultNow['formatted_token'] : $consultNow['token_number']) : null,
        'consultNext'         => $consultNext ? (isset($consultNext['formatted_token']) ? $consultNext['formatted_token'] : $consultNext['token_number']) : null,
        'consultPriorityNow'  => getQueueNonNormalToken($consultation),
        'consultPriorityNext' => getQueueNonNormalToken($consultation),

        // Industrial Clinic
        'industrialNow'       => $industrialNow ? (isset($industrialNow['formatted_token']) ? $industrialNow['formatted_token'] : $industrialNow['token_number']) : null,
        'industrialNext'      => $industrialNext ? (isset($industrialNext['formatted_token']) ? $industrialNext['formatted_token'] : $industrialNext['token_number']) : null,
        'industrialPriorityNow' => getQueueNonNormalToken($industrialClinic),
        'industrialPriorityNext' => getQueueNonNormalToken($industrialClinic),

        // Radiology
        'radiologyNow'        => $radiologyNow ? (isset($radiologyNow['formatted_token']) ? $radiologyNow['formatted_token'] : $radiologyNow['token_number']) : null,
        'radiologyNext'       => $radiologyNext ? (isset($radiologyNext['formatted_token']) ? $radiologyNext['formatted_token'] : $radiologyNext['token_number']) : null,
        'radiologyPriorityNow'=> getQueueNonNormalToken($radiology),
        'radiologyPriorityNext'=> getQueueNonNormalToken($radiology),

        // Cardio Pulmonology
        'cardioNow'           => $cardioNow ? (isset($cardioNow['formatted_token']) ? $cardioNow['formatted_token'] : $cardioNow['token_number']) : null,
        'cardioNext'          => $cardioNext ? (isset($cardioNext['formatted_token']) ? $cardioNext['formatted_token'] : $cardioNext['token_number']) : null,
        'cardioPriorityNow'   => getQueueNonNormalToken($cardioPulmonology),
        'cardioPriorityNext'  => getQueueNonNormalToken($cardioPulmonology),

        // Physical Therapy
        'therapyNow'          => $therapyNow ? (isset($therapyNow['formatted_token']) ? $therapyNow['formatted_token'] : $therapyNow['token_number']) : null,
        'therapyNext'         => $therapyNext ? (isset($therapyNext['formatted_token']) ? $therapyNext['formatted_token'] : $therapyNext['token_number']) : null,
        'therapyPriorityNow'  => getQueueNonNormalToken($physicalTherapy),
        'therapyPriorityNext' => getQueueNonNormalToken($physicalTherapy),

        // Rehabilitation Medicine Specialist
        'rehabSpecialistNow'  => $rehabSpecialistNow ? (isset($rehabSpecialistNow['formatted_token']) ? $rehabSpecialistNow['formatted_token'] : $rehabSpecialistNow['token_number']) : null,
        'rehabSpecialistNext' => $rehabSpecialistNext ? (isset($rehabSpecialistNext['formatted_token']) ? $rehabSpecialistNext['formatted_token'] : $rehabSpecialistNext['token_number']) : null,
        'rehabSpecialistPriorityNow' => getQueueNonNormalToken($rehabSpecialist),
        'rehabSpecialistPriorityNext' => getQueueNonNormalToken($rehabSpecialist),

        // Obstetrics and Gynecology
        'obgynNow'            => $obgynNow ? (isset($obgynNow['formatted_token']) ? $obgynNow['formatted_token'] : $obgynNow['token_number']) : null,
        'obgynNext'           => $obgynNext ? (isset($obgynNext['formatted_token']) ? $obgynNext['formatted_token'] : $obgynNext['token_number']) : null,
        'obgynPriorityNow'    => getQueueNonNormalToken($obgyn),
        'obgynPriorityNext'   => getQueueNonNormalToken($obgyn),

        // General Surgeon
        'generalSurgeonNow'   => $generalSurgeonNow ? (isset($generalSurgeonNow['formatted_token']) ? $generalSurgeonNow['formatted_token'] : $generalSurgeonNow['token_number']) : null,
        'generalSurgeonNext'  => $generalSurgeonNext ? (isset($generalSurgeonNext['formatted_token']) ? $generalSurgeonNext['formatted_token'] : $generalSurgeonNext['token_number']) : null,
        'generalSurgeonPriorityNow' => getQueueNonNormalToken($generalSurgeon),
        'generalSurgeonPriorityNext' => getQueueNonNormalToken($generalSurgeon),

        // Pediatrics
        'pediatricsNow'       => $pediatricsNow ? (isset($pediatricsNow['formatted_token']) ? $pediatricsNow['formatted_token'] : $pediatricsNow['token_number']) : null,
        'pediatricsNext'      => $pediatricsNext ? (isset($pediatricsNext['formatted_token']) ? $pediatricsNext['formatted_token'] : $pediatricsNext['token_number']) : null,
        'pediatricsPriorityNow' => getQueueNonNormalToken($pediatrics),
        'pediatricsPriorityNext' => getQueueNonNormalToken($pediatrics),

        // ENT
        'entNow'              => $entNow ? (isset($entNow['formatted_token']) ? $entNow['formatted_token'] : $entNow['token_number']) : null,
        'entNext'             => $entNext ? (isset($entNext['formatted_token']) ? $entNext['formatted_token'] : $entNext['token_number']) : null,
        'entPriorityNow'      => getQueueNonNormalToken($ent),
        'entPriorityNext'     => getQueueNonNormalToken($ent),

        // Opthalmologist
        'opthalmologistNow'   => $opthalmologistNow ? (isset($opthalmologistNow['formatted_token']) ? $opthalmologistNow['formatted_token'] : $opthalmologistNow['token_number']) : null,
        'opthalmologistNext'  => $opthalmologistNext ? (isset($opthalmologistNext['formatted_token']) ? $opthalmologistNext['formatted_token'] : $opthalmologistNext['token_number']) : null,
        'opthalmologistPriorityNow' => getQueueNonNormalToken($opthalmologist),
        'opthalmologistPriorityNext' => getQueueNonNormalToken($opthalmologist),

        // Dentist
        'dentistNow'          => $dentistNow ? (isset($dentistNow['formatted_token']) ? $dentistNow['formatted_token'] : $dentistNow['token_number']) : null,
        'dentistNext'         => $dentistNext ? (isset($dentistNext['formatted_token']) ? $dentistNext['formatted_token'] : $dentistNext['token_number']) : null,
        'dentistPriorityNow'  => getQueueNonNormalToken($dentist),
        'dentistPriorityNext' => getQueueNonNormalToken($dentist),

        // Urologist
        'urologistNow'        => $urologistNow ? (isset($urologistNow['formatted_token']) ? $urologistNow['formatted_token'] : $urologistNow['token_number']) : null,
        'urologistNext'       => $urologistNext ? (isset($urologistNext['formatted_token']) ? $urologistNext['formatted_token'] : $urologistNext['token_number']) : null,
        'urologistPriorityNow' => getQueueNonNormalToken($urologist),
        'urologistPriorityNext' => getQueueNonNormalToken($urologist),

        // Family Medicine
        'familyMedicineNow'   => $familyMedicineNow ? (isset($familyMedicineNow['formatted_token']) ? $familyMedicineNow['formatted_token'] : $familyMedicineNow['token_number']) : null,
        'familyMedicineNext'  => $familyMedicineNext ? (isset($familyMedicineNext['formatted_token']) ? $familyMedicineNext['formatted_token'] : $familyMedicineNext['token_number']) : null,
        'familyMedicinePriorityNow' => getQueueNonNormalToken($familyMedicine),
        'familyMedicinePriorityNext' => getQueueNonNormalToken($familyMedicine),

        // Internal Medicine
        'internalMedicineNow' => $internalMedicineNow ? (isset($internalMedicineNow['formatted_token']) ? $internalMedicineNow['formatted_token'] : $internalMedicineNow['token_number']) : null,
        'internalMedicineNext'=> $internalMedicineNext ? (isset($internalMedicineNext['formatted_token']) ? $internalMedicineNext['formatted_token'] : $internalMedicineNext['token_number']) : null,
        'internalMedicinePriorityNow' => getQueueNonNormalToken($internalMedicine),
        'internalMedicinePriorityNext'=> getQueueNonNormalToken($internalMedicine),

        // New Departments:
        // Blood Bank
        'bloodBankNow'        => $bloodBankNow ? (isset($bloodBankNow['formatted_token']) ? $bloodBankNow['formatted_token'] : $bloodBankNow['token_number']) : null,
        'bloodBankNext'       => $bloodBankNext ? (isset($bloodBankNext['formatted_token']) ? $bloodBankNext['formatted_token'] : $bloodBankNext['token_number']) : null,
        'bloodBankPriorityNow'=> getQueueNonNormalToken($bloodBank),
        'bloodBankPriorityNext'=> getQueueNonNormalToken($bloodBank),

        // Molecular Pathology Laboratory
        'molecularPathologyNow' => $molecularPathologyNow ? (isset($molecularPathologyNow['formatted_token']) ? $molecularPathologyNow['formatted_token'] : $molecularPathologyNow['token_number']) : null,
        'molecularPathologyNext'=> $molecularPathologyNext ? (isset($molecularPathologyNext['formatted_token']) ? $molecularPathologyNext['formatted_token'] : $molecularPathologyNext['token_number']) : null,
        'molecularPathologyPriorityNow'=> getQueueNonNormalToken($molecularPathology),
        'molecularPathologyPriorityNext'=> getQueueNonNormalToken($molecularPathology),

        // Clinical Laboratory
        'clinicalLabNow'      => $clinicalLabNow ? (isset($clinicalLabNow['formatted_token']) ? $clinicalLabNow['formatted_token'] : $clinicalLabNow['token_number']) : null,
        'clinicalLabNext'     => $clinicalLabNext ? (isset($clinicalLabNext['formatted_token']) ? $clinicalLabNext['formatted_token'] : $clinicalLabNext['token_number']) : null,
        'clinicalLabPriorityNow'=> getQueueNonNormalToken($clinicalLab),
        'clinicalLabPriorityNext'=> getQueueNonNormalToken($clinicalLab),

        // Philhealth
        'philhealthNow'       => $philhealthNow ? (isset($philhealthNow['formatted_token']) ? $philhealthNow['formatted_token'] : $philhealthNow['token_number']) : null,
        'philhealthNext'      => $philhealthNext ? (isset($philhealthNext['formatted_token']) ? $philhealthNext['formatted_token'] : $philhealthNext['token_number']) : null,
        'philhealthPriorityNow'=> getQueueNonNormalToken($philhealth),
        'philhealthPriorityNext'=> getQueueNonNormalToken($philhealth),
    ]);
} catch (\Kreait\Firebase\Exception\DatabaseException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
