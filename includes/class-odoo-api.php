<?php
/**
 * Odoo API client (JSON-2 for Odoo 19+, JSON-RPC session fallback).
 *
 * @package GF_Odoo_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles authentication and API calls to Odoo.
 */
class Odoo_API {

	private const TRANSIENT_KEY   = 'gf_odoo_session';
	private const SESSION_TRANSIENT = 'gf_odoo_session';
	private const SESSION_TTL       = 1500; // 25 minutes (Odoo default session is ~30 minutes).
	private const REQUEST_TIMEOUT = 15;
	private const API_MODE_JSON2  = 'json2';
	private const API_MODE_JSONRPC = 'jsonrpc';

	/**
	 * @var string
	 */
	private $base_url;

	/**
	 * @var string
	 */
	private $api_key;

	/**
	 * @var string
	 */
	private $db;

	/**
	 * @var string
	 */
	private $login;

	/**
	 * @var string|null Session cookie value, or "json2" when using bearer auth.
	 */
	private $session_id = null;

	/**
	 * @var int|null
	 */
	private $uid = null;

	/**
	 * @var string
	 */
	private $api_mode = '';

	/**
	 * @var bool
	 */
	private $authenticated = false;

	/**
	 * @var string
	 */
	private $last_error = '';

	/**
	 * Create an Odoo API client for the given connection settings.
	 *
	 * @param string $base_url Odoo base URL (no trailing slash).
	 * @param string $api_key  API key (bearer token on Odoo 19+).
	 * @param string $db       Odoo database name.
	 * @param string $login    User login (required for JSON-RPC fallback).
	 */
	public function __construct( string $base_url, string $api_key, string $db, string $login ) {
		$this->base_url = untrailingslashit( $base_url );
		$this->api_key  = trim( $api_key );
		$this->db       = trim( $db );
		$this->login    = trim( $login );

		$this->load_session_from_transient();
	}

	/**
	 * Authenticate with Odoo.
	 *
	 * Odoo 19 uses the JSON-2 API with a bearer API key. Older instances use JSON-RPC sessions.
	 *
	 * @return bool
	 */
	public function authenticate(): bool {
		if ( $this->authenticated ) {
			if ( $this->verify_session() ) {
				return true;
			}
			$this->reset_authentication();
		} elseif ( $this->session_id && $this->api_mode && $this->verify_session() ) {
			$this->authenticated = true;
			return true;
		} elseif ( $this->session_id || $this->api_mode ) {
			$this->reset_authentication();
		}

		if ( '' === $this->base_url || '' === $this->api_key || '' === $this->db ) {
			$this->last_error = __( 'Missing Odoo URL, database name, or API key.', 'gf-odoo-connector' );
			return false;
		}

		$this->last_error = '';

		if ( $this->authenticate_json2() ) {
			$this->api_mode        = self::API_MODE_JSON2;
			$this->authenticated   = true;
			$this->session_id      = 'json2';
			$this->save_session_to_transient();
			return true;
		}

		if ( '' !== $this->login && $this->authenticate_jsonrpc() ) {
			$this->api_mode      = self::API_MODE_JSONRPC;
			$this->authenticated = true;
			$this->save_session_to_transient();
			return true;
		}

		$this->clear_session_transient();
		return false;
	}

	/**
	 * Human-readable error from the last authenticate() or API failure.
	 *
	 * @return string Empty when no error was recorded.
	 */
	public function get_last_error(): string {
		return $this->last_error;
	}

	/**
	 * Whether an Odoo error message indicates missing record access (AccessError).
	 *
	 * @param string $message Exception or API error text.
	 *
	 * @return bool
	 */
	public static function is_access_denied_message( string $message ): bool {
		$lower = strtolower( $message );

		$needles = array(
			'top-secret',
			'access error',
			'access denied',
			'not allowed to',
			'you are not allowed',
			'insufficient access',
			'permission',
		);

		foreach ( $needles as $needle ) {
			if ( str_contains( $lower, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Call an Odoo model method.
	 *
	 * @param string $model  Odoo model name.
	 * @param string $method Odoo method name.
	 * @param array  $args   Positional arguments (JSON-RPC style).
	 * @param array  $kwargs Keyword arguments.
	 *
	 * @return mixed
	 *
	 * @throws RuntimeException When authentication or the API call fails.
	 */
	public function call( string $model, string $method, array $args, array $kwargs = [] ) {
		if ( ! $this->authenticate() ) {
			throw new RuntimeException(
				$this->last_error ?: __( 'Could not authenticate with Odoo.', 'gf-odoo-connector' )
			);
		}

		try {
			return $this->execute_call( $model, $method, $args, $kwargs );
		} catch ( RuntimeException $e ) {
			if ( ! $this->is_session_expired_error( $e ) ) {
				throw $e;
			}

			$this->reset_authentication();

			if ( ! $this->authenticate() ) {
				throw new RuntimeException(
					$this->last_error ?: __( 'Odoo session expired and re-authentication failed.', 'gf-odoo-connector' )
				);
			}

			return $this->execute_call( $model, $method, $args, $kwargs );
		}
	}

	/**
	 * Clear cached Odoo session (call when connection settings change).
	 */
	public static function clear_cached_session(): void {
		self::clear_session();
	}

	/**
	 * Clear cached Odoo session (alias for settings save / cache clear).
	 */
	public static function clear_session(): void {
		delete_transient( self::TRANSIENT_KEY );
	}

	/**
	 * Verify the cached session is still valid (lightweight API probe).
	 *
	 * @return bool
	 */
	private function verify_session(): bool {
		if ( ! $this->session_id || '' === $this->api_mode ) {
			return false;
		}

		try {
			if ( self::API_MODE_JSON2 === $this->api_mode ) {
				$this->call_json2( 'res.users', 'search', array( array() ), array( 'limit' => 0 ) );
			} else {
				$response = $this->post_jsonrpc(
					'/web/dataset/call_kw',
					array(
						'model'  => 'res.users',
						'method' => 'search',
						'args'   => array( array() ),
						'kwargs' => array( 'limit' => 0 ),
					),
					true
				);
				$this->parse_jsonrpc_response( $response );
			}

			return true;
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * @param string $model  Model name.
	 * @param string $method Method name.
	 * @param array  $args   Positional args.
	 * @param array  $kwargs Keyword args.
	 *
	 * @return mixed
	 *
	 * @throws RuntimeException
	 */
	private function execute_call( string $model, string $method, array $args, array $kwargs = [] ) {
		if ( self::API_MODE_JSON2 === $this->api_mode ) {
			return $this->call_json2( $model, $method, $args, $kwargs );
		}

		$response = $this->post_jsonrpc(
			'/web/dataset/call_kw',
			array(
				'model'  => $model,
				'method' => $method,
				'args'   => $args,
				'kwargs' => $kwargs,
			),
			true
		);

		$data = $this->parse_jsonrpc_response( $response );

		return $data['result'] ?? null;
	}

	/**
	 * Reset auth state so the next request re-authenticates.
	 */
	private function reset_authentication(): void {
		$this->authenticated = false;
		$this->session_id    = null;
		$this->uid           = null;
		$this->api_mode      = '';
		$this->clear_session_transient();
	}

	/**
	 * @param RuntimeException $e API exception.
	 *
	 * @return bool
	 */
	private function is_session_expired_error( RuntimeException $e ): bool {
		$message = strtolower( $e->getMessage() );

		$needles = array(
			'session',
			'expired',
			'access denied',
			'access_denied',
			'invalid api key',
			'authentication',
		);

		foreach ( $needles as $needle ) {
			if ( false !== strpos( $message, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Active session identifier, or "json2" when using bearer API key auth.
	 *
	 * @return string|null Null when not authenticated.
	 */
	public function get_session_id(): ?string {
		return $this->session_id;
	}

	/**
	 * Verify credentials by authenticating and reading the current Odoo user.
	 *
	 * Clears any cached session before testing.
	 *
	 * @return array{success: bool, message: string} Result for the admin "Test Connection" UI.
	 */
	public function test_connection(): array {
		$this->session_id    = null;
		$this->uid           = null;
		$this->authenticated = false;
		$this->api_mode      = '';
		$this->clear_session_transient();

		try {
			if ( ! $this->authenticate() ) {
				$message = $this->last_error;

				if ( '' === $message ) {
					$message = __( 'Authentication failed. Check your API key and database name.', 'gf-odoo-connector' );
				}

				return array(
					'success' => false,
					'message' => $message,
				);
			}

			$name = $this->get_authenticated_user_name();

			return array(
				'success' => true,
				'message' => sprintf(
					/* translators: %s: Odoo user display name */
					__( 'Connected as %s', 'gf-odoo-connector' ),
					$name
				),
			);
		} catch ( RuntimeException $e ) {
			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * Authenticate via Odoo 19 JSON-2 bearer API key.
	 *
	 * @return bool
	 */
	private function authenticate_json2(): bool {
		try {
			$context = $this->request_json2( 'res.users', 'context_get', array() );

			if ( ! is_array( $context ) ) {
				$this->last_error = __( 'Unexpected response from Odoo JSON-2 API.', 'gf-odoo-connector' );
				return false;
			}

			$this->uid = isset( $context['uid'] ) ? (int) $context['uid'] : null;

			return true;
		} catch ( RuntimeException $e ) {
			$this->last_error = $e->getMessage();
			return false;
		}
	}

	/**
	 * Authenticate via legacy JSON-RPC session (Odoo 16–18).
	 *
	 * @return bool
	 */
	private function authenticate_jsonrpc(): bool {
		try {
			$response = $this->post_jsonrpc(
				'/web/session/authenticate',
				array(
					'db'       => $this->db,
					'login'    => $this->login,
					'password' => $this->api_key,
				),
				false
			);

			$data = $this->decode_http_json_body( $response );

			if ( isset( $data['error'] ) ) {
				$this->last_error = $this->extract_jsonrpc_error_message( $data['error'] );
				return false;
			}

			$result = $data['result'] ?? null;

			if ( empty( $result ) || false === $result ) {
				$this->last_error = __( 'Invalid credentials or database name.', 'gf-odoo-connector' );
				return false;
			}

			$this->session_id = $this->resolve_session_id( $response, $result );
			$this->uid        = isset( $result['uid'] ) ? (int) $result['uid'] : null;

			if ( empty( $this->session_id ) ) {
				$this->last_error = __( 'Authentication succeeded but no session was returned.', 'gf-odoo-connector' );
				return false;
			}

			return true;
		} catch ( RuntimeException $e ) {
			$this->last_error = $e->getMessage();
			return false;
		}
	}

	/**
	 * @param string $model  Model name.
	 * @param string $method Method name.
	 * @param array  $args   Positional args.
	 * @param array  $kwargs Keyword args.
	 *
	 * @return mixed
	 *
	 * @throws RuntimeException
	 */
	private function call_json2( string $model, string $method, array $args, array $kwargs = [] ) {
		return $this->request_json2( $model, $method, $this->build_json2_body( $method, $args, $kwargs ) );
	}

	/**
	 * Map JSON-RPC style args to JSON-2 named parameters.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Positional arguments.
	 * @param array  $kwargs Keyword arguments.
	 *
	 * @return array
	 */
	private function build_json2_body( string $method, array $args, array $kwargs ): array {
		$body = $kwargs;

		switch ( $method ) {
			case 'search_read':
			case 'search':
			case 'search_count':
				if ( isset( $args[0] ) ) {
					$body['domain'] = $args[0];
				}
				break;

			case 'read':
				if ( isset( $args[0] ) ) {
					$body['ids'] = $args[0];
				}
				break;

			case 'create':
				$vals = $args[0] ?? array();
				if ( is_array( $vals ) && isset( $vals[0] ) && is_array( $vals[0] ) ) {
					$body['vals_list'] = $vals;
				} elseif ( is_array( $vals ) ) {
					$body['vals_list'] = array( $vals );
				}
				break;

			case 'write':
				if ( isset( $args[0] ) ) {
					$body['ids'] = $args[0];
				}
				if ( isset( $args[1] ) ) {
					$body['vals'] = $args[1];
				}
				break;
		}

		return $body;
	}

	/**
	 * @param string $model  Model name.
	 * @param string $method Method name.
	 * @param array  $body   Request body.
	 *
	 * @return mixed
	 *
	 * @throws RuntimeException
	 */
	private function request_json2( string $model, string $method, array $body ) {
		$url = sprintf( '%s/json/2/%s/%s', $this->base_url, $model, $method );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => self::REQUEST_TIMEOUT,
				'headers' => array(
					'Content-Type'     => 'application/json; charset=utf-8',
					'Authorization'    => 'bearer ' . $this->api_key,
					'X-Odoo-Database'    => $this->db,
					'User-Agent'         => 'GF-Odoo-Connector/' . GF_ODOO_VERSION,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		return $this->parse_json2_response( $response );
	}

	/**
	 * @param array|\WP_Error $response HTTP response.
	 *
	 * @return mixed
	 *
	 * @throws RuntimeException
	 */
	private function parse_json2_response( $response ) {
		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( $this->format_http_error_message( $response ) );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );
		$data        = json_decode( $raw_body, true );

		if ( 404 === $status_code ) {
			throw new RuntimeException(
				__( 'Odoo JSON-2 API is not available on this server.', 'gf-odoo-connector' )
			);
		}

		if ( $status_code < 200 || $status_code >= 300 ) {
			$message = __( 'Odoo API request failed.', 'gf-odoo-connector' );

			if ( is_array( $data ) && ! empty( $data['message'] ) ) {
				$message = (string) $data['message'];
			} elseif ( 401 === $status_code ) {
				$message = __( 'Invalid API key. Generate a new key in Odoo (Preferences → Account Security → New API Key) and paste the full key here.', 'gf-odoo-connector' );
			} elseif ( 500 <= $status_code ) {
				$message = sprintf(
					/* translators: %d: HTTP status code */
					__( 'Odoo server error (HTTP %d). Please try again later.', 'gf-odoo-connector' ),
					$status_code
				);
			}

			throw new RuntimeException( $message );
		}

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new RuntimeException(
				__( 'Invalid JSON response from Odoo.', 'gf-odoo-connector' )
			);
		}

		return $data;
	}

	/**
	 * @param string $endpoint API path.
	 * @param array  $params   JSON-RPC params.
	 * @param bool   $use_session Send session cookie.
	 *
	 * @return array|\WP_Error
	 */
	private function post_jsonrpc( string $endpoint, array $params, bool $use_session ) {
		$headers = array(
			'Content-Type' => 'application/json',
		);

		if ( $use_session && $this->session_id && 'json2' !== $this->session_id ) {
			$headers['Cookie'] = 'session_id=' . $this->session_id;
		}

		return wp_remote_post(
			$this->base_url . $endpoint,
			array(
				'timeout' => self::REQUEST_TIMEOUT,
				'headers' => $headers,
				'body'    => wp_json_encode(
					array(
						'jsonrpc' => '2.0',
						'method'  => 'call',
						'params'  => $params,
						'id'      => wp_rand( 1, 999999 ),
					)
				),
			)
		);
	}

	/**
	 * @param array|\WP_Error $response HTTP response.
	 *
	 * @return array
	 *
	 * @throws RuntimeException
	 */
	private function parse_jsonrpc_response( $response ): array {
		$data = $this->decode_http_json_body( $response );

		if ( isset( $data['error'] ) ) {
			throw new RuntimeException( $this->extract_jsonrpc_error_message( $data['error'] ) );
		}

		return $data;
	}

	/**
	 * @param array|\WP_Error $response HTTP response.
	 *
	 * @return array
	 *
	 * @throws RuntimeException
	 */
	private function decode_http_json_body( $response ): array {
		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( $this->format_http_error_message( $response ) );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			if ( 500 <= $status_code ) {
				throw new RuntimeException(
					sprintf(
						/* translators: %d: HTTP status code */
						__( 'Odoo server error (HTTP %d). Please try again later.', 'gf-odoo-connector' ),
						$status_code
					)
				);
			}

			throw new RuntimeException(
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Odoo returned HTTP status %d.', 'gf-odoo-connector' ),
					$status_code
				)
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			throw new RuntimeException(
				__( 'Invalid JSON response from Odoo.', 'gf-odoo-connector' )
			);
		}

		return $data;
	}

	/**
	 * @param mixed $error JSON-RPC error payload.
	 *
	 * @return string
	 */
	private function extract_jsonrpc_error_message( $error ): string {
		if ( is_string( $error ) ) {
			return $error;
		}

		if ( ! is_array( $error ) ) {
			return __( 'Unknown Odoo API error.', 'gf-odoo-connector' );
		}

		if ( ! empty( $error['data']['message'] ) ) {
			return (string) $error['data']['message'];
		}

		if ( ! empty( $error['message'] ) ) {
			return (string) $error['message'];
		}

		return wp_json_encode( $error );
	}

	/**
	 * @param array|\WP_Error $response HTTP response.
	 * @param array           $result   Auth result.
	 *
	 * @return string|null
	 */
	private function resolve_session_id( $response, array $result ): ?string {
		if ( ! empty( $result['session_id'] ) ) {
			return (string) $result['session_id'];
		}

		foreach ( wp_remote_retrieve_cookies( $response ) as $cookie ) {
			if ( 'session_id' === $cookie->name && '' !== $cookie->value ) {
				return $cookie->value;
			}
		}

		return null;
	}

	/**
	 * @return string
	 *
	 * @throws RuntimeException
	 */
	private function get_authenticated_user_name(): string {
		if ( null !== $this->uid ) {
			try {
				$users = $this->call(
					'res.users',
					'read',
					array( array( $this->uid ) ),
					array( 'fields' => array( 'name' ) )
				);

				if ( is_array( $users ) && ! empty( $users[0]['name'] ) ) {
					return (string) $users[0]['name'];
				}
			} catch ( RuntimeException $e ) {
				if ( ! Odoo_API::is_access_denied_message( $e->getMessage() ) ) {
					throw $e;
				}
			}
		}

		return $this->login ?: __( 'API user', 'gf-odoo-connector' );
	}

	/**
	 * @return string
	 */
	private function get_transient_cache_key(): string {
		return md5( $this->base_url . '|' . $this->db . '|' . $this->login . '|' . $this->api_key );
	}

	/**
	 * Load cached auth state.
	 */
	private function load_session_from_transient(): void {
		$cached = get_transient( self::TRANSIENT_KEY );

		if ( ! is_array( $cached ) || ( $cached['cache_key'] ?? '' ) !== $this->get_transient_cache_key() ) {
			return;
		}

		$this->api_mode        = (string) ( $cached['api_mode'] ?? '' );
		$this->session_id      = $cached['session_id'] ?? null;
		$this->uid             = isset( $cached['uid'] ) ? (int) $cached['uid'] : null;
		$this->authenticated   = ! empty( $cached['authenticated'] );
	}

	/**
	 * Save auth state to transient.
	 */
	private function save_session_to_transient(): void {
		set_transient(
			self::TRANSIENT_KEY,
			array(
				'cache_key'      => $this->get_transient_cache_key(),
				'api_mode'       => $this->api_mode,
				'session_id'     => $this->session_id,
				'uid'            => $this->uid,
				'authenticated'  => $this->authenticated,
			),
			self::SESSION_TTL
		);
	}

	/**
	 * Clear cached auth state.
	 */
	private function clear_session_transient(): void {
		delete_transient( self::TRANSIENT_KEY );
	}

	/**
	 * @param \WP_Error $error WordPress HTTP API error.
	 *
	 * @return string
	 */
	private function format_http_error_message( $error ): string {
		if ( 'http_request_failed' === $error->get_error_code() ) {
			return __( 'Could not reach Odoo. Check the URL, your network connection, or try again later.', 'gf-odoo-connector' );
		}

		return $error->get_error_message();
	}
}
