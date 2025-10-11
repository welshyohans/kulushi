<?php

class Settings {
    private $conn;
    public function __construct($conn){
        $this->conn = $conn;
    }

    // Returns the incremented last_update_code (creates it with 1 if missing)
    public function nextCode(){
        $manageTx = !$this->conn->inTransaction();
        if ($manageTx) {
            $this->conn->beginTransaction();
        }
        try {
            // Lock the row to avoid race conditions
            $stmt = $this->conn->prepare("SELECT value FROM settings WHERE setting_key = :key FOR UPDATE");
            $stmt->execute([':key' => 'last_update_code']);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                $newVal = 1;
                $ins = $this->conn->prepare("INSERT INTO settings (setting_key, value) VALUES (:key, :val)");
                $ins->execute([':key'=>'last_update_code', ':val'=>$newVal]);
            } else {
                $cur = intval($row['value']);
                $newVal = $cur + 1;
                $upd = $this->conn->prepare("UPDATE settings SET value = :val WHERE setting_key = :key");
                $upd->execute([':val'=>$newVal, ':key'=>'last_update_code']);
            }
            if ($manageTx) {
                $this->conn->commit();
            }
            return $newVal;
        } catch (Throwable $e) {
            if ($manageTx && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            throw $e;
        }
    }
}