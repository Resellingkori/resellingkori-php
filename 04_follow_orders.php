<?php
/**
 * Example 4 - follow your orders by asking ResellingKori (polling). Use this when you cannot receive webhooks,
 * or as a slow safety net next to them (every few hours).
 *
 *   RK_KEY_ID=rkk_... RK_SECRET=rks_... php 04_follow_orders.php [--loop=600] [--full] [--dir=./rk-data]
 *
 * Without --loop it asks once and stops - run it from cron every 5 to 15 minutes.
 * With --loop=SECONDS it keeps running and asks again after that many seconds.
 *
 * What it does
 *   1. asks for the orders that changed since the previous poll (the first poll asks for all of them),
 *   2. applies a change only when its "sequence" is higher than the one it already holds (never go backwards),
 *   3. prints every change, and lists orders that were deleted on ResellingKori's side ("gone"),
 *   4. only then saves <dir>/orders.state.json { "cursor": <server_time>, "last_full": ..., "orders": { "<your reference>": {...} } }.
 *
 * Once a day (and with --full) it asks for ALL orders instead of only the changed ones. Only that full poll can
 * tell you about an order that was deleted on ResellingKori's side: a deleted order does not "change", it is just gone.
 *
 * The cursor is the "server_time" that ResellingKori reported at the START of the poll, so an order that changes
 * while you are still reading the pages is simply received again next time.
 *
 * In your own system replace the part marked APPLY by an update of YOUR order (status, tracking number, e-mail).
 * Exit code 0 = fine, 1 = ResellingKori refused or was not reachable, 2 = configuration problem.
 */
require __DIR__ . '/../../clients/php/ResellingKoriClient.php';

$key    = getenv( 'RK_KEY_ID' );
$secret = getenv( 'RK_SECRET' );
if ( ! $key || ! $secret ) {
	fwrite( STDERR, "Set RK_KEY_ID and RK_SECRET first (see START-HERE.md).\n" );
	exit( 2 );
}
$loop  = 0;
$force = false;
$dir   = getenv( 'RK_DATA_DIR' ) ? getenv( 'RK_DATA_DIR' ) : __DIR__ . '/rk-data';
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( preg_match( '/^--loop=(\d+)$/', $arg, $m ) && (int) $m[1] >= 60 ) {
		$loop = (int) $m[1];
	} elseif ( '--full' === $arg ) {
		$force = true;
	} elseif ( 0 === strpos( $arg, '--dir=' ) ) {
		$dir = substr( $arg, 6 );
	} else {
		fwrite( STDERR, "Unknown option $arg. Usage: php 04_follow_orders.php [--loop=600 (at least 60)] [--full] [--dir=./rk-data]\n" );
		exit( 2 );
	}
}
if ( ! is_dir( $dir ) && ! mkdir( $dir, 0700, true ) ) {
	fwrite( STDERR, "Cannot create the data folder $dir\n" );
	exit( 2 );
}
$state_file = $dir . '/orders.state.json';

function load_state( $file ) {
	$data = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
	return is_array( $data ) && isset( $data['orders'] ) && is_array( $data['orders'] ) ? $data : array( 'cursor' => null, 'orders' => array() );
}

function save_state( $file, array $state ) {
	$tmp = $file . '.tmp' . getmypid();
	if ( false === file_put_contents( $tmp, json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) ) || ! rename( $tmp, $file ) ) {
		fwrite( STDERR, "Cannot write $file\n" );
		exit( 2 );
	}
}

/** One poll. Returns the number of changes applied. Throws ResellingKoriException. */
function poll( ResellingKoriClient $rk, $state_file, $force_full ) {
	$state   = load_state( $state_file );
	$full    = $force_full || empty( $state['cursor'] ) || time() - (int) ( isset( $state['last_full'] ) ? $state['last_full'] : 0 ) > 86400;
	$since   = $full ? null : $state['cursor'];
	if ( $full ) {
		echo "Full poll: asking for every order (once a day, and with --full; only a full poll can tell you about orders deleted on ResellingKori's side).\n";
	}
	$next    = null;
	$changes = 0;
	$gone    = array();
	$page    = 1;
	do {
		$res = $rk->listOrders( array( 'page' => $page, 'per_page' => 100, 'order' => 'asc', 'updated_after' => $since ) );
		if ( null === $next ) {
			$next = (int) $res['server_time'];
		}
		foreach ( $res['orders'] as $o ) {
			$ref  = (string) $o['external_order_id'];
			$have = isset( $state['orders'][ $ref ] ) ? $state['orders'][ $ref ] : null;
			if ( $have && (int) $o['sequence'] <= (int) $have['sequence'] ) {
				continue;                                    // Same or older than what we already applied: ignore.
			}
			$track = $o['tracking']['number'] ? trim( $o['tracking']['courier'] . ' ' . $o['tracking']['number'] ) : '';
			// APPLY: update YOUR order here (status, tracking number, notify your customer).
			printf(
				"[%s] %s (ResellingKori #%s): %s -> %s%s%s%s\n",
				gmdate( 'Y-m-d H:i:s', strtotime( $o['updated_at'] ) ) . ' UTC',
				$ref,
				$o['number'],
				$have ? $have['status'] : '(new to this script)',
				$o['status'],
				$track ? " | tracking $track" . ( $o['tracking']['url'] ? ' ' . $o['tracking']['url'] : '' ) : '',
				$o['error'] ? ' | NEEDS ATTENTION: ' . $o['error'] : '',
				$o['cancellable'] ? ' | can still be cancelled' : ''
			);
			$state['orders'][ $ref ] = array(
				'id'       => $o['id'],
				'status'   => $o['status'],
				'sequence' => (int) $o['sequence'],
				'tracking' => $track,
			);
			++$changes;
		}
		foreach ( $res['gone'] as $ref ) {
			$gone[ (string) $ref ] = true;
		}
		++$page;
	} while ( $page <= (int) $res['total_pages'] );

	foreach ( array_keys( $gone ) as $ref ) {
		echo "Order $ref was deleted on ResellingKori's side. Contact ResellingKori if you did not expect this.\n";
	}
	$state['cursor'] = $next;                                // Saved only now: a crash above simply repeats this poll.
	if ( $full ) {
		$state['last_full'] = $next;
	}
	save_state( $state_file, $state );
	return $changes;
}

$rk = new ResellingKoriClient( $key, $secret, getenv( 'RK_BASE_URL' ) ? array( 'base_url' => getenv( 'RK_BASE_URL' ) ) : array() );
do {
	try {
		$n     = poll( $rk, $state_file, $force );
		$force = false;                                      // --full applies to the first poll; later polls decide for themselves.
		printf( "%s UTC: %d change(s). Asking again %s.\n", gmdate( 'Y-m-d H:i:s' ), $n, $loop ? "in $loop s" : 'next time you run this script' );
	} catch ( ResellingKoriException $e ) {
		fwrite( STDERR, "POLL FAILED: {$e->apiCode} (HTTP {$e->httpStatus}) {$e->getMessage()}\n" );
		if ( ! $e->retryable || ! $loop ) {
			exit( 1 );
		}
		fwrite( STDERR, "  Will try again.\n" );
	}
	if ( $loop ) {
		sleep( $loop );
	}
} while ( $loop );
exit( 0 );
