<?php

declare(strict_types=1);

namespace OCA\GsiTalkGuard\Service;

use OCA\GsiTalkGuard\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IConfig;

/**
 * Configuración de la app. Todo se guarda en la configuración de Nextcloud
 * (tabla appconfig) y se cambia con los comandos occ gsi-talk-guard:*.
 */
class Settings {
	public const MODO_NORMAL = 'normal';            // aplica la acción de cada regla
	public const MODO_SOLO_ALERTAR = 'solo_alertar'; // interruptor general: nunca enmascara
	public const MODO_DESACTIVADO = 'desactivado';   // no analiza nada
	public const MODOS = [self::MODO_NORMAL, self::MODO_SOLO_ALERTAR, self::MODO_DESACTIVADO];

	public const SEVERIDADES = ['baja' => 1, 'media' => 2, 'alta' => 3, 'critica' => 4];

	public function __construct(
		private IAppConfig $appConfig,
		private IConfig $config,
	) {
	}

	private function leer(string $clave, string $porDefecto = ''): string {
		try {
			return $this->appConfig->getValueString(Application::APP_ID, $clave, $porDefecto);
		} catch (\Throwable) {
			return $porDefecto;
		}
	}

	private function escribir(string $clave, string $valor, bool $sensible = false): void {
		$this->appConfig->setValueString(Application::APP_ID, $clave, $valor, false, $sensible);
	}

	public function modo(): string {
		$modo = $this->leer('modo', self::MODO_NORMAL);
		return in_array($modo, self::MODOS, true) ? $modo : self::MODO_NORMAL;
	}

	public function cambiarModo(string $modo): void {
		if (!in_array($modo, self::MODOS, true)) {
			throw new \InvalidArgumentException('Modo inválido: ' . $modo);
		}
		$this->escribir('modo', $modo);
	}

	/** @return list<string> ids de usuario; vacío = todos */
	public function usuariosPiloto(): array {
		return $this->lista($this->leer('usuarios_piloto'));
	}

	public function avisarUsuario(): bool {
		return $this->leer('avisar_usuario', 'si') !== 'no';
	}

	public function clavePublica(): ?string {
		$clave = $this->leer('clave_publica');
		return $clave === '' ? null : $clave;
	}

	public function guardarClavePublica(string $base64): void {
		$this->escribir('clave_publica', $base64);
	}

	public function reglasPersonalizadas(): ?string {
		$json = $this->leer('reglas');
		return $json === '' ? null : $json;
	}

	public function guardarReglas(string $json): void {
		$this->escribir('reglas', $json);
	}

	public static function reglasPredeterminadas(): string {
		return (string)file_get_contents(__DIR__ . '/../../config/reglas.json');
	}

	public function archivoAlertas(): string {
		$ruta = $this->leer('archivo_alertas');
		if ($ruta !== '') {
			return $ruta;
		}
		$datos = rtrim($this->config->getSystemValueString('datadirectory', ''), '/');
		return $datos . '/gsi_talk_guard/alertas.jsonl';
	}

	/** @return list<string> */
	public function correoPara(): array {
		return $this->lista($this->leer('correo_para'));
	}

	public function severidadMinimaCorreo(): string {
		$sev = $this->leer('severidad_minima_correo', 'media');
		return isset(self::SEVERIDADES[$sev]) ? $sev : 'media';
	}

	/** @param list<string> $usuarios */
	public function guardarUsuariosPiloto(array $usuarios): void {
		$this->escribir('usuarios_piloto', implode(',', $usuarios));
	}

	/** @param list<string> $correos */
	public function guardarCorreoPara(array $correos): void {
		$this->escribir('correo_para', implode(',', $correos));
	}

	public function guardarSeveridadMinimaCorreo(string $severidad): void {
		if (!isset(self::SEVERIDADES[$severidad])) {
			throw new \InvalidArgumentException('Severidad inválida: ' . $severidad);
		}
		$this->escribir('severidad_minima_correo', $severidad);
	}

	public function guardarAvisarUsuario(bool $avisar): void {
		$this->escribir('avisar_usuario', $avisar ? 'si' : 'no');
	}

	/** @return list<string> */
	private function lista(string $valor): array {
		return array_values(array_filter(array_map('trim', explode(',', $valor)), fn ($v) => $v !== ''));
	}
}
