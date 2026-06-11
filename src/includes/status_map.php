<?php

/**
 * Maps a raw monday status label to a client-facing status object.
 * Match is case-insensitive; whitespace is trimmed before comparison.
 * Prefers TASK STATUS column (color_mksbwnby); falls back to PROJECT STATUS (color_mksb2tks).
 */
function map_monday_status_to_client_status(string $internal_status): array {
    static $map = null;
    if ($map === null) {
        $map = [
            // In Progress
            'new request'    => ['label' => 'In Progress', 'slug' => 'in_progress',    'color' => '#fbbf24'],
            'in production'  => ['label' => 'In Progress', 'slug' => 'in_progress',    'color' => '#fbbf24'],
            'king design'    => ['label' => 'In Progress', 'slug' => 'in_progress',    'color' => '#fbbf24'],
            'erlan design'   => ['label' => 'In Progress', 'slug' => 'in_progress',    'color' => '#fbbf24'],
            'chester design' => ['label' => 'In Progress', 'slug' => 'in_progress',    'color' => '#fbbf24'],
            'amine edit'     => ['label' => 'In Progress', 'slug' => 'in_progress',    'color' => '#fbbf24'],
            'makine edit'    => ['label' => 'In Progress', 'slug' => 'in_progress',    'color' => '#fbbf24'],
            'leo edit'       => ['label' => 'In Progress', 'slug' => 'in_progress',    'color' => '#fbbf24'],
            'mouad edit'     => ['label' => 'In Progress', 'slug' => 'in_progress',    'color' => '#fbbf24'],
            'caption'        => ['label' => 'In Progress', 'slug' => 'in_progress',    'color' => '#fbbf24'],
            'web develop'    => ['label' => 'In Progress', 'slug' => 'in_progress',    'color' => '#fbbf24'],
            // Internal Review
            'lead designer review'  => ['label' => 'Internal Review', 'slug' => 'internal_review', 'color' => '#f97316'],
            'lead editor review'    => ['label' => 'Internal Review', 'slug' => 'internal_review', 'color' => '#f97316'],
            'project manager review'=> ['label' => 'Internal Review', 'slug' => 'internal_review', 'color' => '#f97316'],
            'ads manager review'    => ['label' => 'Internal Review', 'slug' => 'internal_review', 'color' => '#f97316'],
            'zac review'            => ['label' => 'Internal Review', 'slug' => 'internal_review', 'color' => '#f97316'],
            'mouad review'          => ['label' => 'Internal Review', 'slug' => 'internal_review', 'color' => '#f97316'],
            'jeremy review'         => ['label' => 'Internal Review', 'slug' => 'internal_review', 'color' => '#f97316'],
            'erlan review'          => ['label' => 'Internal Review', 'slug' => 'internal_review', 'color' => '#f97316'],
            'reviewing'             => ['label' => 'Internal Review', 'slug' => 'internal_review', 'color' => '#f97316'],
            'reviewed'              => ['label' => 'Internal Review', 'slug' => 'internal_review', 'color' => '#f97316'],
            // Awaiting Your Review
            'client review'          => ['label' => 'Awaiting Your Review', 'slug' => 'client_review', 'color' => '#3b82f6'],
            'sub item client review' => ['label' => 'Awaiting Your Review', 'slug' => 'client_review', 'color' => '#3b82f6'],
            // Approved
            'approved'       => ['label' => 'Approved', 'slug' => 'approved', 'color' => '#10b981'],
            'done'           => ['label' => 'Approved', 'slug' => 'approved', 'color' => '#10b981'],
            // On Hold
            'on hold'        => ['label' => 'On Hold', 'slug' => 'on_hold', 'color' => '#6b7280'],
            'pending'        => ['label' => 'On Hold', 'slug' => 'on_hold', 'color' => '#6b7280'],
            'none'           => ['label' => 'On Hold', 'slug' => 'on_hold', 'color' => '#6b7280'],
            // Scrapped
            'scrapped'       => ['label' => 'Scrapped', 'slug' => 'scrapped', 'color' => '#ef4444'],
        ];
    }
    // Normalize: lowercase, collapse spaces, strip surrounding spaces from dashes
    // so "SUB ITEM - CLIENT REVIEW" → "sub item client review" hits the map key.
    $key   = preg_replace('/\s+/', ' ', strtolower(trim($internal_status)));
    $key   = preg_replace('/\s*-\s*/', ' ', $key);
    $key   = preg_replace('/\s+/', ' ', trim($key));
    $label = preg_replace('/\s+/', ' ', trim($internal_status)) ?: 'Unknown';
    // Unknown statuses still appear — raw monday label shown verbatim in gray badge
    return $map[$key] ?? ['label' => $label, 'slug' => 'unknown', 'color' => '#9ca3af'];
}

/**
 * Return the monday column ID of the task-status column, using the same
 * priority logic as extract_item_status(). Returns null if none found.
 * Used by API endpoints to know which column to mutate.
 */
function find_task_status_column_id(array $item): ?string {
    $best = $fb1 = $fb2 = null;
    foreach ($item['column_values'] ?? [] as $col) {
        $type  = strtolower($col['type'] ?? '');
        if ($type !== 'color' && $type !== 'status') continue;
        $id    = $col['id'] ?? '';
        $title = strtolower($col['column']['title'] ?? '');
        if (str_contains($title, 'task') && str_contains($title, 'status')) {
            $best ??= $id;
        } elseif (str_contains($title, 'status')) {
            $fb1 ??= $id;
        } else {
            $fb2 ??= $id;
        }
    }
    $id = $best ?? $fb1 ?? $fb2;
    return ($id !== null && $id !== '') ? $id : null;
}

/**
 * Read status from item column_values using title-based matching (portable across boards).
 * Preference order:
 *   1. type=color/status AND title contains both "task" and "status"
 *   2. type=color/status AND title contains "status"
 *   3. Any type=color/status column
 * Falls back to '' if nothing found.
 */
function extract_item_status(array $item): string {
    $best       = '';
    $fallback1  = '';
    $fallback2  = '';

    foreach ($item['column_values'] ?? [] as $col) {
        $type  = strtolower($col['type'] ?? '');
        if ($type !== 'color' && $type !== 'status') continue;
        $text  = preg_replace('/\s+/', ' ', trim($col['text'] ?? ''));
        $title = strtolower($col['column']['title'] ?? '');

        if (str_contains($title, 'task') && str_contains($title, 'status')) {
            if ($text !== '') $best = $text;
        } elseif (str_contains($title, 'status')) {
            if ($fallback1 === '' && $text !== '') $fallback1 = $text;
        } else {
            if ($fallback2 === '' && $text !== '') $fallback2 = $text;
        }
    }

    return $best !== '' ? $best : ($fallback1 !== '' ? $fallback1 : $fallback2);
}

/**
 * Return the text value of a specific column by ID.
 */
function extract_item_column(array $item, string $col_id): string {
    foreach ($item['column_values'] ?? [] as $col) {
        if ($col['id'] === $col_id) return trim($col['text'] ?? '');
    }
    return '';
}

/**
 * Return the text value of the "Submitted By" column (or null if not configured/empty).
 * $column_id is the client's submitted_by_column_id; pass null to get null back immediately.
 */
function extract_submitted_by(array $item, ?string $column_id): ?string {
    if (empty($column_id)) return null;
    foreach ($item['column_values'] ?? [] as $cv) {
        if (($cv['id'] ?? '') === $column_id) {
            $val = trim((string)($cv['text'] ?? ''));
            return $val !== '' ? $val : null;
        }
    }
    return null;
}

/**
 * True if the item's Submitted By value matches the user's username (case-insensitive).
 * Returns false when tracking is not configured or the column is blank.
 */
function belongs_to_user(array $item, array $user): bool {
    $submitted_by = extract_submitted_by($item, $user['submitted_by_column_id'] ?? null);
    if ($submitted_by === null) return false;
    return strcasecmp($submitted_by, $user['username']) === 0;
}

/**
 * Return the URL from a link column.
 * Parses the JSON value field first ({"url":"...","text":"..."}), falls back to text.
 */
function extract_item_link_url(array $item, string $col_id): string {
    foreach ($item['column_values'] ?? [] as $col) {
        if ($col['id'] !== $col_id) continue;
        if (!empty($col['value'])) {
            $parsed = json_decode($col['value'], true);
            if (isset($parsed['url']) && filter_var($parsed['url'], FILTER_VALIDATE_URL)) {
                return $parsed['url'];
            }
        }
        $text = trim($col['text'] ?? '');
        if ($text && filter_var($text, FILTER_VALIDATE_URL)) return $text;
        return '';
    }
    return '';
}
