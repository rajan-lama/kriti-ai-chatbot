<?php
/**
 * REST API: /wp-json/kriti-ai-chatbot/v1
 *
 * @package KritiAIChatbot
 */

namespace KritiAIChatbot;

defined( 'ABSPATH' ) || exit;

/**
 * REST routes.
 */
final class Rest_Controller {

	const NS = 'kriti-ai-chatbot/v1';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Permission callback for the knowledge routes.
	 *
	 * @return bool
	 */
	public static function can_manage_knowledge() {
		return current_user_can( Capabilities::KNOWLEDGE );
	}

	/**
	 * Routes.
	 *
	 * @return void
	 */
	public static function routes() {
		$public = '__return_true'; // Public by design; the handlers rate limit and validate.
		$admin  = array( __CLASS__, 'can_manage_knowledge' );

		register_rest_route(
			self::NS,
			'/chat/message',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'message' ),
				'permission_callback' => $public,
				'args'                => array(
					'message'         => array(
						'type'     => 'string',
						'required' => true,
					),
					'conversation_id' => array(
						'type'    => 'string',
						'default' => '',
					),
					'context'         => array(
						'type'    => 'object',
						'default' => array(),
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/chat/config',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'config' ),
				'permission_callback' => $public,
			)
		);

		register_rest_route(
			self::NS,
			'/chat/feedback',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'feedback' ),
				'permission_callback' => $public,
				'args'                => array(
					'conversation_id' => array(
						'type'     => 'string',
						'required' => true,
					),
					'message_id'      => array(
						'type'     => 'integer',
						'required' => true,
					),
					'rating'          => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'helpful', 'not_helpful' ),
					),
					'note'            => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/chat/session',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'session' ),
				'permission_callback' => $public,
			)
		);

		register_rest_route(
			self::NS,
			'/admin/knowledge/sources',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'sources' ),
				'permission_callback' => $admin,
			)
		);

		foreach ( array( 'sync', 'reindex', 'process', 'retry' ) as $action ) {
			register_rest_route(
				self::NS,
				'/admin/knowledge/' . $action,
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'knowledge_' . $action ),
					'permission_callback' => $admin,
				)
			);
		}

		register_rest_route(
			self::NS,
			'/admin/knowledge/jobs',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'jobs' ),
				'permission_callback' => $admin,
			)
		);

		register_rest_route(
			self::NS,
			'/admin/analytics',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'analytics' ),
				'permission_callback' => static function () {
					return current_user_can( Capabilities::ANALYTICS );
				},
			)
		);
	}

	/**
	 * POST /chat/message
	 *
	 * Response: {conversation_id, message: {id, role, content, citations: [{title, url}]}, answer_status, usage}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function message( \WP_REST_Request $request ) {
		$context = $request->get_param( 'context' );
		$result  = Chat_Service::handle(
			(string) $request->get_param( 'message' ),
			(string) $request->get_param( 'conversation_id' ),
			is_array( $context ) ? $context : array()
		);

		if ( is_wp_error( $result ) ) {
			return self::error_response( $result );
		}

		$citations = array();

		foreach ( $result['sources'] as $source ) {
			$citations[] = array(
				'title' => $source['title'],
				'url'   => $source['url'],
			);
		}

		$response = new \WP_REST_Response(
			array(
				'conversation_id' => $result['conversation_id'],
				'message'         => array(
					'id'        => $result['message_id'],
					'role'      => 'assistant',
					'content'   => $result['answer'],
					'citations' => $citations,
				),
				'answer_status'   => $result['answer_status'],
				'usage'           => $result['usage'],
				'request_id'      => $result['request_id'],
			),
			200
		);
		$response->header( 'X-Request-ID', $result['request_id'] );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * GET /chat/config
	 *
	 * @return \WP_REST_Response
	 */
	public static function config() {
		return new \WP_REST_Response( Chat_Service::public_config(), 200 );
	}

	/**
	 * POST /chat/feedback
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function feedback( \WP_REST_Request $request ) {
		$result = Chat_Service::feedback( (string) $request->get_param( 'conversation_id' ), (int) $request->get_param( 'message_id' ), (string) $request->get_param( 'rating' ), (string) $request->get_param( 'note' ) );

		return is_wp_error( $result ) ? self::error_response( $result ) : new \WP_REST_Response( array( 'recorded' => true ), 200 );
	}

	/**
	 * POST /chat/session
	 *
	 * @return \WP_REST_Response
	 */
	public static function session() {
		$result = Chat_Service::session();

		if ( is_wp_error( $result ) ) {
			return self::error_response( $result );
		}

		$response = new \WP_REST_Response( array( 'conversation_id' => $result['conversation_id'] ), 200 );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * GET /admin/knowledge/sources
	 *
	 * @return \WP_REST_Response
	 */
	public static function sources() {
		return new \WP_REST_Response(
			array(
				'summary'    => Sources::summary( Embedder::ready() ? Embedder::model() : '' ),
				'embeddings' => Knowledge_Store::counts(),
				'errors'     => Sources::recent_errors( 10 ),
			),
			200
		);
	}

	/**
	 * POST /admin/knowledge/sync: queue every page for a check.
	 *
	 * @return \WP_REST_Response
	 */
	public static function knowledge_sync() {
		return new \WP_REST_Response( Sync_Manager::start( false ), 200 );
	}

	/**
	 * POST /admin/knowledge/reindex: queue everything and process it again even if unchanged.
	 *
	 * @return \WP_REST_Response
	 */
	public static function knowledge_reindex() {
		return new \WP_REST_Response( Sync_Manager::start( true ), 200 );
	}

	/**
	 * POST /admin/knowledge/process: work through the next batch (the admin screen calls this in a loop).
	 *
	 * @return \WP_REST_Response
	 */
	public static function knowledge_process() {
		return new \WP_REST_Response( Sync_Manager::run( Sync_Manager::BATCH ), 200 );
	}

	/**
	 * POST /admin/knowledge/retry: put failed jobs back in the queue.
	 *
	 * @return \WP_REST_Response
	 */
	public static function knowledge_retry() {
		$count = Jobs::retry_failed();

		if ( $count ) {
			Sync_Manager::schedule_runner( 5 );
		}

		return new \WP_REST_Response(
			array(
				'retried' => $count,
				'jobs'    => Jobs::counts(),
			),
			200
		);
	}

	/**
	 * GET /admin/knowledge/jobs
	 *
	 * @return \WP_REST_Response
	 */
	public static function jobs() {
		return new \WP_REST_Response(
			array(
				'counts'   => Jobs::counts(),
				'failures' => Jobs::recent_failures( 10 ),
				'state'    => Sync_Manager::state(),
			),
			200
		);
	}

	/**
	 * GET /admin/analytics
	 *
	 * @return \WP_REST_Response
	 */
	public static function analytics() {
		return new \WP_REST_Response( Analytics::summary(), 200 );
	}

	/**
	 * Turn an error into the plugin's single error shape:
	 * {"error": {"code": "...", "message": "...", "request_id": "..."}}
	 *
	 * @param \WP_Error $error          Error.
	 * @param int       $default_status Status when the error carries none.
	 * @return \WP_REST_Response
	 */
	private static function error_response( \WP_Error $error, $default_status = 500 ) {
		$data   = $error->get_error_data();
		$data   = is_array( $data ) ? $data : array();
		$status = isset( $data['status'] ) ? (int) $data['status'] : $default_status;
		$body   = array(
			'error' => array(
				'code'       => $error->get_error_code(),
				'message'    => $error->get_error_message(),
				'request_id' => isset( $data['request_id'] ) ? $data['request_id'] : '',
			),
		);

		if ( isset( $data['retry_after'] ) ) {
			$body['error']['retry_after'] = (int) $data['retry_after'];
		}

		$response = new \WP_REST_Response( $body, $status );
		$response->header( 'Cache-Control', 'no-store' );

		if ( isset( $data['retry_after'] ) ) {
			$response->header( 'Retry-After', (string) (int) $data['retry_after'] );
		}

		return $response;
	}
}
