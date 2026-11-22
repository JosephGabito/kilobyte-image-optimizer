# AGENTS.md

Engineering, architecture, and UX principles for **Kilobyte Image Optimizer** (`kilobyte-image-optimizer`). Every contributor and coding agent must follow these rules.

---

## 1. Architecture (DDD Everywhere)

Both PHP (`src/`) and TypeScript (`src-js/`) follow strict Domain-Driven Design:

- **Domain (`src/Domain/`, `src-js/domain/`)**: Pure business rules, value objects, policies, error mapping, and repository interfaces. Zero WordPress hooks, zero React, zero `fetch`, zero DOM.
- **Application (`src/Application/`, `src-js/application/`)**: Use cases and workflow orchestration depending only on Domain ports.
- **Infrastructure (`src/Infrastructure/`, `src-js/infrastructure/`)**: Concrete adapters (`WP_Filesystem`, `WP_Query`, `wp_options`, `wp_get_image_editor`, browser `OffscreenCanvas` Web Worker, `@wordpress/api-fetch`).
- **Presentation (`src/Presentation/`, `src-js/presentation/`)**: WordPress REST controllers, Admin UI, Media hooks, Frontend delivery, WP-CLI, and Upload interceptor.

### Runtime Discipline
- **Zero `vendor/` bloat in production**: Composer is dev-only (`composer run lint` / `composer run lint:fix`). Production autoloading uses the zero-dependency PSR-4 autoloader in `kilobyte-image-optimizer.php`.
- **Zero global JS hacks**: Share `src-js/domain/` and `src-js/infrastructure/` across `@wordpress/scripts` entrypoints (`build/admin.js`, `build/upload-interceptor.js`).
- **Release ZIP**: Include `src-js/`, `package*.json`, `tsconfig.json`, and `webpack.config.js` so WordPress.org reviewers can rebuild the bundles. Use `.distignore` to exclude installed dependencies and local tooling.

---

## 2. Coding Standards (WordPress VIP Go)

- Every PHP file must pass `composer run lint` (`WordPress-VIP-Go` + `WordPress-Extra`) with **0 errors and 0 warnings**.
- Always use `declare(strict_types=1);` in PHP files.
- Never use raw filesystem functions (`copy`, `file_put_contents`, `file_exists`, `filesize`) — use `WP_Filesystem`, `wp_filesize()`, and `wp_delete_file()`.
- Never run unbounded queries (`posts_per_page => -1`) or unindexed `meta_query` scans on request paths. Maintain O(1) incremental counters for aggregate stats.
- **Preserve originals.** WordPress receives the selected file unchanged. Browser and server optimization write separate copies. Keep existing Media Library links and retain legacy original backups when restoring them.

---

## 3. UI & Design System (Native WordPress Core)

- **Feel like a missing WordPress Core feature.** Zero plugin branding, zero marketing copy, zero upsell banners, zero accounts.
- **Pure `@wordpress/components` (Gutenberg Storybook) only.** Enqueue WordPress Core's `wp-components` stylesheet.
- **Minimal CSS only:** Keep custom CSS strictly inside `assets/css/fine-tuning.css` (loaded only on the plugin's admin screen) for Core chrome alignment only. Never invent custom cards, pills, or component libraries.
- **Double borders are illegal.** Never nest `<Panel>` inside `<Card>`, and never put bordered `<Surface>` boxes inside bordered cards.
- **Clear button hierarchy:** Only one primary CTA (`variant="primary"`, 40px) may dominate the screen at a time. Secondary/utility actions use `size="compact"` (`secondary` / `tertiary`). `Save preference` stays disabled/secondary until settings are dirty.

---

## 4. Personas & Progressive Disclosure

Design every screen for three users at once without cluttering anyone:

- **Cindy (site owner making a living on WordPress):** Plain language, zero dev jargon, safe defaults, test-before-bulk ("Aha!" moment on her own photo), and 1-click undo (`Restore all`). Keep advanced knobs tucked inside the collapsed `Advanced` drawer.
- **Guss (agency dev managing client sites):** Backup disk usage, recovery for legacy originals, a copyable client summary, and `kilobyte_io_settings` filter for blueprint locking.
- **Adam (technical perf engineer):** Off-thread Web Worker compression, Safari/server fallback, WP-CLI (`wp kilobyte stats | optimize | restore | purge-backups`), and clean filter/action hooks (`kilobyte_io_should_optimize`, `kilobyte_io_after_optimize`).

---

## 5. Copy & Communication Rules

- **Sentence case everywhere** (headings, labels, buttons, notices).
- **Zero em dashes (`—`)** in UI copy. Write short, plain sentences for comprehension rather than technical rhythm.
- **Decisions, not options:** Do not expose low-level engineering knobs (like concurrency/worker counts) in the UI when a smart default + PHP filter works better.
- **Standardized error messages (`src-js/domain/errors.ts`):** Never surface raw technical strings directly to users. Every error must pass through `toFriendlyError()` and provide:
  1. `friendly` — plain explanation of what happened
  2. `action` — clear next step for the user
  3. `technical` — raw exception tucked inside a collapsible `Technical details` disclosure

---

## 6. Perceived Performance & Interaction Feedback

- **Zero dead air (> 2s rule):** Never leave a user staring at a static spinner. Render optimistic previews at 0 ms, stream download bytes with `ProgressBar`, move heavy encoding off the main thread into a Web Worker, and show a live elapsed timer or ETA.
- **Pipeline stage clarity:** Preloaders must state the exact active stage (`Reading image...`, `Encoding WEBP...`, `Saving to Media Library...`) and offer a human time estimate (`About 30 seconds left`).
- **Reload protection:** Whenever a browser-driven bulk job cannot survive a reload, display an explicit notice (`Keep this tab open and do not reload until finished.`) and attach a `beforeunload` guard.
- **0 ms button feedback:** Every action button (`Stop`, `Optimize`, `Regenerate`, `Restore`, `Delete backups`, `Save`) must transition immediately on click (`isBusy`, disabled, active verb label like `Stopping...`) so users never wonder if a click registered.
