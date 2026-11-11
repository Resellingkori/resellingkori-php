<?php
/**
 * Example 2 - copy the ResellingKori catalogue to your side and keep it up to date.
 *
 *   RK_KEY_ID=rkk_... RK_SECRET=rks_... php 02_sync_catalogue.php [--full] [--dir=./rk-data]
 *
 * First run (or --full): every product is downloaded. Later runs: only the products that changed since the newest
 * "date_modified" you already have. Run with --full about once a day: an incremental run cannot tell you that a
 * product has disappeared from ResellingKori's catalogue, a full run can (it lists them as "removed").
 *
 * Writes   <dir>/catalogue.json        { "<product id>": Product, ... }
 *          <dir>/catalogue.state.json  { "cursor": <unix seconds>, "last_full": <unix seconds> }
 *
 * This example keeps the data in a JSON file so that it runs anywhere. In your own system replace the two "SAVE"
 * places by writes to your database (key = the product "id"; update it when it is received again - that is normal).
 *
 * Exit code 0 = synchronised, 1 = ResellingKori refused or was not reachable, 2 = configuration problem.
 */
require __DIR__ . '/../../clients/php/ResellingKoriClient.php';

$key    = getenv( 'RK_KEY_ID' );
$secret = getenv( 'RK_SECRET' );
if ( ! $key || ! $secret ) {
	fwrite( STDERR, "Set RK_KEY_ID and RK_SECRET first (see START-HERE.md).\n" );
	exit( 2 );
}
$full = false;
$dir  = getenv( 'RK_DATA_DIR' ) ? getenv( 'RK_DATA_DIR' ) : __DIR__ . '/rk-data';
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( '--full' === $arg ) {
		$full = true;
	} elseif ( 0 === strpos( $arg, '--dir=' ) ) {
		$dir = substr( $arg, 6 );
	} else {
		fwrite( STDERR, "Unknown option $arg. Usage: php 02_sync_catalogue.php [--full] [--dir=./rk-data]\n" );
		exit( 2 );
	}
}
if ( ! is_dir( $dir ) && ! mkdir( $dir, 0700, true ) ) {
	fwrite( STDERR, "Cannot create the data folder $dir\n" );
	exit( 2 );
}

/** Read a JSON file; an unreadable or missing file counts as "nothing yet". */
function load_json( $file ) {
	$data = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
	return is_array( $data ) ? $data : array();
}

/** Write a JSON file so that a crash can never leave half a file behind. */
function save_json( $file, array $data ) {
	$tmp = $file . '.tmp' . getmypid();
	if ( false === file_put_contents( $tmp, json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) ) || ! rename( $tmp, $file ) ) {
		fwrite( STDERR, "Cannot write $file\n" );
		exit( 2 );
	}
}

$catalogue_file = $dir . '/catalogue.json';
$state_file     = $dir . '/catalogue.state.json';
$catalogue      = $full ? array() : load_json( $catalogue_file );
$old_ids        = array_keys( load_json( $catalogue_file ) );
$state          = load_json( $state_file );
$cursor         = ( ! $full && $catalogue && isset( $state['cursor'] ) ) ? (int) $state['cursor'] : null;

try {
	$rk = new ResellingKoriClient( $key, $secret, getenv( 'RK_BASE_URL' ) ? array( 'base_url' => getenv( 'RK_BASE_URL' ) ) : array() );
	if ( null === $cursor ) {
		echo "Full download of the catalogue...\n";
	} else {
		echo 'Incremental sync: products changed since ' . gmdate( 'Y-m-d H:i:s', $cursor ) . " UTC...\n";
	}
	$seen = array();
	$new  = 0;
	$upd  = 0;
	$max  = (int) $cursor;
	foreach ( $rk->eachProduct( $cursor, 100 ) as $product ) {
		$id = (string) $product['id'];
		if ( isset( $catalogue[ $id ] ) ) {
			++$upd;
		} else {
			++$new;
		}
		$catalogue[ $id ] = $product;                     // SAVE (1): insert or update by id.
		$seen[ $id ]      = true;
		$max              = max( $max, (int) strtotime( (string) $product['date_modified'] ) );
	}
} catch ( ResellingKoriException $e ) {
	fwrite( STDERR, "STOPPED: {$e->apiCode} (HTTP {$e->httpStatus}) {$e->getMessage()}\n" );
	if ( 'rkapi_scope_denied' === $e->apiCode ) {
		fwrite( STDERR, "  Your key has no catalog:read permission. Ask ResellingKori.\n" );
	}
	fwrite( STDERR, "  Nothing was saved; the next run starts from the same point.\n" );
	exit( 1 );
}

// A full download is the complete truth: whatever was stored before and was not received now is gone.
$removed = array();
if ( null === $cursor ) {
	$removed = array_values( array_diff( $old_ids, array_keys( $seen ) ) );
	foreach ( $removed as $id ) {
		unset( $catalogue[ $id ] );                       // SAVE (2): delete or hide it in your shop.
	}
}

save_json( $catalogue_file, $catalogue );
save_json(
	$state_file,
	array(
		'cursor'    => $max,                              // The newest change received; the next run asks "since then".
		'last_full' => null === $cursor ? time() : ( isset( $state['last_full'] ) ? (int) $state['last_full'] : 0 ),
	)
);

$in_stock = 0;
$variable = 0;
foreach ( $catalogue as $p ) {
	if ( 'outofstock' !== $p['stock_status'] && $p['purchasable'] ) {
		++$in_stock;
	}
	if ( 'variable' === $p['type'] ) {
		++$variable;
	}
}
printf( "Done. Received %d product(s): %d new, %d changed or unchanged-but-resent.\n", count( $seen ), $new, $upd );
if ( null === $cursor ) {
	printf( "      No longer in ResellingKori's catalogue: %d%s\n", count( $removed ), $removed ? ' (ids ' . implode( ', ', array_slice( $removed, 0, 10 ) ) . ( count( $removed ) > 10 ? ', ...' : '' ) . ')' : '' );
}
printf( "Your copy now holds %d product(s): %d can be ordered now, %d have variations (order those with rk_variation_id).\n", count( $catalogue ), $in_stock, $variable );
printf( "Saved in %s\n", $dir );

foreach ( array_slice( $catalogue, 0, 5 ) as $p ) {
	printf( "  #%-7s %-14s base price %10s  %-9s %s\n", $p['id'], $p['sku'], $p['base_price'], $p['stock_status'], $p['name'] );
}
exit( 0 );
