<?php

include_once '../../model/SMS.php';
include_once '../../config/Database.php';



//$userId=$data['userId'];  

$phone = '0943090921';
$body = 'Test message';





$database = new Database();

$db = $database->connect();

$s = substr($phone, 1);
$t=  '+251'.$s;

    //send sms
$sms = new SMS();
$sms->sendSms($t,$body); 



?>