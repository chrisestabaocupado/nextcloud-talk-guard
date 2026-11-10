<?php

declare(strict_types=1);

namespace OCA\GsiTalkGuard\BackgroundJob;

use OCA\GsiTalkGuard\AppInfo\Application;
use OCA\GsiTalkGuard\Service\Settings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/** Envía el correo de una alerta usando la configuración SMTP de Nextcloud. Solo metadatos. */
class EnviarCorreoAlerta extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private IMailer $mailer,
		private Settings $settings,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	protected function run($argument): void {
		$destinos = $this->settings->correoPara();
		if ($destinos === [] || !is_array($argument)) {
			return;
		}
		$a = $argument;
		$cuerpo = implode("\n", [
			'Alerta: ' . ($a['regla'] ?? '') . ' (' . ($a['regla_id'] ?? '') . ')',
			'Severidad: ' . ($a['severidad'] ?? ''),
			'Acción: ' . ($a['accion'] ?? ''),
			'Evento: ' . ($a['evento'] ?? ''),
			'Fecha del mensaje: ' . ($a['fecha'] ?? ''),
			'Autor: ' . ($a['autor'] ?? ''),
			'Sala: ' . ($a['sala'] ?? '') . ' (' . ($a['sala_tipo'] ?? '') . ')',
			'Participantes: ' . ($a['num_participantes'] ?? 0),
			'Coincidencias: ' . ($a['coincidencias'] ?? 0),
			'Archivo: ' . (($a['archivo'] ?? '') !== '' ? $a['archivo'] : '-'),
			'ID de alerta: ' . ($a['alerta_id'] ?? ''),
			'',
			'El contenido del mensaje no se incluye en este correo. Revise el detalle en Wazuh.',
		]);
		try {
			$mensaje = $this->mailer->createMessage();
			$mensaje->setTo($destinos);
			$mensaje->setSubject('[GSI Talk] ' . mb_strtoupper((string)($a['severidad'] ?? '')) . ': ' . ($a['regla'] ?? ''));
			$mensaje->setPlainBody($cuerpo);
			$this->mailer->send($mensaje);
		} catch (\Throwable $e) {
			$this->logger->error('GSI Talk Guard: no se pudo enviar el correo: ' . $e->getMessage(),
				['app' => Application::APP_ID]);
		}
	}
}
