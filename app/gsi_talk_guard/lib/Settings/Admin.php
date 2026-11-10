<?php

declare(strict_types=1);

namespace OCA\GsiTalkGuard\Settings;

use OCA\GsiTalkGuard\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\Settings\IDelegatedSettings;
use OCP\Util;

/**
 * Página "GSI Talk Guard" en Configuración de administración.
 * Es delegable: en Administración › Privilegios de administración se puede
 * asignar a un grupo de la GSI sin hacerlo administrador de Nextcloud.
 */
class Admin implements IDelegatedSettings {
	public function __construct(
		private IURLGenerator $url,
	) {
	}

	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'admin');
		Util::addStyle(Application::APP_ID, 'admin');
		$rutas = [];
		foreach (['estado', 'alertas', 'probar', 'modo', 'config', 'dominios', 'regla', 'guardarRegla', 'eliminarRegla'] as $r) {
			$rutas[$r] = $this->url->linkToRoute(Application::APP_ID . '.admin.' . $r);
		}
		return new TemplateResponse(Application::APP_ID, 'admin', ['rutas' => $rutas]);
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}

	public function getName(): ?string {
		return null; // una sola página en la sección
	}

	public function getAuthorizedAppConfig(): array {
		return [];
	}
}
