<?php
require_once 'DB.class.php';

class Hardware {
    private $db;
    public $id;
    public $type;
    public $manufacturer;
    public $model;
    public $serial_number;
    public $status;
    public $created_at;
    public $updated_at;

    public function __construct($id = 0) {
        $this->db = new DB();
        if ($id > 0) {
            $this->load($id);
        }
    }

    // Hardware-Asset aus der DB laden
    public function load($id) {
        $data = $this->db->fetchOne("SELECT * FROM hardware WHERE id = ?", [$id]);
        if ($data) {
            $this->id = $data['id'];
            $this->type = $data['type'];
            $this->manufacturer = $data['manufacturer'];
            $this->model = $data['model'];
            $this->serial_number = $data['serial_number'];
            $this->status = $data['status'];
            $this->created_at = $data['created_at'];
            $this->updated_at = $data['updated_at'];
        } else {
            throw new Exception("Fehler: Hardware mit ID $id nicht gefunden!");
        }
    }

    // Neues Hardware-Asset speichern oder aktualisieren
    public function save($customer_id = null) {
        $db = new DB();
        if ($this->id > 0) {
            // Bestehende Hardware aktualisieren
            $sql = "UPDATE hardware SET type = ?, manufacturer = ?, model = ?, serial_number = ?, status = ?, updated_at = NOW() WHERE id = ?";
            $params = [$this->type, $this->manufacturer, $this->model, $this->serial_number, $this->status, $this->id];

            $result = $db->execute($sql, $params);
            if (!$result) {
                die("Fehler: SQL-Fehler beim UPDATE! " . DB::$connection->error);
            }

        } else {
            // Neue Hardware anlegen
            $sql = "INSERT INTO hardware (type, manufacturer, model, serial_number, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())";
            $params = [$this->type, $this->manufacturer, $this->model, $this->serial_number, $this->status];

            $result = $db->execute($sql, $params);
            if (!$result) {
                die("Fehler: SQL-Fehler beim INSERT! " . DB::$connection->error);
            }

            $this->id = $db->getLastInsertId();
        }

        // Nur wenn eine gültige `customer_id` existiert, Verknüpfung herstellen
        if ($customer_id !== null && $customer_id > 0) {

            // Prüfen, ob bereits eine Verknüpfung existiert
            $exists = $db->fetchOne("SELECT * FROM customer2hardware WHERE customer_id = ? AND hardware_id = ?", [$customer_id, $this->id]);

            if (!$exists) {
                $db->execute("INSERT INTO customer2hardware (customer_id, hardware_id) VALUES (?, ?)", [$customer_id, $this->id]);
            } else {
                act_error("customer_relation");
                exit();
            }
        } else {
            echo "Fehler: Kunden-ID ist NULL oder 0! Verknüpfung nicht möglich!<br>";
        }

        return $result;
    }
   
    // Löschen eines Hardware-Assets
    public function delete() {
        if ($this->id > 0) {
            $sql = "DELETE FROM hardware WHERE id = ?";
            return $this->db->execute($sql, [$this->id]);
        }
        return false;
    }

    // Alle Hardware-Assets abrufen
    public static function getAll($fetchDetails = false) {
        $db = new DB();
        $sql = $fetchDetails ? "SELECT * FROM hardware" : "SELECT id FROM hardware";
        $results = $db->fetchAll($sql);
    
        if (!$fetchDetails) {
            // Stelle sicher, dass es ein Array mit ['id' => ID] ist
            return array_map(fn($id) => ['id' => $id], array_column($results, 'id'));
        }
    
        return $results;
    }
    
    // Hardware nach Kunde abrufen
    public static function getByCustomer($customer_id) {
        $db = new DB();
        $hardware_list = $db->fetchAll("
            SELECT h.* 
            FROM hardware h
            JOIN customer2hardware c2h ON h.id = c2h.hardware_id
            WHERE c2h.customer_id = ?", 
            [$customer_id]
        );
    
        var_dump($hardware_list);
        echo "<br>";
    
        return $hardware_list;
    }
    
    // Zugehörige Software-Assets abrufen
    public function getSoftware() {
        $db = new DB();
        return $db->fetchAll("
            SELECT s.* FROM software s
            JOIN software2hardware sh ON s.id = sh.software_id
            WHERE sh.hardware_id = ?", [$this->id]);
    }

}
?>
