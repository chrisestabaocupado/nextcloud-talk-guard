<?php

declare(strict_types=1);

namespace OCA\GsiTalkGuard\AppInfo;

use OCA\GsiTalkGuard\Listener\BeforeMessageListener;
use OCA\GsiTalkGuard\Listener\BeforeSystemMessageListener;
use OCA\GsiTalkGuard\Listener\MessageSentListener;
use OCA\GsiTalkGuard\Listener\SystemMessageListener;
use OCA\GsiTalkGuard\Notification\Notifier;
use OCA\Talk\Events\BeforeChatMessageSentEvent;
use OCA\Talk\Events\BeforeSystemMessageSentEvent;
use OCA\Talk\Events\ChatMessageSentEvent;
use OCA\Talk\Events\SystemMessageSentEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'gsi_talk_guard';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		// Mensajes normales: antes de guardar (enmascarar) y después (alertar).
		$context->registerEventListener(BeforeChatMessageSentEvent::class, BeforeMessageListener::class);
		$context->registerEventListener(ChatMessageSentEvent::class, MessageSentListener::class);
		// Mensajes de sistema: archivos compartidos y ediciones.
		$context->registerEventListener(BeforeSystemMessageSentEvent::class, BeforeSystemMessageListener::class);
		$context->registerEventListener(SystemMessageSentEvent::class, SystemMessageListener::class);

		$context->registerNotifierService(Notifier::class);
	}

	public function boot(IBootContext $context): void {
	}
}
