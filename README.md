# Turnstile Pass

**Humans pass. Bots don't.**

Turnstile Pass integrates Cloudflare Turnstile with Craft CMS for frictionless spam protection. For standard contact forms that do not need visible verification UI, Turnstile Pass recommends Invisible mode as the default configuration. The plugin provides automatic Contact Form verification and a simple Twig API for custom forms.

## Requirements

- Craft CMS `^5.0`
- PHP `^8.2`
- A Cloudflare account; Turnstile is free to use
- The Contact Form plugin is optional; Turnstile Pass integrates with it automatically when installed

## Quick start

```bash
composer require cdgrph/craft-turnstile-pass
php craft plugin/install turnstile-pass
```

In the control panel, go to **Settings > Plugins > Turnstile Pass**, enable the plugin, and enter your Site Key and Secret Key. Both fields accept environment variable references such as `$TURNSTILE_SITE_KEY`.

Alternatively, create `config/turnstile-pass.php`:

```php
<?php
return [
    'enabled' => true,
    'siteKey' => '$TURNSTILE_SITE_KEY',
    'secretKey' => '$TURNSTILE_SECRET_KEY',
];
```

## Usage

Render the Turnstile script once in your layout or page template, then render one widget inside each form you want to protect:

```twig
{# Render once per page, preferably in the layout. #}
{{ craft.turnstilePass.script() }}

<form method="post">
    {{ craft.turnstilePass.widget() }}

    {# Your form fields and submit button #}
</form>
```

Pass widget options as an object:

```twig
{{ craft.turnstilePass.widget({ theme: 'dark', size: 'compact' }) }}
```

Widget option keys are converted to `data-*` attributes, so `theme` becomes `data-theme` and `size` becomes `data-size`. Keys that already start with `data-` are preserved. The `class` option is added to the widget's default `cf-turnstile` class.

## Contact Form integration

When `craftcms/contact-form` is installed, Turnstile Pass automatically verifies every submission before it is sent. No custom server-side code is required: enable Turnstile Pass, render its script on the page, and render its widget inside the form.

**Important:** If Turnstile Pass is enabled but the widget is missing from the form, every submission will be blocked as spam because no Turnstile token is present.

**Silent drops:** A CSP violation, ad blocker, network error, or unsupported browser can leave the token empty when the form is submitted. Turnstile Pass then treats the submission as spam and discards it, while the Contact Form plugin returns a success response to the visitor. Invisible mode has no widget, checkbox, loading indicator, or error UI, so this failure can be harder to notice.

**Availability:** Verification fails closed. If the Cloudflare siteverify API is unreachable, submissions are blocked as spam — the Contact Form plugin still shows visitors a success response, so the drop is silent from their perspective. Each failed verification attempt is recorded in Craft's logs (`connection-failed`), so monitor your logs if you suspect an outage.

## Content Security Policy

Turnstile requires `https://challenges.cloudflare.com` to be allowed by both the `script-src` and `frame-src` directives in your Content Security Policy. Without both directives, the script or its iframe can be blocked and token generation can fail. See Cloudflare's [Turnstile Content Security Policy reference](https://developers.cloudflare.com/turnstile/reference/content-security-policy/).

## Server-side verification (custom forms)

For any custom form POST, read the token from the `cf-turnstile-response` body parameter and verify it in your module or controller:

```php
$token = \Craft::$app->getRequest()->getBodyParam('cf-turnstile-response');

if (!is_string($token) || $token === '') {
    throw new \yii\web\BadRequestHttpException('Turnstile verification failed.');
}

$result = \cdgrph\craftturnstilepass\Plugin::getInstance()
    ->turnstile
    ->verify($token);

if (!$result['success']) {
    throw new \yii\web\BadRequestHttpException('Turnstile verification failed.');
}
```

The result contains only a boolean `success` value and an `error_codes` array. The `verify()` method does not expose or validate the Siteverify response's `action` or `hostname` values. If you rely on `action` or accept submissions across multiple hostnames, call the Siteverify API directly instead of `verify()` and compare those values yourself — tokens are single-use, so a token cannot be verified a second time.

## Widget modes

The widget mode — Managed, Non-interactive, or Invisible — is selected when the widget is created in the Cloudflare dashboard; it is not a Turnstile Pass setting. Turnstile Pass works with all three modes.

- **Managed:** Turnstile chooses between a non-interactive check and a visible checkbox based on the visitor's risk level, and may ask the visitor to interact when additional verification is needed.
- **Non-interactive:** Turnstile displays a visible widget and loading indicator while it runs the challenge, but it does not ask the visitor to interact.
- **Invisible:** Turnstile does not display the Turnstile widget, checkbox, or loading indicator. It is the recommended default in Turnstile Pass for standard contact forms that do not need visible verification UI. Invisible mode cannot present an interactive challenge to a suspicious visitor; in that case, token issuance fails instead.

## Token lifecycle

Turnstile tokens are short-lived: they expire after five minutes and are single-use, so one Siteverify request consumes a token. The default `refresh-expired=auto` behavior automatically refreshes an expired token for an active widget.

A standard static, server-rendered form that navigates to a new page after POST does not need additional JavaScript. Multi-step forms, AJAX resubmissions, SPAs, and submissions after a page is restored from the back-forward cache (bfcache) can reuse an already consumed token and fail verification. These flows may need explicit Turnstile rendering or a call to `turnstile.reset()` before another submission.

## Testing

Cloudflare provides the following official dummy keys for automated and local testing:

| Purpose | Key |
|---|---|
| Site key — always passes (visible) | `1x00000000000000000000AA` |
| Site key — always blocks (visible) | `2x00000000000000000000AB` |
| Site key — always passes (invisible) | `1x00000000000000000000BB` |
| Site key — always blocks (invisible) | `2x00000000000000000000BB` |
| Secret key — always passes | `1x0000000000000000000000000000000AA` |
| Secret key — always fails | `2x0000000000000000000000000000000AA` |

Production site keys can classify automated browsers, including headless and WebDriver sessions, as bots. Use dummy keys for E2E and other automated tests. Validate production keys only in a real browser operated by a person.

## Scope

Turnstile Pass provides automatic integration with the Contact Form plugin and a general-purpose Twig API for custom forms. It does not provide plugin-specific integrations for other third-party form plugins.

The automatic Contact Form integration reads the fixed `cf-turnstile-response` field name. The `response-field: false` option and any change to `response-field-name` are unsupported because they prevent the integration from reading the token. The `execution: 'execute'` option is unsupported unless your own JavaScript calls `turnstile.execute()` before submission and leaves the generated token in the fixed response field.

Multiple Turnstile widgets inside the same form are unsupported because their response fields use the same name and conflict.

## Privacy

Sites that use Turnstile must reference Cloudflare's [Turnstile Privacy Addendum](https://www.cloudflare.com/turnstile-privacy-policy/) from their own privacy policy.

## Support

Report bugs and documentation issues through [GitHub Issues](https://github.com/cdgrph/craft-turnstile-pass/issues). For other support enquiries, email [hello@cdgrph.com](mailto:hello@cdgrph.com).

## License

Turnstile Pass is licensed under [The Craft License](LICENSE.md).
