<?php
/**
 * Example 3 - check an order, place it exactly once, cancel it.
 *
 *   Check only (safe: nothing is created, nothing is reserved):
 *     RK_KEY_ID=rkk_... RK_SECRET=rks_... php 03_place_order.php [--sku=AW-Y526] [--qty=1] [--price=830]
 *
 *   Really place the order (ResellingKori starts processing it and a courier will be sent):
 *     php 03_place_order.php --sku=AW-Y526 --qty=1 --price=830 --ref=WEB-10058 --place
 *
 *   Run the SAME command again: ResellingKori answers "duplicate" and creates nothing new. This is what makes it safe
 *   to retry after a time-out. Your own order number is the reference; never invent a new one for a retry.
 *
 *   Cancel (possible until ResellingKori has processed the order):
 *     php 03_place_order.php --ref=WEB-10058 --cancel
 *
 * Options  --sku       SKU of the product (or of one variation). Default: the first product that can be ordered now.
 *          --qty       quantity, default 1
 *          --price     YOUR selling price per unit in BDT, never below the base price. Default: base price + 15 %.
 *          --delivery  delivery charge you take from your customer, default 100
 *          --prepaid   what your customer has already paid you, default 0 (the courier collects the rest)
 *          --ref       your reference for this order (required with --place and --cancel)
 *
 * The customer below is sample data. In your system it comes from your own order.
 * Exit code 0 = fine, 1 = ResellingKori refused or was not reachable, 2 = wrong usage or configuration.
 */
require __DIR__ . '/../../clients/php/ResellingKoriClient.php';

$customer = array(
	'name'      => 'Rahim Uddin',
	'phone'     => '01700000000',
	'email'     => 'rahim@example.com',
	'address_1' => 'House 12, Road 3, Dhanmondi',
	'address_2' => '',
	'city'      => 'Dhaka',
	'state'     => 'Dhaka',
	'postcode'  => '1209',
	'country'   => 'BD',
);

/* ------------------------------------------------------------ money: exact, in paisa (integers), never floats */

/** "1190.00" or "1190" or "1190.5" -> 119000 paisa. */
function to_paisa( $amount ) {
	if ( ! preg_match( '/^(\d+)(?:\.(\d{1,2}))?$/', trim( (string) $amount ), $m ) ) {
		throw new InvalidArgumentException( "'$amount' is not an amount (use digits and at most two decimals, for example 1190 or 1190.50)." );
	}
	return (int) $m[1] * 100 + (int) str_pad( isset( $m[2] ) ? $m[2] : '', 2, '0' );
}

/** 119000 -> "1190.00" (the format the API expects). */
function money( $paisa ) {
	return sprintf( '%d.%02d', intdiv( $paisa, 100 ), $paisa % 100 );
}

/** Say what went wrong in words a person can act on. */
function explain( ResellingKoriException $e ) {
	fwrite( STDERR, "REFUSED: {$e->apiCode} (HTTP {$e->httpStatus}) {$e->getMessage()}\n" );
	$d = $e->data;
	foreach ( isset( $d['fields'] ) ? $d['fields'] : array() as $f ) {
		fwrite( STDERR, '  - ' . ( isset( $f['field'] ) ? $f['field'] : '?' ) . ': ' . ( isset( $f['message'] ) ? $f['message'] : '' ) . ( isset( $f['code'] ) ? ' [' . $f['code'] . ']' : '' ) . "\n" );
	}
	foreach ( isset( $d['changes'] ) ? $d['changes'] : array() as $c ) {
		fwrite( STDERR, '  - line ' . $c['external_item_id'] . ': you expected a base price of ' . $c['expected_base_unit_price'] . ' but it is now ' . $c['current_base_unit_price'] . ". Refresh your catalogue, show your customer the new price and send again.\n" );
	}
	foreach ( isset( $d['shortages'] ) ? $d['shortages'] : array() as $s ) {
		fwrite( STDERR, '  - ' . $s['label'] . ': you asked for ' . $s['requested'] . ', only ' . $s['available'] . " available.\n" );
	}
	if ( $e->retryable ) {
		fwrite( STDERR, "  This kind of answer can be retried with the SAME order and the SAME reference.\n" );
	}
}

/* ------------------------------------------------------------------------------------------------ options */

$opt = array( 'qty' => '1', 'delivery' => '100', 'prepaid' => '0' );
$act = 'check';
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( '--place' === $arg ) {
		$act = 'place';
	} elseif ( '--cancel' === $arg ) {
		$act = 'cancel';
	} elseif ( preg_match( '/^--(sku|qty|price|delivery|prepaid|ref)=(.*)$/s', $arg, $m ) ) {
		$opt[ $m[1] ] = $m[2];
	} else {
		fwrite( STDERR, "Unknown option $arg. Read the top of this file for the usage.\n" );
		exit( 2 );
	}
}
$key    = getenv( 'RK_KEY_ID' );
$secret = getenv( 'RK_SECRET' );
if ( ! $key || ! $secret ) {
	fwrite( STDERR, "Set RK_KEY_ID and RK_SECRET first (see START-HERE.md).\n" );
	exit( 2 );
}
if ( 'check' !== $act && empty( $opt['ref'] ) ) {
	fwrite( STDERR, "--ref is required: it is YOUR reference for the order, for example your own order number (WEB-10058).\nUse the same reference whenever you repeat or cancel this order.\n" );
	exit( 2 );
}
$ref = isset( $opt['ref'] ) ? $opt['ref'] : 'EXAMPLE-CHECK';

try {
	$rk = new ResellingKoriClient( $key, $secret, getenv( 'RK_BASE_URL' ) ? array( 'base_url' => getenv( 'RK_BASE_URL' ) ) : array() );

	/* ---------------------------------------------------------------------------------------------- cancel */
	if ( 'cancel' === $act ) {
		$res = $rk->cancelOrder( $ref, 'Cancelled from the example script' );
		echo ! empty( $res['already_cancelled'] ) ? "Order $ref was already cancelled.\n" : "Order $ref is cancelled. The stock returns to ResellingKori.\n";
		exit( 0 );
	}

	/* ------------------------------------------------------------------ 1. the product, with live price and stock */
	if ( empty( $opt['sku'] ) ) {
		$pick = null;
		foreach ( $rk->listProducts( array( 'in_stock' => 1, 'per_page' => 50 ) )['items'] as $p ) {
			if ( 'simple' === $p['type'] && $p['purchasable'] && 'instock' === $p['stock_status'] ) {
				$pick = $p;
				break;
			}
		}
		if ( ! $pick ) {
			fwrite( STDERR, "ResellingKori shows no simple product that can be ordered right now. Try again later or pass --sku=.\n" );
			exit( 1 );
		}
		$opt['sku'] = $pick['sku'];
		echo "No --sku given: using the first product that can be ordered now ({$pick['sku']}).\n";
	}
	$found = $rk->productsBySku( array( $opt['sku'] ) );
	if ( empty( $found['matches'] ) ) {
		fwrite( STDERR, "ResellingKori has no product with SKU {$opt['sku']}.\n" );
		exit( 1 );
	}
	$p           = reset( $found['matches'] );
	$variation   = (int) $p['matched_variation_id'];
	$base        = $p['base_price'];
	$label       = $p['name'];
	$unit_sku    = $p['sku'];
	if ( 'variable' === $p['type'] && ! $variation ) {
		fwrite( STDERR, "{$opt['sku']} is a product with variations. Order one variation: pass the SKU of that variation.\n" );
		exit( 2 );
	}
	if ( $variation ) {                                    // A variation has its own price, stock and SKU.
		foreach ( $p['variations'] as $v ) {
			if ( (int) $v['id'] === $variation ) {
				$base     = $v['base_price'];
				$label   .= ' - ' . $v['label'];
				$unit_sku = $v['sku'];
			}
		}
	}
	printf( "Product : %s (SKU %s)\nBase price (what you pay ResellingKori): %s BDT, stock: %s\n", $label, $unit_sku, $base, null === $p['stock_quantity'] ? $p['stock_status'] : $p['stock_quantity'] );

	/* ------------------------------------------------------------------------------------ 2. build the order */
	$qty      = (int) $opt['qty'];
	$base_p   = to_paisa( $base );
	$price_p  = isset( $opt['price'] ) ? to_paisa( $opt['price'] ) : intdiv( $base_p * 115 + 9999, 10000 ) * 100;   // base + 15 %, rounded up to a whole taka (integers only)
	$deliv_p  = to_paisa( $opt['delivery'] );
	$prepaid  = to_paisa( $opt['prepaid'] );
	$total_p  = $price_p * $qty + $deliv_p;                // order_total = items + delivery
	$cod_p    = $total_p - $prepaid;                       // the courier collects the rest
	if ( $qty < 1 || $prepaid > $total_p ) {
		fwrite( STDERR, "Quantity must be at least 1 and --prepaid cannot be more than the order total (" . money( $total_p ) . ").\n" );
		exit( 2 );
	}
	$order = array(
		'schema'                => 2,
		'external_order_id'     => $ref,
		'external_order_number' => '#' . $ref,
		'currency'              => 'BDT',
		'customer'              => $customer,
		'items'                 => array(
			array(
				'external_item_id'         => 1,
				'rk_product_id'            => (int) $p['id'],
				'rk_variation_id'          => $variation,
				'sku'                      => $unit_sku,
				'name'                     => $label,
				'quantity'                 => $qty,
				'selling_unit_price'       => money( $price_p ),
				'expected_base_unit_price' => $base,        // the price guard: refuse if ResellingKori's price has changed meanwhile
			),
		),
		'payment'               => array(
			'method'          => 0 === $cod_p ? 'prepaid' : 'cod',
			'delivery_charge' => money( $deliv_p ),
			'prepaid_amount'  => money( $prepaid ),
			'cod_amount'      => money( $cod_p ),
		),
		'partial'               => false,
		'note'                  => 'Example order from the ResellingKori partner developer kit.',
	);

	/* ------------------------------------------------------------------------------ 3. check it (always) */
	$check = $rk->validateOrder( $order );
	$f     = $check['financials'];
	printf(
		"\nChecked by ResellingKori: %s\n  You sell %d x %s = %s | delivery %s | order total %s | already paid %s | cash the courier collects %s\n  Cost of the goods to you %s | YOUR PROFIT %s BDT\n",
		$check['valid'] ? 'the order would be accepted now' : 'NOT valid',
		$qty,
		money( $price_p ),
		$f['selling_total'],
		$f['delivery_charge'],
		$f['order_total'],
		$f['prepaid_amount'],
		$f['cod_amount'],
		$f['base_total'],
		$f['profit_total']
	);
	if ( ! empty( $check['existing_order'] ) ) {
		$ex = $check['existing_order'];
		echo ! empty( $ex['same_content'] )
			? "  Note: the reference $ref was already used with exactly this content (ResellingKori order #{$ex['order_id']}). Sending it again creates nothing new.\n"
			: "  WARNING: the reference $ref was already used for DIFFERENT content (ResellingKori order #{$ex['order_id']}). ResellingKori would refuse this order (rkapi_order_conflict) - use a new reference.\n";
	}
	if ( 'place' !== $act ) {
		echo "\nThis was only a check - nothing was created. To place the order add: --ref=YOUR-OWN-ORDER-NUMBER --place\n";
		exit( 0 );
	}

	/* ------------------------------------------------------------------------------ 4. place it, exactly once */
	$res = $rk->createOrder( $order );
	$o   = $res['order'];
	echo "\n" . ( ! empty( $res['duplicate'] ) ? ( ! empty( $res['recovered'] ) ? 'The answer to the first attempt was lost, but the order exists' : 'ResellingKori already had exactly this order' ) . ' - nothing new was created.' : 'ORDER CREATED. Stock is reserved and ResellingKori is processing it.' ) . "\n";
	printf( "  ResellingKori order #%s | your reference %s | status %s | cancellable: %s\n", $o['number'], $o['external_order_id'], $o['status'], $o['cancellable'] ? 'yes' : 'no' );
	echo "  Follow it with 04_follow_orders.php or with a webhook (05/06). To cancel: php 03_place_order.php --ref=$ref --cancel\n";
	exit( 0 );
} catch ( InvalidArgumentException $e ) {
	fwrite( STDERR, 'Problem with your input: ' . $e->getMessage() . "\n" );
	exit( 2 );
} catch ( ResellingKoriException $e ) {
	explain( $e );
	exit( 1 );
}
