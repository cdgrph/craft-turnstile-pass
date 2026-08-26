<?php
declare(strict_types=1);

namespace cdgrph\craftturnstilepass\tests\variables;

use cdgrph\craftturnstilepass\Plugin;
use cdgrph\craftturnstilepass\variables\TurnstilePassVariable;
use craft\contactform\Mailer;
use craft\web\View;
use craft\web\twig\variables\CraftVariable;
use PHPUnit\Framework\TestCase;
use yii\base\Event;
use yii\caching\ArrayCache;
use yii\log\Logger;

final class TurnstilePassVariableTest extends TestCase
{
    private Plugin $plugin;
    private TurnstilePassVariable $variable;

    protected function setUp(): void
    {
        $this->bootApp();
        \Yii::getLogger()->messages = [];
        $this->plugin = new Plugin('turnstile-pass', \Yii::$app, [
            'basePath' => \dirname(__DIR__, 2) . '/src',
        ]);
        $this->variable = new TurnstilePassVariable();
    }

    protected function tearDown(): void
    {
        Event::off(CraftVariable::class, CraftVariable::EVENT_INIT);
        Event::off(Mailer::class, Mailer::EVENT_BEFORE_SEND);
        Event::off(View::class, View::EVENT_REGISTER_CP_TEMPLATE_ROOTS);
        Plugin::setInstance(null);
        \Yii::$app = null;
    }

    public function testIsDisabledByDefault(): void
    {
        self::assertFalse($this->variable->getIsEnabled());
    }

    public function testIsDisabledWhenEnabledButNoKeysAreConfigured(): void
    {
        $this->configure(enabled: true);

        self::assertFalse($this->variable->getIsEnabled());
    }

    public function testIsDisabledWhenSiteKeyIsMissing(): void
    {
        $this->configure(enabled: true, secretKey: 'configured-secret');

        self::assertFalse($this->variable->getIsEnabled());
    }

    public function testIsDisabledWhenSecretKeyIsMissing(): void
    {
        $this->configure(enabled: true, siteKey: 'configured-site');

        self::assertFalse($this->variable->getIsEnabled());
    }

    public function testIsEnabledWhenFullyConfigured(): void
    {
        $this->configureOperational();

        self::assertTrue($this->variable->getIsEnabled());
    }

    public function testScriptAndWidgetAreEmptyWhenSecretKeyIsMissing(): void
    {
        $this->configure(enabled: true, siteKey: 'configured-site');

        self::assertSame('', (string)$this->variable->script());
        self::assertSame('', (string)$this->variable->widget());
    }

    public function testScriptAndWidgetAreEmptyWhenSiteKeyIsMissing(): void
    {
        $this->configure(enabled: true, secretKey: 'configured-secret');

        self::assertSame('', (string)$this->variable->script());
        self::assertSame('', (string)$this->variable->widget());
    }

    public function testWidgetRendersSiteKeyWhenOperational(): void
    {
        $this->configureOperational();

        $widget = (string)$this->variable->widget();

        self::assertStringContainsString('cf-turnstile', $widget);
        self::assertStringContainsString('data-sitekey="configured-site"', $widget);
    }

    public function testScriptRendersApiTagWhenOperational(): void
    {
        $this->configureOperational();

        self::assertStringContainsString(
            'https://challenges.cloudflare.com/turnstile/v0/api.js',
            (string)$this->variable->script(),
        );
    }

    public function testGetSiteKeyReturnsResolvedValueRegardlessOfOperationalState(): void
    {
        $this->configure(enabled: true, siteKey: 'configured-site');
        self::assertFalse($this->variable->getIsEnabled());
        self::assertSame('configured-site', $this->variable->getSiteKey());

        $this->configure(enabled: false, siteKey: 'configured-site');
        self::assertSame('configured-site', $this->variable->getSiteKey());
    }

    public function testEmptyWidgetReportsTheMisconfigurationOncePerWindow(): void
    {
        $this->configure(enabled: true, secretKey: 'configured-secret');

        $this->variable->widget();
        self::assertSame(1, $this->misconfigurationLogCount());

        $this->variable->script();
        self::assertSame(1, $this->misconfigurationLogCount());
    }

    public function testEmptyWidgetIsSilentWhenThePluginIsSimplyDisabled(): void
    {
        $this->configure(enabled: false, siteKey: 'configured-site', secretKey: 'configured-secret');

        $this->variable->widget();
        $this->variable->script();

        self::assertSame(0, $this->misconfigurationLogCount());
    }

    public function testRenderedWidgetIsSilent(): void
    {
        $this->configureOperational();

        $this->variable->widget();
        $this->variable->script();

        self::assertSame(0, $this->misconfigurationLogCount());
    }

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

    private function configure(bool $enabled = false, string $siteKey = '', string $secretKey = ''): void
    {
        $settings = $this->plugin->getSettings();
        $settings->enabled = $enabled;
        $settings->siteKey = $siteKey;
        $settings->secretKey = $secretKey;
    }

    private function configureOperational(): void
    {
        $this->configure(enabled: true, siteKey: 'configured-site', secretKey: 'configured-secret');
    }

    private function bootApp(): void
    {
        new class([
            'id' => 'turnstile-pass-variable-test',
            'basePath' => \dirname(__DIR__, 2),
            'components' => [
                'cache' => ArrayCache::class,
            ],
        ]) extends \yii\console\Application {
        };
    }
}
