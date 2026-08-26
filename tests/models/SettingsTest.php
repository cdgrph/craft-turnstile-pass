<?php
declare(strict_types=1);

namespace cdgrph\craftturnstilepass\tests\models;

use cdgrph\craftturnstilepass\models\Settings;
use PHPUnit\Framework\Attributes\DataProvider;
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
    public function testKeyGettersTrimSurroundingWhitespace(): void
    {
        $settings = new Settings();
        $settings->enabled = true;
        $settings->siteKey = "  configured-site\n";
        $settings->secretKey = ' configured-secret ';

        self::assertSame('configured-site', $settings->getSiteKey());
        self::assertSame('configured-secret', $settings->getSecretKey());
        self::assertTrue($settings->isOperational());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invisiblePaddingProvider(): iterable
    {
        yield 'non-breaking space' => ["\u{00A0}"];
        yield 'ideographic space' => ["\u{3000}"];
        yield 'narrow no-break space' => ["\u{202F}"];
        yield 'zero width space' => ["\u{200B}"];
        yield 'byte order mark' => ["\u{FEFF}"];
        yield 'left-to-right mark' => ["\u{200E}"];
        yield 'soft hyphen' => ["\u{00AD}"];
        yield 'Mongolian vowel separator' => ["\u{180E}"];
        yield 'mixed with ASCII whitespace' => [" \u{00A0}\t\u{FEFF}"];
    }

    #[DataProvider('invisiblePaddingProvider')]
    public function testInvisibleOnlyKeysAreTreatedAsMissing(string $padding): void
    {
        $settings = new Settings();
        $settings->enabled = true;
        $settings->siteKey = $padding;
        $settings->secretKey = $padding;

        self::assertFalse($settings->hasSiteKey());
        self::assertFalse($settings->hasSecretKey());
        self::assertSame(['site key', 'secret key'], $settings->missingKeyNames());
        self::assertFalse($settings->isOperational());
    }

    #[DataProvider('invisiblePaddingProvider')]
    public function testKeyGettersTrimSurroundingInvisibleCharacters(string $padding): void
    {
        $settings = new Settings();
        $settings->enabled = true;
        $settings->siteKey = $padding . 'configured-site' . $padding;
        $settings->secretKey = $padding . 'configured-secret' . $padding;

        self::assertSame('configured-site', $settings->getSiteKey());
        self::assertSame('configured-secret', $settings->getSecretKey());
        self::assertTrue($settings->isOperational());
    }

    public function testTrimmingLeavesTheInsideOfAKeyAlone(): void
    {
        $settings = new Settings();
        $settings->siteKey = "config\u{00A0}ured-site";

        self::assertSame("config\u{00A0}ured-site", $settings->getSiteKey());
    }

    public function testOnlyTheBlankKeyIsReportedAsMissing(): void
    {
        $settings = new Settings();
        $settings->enabled = true;
        $settings->siteKey = "\u{00A0}";
        $settings->secretKey = 'configured-secret';

        self::assertSame(['site key'], $settings->missingKeyNames());
    }

    public function testKeysThatAreNotValidUtf8FallBackToAsciiTrimming(): void
    {
        $settings = new Settings();
        $settings->enabled = true;
        // A lone continuation byte makes the UTF-8 pattern fail; the key must
        // still lose its ASCII padding rather than be returned untouched.
        $settings->siteKey = "  \x80configured-site  ";

        self::assertSame("\x80configured-site", $settings->getSiteKey());
        self::assertTrue($settings->hasSiteKey());
    }
}
