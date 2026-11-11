<?php
/**
 * Example 5 - receive ResellingKori's webhooks (order status changes).
 *
 *   Try it on your computer:
 *     RK_KEY_ID=rkk_... RK_SECRET=rks_... php -S 127.0.0.1:8099 05_webhook_receiver.php
 *
 *   In production this file (or its logic) runs on YOUR server behind https, for example
 *     https://api.example.com/resellingkori/webhook
 *   and you register that address with 06_register_webhook.php. Use any path you like, but no "?query".
 *
 * For every request the receiver
 *   1. accepts POST only and reads the RAW body (never decode and re-encode the JSON before checking the signature),
 *   2. verifies the key, the time (+-300 s) and the signature - a request that fails is answered 401/400 and ignored,
 *   3. answers the registration challenge ("callback.verify") with the proof,
 *   4. ignores a repeated nonce or an event it has already processed (it still answers 200, so that no retry follows),
 *   5. ignores an event whose "sequence" is not higher than the one already applied for that order,
 *   6. stores the event and answers 200 quickly.
 *
 * Do the real work (update your shop's order, e-mail your customer) in a worker that reads what step 6 stored:
 * ResellingKori waits at most 10 seconds for your answer. Any 2xx counts as delivered; network errors, 5xx, 408, 425
 * and 429 are retried after 1, 3, 9, 27 and 81 minutes; other 4xx are not retried.
 *
 * Stored in <data folder> (default ./rk-data): orders/<id>.json = latest state of each order, webhook-events.ndjson =
 * one line per applied event, seen/ = the nonces and event ids already handled (old ones are removed after a day).
 */
require __DIR__ . '/../../clients/php/ResellingKoriClient.php';

$key    = getenv( 'RK_KEY_ID' );
$secret = getenv( 'RK_SECRET' );
$dir    = getenv( 'RK_DATA_DIR' ) ? rtrim( getenv( 'RK_DATA_DIR' ), '/\\' ) : __DIR__ . '/rk-data';

/** Answer with a small JSON body and stop. */
function reply( $status, array $body ) {
	http_response_code( $status );
	header( 'Content-Type: application/json' );
	echo json_encode( $body );
	exit;
}

/** Request headers in any server setup (Apache, nginx + FPM, PHP's own server). */
function request_headers() {
	if ( function_exists( 'getallheaders' ) ) {
		$h = getallheaders();
		if ( is_array( $h ) && $h ) {
			return $h;
		}
	}
	$out = array();
	foreach ( $_SERVER as $name => $value ) {
		if ( 0 === strpos( $name, 'HTTP_' ) ) {
			$out[ str_replace( '_', '-', strtolower( substr( $name, 5 ) ) ) ] = $value;
		}
	}
	return $out;
}

/** True the first time a value is seen, false every later time. Atomic: the file is created exclusively. */
function first_time( $dir, $kind, $value ) {
	$fh = @fopen( $dir . '/seen/' . $kind . '-' . hash( 'sha256', (string) $value ), 'x' );
	if ( false === $fh ) {
		return false;
	}
	fclose( $fh );
	return true;
}

function already_done( $dir, $event_id ) {
	return is_file( $dir . '/seen/event-' . hash( 'sha256', (string) $event_id ) );
}

/** Remove remembered nonces/event ids older than a day (called now and then). */
function prune( $dir ) {
	foreach ( (array) glob( $dir . '/seen/*' ) as $f ) {
		if ( is_file( $f ) && filemtime( $f ) < time() - 86400 ) {
			@unlink( $f );
		}
	}
}

/**
 * Keep the order only if its sequence is higher than the one already stored. The lock makes two deliveries that
 * arrive at the same moment safe. Returns true when the order was applied, false when it was old news.
 */
function apply_if_newer( $dir, array $order ) {
	$fh = fopen( $dir . '/orders/' . (int) $order['id'] . '.json', 'c+' );
	if ( false === $fh ) {
		throw new RuntimeException( 'Cannot open the order store.' );
	}
	flock( $fh, LOCK_EX );
	$old   = json_decode( (string) stream_get_contents( $fh ), true );
	$fresh = ! is_array( $old ) || (int) $order['sequence'] > (int) $old['sequence'];
	if ( $fresh ) {
		ftruncate( $fh, 0 );
		rewind( $fh );
		fwrite( $fh, json_encode( $order, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) );
		fflush( $fh );
	}
	flock( $fh, LOCK_UN );
	fclose( $fh );
	return $fresh;
}

/* ---------------------------------------------------------------------------------------------------------- 1 */
if ( ! $key || ! $secret ) {
	error_log( 'ResellingKori webhook receiver: RK_KEY_ID and RK_SECRET are not set.' );
	reply( 500, array( 'ok' => false, 'error' => 'receiver_not_configured' ) );
}
if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
	reply( 405, array( 'ok' => false, 'error' => 'post_only' ) );
}
$raw = (string) file_get_contents( 'php://input' );
if ( strlen( $raw ) > 262144 ) {
	reply( 413, array( 'ok' => false, 'error' => 'too_large' ) );
}
foreach ( array( '', '/seen', '/orders' ) as $sub ) {
	if ( ! is_dir( $dir . $sub ) && ! @mkdir( $dir . $sub, 0700, true ) && ! is_dir( $dir . $sub ) ) {
		error_log( "ResellingKori webhook receiver: cannot create $dir$sub" );
		reply( 500, array( 'ok' => false, 'error' => 'storage_unavailable' ) );
	}
}

/* ---------------------------------------------------------------------------------------------------------- 2 */
$headers = request_headers();
try {
	$event = ResellingKoriClient::verifyWebhook( $key, $secret, $headers, $raw );
} catch ( ResellingKoriException $e ) {
	error_log( 'ResellingKori webhook rejected: ' . $e->getMessage() );
	reply( $e->httpStatus ? $e->httpStatus : 401, array( 'ok' => false ) );
}

/* ---------------------------------------------------------------------------------------------------------- 3 */
if ( 'callback.verify' === $event['type'] ) {
	error_log( 'ResellingKori webhook: registration challenge answered.' );
	reply( 200, ResellingKoriClient::challengeProof( $secret, $event ) );
}

/* ---------------------------------------------------------------------------------------------------------- 4 */
$headers_lc = array_change_key_case( $headers, CASE_LOWER );
$nonce      = isset( $headers_lc['x-rk-nonce'] ) ? (string) $headers_lc['x-rk-nonce'] : '';
if ( 1 === mt_rand( 1, 100 ) ) {
	prune( $dir );
}
if ( ! first_time( $dir, 'nonce', $nonce ) || already_done( $dir, $event['id'] ) ) {
	reply( 200, array( 'ok' => true, 'duplicate' => true ) );
}

/* ------------------------------------------------------------------------------------------------- 5 and 6 */
// Every event has data.order. An event type you do not know yet still means "this order changed": use data.order.
if ( empty( $event['data']['order'] ) || ! is_array( $event['data']['order'] ) ) {
	reply( 200, array( 'ok' => true, 'ignored' => 'no_order' ) );
}
$order = $event['data']['order'];
try {
	$applied = apply_if_newer( $dir, $order );
	if ( $applied ) {
		file_put_contents(
			$dir . '/webhook-events.ndjson',
			json_encode(
				array(
					'received_at' => gmdate( 'c' ),
					'event_id'    => $event['id'],
					'type'        => $event['type'],
					'sequence'    => $order['sequence'],
					'reference'   => $order['external_order_id'],
					'status'      => $order['status'],
					'order'       => $order,
				),
				JSON_UNESCAPED_SLASHES
			) . "\n",
			FILE_APPEND | LOCK_EX
		);
	}
	first_time( $dir, 'event', $event['id'] );   // Marked only after the event was stored: a crash above means a retry is processed.
} catch ( Exception $e ) {
	error_log( 'ResellingKori webhook: could not store the event: ' . $e->getMessage() );
	reply( 500, array( 'ok' => false, 'error' => 'storage_failed' ) );  // 5xx: ResellingKori will send it again later.
}
error_log( sprintf( 'ResellingKori webhook: %s order %s (#%s) is now "%s" (sequence %d)%s', $event['type'], $order['external_order_id'], $order['number'], $order['status'], $order['sequence'], $applied ? '' : ' - old news, ignored' ) );
reply( 200, array( 'ok' => true, 'applied' => $applied ) );
