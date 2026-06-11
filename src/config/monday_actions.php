<?php
/**
 * Monday.com action rules configuration.
 *
 * Each rule maps a column-state combination to a set of action buttons shown
 * to the client in the task detail view. Adding a new rule (new status combo
 * → new buttons) is a config-only change — no code modification required.
 *
 * Matching priority: status index (stable) over label (user-editable).
 * Set index to the integer index of the status option in Monday.
 * Label is kept here as a human-readable reference and for log messages only.
 *
 * All TODO values default to null, which keeps every rule safely inactive.
 * Fill them in once the client confirms the values; no code changes needed.
 */
return [

    // TODO: client to provide the board ID for their tasks board.
    'board_id' => null,

    // Canonical column ID map. Rules reference these aliases so a column ID
    // change only needs updating here, not in every rule that uses it.
    'columns' => [
        'production_status' => null, // TODO: Monday column ID (string) e.g. "color_abc123"
        'task_status'       => null, // TODO: Monday column ID (string) e.g. "color_mksbwnby"
    ],

    // ── Rules ────────────────────────────────────────────────────────────────
    // Each key is an arbitrary stable identifier used internally and in logs.
    // All trigger conditions must be satisfied for the rule to fire.
    'rules' => [

        'copywriting_client_review' => [
            'trigger' => [
                // Both columns must match simultaneously for this rule to activate.
                'production_status' => [
                    'label' => 'COPYWRITING',   // human-readable, used in logs only
                    'index' => null,            // TODO: integer status index from Monday
                ],
                'task_status' => [
                    'label' => 'CLIENT REVIEW', // human-readable, used in logs only
                    'index' => null,            // TODO: integer status index from Monday
                ],
            ],

            'actions' => [

                'approve_copy' => [
                    // Column values to set when the client clicks "Approve Copy".
                    // TODO: client to confirm which columns change and to what values.
                    // Example entry once known:
                    //   'production_status' => ['label' => 'DESIGN',   'index' => null],
                    //   'task_status'       => ['label' => 'APPROVED',  'index' => null],
                    'updates' => [],

                    'comment_required' => false,
                    'comment_prefix'   => null,
                ],

                'request_revision' => [
                    // Column values to set when the client submits a revision request.
                    // TODO: client to confirm which columns change and to what values.
                    // Example entry once known:
                    //   'task_status' => ['label' => 'Revision Requested', 'index' => null],
                    'updates' => [],

                    'comment_required' => true,
                    // Prepended to every revision comment posted to Monday.
                    // TODO: confirm prefix wording with client.
                    'comment_prefix' => '[REVISION REQUEST] ',
                ],
            ],
        ],

        // ── Add future rules here ─────────────────────────────────────────────
        // Each new entry follows the same structure. No code changes required.
        // 'another_status_combo' => [
        //     'trigger'  => [...],
        //     'actions'  => [...],
        // ],

    ],
];
