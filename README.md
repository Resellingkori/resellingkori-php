# ResellingKori PHP SDK

PHP client and examples for the [ResellingKori Partner API](https://github.com/Resellingkori/partner-docs).

Sell ResellingKori products on your website at your own price — no package manager needed.

## Requirements

- PHP 7.4 or higher
- Extensions: `curl`, `json` (pre-installed on most hosts)

## Quick Start

```bash
git clone https://github.com/Resellingkori/resellingkori-php.git
cd resellingkori-php

export RK_KEY_ID=rkk_your_key_id
export RK_SECRET=rks_your_secret

php 01_check_connection.php
```

Expected output: **Connected.** ✅

## Use in Your Project

Copy `ResellingKoriClient.php` into your project:

```php
require_once 'ResellingKoriClient.php';

$client = new ResellingKoriClient(
    getenv('RK_KEY_ID'),
    getenv('RK_SECRET')
);

// Check connection
$ping = $client->ping();
echo $ping['store_name'] . "\n";

// Browse catalogue
$products = $client->getProducts(['per_page' => 20]);

// Validate an order (nothing created)
$check = $client->validateOrder([
    'external_order_id' => 'YOUR-ORDER-001',
    'items' => [
        ['product_id' => 42, 'quantity' => 1, 'unit_price' => '1200.00']
    ],
    'delivery_address' => [
        'name'     => 'Customer Name',
        'phone'    => '01700000000',
        'address'  => '123 Example Road',
        'district' => 'Dhaka',
    ],
]);
echo 'Profit: ' . $check['profit'] . " BDT\n";

// Place the order
$order = $client->createOrder([
    'external_order_id' => 'YOUR-ORDER-001',
    'items' => [
        ['product_id' => 42, 'quantity' => 1, 'unit_price' => '1200.00']
    ],
    'delivery_address' => [
        'name'     => 'Customer Name',
        'phone'    => '01700000000',
        'address'  => '123 Example Road',
        'district' => 'Dhaka',
    ],
]);
echo 'Order ID: ' . $order['id'] . "\n";
```

## Examples

| File | Run | What it does |
|---|---|---|
| `01_check_connection.php` | `php 01_check_connection.php` | Verify credentials and permissions |
| `02_sync_catalogue.php` | `php 02_sync_catalogue.php` | Download and sync the product catalogue |
| `03_place_order.php` | `php 03_place_order.php --place --ref=ORDER-001` | Validate and place an order |
| `04_follow_orders.php` | `php 04_follow_orders.php --loop=600` | Poll for order status changes |
| `05_webhook_receiver.php` | `php -S 0.0.0.0:8080 05_webhook_receiver.php` | Receive signed webhook events |
| `06_register_webhook.php` | `php 06_register_webhook.php` | Register your webhook URL |

## Environment Variables

| Variable | Required | Default | Description |
|---|---|---|---|
| `RK_KEY_ID` | ✅ | — | Your Key ID (`rkk_...`) |
| `RK_SECRET` | ✅ | — | Your Secret (`rks_...`) — server only |
| `RK_BASE_URL` | — | `https://resellingkori.com/wp-json/rk/v1` | Override for sandbox |
| `RK_DATA_DIR` | — | `./rk-data` | Folder for catalogue and order data files |
| `RK_WEBHOOK_PATH` | — | `/resellingkori/webhook` | URL path for the webhook receiver |

## Security

**Never put `RK_SECRET` in browser JavaScript, a mobile app, or a public repository.**  
See [SECURITY.md](https://github.com/Resellingkori/partner-docs/blob/main/SECURITY.md) for the full guide.

## Documentation

- [Getting Started](https://github.com/Resellingkori/partner-docs/blob/main/GETTING_STARTED.md)
- [Full API Guide](https://github.com/Resellingkori/partner-docs/blob/main/PARTNER_API_GUIDE.md)
- [Sandbox Guide](https://github.com/Resellingkori/partner-docs/blob/main/SANDBOX.md)

## License

GPL-2.0-or-later
