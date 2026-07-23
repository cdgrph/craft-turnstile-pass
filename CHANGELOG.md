# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog, and this project adheres to Semantic Versioning.

## Unreleased

### Added

- Optional "Allow Per-Form Skip" setting (`allowFormSkip`, off by default) that lets individual Contact Form templates skip Turnstile verification by submitting a `skipTurnstile` hidden field.

## 1.0.1 - 2026-07-22

### Added
- Quick-start guide with Cloudflare Turnstile key acquisition steps in the README.

### Changed
- Replaced generic composer.json keywords with search-relevant terms to improve Plugin Store discoverability.

## 1.0.0 - 2026-07-21

### Added

- Cloudflare Turnstile script and widget rendering through the `craft.turnstilePass` Twig variable.
- Automatic server-side verification for P&T Contact Form submissions.
- A reusable verification service for custom form handlers.
- Control panel and PHP configuration with environment variable support for site and secret keys.
