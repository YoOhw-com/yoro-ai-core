<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Core {

	public static function init() {
		self::includes();
		self::boot();
	}

	private static function includes() {
		self::include_core();
		self::include_ai();
		self::include_workflows();
		self::include_parsing();
		self::include_actions();
		self::include_execution();
		self::include_services();
		self::include_dev();
		self::include_rest();

		if ( is_admin() ) {
			self::include_admin();
		}
	}

	private static function include_core() {
		require_once YORO_AI_CORE_PATH . 'includes/core/class-yoro-action-cache.php';
		require_once YORO_AI_CORE_PATH . 'includes/core/class-yoro-logger.php';
		require_once YORO_AI_CORE_PATH . 'includes/core/class-yoro-db.php';
		require_once YORO_AI_CORE_PATH . 'includes/core/class-yoro-rate-limiter.php';
		require_once YORO_AI_CORE_PATH . 'includes/core/class-yoro-date-helper.php';
		require_once YORO_AI_CORE_PATH . 'includes/core/class-yoro-response-ux-formatter.php';
		require_once YORO_AI_CORE_PATH . 'includes/core/class-yoro-conversation-memory.php';
		require_once YORO_AI_CORE_PATH . 'includes/core/class-yoro-memory-context-resolver.php';
		require_once YORO_AI_CORE_PATH . 'includes/core/class-yoro-context-provider.php';
		require_once YORO_AI_CORE_PATH . 'includes/core/class-yoro-order-helper.php';
		require_once YORO_AI_CORE_PATH . 'includes/core/class-yoro-capability-registry.php';
	}

	private static function include_ai() {
		self::include_ai_cloud_boundary();
		self::include_ai_normalizers();
		self::include_ai_cloud_entrypoints();
		self::include_ai_conversation();
		self::include_ai_local_guardrails();
		self::include_ai_result_routing();
	}

	private static function include_ai_cloud_boundary() {
		require_once YORO_AI_CORE_PATH . 'includes/ai/interfaces/interface-yoro-ai-client.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/class-yoro-ai-settings.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/class-yoro-ai-request-tracker.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/class-yoro-ai-provider-resolver.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/clients/class-yoro-ai-api-client.php';
	}

	private static function include_ai_normalizers() {
		require_once YORO_AI_CORE_PATH . 'includes/ai/normalizers/class-yoro-semantic-frame-normalizer.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/normalizers/class-yoro-semantic-frame-validator.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/normalizers/class-yoro-command-decision-normalizer.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/normalizers/class-yoro-router-decision-validator.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/normalizers/class-yoro-router-context-decision-hydrator.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/normalizers/class-yoro-ai-inferred-action-param-normalizer.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/normalizers/class-yoro-ai-workflow-result-normalizer.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/normalizers/class-yoro-ai-email-result-normalizer.php';
	}

	private static function include_ai_cloud_entrypoints() {
		require_once YORO_AI_CORE_PATH . 'includes/ai/class-yoro-ai-parser.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/class-yoro-ai-command-router.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/class-yoro-ai-command-router-pipeline.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/class-yoro-ai-command-router-response-builder.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/class-yoro-ai-command-router-non-action-handlers.php';
	}

	private static function include_ai_conversation() {
		require_once YORO_AI_CORE_PATH . 'includes/ai/class-yoro-ai-conversation-response-helper.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/class-yoro-ai-conversation-predicate-classifier.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/class-yoro-ai-conversation-context-handler.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/class-yoro-ai-conversation-router.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/class-yoro-ai-conversation-gate.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/class-yoro-ai-conversation-result-router.php';
	}

	private static function include_ai_local_guardrails() {
		require_once YORO_AI_CORE_PATH . 'includes/ai/class-yoro-ai-semantic-retrieval-helper.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/class-yoro-ai-hardening-guard-router.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/class-yoro-ai-recovery-fallback-result-router.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/class-yoro-ai-pre-router-order-workflow-guard.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/class-yoro-ai-pre-router-guided-followup-guard.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/class-yoro-ai-pre-router-contextual-order-write-guard.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/class-yoro-ai-pre-router-followup-reference-guard.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/class-yoro-ai-pre-router-guard-result-router.php';
	}

	private static function include_ai_result_routing() {
		require_once YORO_AI_CORE_PATH . 'includes/ai/class-yoro-ai-router-result-corrector.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/class-yoro-ai-action-composition-router.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/class-yoro-ai-workflow-candidate-router.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/class-yoro-ai-workflow-validator.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/class-yoro-ai-workflow-planner.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/class-yoro-ai-command-router-result-router.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/class-yoro-ai-post-command-router-result-router.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/class-yoro-ai-inferred-action-grounding.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/class-yoro-ai-hardening-guard-result-router.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/class-yoro-ai-inferred-supported-action-router.php';
		require_once YORO_AI_CORE_PATH . 'includes/ai/class-yoro-ai-action-decomposer.php';
	}

	private static function include_workflows() {
		require_once YORO_AI_CORE_PATH . 'includes/workflows/class-yoro-workflow-definition-registry.php';
		require_once YORO_AI_CORE_PATH . 'includes/workflows/class-yoro-workflow-runner.php';
		require_once YORO_AI_CORE_PATH . 'includes/workflows/class-yoro-workflow-templates.php';
		require_once YORO_AI_CORE_PATH . 'includes/workflows/class-yoro-workflow-candidate-resolver.php';
	}

	private static function include_parsing() {
		require_once YORO_AI_CORE_PATH . 'includes/parsing/class-yoro-action-intent-extractor.php';
		require_once YORO_AI_CORE_PATH . 'includes/parsing/class-yoro-command-shape-extractor.php';
		require_once YORO_AI_CORE_PATH . 'includes/parsing/class-yoro-entity-phrase-resolver.php';
		require_once YORO_AI_CORE_PATH . 'includes/parsing/class-yoro-entity-graph.php';
		require_once YORO_AI_CORE_PATH . 'includes/parsing/class-yoro-entity-resolver.php';
		require_once YORO_AI_CORE_PATH . 'includes/parsing/class-yoro-entity-validator.php';
		require_once YORO_AI_CORE_PATH . 'includes/parsing/class-yoro-intent-router.php';
		require_once YORO_AI_CORE_PATH . 'includes/parsing/class-yoro-memory-command-parser.php';

		require_once YORO_AI_CORE_PATH . 'includes/parsing/class-yoro-workflow-command-parser.php';
		require_once YORO_AI_CORE_PATH . 'includes/parsing/class-yoro-email-command-parser.php';
		require_once YORO_AI_CORE_PATH . 'includes/parsing/class-yoro-followup-command-parser.php';
		require_once YORO_AI_CORE_PATH . 'includes/parsing/class-yoro-orders-command-parser.php';
		require_once YORO_AI_CORE_PATH . 'includes/parsing/class-yoro-parser-context-preparer.php';
		require_once YORO_AI_CORE_PATH . 'includes/parsing/class-yoro-active-workflow-continue-router.php';
		require_once YORO_AI_CORE_PATH . 'includes/parsing/class-yoro-parser-result-normalizer.php';
		require_once YORO_AI_CORE_PATH . 'includes/parsing/class-yoro-parser-router-callbacks.php';
		require_once YORO_AI_CORE_PATH . 'includes/parsing/class-yoro-parser-safety-fallback-router.php';
		require_once YORO_AI_CORE_PATH . 'includes/parsing/class-yoro-parser-tail-router.php';
		require_once YORO_AI_CORE_PATH . 'includes/parsing/class-yoro-parser-engine.php';
	}

	private static function include_actions() {
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-action-registry.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-action-catalog-builder.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-order-presenter.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-store-operations-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-store-customer-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-store-fulfillment-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-store-inventory-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-store-briefing-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-orders-read-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-orders-write-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-orders-email-service.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-orders-actions.php';
		require_once YORO_AI_CORE_PATH . 'includes/actions/class-yoro-workflow-actions.php';
	}

	private static function include_execution() {
		require_once YORO_AI_CORE_PATH . 'includes/execution/class-yoro-action-composition-planner.php';
		require_once YORO_AI_CORE_PATH . 'includes/execution/class-yoro-plan-semantic-validator.php';
		require_once YORO_AI_CORE_PATH . 'includes/execution/class-yoro-action-planner.php';
		require_once YORO_AI_CORE_PATH . 'includes/execution/class-yoro-policy-engine.php';
		require_once YORO_AI_CORE_PATH . 'includes/execution/class-yoro-plan-risk-engine.php';
		require_once YORO_AI_CORE_PATH . 'includes/execution/class-yoro-runtime-guard.php';
		require_once YORO_AI_CORE_PATH . 'includes/execution/class-yoro-action-executor.php';
		require_once YORO_AI_CORE_PATH . 'includes/execution/class-yoro-plan-executor.php';
	}

	private static function include_services() {
		require_once YORO_AI_CORE_PATH . 'includes/services/class-yoro-suggestions.php';
	}

	private static function include_dev() {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		foreach (
			[
				'includes/dev/class-yoro-trace-review-formatter.php',
				'includes/dev/class-yoro-real-command-eval-pack.php',
				'includes/dev/class-yoro-parser-test-suite.php',
			] as $dev_file
		) {
			$path = YORO_AI_CORE_PATH . $dev_file;

			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	}

	private static function include_rest() {
		require_once YORO_AI_CORE_PATH . 'includes/rest/class-yoro-rest-response-helper.php';
		require_once YORO_AI_CORE_PATH . 'includes/rest/class-yoro-rest-workflow-state.php';
		require_once YORO_AI_CORE_PATH . 'includes/rest/class-yoro-rest-runtime-log.php';
		require_once YORO_AI_CORE_PATH . 'includes/rest/class-yoro-rest-memory-sync.php';
		require_once YORO_AI_CORE_PATH . 'includes/rest/class-yoro-rest-confirmation-flow.php';
		require_once YORO_AI_CORE_PATH . 'includes/rest/class-yoro-rest-suggestions-helper.php';
		require_once YORO_AI_CORE_PATH . 'includes/rest/class-yoro-rest-run-log-helper.php';
		require_once YORO_AI_CORE_PATH . 'includes/rest/class-yoro-rest-execute-log-helper.php';
		require_once YORO_AI_CORE_PATH . 'includes/rest/class-yoro-rest-execute-handler.php';
		require_once YORO_AI_CORE_PATH . 'includes/rest/class-yoro-rest-run-handler.php';
		require_once YORO_AI_CORE_PATH . 'includes/rest/class-yoro-rest-undo-handler.php';
		require_once YORO_AI_CORE_PATH . 'includes/rest/class-yoro-rest-api.php';
	}

	private static function include_admin() {
		require_once YORO_AI_CORE_PATH . 'includes/admin/class-yoro-admin-page.php';
	}

	private static function boot() {
		if ( class_exists( 'YoRo_DB' ) ) {
			YoRo_DB::maybe_upgrade();
		}

		YoRo_Action_Registry::init();

		YoRo_Orders_Actions::register();
		YoRo_Workflow_Actions::register_actions();

		do_action( 'yoro_register_actions' );

		YoRo_REST_API::init();

		if ( is_admin() ) {
			YoRo_Admin_Page::init();
		}
	}
}
