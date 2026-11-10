<?php

declare(strict_types=1);

namespace OCA\GsiTalkGuard\Listener;

use OCA\GsiTalkGuard\AppInfo\Application;
use OCA\GsiTalkGuard\Service\Guardian;
use OCA\Talk\Events\BeforeSystemMessageSentEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Talk: antes de guardar un mensaje de sistema.
 * Nunca deja escapar un error: un fallo aquí no debe impedir el envío.
 *
 * @template-implements IEventListener<BeforeSystemMessageSentEvent>
 */
class BeforeSystemMessageListener implements IEventListener {
	public function __construct(
		private Guardian $guardian,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof BeforeSystemMessageSentEvent) {
			return;
		}
		try {
			$this->guardian->antesDeGuardarSistema($event->getRoom(), $event->getComment());
		} catch (\Throwable $e) {
			$this->logger->error('GSI Talk Guard (BeforeSystemMessageListener): ' . $e->getMessage(),
				['app' => Application::APP_ID, 'exception' => $e]);
		}
	}
}
