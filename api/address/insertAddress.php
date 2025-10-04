
<?php
include_once '../../config/Database.php';
include_once '../../model/Address.php';


$jsonData = file_get_contents('php://input');
$data = json_decode($jsonData, true);

$subCity = $data['addressName'];

$database = new Database();
$db = $database->connect();




$address = new Address($db);

$address->insertAddress($subCity);

include_once 'getAllAddress.php';


?>