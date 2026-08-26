# Turnstile Pass

**Humans pass. Bots don't.**

Turnstile Pass integrates Cloudflare Turnstile with Craft CMS for frictionless spam protection. For standard contact forms that do not need visible verification UI, Turnstile Pass recommends Invisible mode as the default configuration. The plugin provides automatic Contact Form verification and a simple Twig API for custom forms.

## Requirements

- Craft CMS `^5.0`
- PHP `^8.2`
- A Cloudflare account; Turnstile is free to use
- The Contact Form plugin is optional; Turnstile Pass integrates with it automatically when installed

## Quick start

### 1. Get your Turnstile keys

1. Log in to the [Cloudflare dashboard](https://dash.cloudflare.com/) and navigate to **Turnstile**.
2. Click **Add widget**, enter a widget name, add your hostname, and choose a widget mode (Invisible is recommended for standard contact forms).
3. Copy the **Site Key** and **Secret Key** shown after creation.

### 2. Install the plugin

```bash
composer require cdgrph/craft-turnstile-pass
php craft plugin/install turnstile-pass
```

### 3. Configure

In the control panel, go to **Settings > Plugins > Turnstile Pass**, enable the plugin, and enter your Site Key and Secret Key. Both fields accept environment variable references such as `$TURNSTILE_SITE_KEY`.

Both keys are required. When the plugin is enabled and either key is missing — for example because an environment variable is not defined on that environment — `script()` and `widget()` render nothing, the control panel settings screen shows a warning, and the plugin names the missing keys in a configuration error in Craft's logs. That error is written when a widget would have been rendered and when a Contact Form submission is verified. Repeats are suppressed for 15 minutes per missing-key combination; if Craft's cache cannot hold that window, the error is reported once and then suppressed until the process restarts.

Alternatively, create `config/turnstile-pass.php`:

```php
<?php
return [
    'enabled' => true,
    'allowFormSkip' => false,
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

### Skipping verification for specific forms

Enable **Allow Per-Form Skip** in the plugin settings, or set `allowFormSkip` to `true` in `config/turnstile-pass.php`. Then add this hidden field to any Contact Form template that should skip verification:

```twig
{{ hiddenInput('skipTurnstile', 'true') }}
```

The value can be any truthy string such as `true` or `1`.

While this setting is on, any client can submit this field and bypass verification on any form — the plugin cannot tell which form a submission came from. Enable it only on sites that need per-form skipping.

## Contact Form integration

When `craftcms/contact-form` is installed, Turnstile Pass automatically verifies every submission before it is sent. No custom server-side code is required: enable Turnstile Pass, render its script on the page, and render its widget inside the form.

**Important:** If Turnstile Pass is enabled but the widget is missing from the form, every submission will be blocked as spam because no Turnstile token is present.

**Incomplete configuration:** If the plugin is enabled while a key is missing, verification is not skipped — submissions still fail closed. The plugin records a configuration error in Craft's logs naming the missing keys, so the cause is distinguishable from Contact Form's own spam warning. Rate limiting uses Craft's cache. A cache that is missing, unusable, or unreachable falls back to reporting once per PHP process rather than staying silent, so the error is never lost but its rate is coarser than the 15 minute window.

**Silent drops:** A CSP violation, ad blocker, network error, or unsupported browser can leave the token empty when the form is submitted. Turnstile Pass then treats the submission as spam and discards it, while the Contact Form plugin returns a success response to the visitor. Invisible mode has no widget, checkbox, loading indicator, or error UI, so this failure can be harder to notice.

**Availability:** Verification fails closed. If the Cloudflare siteverify API is unreachable, submissions are blocked as spam — the Contact Form plugin still shows visitors a success response, so the drop is silent from their perspective. Each failed verification attempt is recorded in Craft's logs (`connection-failed`), so monitor your logs if you suspect an outage.

## Error handling

Client-side failures are reported through `error-callback`; see Cloudflare's [client-side errors guide](https://developers.cloudflare.com/turnstile/troubleshooting/client-side-errors/). If no callback is configured, a challenge failure throws a JavaScript exception that appears as an uncaught error in global error handlers and error-monitoring tools.

```twig
{# Define the callback before the Turnstile script runs the widget. #}
<div id="turnstile-error" role="alert"></div>
<script>
window.onTurnstileError = function (code) {
    const errorCode = String(code);
    const retryable = errorCode.startsWith('600') || errorCode.startsWith('300')
        || errorCode === '110600' || errorCode === '110620' || errorCode === '200500';
    const message = document.getElementById('turnstile-error');

    if (message) {
        message.textContent = retryable
            ? 'Verification failed. Retrying automatically.'
            : 'Verification is unavailable. Reload the page or contact the site owner.';
    }

    return retryable;
};

window.onTurnstileSuccess = function () {
    const message = document.getElementById('turnstile-error');
    if (message) {
        message.textContent = '';
    }
};
</script>

<form method="post">
    {{ craft.turnstilePass.widget({ 'callback': 'onTurnstileSuccess', 'error-callback': 'onTurnstileError' }) }}

    {# Your form fields and submit button #}
</form>
```

With implicit rendering, specify the callback's global function name as a string, not a function value. As described under Usage, `error-callback` becomes `data-error-callback`, and the function must be reachable from `window` when the widget runs.

If no error callback is configured, Turnstile throws a JavaScript exception. If a callback is configured, a falsy return value (including `undefined`) causes Turnstile to log a warning containing the error code to the JavaScript console, while a non-falsy return value suppresses additional error logging. A configuration problem reaches your own monitoring only if the callback reports it itself.

The example defines its callbacks in an inline script. A Content Security Policy that blocks inline scripts prevents that block from running, leaves the callback undefined, and makes Turnstile behave as though no callback were configured, resulting in the exception described above; move the callbacks to an external file, or serve them with a nonce that your policy allows. The Content Security Policy section covers the Cloudflare hosts Turnstile itself requires. The example also uses a fixed element ID and fixed global function names, so pages with multiple protected forms must assign a unique ID and function name to each form or every widget writes to the first matching element.

Turnstile retries automatically. The default `retry` value is `auto`, and the default `retry-interval` is 8000 ms, so transient failures retry without visitor action; see the [widget configuration reference](https://developers.cloudflare.com/turnstile/get-started/client-side-rendering/widget-configurations/).

Cloudflare's [error code reference](https://developers.cloudflare.com/turnstile/troubleshooting/client-side-errors/error-codes/) marks retryability in its Retry column. The retryable codes are `300*`, `600*`, `110600`, `110620`, and `200500`; every other listed code is not retryable. The `110` family is therefore mixed: `110600` and `110620` are retryable, while `110100`, `110110`, and `110200` are configuration problems, as are `400020` and `400070`. Reporting every code as handled removes the console warning that can help you notice a persistent configuration problem.

Invisible mode displays no widget, checkbox, or loading indicator, so an error otherwise leaves nothing visible on the page. Sites using Invisible mode must render their own visitor-facing message from the callback.

## Content Security Policy

Turnstile requires `https://challenges.cloudflare.com` to be allowed by both the `script-src` and `frame-src` directives in your Content Security Policy. Without both directives, the script or its iframe can be blocked and token generation can fail. See Cloudflare's [Turnstile Content Security Policy reference](https://developers.cloudflare.com/turnstile/reference/content-security-policy/).

## Server-side verification (custom forms)

For any custom form POST, read the token from the `cf-turnstile-response` body parameter and verify it in your module or controller:

```php
private function verifyTurnstile(): void
{
    $plugin = \cdgrph\craftturnstilepass\Plugin::getInstance();

    if ($plugin === null || !$plugin->requiresVerification()) {
        return; // Turnstile Pass is not installed, or is switched off.
    }

    $token = \Craft::$app->getRequest()->getBodyParam('cf-turnstile-response');

    if (!is_string($token) || $token === '') {
        throw new \yii\web\BadRequestHttpException('Turnstile verification failed.');
    }

    if (!$plugin->turnstile->verify($token)['success']) {
        throw new \yii\web\BadRequestHttpException('Turnstile verification failed.');
    }
}
```

Call it from your action before you accept the submission. Keep the early return inside a dedicated method rather than in the action itself, so that a disabled plugin skips verification instead of ending the request.

Gate on `requiresVerification()`, not on `isOperational()`. The two answer different questions:

| Method | Question | Returns `false` when |
|---|---|---|
| `requiresVerification()` | Should this submission be verified? | The plugin is disabled |
| `isOperational()` | Is the plugin fully configured? | The plugin is disabled **or** a key is missing |

`isOperational()` reports configuration health, so it is the right check for rendering your own widget or for surfacing a warning. Using it to decide whether to verify would skip verification on an environment that is missing a key, which is the situation verification exists to cover.

When `requiresVerification()` is true but `isOperational()` is false, the plugin names the missing keys in Craft's logs. A missing secret key makes `verify()` reject every submission. A missing site key stops `widget()` from rendering, so a form that relies on it submits no token and is rejected — but a form that renders its own widget can still verify, because `verify()` does not read the site key.

`verify()` returns only a boolean `success` value and an `error_codes` array. The `verify()` method does not expose or validate the Siteverify response's `action` or `hostname` values. If you rely on `action` or accept submissions across multiple hostnames, call the Siteverify API directly instead of `verify()` and compare those values yourself — tokens are single-use, so a token cannot be verified a second time.

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
| Site key — forces an interactive challenge | `3x00000000000000000000FF` |
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
