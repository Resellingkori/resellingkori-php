<?php
/**
 * Example 1 - check the connection.
 *
 *   RK_KEY_ID=rkk_... RK_SECRET=rks_... php 01_check_connection.php
 *
 * Optional: RK_BASE_URL (default https://resellingkori.com/wp-json/rk/v1).
 * Exit code 0 = everything is fine, 1 = ResellingKori refused or was not reachable, 2 = credentials missing.
 */
require __DIR__ . '/../../clients/php/ResellingKoriClient.php';

$key    = getenv( 'RK_KEY_ID' );
$secret = getenv( 'RK_SECRET' );
if ( ! $key || ! $secret ) {
	fwrite( STDERR, "Set RK_KEY_ID and RK_SECRET first (see START-HERE.md).\n" );
	exit( 2 );
}
$options = getenv( 'RK_BASE_URL' ) ? array( 'base_url' => getenv( 'RK_BASE_URL' ) ) : array();

$hints = array(
	'rkapi_auth_invalid'   => 'The signature does not match: a wrong secret or key id, or what you sign is not what you send (route, query, body).',
	'rkapi_auth_missing'   => 'The X-RK-* headers did not arrive. A proxy, firewall or hosting layer may be removing them.',
	'rkapi_auth_expired'   => 'Your server clock is off by more than five minutes. Fix the time (NTP) on your server.',
	'rkapi_key_revoked'    => 'This key was revoked or rotated. Ask ResellingKori for the new key.',
	'rkapi_store_disabled' => 'Your store is switched off at ResellingKori. Contact ResellingKori.',
	'rkapi_ip_denied'      => 'Your key only works from the server addresses registered with ResellingKori.',
	'rkapi_locked'         => 'Too many failed sign-ins from this address. Wait a few minutes, then fix the credentials.',
	'network'              => 'ResellingKori could not be reached. Check the internet connection, firewall and RK_BASE_URL.',
);

try {
	$rk = new ResellingKoriClient( $key, $secret, $options );
	$me = $rk->ping();
} catch ( InvalidArgumentException $e ) {
	fwrite( STDERR, 'Configuration problem: ' . $e->getMessage() . "\n" );
	exit( 2 );
} catch ( ResellingKoriException $e ) {
	fwrite( STDERR, "NOT CONNECTED: {$e->apiCode} (HTTP {$e->httpStatus}) {$e->getMessage()}\n" );
	if ( isset( $hints[ $e->apiCode ] ) ) {
		fwrite( STDERR, '  What to do: ' . $hints[ $e->apiCode ] . "\n" );
	}
	if ( 'rkapi_ip_denied' === $e->apiCode && isset( $e->data['ip'] ) ) {
		fwrite( STDERR, '  ResellingKori saw your server as ' . $e->data['ip'] . "\n" );
	}
	exit( 1 );
}

echo "Connected.\n";
printf( "  Store        : %s (platform: %s)\n", $me['store']['name'], $me['store']['platform'] );
printf( "  Key          : %s (%s)\n", $me['store']['key_id'], $me['store']['key_status'] );
printf( "  Permissions  : %s\n", implode( ', ', $me['store']['scopes'] ) );
printf( "  IP-restricted: %s\n", $me['store']['ip_restricted'] ? 'yes' : 'no' );
printf( "  Sales agent  : %s (%s)\n", $me['agent']['name'], $me['agent']['valid'] ? 'valid' : 'NOT VALID - orders will be refused, contact ResellingKori' );
printf( "  Webhook      : %s\n", $me['callback']['state'] . ( '' !== $me['callback']['url'] ? ' - ' . $me['callback']['url'] : '' ) );
printf( "  Clock        : your server differs from ResellingKori by %d s (the client corrects this automatically)\n", $me['server_time'] - time() );
printf( "  Limits       : %d requests/minute, up to %d order lines, order total up to %s, cash to collect up to %s\n", $me['limits']['requests_per_minute'], $me['limits']['max_lines'], $me['limits']['max_order_amount'], $me['limits']['max_cod_amount'] );

$missing = array_diff( array( 'catalog:read', 'orders:read', 'orders:write' ), $me['store']['scopes'] );
if ( $missing ) {
	echo '  Note: this key cannot use: ' . implode( ', ', $missing ) . ". Ask ResellingKori if your integration needs them.\n";
}
exit( 0 );
