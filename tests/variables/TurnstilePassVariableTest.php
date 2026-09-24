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

    public function testWidgetPointsAtTheDefaultErrorCallback(): void
    {
        $this->configureOperational();

        $widget = (string)$this->variable->widget();

        self::assertStringContainsString('data-error-callback="turnstilePassOnError"', $widget);
        self::assertStringNotContainsString('<script', $widget);
    }

    public function testWidgetKeepsACallerSuppliedErrorCallback(): void
    {
        $this->configureOperational();

        foreach (['error-callback', 'data-error-callback'] as $key) {
            $widget = (string)$this->variable->widget([$key => 'onSiteError']);

            self::assertStringContainsString('data-error-callback="onSiteError"', $widget);
            self::assertStringNotContainsString('turnstilePassOnError', $widget);
        }
    }

    public function testWidgetOmitsTheErrorCallbackWhenTheCallerOptsOut(): void
    {
        $this->configureOperational();

        $widget = (string)$this->variable->widget(['error-callback' => false]);

        self::assertStringNotContainsString('error-callback', $widget);
    }

    public function testWidgetFallsBackToTheDefaultForAnEmptyErrorCallback(): void
    {
        $this->configureOperational();

        foreach (['', null, true] as $value) {
            $widget = (string)$this->variable->widget(['error-callback' => $value]);

            self::assertStringContainsString('data-error-callback="turnstilePassOnError"', $widget);
        }
    }

    public function testWidgetLeavesTheDefaultOutWhenRetryIsDisabled(): void
    {
        $this->configureOperational();

        $widget = (string)$this->variable->widget(['retry' => 'never']);

        self::assertStringNotContainsString('error-callback', $widget);
    }

    public function testScriptDefinesTheDefaultErrorCallbackBeforeTheApiTag(): void
    {
        $this->configureOperational();

        $script = (string)$this->variable->script();

        $definition = strpos($script, 'window.turnstilePassOnError');
        self::assertIsInt($definition);
        self::assertLessThan(strpos($script, 'challenges.cloudflare.com'), $definition);
    }

    public function testScriptAppliesTheNonceAndDataAttributesToBothTags(): void
    {
        $this->configureOperational();

        $script = (string)$this->variable->script(['nonce' => 'abc123', 'data-cfasync' => 'false', 'id' => 'turnstile-api']);

        self::assertSame(2, substr_count($script, 'nonce="abc123"'));
        self::assertSame(2, substr_count($script, 'data-cfasync="false"'));
        self::assertSame(1, substr_count($script, 'id="turnstile-api"'));
        self::assertSame(1, substr_count($script, 'src='));
    }

    /**
     * Runs the rendered callback in Node, so the classification is checked by
     * behaviour rather than by its source text.
     */
    public function testDefaultErrorCallbackReportsOnlyConfigurationCodes(): void
    {
        $node = trim((string)shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            self::markTestSkipped('Node.js is not available.');
        }

        $this->configureOperational();
        preg_match('#<script>(.*?)</script>#s', (string)$this->variable->script(), $match);
        self::assertNotEmpty($match[1] ?? '');

        $harness = <<<'JS'
const window = {};
const thrown = [];
globalThis.setTimeout = (fn) => { try { fn(); } catch (e) { thrown.push(e.message); } };
eval(require('fs').readFileSync(0, 'utf8'));
const results = {};
for (const code of ['600010', '300030', '110600', '110620', '200500', '110100', '110110', '110200', '400020', '400070', '400020', '200100', '999999']) {
    results[code] = window.turnstilePassOnError(Number(code));
}
process.stdout.write(JSON.stringify({ results, thrown }));
JS;
        $process = proc_open([$node, '-e', $harness], [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fwrite($pipes[0], $match[1]);
        fclose($pipes[0]);
        $output = json_decode((string)stream_get_contents($pipes[1]), true);
        fclose($pipes[1]);
        proc_close($process);

        $results = $output['results'];
        ksort($results);
        $expected = [
            '600010' => true,
            '300030' => true,
            '110600' => true,
            '110620' => true,
            '200500' => true,
            '110100' => true,
            '110110' => true,
            '110200' => true,
            '400020' => true,
            '400070' => true,
            '200100' => false,
            '999999' => false,
        ];
        ksort($expected);
        self::assertSame($expected, $results);
        // Each configuration code is reported once per page, however often it recurs.
        self::assertSame([
            'Turnstile error 110100',
            'Turnstile error 110110',
            'Turnstile error 110200',
            'Turnstile error 400020',
            'Turnstile error 400070',
        ], $output['thrown']);
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
