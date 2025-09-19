<?php
class Database {
    private $host = "pg4.sweb.ru";
    private $port = "5433";
    private $db_name = "bananaali4";
    private $username = "bananaali4";
    private $password = "#XF5wY6ACULDTDXN";
    public $conn;

    public function getConnection() {
        $this->conn = null;
        try {
            $dsn = "pgsql:host=" . $this->host . ";port=" . $this->port . ";dbname=" . $this->db_name;
            $this->conn = new PDO($dsn, $this->username, $this->password);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->exec("SET NAMES 'UTF8'");
        } catch(PDOException $exception) {
            error_log("Ошибка подключения: " . $exception->getMessage());
            echo "В настоящее время ведутся технические работы. Пожалуйста, попробуйте позже.";
        }
        return $this->conn;
    }
}
?>