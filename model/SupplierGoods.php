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

    public function findById(int $id): ?array {
        $stmt = $this->conn->prepare("SELECT * FROM supplier_goods WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // Insert new supplier_goods record (all NOT NULL fields must be provided)
    public function insertRelation(array $data): int {
        $required = [
            'supplier_id','goods_id','price','discount_start','discount_price',
            'min_order','is_available_for_credit','is_available',
            'last_update_code'
        ];
        foreach ($required as $k) {
            if (!array_key_exists($k, $data)) {
                throw new InvalidArgumentException("Missing field: $k");
            }
        }

        $sql = "INSERT INTO supplier_goods
                (supplier_id, goods_id, price, discount_start, discount_price, min_order, is_available_for_credit, is_available,
                 last_update_code)
                VALUES
                (:supplier_id, :goods_id, :price, :discount_start, :discount_price, :min_order, :is_available_for_credit, :is_available,
                 :last_update_code)";
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

    // Upsert helper: create or update relation while keeping last_update_code consistent
    public function upsertRelation(array $data, int $lastUpdateCode): array {
        $existing = $this->findBySupplierAndGoods((int)$data['supplier_id'], (int)$data['goods_id']);
        if ($existing) {
            $affected = $this->updateCoreFields(
                (int)$data['supplier_id'],
                (int)$data['goods_id'],
                $data,
                $lastUpdateCode
            );
            $this->touchSupplierLastUpdate((int)$data['supplier_id'], $lastUpdateCode);
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
                'last_update_code' => $lastUpdateCode
            ];
            $newId = $this->insertRelation($insertData);
            $this->touchSupplierLastUpdate((int)$data['supplier_id'], $lastUpdateCode);
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
            $params[':is_available'] = (int)$data['is_available'];
        }

        $sql = "UPDATE supplier_goods
                SET " . implode(', ', $sets) . "
                WHERE supplier_id = :sid AND goods_id = :gid";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $this->touchSupplierLastUpdate($supplierId, $lastUpdateCode);
        return $stmt->rowCount();
    }

    public function updateAvailability(int $supplierId, int $goodsId, int $isAvailable, int $lastUpdateCode): int {
        $sql = "UPDATE supplier_goods
                SET is_available = :avail, last_update_code = :code
                WHERE supplier_id = :sid AND goods_id = :gid";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':avail' => $isAvailable,
            ':code' => $lastUpdateCode,
            ':sid' => $supplierId,
            ':gid' => $goodsId
        ]);
        $this->touchSupplierLastUpdate($supplierId, $lastUpdateCode);
        return $stmt->rowCount();
    }

    public function insertSimpleRelation(int $supplierId, int $goodsId, int $price, int $lastUpdateCode, array $overrides = []): int {
        $data = [
            'supplier_id' => $supplierId,
            'goods_id' => $goodsId,
            'price' => $price,
            'discount_start' => 0,
            'discount_price' => $price,
            'min_order' => 1,
            'is_available_for_credit' => 0,
            'is_available' => 1,
            'last_update_code' => $lastUpdateCode
        ];
        $allowedOverrides = ['discount_start','discount_price','min_order','is_available_for_credit','is_available'];
        foreach ($allowedOverrides as $key) {
            if (array_key_exists($key, $overrides) && $overrides[$key] !== null) {
                $data[$key] = (int)$overrides[$key];
            }
        }
        $newId = $this->insertRelation($data);
        $this->touchSupplierLastUpdate($supplierId, $lastUpdateCode);
        return $newId;
    }

    public function updateById(int $id, array $data, int $lastUpdateCode): int {
        $existing = $this->findById($id);
        if (!$existing) {
            return 0;
        }

        $allowed = ['price','discount_start','discount_price','min_order','is_available_for_credit','is_available'];
        $sets = [];
        $params = [':id' => $id, ':last_update_code' => $lastUpdateCode];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $data)) {
                $sets[] = "$key = :$key";
                $params[":$key"] = (int)$data[$key];
            }
        }
        $sets[] = "last_update_code = :last_update_code";

        $sql = "UPDATE supplier_goods SET " . implode(', ', $sets) . " WHERE id = :id";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        $this->touchSupplierLastUpdate((int)$existing['supplier_id'], $lastUpdateCode);
        return $stmt->rowCount();
    }

    public function updateAvailabilityById(int $id, int $isAvailable, int $lastUpdateCode): int {
        $existing = $this->findById($id);
        if (!$existing) {
            return 0;
        }

        $stmt = $this->conn->prepare("UPDATE supplier_goods SET is_available = :avail, last_update_code = :code WHERE id = :id");
        $stmt->execute([
            ':avail' => $isAvailable,
            ':code' => $lastUpdateCode,
            ':id' => $id
        ]);

        $this->touchSupplierLastUpdate((int)$existing['supplier_id'], $lastUpdateCode);
        return $stmt->rowCount();
    }

    public function buildUpdatesPayload(int $supplierId, int $lastUpdateCode, Settings $settings): array {
        $goodsStmt = $this->conn->prepare('SELECT * FROM goods WHERE last_update_code > :code ORDER BY last_update_code ASC');
        $goodsStmt->execute([':code' => $lastUpdateCode]);
        $goods = $goodsStmt->fetchAll(PDO::FETCH_ASSOC);

        $categoryStmt = $this->conn->prepare('SELECT id, name, commission FROM category WHERE last_update_code > :code ORDER BY last_update_code ASC');
        $categoryStmt->execute([':code' => $lastUpdateCode]);
        $categories = [];
        foreach ($categoryStmt->fetchAll(PDO::FETCH_ASSOC) as $categoryRow) {
            $categories[] = [
                'id' => isset($categoryRow['id']) ? (int)$categoryRow['id'] : 0,
                'name' => isset($categoryRow['name']) ? (string)$categoryRow['name'] : '',
                'commission' => isset($categoryRow['commission']) ? (int)$categoryRow['commission'] : 0,
            ];
        }

        $supplierGoodsStmt = $this->conn->prepare('SELECT * FROM supplier_goods WHERE supplier_id = :sid ORDER BY last_update_code ASC, id ASC');
        $supplierGoodsStmt->execute([':sid' => $supplierId]);
        $supplierGoods = $supplierGoodsStmt->fetchAll(PDO::FETCH_ASSOC);

        $currentCode = (int)$settings->getValue('last_update_code', 0);

        return [
            'supplier_id' => $supplierId,
            'requested_last_update_code' => $lastUpdateCode,
            'current_last_update_code' => $currentCode,
            'goods_updates' => $goods,
            'supplier_goods' => $supplierGoods,
            'categories' => $categories
        ];
    }

    private function touchSupplierLastUpdate(int $supplierId, int $lastUpdateCode): void {
        $stmt = $this->conn->prepare("UPDATE supplier SET last_update_code = :code WHERE shop_id = :sid");
        $stmt->execute([
            ':code' => $lastUpdateCode,
            ':sid' => $supplierId
        ]);
    }
}

?>
