<?php
declare(strict_types=1);

namespace cdgrph\craftturnstilepass\variables;

use cdgrph\craftturnstilepass\Plugin;
use craft\helpers\Html;
use craft\helpers\Template;
use Twig\Markup;

final class TurnstilePassVariable
{
    public function getIsEnabled(): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        return $settings->enabled && $settings->getSiteKey() !== '';
    }

    public function getSiteKey(): string
    {
        return Plugin::getInstance()->getSettings()->getSiteKey();
    }

    public function script(array $options = []): Markup
    {
        if (!$this->getIsEnabled()) {
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
            return Template::raw('');
        }

        $class = 'cf-turnstile';
        if (isset($options['class']) && $options['class'] !== '') {
            $class .= ' ' . $options['class'];
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
}
