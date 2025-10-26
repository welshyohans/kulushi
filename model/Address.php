<?php

class Address{

    private $conn;
    public function __construct($conn){
        $this->conn = $conn;
    }
    public $customer_id;
    public $address_id;

    public function deleteCustomerAddress(){
        $query = "DELETE FROM customer_address WHERE customer_id = :customer_id AND address_id = :address_id";
        
        $stmt = $this->conn->prepare($query);
        
        // Clean data
        $this->customer_id = htmlspecialchars(strip_tags($this->customer_id));
        $this->address_id = htmlspecialchars(strip_tags($this->address_id));
        
        // Bind data
        $stmt->bindParam(':customer_id', $this->customer_id);
        $stmt->bindParam(':address_id', $this->address_id);
        
        // Execute query
        if($stmt->execute()){
            return true;
        }
        
        // Print error if something goes wrong
        printf("Error: %s.\n", $stmt->error);
        
        return false;
    }
  
    public function getAllAddress(){
        $query = "SELECT * FROM address";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();

        $num = $stmt->rowCount();

        $allAddress = array();
        if($num>0){
            while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
                extract($row);
                $eachAddress = array(
                    "addressId" =>$id,
                    "addressName" =>$sub_city,
                    "commissionAddress"=>$commission_address
                );
                array_push($allAddress,$eachAddress);
            }
        }
        return $allAddress;
    }
    
    public function insertAddress($subCity){
        // Use prepared statements to prevent SQL injection
        $query = "INSERT INTO address (city, sub_city, commission_address) VALUES (:city, :sub_city, :commission)";
        
        $stmt = $this->conn->prepare($query);
        
        // Bind values
        $stmt->bindValue(':city', 'Addis Abeba');
        $stmt->bindValue(':sub_city', $subCity);
        $stmt->bindValue(':commission', '2');
        
        $stmt->execute();
        $last_id = $this->conn->lastInsertId();
        
        // Also use prepared statement for the second insert
        $q = "INSERT INTO deliver_time (address_id, when_to_deliver, active_deliver) VALUES (:address_id, :when_to_deliver, :active_deliver)";
        $s = $this->conn->prepare($q);
        $s->bindValue(':address_id', $last_id);
        $s->bindValue(':when_to_deliver', 'ሁሌም ከ ሰኞ እስከ ቀዳሜ ከ 3 ሰአት ጀምሮ እናደርሳለን!!');
        $s->bindValue(':active_deliver', 'በሰአታችን እናደርሳለን!!');
        $s->execute();
    }
    
    public function updateAddressCommission($addressId,$value){
        // Use prepared statements here as well
        $query = "UPDATE address SET commission_address = :value WHERE address.id = :addressId";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':value', $value);
        $stmt->bindValue(':addressId', $addressId);
        $stmt->execute();
    }

}