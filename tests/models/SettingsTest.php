<?php
declare(strict_types=1);

namespace cdgrph\craftturnstilepass\tests\models;

use cdgrph\craftturnstilepass\models\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    public function testDefaults(): void
    {
        $settings = new Settings();

        self::assertFalse($settings->enabled);
        self::assertSame('', $settings->siteKey);
        self::assertSame('', $settings->secretKey);
    }

    public function testValidationPassesWithValidAttributes(): void
    {
        $settings = new Settings();
        $settings->enabled = true;
        $settings->siteKey = 'configured-site-key';
        $settings->secretKey = 'configured-secret-key';

        self::assertTrue($settings->validate());
    }

    public function testGetSiteKeyReturnsLiteralValue(): void
    {
        $settings = new Settings();
        $settings->siteKey = 'literal-key';

        self::assertSame('literal-key', $settings->getSiteKey());
    }

    public function testGetSiteKeyReturnsEmptyStringForUndefinedEnvRef(): void
    {
        $settings = new Settings();
        $settings->siteKey = '$TURNSTILE_TEST_UNDEFINED_ENV';

        self::assertSame('', $settings->getSiteKey());
    }
}
