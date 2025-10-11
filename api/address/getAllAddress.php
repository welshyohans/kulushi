<?php

include_once '../../config/Database.php';
include_once '../../model/Address.php';


$database = new Database();

$db = $database->connect();

$address = new Address($db);

//hello welsh welcome to php
echo json_encode($address->getAllAddress());


?>