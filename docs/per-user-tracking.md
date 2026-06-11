# Per-User Submission Tracking

When configured, the portal injects the logged-in user's username into the monday form via URL prefill. This populates a hidden "Submitted By" column on every submitted task, allowing the dashboard to show each user only their own requests while keeping team-wide review visibility intact.

## How it works

1. The client's monday form has a hidden text column (e.g. "Submitted By") with URL parameter prefill enabled.
2. When a user opens `/new-request.php`, the portal appends `?<column_id>=<username>` to the iframe URL.
3. monday pre-fills that field on form load.
4. On submit, the task is created with "Submitted By" set to the portal username.

## Setup steps

### 1. Add the column to the monday board

On the client's monday board, add a **Short text** column. Suggested title: "Submitted By". The exact title doesn't matter for the portal — only the column ID is used.

To find the column ID after creating it, run this query in the [monday API playground](https://developer.monday.com/api-reference/docs/quickstart):
```graphql
query {
  boards(ids: [YOUR_BOARD_ID]) {
    columns { id title }
  }
}
```
Copy the `id` value for your new column (e.g. `short_text5nn96kew`).

### 2. Configure the form field

In the monday form editor:
1. Click **Edit form** → find the "Submitted By" field → drag it into the form.
2. Click the field → **Question settings** → enable **Hidden field**.
3. Still in Question settings → enable **URL parameter prefill** → set the source to **Query param** (the param name will match the column ID automatically).

### 3. Configure the portal

In the portal admin panel → **Edit Client** → paste the column ID into **Submitted By Column ID** → save.

### 4. Test

1. Log in as a client user → open `/new-request.php`.
2. Right-click the iframe → **Inspect** → check the `src` attribute. It should end with `?short_text5nn96kew=<username>`.
3. Submit a test form. On the monday board, the new task should show "Submitted By" = your username.
4. On the dashboard, only tasks with that username should appear in "My Requests".

## Behaviour when not configured

If `submitted_by_column_id` is NULL for a client, the dashboard shows all board tasks in a single unified table with a notice: "Per-user tracking is not configured. Showing all team tasks."

## Important notes

- **Don't rename usernames.** A user's historical tasks are linked by their username string. Renaming breaks the association (old tasks orphaned to "Other team requests").
- **Security note:** the username is injected via URL query parameter. A user could theoretically edit the URL to submit as a different username. This is low risk in a trusted client environment. Mitigation would require server-side form processing, which is out of scope.
- **New clients** without the column configured are unaffected and see the unified view by default.
