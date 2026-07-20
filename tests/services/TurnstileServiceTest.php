<?php
declare(strict_types=1);

namespace cdgrph\craftturnstilepass\tests\services;

use cdgrph\craftturnstilepass\Plugin;
use cdgrph\craftturnstilepass\services\TurnstileService;
use craft\contactform\Mailer;
use craft\web\View;
use craft\web\twig\variables\CraftVariable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use yii\base\Event;

final class TurnstileServiceTest extends TestCase
{
    private Plugin $plugin;
    private TurnstileService $service;

    protected function setUp(): void
    {
        $this->bootApp();
        $this->plugin = $this->createPlugin();

        $service = $this->plugin->get('turnstile');
        self::assertInstanceOf(TurnstileService::class, $service);
        $this->service = $service;
    }

    protected function tearDown(): void
    {
        Event::off(CraftVariable::class, CraftVariable::EVENT_INIT);
        Event::off(Mailer::class, Mailer::EVENT_BEFORE_SEND);
        Event::off(View::class, View::EVENT_REGISTER_CP_TEMPLATE_ROOTS);
        Plugin::setInstance(null);
        \Yii::$app = null;
    }

    public function testVerifySucceedsWhenDisabled(): void
    {
        $this->plugin->getSettings()->enabled = false;
        $this->service->setClient(new Client([
            'handler' => HandlerStack::create(new MockHandler()),
        ]));

        self::assertSame([
            'success' => true,
            'error_codes' => [],
        ], $this->service->verify('unused-token'));
    }

    public function testVerifyFailsWithoutSecretKey(): void
    {
        $settings = $this->plugin->getSettings();
        $settings->enabled = true;
        $settings->secretKey = '';
        $this->service->setClient(new Client([
            'handler' => HandlerStack::create(new MockHandler()),
        ]));

        self::assertSame([
            'success' => false,
            'error_codes' => ['missing-secret-key'],
        ], $this->service->verify('the-token'));
    }

    public function testVerifySucceedsOnCloudflareSuccess(): void
    {
        $this->enableWithSecretKey();
        $this->setMockResponses([
            new Response(200, [], '{"success":true,"error-codes":[]}'),
        ]);

        self::assertSame([
            'success' => true,
            'error_codes' => [],
        ], $this->service->verify('the-token'));
    }

    public function testVerifyFailsOnCloudflareFailure(): void
    {
        $this->enableWithSecretKey();
        $this->setMockResponses([
            new Response(200, [], '{"success":false,"error-codes":["invalid-input-response"]}'),
        ]);

        self::assertSame([
            'success' => false,
            'error_codes' => ['invalid-input-response'],
        ], $this->service->verify('the-token'));
    }

    public function testVerifyFailsOnConnectionError(): void
    {
        $this->enableWithSecretKey();
        $request = new Request('POST', 'https://challenges.cloudflare.com/turnstile/v0/siteverify');
        $this->setMockResponses([
            new ConnectException('Connection failed.', $request),
        ]);

        self::assertSame([
            'success' => false,
            'error_codes' => ['connection-failed'],
        ], $this->service->verify('the-token'));
    }

    public function testVerifyFailsOnMalformedJson(): void
    {
        $this->enableWithSecretKey();
        $this->setMockResponses([
            new Response(200, [], 'not-json'),
        ]);

        self::assertSame([
            'success' => false,
            'error_codes' => ['invalid-response'],
        ], $this->service->verify('the-token'));
    }

    public function testVerifyFailsOnEmptyJsonArrayBody(): void
    {
        $this->enableWithSecretKey();
        $this->setMockResponses([
            new Response(200, [], '[]'),
        ]);

        self::assertSame([
            'success' => false,
            'error_codes' => [],
        ], $this->service->verify('the-token'));
    }

    public function testVerifySendsSecretAndTokenAsFormParams(): void
    {
        $this->enableWithSecretKey('configured-secret');
        $history = [];
        $handler = new MockHandler([
            new Response(200, [], '{"success":true,"error-codes":[]}'),
        ]);
        $stack = HandlerStack::create($handler);
        $stack->push(Middleware::history($history));
        $this->service->setClient(new Client(['handler' => $stack]));

        $this->service->verify('the-token');

        self::assertCount(1, $history);
        parse_str((string)$history[0]['request']->getBody(), $formParams);
        self::assertSame('configured-secret', $formParams['secret'] ?? null);
        self::assertSame('the-token', $formParams['response'] ?? null);
        self::assertArrayNotHasKey('remoteip', $formParams);
    }

    private function bootApp(): void
    {
        new class([
            'id' => 'turnstile-pass-service-test',
            'basePath' => \dirname(__DIR__, 2),
        ]) extends \yii\console\Application {
        };
    }

    private function createPlugin(): Plugin
    {
        return new Plugin('turnstile-pass', \Yii::$app, [
            'basePath' => \dirname(__DIR__, 2) . '/src',
        ]);
    }

    private function enableWithSecretKey(string $secretKey = 'configured-secret'): void
    {
        $settings = $this->plugin->getSettings();
        $settings->enabled = true;
        $settings->secretKey = $secretKey;
    }

    /**
     * @param array<int, Response|\Throwable> $responses
     */
    private function setMockResponses(array $responses): void
    {
        $this->service->setClient(new Client([
            'handler' => HandlerStack::create(new MockHandler($responses)),
        ]));
    }
}
