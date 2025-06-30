<?php

class License {
    public $id;
    public $license_name;
    public $license_key;
    public $expiry_date;
    public $status;
    public $created_at;
    public $updated_at;
    private $db;

    public function __construct($id = 0) {
        $this->db = new DB();
        if ($id > 0) {
            $this->load($id);
        }
    }

    // Lizenzen aus der DB laden
    public function load($id) {
        $license_data = $this->db->fetchOne("SELECT * FROM license WHERE id = ?", [$id]);
        if ($license_data) {
            $this->id = $license_data['id'];
            $this->license_name = $license_data['license_name'];
            $this->license_key = $license_data['license_key'];
            $this->expiry_date = $license_data['expiry_date'];
            $this->status = $license_data['status'];
            $this->created_at = $license_data['created_at'];
            $this->updated_at = $license_data['updated_at'];
        } else {
            $this->id = 0; // Falls keine Lizenz existiert
        }
    }

    // Lizenzen speichern oder aktualisieren
    public function save() {
        if ($this->id > 0) {
            // Update bestehender Lizenz-Eintrag
            $sql = "UPDATE license SET license_name = ?, license_key = ?, expiry_date = ?, status = ?, updated_at = NOW() WHERE id = ?";
            return $this->db->execute($sql, [$this->license_name, $this->license_key, $this->expiry_date, $this->status, $this->id]);
        } else {
            // Neue Lizenz anlegen
            $sql = "INSERT INTO license (license_name, license_key, expiry_date, status, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())";
            $this->db->execute($sql, [$this->license_name, $this->license_key, $this->expiry_date, $this->status]);
            $this->id = $this->db->getLastInsertId();
            return ($this->id > 0);
        }
    }

    // Alle Lizenzen abzurufen
    public static function getAll() {
        $db = new DB();
        return $db->fetchAll("SELECT * FROM license ORDER BY created_at DESC");
    }
}
?>
