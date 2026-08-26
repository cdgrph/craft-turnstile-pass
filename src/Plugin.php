<?php
declare(strict_types=1);

namespace cdgrph\craftturnstilepass;

use Craft;
use cdgrph\craftturnstilepass\models\Settings;
use cdgrph\craftturnstilepass\services\TurnstileService;
use cdgrph\craftturnstilepass\variables\TurnstilePassVariable;
use craft\base\Model;
use craft\contactform\events\SendEvent;
use craft\contactform\Mailer;
use craft\web\twig\variables\CraftVariable;
use Throwable;
use yii\base\Event;

/**
 * @property-read TurnstileService $turnstile
 * @method Settings getSettings()
 */
final class Plugin extends \craft\base\Plugin
{
    private const MISCONFIGURED_LOG_KEY = 'turnstile-pass:misconfigured:';
    private const MISCONFIGURED_LOG_TTL = 900;

    /**
     * Set once the report has been made without a working cache to throttle it.
     * Static so that the limit survives for the life of the PHP process rather
     * than resetting on every request.
     */
    private static bool $reportedWithoutCache = false;

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    /**
     * Whether a submission must be verified before it is accepted.
     *
     * Gate custom form handlers on this. It stays true when the plugin is
     * enabled but misconfigured, so a missing key cannot silently disable
     * verification.
     */
    public function requiresVerification(): bool
    {
        return $this->getSettings()->requiresVerification();
    }

    /**
     * Whether the plugin is enabled and fully configured.
     *
     * This reports configuration health. It is not a substitute for
     * requiresVerification() when deciding whether to verify a submission.
     */
    public function isOperational(): bool
    {
        return $this->getSettings()->isOperational();
    }

    public function init(): void
    {
        parent::init();

        $this->setComponents(['turnstile' => TurnstileService::class]);
        $this->registerVariable();
        $this->registerContactFormHook();
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate('turnstile-pass/_settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    private function registerVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $event): void {
                $event->sender->set('turnstilePass', TurnstilePassVariable::class);
            },
        );
    }

    private function registerContactFormHook(): void
    {
        if (!class_exists(Mailer::class)) {
            return;
        }

        Event::on(
            Mailer::class,
            Mailer::EVENT_BEFORE_SEND,
            function(SendEvent $event): void {
                if (!$this->requiresVerification()) {
                    return;
                }

                $request = Craft::$app->getRequest();
                if (!$request instanceof \craft\web\Request) {
                    return;
                }

                // Honor per-form skips only behind the opt-in; the default still verifies every submission.
                if ($this->getSettings()->allowFormSkip) {
                    $skip = $request->getBodyParam('skipTurnstile');
                    if (is_string($skip) && filter_var($skip, FILTER_VALIDATE_BOOLEAN)) {
                        return;
                    }
                }

                // Reported after the skip checks and before the token checks so it
                // covers every remaining outcome, including the case where a
                // missing site key still leaves a verifiable token.
                $this->logMisconfiguration();

                // Contact Form short-circuits spam to a silent success, so the
                // submission error is informational only (kept in case a future
                // Contact Form version surfaces errors for spam submissions).
                $reject = static function(SendEvent $event): void {
                    $event->isSpam = true;
                    $event->submission->addError(
                        'turnstile',
                        Craft::t('turnstile-pass', 'Verification failed. Please try again.'),
                    );
                };

                $token = $request->getBodyParam('cf-turnstile-response');

                // Reject non-string values (e.g. cf-turnstile-response[]=x)
                // instead of letting verify(string) raise a TypeError.
                if (!is_string($token) || $token === '') {
                    $reject($event);
                    return;
                }

                if (!$this->turnstile->verify($token)['success']) {
                    $reject($event);
                }
            },
        );
    }

    /**
     * Reports an incomplete configuration, at most once per cache window.
     *
     * Does nothing when the plugin is disabled or fully configured, so callers
     * do not have to test for that first.
     *
     * Contact Form logs spam as a warning, which points away from the real
     * cause, so this is logged at error level. It is rate limited because a
     * misconfigured site can be hit repeatedly, and Craft attaches the request
     * context, including any submitted body, to every log entry. Rate limiting
     * therefore depends on the cache component; without one, reporting the
     * problem is preferred over staying silent.
     *
     * @internal Not part of the supported API. Use isOperational() to test
     *           configuration health.
     */
    public function logMisconfiguration(): void
    {
        $settings = $this->getSettings();

        if (!$settings->isMisconfigured()) {
            return;
        }

        $missing = $settings->missingKeyNames();

        // Keying on which keys are missing means that fixing one of them
        // surfaces the remaining problem immediately, rather than after the
        // window belonging to the previous state has expired.
        $cacheKey = self::MISCONFIGURED_LOG_KEY . implode(',', $missing);

        // Null means the cache could not answer: it is absent, unusable, or it
        // threw. add() alone cannot be trusted, because it also returns false
        // when the backend cannot be written to, and an unreachable backend can
        // throw rather than return at all. A diagnostic must never be the
        // reason a page or a submission fails.
        $suppress = null;

        try {
            $cache = Craft::$app->getCache();

            if ($cache !== null) {
                if ($cache->add($cacheKey, true, self::MISCONFIGURED_LOG_TTL)) {
                    $suppress = false;
                } elseif ($cache->exists($cacheKey)) {
                    $suppress = true;
                }

                // Neither stored nor present means the write failed, so the
                // window was never opened. Leave it null.
            }
        } catch (Throwable) {
            $suppress = null;
        }

        if ($suppress === true) {
            return;
        }

        if ($suppress === null) {
            // Without a cache there is no window to enforce, and Craft attaches
            // the request context — including any submitted body — to every log
            // entry, so an unthrottled fallback would let repeated submissions
            // fill the logs with visitor data. Report once and then stay quiet
            // rather than staying silent from the start.
            if (self::$reportedWithoutCache) {
                return;
            }

            self::$reportedWithoutCache = true;
        }

        Craft::error(
            sprintf(
                'Turnstile Pass is enabled but not fully configured (missing: %s). '
                . 'The widget is not rendered and submissions can be blocked as '
                . 'spam. Check the plugin settings and this environment\'s key '
                . 'environment variables.',
                implode(' and ', $missing),
            ),
            __METHOD__,
        );
    }
}
