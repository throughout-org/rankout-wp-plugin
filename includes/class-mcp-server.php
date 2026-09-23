<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The MCP "Streamable HTTP" endpoint at `{namespace}/mcp`. RankOut's
 * backend opens one short-lived session per call via the official
 * @modelcontextprotocol/sdk TS client (see wpConnector.service.ts's
 * withMcpClient) — it never needs a long-lived resumable stream, so this
 * server is intentionally stateless: every POST is authenticated and
 * answered on its own, no Mcp-Session-Id bookkeeping. That's spec-legal —
 * the session id is optional when the server has no server-initiated
 * messages to deliver, and this one never does.
 */
class RankOut_Connector_MCP_Server {

	const PROTOCOL_VERSION = '2025-03-26';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			RANKOUT_CONNECTOR_NAMESPACE,
			'/mcp',
			array(
				array(
					'methods'             => 'POST',
					'permission_callback' => array( __CLASS__, 'authenticate' ),
					'callback'            => array( __CLASS__, 'handle_post' ),
				),
				array(
					'methods'             => 'GET',
					'permission_callback' => '__return_true',
					'callback'            => function () {
						return new WP_REST_Response( null, 405, array( 'Allow' => 'POST' ) );
					},
				),
				array(
					'methods'             => 'DELETE',
					'permission_callback' => '__return_true',
					'callback'            => function () {
						return new WP_REST_Response( null, 204 );
					},
				),
			)
		);
	}

	public static function authenticate( WP_REST_Request $request ) {
		$token_row = RankOut_Connector_Auth::authenticate( $request );
		if ( is_wp_error( $token_row ) ) {
			return $token_row;
		}
		$request->set_param( '_rankout_token_row', $token_row );
		return true;
	}

	public static function handle_post( WP_REST_Request $request ) {
		$token_row = $request->get_param( '_rankout_token_row' );
		$body      = $request->get_body();
		$message   = json_decode( $body, true );

		if ( null === $message && JSON_ERROR_NONE !== json_last_error() ) {
			return self::rpc_response( null, null, array( 'code' => -32700, 'message' => 'Parse error' ), 400 );
		}

		// Batches are legal JSON-RPC but nothing in this integration sends
		// one — handled anyway for spec completeness, each entry dispatched
		// independently and notifications (no `id`) dropped from the batch
		// response array.
		if ( self::is_list( $message ) ) {
			$responses = array();
			foreach ( $message as $single ) {
				$result = self::dispatch( $single, $token_row );
				if ( null !== $result ) {
					$responses[] = $result;
				}
			}
			return empty( $responses ) ? new WP_REST_Response( null, 202 ) : new WP_REST_Response( $responses, 200 );
		}

		$result = self::dispatch( is_array( $message ) ? $message : array(), $token_row );
		return null === $result ? new WP_REST_Response( null, 202 ) : new WP_REST_Response( $result, 200 );
	}

	private static function is_list( $value ) {
		return is_array( $value ) && array_values( $value ) === $value && ! empty( $value );
	}

	/**
	 * Dispatches one JSON-RPC message. Returns null for notifications
	 * (no `id` — per spec, notifications get no response at all).
	 */
	private static function dispatch( array $message, array $token_row ) {
		$id     = array_key_exists( 'id', $message ) ? $message['id'] : null;
		$method = isset( $message['method'] ) ? $message['method'] : '';
		$params = isset( $message['params'] ) && is_array( $message['params'] ) ? $message['params'] : array();
		$is_notification = ! array_key_exists( 'id', $message );

		switch ( $method ) {
			case 'initialize':
				return self::rpc_response(
					$id,
					array(
						'protocolVersion' => self::PROTOCOL_VERSION,
						'capabilities'    => array( 'tools' => new stdClass() ),
						'serverInfo'      => array( 'name' => 'rankout-connector', 'version' => RANKOUT_CONNECTOR_VERSION ),
					)
				);

			case 'notifications/initialized':
			case 'notifications/cancelled':
				return null;

			case 'tools/list':
				return $is_notification ? null : self::rpc_response( $id, array( 'tools' => self::list_tools( $token_row['scope'] ) ) );

			case 'tools/call':
				if ( $is_notification ) {
					return null;
				}
				return self::rpc_response( $id, self::call_tool( $params, $token_row ) );

			default:
				return $is_notification ? null : self::rpc_response( $id, null, array( 'code' => -32601, 'message' => 'Method not found: ' . $method ), 200, true );
		}
	}

	private static function list_tools( array $granted_scope ) {
		$tools = array();
		foreach ( RankOut_Connector_Tool_Registry::for_scopes( $granted_scope ) as $tool ) {
			$tools[] = array(
				'name'        => $tool['name'],
				'description' => $tool['description'],
				'inputSchema' => $tool['input_schema'],
				'annotations' => array( 'readOnlyHint' => $tool['read_only'] ),
			);
		}
		return $tools;
	}

	private static function call_tool( array $params, array $token_row ) {
		$name = isset( $params['name'] ) ? $params['name'] : '';
		$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

		$tool = RankOut_Connector_Tool_Registry::get( $name );
		if ( ! $tool ) {
			return self::tool_error( sprintf( 'Unknown tool "%s".', $name ) );
		}
		if ( ! RankOut_Connector_Scopes::grants( $token_row['scope'], $tool['required_scope'] ) ) {
			return self::tool_error( sprintf( 'This connection was not granted the "%s" permission required by "%s".', $tool['required_scope'], $name ) );
		}

		$before_snapshot = null;
		if ( ! $tool['read_only'] ) {
			$before_snapshot = RankOut_Connector_Tools_History::capture_snapshot( $name, $args );
		}

		try {
			$result = call_user_func( $tool['handler'], $args, (int) $token_row['wp_user_id'] );
		} catch ( Exception $exception ) {
			return self::tool_error( $exception->getMessage() );
		}

		if ( ! $tool['read_only'] ) {
			RankOut_Connector_Tools_History::record( $name, $args, $before_snapshot, $result, (int) $token_row['wp_user_id'] );
		}

		return array(
			'content' => array( array( 'type' => 'text', 'text' => wp_json_encode( $result ) ) ),
			'isError' => false,
		);
	}

	private static function tool_error( $message ) {
		return array(
			'content' => array( array( 'type' => 'text', 'text' => $message ) ),
			'isError' => true,
		);
	}

	private static function rpc_response( $id, $result = null, $error = null, $status = 200, $force_error_shape = false ) {
		$body = array( 'jsonrpc' => '2.0', 'id' => $id );
		if ( $error || $force_error_shape ) {
			$body['error'] = $error;
		} else {
			$body['result'] = null === $result ? new stdClass() : $result;
		}
		return new WP_REST_Response( $body, $status );
	}
}
