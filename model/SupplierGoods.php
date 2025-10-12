<?php

class SupplierGoods {
    private $conn;

    public function __construct($conn){
        $this->conn = $conn;
    }

    public function supplierExists(int $supplierId): bool {
        $stmt = $this->conn->prepare("SELECT 1 FROM supplier WHERE shop_id = :id LIMIT 1");
        $stmt->execute([':id' => $supplierId]);
        return (bool)$stmt->fetchColumn();
    }

    public function goodsExists(int $goodsId): bool {
        $stmt = $this->conn->prepare("SELECT 1 FROM goods WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $goodsId]);
        return (bool)$stmt->fetchColumn();
    }

    public function findBySupplierAndGoods(int $supplierId, int $goodsId): ?array {
        $stmt = $this->conn->prepare("SELECT * FROM supplier_goods WHERE supplier_id = :sid AND goods_id = :gid LIMIT 1");
        $stmt->execute([':sid' => $supplierId, ':gid' => $goodsId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // Insert new supplier_goods record (all NOT NULL fields must be provided)
    public function insertRelation(array $data): int {
        $required = [
            'supplier_id','goods_id','price','discount_start','discount_price',
            'min_order','is_available_for_credit','is_available',
            'last_update_code','last_update_price','last_update_available'
        ];
        foreach ($required as $k) {
            if (!array_key_exists($k, $data)) {
                throw new InvalidArgumentException("Missing field: $k");
            }
        }

        $sql = "INSERT INTO supplier_goods
                (supplier_id, goods_id, price, discount_start, discount_price, min_order, is_available_for_credit, is_available,
                 last_update_code, last_update_price, last_update_available)
                VALUES
                (:supplier_id, :goods_id, :price, :discount_start, :discount_price, :min_order, :is_available_for_credit, :is_available,
                 :last_update_code, :last_update_price, :last_update_available)";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':supplier_id', (int)$data['supplier_id'], PDO::PARAM_INT);
        $stmt->bindValue(':goods_id', (int)$data['goods_id'], PDO::PARAM_INT);
        $stmt->bindValue(':price', (int)$data['price'], PDO::PARAM_INT);
        $stmt->bindValue(':discount_start', (int)$data['discount_start'], PDO::PARAM_INT);
        $stmt->bindValue(':discount_price', (int)$data['discount_price'], PDO::PARAM_INT);
        $stmt->bindValue(':min_order', (int)$data['min_order'], PDO::PARAM_INT);
        $stmt->bindValue(':is_available_for_credit', (int)$data['is_available_for_credit'], PDO::PARAM_INT);
        $stmt->bindValue(':is_available', (int)$data['is_available'], PDO::PARAM_INT);
        $stmt->bindValue(':last_update_code', (int)$data['last_update_code'], PDO::PARAM_INT);
        $stmt->bindValue(':last_update_price', (int)$data['last_update_price'], PDO::PARAM_INT);
        $stmt->bindValue(':last_update_available', (int)$data['last_update_available'], PDO::PARAM_INT);
        $stmt->execute();
        return (int)$this->conn->lastInsertId();
    }

    // Update existing relation core fields and touch last_update_code with provided value
    public function updateCoreFields(int $supplierId, int $goodsId, array $data, int $lastUpdateCode): int {
        // Only update provided fields among the allowed set, plus enforce last_update_code
        $allowed = ['price','discount_start','discount_price','min_order','is_available_for_credit','is_available'];
        $sets = [];
        $params = [':sid' => $supplierId, ':gid' => $goodsId, ':last_update_code' => $lastUpdateCode];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $data)) {
                $sets[] = "$key = :$key";
                $params[":$key"] = (int)$data[$key];
            }
        }
        $sets[] = "last_update_code = :last_update_code";
        $sql = "UPDATE supplier_goods SET ".implode(', ', $sets)." WHERE supplier_id = :sid AND goods_id = :gid";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    // Upsert helper: create or update relation; on create, initialize last_update_price/available with same code to satisfy NOT NULL
    public function upsertRelation(array $data, int $lastUpdateCode): array {
        $existing = $this->findBySupplierAndGoods((int)$data['supplier_id'], (int)$data['goods_id']);
        if ($existing) {
            $affected = $this->updateCoreFields(
                (int)$data['supplier_id'],
                (int)$data['goods_id'],
                $data,
                $lastUpdateCode
            );
            return ['action' => 'update', 'affected' => $affected, 'id' => (int)$existing['id']];
        } else {
            $requiredForInsert = ['price','discount_start','discount_price','min_order','is_available_for_credit','is_available'];
            foreach ($requiredForInsert as $field) {
                if (!array_key_exists($field, $data)) {
                    throw new InvalidArgumentException("Missing field for insert: $field");
                }
            }
            $insertData = [
                'supplier_id' => (int)$data['supplier_id'],
                'goods_id' => (int)$data['goods_id'],
                'price' => (int)$data['price'],
                'discount_start' => (int)$data['discount_start'],
                'discount_price' => (int)$data['discount_price'],
                'min_order' => (int)$data['min_order'],
                'is_available_for_credit' => (int)$data['is_available_for_credit'],
                'is_available' => (int)$data['is_available'],
                'last_update_code' => $lastUpdateCode,
                'last_update_price' => $lastUpdateCode,      // initialize to code to satisfy NOT NULL
                'last_update_available' => $lastUpdateCode    // initialize to code to satisfy NOT NULL
            ];
            $newId = $this->insertRelation($insertData);
            return ['action' => 'insert', 'id' => $newId];
        }
    }

    public function updatePrice(int $supplierId, int $goodsId, array $data, int $lastUpdateCode): int {
        $required = ['price','discount_start','discount_price','min_order','is_available_for_credit'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $data)) {
                throw new InvalidArgumentException("Missing field for update: $field");
            }
        }

        $sets = [
            'price = :price',
            'discount_start = :discount_start',
            'discount_price = :discount_price',
            'min_order = :min_order',
            'is_available_for_credit = :is_available_for_credit',
            'last_update_price = :code',
            'last_update_code = :code'
        ];
        $params = [
            ':price' => (int)$data['price'],
            ':discount_start' => (int)$data['discount_start'],
            ':discount_price' => (int)$data['discount_price'],
            ':min_order' => (int)$data['min_order'],
            ':is_available_for_credit' => (int)$data['is_available_for_credit'],
            ':code' => $lastUpdateCode,
            ':sid' => $supplierId,
            ':gid' => $goodsId
        ];
        if (array_key_exists('is_available', $data)) {
            $sets[] = 'is_available = :is_available';
            $sets[] = 'last_update_available = :code';
            $params[':is_available'] = (int)$data['is_available'];
        }

        $sql = "UPDATE supplier_goods
                SET " . implode(', ', $sets) . "
                WHERE supplier_id = :sid AND goods_id = :gid";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function updateAvailability(int $supplierId, int $goodsId, int $isAvailable, int $lastUpdateCode): int {
        $sql = "UPDATE supplier_goods
                SET is_available = :avail, last_update_available = :code
                WHERE supplier_id = :sid AND goods_id = :gid";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':avail' => $isAvailable,
            ':code' => $lastUpdateCode,
            ':sid' => $supplierId,
            ':gid' => $goodsId
        ]);
        return $stmt->rowCount();
    }
}

?>
