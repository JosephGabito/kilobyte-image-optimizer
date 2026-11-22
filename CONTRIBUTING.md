# Contributing to Kilobyte Image Optimizer

Thank you for your interest in contributing! Kilobyte Image Optimizer is 100% free, open-source software built to feel like a natural part of WordPress.

## Architecture

Both PHP (`src/`) and TypeScript (`src-js/`) follow Domain-Driven Design (DDD):

- **Domain (`src/Domain/`, `src-js/domain/`)**: Pure business rules, value objects, policies, and repository interfaces. No WordPress hooks, React, `fetch`, or DOM dependencies.
- **Application (`src/Application/`, `src-js/application/`)**: Use cases and workflow orchestration depending only on Domain interfaces.
- **Infrastructure (`src/Infrastructure/`, `src-js/infrastructure/`)**: Concrete adapters (`WP_Filesystem`, `WP_Query`, `wp_get_image_editor`, browser Web Worker, `@wordpress/api-fetch`).
- **Presentation (`src/Presentation/`, `src-js/presentation/`)**: REST endpoints, Admin UI (`@wordpress/components`), Media hooks, Frontend `<picture>` delivery, WP-CLI, and Upload interceptor.

Composer dependencies are **dev-only** (zero `vendor/` runtime bloat in production).

## Local Setup

Requirements:
- PHP **7.4+** and Composer 2
- Node.js **24.15+** (Node 24 series) and npm
- Local WordPress **6.6+** environment (DDEV or WP-CLI with the `plugin-check` plugin active for full release verification)

```bash
composer install
npm ci
npm run start
```

## Coding & UI Standards

- **PHP**: Must pass `WordPress-VIP-Go` + `WordPress-Extra` with **0 errors and 0 warnings** (`npm run lint:php`). Use `declare(strict_types=1);`, `WP_Filesystem` / `wp_filesize()` / `wp_delete_file()`, and bounded queries.
- **TypeScript**: Must pass `npm run typecheck` (`tsc --noEmit`) with **0 errors**.
- **UI**: Use standard WordPress Core components (`@wordpress/components`) only. Keep custom CSS minimal and scoped in `assets/css/fine-tuning.css`. No upsell banners, no double borders, sentence case labels, and plain user-friendly error messages via `toFriendlyError()`.
- **Originals**: Never destroy or overwrite a user's original Media Library file or break existing media URLs.

## Verification Pipeline

Before opening a pull request, run:

```bash
# Auto-fix PHP formatting
npm run lint:php:fix

# Run PHPCS (uncached), TypeScript check, production build, and headless WordPress Plugin Check
npm run verify
```

To test the exact archive that ships to WordPress.org (filtered by `.distignore`):

```bash
npm run package
```

## Pull Requests

1. Fork the repository and create a focused branch from `main`.
2. Keep changes scoped to one fix or feature, and include rebuilt `build/` assets if you modified `src-js/`.
3. Confirm `npm run verify` passes locally and GitHub Actions CI is green on your PR.
4. Explain **why** the change helps WordPress users and how you tested it.
