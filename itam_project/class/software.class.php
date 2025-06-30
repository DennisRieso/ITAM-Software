<?php
require_once 'DB.class.php';

class Software {
    private $db;
    public $id;
    public $software_name;
    public $vendor;
    public $version;
    public $created_at;
    public $updated_at;

    public function __construct($id = 0) {
        $this->db = new DB();
        if ($id > 0) {
            $this->load($id);
        }
    }

    // Software aus der Datenbank laden mit Fehlerbehandlung
    public function load($id) {
        $data = $this->db->fetchOne("SELECT * FROM software WHERE id = ?", [$id]);
        if ($data) {
            $this->id = $data['id'];
            $this->software_name = $data['software_name'];
            $this->vendor = $data['vendor'];
            $this->version = $data['version'];
            $this->created_at = $data['created_at'];
            $this->updated_at = $data['updated_at'];
        } else {
            throw new Exception("Fehler: Software mit ID $id nicht gefunden!");
        }
    }

    // Neue Software speichern oder bestehende aktualisieren
    public function save($customer_id = null, $hardware_ids = [], $license_ids = []) {
        $db = new DB();
        
        if ($this->id > 0) {
            // Software aktualisieren
            $sql = "UPDATE software SET software_name = ?, vendor = ?, version = ?, updated_at = NOW() WHERE id = ?";
            $params = [$this->software_name, $this->vendor, $this->version, $this->id];
            $db->execute($sql, $params);
        } else {
            // Neue Software anlegen
            $sql = "INSERT INTO software (software_name, vendor, version, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())";
            $params = [$this->software_name, $this->vendor, $this->version];
    
            $result = $db->execute($sql, $params);
            if (!$result) {
                die("Fehler beim Einfügen der Software! " . DB::$connection->error);
            }
    
            // Setze die ID für die neu erstellte Software
            $this->id = $db->getLastInsertId();
        }
    
        // Kunden-Verknüpfung herstellen
        if ($customer_id !== null && $customer_id > 0) {
            $exists = $db->fetchOne("SELECT 1 FROM customer2software WHERE customer_id = ? AND software_id = ?", [$customer_id, $this->id]);
            if (!$exists) {
                $db->execute("INSERT INTO customer2software (customer_id, software_id) VALUES (?, ?)", [$customer_id, $this->id]);
            } else {
                act_error("customer_relation");
                exit();
            }
        }
    
        // Hardware-Verknüpfungen aktualisieren
        $db->execute("DELETE FROM software2hardware WHERE software_id = ?", [$this->id]);
    
        foreach ($hardware_ids as $hardware_id) {
            $db->execute("INSERT INTO software2hardware (software_id, hardware_id) VALUES (?, ?)", [$this->id, intval($hardware_id)]);
        }
    
        // Lizenz-Verknüpfungen aktualisieren
        $db->execute("DELETE FROM software2license WHERE software_id = ?", [$this->id]);
    
        foreach ($license_ids as $license_id) {
            $db->execute("INSERT INTO software2license (software_id, license_id) VALUES (?, ?)", [$this->id, intval($license_id)]);
        }
    
        return true;
    }
    
    // Software löschen mit Fehlerbehandlung
    public function delete() {
        if ($this->id > 0) {
            return $this->db->execute("DELETE FROM software WHERE id = ?", [$this->id]);
        }
        return false;
    }

    // Alle Software-Einträge abrufen
    public static function getAll($fetchDetails = false) {
        $db = new DB();
        $sql = $fetchDetails ? "SELECT * FROM software" : "SELECT id FROM software";
        $results = $db->fetchAll($sql);

        return $fetchDetails ? $results : array_column($results, 'id');
    }

    // Software nach Kunde abrufen
    public static function getByCustomer($customer_id) {
        $db = new DB();
        return $db->fetchAll("
            SELECT s.* 
            FROM software s
            JOIN customer2software c2s ON s.id = c2s.software_id
            WHERE c2s.customer_id = ?", 
            [$customer_id]
        );
    }

    // Zugehörige Hardware-Assets abrufen
    public function getHardware() {
        $db = new DB();
        return $db->fetchAll("
            SELECT h.* FROM hardware h
            JOIN software2hardware sh ON h.id = sh.hardware_id
            WHERE sh.software_id = ?", [$this->id]);
    }
}
?>
