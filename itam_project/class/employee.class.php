<?php
require_once 'DB.class.php';

class Employee {
    public $id;
    public $user_roles_id;
    public $first_name;
    public $last_name;
    public $email;
    public $password;
    private $db;

    public function __construct($id = 0)
    {
        $this->db = new DB();

        if ($id > 0) {
            $this->load($id);
        } else {
        }
    }

    // Benutzer aus der Datenbank laden mit Fehlerbehandlung
    public function load($id) {
        $data = $this->db->fetchOne("SELECT * FROM employee WHERE id = ?", [$id]);

        if ($data) {
            $this->id = $data['id'];
            $this->first_name = $data['first_name'];
            $this->last_name = $data['last_name'];
            $this->email = $data['email'];
            $this->user_roles_id = $data['user_roles_id'];
        } else {
            act_error("employee_not_found");
            exit();
        }
    }

    // Sichere Login-Funktion mit password_verify()
    public static function login($email, $password) {
        $db = new DB();
        $query = "SELECT * FROM employee WHERE email = ?";
        $user = $db->fetchOne($query, [$email]);
    
        if ($user) {
            // Prüfen, ob das Passwort bereits mit password_hash() gespeichert wurde
            if (password_verify($password, $user['password'])) {
                return new Employee($user['id']); // Erfolgreich angemeldet mit sicherem Hash
            }
    
            // Falls nicht, prüfen wir den alten MD5-Hash
            if ($user['password'] === md5($password)) {
                // MD5 war erfolgreich → Passwort nun mit password_hash() aktualisieren
                $new_hash = password_hash($password, PASSWORD_DEFAULT);
                $db->execute("UPDATE employee SET password = ? WHERE id = ?", [$new_hash, $user['id']]);
                return new Employee($user['id']); // Erfolgreich angemeldet mit neuem sicheren Hash
            }
        }
        return null; // Login fehlgeschlagen
    }
    
    // Sichere Passwörter speichern mit password_hash()
    public function setPassword($password) {
        $this->password = password_hash($password, PASSWORD_DEFAULT);
    }

    // Benutzer speichern oder aktualisieren
    public function save() {
        if ($this->id > 0) {
            $sql = "UPDATE employee SET first_name = ?, last_name = ?, email = ?, user_roles_id = ?, updated_at = NOW() WHERE id = ?";
            $params = [$this->first_name, $this->last_name, $this->email, $this->user_roles_id, $this->id];

            if (!empty($this->password)) {
                $sql = "UPDATE employee SET first_name = ?, last_name = ?, email = ?, user_roles_id = ?, password = ?, updated_at = NOW() WHERE id = ?";
                $params = [$this->first_name, $this->last_name, $this->email, $this->user_roles_id, $this->password, $this->id];
            }

            $this->db->execute($sql, $params);
        } else {
            $sql = "INSERT INTO employee (first_name, last_name, email, user_roles_id, password, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())";
            $this->db->execute($sql, [$this->first_name, $this->last_name, $this->email, $this->user_roles_id, $this->password]);
            $this->id = $this->db->getLastInsertId();
        }
    }

    // Mitarbeiter nach Kunde abrufen
    public static function getByCustomer($customer_id) {
        $db = new DB();
        return $db->fetchAll("
            SELECT e.id, e.first_name, e.last_name, e.email, e.user_roles_id, r.role AS role_name
            FROM employee e
            JOIN employee2customer c2e ON e.id = c2e.employee_id
            JOIN user_roles r ON e.user_roles_id = r.id
            WHERE c2e.customer_id = ?", 
            [$customer_id]
        );
    }

    // Alle Mitarbeiter abrufen
    public static function getAll() {
        $db = new DB();
        $results = $db->fetchAll("SELECT id FROM employee");
        return array_column($results, 'id');
    }

    // Alle Mitarbeiter-Rollen abrufen
    public static function getAllRoles() {
        $db = new DB();
        return $db->fetchAll("SELECT id, role FROM user_roles");
    }    

    // Löschen eines Mitarbeiters
    public function delete() {
        if ($this->id > 0) {
            $db = new DB();
            $db->execute("DELETE FROM employee WHERE id = ?", [$this->id]);
        }
    }

    // Zugehörige Kunden abrufen
    public function getCustomers() {
        $db = new DB();
        return $db->fetchAll("SELECT c.id, c.company_name FROM customer c 
                            JOIN employee2customer e2c ON c.id = e2c.customer_id 
                            WHERE e2c.employee_id = ?", 
                            [$this->id]);
    }

}
?>
