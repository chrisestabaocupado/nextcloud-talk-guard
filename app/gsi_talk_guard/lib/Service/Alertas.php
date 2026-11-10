<?php

declare(strict_types=1);

namespace OCA\GsiTalkGuard\Service;

use OCA\GsiTalkGuard\BackgroundJob\EnviarCorreoAlerta;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

/**
 * Escribe las alertas en un archivo JSON por línea y encola el correo a la
 * GSI. Las alertas nunca llevan el texto del
 * mensaje: solo metadatos y fragmentos enmascarados.
 */
class Alertas {
	public function __construct(
		private Settings $settings,
		private IJobList $jobList,
		private LoggerInterface $logger,
		private ContextoAlertas $contexto,
	) {
	}

	/** @param array<string, mixed> $registro */
	public function emitir(array $registro): void {
		$registro = $this->contexto->enriquecer($registro);
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
		$argumento = [
			'alerta_id' => $registro['alerta_id'] ?? '',
			'regla' => $registro['regla'] ?? '',
			'regla_id' => $registro['regla_id'] ?? '',
			'severidad' => $registro['severidad'] ?? '',
			'accion' => $registro['accion'] ?? '',
			'evento' => $registro['evento'] ?? '',
			'autor' => $registro['autor'] ?? '',
			'tipo_autor' => $registro['tipo_autor'] ?? 'users',
			'autor_ficha' => $registro['autor_ficha'] ?? null,
			'solicitante' => $registro['solicitante'] ?? '',
			'solicitante_ficha' => $registro['solicitante_ficha'] ?? null,
			'detalle' => mb_substr((string)($registro['detalle'] ?? ''), 0, 1000),
			'sala' => mb_substr((string)($registro['sala_nombre'] ?? ''), 0, 120),
			'sala_tipo' => $registro['sala_tipo'] ?? '',
			'sala_nombre_original' => $registro['sala_nombre_original'] ?? '',
			'sala_token' => $registro['sala_token'] ?? '',
			'sala_url' => $registro['sala_url'] ?? '',
			'participantes_fichas' => array_slice($registro['participantes_fichas'] ?? [], 0, 20),
			'num_participantes' => $registro['num_participantes'] ?? 0,
			'coincidencias' => $registro['coincidencias'] ?? 0,
			'archivo' => mb_substr((string)($registro['archivo'] ?? ''), 0, 200),
			'fecha' => $registro['fecha_mensaje'] ?? '',
		];
		// El límite de Nextcloud es 32.000 bytes de JSON (con escapes Unicode).
		while (strlen((string)json_encode($argumento)) > 30000 && $argumento['participantes_fichas'] !== []) {
			array_pop($argumento['participantes_fichas']);
		}
		if (strlen((string)json_encode($argumento)) > 30000) {
			// La ficha íntegra permanece en el JSONL y en el panel.
			foreach (['autor_ficha', 'solicitante_ficha'] as $campo) {
				if (is_array($argumento[$campo])) {
					unset($argumento[$campo]['grupos']);
					$argumento[$campo] = array_map(static fn ($v) => is_string($v) ? mb_substr($v, 0, 120) : $v, $argumento[$campo]);
				}
			}
		}
		$this->jobList->add(EnviarCorreoAlerta::class, $argumento);
	}
}
