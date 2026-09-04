# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog, and this project adheres to Semantic Versioning.

## 1.3.0 - 2026-09-04

### Changed

- A Contact Form submission that fails Turnstile verification is now rejected as a validation failure instead of being discarded as spam. Contact Form returns its own failure response — a 400 carrying a `turnstile` error for a request that accepts JSON, and for a normal post a failure flash and the re-rendered page rather than a redirect — instead of a success response for a message that was never sent.
- What a normal post shows the visitor depends on the template, which has to render the flash message and `submission.getErrors('turnstile')`. Code that sends without validating, such as `Mailer::send($submission, false)`, still blocks the submission without telling the visitor.
- A submission that fails Contact Form's own rules is no longer verified, so the Turnstile token it carries is left unspent for the resubmission.

## 1.2.0 - 2026-08-26

### Added

- `requiresVerification()` and `isOperational()` on the plugin class, so custom controllers can reuse the plugin's own decisions instead of reimplementing them. Gate verification on `requiresVerification()`; use `isOperational()` to report configuration health.
- A configuration error in Craft's logs, naming the missing keys, when the plugin is enabled while the site key or secret key is not set. It is written where the script tag or the widget would have rendered, and when Contact Form hands the plugin a submission that it does not skip, so it also reaches sites that do not use Contact Form. Contact Form logs a spam warning in that situation, which points away from the real cause. Rate limiting uses Craft's cache to suppress repeats for 15 minutes per missing-key combination; a cache that is missing or unusable records the error once per affected request rather than staying silent.
- A warning on the plugin's control panel settings screen when it is enabled but a key is missing.

### Changed

- `craft.turnstilePass.script()` and `craft.turnstilePass.widget()` now render nothing when the secret key is missing, matching the existing behaviour for a missing site key. Submissions were already blocked in that configuration.
- Site and secret keys are trimmed, including the surrounding whitespace, separator and invisible formatting characters a pasted key can carry, such as a non-breaking space or a byte order mark. A key that is empty once those are removed counts as missing, so the configuration error names it and the control panel warns; a key that merely carries them is used without them rather than being sent to Cloudflare verbatim. A key that is not valid UTF-8 loses only its ASCII padding, as before.

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
