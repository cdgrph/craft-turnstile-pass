<?php
declare(strict_types=1);

namespace cdgrph\craftturnstilepass\services;

use Craft;
use cdgrph\craftturnstilepass\Plugin;
use craft\web\Request;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

final class TurnstileService extends \craft\base\Component
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    private ?ClientInterface $client = null;

    public function setClient(ClientInterface $client): void
    {
        $this->client = $client;
    }

    public function verify(string $token): array
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->enabled) {
            return [
                'success' => true,
                'error_codes' => [],
            ];
        }

        $secretKey = $settings->getSecretKey();
        if ($secretKey === '') {
            Craft::warning('Turnstile verification skipped because the secret key is missing.', __METHOD__);
            return [
                'success' => false,
                'error_codes' => ['missing-secret-key'],
            ];
        }

        $formParams = [
            'secret' => $secretKey,
            'response' => $token,
        ];

        $request = Craft::$app->getRequest();
        if ($request instanceof Request) {
            $remoteIp = $request->getUserIP();
            if ($remoteIp !== null && $remoteIp !== '') {
                $formParams['remoteip'] = $remoteIp;
            }
        }

        try {
            $response = $this->getClient()->request('POST', self::VERIFY_URL, [
                'form_params' => $formParams,
            ]);
        } catch (GuzzleException $exception) {
            Craft::error(
                'Turnstile verification request failed: ' . $exception->getMessage(),
                __METHOD__,
            );
            return [
                'success' => false,
                'error_codes' => ['connection-failed'],
            ];
        }

        $data = json_decode((string)$response->getBody(), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            Craft::error('Turnstile verification returned an invalid response.', __METHOD__);
            return [
                'success' => false,
                'error_codes' => ['invalid-response'],
            ];
        }

        $errorCodes = $data['error-codes'] ?? [];
        if (!is_array($errorCodes)) {
            $errorCodes = [];
        }

        return [
            'success' => (bool)($data['success'] ?? false),
            'error_codes' => array_map('strval', array_values($errorCodes)),
        ];
    }

    private function getClient(): ClientInterface
    {
        return $this->client ??= Craft::createGuzzleClient([
            'timeout' => 5,
        ]);
    }
}
