<?php


class Orders
{
    // DB STUFF
    private $conn;
    private $table = 'orders';

    // PROPERTIES
    public $id;
    public $customer_id;
    public $total_price;
    public $profit;
    public $cash_amount;
    public $credit_amount;
    public $order_time;
    public $deliver_time;
    public $deliver_status;
    public $comment;

    // CONSTRUCTOR
    public function __construct($db)
    {
        $this->conn = $db;
    }

    // CREATE ORDER
    public function create()
    {
        // QUERY
        $query = 'INSERT INTO ' . $this->table . '
        SET
        customer_id = :customer_id,
        total_price = :total_price,
        profit = :profit,
        cash_amount = :cash_amount,
        credit_amount = :credit_amount,
        deliver_status = :deliver_status,
        comment = :comment';

        // PREPARE STATEMENT
        $stmt = $this->conn->prepare($query);

        // CLEANING DATA
        $this->customer_id = htmlspecialchars(strip_tags($this->customer_id));
        $this->total_price = htmlspecialchars(strip_tags($this->total_price));
        $this->profit = htmlspecialchars(strip_tags($this->profit));
        $this->cash_amount = htmlspecialchars(strip_tags($this->cash_amount));
        $this->credit_amount = htmlspecialchars(strip_tags($this->credit_amount));
        $this->deliver_status = htmlspecialchars(strip_tags($this->deliver_status));
        $this->comment = htmlspecialchars(strip_tags($this->comment));


        // BINDING
        $stmt->bindParam(':customer_id', $this->customer_id);
        $stmt->bindParam(':total_price', $this->total_price);
        $stmt->bindParam(':profit', $this->profit);
        $stmt->bindParam(':cash_amount', $this->cash_amount);
        $stmt->bindParam(':credit_amount', $this->credit_amount);
        $stmt->bindParam(':deliver_status', $this->deliver_status);
        $stmt->bindParam(':comment', $this->comment);

        // EXECUTE
        if ($stmt->execute()) {
            return true;
        }

        printf('Error creating order: %s. \n', $stmt->error);
        return false;
    }
}

?>