<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'YORO_AI_PROVIDER_YORO_AI_API' ) ) {
	define( 'YORO_AI_PROVIDER_YORO_AI_API', 'yoro_ai_api' );
}

class YoRo_AI_Parser {

	const PROMPT_VERSION_PARSE = 'parse.v1';
	const PROMPT_VERSION_WORKFLOW = 'workflow.v1';
	const PROMPT_VERSION_ACTION_DECOMPOSITION = 'action_decomposition.v1';
	const PROMPT_VERSION_SUGGESTIONS = 'suggestions.v1';
	const PROMPT_VERSION_EMAIL = 'email.v2';
	const PROMPT_VERSION_CONVERSATION = 'conversation.v3';
	const PROMPT_VERSION_COMMAND_ROUTER = 'command_router.v2';

	public static function parse( $command ) {
		return new WP_Error(
			'yoro_ai_parse_removed',
			__( 'Local AI parse is no longer available in the plugin. Use YoRo AI API command routing instead.', 'yoro-ai-core' )
		);
	}

	public static function parse_freeform( $prompt ) {
		return YoRo_AI_Provider_Resolver::get_client()->generate_suggestions(
			[
				'prompt' => $prompt,
			]
		);
	}

	public static function parse_workflow_plan( $prompt ) {
		return self::parse_workflow_plan_yoro_ai_api( $prompt );
	}

	public static function parse_action_decomposition( array $payload ) {
		return new WP_Error(
			'yoro_ai_api_action_decomposition_not_ready',
			__( 'YoRo AI API action decomposition is not ready.', 'yoro-ai-core' )
		);
	}

	public static function compose_email( WC_Order $order, $instruction ) {
		$mock_result = apply_filters(
			'yoro_ai_compose_email_mock_result',
			null,
			$order,
			$instruction
		);

		if ( null !== $mock_result ) {
			return $mock_result;
		}

		if ( ! YoRo_AI_Provider_Resolver::supports( 'email_compose' ) ) {
			return new WP_Error(
				'yoro_ai_missing_api_key',
				__( 'The AI service is not configured yet.', 'yoro-ai-core' )
			);
		}

		$order_details = class_exists( 'YoRo_Order_Presenter' )
			? YoRo_Order_Presenter::format_order_details( $order )
			: [];

		$requires_shipping = array_key_exists( 'requires_shipping', $order_details )
			? ! empty( $order_details['requires_shipping'] )
			: ( method_exists( $order, 'needs_shipping_address' ) ? (bool) $order->needs_shipping_address() : null );
		$tracking_detected = array_key_exists( 'tracking_detected', $order_details )
			? ! empty( $order_details['tracking_detected'] )
			: null;
		$shipping_complete = array_key_exists( 'shipping_complete', $order_details )
			? ! empty( $order_details['shipping_complete'] )
			: null;
		$ready_to_fulfill = array_key_exists( 'ready_to_fulfill', $order_details )
			? ! empty( $order_details['ready_to_fulfill'] )
			: null;

		$fulfillment_state = 'unknown';

		if ( false === $requires_shipping ) {
			$fulfillment_state = 'no_shipping_required';
		} elseif ( true === $tracking_detected ) {
			$fulfillment_state = 'tracking_available';
		} elseif ( true === $requires_shipping && false === $shipping_complete ) {
			$fulfillment_state = 'address_incomplete';
		} elseif ( true === $requires_shipping && false === $ready_to_fulfill ) {
			$fulfillment_state = 'not_ready_to_fulfill';
		} elseif ( true === $requires_shipping ) {
			$fulfillment_state = 'pre_shipment';
		}

		$order_context = [
			'order_number'        => $order->get_order_number(),
			'status'              => $order->get_status(),
			'total'               => html_entity_decode( wp_strip_all_tags( wc_price( $order->get_total(), [ 'currency' => $order->get_currency() ] ) ) ),
			'currency'            => $order->get_currency(),
			'customer_name'       => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'payment_method'      => $order->get_payment_method_title(),
			'shipping_name'       => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ),
			'shipping_city'       => $order->get_shipping_city(),
			'shipping_state'      => $order->get_shipping_state(),
			'shipping_country'    => $order->get_shipping_country(),
			'shipping_method'     => sanitize_text_field( $order_details['shipping_method'] ?? '' ),
			'item_count'          => isset( $order_details['item_count'] ) ? absint( $order_details['item_count'] ) : (int) $order->get_item_count(),
			'line_items'          => isset( $order_details['line_items'] ) && is_array( $order_details['line_items'] ) ? array_slice( $order_details['line_items'], 0, 5 ) : [],
			'requires_shipping'   => $requires_shipping,
			'tracking_detected'   => $tracking_detected,
			'shipping_complete'   => $shipping_complete,
			'ready_to_fulfill'    => $ready_to_fulfill,
			'fulfillment_state'   => $fulfillment_state,
			'fulfillment_guidance'=> [
				'tracking_available' => 'Safe to say the package has shipped or is on the way. You may mention tracking updates.',
				'pre_shipment'       => 'Do not say shipped, dispatched, handed to carrier, or on the way. Use wording like being prepared for shipment or will ship soon.',
				'not_ready_to_fulfill' => 'Avoid promising dispatch timing. Explain the order is still being prepared or reviewed before shipment.',
				'no_shipping_required' => 'Do not mention shipping, dispatch, carrier, or tracking. Focus on processing, delivery by email/download, or next steps only if relevant.',
				'address_incomplete' => 'Do not imply shipment progress. If shipping details are incomplete, keep the message cautious and avoid carrier/tracking claims.',
			],
		];

		$trace_id = self::generate_trace_id();
		$payload = YoRo_AI_Email_Prompt::build( $order_context, $instruction );
		$result  = YoRo_AI_Provider_Resolver::get_client()->compose_email( $payload );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$parsed = YoRo_AI_Email_Result_Normalizer::decode( $result );

		if ( ! is_array( $parsed ) ) {
			return new WP_Error(
				'yoro_ai_invalid_email',
				__( 'I could not prepare a usable email draft from the AI response.', 'yoro-ai-core' )
			);
		}

		return [
			'to'       => sanitize_email( $order->get_billing_email() ),
			'subject'  => $parsed['subject'],
			'body'     => $parsed['body'],
			'template' => 'ai_custom',
			'meta'     => self::build_meta(
				'email_compose',
				[
					'trace_id'       => $trace_id,
					'prompt_version' => self::PROMPT_VERSION_EMAIL,
				]
			),
		];
	}

	public static function parse_conversation( $command, $client_context = [] ) {
		$client = YoRo_AI_Provider_Resolver::get_client();

		if ( ! $client instanceof YoRo_AI_API_Client ) {
			return new WP_Error(
				'yoro_ai_api_client_unavailable',
				__( 'YoRo AI API client is not available.', 'yoro-ai-core' )
			);
		}

		$router_hints = class_exists( 'YoRo_Action_Catalog_Builder' ) && method_exists( 'YoRo_Action_Catalog_Builder', 'build_router_hints' )
			? YoRo_Action_Catalog_Builder::build_router_hints( $command, $client_context )
			: [];
		$catalog = class_exists( 'YoRo_Action_Catalog_Builder' ) && method_exists( 'YoRo_Action_Catalog_Builder', 'build_for_command' )
			? YoRo_Action_Catalog_Builder::build_for_command( $command, $client_context )
			: [];

		$output_text = $client->parse_intent(
			[
				'feature'        => 'command_router',
				'command'        => (string) $command,
				'site_url'       => home_url(),
				'plugin_version' => defined( 'YORO_AI_CORE_VERSION' ) ? YORO_AI_CORE_VERSION : '',
				'context'        => is_array( $client_context ) ? $client_context : [],
				'router_hints'   => is_array( $router_hints ) ? $router_hints : [],
				'action_catalog' => is_array( $catalog ) ? array_values( $catalog ) : [],
			]
		);

		if ( is_wp_error( $output_text ) ) {
			return $output_text;
		}

		$parsed = json_decode( (string) $output_text, true );

		return is_array( $parsed ) ? $parsed : new WP_Error(
			'yoro_ai_api_conversation_invalid_response',
			__( 'I could not understand the YoRo AI API conversation response.', 'yoro-ai-core' )
		);
	}

	private static function get_provider() {
		return YoRo_AI_Provider_Resolver::resolve_provider();
	}

	public static function build_meta( $feature, array $overrides = [] ) {
		$feature = sanitize_key( $feature );
		$meta = [
			'feature'        => $feature,
			'provider'       => self::get_provider(),
			'model'          => 'yoro-ai-api',
			'prompt_version' => self::get_prompt_version_for_feature( $feature ),
			'trace_id'       => self::generate_trace_id(),
		];

		foreach ( $overrides as $key => $value ) {
			$meta[ sanitize_key( $key ) ] = is_string( $value )
				? sanitize_text_field( $value )
				: $value;
		}

		return $meta;
	}

	private static function generate_trace_id() {
		return 'yoro_ai_' . wp_generate_uuid4();
	}

	private static function get_prompt_version_for_feature( $feature ) {
		switch ( sanitize_key( $feature ) ) {
			case 'workflow_plan':
				return self::PROMPT_VERSION_WORKFLOW;
			case 'action_decomposition':
				return self::PROMPT_VERSION_ACTION_DECOMPOSITION;
			case 'suggestions':
				return self::PROMPT_VERSION_SUGGESTIONS;
			case 'email_compose':
				return self::PROMPT_VERSION_EMAIL;
			case 'conversation':
				return self::PROMPT_VERSION_CONVERSATION;
			case 'command_router':
				return self::PROMPT_VERSION_COMMAND_ROUTER;
			case 'parse':
			default:
				return self::PROMPT_VERSION_PARSE;
		}
	}

	private static function parse_workflow_plan_yoro_ai_api( $prompt ) {
		$payload = json_decode( (string) $prompt, true );

		if ( ! is_array( $payload ) ) {
			return new WP_Error(
				'yoro_ai_workflow_invalid_payload',
				__( 'I could not prepare a workflow planning request.', 'yoro-ai-core' )
			);
		}

		$client = YoRo_AI_Provider_Resolver::get_client();

		if ( ! $client instanceof YoRo_AI_API_Client ) {
			return new WP_Error(
				'yoro_ai_workflow_client_unavailable',
				__( 'YoRo AI API workflow planner is not available.', 'yoro-ai-core' )
			);
		}

		$output_text = $client->plan_workflow( $payload );

		if ( is_wp_error( $output_text ) ) {
			return $output_text;
		}

		if ( empty( $output_text ) ) {
			return new WP_Error(
				'yoro_ai_workflow_empty_response',
				__( 'I could not build a workflow from the AI response.', 'yoro-ai-core' )
			);
		}

		return $output_text;
	}

	public static function get_confidence_threshold() {
		$threshold = get_option( 'yoro_ai_confidence_threshold', 0.75 );

		return max( 0, min( (float) $threshold, 1 ) );
	}

	public static function get_read_only_confidence_threshold() {
		$threshold = get_option( 'yoro_ai_read_only_confidence_threshold', 0.6 );

		return max( 0, min( (float) $threshold, 1 ) );
	}

}
