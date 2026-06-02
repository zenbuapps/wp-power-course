# Power Course MCP Server — Setup Guide

[繁體中文](./mcp.zh-TW.md) | English

> Connect AI agents (Claude Code, Cursor, GPT, etc.) to your WordPress LMS via the [Model Context Protocol (MCP)](https://modelcontextprotocol.io/).

---

## Overview

Power Course exposes an MCP server that lets AI agents programmatically manage your LMS — creating courses, enrolling students, querying reports, and more — all through a standardized tool interface.

Once connected, you can interact with your WordPress site using natural language:

- "List all courses on example.com, sorted by sales"
- "Enroll user #42 into the Advanced TypeScript course"
- "Create a new chapter in Course #123, topic: AI Marketing in the Modern Age — you arrange the content"
- "Export the student list for Course #101 as CSV"

The AI client translates your request into the appropriate MCP tool calls behind the scenes.

---

## Prerequisites

### WordPress User

- To **manage MCP tokens** (generate / revoke from the dashboard), you need an **Administrator** account (`manage_options`).
- Once connected, the AI client runs MCP tools **as the token's creator**; each tool still enforces WordPress capability checks such as `manage_woocommerce`.

### Authentication methods

Power Course supports two authentication methods:

| Method | For | Notes |
| ------ | --- | ----- |
| **Bearer Token (recommended)** | Everyone | Generated right in **Power Course → Settings → AI** — no page hopping, no Base64 |
| Application Password (Basic Auth) | Existing users | Built into WordPress, still supported (see "Advanced / fallback" below) |

---

## Setup Steps

### Step 1 — Generate an MCP Token (in the Power Course dashboard)

1. Go to **WordPress Admin → Power Course → Settings → AI**
2. In the **MCP token management** section, click **Add token**
3. Enter a name (e.g. `Claude Code — my laptop`) and pick an expiration (defaults to **Never expires**; you may also pick 30 / 90 days or 1 year)
4. After clicking **Create**, the dialog shows the **plaintext token** and a **Copy** button — **this token is shown only once, copy it now**
5. The same dialog includes ready-to-use **Claude Code (CLI / JSON) and Cursor** setup snippets with your site URL and token pre-filled — copy with one click

> **Tip**: This token is designed for Power Course MCP. You **do not** need a WordPress application password, and you do **not** need to do any Base64 encoding.
>
> The token list shows each token's name, created time, last-used time and expiration. If you suspect a leak, click **Revoke** — requests using that token are rejected immediately (401).

### Step 2 — Configure Your AI Client

Paste the snippet you copied into your AI client config. The auth header is always `Authorization: Bearer <your-token>`.

#### Claude Code

MCP configuration supports three scopes — pick one:

**Option A — Project-shared** (recommended for teams): add to `.mcp.json` in your project root

```json
{
  "mcpServers": {
    "power-course": {
      "type": "http",
      "url": "https://yoursite.com/wp-json/power-course/v2/mcp",
      "headers": {
        "Authorization": "Bearer abcd1234yourtokenhere"
      }
    }
  }
}
```

**Option B — Personal global** (recommended for personal use): add to `~/.claude.json`, same structure as above.

> **Note**: MCP is **read-only by default**. To allow write/delete operations, log in to **WordPress Admin → Power Course → Settings → AI** and turn on the *Allow update* / *Allow delete* switches. (Issue #217: previously controlled by `ALLOW_UPDATE` / `ALLOW_DELETE` env vars; those are no longer read.)

**Option C — CLI quick setup** (the directly runnable command provided in the plaintext dialog):

```bash
claude mcp add --transport http power-course \
  https://yoursite.com/wp-json/power-course/v2/mcp \
  --header "Authorization: Bearer abcd1234yourtokenhere"
```

#### Cursor

Add to `.cursor/mcp.json` in your project root:

```json
{
  "mcpServers": {
    "power-course": {
      "type": "http",
      "url": "https://yoursite.com/wp-json/power-course/v2/mcp",
      "headers": {
        "Authorization": "Bearer abcd1234yourtokenhere"
      }
    }
  }
}
```

#### WP-CLI (STDIO Transport)

If you have WP-CLI access on the server, you can use STDIO transport instead of HTTP (no token needed):

```bash
# List all registered MCP servers
wp mcp-adapter list

# Start the Power Course MCP server
wp mcp-adapter serve --server=power-course-mcp --user=admin
```

### Step 3 — Verify the Connection

Ask your AI client to list courses:

> "List all published courses on this site"

If the connection is working, you'll get a structured response with your course data.

---

## Advanced / fallback: WordPress Application Password (Basic Auth)

> If you already connected via an application password, you are **not affected** — the old method still works. New users should prefer the Bearer Token flow above (no page hopping, no Base64).

1. Go to **WordPress Admin → Users → Profile**, scroll to **Application Passwords**
2. Enter a name (e.g. `Claude Code`), click **Add New Application Password**, and **copy it immediately** (shown only once)
3. Base64-encode `username:application-password`:

   ```bash
   echo -n "admin:ABCD 1234 EFGH 5678 IJKL 9012" | base64
   # outputs something like: YWRtaW46QUJDRCAxMjM0IEVGR0ggNTY3OCBJSktMIDkwMTI=
   ```

4. Use a `Basic` header in your AI client config:

   ```json
   {
     "mcpServers": {
       "power-course": {
         "type": "http",
         "url": "https://yoursite.com/wp-json/power-course/v2/mcp",
         "headers": {
           "Authorization": "Basic YWRtaW46QUJDRCAxMjM0IEVGR0ggNTY3OCBJSktMIDkwMTI="
         }
       }
     }
   }
   ```

> Application Passwords are built into WordPress (5.6+); no extra plugin needed. Bearer Token and Application Password can coexist.

---

## Available Tools (41 tools × 9 domains)

### Course (6 tools)

| Tool | Description |
|------|-------------|
| `course_list` | List courses with pagination, status filter, sorting, and keyword search |
| `course_get` | Get full course details (chapters, pricing, restrictions, subscriptions, bundles, teachers) |
| `course_create` | Create a new course (WooCommerce product with `_is_course = yes`) |
| `course_update` | Update course fields — only provided fields are modified |
| `course_delete` | Permanently delete a course (irreversible) |
| `course_duplicate` | Duplicate a course with chapters and bundle associations (draft status) |

### Chapter (7 tools)

| Tool | Description |
|------|-------------|
| `chapter_list` | List chapters filtered by course ID or parent chapter ID |
| `chapter_get` | Get full chapter details |
| `chapter_create` | Create a new chapter under a course |
| `chapter_update` | Update chapter title, content, or other fields |
| `chapter_delete` | Move a chapter to trash |
| `chapter_sort` | Atomically reorder chapters (all-or-nothing) |
| `chapter_toggle_finish` | Mark a chapter as finished/unfinished for a user |

### Student (9 tools)

| Tool | Description |
|------|-------------|
| `student_list` | List students with course filter, keyword search, and pagination |
| `student_get` | Get student details including enrolled course IDs |
| `student_add_to_course` | Manually grant a student access to a course (with optional expiry) |
| `student_remove_from_course` | Revoke a student's course access |
| `student_get_progress` | Get student's progress summary (completed chapters, percentage, expiry) |
| `student_get_log` | Query student activity logs with user/course filter and pagination |
| `student_update_meta` | Update student user_meta (whitelisted fields only) |
| `student_export_count` | Preview count of student × course rows before CSV export |
| `student_export_csv` | Export course student list to CSV (returns download URL) |

### Teacher (4 tools)

| Tool | Description |
|------|-------------|
| `teacher_list` | List all teachers (users with `is_teacher = yes` meta) |
| `teacher_get` | Get teacher details with their assigned courses |
| `teacher_assign_to_course` | Assign a teacher to a course (idempotent) |
| `teacher_remove_from_course` | Remove a teacher from a course (idempotent) |

### Bundle (4 tools)

| Tool | Description |
|------|-------------|
| `bundle_list` | List bundle/sales plan products with pagination and course filter |
| `bundle_get` | Get bundle details (linked courses, product IDs, quantities) |
| `bundle_set_products` | Atomically set bundle product IDs and quantities (rollback on failure) |
| `bundle_delete_products` | Remove all or specific products from a bundle |

### Order (3 tools)

| Tool | Description |
|------|-------------|
| `order_list` | List WooCommerce orders with status/customer/date filters (HPOS-compatible) |
| `order_get` | Get order details with course-related line items |
| `order_grant_courses` | Manually re-trigger course access granting for an order (idempotent) |

### Progress (3 tools)

| Tool | Description |
|------|-------------|
| `progress_get_by_user_course` | Get complete chapter-level progress for a student in a course |
| `progress_mark_chapter_finished` | Explicitly mark a chapter finished/unfinished (not toggle) |
| `progress_reset` | **Dangerous**: delete all progress for a student in a course (requires `confirm = true`) |

### Comment (3 tools)

| Tool | Description |
|------|-------------|
| `comment_list` | List comments for a post with pagination, type, and status filters |
| `comment_create` | Post a comment or review (optionally as another user with `moderate_comments`) |
| `comment_toggle_approved` | Toggle comment approval status (cascades to child comments) |

### Report (2 tools)

| Tool | Description |
|------|-------------|
| `report_revenue_stats` | Revenue statistics for a date range (orders, refunds, students, completions). Max 365 days |
| `report_student_count` | New student enrollment count grouped by interval. Max 365 days |

---

## Settings

Configure at **Power Course → Settings → AI** (MCP token management + write/delete permissions), or via REST API.

| Setting | Default | Description |
|---------|---------|-------------|
| `enabled` | `false` | Global MCP server on/off |
| `enabled_categories` | `[]` (all) | Active tool categories — empty array means all tools enabled |
| `rate_limit_per_min` | `60` | Max requests per minute |
| `allow_update` | `false` | **Issue #217.** Allow AI to create/update/sort/duplicate via MCP |
| `allow_delete` | `false` | **Issue #217.** Allow AI to delete/remove/reset via MCP |

---

## Operation-Level Permission Control (Settings → AI)

MCP is **read-only by default**. To allow AI to write or delete data, log in to:

> **WordPress Admin → Power Course → Settings → AI**

Toggle the two switches:

| Switch | Effect |
|--------|--------|
| **Allow update** | Enables create / update / sort / toggle / duplicate / assign / add / mark / grant operations |
| **Allow delete** | Enables delete / remove / reset operations |
| Both off (default) | **Read-only mode**: only list / get / export / stats / count tools are available |

> **Migration note (Issue #217)**: prior versions controlled this via the `ALLOW_UPDATE` / `ALLOW_DELETE` environment variables. **Those env vars are no longer read** — please configure via the AI Tab in WordPress Admin instead. After upgrading, both switches default to `false`; nothing is silently authorised.

### Operation Type Classification

Each MCP tool is automatically classified by its function:

| Operation Type | Tool Name Pattern | Examples |
|----------------|-------------------|----------|
| **read** | `*_list`, `*_get`, `*_export_*`, `*_stats`, `*_count` | `course_list`, `student_get`, `report_revenue_stats` |
| **update** | `*_create`, `*_update`, `*_sort`, `*_toggle_*`, `*_duplicate`, `*_set_*`, `*_assign_*`, `*_add_*`, `*_mark_*`, `*_grant_*` | `course_create`, `chapter_sort`, `student_add_to_course`, `chapter_toggle_finish` |
| **delete** | `*_delete`, `*_remove_*`, `*_reset` | `course_delete`, `student_remove_from_course`, `progress_reset` |

### Error Response

When an AI agent attempts an unauthorised operation, it receives a 403 error with a clear message pointing to the AI Tab:

```
Operation "delete" is disabled for MCP tool "course_delete".
Please enable "Allow delete" in WordPress Admin → Power Course → Settings → AI.
```

---

## Security

- All MCP tools enforce WordPress capability checks (`manage_woocommerce` by default)
- The `allow_update` / `allow_delete` switches in Settings → AI provide operation-level access control (read-only by default)
- Token authentication uses SHA-256 hashed storage — plaintext is shown only at creation
- Each token supports a JSON `capabilities` field to restrict which tools it can access
- Activity logging tracks every tool invocation with 30-day automatic cleanup via `wp_cron`
- Dangerous operations (e.g., `progress_reset`) require an explicit `confirm = true` parameter

---

## Management REST API

Management base URL: `{site_url}/wp-json/power-course/` (separate from the public MCP server endpoint at `power-course/v2/mcp`).

All endpoints require `manage_options` capability.

| Endpoint | Method | Description |
|----------|--------|-------------|
| `mcp/settings` | GET | Get MCP settings (incl. `allow_update` / `allow_delete`) |
| `mcp/settings` | POST | Update MCP settings (PATCH semantics — only fields sent are updated) |
| `mcp/tokens` | GET | List **the current admin's own** tokens (no hash / plaintext) |
| `mcp/tokens` | POST | Create new token (plaintext returned once; optional `expires_days` = 30 / 90 / 365, otherwise never expires) |
| `mcp/tokens/{id}` | DELETE | Revoke your own token (revoking someone else's returns 403) |

---

## Troubleshooting

| Problem | Solution |
|---------|----------|
| 401 Unauthorized | Bearer: ensure the token is not revoked / expired and is pasted into `Authorization: Bearer`. Basic: ensure Base64 credentials are correct and the user exists |
| 403 Forbidden (capability) | Ensure the user has `manage_woocommerce` capability |
| 403 "Operation not allowed" | Open WordPress Admin → Power Course → Settings → AI and turn on *Allow update* and/or *Allow delete* |
| Tools not showing up | Verify the MCP server is enabled and the relevant tool category is enabled |
| Connection timeout | Check that the site URL is publicly accessible; use STDIO for localhost |
| `localhost` not working | Use a tunnel (ngrok, Cloudflare Tunnel) or switch to WP-CLI STDIO transport |

---

## Links

- [Model Context Protocol Specification](https://modelcontextprotocol.io/)
- [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter)
- [WordPress Abilities API](https://github.com/WordPress/abilities-api)
- [Power Course README](./README.md)
