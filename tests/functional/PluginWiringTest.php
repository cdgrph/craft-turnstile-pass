<?php
declare(strict_types=1);

namespace cdgrph\craftturnstilepass\tests\functional;

use cdgrph\craftturnstilepass\models\Settings;
use cdgrph\craftturnstilepass\Plugin;
use craft\contactform\Mailer;
use craft\web\View;
use craft\web\twig\variables\CraftVariable;
use PHPUnit\Framework\TestCase;
use yii\base\Event;

final class PluginWiringTest extends TestCase
{
    protected function setUp(): void
    {
        $this->bootApp();
    }

    protected function tearDown(): void
    {
        Event::off(CraftVariable::class, CraftVariable::EVENT_INIT);
        Event::off(Mailer::class, Mailer::EVENT_BEFORE_SEND);
        Event::off(View::class, View::EVENT_REGISTER_CP_TEMPLATE_ROOTS);
        Plugin::setInstance(null);
        \Yii::$app = null;
    }

    public function testSettingsModelCreated(): void
    {
        $plugin = $this->createPlugin();

        self::assertInstanceOf(Settings::class, $plugin->getSettings());
    }

    public function testVariableEventHandlerRegistered(): void
    {
        $this->createPlugin();

        self::assertTrue(Event::hasHandlers(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
        ));
    }

    public function testContactFormHookRegistered(): void
    {
        $this->createPlugin();

        self::assertTrue(Event::hasHandlers(
            Mailer::class,
            Mailer::EVENT_BEFORE_SEND,
        ));
    }

    private function bootApp(): void
    {
        new class([
            'id' => 'turnstile-pass-wiring-test',
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
}
