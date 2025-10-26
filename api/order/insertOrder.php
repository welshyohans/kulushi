<?php
// SET HEADER
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: access");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// INCLUDING DATABASE AND MAKING OBJECT
include_once '../../config/Database.php';
include_once '../../model/Orders.php';
include_once '../../model/OrderedList.php';

$db_connection = new Database();
$conn = $db_connection->getConnection();

// GET DATA FROM REQUEST
$data = json_decode(file_get_contents("php://input"));

// MAIN RECIEVEING AND PROCESSING ORDER
// CHECK IF RECEIVED DATA IS NOT EMPTY
if (
    !empty($data-&gt;customer_id) &amp;&amp;
    !empty($data-&gt;total_price) &amp;&amp;
    !empty($data-&gt;profit) &amp;&amp;
    !empty($data-&gt;available_credit) &amp;&amp;
    !empty($data-&gt;deliver_status) &amp;&amp;
    !empty($data-&gt;comment) &amp;&amp;
    !empty($data-&gt;ordered_list)
) {
    // CREATE ORDER
    $order = new Orders($conn);
    $order-&gt;customer_id = $data-&gt;customer_id;
    $order-&gt;total_price = $data-&gt;total_price;
    $order-&gt;profit = $data-&gt;profit;
    $order-&gt;available_credit = $data-&gt;available_credit;
    $order-&gt;deliver_status = $data-&gt;deliver_status;
    $order-&gt;comment = $data-&gt;comment;

    if ($order-&gt;create()) {
        $order_id = $conn-&gt;lastInsertId();

        // CREATE ORDERED LIST
        $ordered_list = new OrderedList($conn);
        foreach ($data-&gt;ordered_list as $item) {
            $ordered_list-&gt;orders_id = $order_id;
            $ordered_list-&gt;supplier_goods_id = $item-&gt;supplier_goods_id;
            $ordered_list-&gt;quantity = $item-&gt;quantity;
            $ordered_list-&gt;each_price = $item-&gt;each_price;
            $ordered_list-&gt;status = $item-&gt;status;
            if (!$ordered_list-&gt;create()) {
                //http_response_code(500);
                echo json_encode(array('message' =&gt; 'Error creating ordered list'));
                return;
            }
        }

        echo json_encode(
            array(
                'message' =&gt; 'Order created successfully',
                'order_id' =&gt; $order_id
            )
        );
    } else {
        //http_response_code(500);
        echo json_encode(array('message' =&gt; 'Error creating order'));
    }
} else {
    //http_response_code(400);
    echo json_encode(array('message' =&gt; 'Bad request'));
}


?>