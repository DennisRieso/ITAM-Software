<?php
require_once "class/DB.class.php";

header("Content-Type: application/json");

file_put_contents("debug_ajax.log", "Neue Suchanfrage erhalten...\n", FILE_APPEND);

$search = isset($_GET['query']) ? trim($_GET['query']) : '';

if (empty($search)) {
    file_put_contents("debug_ajax.log", "Kein Suchbegriff eingegeben.\n", FILE_APPEND);
    echo json_encode([]);
    exit();
}

$db = new DB();
$searchTerm = "%$search%";
file_put_contents("debug_ajax.log", "Suchbegriff: $search\n", FILE_APPEND);

$results = [];

// Kunden durchsuchen
$customers = $db->fetchAll("
    SELECT id, company_name, contact_person, email, phone 
    FROM customer 
    WHERE company_name LIKE ? 
    OR contact_person LIKE ?
    OR email LIKE ?
    OR phone LIKE ?", 
    [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
foreach ($customers as $customer) {
    $results[] = [
        "type" => "customer",
        "id" => $customer['id'],
        "customer_id" => $customer['id'], 
        "name" => $customer['company_name'], 
        "details" => "Kontakt: {$customer['contact_person']}, E-Mail: {$customer['email']}"
    ];
}

// Hardware durchsuchen
$hardware = $db->fetchAll("
    SELECT h.id, h.type, h.manufacturer, h.model, h.serial_number, h.status, 
           c.id as customer_id, c.company_name 
    FROM hardware h
    JOIN customer2hardware c2h ON h.id = c2h.hardware_id
    JOIN customer c ON c.id = c2h.customer_id
    WHERE h.type LIKE ? 
    OR h.manufacturer LIKE ? 
    OR h.model LIKE ? 
    OR h.serial_number LIKE ? 
    OR h.status LIKE ?", 
    [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
foreach ($hardware as $hw) {
    $results[] = [
        "type" => "hardware",
        "id" => $hw['id'],
        "customer_id" => $hw['customer_id'], 
        "name" => "{$hw['type']} - {$hw['manufacturer']} ({$hw['model']}, SN: {$hw['serial_number']}, Status: {$hw['status']}) - Kunde: {$hw['company_name']}"
    ];
}

// Software durchsuchen 
$software = $db->fetchAll("
    SELECT s.id, s.software_name, s.vendor, s.version, c.id as customer_id, c.company_name 
    FROM software s
    JOIN customer2software c2s ON s.id = c2s.software_id
    JOIN customer c ON c.id = c2s.customer_id
    WHERE s.software_name LIKE ? 
    OR s.vendor LIKE ? 
    OR s.version LIKE ?", 
    [$searchTerm, $searchTerm, $searchTerm]);
foreach ($software as $sw) {
    $results[] = [
        "type" => "software",
        "id" => $sw['id'],
        "customer_id" => $sw['customer_id'],
        "name" => "{$sw['software_name']} - {$sw['vendor']} v{$sw['version']} ({$sw['company_name']})"
    ];
}

// Lizenzen durchsuchen 
$lizenzen = $db->fetchAll("
    SELECT l.id, l.license_name, l.license_key, l.expiry_date, s.id as software_id, s.software_name 
    FROM license l
    JOIN software2license s2l ON l.id = s2l.license_id
    JOIN software s ON s.id = s2l.software_id
    WHERE l.license_name LIKE ? 
    OR l.license_key LIKE ? 
    OR l.expiry_date LIKE ?", 
    [$searchTerm, $searchTerm, $searchTerm]);
foreach ($lizenzen as $lizenz) {
    $results[] = [
        "type" => "license",
        "id" => $lizenz['id'],
        "software_id" => $lizenz['software_id'],
        "name" => "{$lizenz['license_name']} (Key: {$lizenz['license_key']}, Ablauf: {$lizenz['expiry_date']}, Software: {$lizenz['software_name']})"
    ];
}

// Mitarbeiter durchsuchen
$employees = $db->fetchAll("
    SELECT e.id, e.first_name, e.last_name, c.id as customer_id, c.company_name
    FROM employee e
    JOIN employee2customer e2c ON e.id = e2c.employee_id
    JOIN customer c ON c.id = e2c.customer_id
    WHERE e.first_name LIKE ? OR e.last_name LIKE ?", [$searchTerm, $searchTerm]);
foreach ($employees as $emp) {
    $results[] = [
        "type" => "employee",
        "id" => $emp['id'],
        "customer_id" => $emp['customer_id'],
        "name" => "{$emp['first_name']} {$emp['last_name']} (Kunde: {$emp['company_name']})"
    ];
}

file_put_contents("debug_ajax.log", "✅ Ergebnisse gefunden: " . print_r($results, true) . "\n", FILE_APPEND);
echo json_encode($results);
exit();
?>
