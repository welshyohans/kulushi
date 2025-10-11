<?php

include_once '../../config/Database.php';
include_once '../../model/Address.php';


$database = new Database();

$db = $database->connect();

$address = new Address($db);

echo json_encode($address->getAllAddress());


?>