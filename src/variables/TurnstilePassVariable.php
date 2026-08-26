<?php
declare(strict_types=1);

namespace cdgrph\craftturnstilepass\variables;

use cdgrph\craftturnstilepass\Plugin;
use craft\helpers\Html;
use craft\helpers\Template;
use Twig\Markup;

final class TurnstilePassVariable
{
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

        return Template::raw(Html::tag('script', '', array_merge([
            'src' => 'https://challenges.cloudflare.com/turnstile/v0/api.js',
            'async' => true,
            'defer' => true,
        ], $options)));
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

        return Template::raw(Html::tag('div', '', $attributes));
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
