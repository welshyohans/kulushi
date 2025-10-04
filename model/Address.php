<?php

class Address{

    private $conn;
    public function __construct($conn){
        $this->conn = $conn;
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
        $query = "INSERT INTO address (id, city, sub_city, commission_address) VALUES (NULL, 'Addis Abeba', '$subCity', '2')";
        
        $stmt = $this->conn->exec($query);
        $last_id = $this->conn->lastInsertId();
        
        
        $q = "INSERT INTO deliver_time (id, address_id, when_to_deliver, deliver_at, active_deliver) VALUES (NULL, '$last_id', 'ሁሌም ከ ሰኞ እስከ ቀዳሜ ከ 3 ሰአት ጀምሮ እናደርሳለን!!', current_timestamp(), 'በሰአታችን እናደርሳለን!!');";
        
        $s = $this->conn->exec($q);
       
    }
    
    public function updateAddressCommission($addressId,$value){
          
          $query = "UPDATE address SET commission_address = '$value' WHERE address.id = '$addressId'";
        
        $stmt = $this->conn->exec($query);
    }

}