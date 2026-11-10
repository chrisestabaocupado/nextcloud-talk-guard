<?php

declare(strict_types=1);

namespace OCA\GsiTalkGuard\BackgroundJob;

use OCA\GsiTalkGuard\AppInfo\Application;
use OCA\GsiTalkGuard\Service\Settings;
use OCA\GsiTalkGuard\Service\ContextoAlertas;
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
		private ContextoAlertas $contexto,
	) {
		parent::__construct($time);
	}

	protected function run($argument): void {
		$destinos = $this->settings->correoPara();
		if ($destinos === [] || !is_array($argument)) {
			return;
		}
		// Compatible también con trabajos encolados antes de incorporar las fichas.
		$a = $this->contexto->enriquecer($argument + ['sala_nombre' => $argument['sala'] ?? '']);
		$autor = $a['autor_ficha'] ?? $a['solicitante_ficha'] ?? [];
		$persona = static function (array $p): string {
			return ($p['nombre'] ?? $p['id'] ?? 'no disponible')
				. (!empty($p['usuario']) ? ' (' . $p['usuario'] . ')' : '')
				. (!empty($p['correo']) ? ' <' . $p['correo'] . '>' : '');
		};
		$lineasPersona = [];
		foreach (['telefono' => 'Teléfono', 'organizacion' => 'Organización', 'departamento' => 'Departamento',
			'cargo' => 'Cargo', 'estado' => 'Estado de la cuenta', 'origen' => 'Directorio', 'perfil_url' => 'Perfil'] as $campo => $etiqueta) {
			if (!empty($autor[$campo])) {
				$lineasPersona[] = $etiqueta . ': ' . $autor[$campo];
			}
		}
		if (!empty($autor['grupos'])) {
			$lineasPersona[] = 'Grupos: ' . implode(', ', $autor['grupos']);
		}
		$participantes = array_map($persona, $a['participantes_fichas'] ?? []);
		$omitidos = max(0, (int)($a['num_participantes'] ?? 0) - count($participantes));
		$cuerpo = implode("\n", [
			'Alerta: ' . ($a['regla'] ?? '') . ' (' . ($a['regla_id'] ?? '') . ')',
			'Severidad: ' . ($a['severidad'] ?? ''),
			'Acción: ' . ($a['accion'] ?? ''),
			'Evento: ' . ($a['evento'] ?? ''),
			'Fecha del mensaje: ' . ($a['fecha'] ?? ''),
			'Persona: ' . $persona($autor),
			'ID interno: ' . ($autor['id'] ?? $a['autor'] ?? ''),
			...$lineasPersona,
			'Sala: ' . ($a['sala_nombre'] ?? '') . ' (' . ($a['sala_tipo'] ?? '') . ')',
			'Token de sala: ' . ($a['sala_token'] ?? ''),
			'Enlace a la sala: ' . ($a['sala_url'] ?? ''),
			'Participantes: ' . ($a['num_participantes'] ?? 0),
			...array_map(static fn ($p) => '  - ' . $p, $participantes),
			...($omitidos > 0 ? ['  Otros ' . $omitidos . ' participantes: consulte el detalle en GSI Talk Guard.'] : []),
			'Coincidencias: ' . ($a['coincidencias'] ?? 0),
			'Archivo: ' . (($a['archivo'] ?? '') !== '' ? $a['archivo'] : '-'),
			'ID de alerta: ' . ($a['alerta_id'] ?? ''),
			'Detalle: ' . ($a['detalle'] ?? ''),
			'',
			'El contenido del mensaje no se incluye en este correo. Revise el detalle en GSI Talk Guard, dentro de Nextcloud.',
		]);
		try {
			$mensaje = $this->mailer->createMessage();
			$mensaje->setTo($destinos);
			$mensaje->setSubject('[GSI Talk] ' . mb_strtoupper((string)($a['severidad'] ?? '')) . ': ' . ($a['regla'] ?? ''));
			$mensaje->setPlainBody($cuerpo);
			$fallidos = $this->mailer->send($mensaje);
			if ($fallidos !== []) {
				$this->logger->error('GSI Talk Guard: el servicio de correo informó destinatarios fallidos',
					['app' => Application::APP_ID, 'alerta_id' => $a['alerta_id'] ?? '', 'destinatarios' => $fallidos]);
			}
		} catch (\Throwable $e) {
			$this->logger->error('GSI Talk Guard: no se pudo enviar el correo: ' . $e->getMessage(),
				['app' => Application::APP_ID]);
		}
	}
}
