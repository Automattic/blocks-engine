# Changelog

All notable changes to `@automattic/blocks-engine` will be documented in this file.

This project follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Versioning

This package uses [Semantic Versioning](https://semver.org/). Deprecations are warned one minor version ahead of removal.

## [0.2.1] - 2026-06-29

### Changed

- The WordPress runtime (`@wordpress/block-library`, `@wordpress/blocks`, `@wordpress/block-serialization-default-parser`) is now compiled into a single self-contained CJS chunk shipped in `dist`, and `@wordpress/*` moved to `devDependencies`. A clean consumer install drops from ~491 MB to ~53 MB with no public API change. The runtime is loaded lazily through an internal resolver that prefers the bundle and falls back to real packages in development.
- Nine edit-only `@wordpress` leaf packages (icons, ui, dataviews, image-cropper, server-side-render, commands, preferences, notices, keyboard-shortcuts) are aliased to an empty stub at build time, since the engine never executes block `edit` components — only `save`, `transforms`, and attribute sourcing. Fidelity is unchanged: the full reconstruct/golden suite passes identically against the bundle.

### Fixed

- Aligned all `@wordpress/*` dependencies to a single coherent release, eliminating nested duplicate `node_modules` (previously a mismatched version set installed ~12 copies of some packages). This alone cut a clean install from ~2.0 GB to ~491 MB.

## [0.2.0] - 2026-06-29

### Added

- Static-HTML-to-block-theme reconstruction pipeline: section extraction, preserve-DOM-first reconstruction (native blocks that keep their source classes, with nested `core/html` islands for un-convertible elements), per-section rich-CSS routing, and content-addressed `lib-i` instance-style dedup.
- Carried source CSS is enqueued on the front end and loaded into the block editor via a generated `functions.php` (`add_editor_style`).
- Section extraction now captures designed non-heading bands such as marquees / ticker strips.
- Local and remote source images are carried into the theme, with SSRF and stored-XSS hardening on remote fetches.

### Fixed

- WordPress block gap is neutralized on carried full-bleed sections so they sit flush, while blocks added in the editor keep the default gap.
- Reveal-gated source CSS is neutralized so content is not left hidden.
- Lossy section source identity is preserved.

### Removed

- Unused `convert-semantic-sections` and `semantic-html` modules.

## [0.1.0] - 2026-06-24

### Added

- Async main convert API.
- `BlocksEngineError` for stable package errors.
- `/internals` subpath for supported internal consumers.
- `npx` CLI entrypoint.

### Fixed

- Export map now points to built `dist` artifacts.
