<?php

class OrderedList
{
    // DB STUFF
    private $conn;
    private $table = 'ordered_list';

    // PROPERTIES
    public $id;
    public $orders_id;
    public $supplier_goods_id;
    public $quantity;
    public $each_price;
    public $status;

    // CONSTRUCTOR
    public function __construct($db)
    {
        $this->conn = $db;
    }

    // CREATE ORDERED LIST
    public function create()
    {
        // QUERY
        $query = 'INSERT INTO ' . $this->table . '
        SET
        orders_id = :orders_id,
        supplier_goods_id = :supplier_goods_id,
        quantity = :quantity,
        each_price = :each_price,
        status = :status';

        // PREPARE STATEMENT
        $stmt = $this->conn->prepare($query);

        // CLEANING DATA
        $this->orders_id = htmlspecialchars(strip_tags($this->orders_id));
        $this->supplier_goods_id = htmlspecialchars(strip_tags($this->supplier_goods_id));
        $this->quantity = htmlspecialchars(strip_tags($this->quantity));
        $this->each_price = htmlspecialchars(strip_tags($this->each_price));
        $this->status = htmlspecialchars(strip_tags($this->status));

        // BINDING
        $stmt->bindParam(':orders_id', $this->orders_id);
        $stmt->bindParam(':supplier_goods_id', $this->supplier_goods_id);
        $stmt->bindParam(':quantity', $this->quantity);
        $stmt->bindParam(':each_price', $this->each_price);
        $stmt->bindParam(':status', $this->status);

        // EXECUTE
        if ($stmt->execute()) {
            return true;
        }

        printf('Error creating ordered list: %s. \n', $stmt->error);
        return false;
    }
}
?>