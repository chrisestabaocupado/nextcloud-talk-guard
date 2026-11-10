<?php

declare(strict_types=1);

namespace OCA\GsiTalkGuard\Notification;

use OCA\GsiTalkGuard\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/** Aviso al autor cuando se ocultó un dato de su mensaje. */
class Notifier implements INotifier {
	public function __construct(
		private IURLGenerator $url,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return 'Seguridad de la Información';
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID || $notification->getSubject() !== 'mensaje_enmascarado') {
			throw new UnknownNotificationException();
		}
		$p = $notification->getSubjectParameters();
		$notification
			->setParsedSubject('Se ocultó información sensible de tu mensaje')
			->setParsedMessage(sprintf(
				'Tu mensaje en %s contenía %s, por lo que esa parte se ocultó automáticamente. '
				. 'La Política de uso no permite compartir estos datos por chat. '
				. 'Si los necesitas para tu trabajo, contacta a Seguridad de la Información.',
				$p['sala'] ?? 'una conversación', $p['datos'] ?? 'datos sensibles'))
			->setIcon($this->url->getAbsoluteURL($this->url->imagePath('core', 'actions/password.svg')));
		if (!empty($p['token'])) {
			$notification->setLink($this->url->getAbsoluteURL('/index.php/call/' . rawurlencode((string)$p['token'])));
		}
		return $notification;
	}
}
