<?php

declare(strict_types=1);

namespace OCA\GsiTalkGuard\Service;

use OCA\GsiTalkGuard\AppInfo\Application;
use OCP\Accounts\IAccountManager;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\LDAP\ILDAPProvider;
use OCP\Server;
use Psr\Log\LoggerInterface;

/** Identidad legible, sin sustituir los identificadores originales de auditoría. */
class ContextoAlertas {
	private array $personas = [];

	public function __construct(
		private IUserManager $usuarios,
		private IAccountManager $cuentas,
		private IGroupManager $grupos,
		private IURLGenerator $url,
		private LoggerInterface $logger,
	) {
	}

	/** @return array<string, mixed> */
	public function persona(string $id, string $tipo = 'users', bool $completa = true): array {
		$clave = $tipo . ':' . $id . ':' . (int)$completa;
		if (isset($this->personas[$clave])) {
			return $this->personas[$clave];
		}
		$ficha = ['id' => $id, 'tipo' => $tipo, 'nombre' => $id, 'usuario' => '', 'correo' => ''];
		try {
			$usuario = $tipo === 'users' && $id !== '' ? $this->usuarios->get($id) : null;
			if ($usuario !== null) {
				$ficha['nombre'] = $usuario->getDisplayName();
				$ficha['correo'] = $usuario->getEMailAddress() ?? '';
				$ficha['origen'] = $usuario->getBackendClassName();
				$ficha['usuario'] = $ficha['origen'] === 'LDAP' ? '' : $usuario->getUID();
				$ficha['perfil_url'] = $this->url->linkToRouteAbsolute('profile.ProfilePage.index', ['targetUserId' => $id]);
				if ($completa) {
					$ficha['estado'] = $usuario->isEnabled() ? 'habilitado' : 'deshabilitado';
					$ficha['grupos'] = array_values(array_map(
						fn ($g) => $g->getDisplayName(), $this->grupos->getUserGroups($usuario)));
					$cuenta = $this->cuentas->getAccount($usuario);
					foreach (['telefono' => IAccountManager::PROPERTY_PHONE,
						'organizacion' => IAccountManager::PROPERTY_ORGANISATION,
						'cargo' => IAccountManager::PROPERTY_ROLE] as $campo => $propiedad) {
						$ficha[$campo] = $cuenta->getProperty($propiedad)->getValue();
					}
				}
				if ($ficha['origen'] === 'LDAP') {
					// Servicio opcional: no se resuelve si user_ldap no está habilitada.
					$ldap = Server::get(ILDAPProvider::class);
					$ficha['usuario'] = $ldap->getUserAttribute($id, 'sAMAccountName')
						?: $ldap->getUserAttribute($id, 'uid')
						?: $ldap->getUserAttribute($id, 'userPrincipalName') ?: '';
					if ($completa) {
						foreach (['departamento' => 'department', 'cargo' => 'title',
							'organizacion' => 'company', 'telefono' => 'telephoneNumber'] as $campo => $atributo) {
							$ficha[$campo] = $ldap->getUserAttribute($id, $atributo) ?: ($ficha[$campo] ?? '');
						}
					}
				}
			}
		} catch (\Throwable $e) {
			// Un directorio no disponible no debe impedir registrar ni enviar la alerta.
			$this->logger->warning('GSI Talk Guard: no se pudo completar la ficha de usuario',
				['app' => Application::APP_ID, 'usuario' => $id, 'exception' => $e]);
		}
		// También se usa desde el worker persistente: memoria acotada.
		if (count($this->personas) >= 512) {
			$this->personas = [];
		}
		return $this->personas[$clave] = $ficha;
	}

	/** Conserva las fichas capturadas; en registros antiguos resuelve los datos actuales. */
	public function enriquecer(array $a): array {
		foreach (['autor', 'solicitante'] as $campo) {
			if (!empty($a[$campo]) && !isset($a[$campo . '_ficha'])) {
				$a[$campo . '_ficha'] = $this->persona((string)$a[$campo],
					$campo === 'autor' ? (string)($a['tipo_autor'] ?? 'users') : 'users');
			}
		}
		if (!isset($a['participantes_fichas']) && is_array($a['participantes'] ?? null)) {
			$a['participantes_fichas'] = array_map(function ($actor): array {
				$actor = (string)$actor;
				// Los actores externos se almacenan como "tipo:id"; no son cuentas locales.
				[$tipo, $id] = str_contains($actor, ':') ? explode(':', $actor, 2) : ['users', $actor];
				return $this->persona($id, $tipo, false);
			}, $a['participantes']);
		}
		if (in_array($a['sala_tipo'] ?? '', ['uno_a_uno', 'antigua_uno_a_uno'], true)
			&& !isset($a['sala_nombre_original'])) {
			$ids = $a['participantes'] ?? [];
			if ($ids === [] && !empty($a['sala_nombre'])) {
				$ids = json_decode((string)$a['sala_nombre'], true);
				$ids = is_array($ids) ? $ids : array_map('trim', explode(',', (string)$a['sala_nombre']));
			}
			$nombres = !empty($a['participantes_fichas']) ? array_column($a['participantes_fichas'], 'nombre')
				: array_map(fn ($id) => $this->persona((string)$id, 'users', false)['nombre'], $ids);
			if ($nombres !== []) {
				$a['sala_nombre_original'] = $a['sala_nombre'] ?? '';
				$a['sala_nombre'] = implode(' — ', $nombres);
			}
		}
		if (!empty($a['sala_token']) && empty($a['sala_url'])) {
			$a['sala_url'] = $this->url->getAbsoluteURL('/index.php/call/' . rawurlencode((string)$a['sala_token']));
		}
		return $a;
	}
}
