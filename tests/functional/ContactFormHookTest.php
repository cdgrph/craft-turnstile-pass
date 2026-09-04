<?php
declare(strict_types=1);

namespace cdgrph\craftturnstilepass\tests\functional;

use cdgrph\craftturnstilepass\Plugin;
use cdgrph\craftturnstilepass\services\TurnstileService;
use craft\base\Model;
use craft\config\GeneralConfig;
use craft\contactform\events\SendEvent;
use craft\contactform\Mailer;
use craft\contactform\models\Submission;
use craft\i18n\PhpMessageSource;
use craft\models\Site;
use craft\services\Config;
use craft\services\Sites;
use craft\web\Request;
use craft\web\View;
use craft\web\twig\variables\CraftVariable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use yii\base\Event;
use yii\caching\ArrayCache;
use yii\log\Logger;

final class ContactFormHookTest extends TestCase
{
    private Plugin $plugin;

    protected function setUp(): void
    {
        $this->bootApp();
        \Yii::getLogger()->messages = [];
        $this->plugin = $this->createPlugin();
    }

    protected function tearDown(): void
    {
        Event::off(CraftVariable::class, CraftVariable::EVENT_INIT);
        Event::off(Mailer::class, Mailer::EVENT_BEFORE_SEND);
        Event::off(Submission::class, Model::EVENT_AFTER_VALIDATE);
        Event::off(View::class, View::EVENT_REGISTER_CP_TEMPLATE_ROOTS);
        Plugin::setInstance(null);
        \Yii::$app = null;
    }

    public function testMissingTokenMarksSubmissionAsSpam(): void
    {
        $this->enablePlugin();
        $this->setRequestBodyParams([]);
        [$submission, $event] = $this->createSendEvent();

        Event::trigger(Mailer::class, Mailer::EVENT_BEFORE_SEND, $event);

        self::assertTrue($event->isSpam);
        self::assertTrue($submission->hasErrors('turnstile'));
    }

    public function testSkipParamBypassesVerificationWhenAllowed(): void
    {
        $this->enablePlugin();
        $this->plugin->getSettings()->allowFormSkip = true;
        $this->setRequestBodyParams([
            'skipTurnstile' => 'true',
        ]);
        [$submission, $event] = $this->createSendEvent();

        Event::trigger(Mailer::class, Mailer::EVENT_BEFORE_SEND, $event);

        self::assertFalse($event->isSpam);
        self::assertSame([], $submission->getErrors());
    }

    public function testTruthyVariantSkipValueBypassesVerificationWhenAllowed(): void
    {
        $this->enablePlugin();
        $this->plugin->getSettings()->allowFormSkip = true;
        $this->setRequestBodyParams([
            'skipTurnstile' => '1',
        ]);
        [, $event] = $this->createSendEvent();

        Event::trigger(Mailer::class, Mailer::EVENT_BEFORE_SEND, $event);

        self::assertFalse($event->isSpam);
    }

    public function testSkipParamCoexistsWithMessageBody(): void
    {
        $this->enablePlugin();
        $this->plugin->getSettings()->allowFormSkip = true;
        $this->setRequestBodyParams([
            'skipTurnstile' => 'true',
            'message' => 'Hello world',
        ]);
        [, $event] = $this->createSendEvent();

        Event::trigger(Mailer::class, Mailer::EVENT_BEFORE_SEND, $event);

        self::assertFalse($event->isSpam);
    }

    public function testSkipParamIsIgnoredWhenNotAllowed(): void
    {
        $this->enablePlugin();
        $this->setRequestBodyParams([
            'skipTurnstile' => 'true',
        ]);
        [$submission, $event] = $this->createSendEvent();

        Event::trigger(Mailer::class, Mailer::EVENT_BEFORE_SEND, $event);

        self::assertTrue($event->isSpam);
        self::assertTrue($submission->hasErrors('turnstile'));
    }

    public function testFalseSkipValueDoesNotBypassVerification(): void
    {
        $this->enablePlugin();
        $this->plugin->getSettings()->allowFormSkip = true;
        $this->setRequestBodyParams([
            'skipTurnstile' => 'false',
        ]);
        [, $event] = $this->createSendEvent();

        Event::trigger(Mailer::class, Mailer::EVENT_BEFORE_SEND, $event);

        self::assertTrue($event->isSpam);
    }

    public function testNonStringSkipValueDoesNotBypassVerification(): void
    {
        $this->enablePlugin();
        $this->plugin->getSettings()->allowFormSkip = true;
        $this->setRequestBodyParams([
            'skipTurnstile' => ['true'],
        ]);
        [, $event] = $this->createSendEvent();

        Event::trigger(Mailer::class, Mailer::EVENT_BEFORE_SEND, $event);

        self::assertTrue($event->isSpam);
    }

    public function testFailedVerificationMarksSubmissionAsSpam(): void
    {
        $this->enablePlugin();
        $this->setRequestBodyParams([
            'cf-turnstile-response' => 'bad-token',
        ]);
        $service = $this->plugin->get('turnstile');
        self::assertInstanceOf(TurnstileService::class, $service);
        $service->setClient(new Client([
            'handler' => HandlerStack::create(new MockHandler([
                new Response(200, [], '{"success":false,"error-codes":["invalid-input-response"]}'),
            ])),
        ]));
        [, $event] = $this->createSendEvent();

        Event::trigger(Mailer::class, Mailer::EVENT_BEFORE_SEND, $event);

        self::assertTrue($event->isSpam);
    }

    public function testNonStringTokenMarksSubmissionAsSpam(): void
    {
        $this->enablePlugin();
        $this->setRequestBodyParams([
            'cf-turnstile-response' => ['not-a-string'],
        ]);
        [$submission, $event] = $this->createSendEvent();

        Event::trigger(Mailer::class, Mailer::EVENT_BEFORE_SEND, $event);

        self::assertTrue($event->isSpam);
        self::assertTrue($submission->hasErrors('turnstile'));
    }

    public function testDisabledPluginLeavesSubmissionUntouched(): void
    {
        $this->plugin->getSettings()->enabled = false;
        $this->setRequestBodyParams([]);
        [$submission, $event] = $this->createSendEvent();

        Event::trigger(Mailer::class, Mailer::EVENT_BEFORE_SEND, $event);

        self::assertFalse($event->isSpam);
        self::assertSame([], $submission->getErrors());
    }

    public function testConsoleRequestIsIgnored(): void
    {
        $this->enablePlugin();
        [$submission, $event] = $this->createSendEvent();

        Event::trigger(Mailer::class, Mailer::EVENT_BEFORE_SEND, $event);

        self::assertFalse($event->isSpam);
        self::assertSame([], $submission->getErrors());
    }

    public function testMissingKeysStillMarkSubmissionAsSpam(): void
    {
        $this->enablePluginWithoutKeys();
        $this->setRequestBodyParams([]);
        [$submission, $event] = $this->createSendEvent();

        Event::trigger(Mailer::class, Mailer::EVENT_BEFORE_SEND, $event);

        self::assertTrue($event->isSpam);
        self::assertTrue($submission->hasErrors('turnstile'));
    }

    public function testMisconfigurationIsLoggedOncePerCacheWindow(): void
    {
        $this->enablePluginWithoutKeys();
        $this->setRequestBodyParams([]);

        [, $first] = $this->createSendEvent();
        Event::trigger(Mailer::class, Mailer::EVENT_BEFORE_SEND, $first);
        self::assertSame(1, $this->misconfigurationLogCount());

        [, $second] = $this->createSendEvent();
        Event::trigger(Mailer::class, Mailer::EVENT_BEFORE_SEND, $second);
        self::assertSame(1, $this->misconfigurationLogCount());
    }

    public function testMisconfigurationIsNotLoggedWhenFullyConfigured(): void
    {
        $this->enablePlugin();
        $this->setRequestBodyParams([]);
        [, $event] = $this->createSendEvent();

        Event::trigger(Mailer::class, Mailer::EVENT_BEFORE_SEND, $event);

        self::assertTrue($event->isSpam);
        self::assertSame(0, $this->misconfigurationLogCount());
    }

    public function testMisconfigurationIsNotLoggedWhenPluginIsDisabled(): void
    {
        $this->plugin->getSettings()->enabled = false;
        $this->setRequestBodyParams([]);
        [, $event] = $this->createSendEvent();

        Event::trigger(Mailer::class, Mailer::EVENT_BEFORE_SEND, $event);

        self::assertSame(0, $this->misconfigurationLogCount());
    }

    public function testMisconfigurationIsNotLoggedForConsoleRequest(): void
    {
        $this->enablePluginWithoutKeys();
        [, $event] = $this->createSendEvent();

        Event::trigger(Mailer::class, Mailer::EVENT_BEFORE_SEND, $event);

        self::assertFalse($event->isSpam);
        self::assertSame(0, $this->misconfigurationLogCount());
    }

    public function testMisconfigurationIsNotLoggedForAllowedSkip(): void
    {
        $this->enablePluginWithoutKeys();
        $this->plugin->getSettings()->allowFormSkip = true;
        $this->setRequestBodyParams([
            'skipTurnstile' => 'true',
        ]);
        [, $event] = $this->createSendEvent();

        Event::trigger(Mailer::class, Mailer::EVENT_BEFORE_SEND, $event);

        self::assertFalse($event->isSpam);
        self::assertSame(0, $this->misconfigurationLogCount());
    }

    /**
     * A missing site key does not block a submission on its own: verify() never
     * reads the site key, so a template that renders its own widget can still
     * produce a token that verifies. The diagnostic must still be logged.
     */
    public function testMissingSiteKeyStillAllowsSuccessfulVerification(): void
    {
        $this->enablePluginWithoutKeys(secretKey: true);
        $this->setRequestBodyParams([
            'cf-turnstile-response' => 'good-token',
        ]);
        $service = $this->plugin->get('turnstile');
        self::assertInstanceOf(TurnstileService::class, $service);
        $service->setClient(new Client([
            'handler' => HandlerStack::create(new MockHandler([
                new Response(200, [], '{"success":true}'),
            ])),
        ]));
        [, $event] = $this->createSendEvent();

        Event::trigger(Mailer::class, Mailer::EVENT_BEFORE_SEND, $event);

        self::assertFalse($event->isSpam);
        self::assertSame(1, $this->misconfigurationLogCount());
    }

    public function testMisconfigurationIsLoggedAtErrorLevel(): void
    {
        $this->enablePluginWithoutKeys();
        $this->setRequestBodyParams([]);
        [, $event] = $this->createSendEvent();

        Event::trigger(Mailer::class, Mailer::EVENT_BEFORE_SEND, $event);

        self::assertSame(1, $this->misconfigurationLogCount(Logger::LEVEL_ERROR));
        self::assertSame(0, $this->misconfigurationLogCount(Logger::LEVEL_WARNING));
    }

    public function testMisconfigurationNamesTheMissingKeys(): void
    {
        $this->enablePluginWithoutKeys(siteKey: true);
        $this->setRequestBodyParams([]);
        [, $event] = $this->createSendEvent();

        Event::trigger(Mailer::class, Mailer::EVENT_BEFORE_SEND, $event);

        $messages = $this->misconfigurationLogMessages();
        self::assertCount(1, $messages);
        self::assertStringContainsString('secret key', $messages[0]);
        self::assertStringNotContainsString('site key', $messages[0]);
    }

    /**
     * Fixing one key changes which keys are missing, so the remaining problem
     * must surface immediately rather than waiting out the previous window.
     */
    public function testFixingOneKeyLogsAgainWithinTheSameWindow(): void
    {
        $this->enablePluginWithoutKeys();
        $this->setRequestBodyParams([]);

        [, $first] = $this->createSendEvent();
        Event::trigger(Mailer::class, Mailer::EVENT_BEFORE_SEND, $first);
        self::assertSame(1, $this->misconfigurationLogCount());

        $this->plugin->getSettings()->siteKey = 'configured-site';

        [, $second] = $this->createSendEvent();
        Event::trigger(Mailer::class, Mailer::EVENT_BEFORE_SEND, $second);
        self::assertSame(2, $this->misconfigurationLogCount());
    }

    /**
     * A cache that cannot be written to returns false from add() just like a
     * cache that already holds the key. Suppressing on that would silence the
     * report exactly when the site is already misconfigured.
     */
    public function testBrokenCacheStillReports(): void
    {
        \Yii::$app->set('cache', new class extends ArrayCache {
            protected function addValue($key, $value, $duration): bool
            {
                return false; // Write failure, not a duplicate key.
            }

            public function exists($key): bool
            {
                return false;
            }
        });

        $this->enablePluginWithoutKeys();
        $this->setRequestBodyParams([]);

        [, $first] = $this->createSendEvent();
        Event::trigger(Mailer::class, Mailer::EVENT_BEFORE_SEND, $first);
        self::assertSame(1, $this->misconfigurationLogCount());
    }

    /**
     * The plugin instance lives for one request, so a request that would report
     * the same problem from several places reports it once. This says nothing
     * about later requests, which the cache is responsible for.
     */
    public function testMisconfigurationIsReportedOncePerPluginInstance(): void
    {
        \Yii::$app->set('cache', null);
        $this->enablePluginWithoutKeys();
        $this->setRequestBodyParams([]);

        foreach (range(1, 3) as $ignored) {
            [, $event] = $this->createSendEvent();
            Event::trigger(Mailer::class, Mailer::EVENT_BEFORE_SEND, $event);
        }

        self::assertSame(1, $this->misconfigurationLogCount());
    }

    public function testMissingCacheComponentStillReports(): void
    {
        \Yii::$app->set('cache', null);

        $this->enablePluginWithoutKeys();
        $this->setRequestBodyParams([]);
        [, $event] = $this->createSendEvent();

        Event::trigger(Mailer::class, Mailer::EVENT_BEFORE_SEND, $event);

        self::assertSame(1, $this->misconfigurationLogCount());
    }

    /**
     * An unreachable cache backend throws rather than returning false. A
     * diagnostic must never be the reason a page or a submission fails.
     */
    public function testThrowingCacheDoesNotBreakTheSubmission(): void
    {
        \Yii::$app->set('cache', new class extends ArrayCache {
            protected function addValue($key, $value, $duration): bool
            {
                throw new \RuntimeException('Cache backend is unreachable.');
            }
        });

        $this->enablePluginWithoutKeys();
        $this->setRequestBodyParams([]);
        [$submission, $event] = $this->createSendEvent();

        Event::trigger(Mailer::class, Mailer::EVENT_BEFORE_SEND, $event);

        self::assertSame(1, $this->misconfigurationLogCount());
        self::assertTrue($event->isSpam);
        self::assertTrue($submission->hasErrors('turnstile'));
    }

    /**
     * Contact Form validates the submission before it fires EVENT_BEFORE_SEND,
     * and only a failed validation makes send() return false, which is the one
     * path the controller turns into a visible failure. Rejecting here is what
     * reaches the visitor.
     */
    public function testMissingTokenFailsValidation(): void
    {
        $this->enablePlugin();
        $this->setRequestBodyParams([]);
        $submission = $this->createValidSubmission();

        self::assertFalse($submission->validate());
        self::assertTrue($submission->hasErrors('turnstile'));
    }

    public function testNonStringTokenFailsValidation(): void
    {
        $this->enablePlugin();
        $this->setRequestBodyParams([
            'cf-turnstile-response' => ['not-a-string'],
        ]);
        $submission = $this->createValidSubmission();

        self::assertFalse($submission->validate());
        self::assertTrue($submission->hasErrors('turnstile'));
    }

    public function testFailedVerificationFailsValidation(): void
    {
        $this->enablePlugin();
        $this->setRequestBodyParams([
            'cf-turnstile-response' => 'bad-token',
        ]);
        $this->mockVerifyResponses('{"success":false,"error-codes":["invalid-input-response"]}');
        $submission = $this->createValidSubmission();

        self::assertFalse($submission->validate());
        self::assertTrue($submission->hasErrors('turnstile'));
    }

    public function testSuccessfulVerificationLeavesValidationPassing(): void
    {
        $this->enablePlugin();
        $this->setRequestBodyParams([
            'cf-turnstile-response' => 'good-token',
        ]);
        $this->mockVerifyResponses('{"success":true}');
        $submission = $this->createValidSubmission();

        self::assertTrue($submission->validate());
        self::assertSame([], $submission->getErrors());
    }

    /**
     * Turnstile tokens are single use. Verifying the same token again returns
     * timeout-or-duplicate, which would reject a submission that had already
     * passed. The mock holds one response, so a second call fails the test.
     */
    public function testTokenIsVerifiedOncePerRequest(): void
    {
        $this->enablePlugin();
        $this->setRequestBodyParams([
            'cf-turnstile-response' => 'good-token',
        ]);
        $this->mockVerifyResponses('{"success":true}');
        $submission = $this->createValidSubmission();

        self::assertTrue($submission->validate());

        $event = new SendEvent(['submission' => $submission]);
        Event::trigger(Mailer::class, Mailer::EVENT_BEFORE_SEND, $event);

        self::assertFalse($event->isSpam);
    }

    public function testDisabledPluginLeavesValidationUntouched(): void
    {
        $this->plugin->getSettings()->enabled = false;
        $this->setRequestBodyParams([]);
        $submission = $this->createValidSubmission();

        self::assertTrue($submission->validate());
        self::assertSame([], $submission->getErrors());
    }

    public function testAllowedSkipLeavesValidationUntouched(): void
    {
        $this->enablePlugin();
        $this->plugin->getSettings()->allowFormSkip = true;
        $this->setRequestBodyParams([
            'skipTurnstile' => 'true',
        ]);
        $submission = $this->createValidSubmission();

        self::assertTrue($submission->validate());
        self::assertSame([], $submission->getErrors());
    }

    public function testConsoleRequestLeavesValidationUntouched(): void
    {
        $this->enablePlugin();
        $submission = $this->createValidSubmission();

        self::assertTrue($submission->validate());
        self::assertSame([], $submission->getErrors());
    }

    /**
     * A submission that Contact Form's own rules already reject is not
     * verified, so the token it carries survives the retry. The mock holds no
     * responses, so any verification attempt fails this test.
     */
    public function testSubmissionFailingItsOwnRulesIsNotVerified(): void
    {
        $this->enablePlugin();
        $this->registerContactFormTranslations();
        $this->setRequestBodyParams([
            'cf-turnstile-response' => 'good-token',
        ]);
        $this->mockVerifyResponses();
        $submission = new Submission();

        self::assertFalse($submission->validate());
        self::assertTrue($submission->hasErrors('fromEmail'));
        self::assertFalse($submission->hasErrors('turnstile'));
    }

    /**
     * A caller that validates and then sends without validating again reaches
     * the send hook with the error already recorded, so it is not repeated.
     */
    public function testSendHookDoesNotRepeatAnExistingError(): void
    {
        $this->enablePlugin();
        $this->setRequestBodyParams([]);
        $submission = $this->createValidSubmission();

        self::assertFalse($submission->validate());

        $event = new SendEvent(['submission' => $submission]);
        Event::trigger(Mailer::class, Mailer::EVENT_BEFORE_SEND, $event);

        self::assertTrue($event->isSpam);
        self::assertCount(1, $submission->getErrors('turnstile'));
    }

    /**
     * The verdict is held per token rather than per request, so a second token
     * in the same request is judged on its own answer instead of inheriting
     * the first one's.
     */
    public function testASecondTokenInTheSameRequestIsJudgedOnItsOwn(): void
    {
        $this->enablePlugin();
        $this->mockVerifyResponses(
            '{"success":true}',
            '{"success":false,"error-codes":["invalid-input-response"]}',
        );

        $this->setRequestBodyParams(['cf-turnstile-response' => 'first-token']);
        self::assertTrue($this->createValidSubmission()->validate());

        $this->setRequestBodyParams(['cf-turnstile-response' => 'second-token']);
        $second = $this->createValidSubmission();

        self::assertFalse($second->validate());
        self::assertTrue($second->hasErrors('turnstile'));
    }

    private function bootApp(): void
    {
        // Craft's PhpMessageSource resolves @translations for site-level
        // overrides; the bare console app never defines it.
        \Yii::setAlias('@translations', \dirname(__DIR__) . '/_translations');

        $config = new class extends Config {
            public function getGeneral(): GeneralConfig
            {
                return new GeneralConfig();
            }
        };
        $sites = new class extends Sites {
            public function init(): void
            {
            }

            public function getHasCurrentSite(): bool
            {
                return true;
            }

            public function getCurrentSite(): Site
            {
                return new Site(['baseUrl' => 'http://localhost']);
            }

            public function setCurrentSite(mixed $site): void
            {
            }
        };

        new class([
            'id' => 'turnstile-pass-contact-form-test',
            'basePath' => \dirname(__DIR__, 2),
            'components' => [
                'config' => $config,
                'sites' => $sites,
                'cache' => ArrayCache::class,
            ],
        ]) extends \yii\console\Application {
            public function getConfig(): Config
            {
                return $this->get('config');
            }
        };
    }

    private function createPlugin(): Plugin
    {
        return new Plugin('turnstile-pass', \Yii::$app, [
            'basePath' => \dirname(__DIR__, 2) . '/src',
        ]);
    }

    private function enablePlugin(): void
    {
        $settings = $this->plugin->getSettings();
        $settings->enabled = true;
        $settings->siteKey = 'configured-site';
        $settings->secretKey = 'configured-secret';
    }

    /**
     * Enables the plugin while leaving one or both keys unset, which is what an
     * environment with a missing key environment variable looks like at runtime.
     */
    private function enablePluginWithoutKeys(bool $siteKey = false, bool $secretKey = false): void
    {
        $settings = $this->plugin->getSettings();
        $settings->enabled = true;
        $settings->siteKey = $siteKey ? 'configured-site' : '';
        $settings->secretKey = $secretKey ? 'configured-secret' : '';
    }

    /**
     * Counts diagnostics by category and level rather than by message text, so
     * that changing the wording cannot quietly turn these assertions into no-ops.
     */
    private function misconfigurationLogCount(int $level = Logger::LEVEL_ERROR): int
    {
        $count = 0;

        foreach (\Yii::getLogger()->messages as $message) {
            if ($message[2] === Plugin::class . '::logMisconfiguration' && $message[1] === $level) {
                $count++;
            }
        }

        return $count;
    }

    private function misconfigurationLogMessages(): array
    {
        $messages = [];

        foreach (\Yii::getLogger()->messages as $message) {
            if ($message[2] === Plugin::class . '::logMisconfiguration') {
                $messages[] = (string)$message[0];
            }
        }

        return $messages;
    }

    /**
     * @param array<string, mixed> $bodyParams
     */
    private function setRequestBodyParams(array $bodyParams): void
    {
        $request = new Request();
        $request->setBodyParams($bodyParams);
        \Yii::$app->set('request', $request);
    }

    /**
     * A submission that Contact Form's own rules accept, so that a failed
     * validation can only come from the turnstile check.
     */
    private function createValidSubmission(): Submission
    {
        $submission = new Submission();
        $submission->fromEmail = 'visitor@example.com';
        $submission->message = 'Hello';

        return $submission;
    }

    /**
     * Contact Form's attribute labels translate through its own category,
     * which Craft registers when the plugin loads. The bare test app has no
     * plugins, so the category is registered the same way here.
     */
    private function registerContactFormTranslations(): void
    {
        \Yii::$app->getI18n()->translations['contact-form'] = [
            'class' => PhpMessageSource::class,
            'sourceLanguage' => 'en',
            'basePath' => \dirname(__DIR__, 2) . '/vendor/craftcms/contact-form/src/translations',
            'forceTranslation' => true,
            'allowOverrides' => true,
        ];
    }

    private function mockVerifyResponses(string ...$bodies): void
    {
        $service = $this->plugin->get('turnstile');
        self::assertInstanceOf(TurnstileService::class, $service);
        $service->setClient(new Client([
            'handler' => HandlerStack::create(new MockHandler(
                array_map(static fn(string $body): Response => new Response(200, [], $body), $bodies),
            )),
        ]));
    }

    /**
     * @return array{Submission, SendEvent}
     */
    private function createSendEvent(): array
    {
        $submission = new Submission();

        return [
            $submission,
            new SendEvent(['submission' => $submission]),
        ];
    }
}
