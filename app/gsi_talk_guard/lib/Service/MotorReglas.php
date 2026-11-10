<?php

declare(strict_types=1);

namespace OCA\GsiTalkGuard\Service;

use Psr\Log\LoggerInterface;

/**
 * Carga, valida y aplica las reglas de detección.
 * Las reglas de tipo "regex" se evalúan sobre el texto original (y pueden
 * enmascarar). Las de tipo "palabras" se evalúan sobre el texto en minúsculas
 * y sin acentos, y solo alertan.
 */
class MotorReglas {
	private const SEVERIDADES = ['baja', 'media', 'alta', 'critica'];
	private const MASCARAS = ['digitos', 'parcial', 'total'];

	/** @var list<Regla>|null */
	private ?array $reglas = null;
	/** @var list<string> */
	private array $dominios = [];
	private string $archivoSevBase = 'baja';
	private string $archivoSevSensible = 'media';
	/** @var list<string> */
	private array $archivoPalabras = [];

	public function __construct(
		private Settings $settings,
		private LoggerInterface $logger,
	) {
	}

	// ------------------------------------------------------------------
	// Carga
	// ------------------------------------------------------------------

	private function cargar(): void {
		if ($this->reglas !== null) {
			return;
		}
		$json = $this->settings->reglasPersonalizadas() ?? Settings::reglasPredeterminadas();
		try {
			$this->aplicar(self::interpretar($json));
		} catch (\Throwable $e) {
			$this->logger->error('GSI Talk Guard: reglas inválidas, se usan las predeterminadas: ' . $e->getMessage(),
				['app' => 'gsi_talk_guard']);
			try {
				$this->aplicar(self::interpretar(Settings::reglasPredeterminadas()));
			} catch (\Throwable $e2) {
				$this->logger->error('GSI Talk Guard: las reglas predeterminadas tampoco cargan: ' . $e2->getMessage(),
					['app' => 'gsi_talk_guard']);
				$this->reglas = [];
			}
		}
	}

	/** @param array{reglas: list<Regla>, dominios: list<string>, archivos: array} $datos */
	private function aplicar(array $datos): void {
		$this->reglas = $datos['reglas'];
		$this->dominios = $datos['dominios'];
		$this->archivoSevBase = $datos['archivos']['severidad_base'];
		$this->archivoSevSensible = $datos['archivos']['severidad_sensible'];
		$this->archivoPalabras = $datos['archivos']['palabras'];
	}

	/**
	 * Valida un JSON de reglas. Lanza InvalidArgumentException con un mensaje
	 * claro si algo está mal. Se usa al cargar y al importar.
	 *
	 * @return array{reglas: list<Regla>, dominios: list<string>, archivos: array}
	 */
	public static function interpretar(string $json): array {
		$datos = json_decode($json, true);
		if (!is_array($datos)) {
			throw new \InvalidArgumentException('El archivo no es JSON válido: ' . json_last_error_msg());
		}
		if (!isset($datos['reglas']) || !is_array($datos['reglas'])) {
			throw new \InvalidArgumentException('Falta la lista "reglas".');
		}

		$reglas = [];
		$ids = [];
		foreach ($datos['reglas'] as $i => $r) {
			$donde = 'regla ' . ($r['id'] ?? '#' . $i);
			foreach (['id', 'nombre', 'tipo', 'severidad'] as $campo) {
				if (!isset($r[$campo]) || !is_string($r[$campo]) || $r[$campo] === '') {
					throw new \InvalidArgumentException("$donde: falta el campo \"$campo\".");
				}
			}
			if (isset($ids[$r['id']])) {
				throw new \InvalidArgumentException("$donde: id repetido.");
			}
			$ids[$r['id']] = true;
			if (!preg_match('/^[a-z0-9_]{1,40}$/', $r['id'])) {
				throw new \InvalidArgumentException("$donde: el id solo admite minúsculas, números y _.");
			}
			if (!in_array($r['severidad'], self::SEVERIDADES, true)) {
				throw new \InvalidArgumentException("$donde: severidad inválida ({$r['severidad']}).");
			}
			$accion = $r['accion'] ?? 'alertar';
			if (!in_array($accion, ['alertar', 'enmascarar'], true)) {
				throw new \InvalidArgumentException("$donde: accion debe ser alertar o enmascarar.");
			}
			$mascara = $r['mascara'] ?? null;
			if ($mascara !== null && !in_array($mascara, self::MASCARAS, true)) {
				throw new \InvalidArgumentException("$donde: mascara debe ser digitos, parcial o total.");
			}
			$validar = $r['validar'] ?? null;
			if ($validar !== null && $validar !== 'luhn') {
				throw new \InvalidArgumentException("$donde: validar solo admite luhn.");
			}

			if ($r['tipo'] === 'regex') {
				if (!isset($r['patron']) || !is_string($r['patron'])) {
					throw new \InvalidArgumentException("$donde: falta \"patron\".");
				}
				$patron = self::compilar($r['patron']);
			} elseif ($r['tipo'] === 'palabras') {
				if (!isset($r['palabras']) || !is_array($r['palabras']) || $r['palabras'] === []) {
					throw new \InvalidArgumentException("$donde: falta la lista \"palabras\".");
				}
				if ($accion === 'enmascarar') {
					throw new \InvalidArgumentException("$donde: las reglas de palabras solo pueden alertar.");
				}
				$frases = array_unique(array_map(fn ($p) => self::normalizar((string)$p), $r['palabras']));
				usort($frases, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
				$alternativas = implode('|', array_map(fn ($f) => preg_quote($f, '~'), $frases));
				$patron = '~(?<![\w])(?:' . $alternativas . ')(?![\w])~u';
			} else {
				throw new \InvalidArgumentException("$donde: tipo debe ser regex o palabras.");
			}
			if ($accion === 'enmascarar' && $mascara === null) {
				throw new \InvalidArgumentException("$donde: para enmascarar hay que indicar \"mascara\".");
			}

			$reglas[] = new Regla(
				id: $r['id'],
				nombre: $r['nombre'],
				tipo: $r['tipo'],
				patron: $patron,
				severidad: $r['severidad'],
				umbral: max(1, (int)($r['umbral'] ?? 1)),
				accion: $accion,
				mascara: $mascara,
				reemplazo: isset($r['reemplazo']) ? (string)$r['reemplazo'] : null,
				validar: $validar,
				excluirInstitucionales: (bool)($r['excluir_dominios_institucionales'] ?? false),
				descripcionUsuario: (string)($r['descripcion_usuario'] ?? mb_strtolower($r['nombre'])),
			);
		}

		$archivos = $datos['archivos'] ?? [];
		$sevBase = $archivos['severidad_base'] ?? 'baja';
		$sevSensible = $archivos['severidad_nombre_sensible'] ?? 'media';
		foreach ([$sevBase, $sevSensible] as $s) {
			if (!in_array($s, self::SEVERIDADES, true)) {
				throw new \InvalidArgumentException("archivos: severidad inválida ($s).");
			}
		}

		return [
			'reglas' => $reglas,
			'dominios' => array_values(array_map(fn ($d) => self::normalizar((string)$d), $datos['dominios_institucionales'] ?? [])),
			'archivos' => [
				'severidad_base' => $sevBase,
				'severidad_sensible' => $sevSensible,
				'palabras' => array_values(array_map(fn ($p) => self::normalizar((string)$p), $archivos['palabras_nombre_sensible'] ?? [])),
			],
		];
	}

	private static function compilar(string $patron): string {
		// Las reglas usan sintaxis compatible con Python y PCRE; "~" como delimitador.
		$compilado = '~' . str_replace('~', '\~', $patron) . '~u';
		if (@preg_match($compilado, '') === false) {
			throw new \InvalidArgumentException('Expresión regular inválida: ' . $patron);
		}
		return $compilado;
	}

	// ------------------------------------------------------------------
	// Evaluación
	// ------------------------------------------------------------------

	/** @return list<Regla> */
	public function reglas(): array {
		$this->cargar();
		return $this->reglas;
	}

	/** @return list<Hallazgo> */
	public function evaluar(string $texto): array {
		$this->cargar();
		if ($texto === '') {
			return [];
		}
		$normal = null;
		$hallazgos = [];
		foreach ($this->reglas as $regla) {
			if ($regla->tipo === 'palabras') {
				$normal ??= self::normalizar($texto);
				$objetivo = $normal;
			} else {
				$objetivo = $texto;
			}
			if (preg_match_all($regla->patron, $objetivo, $m) === false) {
				continue;
			}
			$coincidencias = array_values(array_filter($m[0], fn ($c) => $this->cuenta($regla, $c)));
			if (count($coincidencias) >= $regla->umbral) {
				$hallazgos[] = new Hallazgo($regla, $coincidencias);
			}
		}
		return $hallazgos;
	}

	/**
	 * Enmascara en el texto las coincidencias de las reglas indicadas.
	 *
	 * @param list<Regla> $reglas
	 */
	public function enmascarar(string $texto, array $reglas): string {
		foreach ($reglas as $regla) {
			if (!$regla->puedeEnmascarar()) {
				continue;
			}
			$resultado = preg_replace_callback($regla->patron, function (array $m) use ($regla): string {
				return $this->cuenta($regla, $m[0])
					? self::enmascararValor($m[0], (string)$regla->mascara, $regla->reemplazo)
					: $m[0];
			}, $texto);
			if ($resultado !== null) {
				$texto = $resultado;
			}
		}
		return $texto;
	}

	/** ¿La coincidencia cuenta para la regla? (validación Luhn y dominios propios) */
	private function cuenta(Regla $regla, string $coincidencia): bool {
		if ($regla->validar === 'luhn' && !self::luhn($coincidencia)) {
			return false;
		}
		if ($regla->excluirInstitucionales && $this->esInstitucional($coincidencia)) {
			return false;
		}
		return true;
	}

	private function esInstitucional(string $coincidencia): bool {
		$texto = self::normalizar($coincidencia);
		if (str_contains($texto, '://')) {
			$host = (string)(parse_url($texto, PHP_URL_HOST) ?? '');
		} elseif (str_contains($texto, '@')) {
			$host = substr($texto, strrpos($texto, '@') + 1);
		} else {
			return false;
		}
		foreach ($this->dominios as $d) {
			if ($host === $d || str_ends_with($host, '.' . $d)) {
				return true;
			}
		}
		return false;
	}

	/** @return array{0: string, 1: list<string>} severidad y palabras sensibles encontradas */
	public function severidadArchivo(string $nombre): array {
		$this->cargar();
		$n = self::normalizar($nombre);
		$sensibles = array_values(array_filter($this->archivoPalabras, fn ($p) => str_contains($n, $p)));
		return [$sensibles !== [] ? $this->archivoSevSensible : $this->archivoSevBase, $sensibles];
	}

	// ------------------------------------------------------------------
	// Utilidades
	// ------------------------------------------------------------------

	public static function normalizar(string $texto): string {
		if (class_exists(\Normalizer::class)) {
			$descompuesto = \Normalizer::normalize($texto, \Normalizer::FORM_D);
			if ($descompuesto !== false) {
				$texto = (string)preg_replace('/\p{Mn}+/u', '', $descompuesto);
			}
		} else {
			$texto = strtr($texto, [
				'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
				'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
			]);
		}
		return mb_strtolower($texto);
	}

	public static function luhn(string $numero): bool {
		$digitos = preg_replace('/\D/', '', $numero) ?? '';
		$n = strlen($digitos);
		if ($n < 13 || $n > 19) {
			return false;
		}
		$suma = 0;
		for ($i = 0; $i < $n; $i++) {
			$d = (int)$digitos[$n - 1 - $i];
			if ($i % 2 === 1) {
				$d *= 2;
				if ($d > 9) {
					$d -= 9;
				}
			}
			$suma += $d;
		}
		return $suma % 10 === 0;
	}

	public static function enmascararValor(string $valor, string $mascara, ?string $reemplazo = null): string {
		$valor = trim($valor);
		switch ($mascara) {
			case 'total':
				return $reemplazo ?? '[dato oculto]';
			case 'digitos':
				$d = preg_replace('/\D/', '', $valor) ?? '';
				return strlen($d) >= 8 ? substr($d, 0, 4) . '****' . substr($d, -4) : '****';
			case 'parcial':
			default:
				$largo = mb_strlen($valor);
				if ($largo <= 4) {
					return str_repeat('*', $largo);
				}
				$visibles = max(2, intdiv($largo, 5));
				return mb_substr($valor, 0, $visibles) . str_repeat('*', $largo - 2 * $visibles) . mb_substr($valor, -$visibles);
		}
	}
}
