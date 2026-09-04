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
use craft\contactform\models\Submission;
use craft\web\Request;
use craft\web\twig\variables\CraftVariable;
use Throwable;
use WeakMap;
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
     * Set once the report has been made without a usable cache. The plugin
     * instance lives for one request, which is the only scope this can bound.
     */
    private bool $misconfigurationReported = false;

    /**
     * The verdict each submission has already received, against the token it
     * was judged on. The key is both, which is everything the verdict depends
     * on, so a reused submission or a reused token cannot inherit an answer
     * that was not about it. Created on first use because a property cannot be
     * initialised with an object.
     *
     * @var WeakMap<object, array<string, bool>>|null
     */
    private ?WeakMap $verdicts = null;

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
        $this->registerContactFormHooks();
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

    private function registerContactFormHooks(): void
    {
        if (!class_exists(Mailer::class) || !class_exists(Submission::class)) {
            return;
        }

        // Contact Form validates the submission before it fires
        // EVENT_BEFORE_SEND, and send() returns false only when validation
        // fails. That is the one outcome its controller turns into a visible
        // failure, so a rejection has to be recorded here to reach the visitor.
        Event::on(
            Submission::class,
            Model::EVENT_AFTER_VALIDATE,
            function(Event $event): void {
                $submission = $event->sender;

                // Not defensive: a static Event::trigger() on the class leaves the
                // sender null, and addError() on null is fatal.
                if (!$submission instanceof Submission) {
                    return;
                }

                // A submission that already failed its own validation rules is
                // rejected whatever this adds, and the visitor will send it
                // again. Spending the token on it would consume a token that
                // the retry still needs, because a form that is not re-rendered
                // resubmits the one it already holds.
                if ($submission->hasErrors()) {
                    return;
                }

                if ($this->turnstileVerdict($submission) === false) {
                    $submission->addError('turnstile', $this->rejectionMessage());
                }
            },
        );

        // A second layer for callers that send without validating, such as
        // send($submission, false), which never reach the check above. Contact
        // Form short-circuits spam to a silent success, so on its own this
        // stops the mail without telling the visitor anything.
        Event::on(
            Mailer::class,
            Mailer::EVENT_BEFORE_SEND,
            function(SendEvent $event): void {
                // The event's submission is untyped, so it is only usable as a
                // memo key, and only as an error target, when it is what
                // Contact Form puts there.
                $submission = $event->submission;

                if ($this->turnstileVerdict(is_object($submission) ? $submission : null) !== false) {
                    return;
                }

                $event->isSpam = true;

                if ($submission instanceof Submission && !$submission->hasErrors('turnstile')) {
                    $submission->addError('turnstile', $this->rejectionMessage());
                }
            },
        );
    }

    private function rejectionMessage(): string
    {
        return Craft::t('turnstile-pass', 'Verification failed. Please try again.');
    }

    /**
     * Whether the current request carries a token Turnstile accepts.
     *
     * Returns null when verification does not apply to the request, so that
     * callers leave the submission alone rather than reading "not checked" as
     * either a pass or a failure.
     */
    private function turnstileVerdict(?object $submission): ?bool
    {
        if (!$this->requiresVerification()) {
            return null;
        }

        $request = Craft::$app->getRequest();
        if (!$request instanceof Request) {
            return null;
        }

        // Honor per-form skips only behind the opt-in; the default still verifies every submission.
        if ($this->getSettings()->allowFormSkip) {
            $skip = $request->getBodyParam('skipTurnstile');
            if (is_string($skip) && filter_var($skip, FILTER_VALIDATE_BOOLEAN)) {
                return null;
            }
        }

        // Reported after the skip checks and before the token checks so it
        // covers every remaining outcome, including the case where a
        // missing site key still leaves a verifiable token.
        $this->logMisconfiguration();

        $token = $request->getBodyParam('cf-turnstile-response');

        // Reject non-string values (e.g. cf-turnstile-response[]=x)
        // instead of letting verify(string) raise a TypeError.
        if (!is_string($token) || $token === '') {
            return false;
        }

        return $this->verifyOnce($submission, $token);
    }

    /**
     * Verifies a submission's token at most once.
     *
     * Turnstile tokens are single use: verifying the same token again returns
     * timeout-or-duplicate. A submission that passes validation reaches the
     * send hook on the same request, so without a memo the second look would
     * reject a submission the first one accepted.
     *
     * The verdict is held against the submission and the token together, so
     * neither a second submission nor a second token can inherit an answer
     * that was not about it. A submission asked again is asked of Cloudflare
     * again, which then refuses the token it has already spent, and that is
     * what keeps one token to one submission. A caller that hands over no
     * submission gets a verdict without a memo rather than a shared one.
     */
    private function verifyOnce(?object $submission, string $token): bool
    {
        if ($submission === null) {
            return (bool)$this->turnstile->verify($token)['success'];
        }

        $this->verdicts ??= new WeakMap();

        $verdicts = $this->verdicts[$submission] ?? [];

        if (!array_key_exists($token, $verdicts)) {
            $verdicts[$token] = (bool)$this->turnstile->verify($token)['success'];
            $this->verdicts[$submission] = $verdicts;
        }

        return $verdicts[$token];
    }

    /**
     * Reports an incomplete configuration, at most once per cache window.
     *
     * Does nothing when the plugin is disabled or fully configured, so callers
     * do not have to test for that first.
     *
     * Contact Form reports a rejected submission below the level production
     * logging keeps, and the spam warning it still writes for a caller that
     * skips validation points away from the real cause, so this is logged at
     * error level. It is rate limited because a
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
            // The cache could not hold the window, so the only scope left is
            // this request. Reporting still happens — reaching this path takes
            // an incomplete configuration and a broken cache at once, and such
            // a site needs to be noticed — but the widget, the script tag, and
            // the Contact Form hook report it between them once rather than
            // three times. Later requests are not bounded: PHP keeps no
            // userland state between them.
            if ($this->misconfigurationReported) {
                return;
            }

            $this->misconfigurationReported = true;
        }

        Craft::error(
            sprintf(
                'Turnstile Pass is enabled but not fully configured (missing: %s). '
                . 'The widget is not rendered and submissions can be '
                . 'rejected. Check the plugin settings and this environment\'s key '
                . 'environment variables.',
                implode(' and ', $missing),
            ),
            __METHOD__,
        );
    }
}
