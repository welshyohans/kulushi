<?php 

require_once __DIR__ . '/../load_env.php';
loadEnv(__DIR__ . '/../.env');

// Access variables like:
$host = getenv('DB_HOST');
$user = getenv('DB_USER'); // Corrected from USER_NAME to be more conventional
$pass = getenv('DB_PASS'); // Corrected from PASSWORD
$name = getenv('DB_NAME');



class Database{

    private $host;
    private $db_name;
    private $username;
    private $password;

    private $conn;

    public function __construct(){
        $this->host = getenv('DB_HOST');
        $this->db_name = getenv('DB_NAME');
        $this->username = getenv('DB_USER');
        $this->password = getenv('DB_PASS');
    }

    public function connect(){
        $this->conn = null; // Ensure we start with a fresh connection
        try{
            $dsn="mysql:host=".$this->host.";dbname=".$this->db_name.";charset=utf8";
            $this->conn = new PDO($dsn,$this->username,$this->password);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }catch(PDOException $e){
            // Leave error handling to callers so APIs can return clean JSON.
            $this->conn = null;
        }
        return $this->conn;

    }

}


?>
