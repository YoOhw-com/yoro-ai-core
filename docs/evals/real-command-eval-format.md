# Real Command Eval Pack

The real command eval pack captures natural commands that have appeared during product testing and turns them into repeatable router expectations.

It is not a keyword parser and it does not train the model at runtime. Its job is to prevent regressions while YoRo stays AI-first:

```text
real command
-> AI router fixture
-> CommandShape
-> Router_Decision_Validator
-> planner/executor when requested
-> expected semantic behavior
```

## Case Shape

Each case lives in `YoRo_Real_Command_Eval_Pack::cases()` and contains:

```php
[
    'name'    => 'Aged pending orders stays a filtered order search',
    'command' => 'Are there any orders pending for more than 3 days?',
    'mode'    => 'execute',
    'fixture' => self::fixture(
        'task_request',
        'orders.search',
        [
            'limit'         => 10,
            'status'        => [ 'pending' ],
            'min_age_hours' => 72,
        ],
        0.85
    ),
    'expect'  => [
        'parsed.action'                         => 'orders.search',
        'parsed.params.status.0'                => 'pending',
        'parsed.params.min_age_hours'           => 72,
        'parsed.command_shape.age_filter.hours' => 72,
        'result.display_type'                   => 'orders_list',
    ],
]
```

Use `mode => execute` only for read-only actions where result shape matters. Keep write actions as parse/plan expectations unless the test uses preview or a safe fixture.

## Trace Review

Failures include a compact trace from `YoRo_Trace_Review_Formatter`:

```text
Command: Are there any orders pending for more than 3 days?
Verdict: FAIL
Shape: surface=orders target_type=order_list status=pending age_filter=older_than 3 days/72h
AI Decision: action=operations.get_attention_queue intent=operations_attention confidence=0.85
Validator: missing=[] ambiguities=[] params={"status":"pending"}
Plan: type=single action=operations.get_attention_queue
Execution: display_type=operations_attention_queue count=3439 result_type=operations_attention_queue
Failed: parsed.action expected=orders.search actual=operations.get_attention_queue
```

This makes it clear which layer drifted: shape extraction, AI decision, validator normalization, planner, or executor.

## Adding New Cases

When a user command exposes a useful behavior or a bug:

1. Redact customer PII.
2. Add the command to `YoRo_Real_Command_Eval_Pack::cases()`.
3. Define expected semantic behavior, not wording-only output.
4. Run `YoRo_Real_Command_Eval_Pack::run()` or the full parser suite.
5. Fix shape/validator/executor behavior until the eval passes.

The pack is intentionally small and high-signal. It should represent product behavior that must not regress, not every possible phrasing.
