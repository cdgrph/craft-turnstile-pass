<?php
declare(strict_types=1);

namespace cdgrph\craftturnstilepass\models;

use craft\helpers\App;

final class Settings extends \craft\base\Model
{
    public bool $enabled = false;
    public bool $allowFormSkip = false;
    public string $siteKey = '';
    public string $secretKey = '';

    /**
     * Trimmed, because a key is tested for presence and then sent verbatim to
     * Cloudflare and rendered into the widget. Treating a padded key as present
     * while using the padded value would fail every verification with no
     * configuration error to explain it.
     */
    public function getSiteKey(): string
    {
        return trim((string)App::parseEnv($this->siteKey));
    }

    public function getSecretKey(): string
    {
        return trim((string)App::parseEnv($this->secretKey));
    }

    public function hasSiteKey(): bool
    {
        return $this->getSiteKey() !== '';
    }

    public function hasSecretKey(): bool
    {
        return $this->getSecretKey() !== '';
    }

    /**
     * Whether submissions must be verified at all.
     *
     * This deliberately ignores whether the keys are present: an incomplete
     * configuration must still fail closed rather than let submissions through.
     */
    public function requiresVerification(): bool
    {
        return $this->enabled;
    }

    /**
     * Whether the plugin is enabled and configured well enough to protect a form.
     *
     * Use this to decide whether to render a widget or to report a configuration
     * problem. Do not use it to decide whether to verify a submission.
     */
    public function isOperational(): bool
    {
        return $this->requiresVerification()
            && $this->hasSiteKey()
            && $this->hasSecretKey();
    }

    /**
     * Whether the plugin is switched on but cannot do its job.
     *
     * Being disabled is a choice; being enabled without keys is always a
     * mistake, and this is the state worth reporting to an administrator.
     */
    public function isMisconfigured(): bool
    {
        return $this->requiresVerification() && !$this->isOperational();
    }

    /**
     * @return list<string> Human-readable names of the keys that are not set.
     */
    public function missingKeyNames(): array
    {
        $missing = [];

        if (!$this->hasSiteKey()) {
            $missing[] = 'site key';
        }

        if (!$this->hasSecretKey()) {
            $missing[] = 'secret key';
        }

        return $missing;
    }

    protected function defineRules(): array
    {
        return [
            [['siteKey', 'secretKey'], 'string'],
            [['enabled', 'allowFormSkip'], 'boolean'],
        ];
    }
}
