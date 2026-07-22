<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Action_Definition_Factory {

	const CONTRACT_TYPE = 'yoro.semantic.action_definition';
	const SCHEMA_VERSION = 1;

	public static function prepare( $name, array $action ) {
		$name         = sanitize_text_field( (string) $name );
		$effect       = sanitize_key( $action['effect'] ?? ( ! empty( $action['write_action'] ) ? 'mutate' : 'read' ) );
		$write_action = ! in_array( $effect, [ 'read', 'draft' ], true );

		$action['action_contract'] = [
			'contract_type'   => self::CONTRACT_TYPE,
			'schema_version'  => self::SCHEMA_VERSION,
			'capability'      => $name,
			'safety'          => [
				'effect'                => $effect,
				'risk_level'            => sanitize_key( $action['risk_level'] ?? 'low' ),
				'reversibility'         => sanitize_key( $action['reversibility'] ?? ( $write_action ? 'partial' : 'full' ) ),
				'requires_confirmation' => ! empty( $action['requires_confirmation'] ) || ! empty( $action['write_action'] ),
			],
			'idempotency'     => [
				'strategy' => $write_action ? 'operation_envelope_key' : 'request_trace',
				'required' => $write_action,
			],
			'audit'           => [
				'event_type'      => 'connector_action_result',
				'result_required' => true,
			],
			'language'        => [
				'input'           => 'any_bcp47_unicode',
				'canonical_slots' => true,
				'output'          => 'match_latest_user',
				'fixed_allowlist' => false,
			],
		];

		return $action;
	}

	public static function with_semantic_schema( array $contract, array $semantic_contract ) {
		$contract['semantic_schema'] = [
			'operation_key'   => sanitize_key( $semantic_contract['operation_key'] ?? '' ),
			'intent_family'   => sanitize_key( $semantic_contract['intent_family'] ?? '' ),
			'canonical_slots' => true,
		];

		$ordered = [
			'contract_type'  => $contract['contract_type'] ?? self::CONTRACT_TYPE,
			'schema_version' => $contract['schema_version'] ?? self::SCHEMA_VERSION,
			'capability'     => $contract['capability'] ?? '',
			'semantic_schema'=> $contract['semantic_schema'],
			'safety'         => $contract['safety'] ?? [],
			'idempotency'    => $contract['idempotency'] ?? [],
			'audit'          => $contract['audit'] ?? [],
			'language'       => $contract['language'] ?? [],
		];

		return $ordered;
	}
}
