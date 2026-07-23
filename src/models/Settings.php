<?php
declare(strict_types=1);

namespace cdgrph\craftturnstilepass\models;

use craft\helpers\App;

final class Settings extends \craft\base\Model
{
    public bool $enabled = false;
    public bool $allowFormSkip = false;
    public string $siteKey = '';
    public string $secretKey = '';

    public function getSiteKey(): string
    {
        return (string)App::parseEnv($this->siteKey);
    }

    public function getSecretKey(): string
    {
        return (string)App::parseEnv($this->secretKey);
    }

    protected function defineRules(): array
    {
        return [
            [['siteKey', 'secretKey'], 'string'],
            [['enabled', 'allowFormSkip'], 'boolean'],
        ];
    }
}
