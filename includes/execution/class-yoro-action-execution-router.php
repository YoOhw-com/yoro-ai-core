<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Action_Execution_Router {

	private $providers = [];

	public function __construct( $providers = null ) {
		if ( null === $providers ) {
			$providers = [
				new YoRo_Native_Ability_Execution_Provider(),
				new YoRo_Local_Action_Execution_Provider(),
			];
		}

		$this->providers = is_array( $providers ) ? array_values( $providers ) : [];
	}

	public function execute( $action_name, array $action, $params = [], $context = [] ) {
		$context = is_array( $context ) ? $context : [];
		$started_at = microtime( true );
		$primary_candidates = [];
		$local_candidates = [];
		$rejections = [];

		foreach ( $this->providers as $provider ) {
			if ( ! $provider instanceof YoRo_Action_Execution_Provider ) {
				return $this->fail_closed( 'yoro_invalid_execution_provider', 'invalid_provider', $started_at );
			}

			$kind = (string) $provider->provider_kind();

			if ( ! in_array( $kind, [ 'local', 'native_ability', 'composite' ], true ) ) {
				return $this->fail_closed( 'yoro_invalid_execution_provider_kind', 'invalid_provider_kind', $started_at );
			}

			$decision = $provider->assess( $action_name, $action, $params, $context );

			if (
				! is_array( $decision ) ||
				! array_key_exists( 'eligible', $decision ) ||
				! is_bool( $decision['eligible'] ) ||
				empty( $decision['reason'] )
			) {
				return $this->fail_closed( 'yoro_invalid_execution_provider_decision', 'invalid_provider_decision', $started_at );
			}

			$candidate = [
				'provider' => $provider,
				'kind'     => $kind,
				'decision' => $decision,
			];

			if ( ! $decision['eligible'] ) {
				$rejections[] = $candidate;
				continue;
			}

			if ( 'local' === $kind ) {
				$local_candidates[] = $candidate;
			} else {
				$primary_candidates[] = $candidate;
			}
		}

		if ( count( $primary_candidates ) > 1 || count( $local_candidates ) > 1 ) {
			return $this->fail_closed( 'yoro_ambiguous_execution_provider', 'ambiguous_provider', $started_at );
		}

		if ( 1 === count( $primary_candidates ) ) {
			$selected = $primary_candidates[0];
		} elseif ( 1 === count( $local_candidates ) ) {
			$selected = $local_candidates[0];
			$selection_context = $this->first_non_local_rejection( $rejections );

			if ( is_array( $selection_context ) ) {
				$selected['decision']['reason'] = $selection_context['decision']['reason'];
				$selected['decision']['ability_id'] = $selection_context['decision']['ability_id'] ?? '';
				$selected['decision']['candidate_tranche'] = $selection_context['decision']['candidate_tranche'] ?? '';
			}
		} else {
			return $this->fail_closed( 'yoro_execution_provider_unavailable', 'provider_unavailable', $started_at );
		}

		$result = $selected['provider']->execute(
			$action_name,
			$action,
			$params,
			$context,
			$selected['decision']
		);

		return [
			'result'  => $result,
			'receipt' => $this->build_receipt(
				$action_name,
				$selected['kind'],
				$selected['decision'],
				$result,
				$started_at
			),
		];
	}

	private function first_non_local_rejection( array $rejections ) {
		foreach ( $rejections as $rejection ) {
			if ( 'local' !== ( $rejection['kind'] ?? '' ) ) {
				return $rejection;
			}
		}

		return null;
	}

	private function fail_closed( $error_code, $reason, $started_at ) {
		$error = new WP_Error( $error_code, __( 'YoRo could not establish one safe action execution provider.', 'yoro-ai-core' ) );

		return [
			'result'  => $error,
			'receipt' => $this->build_receipt( '', 'none', [ 'reason' => $reason ], $error, $started_at ),
		];
	}

	private function build_receipt( $action_name, $provider_kind, array $decision, $result, $started_at ) {
		$outcome = 'success';

		if ( is_wp_error( $result ) || ( is_array( $result ) && array_key_exists( 'success', $result ) && empty( $result['success'] ) ) ) {
			$outcome = 'error';
		}

		return [
			'semantic_action'    => (string) $action_name,
			'provider_kind'      => (string) $provider_kind,
			'selection_reason'   => (string) ( $decision['reason'] ?? '' ),
			'ability_id'         => (string) ( $decision['ability_id'] ?? '' ),
			'candidate_tranche'  => (string) ( $decision['candidate_tranche'] ?? '' ),
			'wordpress_version'  => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '',
			'woocommerce_version' => defined( 'WC_VERSION' ) ? (string) WC_VERSION : '',
			'duration_ms'        => max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) ),
			'outcome'            => $outcome,
		];
	}
}
