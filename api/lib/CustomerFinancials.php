<?php
declare(strict_types=1);

final class CustomerFinancials
{
    public static function tableExists(PDO $db, string $tableName): bool
    {
        try {
            $stmt = $db->query("SHOW TABLES LIKE " . $db->quote($tableName));
            return $stmt->rowCount() > 0;
        } catch (Throwable $throwable) {
            return false;
        }
    }

    public static function columnExists(PDO $db, string $tableName, string $columnName): bool
    {
        try {
            $stmt = $db->query("SHOW COLUMNS FROM `$tableName` LIKE " . $db->quote($columnName));
            return $stmt->rowCount() > 0;
        } catch (Throwable $throwable) {
            return false;
        }
    }

    /**
     * Recalculate customer.total_credit and customer.total_unpaid from delivered orders,
     * and include customer.manual_credit in total_unpaid when that column exists.
     *
     * Returns: total_credit, total_unpaid, total_cash, manual_credit
     */
    public static function recalcCustomerTotals(PDO $db, int $customerId): array
    {
        $hasManualCredit = self::columnExists($db, 'customer', 'manual_credit');

        $totalsStmt = $db->prepare(
            'SELECT
                COALESCE(SUM(COALESCE(unpaid_credit, 0)), 0) AS total_credit,
                COALESCE(SUM(COALESCE(unpaid_cash, 0)), 0) AS total_cash
             FROM orders
             WHERE customer_id = :customerId
               AND deliver_status = 6'
        );
        $totalsStmt->execute([':customerId' => $customerId]);
        $totals = $totalsStmt->fetch(PDO::FETCH_ASSOC) ?: ['total_credit' => 0, 'total_cash' => 0];

        $totalCreditValue = (float)$totals['total_credit'];
        $totalCashValue = (float)$totals['total_cash'];

        $manualCreditValue = 0.0;
        if ($hasManualCredit) {
            $manualStmt = $db->prepare('SELECT COALESCE(manual_credit, 0) AS manual_credit FROM customer WHERE id = :customerId LIMIT 1');
            $manualStmt->execute([':customerId' => $customerId]);
            $manualRow = $manualStmt->fetch(PDO::FETCH_ASSOC);
            $manualCreditValue = $manualRow ? (float)$manualRow['manual_credit'] : 0.0;
        }

        $totalUnpaidValue = $totalCashValue + $totalCreditValue + $manualCreditValue;

        $customerUpdateStmt = $db->prepare(
            'UPDATE customer
             SET total_credit = :totalCredit,
                 total_unpaid = :totalUnpaid
             WHERE id = :customerId'
        );
        $customerUpdateStmt->execute([
            ':totalCredit' => self::formatMoney($totalCreditValue),
            ':totalUnpaid' => self::formatMoney($totalUnpaidValue),
            ':customerId' => $customerId
        ]);

        return [
            'total_credit' => $totalCreditValue,
            'total_unpaid' => $totalUnpaidValue,
            'total_cash' => $totalCashValue,
            'manual_credit' => $manualCreditValue
        ];
    }

    /**
     * Sync customer.manual_* totals by summing ledger entry tables (if they exist).
     * Returns: manual_credit, manual_profit, manual_loss
     */
    public static function syncManualTotalsFromLedgers(PDO $db, int $customerId): array
    {
        $hasManualCredit = self::columnExists($db, 'customer', 'manual_credit');
        $hasManualProfit = self::columnExists($db, 'customer', 'manual_profit');
        $hasManualLoss = self::columnExists($db, 'customer', 'manual_loss');

        $manualCredit = 0.0;
        $manualProfit = 0.0;
        $manualLoss = 0.0;

        if ($hasManualCredit && self::tableExists($db, 'customer_manual_credit_entries')) {
            $stmt = $db->prepare('SELECT COALESCE(SUM(amount), 0) AS total FROM customer_manual_credit_entries WHERE customer_id = :customerId');
            $stmt->execute([':customerId' => $customerId]);
            $manualCredit = (float)(($stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0])['total']);
        }

        if ($hasManualProfit && self::tableExists($db, 'customer_manual_profit_entries')) {
            $stmt = $db->prepare('SELECT COALESCE(SUM(amount), 0) AS total FROM customer_manual_profit_entries WHERE customer_id = :customerId');
            $stmt->execute([':customerId' => $customerId]);
            $manualProfit = (float)(($stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0])['total']);
        }

        if ($hasManualLoss && self::tableExists($db, 'customer_manual_loss_entries')) {
            $stmt = $db->prepare('SELECT COALESCE(SUM(amount), 0) AS total FROM customer_manual_loss_entries WHERE customer_id = :customerId');
            $stmt->execute([':customerId' => $customerId]);
            $manualLoss = (float)(($stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0])['total']);
        }

        $sets = [];
        $params = [':customerId' => $customerId];

        if ($hasManualProfit) {
            $sets[] = 'manual_profit = :manual_profit';
            $params[':manual_profit'] = self::formatMoney($manualProfit);
        }
        if ($hasManualLoss) {
            $sets[] = 'manual_loss = :manual_loss';
            $params[':manual_loss'] = self::formatMoney($manualLoss);
        }
        if ($hasManualCredit) {
            $sets[] = 'manual_credit = :manual_credit';
            $params[':manual_credit'] = self::formatMoney($manualCredit);
        }

        if ($sets !== []) {
            $stmt = $db->prepare('UPDATE customer SET ' . implode(', ', $sets) . ' WHERE id = :customerId');
            $stmt->execute($params);
        }

        return [
            'manual_credit' => $manualCredit,
            'manual_profit' => $manualProfit,
            'manual_loss' => $manualLoss
        ];
    }

    public static function formatMoney(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}

