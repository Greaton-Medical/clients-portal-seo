<?php
/**
 * Rule engine for conditional Monday action buttons.
 *
 * Reads rules from config/monday_actions.php and checks whether a Monday item's
 * current column state matches any configured rule trigger. When a match is found,
 * the task detail page replaces the default action buttons with rule-specific ones.
 */

function _load_monday_actions_config(): array {
    static $cfg = null;
    if ($cfg === null) {
        $path = __DIR__ . '/../config/monday_actions.php';
        $cfg  = file_exists($path) ? (require $path) : [];
    }
    return $cfg;
}

/**
 * Check a Monday item against all configured action rules.
 * Returns the first matching rule key, or null if no rule matches.
 *
 * A rule matches only when:
 * - All column IDs in the trigger are non-null (i.e. configured).
 * - All trigger conditions evaluate to true against the item's column values.
 */
function match_item_action_rule(array $item): ?string {
    $cfg = _load_monday_actions_config();
    foreach ($cfg['rules'] ?? [] as $rule_key => $rule) {
        if (_rule_triggers_match($item, $rule['trigger'] ?? [], $cfg['columns'] ?? [])) {
            return $rule_key;
        }
    }
    return null;
}

/**
 * Return the action sub-config for a specific rule + action combination.
 * e.g. get_rule_action('copywriting_client_review', 'approve_copy')
 */
function get_rule_action(string $rule_key, string $action_key): ?array {
    $cfg = _load_monday_actions_config();
    return $cfg['rules'][$rule_key]['actions'][$action_key] ?? null;
}

/**
 * Return the column alias → ID map from config.
 */
function get_monday_actions_columns(): array {
    return _load_monday_actions_config()['columns'] ?? [];
}

/**
 * Apply the column updates defined in an action config to a Monday item.
 *
 * Iterates through $updates_config (alias => target) and calls the appropriate
 * Monday mutation for each column. Prefers index-based updates (stable) over
 * label-based (user-editable). Stops on the first failure.
 *
 * Returns ['ok' => true] on full success, or
 *         ['ok' => false, 'error' => string, 'detail' => mixed, 'column' => string] on failure.
 */
function apply_monday_action_updates(
    int    $item_id,
    int    $board_id,
    array  $updates_config,
    array  $columns
): array {
    foreach ($updates_config as $col_alias => $target) {
        $col_id = $columns[$col_alias] ?? null;
        if ($col_id === null) {
            error_log("[MONDAY ACTIONS] apply_updates: alias '{$col_alias}' has no column ID configured — skipped.");
            continue;
        }

        $label = $target['label'] ?? '';
        $index = $target['index'] ?? null;

        if ($index !== null) {
            // Index-based: stable even when the Monday status label is renamed.
            $value_json    = json_encode(['index' => (int)$index]);
            $value_literal = json_encode($value_json);
            $mutation = "
                mutation {
                    change_column_value(
                        item_id: {$item_id},
                        board_id: {$board_id},
                        column_id: \"{$col_id}\",
                        value: {$value_literal}
                    ) { id }
                }
            ";
            $result = monday_query($mutation);
        } else {
            // Label-based fallback (used while index is still a TODO).
            $result = monday_change_item_status($item_id, $board_id, $col_id, $label);
        }

        if (isset($result['error'])) {
            error_log("[MONDAY ACTIONS] apply_updates: failed on alias '{$col_alias}' col_id='{$col_id}': " . json_encode($result));
            return [
                'ok'     => false,
                'error'  => $result['error'],
                'detail' => $result['detail'] ?? null,
                'column' => $col_alias,
            ];
        }
    }

    return ['ok' => true, 'error' => null];
}

// ── Internal helpers ─────────────────────────────────────────────────────────

/**
 * Returns true only if every condition in $trigger matches the item's column values.
 * A null column ID in $columns means the rule is not yet configured — always returns false.
 */
function _rule_triggers_match(array $item, array $trigger, array $columns): bool {
    if (empty($trigger)) return false;

    foreach ($trigger as $col_alias => $condition) {
        $col_id = $columns[$col_alias] ?? null;
        if ($col_id === null) return false; // Rule not yet configured — safely inactive

        // Find the column value inside the item
        $col_val = null;
        foreach ($item['column_values'] ?? [] as $col) {
            if (($col['id'] ?? '') === $col_id) {
                $col_val = $col;
                break;
            }
        }
        if ($col_val === null) return false;

        $target_index = $condition['index'] ?? null;
        $target_label = $condition['label'] ?? null;

        if ($target_index !== null) {
            // Prefer matching by status index (stable — survives Monday label renames).
            $raw    = $col_val['value'] ?? null;
            $parsed = ($raw !== null) ? json_decode($raw, true) : null;
            $actual = $parsed['index'] ?? null;
            if ($actual !== $target_index) return false;
        } elseif ($target_label !== null) {
            // Fall back to case-insensitive label comparison.
            $actual = trim($col_val['text'] ?? '');
            if (strcasecmp($actual, $target_label) !== 0) return false;
        } else {
            return false; // Neither index nor label configured — condition can never match
        }
    }

    return true;
}
