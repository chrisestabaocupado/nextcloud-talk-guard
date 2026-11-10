<?php

declare(strict_types=1);

namespace OCA\GsiTalkGuard\Service;

use OCA\GsiTalkGuard\BackgroundJob\EnviarCorreoAlerta;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

/**
 * Escribe las alertas en un archivo JSON por línea (leído por el agente de
 * Wazuh) y encola el correo a la GSI. Las alertas nunca llevan el texto del
 * mensaje: solo metadatos y fragmentos enmascarados.
 */
class Alertas {
	public function __construct(
		private Settings $settings,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
	}

	/** @param array<string, mixed> $registro */
	public function emitir(array $registro): void {
		$registro = ['origen' => 'talk-guard', 'detectado' => gmdate('c')] + $registro;
		$registro['nivel'] = Settings::SEVERIDADES[$registro['severidad']] ?? 0;

		$this->escribir($registro);
		$this->encolarCorreo($registro);
	}

	/** @param array<string, mixed> $registro */
	private function escribir(array $registro): void {
		$ruta = $this->settings->archivoAlertas();
		$dir = dirname($ruta);
		if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
			$this->logger->error('GSI Talk Guard: no se pudo crear ' . $dir, ['app' => 'gsi_talk_guard']);
			return;
		}
		$nuevo = !file_exists($ruta);
		$linea = json_encode($registro, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
		if (@file_put_contents($ruta, $linea, FILE_APPEND | LOCK_EX) === false) {
			$this->logger->error('GSI Talk Guard: no se pudo escribir ' . $ruta, ['app' => 'gsi_talk_guard']);
			return;
		}
		if ($nuevo) {
			@chmod($ruta, 0640);
		}
	}

	/** @param array<string, mixed> $registro */
	private function encolarCorreo(array $registro): void {
		if ($this->settings->correoPara() === []) {
			return;
		}
		$minimo = Settings::SEVERIDADES[$this->settings->severidadMinimaCorreo()];
		if (($registro['nivel'] ?? 0) < $minimo) {
			return;
		}
		// Solo los campos necesarios: el argumento de un trabajo tiene tamaño limitado.
		$this->jobList->add(EnviarCorreoAlerta::class, [
			'alerta_id' => $registro['alerta_id'] ?? '',
			'regla' => $registro['regla'] ?? '',
			'regla_id' => $registro['regla_id'] ?? '',
			'severidad' => $registro['severidad'] ?? '',
			'accion' => $registro['accion'] ?? '',
			'evento' => $registro['evento'] ?? '',
			'autor' => $registro['autor'] ?? '',
			'sala' => mb_substr((string)($registro['sala_nombre'] ?? ''), 0, 120),
			'sala_tipo' => $registro['sala_tipo'] ?? '',
			'num_participantes' => $registro['num_participantes'] ?? 0,
			'coincidencias' => $registro['coincidencias'] ?? 0,
			'archivo' => mb_substr((string)($registro['archivo'] ?? ''), 0, 200),
			'fecha' => $registro['fecha_mensaje'] ?? '',
		]);
	}
}
