# Power Course MCP Server — Setup Guide

[繁體中文](./mcp.zh-TW.md) | English

> Connect AI agents (Claude Code, Cursor, GPT, etc.) to your WordPress LMS via the [Model Context Protocol (MCP)](https://modelcontextprotocol.io/).

---

## Overview

Power Course exposes an MCP server that lets AI agents programmatically manage your LMS — creating courses, enrolling students, querying reports, and more — all through a standardized tool interface.

Once connected, you can interact with your WordPress site using natural language:

- "List all courses on my site"
- "Enroll user #42 into the Advanced TypeScript course"
- "Show me this month's revenue report"
- "Export the student list for Course #101 as CSV"

The AI client translates your request into the appropriate MCP tool calls behind the scenes.

---

## Prerequisites

### WordPress Plugins

Install and activate the following plugins **before** enabling the MCP server:

| Plugin | Minimum Version | Download |
|--------|----------------|----------|
| Power Course | 1.1.0+ | [GitHub Releases](https://github.com/zenbuapps/wp-power-course/releases) |
| MCP Adapter | 0.5.0+ | [mcp-adapter.zip](https://github.com/WordPress/mcp-adapter/releases/latest) |
| Abilities API | 0.4.0+ | [abilities-api.zip](https://github.com/WordPress/abilities-api/releases/latest) |

### WordPress User

You need a WordPress account with **`manage_woocommerce`** capability (typically an Administrator or Shop Manager role).

---

## Setup Steps

### Step 1 — Enable the MCP Server (WordPress Admin)

1. Log in to your WordPress admin panel
2. Navigate to **Power Course → Settings → MCP**
3. Toggle the MCP server **on**
4. (Optional) Select which tool categories to enable — by default, all 9 categories (41 tools) are active

### Step 2 — Generate an Application Password

1. Go to **WordPress Admin → Users → Profile**
2. Scroll down to the **Application Passwords** section
3. Enter a name (e.g. `Claude Code`) and click **Add New Application Password**
4. **Copy the generated password immediately** — it is shown only once

> **Tip**: Application Passwords are built into WordPress (5.6+). No extra plugin is needed.

### Step 3 — Encode Your Credentials

Combine your WordPress username and application password, then Base64-encode the string:

```
username:xxxx xxxx xxxx xxxx xxxx xxxx
```

You can encode it using the command line:

```bash
echo -n "admin:ABCD 1234 EFGH 5678 IJKL 9012" | base64
```

This outputs something like: `YWRtaW46QUJDRCAxMjM0IEVGR0ggNTY3OCBJSktMIDkwMTI=`

### Step 4 — Configure Your AI Client

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
        "Authorization": "Basic ${POWER_COURSE_MCP_AUTH}"
      }
    }
  }
}
```

Then set the environment variable (keeps credentials out of version control):

```bash
export POWER_COURSE_MCP_AUTH="YWRtaW46QUJDRCAxMjM0IEVGR0ggNTY3OCBJSktMIDkwMTI="
```

**Option B — Personal global**: add to `~/.claude.json`

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

**Option C — CLI quick setup**:

```bash
claude mcp add --transport http power-course \
  https://yoursite.com/wp-json/power-course/v2/mcp \
  --header "Authorization: Basic YWRtaW46QUJDRCAxMjM0IEVGR0ggNTY3OCBJSktMIDkwMTI="
```

> **Note**: `settings.json` manages permissions and preferences — MCP servers **cannot** be configured there.

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

## Security

- All MCP tools enforce WordPress capability checks (`manage_woocommerce` by default)
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
| 403 Forbidden | Ensure the user has `manage_woocommerce` capability |
| Tools not showing up | Verify MCP server is enabled and the tool category is active in Settings → MCP |
| Connection timeout | Check that the site URL is publicly accessible; use STDIO for localhost |
| `localhost` not working | Use a tunnel (ngrok, Cloudflare Tunnel) or switch to WP-CLI STDIO transport |

---

## Links

- [Model Context Protocol Specification](https://modelcontextprotocol.io/)
- [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter)
- [WordPress Abilities API](https://github.com/WordPress/abilities-api)
- [Power Course README](./README.md)
