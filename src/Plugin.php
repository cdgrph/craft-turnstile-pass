<?php
declare(strict_types=1);

namespace cdgrph\craftturnstilepass;

use Craft;
use cdgrph\craftturnstilepass\models\Settings;
use cdgrph\craftturnstilepass\services\TurnstileService;
use cdgrph\craftturnstilepass\variables\TurnstilePassVariable;
use craft\base\Model;
use craft\contactform\events\SendEvent;
use craft\contactform\Mailer;
use craft\web\twig\variables\CraftVariable;
use yii\base\Event;

/**
 * @property-read TurnstileService $turnstile
 */
final class Plugin extends \craft\base\Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    public function init(): void
    {
        parent::init();

        $this->setComponents(['turnstile' => TurnstileService::class]);
        $this->registerVariable();
        $this->registerContactFormHook();
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate('turnstile-pass/_settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    private function registerVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $event): void {
                $event->sender->set('turnstilePass', TurnstilePassVariable::class);
            },
        );
    }

    private function registerContactFormHook(): void
    {
        if (!class_exists(Mailer::class)) {
            return;
        }

        Event::on(
            Mailer::class,
            Mailer::EVENT_BEFORE_SEND,
            function(SendEvent $event): void {
                if (!$this->getSettings()->enabled) {
                    return;
                }

                // Contact Form short-circuits spam to a silent success, so the
                // submission error is informational only (kept in case a future
                // Contact Form version surfaces errors for spam submissions).
                $reject = static function(SendEvent $event): void {
                    $event->isSpam = true;
                    $event->submission->addError(
                        'turnstile',
                        Craft::t('turnstile-pass', 'Verification failed. Please try again.'),
                    );
                };

                $token = Craft::$app->getRequest()->getBodyParam('cf-turnstile-response');

                // Reject non-string values (e.g. cf-turnstile-response[]=x)
                // instead of letting verify(string) raise a TypeError.
                if (!is_string($token) || $token === '') {
                    $reject($event);
                    return;
                }

                if (!$this->turnstile->verify($token)['success']) {
                    $reject($event);
                }
            },
        );
    }
}
