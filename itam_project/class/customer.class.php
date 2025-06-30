<?php
require_once 'DB.class.php';

class Customer {
    private $db;
    public $id;
    public $company_name;
    public $contact_person;
    public $email;
    public $phone;
    public $street;
    public $house_number;
    public $postal_code;
    public $location;
    public $created_at;
    public $updated_at;

    public function __construct($id = 0) {
        $this->db = new DB();
        if ($id > 0) {
            $this->load($id);
        }
    }

    // Sichere Ladefunktion
    public function load($id) {
        $id = intval($id); // Stelle sicher, dass es eine Ganzzahl ist
        $sql = "SELECT * FROM customer WHERE id = ?";
        $data = $this->db->fetchOne($sql, [$id]); // Sicherer Parameter

        if ($data) {
            $this->id = $data['id'];
            $this->company_name = $data['company_name'];
            $this->contact_person = $data['contact_person'];
            $this->email = $data['email'];
            $this->phone = $data['phone'];
            $this->street = $data['street'];
            $this->house_number = $data['house_number'];
            $this->postal_code = $data['postal_code'];
            $this->location = $data['location'];
            $this->created_at = $data['created_at'];
            $this->updated_at = $data['updated_at'];
        }
    }

    // Sichere Speicherfunktion
    public function save() {
        if ($this->id > 0) {
            // Update bestehender Kunde
            $sql = "UPDATE customer 
                    SET company_name = ?, contact_person = ?, email = ?, phone = ?, 
                        street = ?, house_number = ?, postal_code = ?, location = ?, updated_at = NOW() 
                    WHERE id = ?";
            $this->db->execute($sql, [
                $this->company_name, $this->contact_person, $this->email, $this->phone, 
                $this->street, $this->house_number, $this->postal_code, $this->location, $this->id
            ]);
        } else {
            // Neuen Kunden anlegen
            $sql = "INSERT INTO customer 
                    (company_name, contact_person, email, phone, street, house_number, postal_code, location, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            $this->db->execute($sql, [
                $this->company_name, $this->contact_person, $this->email, $this->phone, 
                $this->street, $this->house_number, $this->postal_code, $this->location
            ]);
            $this->id = $this->db->getLastInsertId();
        }
    }

    // Sichere Löschfunktion mit Validierung
    public function delete() {
        if ($this->id > 0) {
            // Prüfen, ob Kunde existiert
            $existing = $this->db->fetchOne("SELECT id FROM customer WHERE id = ?", [$this->id]);
            if ($existing) {
                $this->db->execute("DELETE FROM customer WHERE id = ?", [$this->id]);
                echo "✅ Kunde mit ID {$this->id} wurde gelöscht.";
            } else {
                echo "❌ Kunde mit ID {$this->id} existiert nicht.";
            }
        }
    }

    // Sichere Abfrage aller Kunden-IDs
    public static function getAll() {
        $db = new DB();
        $results = $db->fetchAll("SELECT id FROM customer");
        return array_column($results, 'id');
    }
}
?>
