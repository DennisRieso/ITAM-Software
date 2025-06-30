<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();

    if (!isset($_SESSION['user_id'])) {
        header("Location: index.php?act=login");
        exit();
    }
}

require_once 'class/DB.class.php';
require_once 'class/hardware.class.php';
require_once 'class/software.class.php';
require_once 'class/employee.class.php';
require_once 'class/customer.class.php';
require_once 'class/license.class.php';

// Funktion zur Ausgabe der Webseite
function output($in_content)
{
    $out = file_get_contents("view/index.html");
    $out = str_replace("###CONTENT###", $in_content, $out);

    $text = "<a href='index.php?act=login'>Login</a>";

    if (isset($_SESSION['user_id'])) {
        $text = "Angemeldet als " . $_SESSION['first_name'] . " | ";
        $text .= "<a href='index.php?act=logout'>Logout</a>";
    }

    $out = str_replace("###Logout###", $text, $out);
    die($out);
}

// Login-Funktion
function act_login()
{
    if (g('email') != null && g('password') != null) {
        $user = Employee::login(g('email'), g('password'));

        if ($user) {
            $_SESSION['user_id'] = $user->id;
            $_SESSION['user_role'] = $user->user_roles_id;
            $_SESSION['first_name'] = $user->first_name;

            // Weiterleitung zur Kundenliste
            header("Location: index.php?act=list_customer");
            exit();
        } else {
            // Fehlermeldung mit "Zurück zum Login"-Button
            $error_message = "Login fehlgeschlagen. Bitte prüfen Sie Ihre E-Mail oder Passwort.";
            $error_page = file_get_contents("view/error.html");
            $output = str_replace("###ERROR_MESSAGE###", htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'), $error_page);
            $output = str_replace("###BACK_LINK###", "index.php?act=login", $output); // Korrekte Rückverlinkung

            output($output);
            exit();
        }
    }

    // Login-Formular anzeigen
    $html_output = file_get_contents("view/login.html");
    output($html_output);
}

// Logout-Funktion
function act_logout()
{
    session_start();
    session_destroy();
    
    // Zur Startseite weiterleiten
    header("Location: index.php");
    exit();
}

// Hardware-Management
// Liste aller Hardware-Geräte anzeigen
function act_list_hardware()
{
    if (!isset($_SESSION['user_id'])) {
        output("Zugriff verweigert!");
    }

    $db = new DB();
    $all_hardware = Hardware::getAll();
    $table_html = file_get_contents("view/list_hardware.html");

    $all_rows = "";
    $is_admin = ($_SESSION['user_role'] == 1);
    $is_support = ($_SESSION['user_role'] == 3);
    $user_id = $_SESSION['user_id'];
    $username = $_SESSION['first_name'] ?? "Unbekannt";

    foreach ($all_hardware as $hardware_data) {
        $tmp_hardware = new Hardware($hardware_data['id']);
    
        // Kunden-ID für die Hardware abrufen
        // Kundeninformationen zur Hardware abrufen
        $customer_data = $db->fetchOne("
            SELECT c.id AS customer_id, c.company_name 
            FROM customer c
            JOIN customer2hardware c2h ON c.id = c2h.customer_id
            WHERE c2h.hardware_id = ?", 
            [$tmp_hardware->id]
        );

        $customer_name = $customer_data['company_name'] ?? "Kein Kunde";
        $customer_id = $customer_data['customer_id'] ?? 0;

        // Prüfen, ob der Supporter Zugriff hat
        $has_access = $is_admin || ($is_support && $db->fetchOne("SELECT * FROM employee2customer WHERE customer_id = ? AND employee_id = ?", [$customer_id, $user_id]));
    
        // Verknüpfte Software abrufen
        $linked_software = $db->fetchAll(
            "SELECT s.id, s.software_name FROM software s
             JOIN software2hardware s2h ON s.id = s2h.software_id 
             WHERE s2h.hardware_id = ?",
            [$tmp_hardware->id]
        );
    
        $software_display = empty($linked_software) ? "Keine Verknüpfung" : implode(", ", array_map(fn($software) => $software['software_name'], $linked_software));
    
        // Buttons für Admins und Supporter mit Zugriff
        $buttons = "";
        if ($has_access) {
            //$buttons .= "<a href='index.php?act=manage_hardware&id={$tmp_hardware->id}' class='edit-btn'>Bearbeiten</a>";
        }
        if ($is_admin) {
            $buttons .= "<a href='index.php?act=delete_hardware&id={$tmp_hardware->id}' class='delete-btn'>Löschen</a>";
        }
    
        $all_rows .= "<tr>
            <td>{$tmp_hardware->id}</td>
            <td>{$tmp_hardware->type}</td>
            <td>{$tmp_hardware->manufacturer}</td>
            <td>{$tmp_hardware->model}</td>
            <td>{$tmp_hardware->serial_number}</td>
            <td>{$tmp_hardware->status}</td>
            <td>{$customer_name}</td>
            <td>{$software_display}</td>
            <td class='actions'>{$buttons}</td>
        </tr>";
    }
    
    $customer_info = "Alle Hardware";
    $add_hardware_button = $is_admin ? " " : "";

    // Zurück-Button zur richtigen Kundenansicht
    $back_button = "<a href='index.php?act=list_customer' class='back-btn'>← Zur Kundenübersicht</a><br>";

    // Platzhalter ersetzen
    $out = str_replace("###USERNAME###", $username, $table_html);
    $out = str_replace("###HARDWARE_ROWS###", $all_rows, $out);
    $out = str_replace("###CUSTOMER_INFO###", $customer_info, $out);
    $out = str_replace("###ADD_HARDWARE_BUTTON###", $add_hardware_button, $out);
    $out = str_replace("###BACK_BUTTON###", $back_button, $out);

    output($out);
}

// Nur die Hardware, die mit einem bestimmten Kunden verknüpft ist
function act_list_customer_hardware()
{
    if (!isset($_SESSION['user_id'])) {
        act_error("access_denied");
        exit();
    }

    $is_admin = ($_SESSION['user_role'] == 1);
    $is_support = ($_SESSION['user_role'] == 3);
    $user_id = $_SESSION['user_id'];

    // Kunden-ID prüfen
    $customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
    if ($customer_id <= 0) {
        act_error("invalid_customer_id");
        exit();
    }

    $db = new DB();
    
    // Prüfen, ob Support Zugriff auf diesen Kunden hat
    $has_access = $is_admin || ($is_support && $db->fetchOne(
        "SELECT 1 FROM employee2customer WHERE customer_id = ? AND employee_id = ?", 
        [$customer_id, $user_id]
    ));

    // Kundendaten laden
    $customer = new Customer($customer_id);
    if (!$customer->id) {
        act_error("customer_not_found");
        exit();
    }

    // Kunde-Info für Platzhalter setzen
    $customer_info = htmlspecialchars($customer->company_name ?? "Unbekannter Kunde", ENT_QUOTES, 'UTF-8');

    // Hardware des Kunden abrufen
    $all_hardware = $db->fetchAll("
        SELECT h.*, c.company_name 
        FROM hardware h
        JOIN customer2hardware c2h ON h.id = c2h.hardware_id
        JOIN customer c ON c.id = c2h.customer_id
        WHERE c2h.customer_id = ?", 
        [$customer_id]
    );

    $table_html = file_get_contents("view/list_hardware.html");

    $all_rows = "";
    $username = $_SESSION['first_name'] ?? "Unbekannt";

    foreach ($all_hardware as $hardware) {
        $hardware_id = $hardware['id'];

        // Verknüpfte Software abrufen
        $linked_software = $db->fetchAll(
            "SELECT s.software_name FROM software s 
             JOIN software2hardware s2h ON s.id = s2h.software_id 
             WHERE s2h.hardware_id = ?",
            [$hardware_id]
        );

        $software_display = empty($linked_software) 
            ? "Keine Verknüpfung" 
            : implode(", ", array_map(fn($s) => $s['software_name'], $linked_software)); // Kein <a> Tag mehr

        // Bearbeiten nur für Admins & Support bei eigenen Kunden
        $buttons = ($is_admin || $has_access) ? "<a href='index.php?act=manage_hardware&id={$hardware_id}&customer_id={$customer_id}' class='edit-btn'><i class='bi bi-pencil-square'></i>Bearbeiten</a>" : "";
        $buttons .= $is_admin ? "<a href='index.php?act=delete_hardware&id={$hardware_id}&customer_id={$customer_id}' class='delete-btn'><i class='bi bi-trash'></i>Löschen</a>" : "";

        $all_rows .= "<tr>
            <td>{$hardware_id}</td>
            <td>{$hardware['type']}</td>
            <td>{$hardware['manufacturer']}</td>
            <td>{$hardware['model']}</td>
            <td>{$hardware['serial_number']}</td>
            <td>{$hardware['status']}</td>
            <td>{$hardware['company_name']}</td>
            <td>{$software_display}</td>
            <td class='actions'>{$buttons}</td>
        </tr>";
    }

    // Zurück-Button zur Kundenliste
    $back_button =  "<a href='index.php?act=list_customer' class='back-btn'>← Zur Kundenliste</a>";

    // Neue Hardware hinzufügen
    $add_hardware_button = ($is_admin || $has_access) 
        ? "<a href='index.php?act=manage_hardware&customer_id={$customer_id}' class='edit-btn'>➕ Neue Hardware hinzufügen</a>" 
        : "";

    // Platzhalter ersetzen
    $out = str_replace("###USERNAME###", htmlspecialchars($username, ENT_QUOTES, 'UTF-8'), $table_html);
    $out = str_replace("###CUSTOMER_INFO###", $customer_info, $out);
    $out = str_replace("###HARDWARE_ROWS###", $all_rows, $out);
    $out = str_replace("###ADD_HARDWARE_BUTTON###", $add_hardware_button, $out);
    $out = str_replace("###BACK_BUTTON###", $back_button, $out);

    output($out);
}

// Hardware hinzufügen oder bearbeiten
function act_manage_hardware()
{
    // Zugriffskontrolle
    if (!isset($_SESSION['user_id'])) {
        act_error("access_denied");
        exit();
    }

    $user_id = $_SESSION['user_id'];
    $user_role = $_SESSION['user_role'];
    $is_admin = ($user_role == 1);
    $is_support = ($user_role == 3);

    // Hardware-ID abrufen
    $hardware_id = isset($_POST['id']) ? intval($_POST['id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);

    // Kunden-ID abrufen
    $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : (isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0);

    $db = new DB();

    // Falls Hardware existiert, Kunden-ID aus DB abrufen
    if ($hardware_id > 0 && empty($_POST['send'])) { 
        $customer_data = $db->fetchOne("SELECT customer_id FROM customer2hardware WHERE hardware_id = ?", [$hardware_id]);
        if (!empty($customer_data) && isset($customer_data['customer_id'])) {
            $customer_id = intval($customer_data['customer_id']);
        }
    }

    // Prüfen, ob Zugriff erlaubt ist
    $has_access = $is_admin || ($is_support && $customer_id > 0 && $db->fetchOne(
        "SELECT 1 FROM employee2customer WHERE customer_id = ? AND employee_id = ?", 
        [$customer_id, $user_id]
    ));

    if (!$has_access) {
        act_error("access_denied");
        exit();
    }

    // Hardware-Objekt laden oder neu erstellen
    $tmp_hardware = ($hardware_id > 0) ? new Hardware($hardware_id) : new Hardware();   

    if (!$tmp_hardware->id && $hardware_id > 0) {
        act_error("hardware_not_found");
        exit();
    }    

    // Falls das Formular gesendet wurde
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send'])) {
        $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;

        // Hardware-Felder setzen
        $tmp_hardware->type = trim($_POST['type']);
        $tmp_hardware->manufacturer = trim($_POST['manufacturer']);
        $tmp_hardware->model = trim($_POST['model']);
        $tmp_hardware->serial_number = trim($_POST['serial_number']);
        $tmp_hardware->status = trim($_POST['status']);

        try {
            $result = $tmp_hardware->save();
            if (!$result) {
                die("Fehler: Hardware konnte nicht gespeichert werden!");
            }

            // Verknüpfung mit Kunden speichern
            if ($customer_id > 0) {
                $db->execute("DELETE FROM customer2hardware WHERE hardware_id = ?", [$tmp_hardware->id]);
                $db->execute("INSERT INTO customer2hardware (customer_id, hardware_id) VALUES (?, ?)", [$customer_id, $tmp_hardware->id]);
            }

            // Software-Verknüpfungen aktualisieren
            $selected_software = isset($_POST['software_ids']) ? $_POST['software_ids'] : [];

            $db->execute("DELETE FROM software2hardware WHERE hardware_id = ?", [$tmp_hardware->id]);
            foreach ($selected_software as $software_id) {
                $db->execute("INSERT INTO software2hardware (hardware_id, software_id) VALUES (?, ?)", [$tmp_hardware->id, intval($software_id)]);
            }

            // Logging der Aktion
            if ($is_admin || $is_support) {
                $action = "Hardware '{$tmp_hardware->type} {$tmp_hardware->manufacturer} ({$tmp_hardware->model})' (ID: {$tmp_hardware->id}) wurde von User ID {$_SESSION['user_id']} gespeichert/aktualisiert.";
                $db->logAction($_SESSION['user_id'], $action);
            }

            // Weiterleitung zur Kunden-Hardwareliste
            if ($customer_id > 0) {
                header("Location: index.php?act=list_customer_hardware&customer_id=$customer_id");
            } else {
                header("Location: index.php?act=list_hardware");
            }
            exit();
        } catch (Exception $e) {
            die("Fehler: " . $e->getMessage());
        }
    }

    // Alle verknüpften Kunden für die Hardware abrufen
    $linked_customers = $db->fetchAll("SELECT customer_id FROM customer2hardware WHERE hardware_id = ?", [$tmp_hardware->id]);
    $linked_customer_ids = array_column($linked_customers, 'customer_id');

    // Kunden für Admin & Support filtern
    if ($is_admin) {
        $all_customers = $db->fetchAll("SELECT id, company_name FROM customer ORDER BY company_name ASC");
    } else {
        $all_customers = $db->fetchAll("
            SELECT c.id, c.company_name 
            FROM customer c
            JOIN employee2customer e2c ON c.id = e2c.customer_id
            WHERE e2c.employee_id = ?
            ORDER BY c.company_name ASC",
            [$user_id]
        );
    }

    // Dropdown für Kunden
    $customer_options = "";
    foreach ($all_customers as $customer) {
        $selected = ($customer['id'] == $customer_id) ? "selected" : "";
        $customer_options .= "<option value='{$customer['id']}' $selected>{$customer['company_name']}</option>";
    }

    // Verknüpfte Software abrufen
    $linked_software_ids = array_column(
        $db->fetchAll("SELECT software_id FROM software2hardware WHERE hardware_id = ?", [$tmp_hardware->id]), 
        'software_id'
    );

    // Software für Admin & Support filtern
    if ($is_admin) {
        $all_software = $db->fetchAll("SELECT id, software_name FROM software ORDER BY software_name ASC");
    } else {
        $all_software = $db->fetchAll("
            SELECT s.id, s.software_name 
            FROM software s
            JOIN customer2software c2s ON s.id = c2s.software_id
            WHERE c2s.customer_id = ?
            ORDER BY s.software_name ASC", 
            [$customer_id]
        );
    }

    // Dropdown für Software
    $software_options = "";
    foreach ($all_software as $software) {
        $selected = in_array($software['id'], $linked_software_ids) ? "selected" : "";
        $software_options .= "<option value='{$software['id']}' $selected>{$software['software_name']}</option>";
    }

    // HTML-Formular laden
    $out = file_get_contents("view/manage_hardware.html");

    // Platzhalter ersetzen
    $out = str_replace("###ID###", $tmp_hardware->id ?? "", $out);
    $out = str_replace("###TYPE###", htmlspecialchars($tmp_hardware->type ?? "", ENT_QUOTES, 'UTF-8'), $out);
    $out = str_replace("###MANUFACTURER###", htmlspecialchars($tmp_hardware->manufacturer ?? "", ENT_QUOTES, 'UTF-8'), $out);
    $out = str_replace("###MODEL###", htmlspecialchars($tmp_hardware->model ?? "", ENT_QUOTES, 'UTF-8'), $out);
    $out = str_replace("###SERIAL_NUMBER###", htmlspecialchars($tmp_hardware->serial_number ?? "", ENT_QUOTES, 'UTF-8'), $out);
    $out = str_replace("###SOFTWARE_OPTIONS###", $software_options, $out);
    $out = str_replace("###CUSTOMER_OPTIONS###", $customer_options, $out);
    $out = str_replace("###CUSTOMER_ID###", $customer_id, $out);
    $back_link = "index.php?act=list_customer_hardware&customer_id=$customer_id";
    $out = str_replace("###BACK_LINK###", $back_link, $out);

    output($out);
}

// Hardware löschen
function act_delete_hardware()
{
    // Zugriffskontrolle: Nur Admins dürfen löschen
    if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 1) {
        act_error("access_denied");
        exit();
    }

    // ID aus URL sicher extrahieren
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;

    if ($id <= 0) {
        act_error("invalid_hardware_id");
        exit();
    }

    // Überprüfen, ob die Hardware existiert
    $tmp_hardware = new Hardware($id);
    if (!$tmp_hardware->id) {
        act_error("hardware_not_found");
        exit();
    }

    // Kunden-ID ermitteln, um nach dem Löschen dorthin zurückzuleiten
    $db = new DB();
    $customer = $db->fetchOne("SELECT customer_id FROM customer2hardware WHERE hardware_id = ?", [$id]);
    $customer_id = $customer ? $customer['customer_id'] : null;

    // Hardware-Name vor der Löschung speichern
    $hardware_name = $tmp_hardware->manufacturer . " " . $tmp_hardware->model;

    // Hardware & Verknüpfung löschen
    $db->execute("DELETE FROM customer2hardware WHERE hardware_id = ?", [$id]); // Erst die Verknüpfung löschen
    $tmp_hardware->delete(); // Dann die Hardware selbst löschen

    // Logging der Löschung
    $action = "Hardware '{$hardware_name}' (ID: {$id}) wurde von User ID {$_SESSION['user_id']} gelöscht.";
    $db->logAction($_SESSION['user_id'], $action);

    // Nach dem Löschen zurück zur richtigen Liste
    if ($customer_id) {
        header("Location: index.php?act=list_customer_hardware&customer_id=$customer_id");
    } else {
        header("Location: index.php?act=list_hardware");
    }
    exit();
}

// Software-Management
// Liste aller Software anzeigen
function act_list_software()
{
    if (!isset($_SESSION['user_id'])) {
        output("Zugriff verweigert!");
    }

    $db = new DB();
    $all_software = Software::getAll(true);
    $table_html = file_get_contents("view/list_software.html");

    $all_rows = "";
    $is_admin = ($_SESSION['user_role'] == 1);
    $is_support = ($_SESSION['user_role'] == 3);
    $user_id = $_SESSION['user_id'];
    $username = $_SESSION['first_name'] ?? "Unbekannt";

    foreach ($all_software as $software_data) {
        $tmp_software = new Software($software_data['id']);

        // Kundenname für die Software abrufen
        $customer_data = $db->fetchOne("
            SELECT c.id AS customer_id, c.company_name 
            FROM customer c
            JOIN customer2software c2s ON c.id = c2s.customer_id
            WHERE c2s.software_id = ?", 
            [$tmp_software->id]);

        $customer_id = $customer_data['customer_id'] ?? 0;
        $customer_name = $customer_data['company_name'] ?? "Kein Kunde";

        // Verknüpfte Lizenzen abrufen
        $linked_licenses = $db->fetchAll(
            "SELECT l.id, l.license_name FROM license l
             JOIN software2license s2l ON l.id = s2l.license_id 
             WHERE s2l.software_id = ?",
            [$tmp_software->id]
        );
        $license_display = empty($linked_licenses) 
        ? "Keine Lizenz" 
        : implode(", ", array_map(fn($l) => $l['license_name'], $linked_licenses));

        // Verknüpfte Hardware abrufen
        $linked_hardware = $db->fetchAll(
            "SELECT h.id, h.type FROM hardware h
            JOIN software2hardware s2h ON h.id = s2h.hardware_id
            WHERE s2h.software_id = ?",
            [$tmp_software->id]
        );
        $hardware_display = empty($linked_hardware) 
            ? "Keine Hardware" 
            : implode(", ", array_map(fn($h) => $h['type'], $linked_hardware));

        // Prüfen, ob der Supporter Zugriff hat
        $has_access = $is_admin || ($is_support && $db->fetchOne(
            "SELECT * FROM employee2customer WHERE customer_id = ? AND employee_id = ?", 
            [$customer_id, $user_id]
        ));

        // Buttons für Admins und Supporter mit Zugriff
        $buttons = "";
        if ($has_access) {
            //$buttons .= "<a href='index.php?act=manage_software&id={$tmp_software->id}' class='edit-btn'>Bearbeiten</a>";
        }
        if ($is_admin) {
            $buttons .= "<a href='index.php?act=delete_software&id={$tmp_software->id}' class='delete-btn'>Löschen</a>";
        }

        // Lizenz-Button immer anzeigen
        //$buttons .= "<a href='index.php?act=list_license&software_id={$tmp_software->id}' class='license-btn'>🔑 Lizenzen anzeigen</a>";

        $all_rows .= "<tr>
            <td>{$tmp_software->id}</td>
            <td>{$tmp_software->software_name}</td>
            <td>{$tmp_software->vendor}</td>
            <td>{$tmp_software->version}</td>
            <td>{$customer_name}</td>
            <td>{$hardware_display}</td>
            <td>{$license_display}</td>
            <td class='actions'>{$buttons}</td>
        </tr>";
    }

    $customer_info = "Alle Software";
    $add_software_button = $is_admin ? " " : "";

    // Zurück-Button zur richtigen Kundenansicht
        $back_button = "<a href='index.php?act=list_customer' class='back-btn'>← Zur Kundenübersicht</a><br>";

    // Platzhalter ersetzen
    $out = str_replace("###USERNAME###", $username, $table_html);
    $out = str_replace("###SOFTWARE_ROWS###", $all_rows, $out);
    $out = str_replace("###CUSTOMER_INFO###", $customer_info, $out);
    $out = str_replace("###ADD_SOFTWARE_BUTTON###", $add_software_button, $out);
    $out = str_replace("###BACK_BUTTON###", $back_button, $out);

    output($out);
}

// Nur die Software, die mit einem bestimmten Kunden verknüpft ist
function act_list_customer_software()
{
    if (!isset($_SESSION['user_id'])) {
        act_error("access_denied");
        exit();
    }
    
    $is_admin = ($_SESSION['user_role'] == 1);
    $is_support = ($_SESSION['user_role'] == 3);
    $user_id = $_SESSION['user_id'];

    $customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
    if ($customer_id <= 0) {
        act_error("invalid_customer_id");
        exit();
    }

    $db = new DB();

    // Prüfen, ob Support Zugriff auf diesen Kunden hat
    $has_access = $is_admin || ($is_support && $db->fetchOne(
        "SELECT 1 FROM employee2customer WHERE customer_id = ? AND employee_id = ?", 
        [$customer_id, $user_id]
    ));

    $customer = new Customer($customer_id);
    if (!$customer->id) {
        act_error("customer_not_found");
        exit();
    }

    $customer_info = htmlspecialchars($customer->company_name ?? "Unbekannter Kunde", ENT_QUOTES, 'UTF-8');

    // Software des Kunden abrufen
    $all_software = $db->fetchAll("
        SELECT s.*, c.company_name 
        FROM software s
        JOIN customer2software c2s ON s.id = c2s.software_id
        JOIN customer c ON c.id = c2s.customer_id
        WHERE c2s.customer_id = ?", 
        [$customer_id]
    );

    $table_html = file_get_contents("view/list_software.html");

    $all_rows = "";
    $username = $_SESSION['first_name'] ?? "Unbekannt";

    foreach ($all_software as $software_data) {
        $software_id = $software_data['id'];

        // Verknüpfte Hardware abrufen
        $linked_hardware = $db->fetchAll(
            "SELECT h.model FROM hardware h
            JOIN software2hardware s2h ON h.id = s2h.hardware_id
            WHERE s2h.software_id = ?",
            [$software_id]
        );

        $hardware_display = empty($linked_hardware) 
            ? "Keine Hardware" 
            : implode(", ", array_map(fn($h) => $h['model'], $linked_hardware));

        // Verknüpfte Lizenzen abrufen
        $linked_licenses = $db->fetchAll(
            "SELECT l.license_name FROM license l
            JOIN software2license s2l ON l.id = s2l.license_id
            WHERE s2l.software_id = ?",
            [$software_id]
        );

        $license_display = empty($linked_licenses) 
            ? "Keine Lizenz" 
            : implode(", ", array_map(fn($l) => $l['license_name'], $linked_licenses));

        // Buttons für Admins & Supporter mit Zugriff
        $buttons = ($is_admin || $has_access) 
            ? "<a href='index.php?act=manage_software&id={$software_id}&customer_id={$customer_id}' class='edit-btn'><i class='bi bi-pencil-square'></i>Bearbeiten</a>" 
            : "";

        if ($is_admin) {
            $buttons .= "<a href='index.php?act=delete_software&id={$software_id}&customer_id={$customer_id}' class='delete-btn'><i class='bi bi-trash'></i>Löschen</a>";
        }

        // Lizenz-Button immer anzeigen
        $buttons .= "<a href='index.php?act=list_software_license&software_id={$software_id}' class='license-btn'>🔑 Lizenzen anzeigen</a>";

        // Spalten richtig anordnen
        $all_rows .= "<tr>
            <td>{$software_id}</td>
            <td>{$software_data['software_name']}</td>
            <td>{$software_data['vendor']}</td>
            <td>{$software_data['version']}</td>
            <td>{$software_data['company_name']}</td>
            <td>{$hardware_display}</td>
            <td>{$license_display}</td>
            <td class='actions'>{$buttons}</td>
        </tr>";
    }

    // Zurück-Button zur Kundenliste
    $back_button =  "<a href='index.php?act=list_customer' class='back-btn'>← Zur Kundenliste</a>";

    // Neue Software hinzufügen
    $add_software_button = ($is_admin || $has_access) 
        ? "<a href='index.php?act=manage_software&customer_id={$customer_id}' class='edit-btn'>➕ Neue Software hinzufügen</a>" 
        : "";

    // Platzhalter ersetzen
    $out = str_replace("###USERNAME###", htmlspecialchars($username, ENT_QUOTES, 'UTF-8'), $table_html);
    $out = str_replace("###CUSTOMER_INFO###", $customer_info, $out);
    $out = str_replace("###SOFTWARE_ROWS###", $all_rows, $out);
    $out = str_replace("###ADD_SOFTWARE_BUTTON###", $add_software_button, $out);
    $out = str_replace("###BACK_BUTTON###", $back_button, $out);

    output($out);
}

// Software hinzufügen oder bearbeiten
function act_manage_software()
{
    if (!isset($_SESSION['user_id'])) {
        act_error("access_denied");
        exit();
    }

    $user_id = $_SESSION['user_id'];
    $user_role = $_SESSION['user_role'];
    $is_admin = ($user_role == 1);
    $is_support = ($user_role == 3);

    $software_id = isset($_POST['id']) ? intval($_POST['id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);
    $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : (isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0);

    $db = new DB();
    $tmp_software = ($software_id > 0) ? new Software($software_id) : new Software();

    if (!$tmp_software->id && $software_id > 0) {
        act_error("software_not_found");
        exit();
    }

    // Bereits verknüpfte Hardware & Lizenzen abrufen
    $linked_hardware_ids = array_column($db->fetchAll("SELECT hardware_id FROM software2hardware WHERE software_id = ?", [$software_id]), 'hardware_id');
    $linked_license_ids = array_column($db->fetchAll("SELECT license_id FROM software2license WHERE software_id = ?", [$software_id]), 'license_id');

    // Falls das Formular gesendet wurde
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send'])) {
        $tmp_software->software_name = trim($_POST['software_name']);
        $tmp_software->vendor = trim($_POST['vendor']);
        $tmp_software->version = trim($_POST['version']);

        $hardware_ids = isset($_POST['hardware_ids']) ? $_POST['hardware_ids'] : [];
        $license_ids = isset($_POST['license_ids']) ? $_POST['license_ids'] : [];

        try {
            $result = $tmp_software->save();
            if (!$result) {
                act_error("unknown");
                exit();
            }

            // Verknüpfung mit Kunden speichern
            if ($customer_id > 0) {
                $db->execute("DELETE FROM customer2software WHERE software_id = ?", [$tmp_software->id]);
                $db->execute("INSERT INTO customer2software (customer_id, software_id) VALUES (?, ?)", [$customer_id, $tmp_software->id]);
            }

            // Verknüpfung mit Hardware aktualisieren
            $db->execute("DELETE FROM software2hardware WHERE software_id = ?", [$tmp_software->id]);
            foreach ($hardware_ids as $hardware_id) {
                $db->execute("INSERT INTO software2hardware (software_id, hardware_id) VALUES (?, ?)", [$tmp_software->id, $hardware_id]);
            }

            // Verknüpfung mit Lizenzen aktualisieren
            $db->execute("DELETE FROM software2license WHERE software_id = ?", [$tmp_software->id]);
            foreach ($license_ids as $license_id) {
                $db->execute("INSERT INTO software2license (software_id, license_id) VALUES (?, ?)", [$tmp_software->id, $license_id]);
            }

            // Logging der Aktion
            $action = "Software '{$tmp_software->software_name}' (ID: {$tmp_software->id}) wurde von User ID {$user_id} gespeichert.";
            $db->logAction($user_id, $action);

        } catch (Exception $e) {
            die("Fehler: " . $e->getMessage());
        }

        header("Location: index.php?act=list_customer_software&customer_id=$customer_id");
        exit();
    }

    // Kunden filtern
    if ($is_admin) {
        $all_customers = $db->fetchAll("SELECT id, company_name FROM customer ORDER BY company_name ASC");
    } else {
        $all_customers = $db->fetchAll("
            SELECT c.id, c.company_name 
            FROM customer c
            JOIN employee2customer e2c ON c.id = e2c.customer_id
            WHERE e2c.employee_id = ?
            ORDER BY c.company_name ASC",
            [$user_id]
        );
    }

    if (!$is_admin && count($all_customers) == 1) {
        $customer_id = $all_customers[0]['id'];
    }

    // Hardware filtern
    if ($is_admin) {
        $all_hardware = $db->fetchAll("SELECT id, type, manufacturer, model FROM hardware ORDER BY manufacturer ASC");
    } else {
        $all_hardware = $db->fetchAll("
            SELECT h.id, h.type, h.manufacturer, h.model 
            FROM hardware h
            JOIN customer2hardware c2h ON h.id = c2h.hardware_id
            WHERE c2h.customer_id = ?
            ORDER BY h.manufacturer ASC", 
            [$customer_id]
        );
    }

    // Lizenzen filtern
    if ($is_admin) {
        $all_licenses = $db->fetchAll("SELECT id, license_name FROM license ORDER BY license_name ASC");
    } else {
        $all_licenses = $db->fetchAll("
            SELECT l.id, l.license_name 
            FROM license l
            JOIN software2license s2l ON l.id = s2l.license_id
            JOIN customer2software c2s ON s2l.software_id = c2s.software_id
            WHERE c2s.customer_id = ?
            ORDER BY l.license_name ASC", 
            [$customer_id]
        );
    }

    // Optionen für Select-Felder generieren
    $customer_options = "";
    foreach ($all_customers as $customer) {
        $selected = ($customer['id'] == $customer_id) ? "selected" : "";
        $customer_options .= "<option value='{$customer['id']}' $selected>{$customer['company_name']}</option>";
    }

    $hardware_options = "";
    foreach ($all_hardware as $hardware) {
        $selected = in_array($hardware['id'], $linked_hardware_ids) ? "selected" : "";
        $hardware_options .= "<option value='{$hardware['id']}' $selected>{$hardware['type']} - {$hardware['manufacturer']} ({$hardware['model']})</option>";
    }

    $license_options = "";
    foreach ($all_licenses as $license) {
        $selected = in_array($license['id'], $linked_license_ids) ? "selected" : "";
        $license_options .= "<option value='{$license['id']}' $selected>{$license['license_name']}</option>";
    }

    // HTML-Formular anzeigen
    $out = file_get_contents("view/manage_software.html");

    // Platzhalter ersetzen
    $out = str_replace("###ID###", $tmp_software->id ?? "", $out);
    $out = str_replace("###SOFTWARE_NAME###", htmlspecialchars($tmp_software->software_name ?? "", ENT_QUOTES, 'UTF-8'), $out);
    $out = str_replace("###VENDOR###", htmlspecialchars($tmp_software->vendor ?? "", ENT_QUOTES, 'UTF-8'), $out);
    $out = str_replace("###VERSION###", htmlspecialchars($tmp_software->version ?? "", ENT_QUOTES, 'UTF-8'), $out);
    $out = str_replace("###CUSTOMER_ID###", $customer_id > 0 ? $customer_id : "", $out);
    $out = str_replace("###HARDWARE_OPTIONS###", $hardware_options, $out);
    $out = str_replace("###LICENSE_OPTIONS###", $license_options, $out);
    $out = str_replace("###CUSTOMER_OPTIONS###", $customer_options, $out);

    output($out);
}

// Software löschen
function act_delete_software()
{
    // Zugriffskontrolle: Nur Admins dürfen löschen
    if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 1) {
        act_error("access_denied");
        exit();
    }

    // Software-ID prüfen
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if ($id <= 0) {
        act_error("invalid_software_id");
        exit();
    }

    // Kunden-ID herausfinden, falls vorhanden
    $db = new DB();
    $customer_id_query = $db->fetchOne("SELECT customer_id FROM customer2software WHERE software_id = ?", [$id]);
    $customer_id = $customer_id_query ? intval($customer_id_query['customer_id']) : null;

    // Software-Objekt laden
    $tmp_software = new Software($id);
    if (!$tmp_software->id) {
        act_error("software_not_found");
        exit();
    }

    // Software-Name vor der Löschung speichern
    $software_name = $tmp_software->software_name;

    // Falls die Software mit einem Kunden verknüpft ist, lösche die Verknüpfung
    if ($customer_id) {
        $db->execute("DELETE FROM customer2software WHERE software_id = ?", [$id]);
    }

    // Software endgültig löschen
    $tmp_software->delete();

    // Logging der Löschung
    $action = "Software '{$software_name}' (ID: {$id}) wurde von User ID {$_SESSION['user_id']} gelöscht.";
    $db->logAction($_SESSION['user_id'], $action);

    // Falls eine Kunden-ID existiert, zur Kunden-Software-Seite zurückkehren
    if ($customer_id) {
        header("Location: index.php?act=list_customer_software&customer_id=$customer_id");
    } else {
        header("Location: index.php?act=list_software");
    }
    exit();
}

// Lizenz-Management
// Alle Lizenzen anzeigen
function act_list_license()
{
    if (!isset($_SESSION['user_id'])) {
        output("Zugriff verweigert!");
    }

    $db = new DB();
    $software_id = isset($_GET['software_id']) ? intval($_GET['software_id']) : 0;
    
    $is_admin   = ($_SESSION['user_role'] == 1);
    $is_support = ($_SESSION['user_role'] == 3);
    $user_id    = $_SESSION['user_id'];
    $username   = $_SESSION['first_name'] ?? "Unbekannt";

    if ($software_id > 0) {
        $software = new Software($software_id);
        if (!$software->id) {
            act_error("software_not_found");
            exit();
        }

        $customer_data = $db->fetchOne("SELECT customer_id FROM customer2software WHERE software_id = ?", [$software_id]);
        $customer_id = isset($customer_data['customer_id']) ? intval($customer_data['customer_id']) : 0;
    } else {
        $customer_id = null;
    }

    // Prüfen, ob der Supporter Zugriff hat
    $has_access = $is_admin || ($is_support && $customer_id > 0 && 
    $db->fetchOne("SELECT 1 FROM employee2customer WHERE customer_id = ? AND employee_id = ?", [$customer_id, $user_id])
    );

    // 🔍 Alle Lizenzen abrufen + verknüpfte Software ermitteln
    $all_licenses = $db->fetchAll("
        SELECT l.id, l.license_name, l.license_key, l.expiry_date, l.status,
            GROUP_CONCAT(s.software_name SEPARATOR ', ') AS linked_software
        FROM license l
        LEFT JOIN software2license s2l ON l.id = s2l.license_id
        LEFT JOIN software s ON s2l.software_id = s.id
        GROUP BY l.id
    ");

    $software_info = ($software_id > 0) ? htmlspecialchars($software->software_name ?? "Unbekannte Software", ENT_QUOTES, 'UTF-8') : "Alle Lizenzen";

    $table_html = file_get_contents("view/list_license.html");
    $all_rows = "";

    if (empty($all_licenses)) {
        $all_rows = "<tr><td colspan='7' class='text-center'>Keine Lizenzen gefunden</td></tr>";
    } else {
        foreach ($all_licenses as $license) {
            $software_display = htmlspecialchars($license['linked_software'] ?? "Keine Software", ENT_QUOTES, 'UTF-8');

            // Buttons für Admins & Supporter mit Zugriff
            $buttons = "";
            if ($has_access) {
                //$buttons .= "<a href='index.php?act=manage_license&id={$license['id']}&software_id={$software_id}' class='edit-btn'><i class='bi bi-pencil-square'></i>Bearbeiten</a>";
            }
            if ($is_admin) {
                $buttons .= "<a href='index.php?act=delete_license&id={$license['id']}&software_id={$software_id}' class='delete-btn'><i class='bi bi-trash'></i>Löschen</a>";
            }

            $all_rows .= "<tr>
                <td>{$license['id']}</td>
                <td>{$license['license_name']}</td>
                <td>{$license['license_key']}</td>
                <td>{$license['expiry_date']}</td>
                <td>{$license['status']}</td>
                <td>{$software_display}</td> <!-- ✅ Neue Spalte -->
                <td class='actions'>{$buttons}</td>
            </tr>";
        }
    }

    // Zurück-Button zur richtigen Ansicht
    if ($customer_id > 0) {
        $back_button = "<a href='index.php?act=list_customer_software&customer_id={$customer_id}' class='back-btn'>← Zur Software-Übersicht</a><br>";
    } else {
        $back_button = "<a href='index.php?act=list_customer' class='back-btn'>← Zur Kundenübersicht</a><br>";
    }

    // Neue Lizenz hinzufügen
    $add_license_button = $is_admin ? " " : "";
    //? "<a href='index.php?act=manage_license&software_id={$software_id}' class='edit-btn'>➕ Neue Lizenz hinzufügen</a>"
    //: "";

    // Platzhalter ersetzen
    $out = str_replace("###USERNAME###", htmlspecialchars($username, ENT_QUOTES, 'UTF-8'), $table_html);
    $out = str_replace("###SOFTWARE_INFO###", $software_info, $out);
    $out = str_replace("###LICENSE_ROWS###", $all_rows, $out);
    $out = str_replace("###ADD_LICENSE_BUTTON###", $add_license_button, $out);
    $out = str_replace("###BACK_BUTTON###", $back_button, $out);

    output($out);
}

// Nur die Lizenzen, die mit einer bestimmten Software eines Kunden verknüpft sind
function act_list_software_license()
{
    // Zugriffskontrolle
    if (!isset($_SESSION['user_id'])) {
        act_error("access_denied");
        exit();
    }

    $is_admin = ($_SESSION['user_role'] == 1);
    $is_support = ($_SESSION['user_role'] == 3);

    if (!$is_admin && !$is_support) {
        act_error("access_denied");
        exit();
    }

    // Software-ID prüfen
    $software_id = isset($_GET['software_id']) ? intval($_GET['software_id']) : 0;
    if ($software_id <= 0) {
        act_error("invalid_software_id");
        exit();
    }

    // Software-Daten laden
    $software = new Software($software_id);
    if (!$software->id) {
        act_error("software_not_found");
        exit();
    }

    // Kunden-IDs abrufen, die mit dieser Software verknüpft sind
    $db = new DB();
    $customer_data = $db->fetchOne("SELECT customer_id FROM customer2software WHERE software_id = ? LIMIT 1", [$software_id]);
    $customer_id = $customer_data['customer_id'] ?? 0; // Falls keine Verknüpfung existiert, Standardwert setzen

    // Software-Namen für Anzeige setzen
    $software_info = htmlspecialchars($software->software_name ?? "Unbekannte Software", ENT_QUOTES, 'UTF-8');

    // Lizenzen mit der zugehörigen Software abrufen
    $all_licenses = $db->fetchAll("
        SELECT l.id, l.license_name, l.license_key, l.expiry_date, l.status,
               GROUP_CONCAT(s.software_name SEPARATOR ', ') AS linked_software
        FROM license l
        JOIN software2license s2l ON l.id = s2l.license_id
        JOIN software s ON s2l.software_id = s.id
        WHERE s2l.software_id = ?
        GROUP BY l.id",
        [$software_id]
    );

    // HTML-Template laden
    $table_html = file_get_contents("view/list_license.html");

    $all_rows = "";
    $username = $_SESSION['first_name'] ?? "Unbekannt";

    if (empty($all_licenses)) {
        $all_rows = "<tr><td colspan='7' style='text-align:center;'>Keine Lizenzen gefunden</td></tr>";
    } else {
        foreach ($all_licenses as $license_data) {
            $linked_software = htmlspecialchars($license_data['linked_software'] ?? "Keine Software", ENT_QUOTES, 'UTF-8');

            // Buttons für Admins mit software_id
            $buttons = ($is_admin || $is_support) ? "
            <a href='index.php?act=manage_license&id={$license_data['id']}&software_id={$software_id}' class='edit-btn'>Bearbeiten</a>
            " : "";
            
            if ($is_admin) {
                $buttons .= "<a href='index.php?act=delete_license&id={$license_data['id']}&software_id={$software_id}' class='delete-btn'>Löschen</a>";
            }

            $all_rows .= "<tr>
                <td>{$license_data['id']}</td>
                <td>{$license_data['license_name']}</td>
                <td>{$license_data['license_key']}</td>
                <td>{$license_data['expiry_date']}</td>
                <td>{$license_data['status']}</td>
                <td>{$linked_software}</td>
                <td class='actions'>{$buttons}</td>
            </tr>";
        }
    }

    // Zurück-Button zur Kunden-Software-Übersicht
    if ($customer_id > 0) {
        $back_button = "<br><a href='index.php?act=list_customer_software&customer_id={$customer_id}' class='back-btn'>← Zur Software</a><br>";
    } else {
        $back_button = "<br><a href='index.php?act=list_software' class='back-btn'>← Zur Softwareliste</a><br>";
    }

    // Prüfen, ob Support Zugriff auf den Kunden hat
    $has_access = $is_admin || ($is_support && $db->fetchOne(
    "SELECT 1 FROM employee2customer WHERE customer_id = ? AND employee_id = ?", 
    [$customer_id, $_SESSION['user_id']]
    ));

    // Neue Lizenz hinzufügen
    $add_license_button = ($is_admin || $has_access) 
        ? "<a href='index.php?act=manage_license&software_id={$software_id}' class='edit-btn'>➕ Neue Lizenz hinzufügen</a>" 
        : "";

    // Platzhalter ersetzen
    $out = str_replace("###USERNAME###", htmlspecialchars($username, ENT_QUOTES, 'UTF-8'), $table_html);
    $out = str_replace("###SOFTWARE_INFO###", $software_info, $out);
    $out = str_replace("###LICENSE_ROWS###", $all_rows, $out);
    $out = str_replace("###ADD_LICENSE_BUTTON###", $add_license_button, $out);
    $out = str_replace("###BACK_BUTTON###", $back_button, $out);

    output($out);
}

// Lizenzen hinzufügen oder bearbeiten
function act_manage_license()
{
    // Zugriffskontrolle
    $is_admin = ($_SESSION['user_role'] == 1);
    $is_support = ($_SESSION['user_role'] == 3);
    $software_id = isset($_GET['software_id']) ? intval($_GET['software_id']) : 0;
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    
    $db = new DB();
    $customer_data = $db->fetchOne("SELECT customer_id FROM customer2software WHERE software_id = ?", [$software_id]);
    $customer_id = $customer_data['customer_id'] ?? 0;
    
    $has_access = $is_admin || ($is_support && $db->fetchOne(
        "SELECT 1 FROM employee2customer WHERE customer_id = ? AND employee_id = ?", 
        [$customer_id, $_SESSION['user_id']]
    ));

    // Support darf neue Lizenzen für seinen Kunden erstellen
    if ($id == 0 && $is_support && $customer_id > 0) {
        $has_access = true;
    }
    
    if (!$has_access) {
        act_error("access_denied");
        exit();
    }        

    // Lizenz-ID holen
    $tmp_license = new License($id);

    if ($id > 0 && !$tmp_license->id) {
        act_error("license_not_found");
        exit();
    }

    // Falls das Formular gesendet wurde
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send'])) {
        $tmp_license->license_name = trim($_POST['license_name']);
        $tmp_license->license_key = trim($_POST['license_key']);
        $tmp_license->expiry_date = $_POST['expiry_date'];
        $tmp_license->status = trim($_POST['status']);
        
        // Lizenz speichern
        if (!$tmp_license->save()) {
            echo "Fehler beim Speichern der Lizenz!";
            exit();
        }

        // Verknüpfung mit Software aktualisieren
        $selected_software = isset($_POST['software_ids']) ? $_POST['software_ids'] : [];

        // Alte Verknüpfungen löschen
        $db->execute("DELETE FROM software2license WHERE license_id = ?", [$tmp_license->id]);

        // Neue Verknüpfungen setzen
        foreach ($selected_software as $software_id) {
            $db->execute("INSERT INTO software2license (software_id, license_id) VALUES (?, ?)", [$software_id, $tmp_license->id]);
        }

        // Logging der Aktion
        if ($is_admin || $is_support) {
            $action = "Lizenz '{$tmp_license->license_name}' (ID: {$tmp_license->id}) wurde von User ID {$_SESSION['user_id']} gespeichert/aktualisiert.";
            $db->logAction($_SESSION['user_id'], $action);
        }

        // Weiterleitung zurück zur Software-Lizenzübersicht
        if (!empty($selected_software)) {
            header("Location: index.php?act=list_software_license&software_id=" . $selected_software[0]);
        } elseif ($customer_id > 0) { 
            header("Location: index.php?act=list_customer_licenses&customer_id=" . $customer_id);
        } else {
            header("Location: index.php?act=list_license");
        }

        exit();
    }

    // Verknüpfte Software abrufen
    $linked_software_ids = array_column(
        $db->fetchAll("SELECT software_id FROM software2license WHERE license_id = ?", [$tmp_license->id]), 
        'software_id'
    );

    // Software für Admin & Support filtern
    if ($is_admin) {
        $all_software = $db->fetchAll("SELECT id, software_name FROM software ORDER BY software_name ASC");
    } else {
        $all_software = $db->fetchAll("
            SELECT s.id, s.software_name 
            FROM software s
            JOIN customer2software c2s ON s.id = c2s.software_id
            WHERE c2s.customer_id = ?
            ORDER BY s.software_name ASC", 
            [$customer_id]
        );
    }

    // Dropdown für Software
    $software_options = "";
    foreach ($all_software as $software) {
        // Beim Erstellen einer neuen Lizenz wird die Software aus der URL vorausgewählt
        $selected = (in_array($software['id'], $linked_software_ids) || $software['id'] == $software_id) ? "selected" : "";
        $software_options .= "<option value='{$software['id']}' $selected>{$software['software_name']}</option>";
    }

    // Platzhalter in HTML ersetzen
    $out = file_get_contents("view/manage_license.html");
    $out = str_replace("###ID###", $tmp_license->id ?? "", $out);
    $out = str_replace("###LICENSE_NAME###", htmlspecialchars($tmp_license->license_name ?? "", ENT_QUOTES, 'UTF-8'), $out);
    $out = str_replace("###LICENSE_KEY###", htmlspecialchars($tmp_license->license_key ?? "", ENT_QUOTES, 'UTF-8'), $out);
    $out = str_replace("###EXPIRY_DATE###", $tmp_license->expiry_date ?? "", $out);
    $out = str_replace("###STATUS###", htmlspecialchars($tmp_license->status ?? "", ENT_QUOTES, 'UTF-8'), $out);
    $out = str_replace("###SOFTWARE_OPTIONS###", $software_options, $out);
    $out = str_replace("###SOFTWARE_ID###", $software_id, $out);
    $out = str_replace("###CUSTOMER_ID###", $customer_id > 0 ? $customer_id : "", $out);

    output($out);
}

// Lizenzen löschen
function act_delete_license()
{
    if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 1) {
        act_error("access_denied");
        exit();
    }

    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if ($id <= 0) {
        act_error("invalid_license_id");
        exit();
    }

    // Lizenz laden und prüfen
    $license = new License($id);
    if (!$license->id) {
        act_error("license_not_found");
        exit();
    }

    // Lizenz-Name für Logging sichern
    $license_name = $license->license_name;

    // Verknüpfte Software-ID abrufen, falls vorhanden
    $db = new DB();
    $software_data = $db->fetchOne("SELECT software_id FROM software2license WHERE license_id = ?", [$id]);
    $software_id = $software_data ? intval($software_data['software_id']) : 0;

    // Verknüpfungen in `software2license` löschen
    $db->execute("DELETE FROM software2license WHERE license_id = ?", [$id]);

    // Lizenz löschen
    $db->execute("DELETE FROM license WHERE id = ?", [$id]);

    // Logging der Löschung
    $action = "Lizenz '{$license_name}' (ID: {$id}) wurde von User ID {$_SESSION['user_id']} gelöscht.";
    $db->logAction($_SESSION['user_id'], $action);

    // Weiterleitung zur richtigen Ansicht
    if ($software_id > 0) {
        header("Location: index.php?act=list_software_license&software_id={$software_id}"); 
    } else {
        header("Location: index.php?act=list_license");
    }
    exit();
}

// Passwort-Management
// Passwortvalidierung: Überprüft die Passwortanforderungen
function validatePassword($password)
{
    return preg_match('/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/', $password);
}

// Passwort ändern
function act_change_password()
{
    if (!isset($_SESSION['user_id'])) {
        act_error("access_denied");
        exit();
    }

    $db = new DB();
    $user_id = $_SESSION['user_id'];
    $employee = new Employee($user_id);

    // Falls Formular gesendet wurde
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send'])) {
        $old_password = $_POST['old_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        // Passwort aus der Datenbank abrufen
        $stored_hash = $db->fetchOne("SELECT password FROM employee WHERE id = ?", [$user_id]);
        if (!$stored_hash || !isset($stored_hash['password'])) {
            act_error("password_not_found", "index.php?act=change_password");
            exit();
        }

        // Überprüfen, ob altes Passwort korrekt ist
        if (!password_verify($old_password, $stored_hash['password'])) {
            act_error("wrong_old_password", "index.php?act=change_password");
            exit();
        }

        // Überprüfen, ob neue Passwörter übereinstimmen
        if ($new_password !== $confirm_password) {
            act_error("passwords_do_not_match", "index.php?act=change_password");
            exit();
        }

        // Sicherheitsüberprüfung des neuen Passworts
        if (!validatePassword($new_password)) {
            act_error("invalid_password", "index.php?act=change_password");
            exit();
        }

        // Passwort-Update in der Datenbank
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $update_success = $db->execute("UPDATE employee SET password = ? WHERE id = ?", [$hashed_password, $user_id]);

        if ($update_success) {
            // Logging der Passwortänderung
            $db->logAction($user_id, "Benutzer hat sein Passwort geändert.");

            // Neue korrekte Weiterleitung
            if ($_SESSION['user_role'] == 1) { // Falls Admin, zurück zur Employee-Liste
                header("Location: index.php?act=list_employee&msg=password_changed_success");
            } else { // Falls Support-Mitarbeiter, zurück zur Kundenliste
                $customer_id = $db->fetchOne("SELECT customer_id FROM employee2customer WHERE employee_id = ?", [$user_id]);
                if ($customer_id && isset($customer_id['customer_id'])) {
                    header("Location: index.php?act=list_customer_employees&customer_id={$customer_id['customer_id']}&msg=password_changed_success");
                } else {
                    header("Location: index.php?act=list_employee&msg=password_changed_success");
                }
            }
            exit();
        } else {
            act_error("unknown", "index.php?act=change_password");
            exit();
        }
    }
    // Falls GET-Request, Passwort-Änderungsseite anzeigen
    $out = file_get_contents("view/change_password.html");
    output($out);
}

// Logging-Management
// Nur die Logs, die mit einem bestimmten Mitarbeiter verknüpft sind
function act_list_employee_logs()
{
    if (!isset($_SESSION['user_id'])) {
        act_error("access_denied");
        exit();
    }

    $user_id = $_SESSION['user_id']; 
    $user_role = $_SESSION['user_role']; 
    $is_admin = ($user_role == 1);
    $is_support = ($user_role == 3);

    // Admins können alle Logs sehen, Supporter nur ihre eigenen
    $requested_employee_id = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : 0;
    
    if ($is_admin) {
        $employee_id = $requested_employee_id; // Admin kann beliebigen Mitarbeiter aufrufen
    } else {
        if ($requested_employee_id != $user_id) {
            act_error("access_denied"); // Support darf nur sich selbst sehen
            exit();
        }
        $employee_id = $user_id; // Supporter sehen nur sich selbst
    }

    $db = new DB();
    $employee_data = $db->fetchOne("SELECT first_name, last_name FROM employee WHERE id = ?", [$employee_id]);

    if (!$employee_data) {
        act_error("employee_not_found");
        exit();
    }

    // Logs abrufen
    $logs = $db->fetchAll("SELECT id, action, timestamp FROM logs WHERE employee_id = ? ORDER BY timestamp DESC", [$employee_id]);

    $table_html = file_get_contents("view/list_logs.html");
    $all_rows = "";

    if (empty($logs)) {
        $all_rows = "<tr><td colspan='4' style='text-align:center;'>Keine Logs vorhanden</td></tr>";
    } else {
        foreach ($logs as $log) {
            // Bearbeiten/Löschen nur für Admins
            $buttons = $is_admin
                ? "<a href='index.php?act=edit_log&id={$log['id']}' class='edit-btn'><i class='bi bi-pencil-square'></i>Bearbeiten</a>
                   <a href='index.php?act=delete_log&id={$log['id']}' class='delete-btn'><i class='bi bi-trash'></i>Löschen</a>"
                : ""; // Supporter haben keine Buttons

            $all_rows .= "<tr>
                <td>{$log['id']}</td>
                <td>{$log['timestamp']}</td>
                <td>{$log['action']}</td>
                <td class='actions'>{$buttons}</td>
            </tr>";
        }
    }

    // Back-Button: Admins zurück zur Mitarbeiterliste, Supporter zur Kundenübersicht
    if ($is_admin) {
        $customer_data = $db->fetchOne("SELECT customer_id FROM employee2customer WHERE employee_id = ?", [$employee_id]);
        $customer_id = $customer_data['customer_id'] ?? 0;
        $back_button = "<a href='index.php?act=list_customer_employees&customer_id={$customer_id}' class='back-btn'>← Zur Mitarbeiterliste</a><br>";
    } else {
        $back_button = "<a href='index.php?act=list_customer' class='back-btn'>← Zurück zur Kundenübersicht</a><br>";
    }

    // Admins können Logs hinzufügen, Supporter nicht
    $add_log_form = $is_admin ? "
        <h3>Neuen Log-Eintrag hinzufügen</h3>
        <form method='POST' action='index.php?act=add_log'>
            <input type='hidden' name='employee_id' value='{$employee_id}'>
            <label for='action'>Aktion:</label>
            <input type='text' id='action' name='action' required>
            <button type='submit' class='save-btn'>Speichern</button>
        </form>
    " : "";

    $username = isset($_SESSION['first_name']) ? $_SESSION['first_name'] : "Unbekannt";

    $out = str_replace("###LOG_ROWS###", $all_rows, $table_html);
    $out = str_replace("###ADD_LOG_FORM###", $add_log_form, $out);
    $out = str_replace("###BACK_BUTTON###", $back_button, $out);
    $out = str_replace("###USERNAME###", $username, $out);

    output($out);
}

// Log hinzufügen
function act_add_log()
{
    if (!isset($_SESSION['user_id'])) {
        act_error("access_denied");
        exit();
    }

    $is_admin = ($_SESSION['user_role'] == 1);
    if (!$is_admin) {
        act_error("access_denied");
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $employee_id = isset($_POST['employee_id']) ? intval($_POST['employee_id']) : 0;
        $action = trim($_POST['action']);

        if ($employee_id <= 0 || empty($action)) {
            act_error("invalid_data");
            exit();
        }

        $db = new DB();
        $db->execute("INSERT INTO logs (employee_id, action, timestamp) VALUES (?, ?, NOW())", [$employee_id, $action]);

        header("Location: index.php?act=list_employee_logs&employee_id=$employee_id");
        exit();
    }
}

// Log löschen
function act_delete_log()
{
    if (!isset($_SESSION['user_id'])) {
        act_error("access_denied");
        exit();
    }

    $is_admin = ($_SESSION['user_role'] == 1);
    if (!$is_admin) {
        act_error("access_denied");
        exit();
    }

    $log_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if ($log_id <= 0) {
        act_error("invalid_log_id");
        exit();
    }

    $db = new DB();
    $employee_data = $db->fetchOne("SELECT employee_id FROM logs WHERE id = ?", [$log_id]);

    if (!$employee_data) {
        act_error("log_not_found");
        exit();
    }

    $employee_id = $employee_data['employee_id'];
    $db->execute("DELETE FROM logs WHERE id = ?", [$log_id]);

    header("Location: index.php?act=list_employee_logs&employee_id=$employee_id");
    exit();
}

// Log editieren
function act_edit_log()
{
    if (!isset($_SESSION['user_id'])) {
        act_error("access_denied");
        exit();
    }

    $is_admin = ($_SESSION['user_role'] == 1);
    if (!$is_admin) {
        act_error("access_denied");
        exit();
    }

    $log_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if ($log_id <= 0) {
        act_error("invalid_log_id");
        exit();
    }

    $db = new DB();
    $log_data = $db->fetchOne("SELECT * FROM logs WHERE id = ?", [$log_id]);

    if (!$log_data) {
        act_error("log_not_found");
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $new_action = trim($_POST['action']);
        if (empty($new_action)) {
            act_error("invalid_data");
            exit();
        }

        $db->execute("UPDATE logs SET action = ?, timestamp = NOW() WHERE id = ?", [$new_action, $log_id]);
        header("Location: index.php?act=list_employee_logs&employee_id={$log_data['employee_id']}");
        exit();
    }

    output("<h2>Log bearbeiten</h2>
    <form method='POST'>
        <label for='action'>Aktion:</label>
        <input type='text' name='action' value='" . htmlspecialchars($log_data['action'], ENT_QUOTES, 'UTF-8') . "' required>
        <button type='submit'>Speichern</button>
    </form>
    <br>
    <a href='index.php?act=list_employee_logs&employee_id={$log_data['employee_id']}' class='back-btn'>← Zurück</a>");
}

// Kunden-Management
// Liste aller Kunden anzeigen
function act_list_customer()
{
    if (!isset($_SESSION['user_id'])) {
        output("Zugriff verweigert!");
    }

    $db = new DB(); // Datenbankverbindung

    $search = isset($_GET['search']) ? trim($_GET['search']) : ""; // Suchbegriff aus GET abrufen
    $is_admin = isset($_SESSION['user_role']) && $_SESSION['user_role'] == 1; // Admin = 1
    $is_support = isset($_SESSION['user_role']) && $_SESSION['user_role'] == 3; // Supporter = 3
    $user_id = $_SESSION['user_id'];
    $username = isset($_SESSION['first_name']) ? $_SESSION['first_name'] : "Unbekannt";

    // Falls eine Suche vorhanden ist, filtere die Kunden
    if (!empty($search)) {
        $all_customer_ids = [];

        // Nach Hardware suchen
        $hardware_customers = $db->fetchAll("
            SELECT DISTINCT c.id FROM customer c
            JOIN customer2hardware c2h ON c.id = c2h.customer_id
            JOIN hardware h ON c2h.hardware_id = h.id
            WHERE h.type LIKE ? OR h.model LIKE ? OR h.serial_number LIKE ?
        ", ["%$search%", "%$search%", "%$search%"]);

        // Nach Software suchen
        $software_customers = $db->fetchAll("
            SELECT DISTINCT c.id FROM customer c
            JOIN customer2software c2s ON c.id = c2s.customer_id
            JOIN software s ON c2s.software_id = s.id
            WHERE s.software_name LIKE ? OR s.vendor LIKE ? OR s.version LIKE ?
        ", ["%$search%", "%$search%", "%$search%"]);

        // Nach Lizenzen suchen (indirekt über Software)
        $license_customers = $db->fetchAll("
            SELECT DISTINCT c.id FROM customer c
            JOIN customer2software c2s ON c.id = c2s.customer_id
            JOIN software s ON c2s.software_id = s.id
            JOIN software2license s2l ON s.id = s2l.software_id
            JOIN license l ON s2l.license_id = l.id
            WHERE l.license_name LIKE ? OR l.license_key LIKE ?
        ", ["%$search%", "%$search%"]);

        // Alle gefundenen Kunden IDs zusammenführen und duplizierte entfernen
        $all_customer_ids = array_unique(array_merge(
            array_column($hardware_customers, 'id'),
            array_column($software_customers, 'id'),
            array_column($license_customers, 'id')
        ));
    } else {
        // Standard: Alle Kunden laden
        $all_customer_ids = Customer::getAll();
    }

    // Kundenliste erstellen
    $all_rows = "";
    foreach ($all_customer_ids as $one_customer_id) {
        $tmp_customer = new Customer($one_customer_id);

        // Standard-Aktionen für alle Benutzer
        $buttons = "<a href='index.php?act=list_customer_hardware&customer_id={$tmp_customer->id}' class='edit-btn'>🔍 Hardware anzeigen</a>";
        $buttons .= "<a href='index.php?act=list_customer_software&customer_id={$tmp_customer->id}' class='edit-btn'>🔍 Software anzeigen</a>";
        $buttons .= "<a href='index.php?act=list_customer_employees&customer_id={$tmp_customer->id}' class='edit-btn'>👥 Mitarbeiter anzeigen</a>";

        // Prüfen, ob der Supporter diesen Kunden bearbeiten darf
        $has_access = $is_admin || ($is_support && $db->fetchOne("SELECT * FROM employee2customer WHERE customer_id = ? AND employee_id = ?", [$tmp_customer->id, $user_id]));

        if ($has_access) {
            $buttons .= "<a href='index.php?act=manage_customer&id={$tmp_customer->id}' class='edit-btn'><i class='bi bi-pencil-square'></i>Bearbeiten</a>";
        }

        // Nur Admins dürfen Kunden löschen
        if ($is_admin) {
            $buttons .= "<a href='index.php?act=delete_customer&id={$tmp_customer->id}' class='delete-btn'><i class='bi bi-trash'></i>Löschen</a>";
        }

        $all_rows .= "<tr>
            <td>{$tmp_customer->id}</td>
            <td>{$tmp_customer->company_name}</td>
            <td>{$tmp_customer->contact_person}</td>
            <td>{$tmp_customer->email}</td>
            <td>{$tmp_customer->phone}</td>
            <td>{$tmp_customer->street}</td>
            <td>{$tmp_customer->house_number}</td>
            <td>{$tmp_customer->postal_code}</td>
            <td>{$tmp_customer->location}</td>
            <td class='actions'>{$buttons}</td>
        </tr>";
    }

    // Admins können neue Kunden hinzufügen
    $new_customer_button = $is_admin ? "<a href='index.php?act=manage_customer' class='edit-btn'>➕ Neuen Kunden hinzufügen</a>" : "";
    // Übersichtsbuttons
    $view_all_hardware_button = "<a href='index.php?act=list_hardware' class='edit-btn'>🔍 Hardwareübersicht</a>";
    $view_all_software_button = "<a href='index.php?act=list_software' class='edit-btn'>🔍 Softwareübersicht</a>";
    $view_all_license_button  = "<a href='index.php?act=list_license'  class='edit-btn'>🔍 Lizenzübersicht</a>";
    $view_all_employee_button = "<a href='index.php?act=list_employee' class='edit-btn'>👥 Alle Mitarbeiter verwalten</a>";

    // Platzhalter ersetzen
    $table_html = file_get_contents("view/list_customer.html");
    $out = str_replace("###CUSTOMER_ROWS###", $all_rows, $table_html);
    $out = str_replace("###NEW_CUSTOMER_BUTTON###", $new_customer_button, $out);
    $out = str_replace("###USERNAME###", $username, $out);
    $out = str_replace("###SEARCH###", htmlspecialchars($search, ENT_QUOTES, 'UTF-8'), $out);
    $out = str_replace("###VIEW_ALL_HARDWARE_BUTTON###", $view_all_hardware_button, $out);
    $out = str_replace("###VIEW_ALL_SOFTWARE_BUTTON###", $view_all_software_button, $out);
    $out = str_replace("###VIEW_ALL_LICENSE_BUTTON###", $view_all_license_button, $out);
    $out = str_replace("###VIEW_ALL_EMPLOYEE_BUTTON###", $view_all_employee_button, $out);

    output($out);
}

// Kunde hinzufügen oder bearbeiten
function act_manage_customer()
{
    // Zugriffskontrolle:
    $db = new DB();
    $is_admin = ($_SESSION['user_role'] == 1);
    $is_support = ($_SESSION['user_role'] == 3);
    $customer_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

    // Prüfen, ob Support Zugriff hat
    $has_access = $is_admin || ($is_support && $db->fetchOne("SELECT * FROM employee2customer WHERE customer_id = ? AND employee_id = ?", [$customer_id, $_SESSION['user_id']]));

    if (!$has_access) {
        act_error("access_denied");
        exit();
    }

    // Kunden-ID prüfen
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    $customer = ($id > 0) ? new Customer($id) : new Customer();

    if ($id > 0 && !$customer->id) {
        act_error("customer_not_found");
        exit();
    }

    // Falls das Formular gesendet wurde, speichere die Daten
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send'])) {
        $customer->company_name   = htmlspecialchars(trim($_POST['company_name']));
        $customer->contact_person = htmlspecialchars(trim($_POST['contact_person']));
        $customer->email          = filter_var($_POST['email'], FILTER_VALIDATE_EMAIL) ? $_POST['email'] : null;
        $customer->phone          = htmlspecialchars(trim($_POST['phone']));
        $customer->street         = htmlspecialchars(trim($_POST['street']));
        $customer->house_number   = htmlspecialchars(trim($_POST['house_number']));
        $customer->postal_code    = htmlspecialchars(trim($_POST['postal_code']));
        $customer->location       = htmlspecialchars(trim($_POST['location']));

        if (!$customer->email) {
            act_error("invalid_email");
            exit();
        }

        $customer->save();

        // Mitarbeiter-Zuordnung aktualisieren
        $selected_employees = isset($_POST['employee_ids']) ? $_POST['employee_ids'] : [];
        $db->execute("DELETE FROM employee2customer WHERE customer_id = ?", [$customer->id]);
        foreach ($selected_employees as $employee_id) {
            $db->execute("INSERT INTO employee2customer (customer_id, employee_id) VALUES (?, ?)", [$customer->id, intval($employee_id)]);
        }

        // Hardware-Zuordnung aktualisieren
        $selected_hardware = isset($_POST['hardware_ids']) ? $_POST['hardware_ids'] : [];
        $db->execute("DELETE FROM customer2hardware WHERE customer_id = ?", [$customer->id]);
        foreach ($selected_hardware as $hardware_id) {
            $db->execute("INSERT INTO customer2hardware (customer_id, hardware_id) VALUES (?, ?)", [$customer->id, intval($hardware_id)]);
        }

        // Software-Zuordnung aktualisieren
        $selected_software = isset($_POST['software_ids']) ? $_POST['software_ids'] : [];
        $db->execute("DELETE FROM customer2software WHERE customer_id = ?", [$customer->id]);
        foreach ($selected_software as $software_id) {
            $db->execute("INSERT INTO customer2software (customer_id, software_id) VALUES (?, ?)", [$customer->id, intval($software_id)]);
        }

        // Logging
        if ($is_admin || $is_support) {
            $action = "Kunde '{$customer->company_name}' (ID: {$customer->id}) wurde von User ID {$_SESSION['user_id']} gespeichert/aktualisiert.";
            $db->logAction($_SESSION['user_id'], $action);
        }

        // Weiterleitung zur Kundenliste
        header("Location: index.php?act=list_customer");
        exit();
    }

    // Dropdown für Mitarbeiter
    $all_employees = $db->fetchAll("SELECT id, first_name, last_name FROM employee ORDER BY last_name ASC");
    $linked_employees = $db->fetchAll("SELECT employee_id FROM employee2customer WHERE customer_id = ?", [$customer->id]);

    $employee_options = "";
    foreach ($all_employees as $employee) {
        $selected = in_array($employee['id'], array_column($linked_employees, 'employee_id')) ? "selected" : "";
        $employee_options .= "<option value='{$employee['id']}' $selected>{$employee['last_name']}, {$employee['first_name']}</option>";
    }

    // Dropdown für Hardware
    $all_hardware = $db->fetchAll("SELECT id, type, manufacturer, model FROM hardware ORDER BY manufacturer ASC");
    $linked_hardware = $db->fetchAll("SELECT hardware_id FROM customer2hardware WHERE customer_id = ?", [$customer->id]);

    $hardware_options = "";
    foreach ($all_hardware as $hardware) {
        $selected = in_array($hardware['id'], array_column($linked_hardware, 'hardware_id')) ? "selected" : "";
        $hardware_options .= "<option value='{$hardware['id']}' $selected>{$hardware['manufacturer']} - {$hardware['model']} ({$hardware['type']})</option>";
    }

    // Dropdown für Software
    $all_software = $db->fetchAll("SELECT id, software_name FROM software ORDER BY software_name ASC");
    $linked_software = $db->fetchAll("SELECT software_id FROM customer2software WHERE customer_id = ?", [$customer->id]);

    $software_options = "";
    foreach ($all_software as $software) {
        $selected = in_array($software['id'], array_column($linked_software, 'software_id')) ? "selected" : "";
        $software_options .= "<option value='{$software['id']}' $selected>{$software['software_name']}</option>";
    }

    // Platzhalter ersetzen
    $out = file_get_contents("view/manage_customer.html");
    $out = str_replace("###id###", $customer->id ?? "", $out);
    $out = str_replace("###company_name###", htmlspecialchars($customer->company_name ?? "", ENT_QUOTES, 'UTF-8'), $out);
    $out = str_replace("###contact_person###", htmlspecialchars($customer->contact_person ?? "", ENT_QUOTES, 'UTF-8'), $out);
    $out = str_replace("###email###", htmlspecialchars($customer->email ?? "", ENT_QUOTES, 'UTF-8'), $out);
    $out = str_replace("###phone###", htmlspecialchars($customer->phone ?? "", ENT_QUOTES, 'UTF-8'), $out);
    $out = str_replace("###street###", htmlspecialchars($customer->street ?? "", ENT_QUOTES, 'UTF-8'), $out);
    $out = str_replace("###house_number###", htmlspecialchars($customer->house_number ?? "", ENT_QUOTES, 'UTF-8'), $out);
    $out = str_replace("###postal_code###", htmlspecialchars($customer->postal_code ?? "", ENT_QUOTES, 'UTF-8'), $out);
    $out = str_replace("###location###", htmlspecialchars($customer->location ?? "", ENT_QUOTES, 'UTF-8'), $out);
    $out = str_replace("###EMPLOYEE_OPTIONS###", $employee_options, $out);
    $out = str_replace("###HARDWARE_OPTIONS###", $hardware_options, $out);
    $out = str_replace("###SOFTWARE_OPTIONS###", $software_options, $out);

    output($out);
}

// Kunde löschen
function act_delete_customer()
{
    // Zugriffskontrolle:
    if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 1) {
        die("Zugriff verweigert! (Kein Admin)");
    }

    // ID-Validierung
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if ($id <= 0) {
        die("Ungültige Kunden-ID!");
    }

    // Datenbankverbindung
    $db = new DB();

    // Kundenobjekt erstellen
    $customer = new Customer($id);
    if (!$customer->id) {
        die("Kunde nicht gefunden!");
    }

    // Prüfen, ob der Kunde mit Hardware oder Software verknüpft ist
    $linkedHardware = $db->fetchOne("SELECT COUNT(*) as count FROM customer2hardware WHERE customer_id = ?", [$id])['count'];
    $linkedSoftware = $db->fetchOne("SELECT COUNT(*) as count FROM customer2software WHERE customer_id = ?", [$id])['count'];

    if ($linkedHardware > 0 || $linkedSoftware > 0) {
        die("Kunde hat noch verknüpfte Hardware oder Software!");
    }

    // Logging der Löschung
    $action = "Kunde '{$customer->company_name}' (ID: {$customer->id}) wurde von User ID {$_SESSION['user_id']} gelöscht.";
    $db->logAction($_SESSION['user_id'], $action);

    // Kunde löschen
    $customer->delete();

    // Weiterleitung zur Kundenliste
    header("Location: index.php?act=list_customer");
    exit();
}

// Mitarbeiter-Management
// Liste aller Mitarbeiter anzeigen
function act_list_employee()
{
    if (!isset($_SESSION['user_id'])) {
        act_error("access_denied");
        exit();
    }

    $db = new DB();
    $all_employee_data = $db->fetchAll("
        SELECT e.id, e.first_name, e.last_name, e.email, e.user_roles_id,
            GROUP_CONCAT(DISTINCT c.company_name SEPARATOR ', ') AS assigned_customers
        FROM employee e
        LEFT JOIN employee2customer e2c ON e.id = e2c.employee_id
        LEFT JOIN customer c ON e2c.customer_id = c.id
        GROUP BY e.id
    ");

    $table_html = file_get_contents("view/list_employee.html");
    $all_rows = "";
    $is_admin = ($_SESSION['user_role'] == 1);
    $username = $_SESSION['first_name'] ?? "Unbekannt";

    if (empty($all_employee_data)) {
        $all_rows = "<tr><td colspan='7' class='text-center'>Keine Mitarbeiter gefunden</td></tr>";
    } else {
        foreach ($all_employee_data as $employee_data) {
            $customer_names = htmlspecialchars($employee_data['assigned_customers'] ?? "Kein Kunde", ENT_QUOTES, 'UTF-8');

            // Aktionen für Admins
            $buttons = "";
            if ($is_admin) {
                $buttons .= "<a href='index.php?act=manage_employee&id={$employee_data['id']}' class='edit-btn'>Bearbeiten</a>";
                $buttons .= "<a href='index.php?act=delete_employee&id={$employee_data['id']}' class='delete-btn'>Löschen</a>";
                $buttons .= "<a href='index.php?act=list_employee_logs&employee_id={$employee_data['id']}' class='log-btn'>🔍 Logs</a>";
            }

            $all_rows .= "<tr>
                <td>{$employee_data['id']}</td>
                <td>{$employee_data['first_name']}</td>
                <td>{$employee_data['last_name']}</td>
                <td>{$employee_data['email']}</td>
                <td>{$employee_data['user_roles_id']}</td>
                <td>{$customer_names}</td>
                <td class='actions'>{$buttons}</td>
            </tr>";
        }
    }

    $customer_info = "Alle Mitarbeiter";
    $new_employee_button = $is_admin ? "<a href='index.php?act=manage_employee' class='edit-btn'>➕ Neuen Mitarbeiter hinzufügen</a>" : "";
    $back_button = "<a href='index.php?act=list_customer' class='back-btn'>← Zur Kundenübersicht</a><br>";

    // Platzhalter ersetzen
    $out = str_replace("###EMPLOYEE_ROWS###", $all_rows, $table_html);
    $out = str_replace("###NEW_EMPLOYEE_BUTTON###", $new_employee_button, $out);
    $out = str_replace("###CUSTOMER_INFO###", $customer_info, $out);
    $out = str_replace("###USERNAME###", htmlspecialchars($username, ENT_QUOTES, 'UTF-8'), $out);
    $out = str_replace("###BACK_BUTTON###", $back_button, $out);

    output($out);
}

// Nur die Mitarbeiter, die mit einem bestimmten Kunden verknüpft sind
function act_list_customer_employees()
{
    if (!isset($_SESSION['user_id'])) {
        act_error("access_denied");
        exit();
    }

    $is_admin = ($_SESSION['user_role'] == 1);
    $is_support = ($_SESSION['user_role'] == 3);
    if (!$is_admin && !$is_support) {
        act_error("access_denied");
        exit();
    }

    $customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
    if ($customer_id <= 0) {
        act_error("invalid_customer_id");
        exit();
    }

    $db = new DB();
    $customer = new Customer($customer_id);
    if (!$customer->id) {
        act_error("customer_not_found");
        exit();
    }

    $customer_info = htmlspecialchars($customer->company_name ?? "Unbekannter Kunde", ENT_QUOTES, 'UTF-8');

    // Mitarbeiter des Kunden abrufen
    $all_employees = $db->fetchAll("
        SELECT e.id, e.first_name, e.last_name, e.email, e.user_roles_id,
            GROUP_CONCAT(DISTINCT c.company_name SEPARATOR ', ') AS assigned_customers
        FROM employee e
        JOIN employee2customer e2c ON e.id = e2c.employee_id
        JOIN customer c ON e2c.customer_id = c.id
        WHERE e2c.customer_id = ?
        GROUP BY e.id", 
        [$customer_id]
    );

    $table_html = file_get_contents("view/list_employee.html");
    $all_rows = "";
    $username = $_SESSION['first_name'] ?? "Unbekannt";

    if (empty($all_employees)) {
        $all_rows = "<tr><td colspan='7' class='text-center'>Keine Mitarbeiter gefunden</td></tr>";
    } else {
        foreach ($all_employees as $employee_data) {
            $customer_names = htmlspecialchars($employee_data['assigned_customers'] ?? "Kein Kunde", ENT_QUOTES, 'UTF-8');

            // Logs-Button anzeigen, aber für Support nur bei sich selbst
            $show_logs_button = $is_admin || ($_SESSION['user_id'] == $employee_data['id']);

            $buttons = "";
            if ($is_admin) {
                $buttons .= "<a href='index.php?act=manage_employee&id={$employee_data['id']}' class='edit-btn'><i class='bi bi-pencil-square'></i>Bearbeiten</a>";
                $buttons .= "<a href='index.php?act=delete_employee&id={$employee_data['id']}' class='delete-btn'><i class='bi bi-trash'></i>Löschen</a>";
            }

            if ($show_logs_button) {
                $buttons .= "<a href='index.php?act=list_employee_logs&employee_id={$employee_data['id']}' class='log-btn'>📜 Logs ansehen</a>";
            }

            if ($_SESSION['user_id'] == $employee_data['id']) {
                $buttons .= "<a href='index.php?act=change_password' class='btn-warning'>🔑 Passwort ändern</a> ";
            }

            $all_rows .= "<tr>
                <td>{$employee_data['id']}</td>
                <td>{$employee_data['first_name']}</td>
                <td>{$employee_data['last_name']}</td>
                <td>{$employee_data['email']}</td>
                <td>{$employee_data['user_roles_id']}</td>
                <td>{$customer_names}</td>
                <td class='actions'>{$buttons}</td> 
            </tr>";
        }
    }

    $back_button = "<a href='index.php?act=list_customer' class='back-btn'>← Zur Kundenliste</a>";
    $new_employee_button = $is_admin ? "<a href='index.php?act=manage_employee&customer_id=$customer_id' class='edit-btn'>➕ Neuen Mitarbeiter hinzufügen</a>" : "";
    
    $out = str_replace("###USERNAME###", htmlspecialchars($username, ENT_QUOTES, 'UTF-8'), $table_html);
    $out = str_replace("###CUSTOMER_INFO###", $customer_info, $out);
    $out = str_replace("###EMPLOYEE_ROWS###", $all_rows, $out);
    $out = str_replace("###NEW_EMPLOYEE_BUTTON###", $new_employee_button, $out);
    $out = str_replace("###BACK_BUTTON###", $back_button, $out);

    output($out);
}

// Mitarbeiter hinzufügen oder bearbeiten
function act_manage_employee()
{
    // Zugriffskontrolle:
    $user_id = $_SESSION['user_id'];
    $user_role = $_SESSION['user_role'];
    $is_admin = ($_SESSION['user_role'] == 1);
    $is_support = ($_SESSION['user_role'] == 3);
    
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    $editing_self = ($id == $user_id);

    if (!$is_admin && !$editing_self) {
        act_error("access_denied");
        exit();
    }

    $employee = ($id > 0) ? new Employee($id) : new Employee();

    if ($id > 0 && !$employee->id) {
        act_error("employee_not_found");
        exit();
    }

    $db = new DB();

    // Erste Kunden-ID des Mitarbeiters ermitteln
    $customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
    
    if ($customer_id == 0 && $employee->id > 0) {
        $linked_customers = $db->fetchAll("SELECT customer_id FROM employee2customer WHERE employee_id = ?", [$employee->id]);
        $linked_customer_ids = array_column($linked_customers, 'customer_id'); // Mehrere IDs holen       
    }

    // Falls das Formular gesendet wurde, Daten speichern
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send'])) {
        if ($is_admin || $editing_self) {
            $employee->id = isset($_POST['id']) ? intval($_POST['id']) : 0;
            $employee->first_name = htmlspecialchars(trim($_POST['first_name']), ENT_QUOTES, 'UTF-8');
            $employee->last_name = htmlspecialchars(trim($_POST['last_name']), ENT_QUOTES, 'UTF-8');
            $employee->email = filter_var($_POST['email'], FILTER_VALIDATE_EMAIL) ? $_POST['email'] : null;
            $employee->user_roles_id = intval($_POST['user_roles_id']);

            // Passwort-Handling mit Sicherheitsprüfung
            if ($is_admin) {
                $employee->user_roles_id = intval($_POST['user_roles_id']);
            }

            // Passwort-Handling nur für sich selbst oder Admin
            if (!empty($_POST['password'])) {
                $password = $_POST['password'];
                if (!validatePassword($password)) {
                    act_error("invalid_password", "index.php?act=manage_employee&id=" . $employee->id);
                    exit();
                }
                $employee->password = password_hash($password, PASSWORD_DEFAULT);
            } else {
                $existing_password = $db->fetchOne("SELECT password FROM employee WHERE id = ?", [$employee->id]);
                if ($existing_password) {
                    $employee->password = $existing_password['password'];
                }
            }

            if (!$employee->email) {
                act_error("invalid_email");
                exit();
            }

            $employee->save();

            // Kunden-Zuweisung aktualisieren
            $selected_customers = isset($_POST['customer_ids']) ? $_POST['customer_ids'] : [];

            // Alte Verknüpfungen entfernen
            $db->execute("DELETE FROM employee2customer WHERE employee_id = ?", [$employee->id]);

            // Neue Verknüpfungen setzen
            if (!empty($selected_customers)) {
                foreach ($selected_customers as $customer_id) {
                    $db->execute("INSERT INTO employee2customer (customer_id, employee_id) VALUES (?, ?)", [$customer_id, $employee->id]);
                }
            }

            // Kunden-Zuweisung nur für Admins
            if ($is_admin) {
                $selected_customers = isset($_POST['customer_ids']) ? $_POST['customer_ids'] : [];
                $db->execute("DELETE FROM employee2customer WHERE employee_id = ?", [$employee->id]);

                if (!empty($selected_customers)) {
                    foreach ($selected_customers as $customer_id) {
                        $db->execute("INSERT INTO employee2customer (customer_id, employee_id) VALUES (?, ?)", [$customer_id, $employee->id]);
                    }
                }
            }

            // Logging der Aktion
            $action = ($id > 0)
                ? "Mitarbeiter '{$employee->first_name} {$employee->last_name}' (ID: {$employee->id}) wurde von User ID {$_SESSION['user_id']} aktualisiert."
                : "Mitarbeiter '{$employee->first_name} {$employee->last_name}' wurde von User ID {$_SESSION['user_id']} neu angelegt.";
            $db->logAction($_SESSION['user_id'], $action);

            // Weiterleitung zur Kunden-Mitarbeiterliste
            if (!empty($selected_customers)) {
                header("Location: index.php?act=list_customer_employees&customer_id=" . $selected_customers[0]);
            } else {
                header("Location: index.php?act=list_employee");
            }
            exit();
        }
    }

    // Dropdown für Kunden generieren
    $all_customers = $db->fetchAll("SELECT id, company_name FROM customer");
    if ($employee->id > 0) {
        // Lade Kunden aus der Datenbank, falls der Mitarbeiter bereits existiert
        $linked_customers = $db->fetchAll("SELECT customer_id FROM employee2customer WHERE employee_id = ?", [$employee->id]);
        $linked_customer_ids = array_column($linked_customers, 'customer_id');
    } else {
        // Falls neuer Mitarbeiter, nutze customer_id aus GET-Parametern
        $linked_customer_ids = isset($_GET['customer_id']) ? [(int) $_GET['customer_id']] : [];
    }  

    $customer_options = "";
    foreach ($all_customers as $customer) {
        $selected = (!empty($linked_customer_ids) && in_array($customer['id'], $linked_customer_ids)) ? "selected" : "";
        $customer_options .= "<option value='{$customer['id']}' " . 
            (in_array($customer['id'], $linked_customer_ids) ? "selected" : "") . 
            ">{$customer['company_name']}</option>";
    }

    // Dropdown für Rollen generieren
    $roles = Employee::getAllRoles();
    $role_options = "";
    foreach ($roles as $role) {
        $selected = ($employee->user_roles_id == $role['id']) ? "selected" : "";
        $role_options .= "<option value='{$role['id']}' $selected>{$role['role']}</option>";
    }

    $back_link = (!empty($linked_customer_ids) && $linked_customer_ids[0] > 0) 
    ? "index.php?act=list_customer_employees&customer_id=" . $linked_customer_ids[0]
    : "index.php?act=list_employee";

    // Platzhalter ersetzen
    $out = file_get_contents("view/manage_employee.html");
    $out = str_replace("###ID###", $employee->id ?? "", $out);
    $out = str_replace("###FIRST_NAME###", htmlspecialchars($employee->first_name ?? "", ENT_QUOTES, 'UTF-8'), $out);
    $out = str_replace("###LAST_NAME###", htmlspecialchars($employee->last_name ?? "", ENT_QUOTES, 'UTF-8'), $out);
    $out = str_replace("###EMAIL###", htmlspecialchars($employee->email ?? "", ENT_QUOTES, 'UTF-8'), $out);
    $out = str_replace("###ROLE_OPTIONS###", $role_options, $out);
    $out = str_replace("###CUSTOMER_OPTIONS###", $customer_options, $out);
    $out = str_replace("###CUSTOMER_ID###", $customer_id, $out);
    $out = str_replace("###BACK_LINK###", $back_link, $out);
    $out = str_replace("###PASSWORD_FIELD###", ($editing_self ? '<label for="password">Neues Passwort:</label><input type="password" id="password" name="password">' : ""), $out);

    output($out);
}

// Mitarbeiter löschen
function act_delete_employee()
{
    // Zugriffskontrolle: Nur Admins dürfen löschen
    if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 1) {
        act_error("access_denied");
        exit();
    }

    // Mitarbeiter-ID prüfen
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;

    if ($id <= 0) {
        act_error("invalid_id");
        exit();
    }

    // Kunden-ID ermitteln, falls vorhanden
    $db = new DB();
    $customer_query = $db->fetchOne("SELECT customer_id FROM employee2customer WHERE employee_id = ?", [$id]);
    $customer_id = $customer_query ? intval($customer_query['customer_id']) : null;

    // Mitarbeiter prüfen
    $employee = new Employee($id);
    if (!$employee->id) {
        act_error("employee_not_found");
        exit();
    }

    // Mitarbeiter-Name für Logging sichern
    $employee_name = "{$employee->first_name} {$employee->last_name}";

    // Verknüpfungen löschen, bevor der Mitarbeiter entfernt wird
    $db->execute("DELETE FROM employee2customer WHERE employee_id = ?", [$id]);

    // Mitarbeiter löschen
    $employee->delete();

    // Logging der Löschung
    $action = "Mitarbeiter '{$employee_name}' (ID: {$id}) wurde von User ID {$_SESSION['user_id']} gelöscht.";
    $db->logAction($_SESSION['user_id'], $action);

    // Nach dem Löschen zur richtigen Liste weiterleiten
    if ($customer_id) {
        header("Location: index.php?act=list_customer_employees&customer_id=$customer_id");
    } else {
        header("Location: index.php?act=list_employee");
    }
    exit();
}

// Sonstiges
// Fehlerseite
function act_error($msg_key = "unknown", $back_url = "index.php")
{
    $error_messages = [
        "access_denied" => "Du hast keine Berechtigung, auf diese Seite zuzugreifen.",
        "hardware_not_found" => "Die gesuchte Hardware wurde nicht gefunden.",
        "software_not_found" => "Die gesuchte Software wurde nicht gefunden.",
        "customer_not_found" => "Der Kunde wurde nicht gefunden.",
        "employee_not_found" => "Der Mitarbeiter wurde nicht gefunden.",
        "invalid_password" => "Das Passwort ist nicht sicher genug! Es muss mindestens 8 Zeichen lang sein, eine Zahl, einen Großbuchstaben, einen Kleinbuchstaben und ein Sonderzeichen enthalten.",
        "invalid_email" => "Die eingegebene E-Mail-Adresse ist ungültig.",
        "customer_relation" => "Die Kundenverknüpfung existiert bereits!",
        "unknown" => "Ein unbekannter Fehler ist aufgetreten."
    ];

    // Falls keine direkte Parameterübergabe, prüfe GET-Parameter
    if ($msg_key === "unknown" && isset($_GET['msg'])) {
        $msg_key = $_GET['msg'];
    }

    // Standard-Fehlermeldung verwenden, falls der Key nicht existiert
    $error_msg = isset($error_messages[$msg_key]) ? $error_messages[$msg_key] : $error_messages["unknown"];

    // HTML-Fehlerseite laden
    $error_page = file_get_contents("view/error.html");
    $output = str_replace("###ERROR_MESSAGE###", htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'), $error_page);
    $output = str_replace("###BACK_LINK###", htmlspecialchars($back_url, ENT_QUOTES, 'UTF-8'), $output);

    output($output);
}

// Hilfsfunktion zum Abrufen von GET-/POST-Werten
function g($assoc_index)
{
    return $_REQUEST[$assoc_index] ?? null;
}
?>

<!-- SweetAlert2 lokal -->
<link rel="stylesheet" href="libs/sweetalert2/sweetalert2.min.css">
<script src="libs/sweetalert2/sweetalert2.all.min.js"></script>

<!-- Eigenes JavaScript -->
<script src="js/main.js"></script>
