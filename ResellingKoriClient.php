<?php
/**
 * ResellingKori Partner API - PHP client (single file, no dependencies, PHP 7.4+ with ext-curl and ext-json).
 *
 * Gives any PHP website (Laravel, CodeIgniter, plain PHP, WordPress without WooCommerce ...) the same access the
 * ResellingKori Connector plugin has: signed requests (RK2), catalogue sync, dry-run checks, idempotent orders at
 * your own selling price with SAFE retries, order status, cancellation and webhook verification.
 *
 *   require 'ResellingKoriClient.php';
 *   $rk = new ResellingKoriClient( getenv('RK_KEY_ID'), getenv('RK_SECRET') );
 *   $me = $rk->ping();
 *   $page = $rk->listProducts( array( 'page' => 1, 'per_page' => 50 ) );
 *   $check = $rk->validateOrder( $order );   // nothing is created
 *   $res   = $rk->createOrder( $order );     // created once, even if the network fails half-way
 *
 * Keep the secret on your server (environment variable / config outside the web root). Never put it in browser
 * JavaScript or a mobile app: anyone holding it can order in your name.
 *
 * License: GPL-2.0-or-later (same as the ResellingKori plug-ins). Version 1.1.1.
 */

if ( ! class_exists( 'ResellingKoriException' ) ) {

	/** Every error from the API or the network. Branch on $e->apiCode and $e->retryable, not on the message. */
	class ResellingKoriException extends RuntimeException {

		/** @var string API error code such as "rkapi_validation", or "network" for transport failures. */
		public $apiCode;

		/** @var int HTTP status (0 for network errors). */
		public $httpStatus;

		/** @var array The "data" member of the error (fields[], changes[], shortages[], server_time ...). */
		public $data;

		/** @var bool True when sending the SAME request again later is safe and may succeed. */
		public $retryable;

		public function __construct( $message, $api_code, $http_status, array $data = array() ) {
			parent::__construct( $message, $http_status );
			$this->apiCode    = (string) $api_code;
			$this->httpStatus = (int) $http_status;
			$this->data       = $data;
			$this->retryable  = 0 === $http_status || $http_status >= 500 || in_array( $http_status, array( 408, 425, 429 ), true )
				|| in_array( $this->apiCode, array( 'rkapi_in_progress', 'rkapi_busy' ), true );
		}
	}
}

if ( ! class_exists( 'ResellingKoriClient' ) ) {

	class ResellingKoriClient {

		const VERSION = '1.1.1';

		/** @var string */
		private $key_id;

		/** @var string */
		private $secret;

		/** @var string Site part of the base URL, e.g. https://resellingkori.com */
		private $site;

		/** @var string REST route prefix used for signing, e.g. /rk/v1 */
		private $prefix;

		/** @var bool Base URL uses ?rest_route= (plain permalinks). */
		private $plain;

		/** @var int Seconds. */
		private $timeout;

		/** @var int Seconds added to time() (corrected automatically after a clock-skew answer). */
		private $clock_offset = 0;

		/** @var callable|null Transport override for tests: fn(method, url, headers[], body) => [status, body]. */
		private $transport;

		/**
		 * @param string $key_id  Key ID (rkk_...).
		 * @param string $secret  Secret (rks_...).
		 * @param array  $options { base_url, timeout, transport }.
		 */
		public function __construct( $key_id, $secret, array $options = array() ) {
			if ( ! preg_match( '/^rkk_[a-f0-9]{8,64}$/', (string) $key_id ) || '' === (string) $secret ) {
				throw new InvalidArgumentException( 'A ResellingKori key id (rkk_...) and secret are required.' );
			}
			$this->key_id    = (string) $key_id;
			$this->secret    = (string) $secret;
			$this->timeout   = isset( $options['timeout'] ) ? max( 5, (int) $options['timeout'] ) : 30;
			$this->transport = isset( $options['transport'] ) && is_callable( $options['transport'] ) ? $options['transport'] : null;
			$base            = isset( $options['base_url'] ) ? (string) $options['base_url'] : 'https://resellingkori.com/wp-json/rk/v1';
			$p               = parse_url( $base );
			if ( ! is_array( $p ) || empty( $p['scheme'] ) || empty( $p['host'] ) ) {
				throw new InvalidArgumentException( 'base_url is not a valid URL.' );
			}
			$this->site = $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
			$query      = array();
			if ( isset( $p['query'] ) ) {
				parse_str( $p['query'], $query );
			}
			if ( isset( $query['rest_route'] ) ) { // https://example.com/?rest_route=/rk/v1
				$this->plain  = true;
				$this->site  .= isset( $p['path'] ) ? rtrim( $p['path'], '/' ) : '';
				$this->prefix = '/' . trim( (string) $query['rest_route'], '/' );
			} else { // https://example.com/wp-json/rk/v1
				$path = isset( $p['path'] ) ? $p['path'] : '';
				$at   = strpos( $path, '/wp-json/' );
				if ( false === $at ) {
					throw new InvalidArgumentException( 'base_url must contain /wp-json/ (or ?rest_route=).' );
				}
				$this->plain  = false;
				$this->site  .= substr( $path, 0, $at + 8 ); // ... up to and including /wp-json
				$this->prefix = '/' . trim( substr( $path, $at + 8 ), '/' );
			}
		}

		/* ------------------------------------------------------------ Signing */

		/** RFC 3986 query, keys sorted (byte order), scalars only. */
		public static function buildQuery( array $params ) {
			ksort( $params, SORT_STRING );
			$parts = array();
			foreach ( $params as $k => $v ) {
				if ( null === $v ) {
					continue;
				}
				if ( ! is_scalar( $v ) ) {
					throw new InvalidArgumentException( 'Query parameters must be scalars.' );
				}
				if ( is_bool( $v ) ) {
					$v = $v ? '1' : '0';
				}
				$parts[] = rawurlencode( (string) $k ) . '=' . rawurlencode( (string) $v );
			}
			return implode( '&', $parts );
		}

		public static function sign( $secret, $timestamp, $nonce, $method, $route, $query, $body ) {
			$canonical = implode( "\n", array( 'RK2', (string) (int) $timestamp, (string) $nonce, strtoupper( $method ), $route, $query, hash( 'sha256', (string) $body ) ) );
			return hash_hmac( 'sha256', $canonical, (string) $secret );
		}

		/* ----------------------------------------------------------- Transport */

		/**
		 * One signed request. Returns the decoded JSON body; throws ResellingKoriException on any error.
		 *
		 * @param string      $method GET|POST.
		 * @param string      $path   Route below the prefix, e.g. "/orders".
		 * @param array       $query  Query parameters.
		 * @param string|null $body   Raw JSON body (sent byte-for-byte as signed).
		 * @return array
		 */
		public function request( $method, $path, array $query = array(), $body = null ) {
			$method = strtoupper( $method );
			$route  = $this->prefix . '/' . ltrim( $path, '/' );
			$q      = self::buildQuery( $query );
			$raw    = null === $body ? '' : (string) $body;
			for ( $try = 0; $try < 2; $try++ ) {
				$ts    = time() + $this->clock_offset;
				$nonce = bin2hex( random_bytes( 16 ) );
				$sig   = self::sign( $this->secret, $ts, $nonce, $method, $route, $q, $raw );
				$url   = $this->plain
					? $this->site . '/?rest_route=' . rawurlencode( $route ) . ( '' !== $q ? '&' . $q : '' )
					: $this->site . $route . ( '' !== $q ? '?' . $q : '' );
				$headers = array(
					'Accept: application/json',
					'User-Agent: ResellingKori-PHP-Client/' . self::VERSION,
					'X-RK-Key: ' . $this->key_id,
					'X-RK-Timestamp: ' . $ts,
					'X-RK-Nonce: ' . $nonce,
					'X-RK-Signature: ' . $sig,
				);
				if ( 'GET' !== $method ) {
					$headers[] = 'Content-Type: application/json';
				}
				list( $status, $resp ) = $this->send( $method, $url, $headers, $raw );
				$data = json_decode( (string) $resp, true );
				if ( $status >= 200 && $status < 300 && is_array( $data ) ) {
					return $data;
				}
				$code = is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : 'http_' . $status;
				$info = is_array( $data ) && isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array();
				// Our clock is off: adopt the server's and sign once more (new nonce, same body).
				if ( 0 === $try && 'rkapi_auth_expired' === $code && isset( $info['server_time'] ) ) {
					$this->clock_offset = (int) $info['server_time'] - time();
					continue;
				}
				$msg = is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : ( 0 === $status ? 'Network error: ' . $resp : 'Unexpected HTTP ' . $status . ' answer.' );
				throw new ResellingKoriException( $msg, 0 === $status ? 'network' : $code, $status, $info );
			}
			throw new ResellingKoriException( 'Clock could not be synchronised.', 'rkapi_auth_expired', 401 );
		}

		/** @return array [ status, body ]; status 0 = network failure (body = error text). */
		private function send( $method, $url, array $headers, $body ) {
			if ( $this->transport ) {
				return call_user_func( $this->transport, $method, $url, $headers, $body );
			}
			$ch = curl_init( $url );
			curl_setopt_array(
				$ch,
				array(
					CURLOPT_CUSTOMREQUEST  => $method,
					CURLOPT_HTTPHEADER     => $headers,
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_FOLLOWLOCATION => false,
					CURLOPT_TIMEOUT        => $this->timeout,
					CURLOPT_CONNECTTIMEOUT => 10,
					CURLOPT_SSL_VERIFYPEER => true,
					CURLOPT_SSL_VERIFYHOST => 2,
				)
			);
			if ( 'GET' !== $method ) {
				curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
			}
			$resp   = curl_exec( $ch );
			$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
			$err    = curl_error( $ch );
			curl_close( $ch );
			if ( false === $resp ) {
				return array( 0, $err );
			}
			return array( $status, $resp );
		}

		/* ---------------------------------------------------------- Endpoints */

		public function ping() {
			return $this->request( 'GET', '/ping' );
		}

		/** @param array $params page, per_page (<=100), updated_after (unix|ISO), category (slug), in_stock (bool), sku, include. */
		public function listProducts( array $params = array() ) {
			return $this->request( 'GET', '/products', $params );
		}

		/**
		 * Iterate over the whole catalogue (or everything changed since $updated_after), page by page.
		 *
		 * @return Generator yields Product arrays
		 */
		public function eachProduct( $updated_after = null, $per_page = 100 ) {
			$page = 1;
			do {
				$res = $this->listProducts( array( 'page' => $page, 'per_page' => $per_page, 'updated_after' => $updated_after ) );
				foreach ( $res['items'] as $item ) {
					yield $item;
				}
				++$page;
			} while ( $page <= (int) $res['total_pages'] );
		}

		public function categories( $hide_empty = false ) {
			return $this->request( 'GET', '/categories', $hide_empty ? array( 'hide_empty' => 1 ) : array() );
		}

		/** @param string $include '', 'details' or 'details,description'. */
		public function product( $id, $include = '' ) {
			return $this->request( 'GET', '/products/' . (int) $id, '' !== $include ? array( 'include' => $include ) : array() );
		}

		public function productsBySku( array $skus, $include = '' ) {
			$q = array( 'skus' => implode( ',', array_slice( array_map( 'strval', $skus ), 0, 50 ) ) );
			if ( '' !== $include ) {
				$q['include'] = $include;
			}
			return $this->request( 'GET', '/products/by-sku', $q );
		}

		public function searchProducts( $q, $per_page = 20 ) {
			return $this->request( 'GET', '/products/search', array( 'q' => (string) $q, 'per_page' => (int) $per_page ) );
		}

		/** Dry run: same checks as createOrder(), nothing is created or reserved. "schema" and "currency" default to 2 and BDT, as in createOrder(). */
		public function validateOrder( array $order ) {
			$order += array( 'schema' => 2, 'currency' => 'BDT' );
			return $this->request( 'POST', '/orders/validate', array(), self::encode( $order ) );
		}

		/**
		 * Create an order exactly once, even when an answer is lost.
		 *
		 * The body is serialised ONCE; after a timeout/5xx/busy answer the client asks ResellingKori whether the order
		 * exists (GET /orders/lookup) and only resends the identical body when it does not. A 4xx answer is final.
		 *
		 * @param array $order    Order (schema 2). "schema" and "currency" default to 2 and BDT.
		 * @param int   $attempts Total attempts (default 4).
		 * @return array { order, duplicate, recovered? }
		 */
		public function createOrder( array $order, $attempts = 4 ) {
			$order += array( 'schema' => 2, 'currency' => 'BDT' );
			if ( empty( $order['external_order_id'] ) ) {
				throw new InvalidArgumentException( 'external_order_id is required (your own order reference).' );
			}
			$body = self::encode( $order );
			$ext  = (string) $order['external_order_id'];
			$last = null;
			for ( $i = 0; $i < max( 1, (int) $attempts ); $i++ ) {
				if ( $i > 0 ) {
					sleep( min( 8, 1 << $i ) ); // 2, 4, 8 s.
					$found = $this->recover( $ext );
					if ( is_array( $found ) ) {
						return $found;
					}
					if ( 'creating' === $found ) {
						continue; // Still being created by our earlier attempt: wait, look again.
					}
				}
				try {
					return $this->request( 'POST', '/orders', array(), $body );
				} catch ( ResellingKoriException $e ) {
					if ( ! $e->retryable ) {
						throw $e;
					}
					$last = $e;
				}
			}
			throw $last ? $last : new ResellingKoriException( 'Order could not be confirmed.', 'network', 0 );
		}

		/** @return array|string|null Order answer, "creating", or null when ResellingKori has no such order. */
		private function recover( $ext ) {
			try {
				$res = $this->lookupOrder( $ext );
			} catch ( ResellingKoriException $e ) {
				if ( 'rkapi_order_not_found' === $e->apiCode ) {
					return null;
				}
				return $e->retryable ? 'creating' : null;
			}
			if ( empty( $res['order'] ) ) {
				return 'creating';
			}
			return array( 'order' => $res['order'], 'duplicate' => true, 'recovered' => true );
		}

		public function lookupOrder( $external_order_id ) {
			return $this->request( 'GET', '/orders/lookup', array( 'external_order_id' => (string) $external_order_id ) );
		}

		public function order( $id ) {
			return $this->request( 'GET', '/orders/' . (int) $id );
		}

		/** @param array $params page, per_page, status, updated_after, order (asc|desc). */
		public function listOrders( array $params = array() ) {
			return $this->request( 'GET', '/orders', $params );
		}

		public function orderStatuses( array $ids ) {
			return $this->request( 'GET', '/orders/status', array( 'ids' => implode( ',', array_map( 'intval', array_slice( $ids, 0, 100 ) ) ) ) );
		}

		public function cancelOrder( $external_order_id, $reason = '' ) {
			return $this->request( 'POST', '/orders/cancel', array(), self::encode( array( 'external_order_id' => (string) $external_order_id, 'reason' => (string) $reason ) ) );
		}

		/** Register your webhook address; ResellingKori immediately sends a callback.verify challenge to it. */
		public function registerWebhook( $url ) {
			return $this->request( 'POST', '/connection', array(), self::encode( array( 'callback_url' => (string) $url ) ) );
		}

		public function removeWebhook() {
			return $this->request( 'POST', '/connection', array(), self::encode( array( 'remove_callback' => true ) ) );
		}

		private static function encode( array $data ) {
			return json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
		}

		/* ---------------------------------------------------------- Webhooks */

		/**
		 * Verify a webhook delivery and return the decoded event. Throws ResellingKoriException (401/400) when invalid.
		 * YOU must still (a) reject an X-RK-Nonce / event id you have already processed and (b) ignore an event whose
		 * "sequence" is lower than the last one you applied for that order.
		 *
		 * For a "callback.verify" event, answer HTTP 200 with json_encode( ResellingKoriClient::challengeProof(...) ).
		 *
		 * @param string $key_id    Your key id.
		 * @param string $secret    Your secret.
		 * @param array  $headers   Request headers (any case), e.g. getallheaders().
		 * @param string $raw_body  The raw body: file_get_contents('php://input').
		 * @param int    $tolerance Seconds.
		 * @return array Event.
		 */
		public static function verifyWebhook( $key_id, $secret, array $headers, $raw_body, $tolerance = 300 ) {
			$h = array_change_key_case( $headers, CASE_LOWER );
			$get = function ( $n ) use ( $h ) {
				$v = isset( $h[ $n ] ) ? $h[ $n ] : '';
				return trim( is_array( $v ) ? (string) reset( $v ) : (string) $v );
			};
			$fail = function () {
				return new ResellingKoriException( 'Invalid webhook signature.', 'invalid_signature', 401 );
			};
			$ts  = $get( 'x-rk-timestamp' );
			$non = $get( 'x-rk-nonce' );
			$sig = strtolower( $get( 'x-rk-signature' ) );
			$eid = $get( 'x-rk-event-id' );
			if ( ! hash_equals( (string) $key_id, $get( 'x-rk-key' ) ) || ! preg_match( '/^\d{9,12}$/', $ts ) || ! preg_match( '/^[A-Za-z0-9_\-]{8,64}$/', $non ) || ! preg_match( '/^[a-f0-9]{64}$/', $sig ) ) {
				throw $fail();
			}
			if ( abs( time() - (int) $ts ) > $tolerance ) {
				throw $fail();
			}
			if ( ! hash_equals( self::sign( $secret, $ts, $non, 'POST', '/rkc/v1/webhook', '', (string) $raw_body ), $sig ) ) {
				throw $fail();
			}
			$event = json_decode( (string) $raw_body, true );
			if ( ! is_array( $event ) || ! isset( $event['id'], $event['type'] ) || (string) $event['id'] !== $eid ) {
				throw new ResellingKoriException( 'Malformed webhook body.', 'invalid_body', 400 );
			}
			return $event;
		}

		/** The answer to a callback.verify event: array( 'proof' => ... ). */
		public static function challengeProof( $secret, array $event ) {
			return array( 'proof' => hash_hmac( 'sha256', "RK2-CALLBACK\n" . (string) $event['challenge'], (string) $secret ) );
		}
	}
}
