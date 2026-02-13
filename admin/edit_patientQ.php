<?php
// Import database
include("../connection.php");


if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $patientId = $_POST['id'];
    $name = $_POST['name'];
    $purpose = implode(', ', $_POST['purpose']); 
    $database->getReference('queue/' . $patientId)->update([
        'name' => $name,
        'purpose' => $purpose
    ]);

    echo "<script>alert('Patient details updated successfully!'); window.close();</script>";
} elseif (isset($_GET['id'])) {
    $patientId = $_GET['id'];
    $patient = $database->getReference('queue/' . $patientId)->getValue();
?>
    <h2>Edit Patient</h2>
    <form method="POST">
        <input type="hidden" name="id" value="<?php echo $patientId; ?>">
        <label>Name:</label>
        <input type="text" name="name" value="<?php echo htmlspecialchars($patient['name']); ?>" required><br>
        
        <label>Purpose:</label><br>
        <input type="checkbox" name="purpose[]" value="Urinalysis" <?php if (strpos($patient['purpose'], 'Urinalysis') !== false) echo 'checked'; ?>> Urinalysis<br>
        <input type="checkbox" name="purpose[]" value="Fecalysis" <?php if (strpos($patient['purpose'], 'Fecal Analysis') !== false) echo 'checked'; ?>> Fecal Analysis<br>
        <input type="checkbox" name="purpose[]" value="Blood Tests" <?php if (strpos($patient['purpose'], 'Blood Tests') !== false) echo 'checked'; ?>> Blood Tests<br>
        <input type="checkbox" name="purpose[]" value="Drug Testing" <?php if (strpos($patient['purpose'], 'Drug Testing') !== false) echo 'checked'; ?>> Drug Testing<br>

        <button type="submit">Update</button>
    </form>
<?php
} else {
    echo "Invalid request.";
}
?>
