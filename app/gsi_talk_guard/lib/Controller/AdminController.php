<?php

declare(strict_types=1);

namespace OCA\GsiTalkGuard\Controller;

use OCA\GsiTalkGuard\AppInfo\Application;
use OCA\GsiTalkGuard\Service\Alertas;
use OCA\GsiTalkGuard\Service\EditorReglas;
use OCA\GsiTalkGuard\Service\Evidencias;
use OCA\GsiTalkGuard\Service\Hallazgo;
use OCA\GsiTalkGuard\Service\LectorAlertas;
use OCA\GsiTalkGuard\Service\MotorReglas;
use OCA\GsiTalkGuard\Service\Settings;
use OCA\GsiTalkGuard\Settings\Admin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * API de la página de administración. Accesible para administradores y para
 * los grupos a los que se delegue la sección "GSI Talk Guard".
 */
class AdminController extends Controller {
	public function __construct(
		IRequest $request,
		private Settings $settings,
		private MotorReglas $motor,
		private EditorReglas $editor,
		private LectorAlertas $lector,
		private Evidencias $evidencias,
		private Alertas $alertas,
		private IUserManager $usuarios,
		private IUserSession $sesion,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	// ------------------------------------------------------------------
	// Lectura
	// ------------------------------------------------------------------

	#[AuthorizedAdminSetting(settings: Admin::class)]
	#[FrontpageRoute(verb: 'GET', url: '/api/estado')]
	public function estado(): JSONResponse {
		$conteo = $this->lector->conteoPorRegla();
		$reglas = [];
		foreach ($this->motor->reglas() as $r) {
			$reglas[] = [
				'id' => $r->id,
				'nombre' => $r->nombre,
				'descripcion' => $r->descripcion,
				'descripcion_usuario' => $r->descripcionUsuario,
				'tipo' => $r->tipo,
				'definicion' => $r->definicion,
				'severidad' => $r->severidad,
				'accion' => $r->accion,
				'habilitada' => $r->habilitada,
				'eventos' => $r->eventos,
				'palabras' => $r->palabras,
				'umbral' => $r->umbral,
				'coincidencia' => $r->requiereTodasPalabras ? 'todas' : 'cualquiera',
				'mascara' => $r->mascara,
				'puede_enmascarar' => $r->tipo === 'regex' && $r->mascara !== null,
				'validar' => $r->validar,
				'excluir_dominios_institucionales' => $r->excluirInstitucionales,
				'reemplazo' => $r->reemplazo,
				'alertas_7_dias' => $conteo[$r->id] ?? 0,
			];
		}
		return new JSONResponse([
			'modo' => $this->settings->modo(),
			'usuarios_piloto' => $this->settings->usuariosPiloto(),
			'clave_publica' => $this->evidencias->disponible(),
			'avisar_usuario' => $this->settings->avisarUsuario(),
			'reglas_personalizadas' => $this->settings->reglasPersonalizadas() !== null,
			'archivo_alertas' => $this->settings->archivoAlertas(),
			'correo_para' => $this->settings->correoPara(),
			'severidad_minima_correo' => $this->settings->severidadMinimaCorreo(),
			'dominios' => $this->dominiosSeguros(),
			'reglas' => $reglas,
			'otras_reglas' => ['archivo_compartido', 'cambio_configuracion', 'acceso_evidencia'],
		]);
	}

	#[AuthorizedAdminSetting(settings: Admin::class)]
	#[FrontpageRoute(verb: 'GET', url: '/api/alertas')]
	public function alertas(int $dias = 1, string $severidad = 'baja', string $regla = '',
		string $autor = '', string $accion = '', int $pagina = 1, int $porPagina = 50): JSONResponse {
		return new JSONResponse($this->lector->consultar($dias, [
			'severidad' => $severidad, 'regla' => $regla, 'autor' => $autor, 'accion' => $accion,
		], $pagina, $porPagina));
	}

	#[AuthorizedAdminSetting(settings: Admin::class)]
	#[FrontpageRoute(verb: 'POST', url: '/api/probar')]
	public function probar(string $texto = '', string $evento = 'mensaje'): JSONResponse {
		$texto = mb_substr($texto, 0, 32000);
		if (!in_array($evento, ['mensaje', 'edicion', 'archivo'], true)) {
			return $this->error('Tipo de evento inválido.');
		}
		$hallazgos = $this->motor->evaluar($texto, $evento);
		$enmascarado = $this->motor->enmascarar($texto, array_map(fn (Hallazgo $h) => $h->regla, $hallazgos));
		return new JSONResponse([
			'hallazgos' => array_map(fn (Hallazgo $h) => [
				'regla_id' => $h->regla->id,
				'regla' => $h->regla->nombre,
				'severidad' => $h->regla->severidad,
				'accion' => $h->regla->accion,
				'fragmentos' => $h->fragmentos(),
			], $hallazgos),
			'enmascarado' => $enmascarado !== $texto ? $enmascarado : null,
			'se_aplicaria' => $this->settings->modo() === Settings::MODO_NORMAL && $this->evidencias->disponible(),
		]);
	}

	// ------------------------------------------------------------------
	// Cambios (cada uno queda registrado como alerta)
	// ------------------------------------------------------------------

	#[AuthorizedAdminSetting(settings: Admin::class)]
	#[FrontpageRoute(verb: 'POST', url: '/api/modo')]
	public function modo(string $modo): JSONResponse {
		if (!in_array($modo, Settings::MODOS, true)) {
			return $this->error('Modo inválido.');
		}
		$anterior = $this->settings->modo();
		if ($anterior !== $modo) {
			$this->settings->cambiarModo($modo);
			$this->registrar("modo: $anterior -> $modo", $modo === Settings::MODO_NORMAL ? 'media' : 'alta');
		}
		return new JSONResponse(['ok' => true]);
	}

	#[AuthorizedAdminSetting(settings: Admin::class)]
	#[FrontpageRoute(verb: 'POST', url: '/api/config')]
	public function config(array $usuarios_piloto = [], array $correo_para = [],
		string $severidad_minima_correo = 'media', bool $avisar_usuario = true): JSONResponse {
		$piloto = $this->limpiarLista($usuarios_piloto);
		$desconocidos = array_values(array_filter($piloto, fn ($u) => !$this->usuarios->userExists($u)));
		if ($desconocidos !== []) {
			return $this->error('Estos usuarios no existen en Nextcloud: ' . implode(', ', $desconocidos)
				. '. Use el identificador interno (campo user_id de occ user:info).');
		}
		$correos = $this->limpiarLista($correo_para);
		foreach ($correos as $c) {
			if (!filter_var($c, FILTER_VALIDATE_EMAIL)) {
				return $this->error('Correo inválido: ' . $c);
			}
		}
		if (!isset(Settings::SEVERIDADES[$severidad_minima_correo])) {
			return $this->error('Severidad inválida.');
		}

		$cambios = [];
		if ($piloto !== $this->settings->usuariosPiloto()) {
			$cambios[] = 'usuarios del piloto: ' . ($piloto === [] ? 'todos' : count($piloto) . ' usuarios');
			$this->settings->guardarUsuariosPiloto($piloto);
		}
		if ($correos !== $this->settings->correoPara()) {
			$cambios[] = 'correo: ' . ($correos === [] ? 'desactivado' : implode(', ', $correos));
			$this->settings->guardarCorreoPara($correos);
		}
		if ($severidad_minima_correo !== $this->settings->severidadMinimaCorreo()) {
			$cambios[] = 'severidad mínima del correo: ' . $severidad_minima_correo;
			$this->settings->guardarSeveridadMinimaCorreo($severidad_minima_correo);
		}
		if ($avisar_usuario !== $this->settings->avisarUsuario()) {
			$cambios[] = 'avisar al usuario: ' . ($avisar_usuario ? 'sí' : 'no');
			$this->settings->guardarAvisarUsuario($avisar_usuario);
		}
		if ($cambios !== []) {
			$this->registrar(implode('; ', $cambios), 'media');
		}
		return new JSONResponse(['ok' => true, 'cambios' => count($cambios)]);
	}

	#[AuthorizedAdminSetting(settings: Admin::class)]
	#[FrontpageRoute(verb: 'POST', url: '/api/dominios')]
	public function dominios(array $dominios = []): JSONResponse {
		try {
			$anteriores = $this->editor->dominios();
			$this->editor->cambiarDominios(array_map('strval', $dominios));
			$nuevos = $this->editor->dominios();
		} catch (\InvalidArgumentException $e) {
			return $this->error($e->getMessage());
		}
		if ($anteriores !== $nuevos) {
			$this->registrar('dominios institucionales: ' . implode(', ', $nuevos), 'media');
		}
		return new JSONResponse(['ok' => true, 'dominios' => $nuevos]);
	}

	#[AuthorizedAdminSetting(settings: Admin::class)]
	#[FrontpageRoute(verb: 'POST', url: '/api/regla')]
	public function regla(string $id, string $accion): JSONResponse {
		if ($accion === 'enmascarar' && !$this->evidencias->disponible()) {
			return $this->error('No hay clave pública de evidencias. Genérela primero con occ gsi-talk-guard:claves:generar.');
		}
		try {
			$anterior = $this->editor->cambiarAccion($id, $accion);
		} catch (\InvalidArgumentException $e) {
			return $this->error($e->getMessage());
		}
		if ($anterior !== $accion) {
			$this->registrar("regla $id: $anterior -> $accion", $accion === 'alertar' ? 'alta' : 'media');
		}
		return new JSONResponse(['ok' => true]);
	}

	#[AuthorizedAdminSetting(settings: Admin::class)]
	#[FrontpageRoute(verb: 'POST', url: '/api/regla/guardar')]
	public function guardarRegla(array $regla, string $id_original = ''): JSONResponse {
		$id = trim((string)($regla['id'] ?? ''));
		$nombre = trim((string)($regla['nombre'] ?? ''));
		if ($nombre === '' || $id === '') {
			return $this->error('Indique un nombre para la regla.');
		}
		if (($regla['accion'] ?? 'alertar') === 'enmascarar' && !$this->evidencias->disponible()) {
			return $this->error('Configure primero la clave de evidencias desde el servidor.');
		}
		try {
			$this->editor->guardarRegla($regla, $id_original !== '' ? $id_original : null);
		} catch (\InvalidArgumentException $e) {
			return $this->error($e->getMessage());
		}
		$this->registrar('regla guardada: ' . $id . ' (' . $nombre . ')', 'media');
		return new JSONResponse(['ok' => true]);
	}

	#[AuthorizedAdminSetting(settings: Admin::class)]
	#[FrontpageRoute(verb: 'POST', url: '/api/regla/eliminar')]
	public function eliminarRegla(string $id): JSONResponse {
		try {
			$this->editor->eliminarRegla($id);
		} catch (\InvalidArgumentException $e) {
			return $this->error($e->getMessage());
		}
		$this->registrar('regla eliminada: ' . $id, 'media');
		return new JSONResponse(['ok' => true]);
	}

	// ------------------------------------------------------------------

	private function registrar(string $detalle, string $severidad): void {
		$usuario = $this->sesion->getUser()?->getUID() ?? 'desconocido';
		$this->alertas->emitir([
			'alerta_id' => 'config-' . time() . '-' . bin2hex(random_bytes(3)),
			'evento' => 'configuracion',
			'regla_id' => 'cambio_configuracion',
			'regla' => 'Cambio de configuración desde la web',
			'severidad' => $severidad,
			'accion' => 'configuracion',
			'autor' => $usuario,
			'detalle' => $detalle,
			'ip' => $this->request->getRemoteAddress(),
		]);
	}

	/** @return list<string> */
	private function limpiarLista(array $valores): array {
		$salida = [];
		foreach ($valores as $v) {
			$v = trim((string)$v);
			if ($v !== '' && !in_array($v, $salida, true)) {
				$salida[] = $v;
			}
		}
		return $salida;
	}

	/** @return list<string> */
	private function dominiosSeguros(): array {
		try {
			return $this->editor->dominios();
		} catch (\Throwable) {
			return [];
		}
	}

	private function error(string $mensaje): JSONResponse {
		return new JSONResponse(['ok' => false, 'error' => $mensaje], Http::STATUS_BAD_REQUEST);
	}
}
