<?php
require_once __DIR__ . '/config.php';

/**
 * Execute GraphQL query against monday.com API
 */
function monday_query(string $query, array $variables = []): array {
    if (!MONDAY_API_TOKEN) {
        return ['error' => 'Monday API token is not configured.'];
    }

    $payload = ['query' => $query];
    if (!empty($variables)) {
        $payload['variables'] = $variables;
    }

    $ch = curl_init(MONDAY_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . MONDAY_API_TOKEN,
            'Content-Type: application/json',
            'API-Version: 2024-01',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        error_log("Monday cURL error: $curl_err");
        return ['error' => 'Failed to communicate with monday.com', 'detail' => $curl_err];
    }

    $data = json_decode($response, true);
    if ($http_code !== 200 || isset($data['errors'])) {
        error_log("Monday API error ($http_code): " . $response);
        return [
            'error' => 'monday.com API returned an error.',
            'detail' => $data['errors'] ?? $response,
            'http_code' => $http_code,
        ];
    }

    return $data;
}

/**
 * Create an item on monday board.
 * In MOCK_MONDAY mode, returns a fake ID without calling the real API.
 */
function monday_create_item(int $board_id, string $group_id, string $item_name, array $column_values): array {
    // MOCK MODE - simulate response without hitting real API
    if (MOCK_MONDAY) {
        $fake_id = (int)(time() . rand(100, 999));
        error_log("[MOCK MONDAY] create_item: '{$item_name}' on board={$board_id} group={$group_id}");
        error_log("[MOCK MONDAY] column_values: " . json_encode($column_values));
        return [
            'data' => [
                'create_item' => [
                    'id' => (string)$fake_id,
                    'name' => $item_name,
                ],
            ],
            '_mock' => true,
        ];
    }

    $mutation = '
      mutation ($boardId: ID!, $groupId: String!, $itemName: String!, $columnValues: JSON!) {
        create_item (
          board_id: $boardId,
          group_id: $groupId,
          item_name: $itemName,
          column_values: $columnValues
        ) {
          id
          name
        }
      }
    ';

    $variables = [
        'boardId' => (string)$board_id,
        'groupId' => $group_id,
        'itemName' => $item_name,
        'columnValues' => json_encode($column_values, JSON_UNESCAPED_UNICODE),
    ];

    return monday_query($mutation, $variables);
}

/**
 * Format raw form inputs into monday's column_values format.
 *
 * Monday column type formats:
 *   - status / dropdown: ['index' => N]
 *   - long_text: string
 *   - date: ['date' => 'YYYY-MM-DD']
 *   - link: ['url' => '...', 'text' => '...']
 */
function format_column_values(array $inputs): array {
    $out = [];

    foreach ($inputs as $col_id => $val) {
        if ($val === '' || $val === null) continue;

        // Status/dropdown columns - use 'index' with numeric option id
        if (str_starts_with($col_id, 'single_select') || $col_id === 'priority_Mjj26KQF') {
            $out[$col_id] = ['index' => (int)$val];
        }
        // Date column
        elseif (str_starts_with($col_id, 'date')) {
            $out[$col_id] = ['date' => $val];
        }
        // Long text column
        elseif (str_starts_with($col_id, 'long_text')) {
            $out[$col_id] = $val;
        }
        // Link column
        elseif (str_starts_with($col_id, 'link')) {
            if (filter_var($val, FILTER_VALIDATE_URL)) {
                $out[$col_id] = ['url' => $val, 'text' => $val];
            }
        }
        // Plain text fallback
        else {
            $out[$col_id] = $val;
        }
    }

    return $out;
}

/**
 * Fetch live monday items by ID in one batched call (or multiple if >100 IDs).
 * Returns ['data' => ['items' => [...]]] on success, ['error' => ...] on failure.
 * In MOCK_MONDAY mode returns synthesized data seeded by item_id (stable across calls).
 */
function monday_get_items(array $item_ids): array {
    if (empty($item_ids)) {
        return ['data' => ['items' => []]];
    }

    if (MOCK_MONDAY) {
        $mock_statuses  = ['KING DESIGN', 'CLIENT REVIEW', 'APPROVED', 'PENDING', 'REVIEWING', 'IN PRODUCTION', 'SCRAPPED', 'DONE'];
        $mock_priorities = ['URGENT', 'HIGH', 'MEDIUM', 'LOW'];
        $items = [];
        foreach ($item_ids as $raw_id) {
            $id = (int)$raw_id;
            // Seed by ID so the same item always gets the same mock data
            $status   = $mock_statuses[$id   % count($mock_statuses)];
            $priority = $mock_priorities[($id + 2) % count($mock_priorities)];
            // Spread deadlines: -5 to +8 days relative to today
            $offset   = ($id % 14) - 5;
            $deadline = date('Y-m-d', strtotime("{$offset} days"));
            $has_output = ($id % 3 === 0);
            $output_url = $has_output ? 'https://drive.google.com/mock-output-' . $id : '';
            $has_subitems = ($id % 4 === 0);
            $mock_subitems = $has_subitems ? [
                [
                    'id'         => (string)($id * 100 + 1),
                    'name'       => 'Subitem A — Different Audience',
                    'state'      => 'active',
                    'created_at' => date('Y-m-d\TH:i:s\Z', time() - 3600),
                    'column_values' => [
                        ['id' => 'color_mksbwnby', 'text' => 'IN PRODUCTION', 'value' => null, 'type' => 'color', 'column' => ['title' => 'TASK STATUS']],
                        ['id' => 'priority_Mjj26KQF', 'text' => 'HIGH', 'value' => null, 'type' => 'priority', 'column' => ['title' => 'Priority']],
                    ],
                ],
                [
                    'id'         => (string)($id * 100 + 2),
                    'name'       => 'Subitem B — Different Hook',
                    'state'      => 'active',
                    'created_at' => date('Y-m-d\TH:i:s\Z', time() - 1800),
                    'column_values' => [
                        ['id' => 'color_mksbwnby', 'text' => 'CLIENT REVIEW', 'value' => null, 'type' => 'color', 'column' => ['title' => 'TASK STATUS']],
                        ['id' => 'priority_Mjj26KQF', 'text' => 'MEDIUM', 'value' => null, 'type' => 'priority', 'column' => ['title' => 'Priority']],
                    ],
                ],
            ] : [];
            $mock_subitems = array_map(function (array $s) use ($id): array {
                $s['updated_at'] = $s['created_at'];
                return $s;
            }, $mock_subitems);
            $items[] = [
                'id'          => (string)$id,
                'name'        => "Mock Task #{$id}",
                'state'       => 'active',
                'updated_at'  => date('Y-m-d\TH:i:s\Z', time() - ($id % 7200)),
                'board'       => ['id' => '18409671597'],
                'group'       => ['id' => 'group_mkznjcej'],
                'parent_item' => null,
                'subitems'    => $mock_subitems,
                'column_values' => [
                    ['id' => 'color_mksbwnby',    'text' => $status,   'value' => null, 'type' => 'color',    'column' => ['title' => 'TASK STATUS']],
                    ['id' => 'color_mksb2tks',    'text' => '',        'value' => null, 'type' => 'color',    'column' => ['title' => 'PROJECT STATUS']],
                    ['id' => 'priority_Mjj26KQF', 'text' => $priority, 'value' => null, 'type' => 'priority', 'column' => ['title' => 'Priority']],
                    ['id' => 'date4',             'text' => $deadline, 'value' => json_encode(['date' => $deadline]), 'type' => 'date', 'column' => ['title' => 'Deadline']],
                    ['id' => 'link_mksbghas',     'text' => $output_url,
                     'value' => $has_output ? json_encode(['url' => $output_url, 'text' => 'View files']) : null,
                     'type' => 'link', 'column' => ['title' => 'Output']],
                    ['id' => 'short_text5nn96kew', 'text' => 'demo_user', 'value' => json_encode('demo_user'), 'type' => 'text', 'column' => ['title' => 'Submitted By']],
                    ['id' => 'text_item_desc',     'text' => 'Mock Item Description #' . $id, 'value' => json_encode('Mock Item Description #' . $id), 'type' => 'text', 'column' => ['title' => 'Item Description']],
                ],
            ];
        }
        error_log('[MOCK MONDAY] monday_get_items: returned ' . count($items) . ' mock items');
        return ['data' => ['items' => $items]];
    }

    // Real API — chunk into batches of 100 (monday's per-call limit)
    $gql = '
        query ($ids: [ID!]!) {
            items (ids: $ids, limit: 100) {
                id
                name
                state
                updated_at
                board { id }
                group { id }
                parent_item { id board { id } }
                subitems {
                    id
                    name
                    state
                    created_at
                    updated_at
                    column_values {
                        id
                        text
                        value
                        type
                        column { title }
                    }
                }
                column_values {
                    id
                    text
                    value
                    type
                    column { title }
                }
            }
        }
    ';

    $all_items = [];
    foreach (array_chunk($item_ids, 100) as $chunk) {
        $result = monday_query($gql, ['ids' => array_map('strval', $chunk)]);
        if (isset($result['error'])) {
            return $result;
        }
        $all_items = array_merge($all_items, $result['data']['items'] ?? []);
    }

    return ['data' => ['items' => $all_items]];
}

/**
 * Fetch all items across ALL groups in a monday board (cursor-paginated, max 1000 items).
 * Returns ['items' => [...]] on success (empty array = board has no items, not an error).
 * Returns ['error' => '...'] on API/network failure.
 * In MOCK_MONDAY mode: returns 7 stable fake items spread across mock groups.
 *
 * One board per client is assumed. If multiple clients ever share a board, a "Client"
 * column filter would be needed — see PHASE_4_NOTES.md §"Group Architecture".
 */
function monday_get_items_in_board(int $board_id, int $limit = 1000): array {
    if (MOCK_MONDAY) {
        $seed      = abs(crc32((string)$board_id));
        $statuses  = ['NEW REQUEST', 'IN PRODUCTION', 'LEAD DESIGNER REVIEW', 'CLIENT REVIEW', 'APPROVED', 'ON HOLD', 'SCRAPPED'];
        $mock_groups = [
            ['id' => 'group_new',      'title' => 'New Requests'],
            ['id' => 'group_design',   'title' => 'Design'],
            ['id' => 'group_approved', 'title' => 'Approved Edits'],
        ];
        $priorities = ['URGENT', 'HIGH', 'MEDIUM', 'LOW'];
        $items = [];
        for ($i = 1; $i <= 7; $i++) {
            $id       = $seed + $i;
            $status   = $statuses[$id % count($statuses)];
            $priority = $priorities[($id + 2) % count($priorities)];
            $group    = $mock_groups[$id % count($mock_groups)];
            $offset   = ($id % 14) - 5;
            $deadline = date('Y-m-d', strtotime("{$offset} days"));
            $has_out  = ($id % 3 === 0);
            $out_url  = $has_out ? 'https://drive.google.com/mock-output-' . $id : '';
            $items[]  = [
                'id'         => (string)$id,
                'name'       => "Mock Task #{$i}",
                'state'      => 'active',
                'updated_at' => date('Y-m-d\TH:i:s\Z', time() - ($i * 3600)),
                'created_at' => date('Y-m-d\TH:i:s\Z', time() - ($i * 86400)),
                'board'      => ['id' => (string)$board_id],
                'group'      => $group,
                'subitems'   => [],
                'column_values' => [
                    ['id' => 'color_mksbwnby',    'text' => $status,   'value' => null, 'type' => 'color',    'column' => ['title' => 'TASK STATUS']],
                    ['id' => 'color_mksb2tks',    'text' => '',        'value' => null, 'type' => 'color',    'column' => ['title' => 'PROJECT STATUS']],
                    ['id' => 'priority_Mjj26KQF', 'text' => $priority, 'value' => null, 'type' => 'priority', 'column' => ['title' => 'Priority']],
                    ['id' => 'date4',             'text' => $deadline, 'value' => json_encode(['date' => $deadline]), 'type' => 'date', 'column' => ['title' => 'Deadline']],
                    ['id' => 'link_mksbghas',     'text' => $out_url,
                     'value' => $has_out ? json_encode(['url' => $out_url, 'text' => 'View files']) : null,
                     'type' => 'link', 'column' => ['title' => 'Output']],
                    ['id' => 'short_text5nn96kew', 'text' => 'demo_user', 'value' => json_encode('demo_user'), 'type' => 'text', 'column' => ['title' => 'Submitted By']],
                    ['id' => 'text_item_desc',     'text' => 'Mock Description #' . $i, 'value' => json_encode('Mock Description #' . $i), 'type' => 'text', 'column' => ['title' => 'Item Description']],
                ],
            ];
        }
        error_log("[MOCK MONDAY] monday_get_items_in_board: board={$board_id} → " . count($items) . ' items across ' . count($mock_groups) . ' groups');
        return ['items' => $items];
    }

    if (!MONDAY_API_TOKEN) {
        error_log('[Monday] monday_get_items_in_board: API token not configured');
        return ['error' => 'Monday API token is not configured.'];
    }

    $gql = '
        query ($boardId: ID!, $pageSize: Int!, $cursor: String) {
            boards(ids: [$boardId]) {
                items_page(limit: $pageSize, cursor: $cursor) {
                    cursor
                    items {
                        id
                        name
                        state
                        updated_at
                        created_at
                        board { id }
                        group { id title }
                        subitems {
                            id
                            name
                            state
                            created_at
                            column_values {
                                id
                                text
                                value
                                type
                                column { title }
                            }
                        }
                        column_values {
                            id
                            text
                            value
                            type
                            column { title }
                        }
                    }
                }
            }
        }
    ';

    $all_items = [];
    $cursor    = null;
    $page_size = min($limit, 200);
    $max_pages = 5;
    $page_num  = 0;

    do {
        $vars = ['boardId' => (string)$board_id, 'pageSize' => $page_size];
        if ($cursor !== null) {
            $vars['cursor'] = $cursor;
        }

        $result = monday_query($gql, $vars);
        if (isset($result['error'])) {
            error_log("[MONDAY API] get_items_in_board failed. Board: $board_id, Error: " . json_encode($result));
            return $result;
        }

        $page = $result['data']['boards'][0]['items_page'] ?? null;
        if ($page === null) {
            error_log("[MONDAY API] get_items_in_board unexpected response. Board: $board_id, Response: " . json_encode($result));
            return ['error' => 'Unexpected response from monday.com API.'];
        }

        $batch     = $page['items'] ?? [];
        $cursor    = $page['cursor'] ?? null;
        $all_items = array_merge($all_items, $batch);
        $page_num++;

        if ($page_num >= $max_pages && $cursor !== null) {
            error_log("[MONDAY API] get_items_in_board: hit {$max_pages}-page limit for board {$board_id} ({$limit} items), stopping");
            break;
        }

    } while ($cursor !== null && count($all_items) < $limit);

    return ['items' => $all_items];
}

/**
 * Upload a file to a monday item's file column via multipart POST.
 * In MOCK mode: logs and returns a fake success without hitting the API.
 */
function monday_upload_file_to_item(int $item_id, string $column_id, string $file_path, string $file_name): array {
    if (MOCK_MONDAY) {
        error_log("[MOCK MONDAY] uploaded '{$file_name}' to item {$item_id} column {$column_id}");
        return ['data' => ['add_file_to_column' => ['id' => (string)rand(100000, 999999)]]];
    }

    if (!MONDAY_API_TOKEN) {
        return ['error' => 'Monday API token is not configured.'];
    }

    $mutation = 'mutation ($itemId: ID!, $columnId: String!, $file: File!) {
        add_file_to_column (item_id: $itemId, column_id: $columnId, file: $file) { id }
    }';

    $variables = json_encode(['itemId' => (string)$item_id, 'columnId' => $column_id]);

    $ch = curl_init('https://api.monday.com/v2/file');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'query'           => $mutation,
            'variables'       => $variables,
            'variables[file]' => new CURLFile(
                $file_path,
                mime_content_type($file_path) ?: 'application/octet-stream',
                $file_name
            ),
        ],
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . MONDAY_API_TOKEN,
            'API-Version: 2024-01',
        ],
        CURLOPT_TIMEOUT => 60,
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        error_log("[Monday] File upload cURL error for '{$file_name}': {$curl_err}");
        return ['error' => 'File upload failed: ' . $curl_err];
    }

    $data = json_decode($response, true);
    if ($http_code !== 200 || isset($data['errors'])) {
        error_log("[Monday] File upload API error ({$http_code}) for '{$file_name}': {$response}");
        return ['error' => 'File upload API error', 'detail' => $data['errors'] ?? $response];
    }

    return $data;
}

/**
 * Create a subitem under a parent monday item.
 * In MOCK mode: logs and returns a fake subitem ID.
 */
function monday_create_subitem(int $parent_item_id, string $subitem_name): array {
    if (MOCK_MONDAY) {
        $fake_id = (int)(time() . rand(100, 999));
        error_log("[MOCK MONDAY] create_subitem: '{$subitem_name}' under parent {$parent_item_id} → fake id {$fake_id}");
        return ['data' => ['create_subitem' => ['id' => (string)$fake_id]]];
    }

    $mutation = '
        mutation ($parentId: ID!, $itemName: String!) {
            create_subitem (parent_item_id: $parentId, item_name: $itemName) { id }
        }
    ';

    return monday_query($mutation, [
        'parentId' => (string)$parent_item_id,
        'itemName' => $subitem_name,
    ]);
}

/**
 * Set a status/color column to a new label value via change_column_value.
 * Status columns require a JSON-encoded {"label": "..."} string — change_simple_column_value
 * does not work for color/status types and fails silently or returns an API error.
 *
 * Uses inline string interpolation (not GraphQL variables) because monday's JSON! variable
 * type behaves inconsistently for status columns — the inline approach is the proven pattern.
 *
 * Returns ['data' => [...]] on success, ['error' => '...'] on failure.
 * In MOCK mode: logs and returns fake success.
 */
function monday_change_item_status(int $item_id, int $board_id, string $column_id, string $value): array {
    if (MOCK_MONDAY) {
        error_log("[MOCK MONDAY] change_item_status: item=$item_id board=$board_id col=$column_id value='$value'");
        return ['data' => ['change_column_value' => ['id' => (string)$item_id]]];
    }

    // Build value as a JSON string literal and embed it inline in the query.
    // Passing it as a JSON! variable causes monday to receive a JSON-encoded string
    // instead of a JSON object, which silently fails for status/color columns.
    $value_json    = json_encode(['label' => $value]);           // {"label":"IN PRODUCTION"}
    $value_literal = json_encode($value_json);                   // "{\"label\":\"IN PRODUCTION\"}" — valid GraphQL string literal

    $mutation = "
        mutation {
            change_column_value(
                item_id: {$item_id},
                board_id: {$board_id},
                column_id: \"{$column_id}\",
                value: {$value_literal}
            ) { id }
        }
    ";
    return monday_query($mutation);
}

/**
 * Write a raw JSON-encoded value to any monday column (text, long-text, etc.).
 * $value must already be JSON-encoded for the column type, e.g. json_encode("my text").
 * Returns ['data' => [...]] on success, ['error' => '...'] on failure.
 * In MOCK mode: logs and returns fake success.
 */
function monday_change_column_value(int $item_id, int $board_id, string $column_id, string $value_json): array {
    if (MOCK_MONDAY) {
        error_log("[MOCK MONDAY] change_column_value: item=$item_id board=$board_id col=$column_id value=$value_json");
        return ['data' => ['change_column_value' => ['id' => (string)$item_id]]];
    }

    $value_literal = json_encode($value_json); // encode to GraphQL string literal
    $mutation = "
        mutation {
            change_column_value(
                item_id: {$item_id},
                board_id: {$board_id},
                column_id: \"{$column_id}\",
                value: {$value_literal}
            ) { id }
        }
    ";
    return monday_query($mutation);
}

/**
 * Post a comment (update) on a monday item.
 * Returns ['data' => [...]] on success, ['error' => '...'] on failure.
 * In MOCK mode: logs and returns fake success.
 */
function monday_create_update(int $item_id, string $body): array {
    if (MOCK_MONDAY) {
        error_log("[MOCK MONDAY] create_update: item=$item_id body=" . substr($body, 0, 80));
        return ['data' => ['create_update' => ['id' => (string)rand(1000000, 9999999)]]];
    }

    $mutation = '
        mutation ($itemId: ID!, $body: String!) {
            create_update(item_id: $itemId, body: $body) { id }
        }
    ';
    return monday_query($mutation, [
        'itemId' => (string)$item_id,
        'body'   => $body,
    ]);
}

/**
 * Fetch the most recent updates (comments) on a monday item.
 * Returns ['updates' => [...]] on success, ['error' => '...'] on failure.
 * In MOCK mode: returns two seeded comments.
 */
function monday_get_item_updates(int $item_id, int $limit = 20): array {
    if (MOCK_MONDAY) {
        return ['updates' => [
            [
                'id'         => '9001',
                'body'       => 'Design draft is complete and ready for your review!',
                'created_at' => date('Y-m-d\TH:i:s\Z', time() - 3600),
                'creator'    => ['name' => 'Design Team'],
            ],
            [
                'id'         => '9000',
                'body'       => 'Task created and assigned.',
                'created_at' => date('Y-m-d\TH:i:s\Z', time() - 86400),
                'creator'    => ['name' => 'Project Manager'],
            ],
        ]];
    }

    $gql = '
        query ($itemId: ID!, $limit: Int!) {
            items(ids: [$itemId], limit: 1) {
                updates(limit: $limit) {
                    id
                    body
                    created_at
                    creator { name }
                }
            }
        }
    ';
    $result = monday_query($gql, ['itemId' => (string)$item_id, 'limit' => $limit]);
    if (isset($result['error'])) {
        return $result;
    }
    $updates = $result['data']['items'][0]['updates'] ?? [];
    return ['updates' => $updates];
}

/**
 * Fetch all columns on a monday board.
 * Returns ['columns' => [...]] on success, ['error' => '...'] on failure.
 * In MOCK mode: returns a representative set of columns.
 */
function monday_get_board_columns(int $board_id): array {
    if (MOCK_MONDAY) {
        return ['columns' => [
            ['id' => 'name',               'title' => 'Name',             'type' => 'name'],
            ['id' => 'color_mksbwnby',     'title' => 'TASK STATUS',      'type' => 'color'],
            ['id' => 'color_mksb2tks',     'title' => 'PROJECT STATUS',   'type' => 'color'],
            ['id' => 'priority_Mjj26KQF',  'title' => 'Priority',         'type' => 'priority'],
            ['id' => 'date4',              'title' => 'Deadline',         'type' => 'date'],
            ['id' => 'link_mksbghas',      'title' => 'Output',           'type' => 'link'],
            ['id' => 'short_text5nn96kew', 'title' => 'Submitted By',     'type' => 'text'],
            ['id' => 'long_text_notes',    'title' => 'Internal Notes',   'type' => 'long-text'],
            ['id' => 'text_sku',           'title' => 'Internal SKU',     'type' => 'text'],
            ['id' => 'people_assignee',    'title' => 'Assigned To',      'type' => 'people'],
        ]];
    }

    $gql = '
        query ($boardId: ID!) {
            boards(ids: [$boardId]) {
                columns {
                    id
                    title
                    type
                }
            }
        }
    ';

    $result = monday_query($gql, ['boardId' => (string)$board_id]);
    if (isset($result['error'])) {
        return $result;
    }

    $columns = $result['data']['boards'][0]['columns'] ?? null;
    if ($columns === null) {
        return ['error' => 'Unexpected response from monday.com API.'];
    }

    return ['columns' => $columns];
}

/**
 * Extract the "Item Description" column value from a monday item.
 * Matches by title (case-insensitive) so it works across any board layout.
 */
function extract_item_description(array $item): string {
    foreach ($item['column_values'] ?? [] as $col) {
        $title = strtolower(trim($col['column']['title'] ?? ''));
        if ($title === 'item description') {
            return trim($col['text'] ?? '');
        }
    }
    return '';
}

/**
 * Map content category index to readable label (for storing in our DB)
 */
function content_category_label(string $index): string {
    $map = [
        '0' => 'Ads',
        '1' => 'Social Media',
        '2' => 'Printables',
        '3' => 'Website Content',
        '4' => 'Branding',
        '6' => 'Email Templates',
    ];
    return $map[$index] ?? 'Unknown';
}

// ── Copy Review Workflow ──────────────────────────────────────────────────────
// Status labels are identical across all clients. If they ever diverge, promote
// these constants to per-client DB columns.

const COPY_REVIEW_TRIGGER_PRODUCTION_STATUS = 'COPYWRITING';
const COPY_REVIEW_TRIGGER_TASK_STATUS       = 'CLIENT REVIEW';
const COPY_REVIEW_APPROVE_TASK_STATUS       = 'APPROVED COPY';
const COPY_REVIEW_REVISION_TASK_STATUS      = 'REVIEWED';
const COPY_REVIEW_REVISION_COMMENT_PREFIX   = '[REVISION REQUEST]';

/**
 * Return the human-readable text of a specific column by its ID.
 * Uses Monday's column_values[].text, which is the display label for status columns.
 */
function extract_column_text_by_id(array $item, string $column_id): string {
    foreach ($item['column_values'] ?? [] as $col) {
        if (($col['id'] ?? '') === $column_id) {
            return trim($col['text'] ?? '');
        }
    }
    return '';
}

/**
 * Return true only when the item is in the exact COPYWRITING + CLIENT REVIEW state
 * AND the client has copy-review enabled with both column IDs configured.
 */
function should_show_copy_review_buttons(array $item, array $client): bool {
    if (empty($client['copy_review_enabled']))        return false;
    if (empty($client['task_status_column_id']))      return false;
    if (empty($client['production_status_column_id'])) return false;

    $task_status       = extract_column_text_by_id($item, $client['task_status_column_id']);
    $production_status = extract_column_text_by_id($item, $client['production_status_column_id']);

    return strcasecmp($task_status,       COPY_REVIEW_TRIGGER_TASK_STATUS)       === 0
        && strcasecmp($production_status, COPY_REVIEW_TRIGGER_PRODUCTION_STATUS) === 0;
}
