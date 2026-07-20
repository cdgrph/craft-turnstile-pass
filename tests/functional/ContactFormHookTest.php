<?php
declare(strict_types=1);

namespace cdgrph\craftturnstilepass\tests\functional;

use cdgrph\craftturnstilepass\Plugin;
use cdgrph\craftturnstilepass\services\TurnstileService;
use craft\config\GeneralConfig;
use craft\contactform\events\SendEvent;
use craft\contactform\Mailer;
use craft\contactform\models\Submission;
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

final class ContactFormHookTest extends TestCase
{
    private Plugin $plugin;

    protected function setUp(): void
    {
        $this->bootApp();
        $this->plugin = $this->createPlugin();
    }

    protected function tearDown(): void
    {
        Event::off(CraftVariable::class, CraftVariable::EVENT_INIT);
        Event::off(Mailer::class, Mailer::EVENT_BEFORE_SEND);
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
        $settings->secretKey = 'configured-secret';
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
