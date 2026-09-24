<?php
declare(strict_types=1);

namespace cdgrph\craftturnstilepass\variables;

use cdgrph\craftturnstilepass\Plugin;
use craft\helpers\Html;
use craft\helpers\Template;
use Twig\Markup;

final class TurnstilePassVariable
{
    private const DEFAULT_ERROR_CALLBACK = 'turnstilePassOnError';

    /**
     * Whether the plugin can render a working widget.
     *
     * Kept under the original Twig-facing name for backwards compatibility.
     */
    public function getIsEnabled(): bool
    {
        return Plugin::getInstance()->isOperational();
    }

    public function getSiteKey(): string
    {
        return Plugin::getInstance()->getSettings()->getSiteKey();
    }

    public function script(array $options = []): Markup
    {
        if (!$this->getIsEnabled()) {
            $this->reportIfMisconfigured();

            return Template::raw('');
        }

        // The callback precedes the API tag, so it exists before any widget runs.
        // It shares the nonce and data-* attributes (such as data-cfasync), so
        // a CSP or script loader treats both tags alike and keeps their order.
        $callbackOptions = array_filter(
            $options,
            static fn($value, $name) => $name === 'nonce' || str_starts_with((string)$name, 'data-'),
            ARRAY_FILTER_USE_BOTH,
        );

        return Template::raw(
            Html::script(self::defaultErrorCallbackScript(), $callbackOptions)
            . Html::tag('script', '', array_merge([
                'src' => 'https://challenges.cloudflare.com/turnstile/v0/api.js',
                'async' => true,
                'defer' => true,
            ], $options))
        );
    }

    public function widget(array $options = []): Markup
    {
        if (!$this->getIsEnabled()) {
            $this->reportIfMisconfigured();

            return Template::raw('');
        }

        $class = 'cf-turnstile';
        if (isset($options['class'])) {
            $optionClass = is_array($options['class'])
                ? implode(' ', $options['class'])
                : $options['class'];
            if ($optionClass !== '') {
                $class .= ' ' . $optionClass;
            }
        }
        unset($options['class']);

        $attributes = [];
        foreach ($options as $name => $value) {
            $name = (string)$name;
            if (!str_starts_with($name, 'data-')) {
                $name = 'data-' . $name;
            }
            $attributes[$name] = $value;
        }

        $attributes['class'] = $class;
        $attributes['data-sitekey'] = $this->getSiteKey();

        // A caller-supplied callback name wins, and `false` opts out of any
        // callback. With retries disabled a retryable failure is final, so the
        // default, which ignores those codes, would hide it.
        $errorCallback = $attributes['data-error-callback'] ?? null;
        if ($errorCallback !== false && (!is_string($errorCallback) || $errorCallback === '')) {
            unset($attributes['data-error-callback']);
            if (($attributes['data-retry'] ?? null) !== 'never') {
                $attributes['data-error-callback'] = self::DEFAULT_ERROR_CALLBACK;
            }
        }

        return Template::raw(Html::tag('div', '', $attributes));
    }

    /**
     * Turnstile throws when a challenge fails and no error callback is set, so
     * every transient failure surfaced as an uncaught exception. Retryable codes
     * are left to Turnstile's automatic retry. Configuration codes are rethrown
     * outside Turnstile, once per code and page, so error monitoring still sees
     * them. Any other code is returned falsy, which makes Turnstile log a
     * console warning instead of reporting a visitor-side failure as an error.
     */
    private static function defaultErrorCallbackScript(): string
    {
        return <<<'JS'
window.turnstilePassOnError = (function () {
    var retryable = ['110600', '110620', '200500'];
    var configuration = ['110100', '110110', '110200', '400020', '400070'];
    var reported = {};
    return function (code) {
        var errorCode = String(code);
        if (/^(300|600)/.test(errorCode) || retryable.indexOf(errorCode) !== -1) {
            return true;
        }
        if (configuration.indexOf(errorCode) === -1) {
            return false;
        }
        if (!reported[errorCode]) {
            reported[errorCode] = true;
            setTimeout(function () {
                throw new Error('Turnstile error ' + errorCode);
            });
        }
        return true;
    };
})();
JS;
    }

    /**
     * Rendering nothing is the first visible symptom of a missing key, and it
     * happens on every page view rather than only on a submission. Reporting
     * here also covers sites that do not use the Contact Form plugin, where the
     * submission-time diagnostic never runs.
     */
    private function reportIfMisconfigured(): void
    {
        Plugin::getInstance()->logMisconfiguration();
    }
}
