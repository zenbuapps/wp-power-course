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

You need a WordPress account with **`manage_woocommerce`** capability (typically an Administrator or Shop Manager role).

---

## Setup Steps

### Step 1 — Generate an Application Password

1. Go to **WordPress Admin → Users → Profile**
2. Scroll down to the **Application Passwords** section
3. Enter a name (e.g. `Claude Code`) and click **Add New Application Password**
4. **Copy the generated password immediately** — it is shown only once

> **Tip**: Application Passwords are built into WordPress (5.6+). No extra plugin is needed.

### Step 2 — Encode Your Credentials

Combine your WordPress username and application password, then Base64-encode the string:

```
username:xxxx xxxx xxxx xxxx xxxx xxxx
```

You can encode it using the command line:

```bash
echo -n "admin:ABCD 1234 EFGH 5678 IJKL 9012" | base64
```

Or visit [https://www.base64encode.org/](https://www.base64encode.org/) and enter `admin:ABCD 1234 EFGH 5678 IJKL 9012`

This outputs something like: `YWRtaW46QUJDRCAxMjM0IEVGR0ggNTY3OCBJSktMIDkwMTI=`

### Step 3 — Configure Your AI Client

Add the MCP server to your AI client's configuration.

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
        "Authorization": "Basic YWRtaW46QUJDRCAxMjM0IEVGR0ggNTY3OCBJSktMIDkwMTI="
      }
    }
  }
}
```

**Option B — Personal global** (recommended for personal use): add to `~/.claude.json`

```json
{
  "mcpServers": {
    "power-course": {
      "type": "http",
      "url": "https://yoursite.com/wp-json/power-course/v2/mcp",
      "headers": {
        "Authorization": "Basic YWRtaW46QUJDRCAxMjM0IEVGR0ggNTY3OCBJSktMIDkwMTI="
      },
      "env": {
        "ALLOW_UPDATE": "1",
        "ALLOW_DELETE": "1"
      }
    }
  }
}
```

> **Note**: The `env` variables `ALLOW_UPDATE` and `ALLOW_DELETE` control write permissions. See the "Environment Variable Access Control" section below for details.

**Option C — CLI quick setup**:

```bash
claude mcp add --transport http power-course \
  https://yoursite.com/wp-json/power-course/v2/mcp \
  --header "Authorization: Basic YWRtaW46QUJDRCAxMjM0IEVGR0ggNTY3OCBJSktMIDkwMTI="
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
        "Authorization": "Basic YWRtaW46QUJDRCAxMjM0IEVGR0ggNTY3OCBJSktMIDkwMTI="
      }
    }
  }
}
```

#### WP-CLI (STDIO Transport)

If you have WP-CLI access on the server, you can use STDIO transport instead of HTTP:

```bash
# List all registered MCP servers
wp mcp-adapter list

# Start the Power Course MCP server
wp mcp-adapter serve --server=power-course-mcp --user=admin
```

### Step 5 — Verify the Connection

Ask your AI client to list courses:

> "List all published courses on this site"

If the connection is working, you'll get a structured response with your course data.

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

Configure at **Power Course → Settings → MCP** or via REST API.

| Setting | Default | Description |
|---------|---------|-------------|
| `enabled` | `false` | Global MCP server on/off |
| `enabled_categories` | `[]` (all) | Active tool categories — empty array means all tools enabled |
| `rate_limit_per_min` | `60` | Max requests per minute |

---

## Environment Variable Access Control

The MCP server uses environment variables for fine-grained operation-level permission control. **By default, only read operations are allowed** — write and delete must be explicitly enabled.

### Environment Variables

| Variable | Value | Effect |
|----------|-------|--------|
| `ALLOW_UPDATE` | `"1"` | Enable create & modify operations (create / update / sort / toggle / duplicate / assign / add / mark / grant) |
| `ALLOW_DELETE` | `"1"` | Enable delete operations (delete / remove / reset) |
| Neither set | — | **Read-only mode**: only list / get / export / stats / count tools are available |

### Operation Type Classification

Each MCP tool is automatically classified by its function:

| Operation Type | Tool Name Pattern | Examples |
|----------------|-------------------|----------|
| **read** | `*_list`, `*_get`, `*_export_*`, `*_stats`, `*_count` | `course_list`, `student_get`, `report_revenue_stats` |
| **update** | `*_create`, `*_update`, `*_sort`, `*_toggle_*`, `*_duplicate`, `*_set_*`, `*_assign_*`, `*_add_*`, `*_mark_*`, `*_grant_*` | `course_create`, `chapter_sort`, `student_add_to_course` |
| **delete** | `*_delete`, `*_remove_*`, `*_reset` | `course_delete`, `student_remove_from_course`, `progress_reset` |

### Configuration Examples

#### Read-only mode (default, safest)

Suitable for querying and reporting — AI cannot modify any data:

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

#### Allow create & update, but no delete

Suitable for daily content management — can create courses and chapters, but cannot delete anything:

```json
{
  "mcpServers": {
    "power-course": {
      "type": "http",
      "url": "https://yoursite.com/wp-json/power-course/v2/mcp",
      "headers": {
        "Authorization": "Basic YWRtaW46QUJDRCAxMjM0IEVGR0ggNTY3OCBJSktMIDkwMTI="
      },
      "env": {
        "ALLOW_UPDATE": "1"
      }
    }
  }
}
```

#### Full access (read + update + delete)

Suitable for fully trusted automation scenarios:

```json
{
  "mcpServers": {
    "power-course": {
      "type": "http",
      "url": "https://yoursite.com/wp-json/power-course/v2/mcp",
      "headers": {
        "Authorization": "Basic YWRtaW46QUJDRCAxMjM0IEVGR0ggNTY3OCBJSktMIDkwMTI="
      },
      "env": {
        "ALLOW_UPDATE": "1",
        "ALLOW_DELETE": "1"
      }
    }
  }
}
```

### Error Response

When an AI agent attempts an unauthorized operation, it receives a 403 error with a clear message:

```
Operation not allowed for MCP tool "course_delete". Operation type "delete" requires environment variable ALLOW_DELETE=1
```

---

## Security

- All MCP tools enforce WordPress capability checks (`manage_woocommerce` by default)
- Environment variables `ALLOW_UPDATE` / `ALLOW_DELETE` provide operation-level access control (read-only by default)
- Token authentication uses SHA-256 hashed storage — plaintext is shown only at creation
- Each token supports a JSON `capabilities` field to restrict which tools it can access
- Activity logging tracks every tool invocation with 30-day automatic cleanup via `wp_cron`
- Dangerous operations (e.g., `progress_reset`) require an explicit `confirm = true` parameter

---

## Management REST API

Base URL: `{site_url}/wp-json/power-course/v2/`

All endpoints require `manage_options` capability.

| Endpoint | Method | Description |
|----------|--------|-------------|
| `mcp/settings` | GET | Get MCP settings |
| `mcp/settings` | POST | Update MCP settings |
| `mcp/tokens` | GET | List API tokens (hashed, no plaintext) |
| `mcp/tokens` | POST | Create new token (returns plaintext once) |
| `mcp/tokens/{id}` | DELETE | Revoke a token |
| `mcp/activity` | GET | Query tool activity logs (filterable by `tool_name`, paginated) |

---

## Troubleshooting

| Problem | Solution |
|---------|----------|
| 401 Unauthorized | Check that your Base64 credentials are correct and the WordPress user exists |
| 403 Forbidden (capability) | Ensure the user has `manage_woocommerce` capability |
| 403 "Operation not allowed" | Add `ALLOW_UPDATE` and/or `ALLOW_DELETE` to your `env` config (see Environment Variable Access Control) |
| Tools not showing up | Verify MCP server is enabled and the tool category is active in Settings → MCP |
| Connection timeout | Check that the site URL is publicly accessible; use STDIO for localhost |
| `localhost` not working | Use a tunnel (ngrok, Cloudflare Tunnel) or switch to WP-CLI STDIO transport |

---

## Links

- [Model Context Protocol Specification](https://modelcontextprotocol.io/)
- [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter)
- [WordPress Abilities API](https://github.com/WordPress/abilities-api)
- [Power Course README](./README.md)
