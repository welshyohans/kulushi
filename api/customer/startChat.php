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

$respond = static function (int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $respond(405, [
        'success' => false,
        'message' => 'Method not allowed. Use POST.'
    ]);
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    $respond(400, [
        'success' => false,
        'message' => 'Unable to read request body.'
    ]);
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    $respond(400, [
        'success' => false,
        'message' => 'Invalid JSON payload.'
    ]);
}

if (!array_key_exists('customerId', $payload)) {
    $respond(400, [
        'success' => false,
        'message' => 'Missing field: customerId.'
    ]);
}

$customerId = filter_var($payload['customerId'], FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1]
]);
if ($customerId === false) {
    $respond(422, [
        'success' => false,
        'message' => 'customerId must be a positive integer.'
    ]);
}

require_once __DIR__ . '/../../config/Database.php';

try {
    $database = new Database();
    $db = $database->connect();

    if (!$db instanceof PDO) {
        $respond(500, [
            'success' => false,
            'message' => 'Database connection failed.'
        ]);
    }

    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $stmt = $db->prepare(
        'SELECT
            c.name,
            c.specific_address,
            c.location_description,
            a.city,
            a.sub_city
        FROM customer c
        LEFT JOIN address a ON a.id = c.address_id
        WHERE c.id = :customerId
        LIMIT 1'
    );
    $stmt->execute([':customerId' => $customerId]);
    $customer = $stmt->fetch();

    if (!$customer) {
        $respond(404, [
            'success' => false,
            'message' => 'Customer not found.',
            'customerId' => $customerId
        ]);
    }

    $addressParts = [];
    if (!empty($customer['specific_address'])) {
        $addressParts[] = trim((string)$customer['specific_address']);
    }
    if (!empty($customer['location_description'])) {
        $addressParts[] = trim((string)$customer['location_description']);
    }

    $cityParts = array_filter([
        $customer['sub_city'] ?? null,
        $customer['city'] ?? null
    ], static fn($value) => $value !== null && $value !== '');

    if (!empty($cityParts)) {
        $addressParts[] = implode(', ', $cityParts);
    }

    $address = trim(implode(', ', $addressParts));

    $respond(200, [
        'success' => true,
        'message' => 'Chat context prepared.',
        'customerName' => (string)$customer['name'],
        'address' => $address,
        'deliverTime' => 'we deliver as soon as possible but at least we deliver in the same day you ordered',
        'model' => 'google/gemini-2.5-flash',
        'apiKey' => getenv('apiKey') ?: '',
        'document' => "You are **MerkatoPro Assistant**, a customer-support AI for the **MerkatoPro** mobile app.

## Product and audience

* **MerkatoPro** is a **digital marketplace** that connects **wholesalers/suppliers** with **retailers**.
* You support **retailers only** (B2B). If someone is not a retailer, explain politely that the service is for retailers.

## What MerkatoPro does

* MerkatoPro sources **mobile phones and mobile accessories** from **multiple suppliers** located in the **Merkato local marketplace** and distributes them to retailers across **Addis Ababa**.
* MerkatoPro **does not own inventory**; items are obtained from partner suppliers. MerkatoPro provides support/guarantee for items sold (especially smartphones).

## Key benefits to highlight (when relevant)

* Retailers can **compare supplier prices** from the comfort of their shop.
* MerkatoPro helps retailers get **better market prices** by comparing prices from **reliable suppliers**.
* Retailers can choose:

  * **Delivery** (delivery fee applies), or
  * **Pickup** (no delivery fee).

## Delivery and pickup rules

* If MerkatoPro **delivers** an order, a **delivery fee** is charged.
* If the retailer **picks up** the order, **no delivery fee** is charged.
* For large orders, recommend **picking up** to save money.
* MerkatoPro may send **notifications** when delivering to a specific area so retailers can order small items without worry.

## Address

* **Merkato Dire Building, 3rd floor**.

## Smartphone IMEI and warranty support

* For smartphones sold through MerkatoPro, MerkatoPro **records the IMEI number**.
* If a smartphone has a problem, the retailer can **return it using the IMEI record** (so they are not unfairly blamed or refused).

## Credit policy (very important)

* **First order:** no credit.
* After becoming a customer, the retailer can receive **10,000 ETB credit**.
* Credit can **increase over time** as the relationship and communication strengthens.
* For **trusted customers**, there can be **no credit limit** for items that are eligible for credit.
* Currently, credit is available for goods from these suppliers: **Aaron, Endris, and Miki**.
* **Repayment rule:** any credit taken must be paid on the **same day next week** (example: goods received on Monday must be paid on Monday the following week).
* If the retailer cannot pay for any reason, they may **return the goods**.

## Supplier payment flow (trust and responsibility)

* MerkatoPro pays the supplier **after** the retailer pays MerkatoPro.
* Encourage retailers to **pay on time**; if they cannot pay, returning the goods is acceptable and **does not harm the relationship**.

## Guidance style

* Answer questions using only the policies and facts above. Do **not** invent details (prices, stock, supplier promises, or policies not stated).
* If a question requires information you do not have (exact current prices, availability, delivery ETA to a specific sub-city, etc.), ask for the needed order details or direct the user to the app’s ordering/support flow.
* Be clear, concise, and retailer-focused.
",
        'faq' => [
            'MerkatoPro በመጠቀሜ ምን አገኛለው?',
            'ዱቤ/credit እንዴት ነው ሚሰራው?',
            'በሆነ አጋጣሚ የማይሰራ ስልክ እንዴት ነው ምመልሰው?'
        ]
    ]);
} catch (PDOException $exception) {
    $respond(500, [
        'success' => false,
        'message' => 'Database error.',
        'error' => $exception->getMessage()
    ]);
} catch (Throwable $throwable) {
    $respond(500, [
        'success' => false,
        'message' => 'Unexpected server error.',
        'error' => $throwable->getMessage()
    ]);
}
