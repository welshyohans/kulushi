<?php

class Goods {
    private $conn;
    public function __construct($conn){
        $this->conn = $conn;
    }

    // Create a new goods row. $data must include all NOT NULL fields.
    public function createGoods(array $data): int {
        $required = ['category_id','brand_id','name','description','priority','show_in_home','image_url','last_update_code','star_value','tiktok_url','commission'];
        foreach ($required as $k) {
            if (!array_key_exists($k, $data)) {
                throw new InvalidArgumentException("Missing field: $k");
            }
        }

        $sql = "INSERT INTO goods (category_id, brand_id, name, description, priority, show_in_home, image_url, last_update_code, last_update, star_value, tiktok_url, commission)
                VALUES (:category_id, :brand_id, :name, :description, :priority, :show_in_home, :image_url, :last_update_code, NOW(), :star_value, :tiktok_url, :commission)";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':category_id', (int)$data['category_id'], PDO::PARAM_INT);
        $stmt->bindValue(':brand_id', (int)$data['brand_id'], PDO::PARAM_INT);
        $stmt->bindValue(':name', $data['name']);
        $stmt->bindValue(':description', $data['description']);
        $stmt->bindValue(':priority', (int)$data['priority'], PDO::PARAM_INT);
        $stmt->bindValue(':show_in_home', (int)$data['show_in_home'], PDO::PARAM_INT);
        $stmt->bindValue(':image_url', $data['image_url']);
        $stmt->bindValue(':last_update_code', (int)$data['last_update_code'], PDO::PARAM_INT);
        $stmt->bindValue(':star_value', $data['star_value']);
        $stmt->bindValue(':tiktok_url', $data['tiktok_url']);
        $stmt->bindValue(':commission', (int)$data['commission'], PDO::PARAM_INT);
        $stmt->execute();

        return (int)$this->conn->lastInsertId();
    }
}

?>
