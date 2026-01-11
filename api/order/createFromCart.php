<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Use POST.'
    ]);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON payload.'
    ]);
    exit;
}

if (!isset($payload['customerId'], $payload['items']) || !is_array($payload['items'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'customerId and items are required.'
    ]);
    exit;
}

$customerId = (int)$payload['customerId'];
if ($customerId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'customerId must be a positive integer.'
    ]);
    exit;
}

$items = $payload['items'];
if (count($items) === 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Cart items cannot be empty.'
    ]);
    exit;
}

require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../model/SMS.php';

function formatPhoneForSms(?string $rawPhone): ?string
{
    if ($rawPhone === null) {
        return null;
    }

    $digits = preg_replace('/\D+/', '', $rawPhone);
    if ($digits === '') {
        return null;
    }

    if (strncmp($digits, '251', 3) === 0) {
        $digits = substr($digits, -9);
    }

    if (strlen($digits) === 9) {
        return '+251' . $digits;
    }

    if (strlen($digits) === 10 && $digits[0] === '0') {
        return '+251' . substr($digits, 1);
    }

    return null;
}

try {
    $database = new Database();
    $db = $database->connect();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $db->beginTransaction();

    $customerStmt = $db->prepare('SELECT id, name, phone FROM customer WHERE id = :customerId LIMIT 1');
    $customerStmt->execute([':customerId' => $customerId]);
    $customer = $customerStmt->fetch();
    if (!$customer) {
        throw new RuntimeException('Customer profile not found.');
    }

    $preparedItems = [];
    $totalPrice = 0.0;
    $totalProfit = 0.0;
    $totalQuantity = 0;

    $offerStmt = $db->prepare(
        'SELECT
            sg.id AS supplier_goods_id,
            sg.supplier_id,
            sg.goods_id,
            sg.price,
            sg.discount_price,
            sg.discount_start,
            sg.min_order,
            sg.is_available,
            s.shop_name,
            s.shop_type,
            g.name AS goods_name,
            g.commission AS goods_commission
        FROM supplier_goods sg
        INNER JOIN supplier s ON s.shop_id = sg.supplier_id
        INNER JOIN goods g ON g.id = sg.goods_id
        WHERE sg.id = :supplierGoodsId
        LIMIT 1'
    );

    foreach ($items as $index => $item) {
        if (!is_array($item) || !isset($item['supplierGoodsId'], $item['quantity'])) {
            throw new RuntimeException("Invalid item at index {$index}.");
        }

        $supplierGoodsId = (int)$item['supplierGoodsId'];
        $quantity = (int)$item['quantity'];
        if ($supplierGoodsId <= 0 || $quantity <= 0) {
            throw new RuntimeException("Invalid supplierGoodsId or quantity at index {$index}.");
        }

        $offerStmt->execute([':supplierGoodsId' => $supplierGoodsId]);
        $offer = $offerStmt->fetch();
        if (!$offer) {
            throw new RuntimeException("Supplier goods #{$supplierGoodsId} not found.");
        }

        if ((int)$offer['is_available'] !== 1) {
            throw new RuntimeException("Goods '{$offer['goods_name']}' is currently unavailable.");
        }

        if (strtolower($offer['shop_type']) === 'self-delivered') {
            throw new RuntimeException("Goods '{$offer['goods_name']}' is self-delivered. Visit the supplier shop to order.");
        }

        $minOrder = (int)$offer['min_order'];
        if ($quantity < $minOrder) {
            throw new RuntimeException("Goods '{$offer['goods_name']}' requires a minimum order of {$minOrder}.");
        }

        $unitBasePrice = isset($offer['discount_price']) && (int)$offer['discount_price'] > 0
            ? (float)$offer['discount_price']
            : (float)$offer['price'];
        $commission = isset($offer['goods_commission']) ? (float)$offer['goods_commission'] : 0.0;
        $unitPrice = $unitBasePrice + $commission;

        $lineTotal = $unitPrice * $quantity;
        $lineProfit = $commission * $quantity;
        $totalPrice += $lineTotal;
        $totalProfit += $lineProfit;
        $totalQuantity += $quantity;

        $preparedItems[] = [
            'supplier_goods_id' => $supplierGoodsId,
            'supplier_id' => (int)$offer['supplier_id'],
            'goods_id' => (int)$offer['goods_id'],
            'goods_name' => $offer['goods_name'],
            'min_order' => $minOrder,
            'unit_price' => $unitPrice,
            'supplier_price' => (float)$offer['price'],
            'commission' => $commission,
            'line_profit' => $lineProfit,
            'quantity' => $quantity,
            'line_total' => $lineTotal
        ];
    }

    $mergedIntoExisting = false;
    $existingDeliverStatus = null;
    $existingOrderStmt = $db->prepare(
        'SELECT id, deliver_status
         FROM orders
         WHERE customer_id = :customerId
           AND deliver_status IN (1, 3)
           AND order_time >= CURDATE()
           AND order_time < (CURDATE() + INTERVAL 1 DAY)
         ORDER BY order_time DESC
         LIMIT 1
         FOR UPDATE'
    );
    $existingOrderStmt->execute([':customerId' => $customerId]);
    $existingOrderRow = $existingOrderStmt->fetch();
    $existingOrderId = is_array($existingOrderRow) ? ($existingOrderRow['id'] ?? false) : false;

    if ($existingOrderId !== false) {
        $orderId = (int)$existingOrderId;
        $mergedIntoExisting = true;
        $existingDeliverStatus = $existingOrderRow['deliver_status'] ?? null;

        $isPickupPricing = ((int)$existingDeliverStatus === 3);
        $effectiveTotalPrice = $isPickupPricing ? max(0.0, $totalPrice - $totalProfit) : $totalPrice;
        $effectiveTotalProfit = $isPickupPricing ? 0.0 : $totalProfit;

        $updateOrderStmt = $db->prepare(
            'UPDATE orders
             SET total_price = COALESCE(total_price, 0) + :total_price,
                 profit = COALESCE(profit, 0) + :profit,
                 cash_amount = COALESCE(cash_amount, 0) + :cash_amount,
                 credit_amount = COALESCE(credit_amount, 0) + :credit_amount
             WHERE id = :orderId'
        );
        $updateOrderStmt->execute([
            ':total_price' => $effectiveTotalPrice,
            ':profit' => number_format($effectiveTotalProfit, 2, '.', ''),
            ':cash_amount' => $effectiveTotalPrice,
            ':credit_amount' => 0,
            ':orderId' => $orderId
        ]);
    } else {
        $orderStmt = $db->prepare(
            'INSERT INTO orders (customer_id, total_price, profit, unpaid_cash, unpaid_credit, cash_amount, credit_amount, deliver_status, comment)
             VALUES (:customer_id, :total_price, :profit, 0, 0, :cash_amount, 0, 1, :comment)'
        );
        $comment = sprintf('Auto order via web — items: %d, quantity: %d', count($preparedItems), $totalQuantity);
        $orderStmt->execute([
            ':customer_id' => $customerId,
            ':total_price' => $totalPrice,
            ':profit' => number_format($totalProfit, 2, '.', ''),
            ':cash_amount' => $totalPrice,
            ':comment' => $comment
        ]);
        $orderId = (int)$db->lastInsertId();
    }

    $orderListStmt = $db->prepare(
        'INSERT INTO ordered_list (orders_id, supplier_goods_id, goods_id, quantity, each_price, supplier_price, commission, line_profit, eligible_for_credit, status)
         VALUES (:orders_id, :supplier_goods_id, :goods_id, :quantity, :each_price, :supplier_price, :commission, :line_profit, :eligible_for_credit, 1)'
    );

    $isPickupPricing = $mergedIntoExisting && ((int)$existingDeliverStatus === 3);
    foreach ($preparedItems as $prepared) {
        $eachPrice = $isPickupPricing
            ? max(0.0, round((float)$prepared['unit_price'] - (float)$prepared['commission'], 2))
            : (float)$prepared['unit_price'];
        $commission = $isPickupPricing ? 0.0 : (float)$prepared['commission'];
        $lineProfit = $isPickupPricing ? 0.0 : (float)$prepared['line_profit'];

        $orderListStmt->execute([
            ':orders_id' => $orderId,
            ':supplier_goods_id' => $prepared['supplier_goods_id'],
            ':goods_id' => $prepared['goods_id'],
            ':quantity' => $prepared['quantity'],
            ':each_price' => $eachPrice,
            ':supplier_price' => $prepared['supplier_price'],
            ':commission' => $commission,
            ':line_profit' => $lineProfit,
            ':eligible_for_credit' => 0
        ]);
    }

    $db->commit();

    $responseTotalPrice = $isPickupPricing ? max(0.0, $totalPrice - $totalProfit) : $totalPrice;

    $responsePayload = [
        'success' => true,
        'message' => $mergedIntoExisting ? 'Order updated successfully.' : 'Order submitted successfully.',
        'orderId' => $orderId,
        'totalPrice' => $responseTotalPrice,
        'itemCount' => count($preparedItems),
        'totalQuantity' => $totalQuantity,
        'mergedIntoExistingOrder' => $mergedIntoExisting
    ];

    echo json_encode($responsePayload);

    try {
        $sms = new SMS();
        $customerPhone = formatPhoneForSms($customer['phone'] ?? null);
        $friendlyTotal = number_format($responseTotalPrice, 2, '.', ',');
        $summaryLines = array_map(static function (array $item) use ($isPickupPricing): string {
            $name = $item['goods_name'] ?? 'Goods';
            $qty = (int)$item['quantity'];
            $unit = (float)($item['unit_price'] ?? 0);
            $commission = (float)($item['commission'] ?? 0);
            if ($isPickupPricing) {
                $unit = max(0.0, $unit - $commission);
            }
            $price = number_format($unit, 2, '.', ',');
            return sprintf('%s=%d*%s', $name, $qty, $price);
        }, $preparedItems);
        $summaryText = implode(', ', $summaryLines);

        if ($customerPhone) {
            $sms->sendSms(
                $customerPhone,
                sprintf(
                    "Merkato Pro: Order #%d received. Total ETB %s. We will contact you shortly.",
                    $orderId,
                    $friendlyTotal
                )
            );
        }

        $adminPhone = formatPhoneForSms('0943-090921');
        if ($adminPhone) {
            $customerName = $customer['name'] ?? 'Customer';
            $sms->sendSms(
                $adminPhone,
                sprintf(
                    "New order #%d from %s. Total ETB %s. %s",
                    $orderId,
                    $customerName,
                    $friendlyTotal,
                    $summaryText
                )
            );
        }
    } catch (Throwable $smsError) {
        error_log('Order SMS dispatch failed: ' . $smsError->getMessage());
    }
} catch (Throwable $exception) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $exception->getMessage()
    ]);
}
