<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Suggestions_Prompt {

	public static function build( $action ) {
		return sprintf(
			'You are suggesting next actions for a WooCommerce admin.

IMPORTANT:
- Suggestions MUST be natural language commands
- DO NOT use system/internal commands
- DO NOT use formats like:
customers.contact --orders=on-hold
orders.list --status=on-hold

GOOD examples:
- "Email this customer explaining the issue"
- "Show orders that are on hold"
- "Add a note to the first order"

Return JSON array:
[
{"label": "...", "command": "..."}
]

Max 3 suggestions.

Action: %s
',
			$action
		);
	}
}
