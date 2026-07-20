# Turnstile Pass

**Humans pass. Bots don't.**

Turnstile Pass integrates Cloudflare Turnstile with Craft CMS for frictionless spam protection. It provides automatic Contact Form verification and a simple Twig API for custom forms.

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

In the control panel, go to **Settings → Plugins → Turnstile Pass**, enable the plugin, and enter your Site Key and Secret Key. Both fields accept environment variable references such as `$TURNSTILE_SITE_KEY`.

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

Render the Turnstile script and widget inside your form:

```twig
<form method="post">
    {{ craft.turnstilePass.script() }}
    {{ craft.turnstilePass.widget() }}

    {# Your form fields and submit button #}
</form>
```

Pass widget options as an object:

```twig
{{ craft.turnstilePass.widget({ theme: 'dark', action: 'contact' }) }}
```

Widget option keys are converted to `data-*` attributes, so `theme` becomes `data-theme` and `action` becomes `data-action`. Keys that already start with `data-` are preserved. The `class` option is added to the widget's default `cf-turnstile` class.

## Contact Form integration

When `craftcms/contact-form` is installed, Turnstile Pass automatically verifies every submission before it is sent. No custom server-side code is required: enable Turnstile Pass and render its script and widget in the form.

**Important:** If Turnstile Pass is enabled but the widget is missing from the form, every submission will be blocked as spam because no Turnstile token is present.

**Availability:** Verification fails closed. If the Cloudflare siteverify API is unreachable, submissions are blocked as spam — the Contact Form plugin still shows visitors a success response, so the drop is silent from their perspective. Each failed verification attempt is recorded in Craft's logs (`connection-failed`), so monitor your logs if you suspect an outage.

## Server-side verification (custom forms)

For any custom form POST, read the token from the `cf-turnstile-response` body parameter and verify it in your module or controller:

```php
$token = (string)\Craft::$app->getRequest()->getBodyParam('cf-turnstile-response', '');

$result = \cdgrph\craftturnstilepass\Plugin::getInstance()
    ->turnstile
    ->verify($token);

if (!$result['success']) {
    throw new \yii\web\BadRequestHttpException('Turnstile verification failed.');
}
```

The result contains a boolean `success` value and an `error_codes` array.

## Widget modes

The widget mode—Managed, Non-interactive, or Invisible—is selected when the widget is created in the Cloudflare dashboard. Turnstile Pass works with all three modes.

## Testing

Cloudflare provides the following official dummy keys for automated and local testing:

| Purpose | Key |
|---|---|
| Site key — always passes (visible) | `1x00000000000000000000AA` |
| Site key — always blocks (visible) | `2x00000000000000000000AB` |
| Secret key — always passes | `1x0000000000000000000000000000000AA` |
| Secret key — always fails | `2x0000000000000000000000000000000AA` |

## Scope

Turnstile Pass provides automatic integration with the P&T Contact Form plugin and a general-purpose Twig API for custom forms. It does not provide plugin-specific integrations for other third-party form plugins.

## Support

Report bugs and documentation issues through [GitHub Issues](https://github.com/cdgrph/craft-turnstile-pass/issues). For other support enquiries, email [hello@cdgrph.com](mailto:hello@cdgrph.com).

## License

Turnstile Pass is licensed under [The Craft License](LICENSE.md).
