<?php

declare(strict_types=1);

namespace OCA\GsiTalkGuard\Listener;

use OCA\GsiTalkGuard\AppInfo\Application;
use OCA\GsiTalkGuard\Service\Guardian;
use OCA\Talk\Events\ChatMessageSentEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Talk: después de guardar un mensaje.
 * Nunca deja escapar un error: un fallo aquí no debe impedir el envío.
 *
 * @template-implements IEventListener<ChatMessageSentEvent>
 */
class MessageSentListener implements IEventListener {
	public function __construct(
		private Guardian $guardian,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof ChatMessageSentEvent) {
			return;
		}
		try {
			$this->guardian->despuesDeGuardar($event->getRoom(), $event->getComment());
		} catch (\Throwable $e) {
			$this->logger->error('GSI Talk Guard (MessageSentListener): ' . $e->getMessage(),
				['app' => Application::APP_ID, 'exception' => $e]);
		}
	}
}
