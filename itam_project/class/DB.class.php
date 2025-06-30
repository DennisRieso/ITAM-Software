<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

class DB
{
    public $servername = "localhost"; 
    public $username = "root";
    public $password = "";
    public $db_name = "itam_test";
    public static $connection = null;

    public function __construct() {
        if (self::$connection == null) {
            self::$connection = new mysqli($this->servername, $this->username, $this->password, $this->db_name);
        }

        if (self::$connection->connect_error) {
            die("Fehler bei der Datenbankverbindung: " . self::$connection->connect_error);
        }
    }

    // Sichere Methode zur Parameter-Typ-Erkennung
    private function getParamTypes($params) {
        $types = "";
        foreach ($params as $param) {
            if (is_int($param)) {
                $types .= "i"; // Integer
            } elseif (is_float($param)) {
                $types .= "d"; // Double/Float
            } elseif (is_string($param)) {
                $types .= "s"; // String
            } else {
                $types .= "b"; // Blob
            }
        }
        return $types;
    }

    // Einzelnen Eintrag laden
    public function fetchOne($sql, $params = []) {
        $stmt = self::$connection->prepare($sql);
        if (!$stmt) {
            trigger_error("Fehler beim Prepare-Statement: " . self::$connection->error, E_USER_ERROR);
        }
    
        if (!empty($params)) {
            $types = $this->getParamTypes($params);
            $stmt->bind_param($types, ...$params);
        }
    
        if (!$stmt->execute()) {
            trigger_error("Fehler bei der SQL-Ausführung: " . $stmt->error, E_USER_ERROR);
        }
    
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
    
        if (!$row) {
            return []; // Statt NULL ein leeres Array zurückgeben
        }
    
        return $row;
    }
    
    // Mehrere Einträge laden
    public function fetchAll($query, $params = []) {
        $stmt = DB::$connection->prepare($query);
        if (!$stmt) {
            trigger_error("Fehler bei der SQL-Vorbereitung: " . DB::$connection->error, E_USER_ERROR);
        }

        if (!empty($params)) {
            $types = $this->getParamTypes($params);
            $stmt->bind_param($types, ...$params);
        }

        if (!$stmt->execute()) {
            trigger_error("Fehler bei der SQL-Ausführung: " . $stmt->error, E_USER_ERROR);
        }

        $result = $stmt->get_result();
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    // SQL-Befehle ausführen (INSERT, UPDATE, DELETE)
    public function execute($sql, $params = []) {
        $stmt = DB::$connection->prepare($sql);
        if (!$stmt) {
            trigger_error("Fehler bei der SQL-Vorbereitung: " . DB::$connection->error, E_USER_ERROR);
        }

        if (!empty($params)) {
            $types = $this->getParamTypes($params);
            $stmt->bind_param($types, ...$params);
        }

        if (!$stmt->execute()) {
            trigger_error("Fehler bei der SQL-Ausführung: " . $stmt->error, E_USER_ERROR);
        }

        return true;
    }

    // Letzte eingefügte ID holen
    public function getLastInsertId() {
        return DB::$connection->insert_id;
    }

    // Logging
    public function logAction($employee_id, $action) {
        $sql = "INSERT INTO logs (employee_id, action) VALUES (?, ?)";
        return $this->execute($sql, [$employee_id, $action]);
    }
    
}
?>
