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

    /**
     * @return array{success: bool, error_codes: list<string>}
     */
    public function verify(string $token): array
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->enabled) {
            return $this->result(true);
        }

        if (strlen($token) > 2048) {
            Craft::warning('Turnstile verification rejected a token exceeding 2048 bytes.', __METHOD__);
            return $this->result(false, ['invalid-input-response']);
        }

        $secretKey = $settings->getSecretKey();
        if ($secretKey === '') {
            Craft::warning('Turnstile verification skipped because the secret key is missing.', __METHOD__);
            return $this->result(false, ['missing-secret-key']);
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
                'Turnstile verification request failed (connection-failed): ' . $exception->getMessage(),
                __METHOD__,
            );
            return $this->result(false, ['connection-failed']);
        }

        $data = json_decode((string)$response->getBody(), true);
        $errorCodes = is_array($data) ? ($data['error-codes'] ?? []) : null;
        if (
            json_last_error() !== JSON_ERROR_NONE ||
            !is_array($data) ||
            !is_array($errorCodes) ||
            array_filter(
                $errorCodes,
                static fn(mixed $errorCode): bool => !is_string($errorCode),
            ) !== []
        ) {
            Craft::error('Turnstile verification returned an invalid response (invalid-response).', __METHOD__);
            return $this->result(false, ['invalid-response']);
        }

        return $this->result(
            ($data['success'] ?? null) === true,
            array_values($errorCodes),
        );
    }

    /**
     * @param list<string> $errorCodes
     * @return array{success: bool, error_codes: list<string>}
     */
    private function result(bool $success, array $errorCodes = []): array
    {
        return [
            'success' => $success,
            'error_codes' => $errorCodes,
        ];
    }

    private function getClient(): ClientInterface
    {
        return $this->client ??= Craft::createGuzzleClient([
            'timeout' => 5,
        ]);
    }
}
