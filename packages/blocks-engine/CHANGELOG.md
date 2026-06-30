# Changelog

All notable changes to `@automattic/blocks-engine` will be documented in this file.

This project follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Versioning

This package uses [Semantic Versioning](https://semver.org/). Deprecations are warned one minor version ahead of removal.

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
