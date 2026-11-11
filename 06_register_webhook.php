<?php
/**
 * Example 6 - tell ResellingKori where to send webhooks, or switch webhooks off.
 *
 *   RK_KEY_ID=rkk_... RK_SECRET=rks_... php 06_register_webhook.php https://api.example.com/resellingkori/webhook
 *   RK_KEY_ID=rkk_... RK_SECRET=rks_... php 06_register_webhook.php --remove
 *   RK_KEY_ID=rkk_... RK_SECRET=rks_... php 06_register_webhook.php            (only shows the current state)
 *
 * Before you register: your receiver (05_webhook_receiver.php or your own) must already be reachable at that address,
 * because ResellingKori sends a signed "callback.verify" challenge to it at once and your receiver must answer it.
 *
 * Rules for the address: https, a public host name (not an IP address, not localhost, not a private network), port 443,
 * any path but no "?query", no redirects. Your key needs the permission webhooks:manage.
 * You may register 12 times per hour; fix the problem before you try again.
 *
 * Exit code 0 = webhook verified / removed / state shown, 1 = not verified or refused, 2 = wrong usage or configuration.
 */
require __DIR__ . '/../../clients/php/ResellingKoriClient.php';

$key    = getenv( 'RK_KEY_ID' );
$secret = getenv( 'RK_SECRET' );
if ( ! $key || ! $secret ) {
	fwrite( STDERR, "Set RK_KEY_ID and RK_SECRET first (see START-HERE.md).\n" );
	exit( 2 );
}
$args = array_slice( $argv, 1 );
if ( count( $args ) > 1 || ( $args && '--remove' !== $args[0] && 0 === strpos( $args[0], '--' ) ) ) {
	fwrite( STDERR, "Usage: php 06_register_webhook.php [https://your-address | --remove]\n" );
	exit( 2 );
}

$hints = array(
	'verified'    => 'ResellingKori reached your receiver and it answered the challenge correctly. Status changes will now be sent to it.',
	'rejected'    => 'The ADDRESS is not allowed (see the message). Fix the address - nothing was sent to it.',
	'unreachable' => 'ResellingKori could not connect (or got no answer within the time limit). Is the receiver online, on port 443, with a valid certificate?',
	'failed'      => 'Your receiver answered, but not with the correct proof. It must verify the signature and answer HTTP 200 with {"proof": ...} - use ResellingKoriClient::challengeProof().',
	'removed'     => 'The webhook is switched off. Follow your orders by polling (04_follow_orders.php).',
);

try {
	$rk = new ResellingKoriClient( $key, $secret, getenv( 'RK_BASE_URL' ) ? array( 'base_url' => getenv( 'RK_BASE_URL' ) ) : array() );
	if ( ! $args ) {
		$me = $rk->ping();
		$cb = $me['callback'];
		printf( "Webhook state: %s%s\n", $cb['state'], '' !== $cb['url'] ? ' - ' . $cb['url'] : '' );
		echo "  (none = nothing registered, pending = registered but the challenge has not been answered, verified = working)\n";
		exit( 0 );
	}
	$res = '--remove' === $args[0] ? $rk->removeWebhook() : $rk->registerWebhook( $args[0] );
} catch ( ResellingKoriException $e ) {
	fwrite( STDERR, "REFUSED: {$e->apiCode} (HTTP {$e->httpStatus}) {$e->getMessage()}\n" );
	if ( 'rkapi_scope_denied' === $e->apiCode ) {
		fwrite( STDERR, "  Your key has no webhooks:manage permission. Ask ResellingKori to add it.\n" );
	} elseif ( 'rkapi_rate_limited' === $e->apiCode ) {
		fwrite( STDERR, "  You registered too often. Wait an hour (or the time in Retry-After) and fix the problem first.\n" );
	}
	exit( 1 );
}

$r     = $res['callback_result'];
$state = is_array( $r ) ? $r['state'] : 'none';
printf( "Result: %s\n", $state );
if ( is_array( $r ) && '' !== (string) $r['message'] ) {
	printf( "  ResellingKori says: %s\n", $r['message'] );
}
if ( isset( $hints[ $state ] ) ) {
	printf( "  %s\n", $hints[ $state ] );
}
printf( "Registered address now: %s\n", '' !== $res['callback']['url'] ? $res['callback']['url'] . ' (' . $res['callback']['state'] . ')' : '(none)' );
exit( in_array( $state, array( 'verified', 'removed' ), true ) ? 0 : 1 );
