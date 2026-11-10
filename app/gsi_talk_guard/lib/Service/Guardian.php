<?php

declare(strict_types=1);

namespace OCA\GsiTalkGuard\Service;

use OCA\GsiTalkGuard\AppInfo\Application;
use OCA\Talk\Room;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Lógica principal. Flujo de un mensaje normal:
 *   1. antesDeGuardar(): evalúa reglas; si corresponde, enmascara el texto
 *      y recuerda el original (aún no tiene id).
 *   2. despuesDeGuardar(): con el id ya asignado, guarda la evidencia,
 *      emite las alertas y avisa al autor.
 * Los archivos compartidos y las ediciones llegan como mensajes de sistema.
 */
class Guardian {
	private const MAX_CHAT = 32000; // Room::MAX_CHAT_LENGTH en Talk 25

	/** Estado entre el evento "antes" y el "después" del mismo mensaje. */
	private static array $pendientes = [];

	private const TIPOS_SALA = [
		1 => 'uno_a_uno', 2 => 'grupo', 3 => 'publica', 4 => 'registro_cambios',
		5 => 'antigua_uno_a_uno', 6 => 'notas_personales',
	];

	public function __construct(
		private Settings $settings,
		private MotorReglas $motor,
		private Alertas $alertas,
		private Evidencias $evidencias,
		private IDBConnection $db,
		private ICommentsManager $comments,
		private INotificationManager $notificaciones,
		private IURLGenerator $url,
		private LoggerInterface $logger,
	) {
	}

	// ==================================================================
	// Mensajes normales
	// ==================================================================

	public function antesDeGuardar(Room $sala, IComment $mensaje): void {
		if (!$this->debeAnalizar($mensaje)) {
			return;
		}
		$original = $mensaje->getMessage();
		$hallazgos = $this->motor->evaluar($original);
		if ($hallazgos === []) {
			return;
		}
		[$texto, $enmascaradas, $nota] = $this->aplicarMascaras($sala, $original, $hallazgos);
		if ($texto !== $original) {
			$mensaje->setMessage($texto, self::MAX_CHAT);
		}
		self::$pendientes[spl_object_id($mensaje)] = [
			'original' => $original, 'hallazgos' => $hallazgos,
			'enmascaradas' => $enmascaradas, 'nota' => $nota,
		];
	}

	public function despuesDeGuardar(Room $sala, IComment $mensaje): void {
		$clave = spl_object_id($mensaje);
		$p = self::$pendientes[$clave] ?? null;
		unset(self::$pendientes[$clave]);
		if ($p === null) {
			return;
		}
		$this->reportar($sala, $mensaje, 'mensaje', (string)$mensaje->getId(),
			$p['original'], $p['hallazgos'], $p['enmascaradas'], $p['nota']);
	}

	// ==================================================================
	// Mensajes de sistema: archivos compartidos y ediciones
	// ==================================================================

	public function antesDeGuardarSistema(Room $sala, IComment $mensaje): void {
		$datos = json_decode($mensaje->getMessage(), true);
		if (!is_array($datos) || ($datos['message'] ?? '') !== 'file_shared' || !$this->debeAnalizar($mensaje)) {
			return;
		}
		$titulo = $datos['parameters']['metaData']['caption'] ?? null;
		if (!is_string($titulo) || $titulo === '') {
			return;
		}
		$hallazgos = $this->motor->evaluar($titulo);
		if ($hallazgos === []) {
			return;
		}
		[$nuevo, $enmascaradas, $nota] = $this->aplicarMascaras($sala, $titulo, $hallazgos);
		if ($nuevo !== $titulo) {
			$datos['parameters']['metaData']['caption'] = $nuevo;
			$mensaje->setMessage((string)json_encode($datos), self::MAX_CHAT);
		}
		self::$pendientes[spl_object_id($mensaje)] = [
			'original' => $titulo, 'hallazgos' => $hallazgos,
			'enmascaradas' => $enmascaradas, 'nota' => $nota,
		];
	}

	public function despuesDeGuardarSistema(Room $sala, IComment $mensaje): void {
		$clave = spl_object_id($mensaje);
		$p = self::$pendientes[$clave] ?? null;
		unset(self::$pendientes[$clave]);

		$datos = json_decode($mensaje->getMessage(), true);
		if (!is_array($datos)) {
			return;
		}
		switch ($datos['message'] ?? '') {
			case 'file_shared':
				if ($this->debeAnalizar($mensaje)) {
					$this->archivoCompartido($sala, $mensaje, $datos, $p);
				}
				break;
			case 'message_edited':
				$this->mensajeEditado($sala, $mensaje, $datos);
				break;
		}
	}

	private function archivoCompartido(Room $sala, IComment $mensaje, array $datos, ?array $p): void {
		$archivo = $this->nombreArchivo((string)($datos['parameters']['share'] ?? ''));
		[$severidad, $sensibles] = $this->motor->severidadArchivo($archivo);
		$id = (string)$mensaje->getId();
		$base = $this->base($sala, $mensaje, 'archivo', $id);
		$this->alertas->emitir($base + [
			'alerta_id' => $id . '-archivo_compartido',
			'regla_id' => 'archivo_compartido',
			'regla' => 'Archivo compartido en Talk' . ($sensibles !== [] ? ' con nombre sensible' : ''),
			'severidad' => $severidad,
			'accion' => 'alerta',
			'coincidencias' => count($sensibles),
			'fragmentos' => $sensibles,
			'archivo' => $archivo,
		]);
		if ($p !== null) { // el texto que acompaña al archivo tuvo coincidencias
			$this->reportar($sala, $mensaje, 'archivo', $id, $p['original'], $p['hallazgos'],
				$p['enmascaradas'], $p['nota'], $archivo);
		}
	}

	private function mensajeEditado(Room $sala, IComment $sistema, array $datos): void {
		$idEditado = (string)($datos['parameters']['message'] ?? '');
		if ($idEditado === '') {
			return;
		}
		try {
			$editado = $this->comments->get($idEditado);
		} catch (\Throwable) {
			return;
		}
		if (!$this->debeAnalizar($editado)) {
			return;
		}

		// El texto editable es el mensaje o, si es un archivo, su título.
		$json = null;
		if ($editado->getVerb() === 'object_shared') {
			$json = json_decode($editado->getMessage(), true);
			$original = is_array($json) ? (string)($json['parameters']['metaData']['caption'] ?? '') : '';
		} else {
			$original = $editado->getMessage();
		}
		$hallazgos = $this->motor->evaluar($original);
		if ($hallazgos === []) {
			return;
		}

		[$texto, $enmascaradas, $nota] = $this->aplicarMascaras($sala, $original, $hallazgos);
		if ($texto !== $original) {
			// La edición ya se guardó: se reescribe de inmediato con el dato oculto.
			if (is_array($json)) {
				$json['parameters']['metaData']['caption'] = $texto;
				$editado->setMessage((string)json_encode($json), self::MAX_CHAT);
			} else {
				$editado->setMessage($texto, self::MAX_CHAT);
			}
			$this->comments->save($editado);
		}
		$this->reportar($sala, $editado, 'edicion', $idEditado . '-e' . $sistema->getId(),
			$original, $hallazgos, $enmascaradas, $nota);
	}

	// ==================================================================
	// Piezas comunes
	// ==================================================================

	private function debeAnalizar(IComment $mensaje): bool {
		if ($this->settings->modo() === Settings::MODO_DESACTIVADO) {
			return false;
		}
		$piloto = $this->settings->usuariosPiloto();
		return $piloto === [] || in_array($mensaje->getActorId(), $piloto, true);
	}

	/**
	 * @param list<Hallazgo> $hallazgos
	 * @return array{0: string, 1: list<Regla>, 2: ?string} texto final, reglas enmascaradas, nota
	 */
	private function aplicarMascaras(Room $sala, string $texto, array $hallazgos): array {
		$candidatas = [];
		foreach ($hallazgos as $h) {
			if ($h->regla->puedeEnmascarar()) {
				$candidatas[] = $h->regla;
			}
		}
		if ($candidatas === []) {
			return [$texto, [], null];
		}
		if ($this->settings->modo() !== Settings::MODO_NORMAL) {
			return [$texto, [], 'modo solo_alertar: no se enmascaró'];
		}
		if (!$this->evidencias->disponible()) {
			// Sin clave pública el original se perdería: no se enmascara.
			$this->logger->warning('GSI Talk Guard: hay reglas para enmascarar pero no hay clave pública configurada.',
				['app' => Application::APP_ID]);
			return [$texto, [], 'sin clave publica: no se enmascaró'];
		}
		if ($sala->isFederatedConversation()) {
			return [$texto, [], 'sala federada: no se enmascaró'];
		}
		$nuevo = $this->motor->enmascarar($texto, $candidatas);
		return $nuevo === $texto ? [$texto, [], null] : [$nuevo, $candidatas, null];
	}

	/**
	 * Evidencia, alertas y aviso al autor.
	 *
	 * @param list<Hallazgo> $hallazgos
	 * @param list<Regla> $enmascaradas
	 */
	private function reportar(Room $sala, IComment $mensaje, string $evento, string $idBase,
		string $original, array $hallazgos, array $enmascaradas, ?string $nota, ?string $archivo = null): void {
		$idsEnmascaradas = array_map(fn (Regla $r) => $r->id, $enmascaradas);
		$base = $this->base($sala, $mensaje, $evento, (string)$mensaje->getId());

		$evidencia = false;
		$necesitaEvidencia = $enmascaradas !== [];
		foreach ($hallazgos as $h) {
			$necesitaEvidencia = $necesitaEvidencia
				|| Settings::SEVERIDADES[$h->regla->severidad] >= Settings::SEVERIDADES['alta'];
		}
		if ($necesitaEvidencia) {
			try {
				$evidencia = $this->evidencias->guardar($idBase, (int)$mensaje->getId(), $sala->getToken(),
					$mensaje->getActorId(), $original);
			} catch (\Throwable $e) {
				$this->logger->error('GSI Talk Guard: no se pudo guardar la evidencia: ' . $e->getMessage(),
					['app' => Application::APP_ID]);
			}
		}

		foreach ($hallazgos as $h) {
			$registro = $base + [
				'alerta_id' => $idBase . '-' . $h->regla->id,
				'regla_id' => $h->regla->id,
				'regla' => $h->regla->nombre,
				'severidad' => $h->regla->severidad,
				'accion' => in_array($h->regla->id, $idsEnmascaradas, true) ? 'enmascarado' : 'alerta',
				'coincidencias' => count($h->coincidencias),
				'fragmentos' => $h->fragmentos(),
				'archivo' => $archivo,
				'evidencia' => $evidencia ? $idBase : null,
				'huella_sha256' => hash('sha256', $original),
			];
			if ($nota !== null && $h->regla->puedeEnmascarar()) {
				$registro['nota'] = $nota;
			}
			$this->alertas->emitir($registro);
		}

		if ($enmascaradas !== [] && $mensaje->getActorType() === 'users' && $this->settings->avisarUsuario()) {
			$this->avisar($sala, $mensaje->getActorId(), $enmascaradas);
		}
	}

	/** @return array<string, mixed> */
	private function base(Room $sala, IComment $mensaje, string $evento, string $mensajeId): array {
		$participantes = $this->participantes($sala->getId());
		return [
			'evento' => $evento,
			'fecha_mensaje' => $mensaje->getCreationDateTime()->format(\DateTimeInterface::ATOM),
			'autor' => $mensaje->getActorId(),
			'tipo_autor' => $mensaje->getActorType(),
			'mensaje_id' => (int)$mensajeId,
			'sala_token' => $sala->getToken(),
			'sala_nombre' => $this->nombreSala($sala),
			'sala_tipo' => self::TIPOS_SALA[$sala->getType()] ?? (string)$sala->getType(),
			'participantes' => $participantes,
			'num_participantes' => count($participantes),
		];
	}

	/** En salas uno a uno Talk guarda como nombre la lista JSON de los dos usuarios. */
	private function nombreSala(Room $sala): string {
		$nombre = $sala->getName();
		if (in_array($sala->getType(), [1, 5], true)) {
			$ids = json_decode($nombre, true);
			if (is_array($ids)) {
				return implode(', ', array_map('strval', $ids));
			}
		}
		return $nombre !== '' ? $nombre : '(sin nombre)';
	}

	/** Texto para el aviso al autor: "tu conversación con X" o "la conversación «Y»". */
	private function salaParaAviso(Room $sala, string $autor): string {
		if (in_array($sala->getType(), [1, 5], true)) {
			$ids = json_decode($sala->getName(), true);
			$otros = is_array($ids) ? array_values(array_diff(array_map('strval', $ids), [$autor])) : [];
			return $otros !== [] ? 'tu conversación con ' . $otros[0] : 'una conversación privada';
		}
		return $sala->getName() !== '' ? 'la conversación «' . $sala->getName() . '»' : 'una conversación';
	}

	/** "a", "a y b", "a, b y c" */
	private static function enumerar(array $elementos): string {
		if (count($elementos) <= 1) {
			return (string)($elementos[0] ?? '');
		}
		$ultimo = array_pop($elementos);
		return implode(', ', $elementos) . ' y ' . $ultimo;
	}

	/** @return list<string> */
	private function participantes(int $salaId): array {
		$qb = $this->db->getQueryBuilder();
		$filas = $qb->select('actor_type', 'actor_id')->from('talk_attendees')
			->where($qb->expr()->eq('room_id', $qb->createNamedParameter($salaId, IQueryBuilder::PARAM_INT)))
			->orderBy('actor_id')
			->executeQuery()->fetchAll();
		return array_map(fn ($f) => $f['actor_type'] === 'users'
			? (string)$f['actor_id'] : $f['actor_type'] . ':' . $f['actor_id'], $filas);
	}

	private function nombreArchivo(string $shareId): string {
		if ($shareId === '' || !ctype_digit($shareId)) {
			return '(compartición no encontrada)';
		}
		$qb = $this->db->getQueryBuilder();
		$fila = $qb->select('file_target')->from('share')
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$shareId, IQueryBuilder::PARAM_INT)))
			->executeQuery()->fetch();
		if (!$fila) {
			return '(compartición no encontrada)';
		}
		// Talk guarda la carpeta de destino como marcador: {TALK_PLACEHOLDER}/archivo
		return ltrim(str_replace('{TALK_PLACEHOLDER}', '', (string)$fila['file_target']), '/');
	}

	/** @param list<Regla> $reglas */
	private function avisar(Room $sala, string $usuario, array $reglas): void {
		try {
			$descripciones = array_values(array_unique(array_map(fn (Regla $r) => $r->descripcionUsuario, $reglas)));
			$n = $this->notificaciones->createNotification();
			$n->setApp(Application::APP_ID)
				->setUser($usuario)
				->setDateTime(new \DateTime())
				->setObject('sala', $sala->getToken())
				->setSubject('mensaje_enmascarado', [
					'sala' => $this->salaParaAviso($sala, $usuario),
					'datos' => self::enumerar($descripciones),
					'token' => $sala->getToken(),
				]);
			$this->notificaciones->notify($n);
		} catch (\Throwable $e) {
			$this->logger->error('GSI Talk Guard: no se pudo avisar al usuario: ' . $e->getMessage(),
				['app' => Application::APP_ID]);
		}
	}
}
