# Power Course

> WordPress 最好用的課程外掛 — A full-featured LMS plugin for WordPress + WooCommerce

**Version:** 0.11.23 | **PHP:** 8.0+ | **WordPress:** 5.7+ | **WooCommerce:** 7.6.0+

---

## Features

- 🎓 **Course Management** — Create and manage online courses as WooCommerce products
- 📚 **Hierarchical Chapters** — Nested chapter/unit structure with drag-and-drop reordering
- 🎬 **Multi-source Video** — Bunny Stream (HLS), YouTube, Vimeo, or custom embed code
- 📈 **Progress Tracking** — Per-student chapter completion and course progress (0–100%)
- 🔐 **Access Control** — Flexible expiry: unlimited, fixed duration, specific date, or follow WC Subscription
- 💼 **Bundle Products (銷售方案)** — Group multiple products to grant course access on purchase
- 👨‍🏫 **Teacher Management** — Assign instructors to courses with dedicated admin panel
- 👩‍🎓 **Student Management** — Bulk enroll/remove students, update expiry, export CSV
- 📧 **Automated Emails (PowerEmail)** — Drag-and-drop email builder with 5 trigger points
- 📊 **Analytics** — Revenue reports, course completion rates, student activity logs
- 💧 **Watermarking** — Dynamic video watermark and PDF watermark (user info overlay)
- 🛒 **WooCommerce Integration** — Guest checkout prevention for course products, subscription support
- 📱 **Mobile-friendly** — Sticky video player and tabs on mobile
- 🔌 **Elementor Compatible** — Works alongside Elementor for content editing
- 🏷️ **Shortcodes** — Ready-to-use shortcodes for course listings and CTAs

---

## Requirements

| Dependency | Minimum Version |
|-----------|----------------|
| WordPress | 5.7 |
| PHP | 8.0 |
| WooCommerce | 7.6.0 |
| [Powerhouse](https://github.com/j7-dev/wp-powerhouse) | 3.3.41 |

Optional:
- WooCommerce Subscriptions (for subscription-based course access)

---

## Installation

### Production
1. Download the latest release zip from [GitHub Releases](https://github.com/j7-dev/wp-power-course/releases)
2. Go to **WordPress Admin → Plugins → Add New → Upload Plugin**
3. Install and activate **WooCommerce** and **Powerhouse** first
4. Install and activate **Power Course**
5. Plugin will automatically create required database tables on activation

### Development

```bash
# Install PHP dependencies
composer install

# Install JS dependencies
pnpm install

# Start Vite dev server (port 5174)
pnpm run dev

# Build for production
pnpm run build
```

---

## Architecture Overview

### Backend (PHP)

```
inc/
├── classes/           # PSR-4: J7\PowerCourse\
│   ├── Bootstrap.php  # Service initialization
│   ├── Api/           # REST API endpoints (namespace: power-course)
│   ├── Resources/     # Domain resources (Course, Chapter, Student, etc.)
│   ├── BundleProduct/ # 銷售方案 (bundle product) logic
│   ├── PowerEmail/    # Automated email subsystem
│   ├── Compatibility/ # Version migration handlers
│   └── Utils/         # Utility classes
└── templates/         # PHP templates (course-product, classroom, my-account)
```

**Custom Database Tables:**
- `{prefix}_pc_avl_coursemeta` — User ↔ Course metadata (expiry, progress)
- `{prefix}_pc_avl_chaptermeta` — User ↔ Chapter progress
- `{prefix}_pc_email_records` — Automated email send history
- `{prefix}_pc_student_logs` — Student activity audit trail

### Frontend (React / TypeScript)

```
js/src/
├── main.tsx           # Entry point — mounts App1 and App2
├── App1.tsx           # Admin SPA (refine.dev + Ant Design, HashRouter)
├── App2/              # VidStack video player (standalone)
├── pages/admin/       # Admin page components
├── components/        # Reusable React components
└── hooks/             # Custom hooks (useEnv, useCourseSelect, etc.)
```

Two React apps are mounted:
- **App1** (`#power_course`) — Full admin dashboard built with [refine.dev](https://refine.dev)
- **App2** (`.pc-vidstack`) — Standalone VidStack player embedded in PHP templates

### Custom Post Type

| CPT | Slug | Description |
|-----|------|-------------|
| `pc_chapter` | `classroom` | Course chapters (hierarchical) |

---

## REST API

All endpoints under: `{site_url}/wp-json/power-course/`

| Endpoint | Methods | Description |
|----------|---------|-------------|
| `courses` | GET, POST, DELETE | List / create / bulk-delete courses |
| `courses/{id}` | GET, POST, DELETE | Get / update / delete single course |
| `courses/student-logs` | GET | Student activity logs |
| `courses/add-students` | POST | Grant course access |
| `courses/remove-students` | POST | Revoke course access |
| `courses/update-students` | POST | Update student expiry date |
| `chapters` | GET, POST, DELETE | List / create / bulk-delete chapters |
| `chapters/{id}` | POST | Update chapter |
| `chapters/sort` | POST | Reorder chapters |
| `toggle-finish-chapters/{id}` | POST | Toggle chapter completion status |
| `options` | GET, POST | Plugin options |
| `reports/revenue` | GET | Revenue analytics |

---

## Key Concepts

### Course Access (Availability)
```php
use J7\PowerCourse\Utils\Course as CourseUtils;

CourseUtils::is_course_product($product);         // Is it a course?
CourseUtils::is_avl($course_id, $user_id);        // Does user have access?
CourseUtils::is_course_ready($product);            // Has it launched?
CourseUtils::is_expired($product, $user_id);      // Has access expired?
CourseUtils::get_course_progress($product, $user_id); // float 0–100
```

### Granting Course Access
```php
use J7\PowerCourse\Resources\Course\LifeCycle;

// Always use the action hook — never call the underlying function directly
do_action(
    LifeCycle::ADD_STUDENT_TO_COURSE_ACTION,
    $user_id,     // int
    $course_id,   // int
    $expire_date, // 0 = unlimited | timestamp | 'subscription_123'
    $order        // \WC_Order|null
);
```

### Expire Date Types
| Value | Meaning |
|-------|---------|
| `0` | Unlimited access |
| Unix timestamp | Access until that date |
| `'subscription_123'` | Follows WC Subscription #123 |

### Bundle Products (銷售方案)
Bundle products group multiple products. When purchased, they can grant access to linked courses. **Bundle products cannot contain other bundle products.**

---

## WordPress Hooks

### Actions You Can Hook Into

```php
// After course access is granted
add_action('power_course_after_add_student_to_course',
    function(int $user_id, int $course_id, int|string $expire_date, ?\WC_Order $order) {
        // send custom notification, etc.
    }, 10, 4
);

// When a course reaches 100% completion
add_action('power_course_course_finished', function(int $course_id, int $user_id) {
    // award certificate, etc.
}, 10, 2);

// Before course/chapter meta is saved via API
add_action('power_course_before_update_product_meta',
    function(\WC_Product $product, array $meta_data) {
        // validate or modify meta_data
    }, 10, 2
);
```

---

## Email Automation (PowerEmail)

Create automated email templates triggered by student events:

| Trigger | When |
|---------|------|
| `course_granted` | Student gains course access |
| `course_finish` | Student completes 100% of course |
| `course_launch` | Course launch schedule is reached |
| `chapter_enter` | Student first visits a chapter |
| `chapter_finish` | Student marks a chapter complete |

Email templates support variable replacement: `{user.display_name}`, `{user.email}`, `{course.title}`, `{chapter.title}`, etc.

---

## Plugin Settings

Configure at **Power Course → Settings** or via REST API `POST /wp-json/power-course/options`.

Key settings (stored as `power_course_settings` WP option):

| Setting | Default | Description |
|---------|---------|-------------|
| `course_access_trigger` | `completed` | WC order status that grants access |
| `hide_myaccount_courses` | `no` | Hide courses in WC My Account |
| `fix_video_and_tabs_mobile` | `no` | Sticky video/tabs on mobile |
| `pc_watermark_qty` | `0` | Video watermark count (0 = disabled) |
| `pc_watermark_text` | `用戶 {display_name}...` | Watermark template |
| `pc_pdf_watermark_qty` | `0` | PDF watermark count |
| `hide_courses_in_main_query` | `no` | Exclude from main WP query |

---

## Development

### Commands

```bash
pnpm run dev            # Vite dev server (http://localhost:5174)
pnpm run build          # Production JS build
pnpm run build:wp       # WordPress-optimized build

pnpm run lint:php       # PHP CodeSniffer + PHPStan
pnpm run lint:ts        # TypeScript ESLint
pnpm run format         # Prettier-ESLint format

pnpm run release        # Bump patch version + build + release
pnpm run release:minor  # Bump minor version
pnpm run release:major  # Bump major version
pnpm run zip            # Create plugin zip file
pnpm run sync:version   # Sync version: package.json → plugin.php
```

### PHP Quality Tools

```bash
composer run phpstan    # PHPStan static analysis
composer run lint       # PHP CodeSniffer
composer run test       # PHPUnit tests
```

### Code Standards

- **PHP:** WordPress Coding Standards (WPCS), PHPStan level configured in `phpstan.neon`
- **TypeScript:** ESLint
- **Formatting:** Prettier with tabs, single quotes, no semicolons

### Environment Setup

Bunny Stream CDN credentials are configured in the **Powerhouse** plugin settings (not Power Course). Go to **Powerhouse → Settings** to set:
- `bunny_library_id`
- `bunny_cdn_hostname`
- `bunny_stream_api_key`

---

## Common Q & A

### 銷售方案可以再加入銷售方案嗎？
不行，銷售方案目前只能加入簡單商品、簡易訂閱，且不能加入銷售方案。

### 自製播放器 VidStack 各項功能在不同裝置的可用狀況？
[詳情統計](https://docs.google.com/spreadsheets/d/1mib3g3LLEl31GK11PMq8ozkQpFFMx8c_qss8UChW0j4/edit?usp=sharing)

---

## License

GPL v2 or later — see [LICENSE](LICENSE) for details.

---

## Links

- [GitHub Repository](https://github.com/j7-dev/wp-power-course)
- [Author](https://github.com/j7-dev)
- [WordPress Developer Reference](https://developer.wordpress.org/reference/)
- [WooCommerce Code Reference](https://woocommerce.github.io/code-reference/)
- [refine.dev Documentation](https://refine.dev/docs/)
