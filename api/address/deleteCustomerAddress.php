<?php
// Headers
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Access-Control-Allow-Headers,Content-Type,Access-Control-Allow-Methods, Authorization, X-Requested-With');

include_once '../../config/Database.php';
include_once '../../model/Address.php';

// Instantiate DB & connect
$database = new Database();
$db = $database->connect();

// Instantiate address object
$address = new Address($db);

// Get raw posted data
$data = json_decode(file_get_contents("php://input"));

if(isset($data->customerId) && isset($data->addressId)) {
    // Set ID to delete
    $address->customer_id = $data->customerId;
    $address->address_id = $data->addressId;

    // Delete post
    if($address->deleteCustomerAddress()) {
        echo json_encode(
            array(
                'success' => true,
                'message' => 'Customer Address Deleted'
            )
        );
    } else {
        echo json_encode(
            array(
                'success' => false,
                'message' => 'Customer Address Not Deleted'
            )
        );
    }
} else {
    echo json_encode(
        array(
            'success' => false,
            'message' => 'customerId and addressId are required.'
        )
    );
}