<?php
include("../connection.php");

$key = $_GET['key'] ?? null;

function is_assoc_array(array $arr): bool {
    return array_keys($arr) !== range(0, count($arr) - 1);
}

function mapWithId($node) {
    $out = [];
    if (!is_array($node)) return $out;

    // If medications/illnesses/... are keyed by push IDs, this is "assoc"
    if (is_assoc_array($node)) {
        foreach ($node as $k => $v) {
            if (is_array($v)) $v['_id'] = $k;   // keep Firebase child key
            $out[] = $v;
        }
    } else {
        // Plain list; no keys to preserve
        foreach ($node as $v) {
            if (is_array($v)) $v['_id'] = '';
            $out[] = $v;
        }
    }
    return $out;
}

if ($key) {
    try {
        $ref  = $database->getReference("patients/{$key}/medical_record");
        $data = $ref->getValue();

        if ($data) {
            $data['medications']         = isset($data['medications'])         ? mapWithId($data['medications'])         : [];
            $data['surgical_procedures'] = isset($data['surgical_procedures']) ? mapWithId($data['surgical_procedures']) : [];
            $data['illnesses']           = isset($data['illnesses'])           ? mapWithId($data['illnesses'])           : [];
            $data['vaccinations']        = isset($data['vaccinations'])        ? mapWithId($data['vaccinations'])        : [];
            echo json_encode($data);
        } else {
            echo json_encode(null);
        }
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
} else {
    echo json_encode(['error' => 'Invalid key']);
}
