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
        self::assertFalse($settings->allowFormSkip);
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

    public function testRequiresVerificationFollowsEnabledOnly(): void
    {
        $settings = new Settings();

        self::assertFalse($settings->requiresVerification());

        $settings->enabled = true;

        self::assertTrue($settings->requiresVerification());
    }

    public function testIsOperationalRequiresEnabledAndBothKeys(): void
    {
        $settings = new Settings();
        $settings->enabled = true;
        $settings->siteKey = 'configured-site-key';
        $settings->secretKey = 'configured-secret-key';

        self::assertTrue($settings->isOperational());
    }

    public function testIsOperationalIsFalseWhenDisabled(): void
    {
        $settings = new Settings();
        $settings->siteKey = 'configured-site-key';
        $settings->secretKey = 'configured-secret-key';

        self::assertFalse($settings->isOperational());
    }

    public function testIsOperationalIsFalseWhenSiteKeyIsMissing(): void
    {
        $settings = new Settings();
        $settings->enabled = true;
        $settings->secretKey = 'configured-secret-key';

        self::assertFalse($settings->hasSiteKey());
        self::assertTrue($settings->hasSecretKey());
        self::assertFalse($settings->isOperational());
    }

    public function testIsOperationalIsFalseWhenSecretKeyIsMissing(): void
    {
        $settings = new Settings();
        $settings->enabled = true;
        $settings->siteKey = 'configured-site-key';

        self::assertTrue($settings->hasSiteKey());
        self::assertFalse($settings->hasSecretKey());
        self::assertFalse($settings->isOperational());
    }

    public function testIsOperationalIsFalseWhenBothKeysAreMissing(): void
    {
        $settings = new Settings();
        $settings->enabled = true;

        self::assertFalse($settings->isOperational());
    }

    public function testIsOperationalIsFalseForUndefinedEnvRefs(): void
    {
        $settings = new Settings();
        $settings->enabled = true;
        $settings->siteKey = '$TURNSTILE_TEST_UNDEFINED_ENV';
        $settings->secretKey = '$TURNSTILE_TEST_UNDEFINED_ENV';

        self::assertFalse($settings->isOperational());
    }

    public function testWhitespaceOnlyKeysAreTreatedAsMissing(): void
    {
        $settings = new Settings();
        $settings->enabled = true;
        $settings->siteKey = "  \t ";
        $settings->secretKey = ' ';

        self::assertFalse($settings->hasSiteKey());
        self::assertFalse($settings->hasSecretKey());
        self::assertFalse($settings->isOperational());
    }
    public function testIsMisconfiguredOnlyWhenEnabledWithoutKeys(): void
    {
        $settings = new Settings();
        self::assertFalse($settings->isMisconfigured());

        $settings->enabled = true;
        self::assertTrue($settings->isMisconfigured());

        $settings->siteKey = 'configured-site';
        $settings->secretKey = 'configured-secret';
        self::assertFalse($settings->isMisconfigured());
    }

    public function testMissingKeyNamesReportsOnlyTheAbsentKeys(): void
    {
        $settings = new Settings();
        $settings->enabled = true;

        self::assertSame(['site key', 'secret key'], $settings->missingKeyNames());

        $settings->siteKey = 'configured-site';
        self::assertSame(['secret key'], $settings->missingKeyNames());

        $settings->secretKey = 'configured-secret';
        self::assertSame([], $settings->missingKeyNames());
    }
}
