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
     * The characters stripped from both ends of a key.
     *
     * Turnstile keys are ASCII, so nothing legitimate is lost by removing what
     * a key picks up on its way through a clipboard, an editor or a
     * spreadsheet: every control character and the space, every Unicode
     * separator, and every format character - the categories that hold the
     * next line, the non-breaking space, the zero-width characters and a byte
     * order mark.
     *
     * The general categories decide the set. Whether \s reaches beyond ASCII
     * is a PCRE build option, so leaving it in would make the PCRE version a
     * site happens to run part of the answer.
     */
    private const KEY_PADDING = '\p{Cc}\x20\p{Z}\p{Cf}';

    /**
     * Removes that padding from both ends of a key.
     *
     * A key is tested for presence and then sent verbatim to Cloudflare and
     * rendered into the widget. Treating a padded key as present while using
     * the padded value would fail every verification with no configuration
     * error to explain it. trim() removes a fixed set of ASCII bytes, so it
     * leaves that gap open for the characters most likely to arrive by paste.
     *
     * Every presence test and every diagnostic reads a key through here, so
     * isOperational(), missingKeyNames(), the configuration error and the
     * control panel warning all agree on what counts as empty.
     */
    private static function trimKey(string $key): string
    {
        $pattern = '/^[' . self::KEY_PADDING . ']+|[' . self::KEY_PADDING . ']+$/u';

        // A subject that is not valid UTF-8 makes the /u pattern fail and
        // return null; fall back to ASCII trimming rather than to the raw
        // value, so the result is never less trimmed than it used to be.
        return preg_replace($pattern, '', $key) ?? trim($key);
    }

    public function getSiteKey(): string
    {
        return self::trimKey((string)App::parseEnv($this->siteKey));
    }

    public function getSecretKey(): string
    {
        return self::trimKey((string)App::parseEnv($this->secretKey));
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
