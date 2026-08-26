# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog, and this project adheres to Semantic Versioning.

## 1.2.0 - 2026-08-26

### Added

- `requiresVerification()` and `isOperational()` on the plugin class, so custom controllers can reuse the plugin's own decisions instead of reimplementing them. Gate verification on `requiresVerification()`; use `isOperational()` to report configuration health.
- A configuration error in Craft's logs, naming the missing keys, when the plugin is enabled while the site key or secret key is not set. It is written when a widget would have been rendered and when a Contact Form submission is verified, so it also reaches sites that do not use Contact Form. Contact Form logs a spam warning in that situation, which points away from the real cause. Rate limiting uses Craft's cache to suppress repeats for 15 minutes per missing-key combination; a cache that is missing or unusable falls back to reporting once per PHP process rather than staying silent.
- A warning on the plugin's control panel settings screen when it is enabled but a key is missing.

### Changed

- `craft.turnstilePass.script()` and `craft.turnstilePass.widget()` now render nothing when the secret key is missing, matching the existing behaviour for a missing site key. Submissions were already blocked in that configuration.
- Keys that resolve to whitespace only are treated as missing everywhere, including by `verify()`, which previously sent them to Cloudflare.

## 1.1.0 - 2026-07-23

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
