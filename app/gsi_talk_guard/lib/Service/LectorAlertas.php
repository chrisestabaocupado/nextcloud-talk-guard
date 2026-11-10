<?php

declare(strict_types=1);

namespace OCA\GsiTalkGuard\Service;

/**
 * Lee alertas.jsonl y sus copias rotadas (alertas.jsonl.1, .2.gz, ...)
 * para mostrarlas en la interfaz web. Con rotación diaria, la copia N
 * contiene aproximadamente el día N anterior.
 */
class LectorAlertas {
	public const DIAS_MAXIMOS = 31;
	private const ALERTAS_MAXIMAS = 50000; // tope de memoria por consulta

	public function __construct(
		private Settings $settings,
	) {
	}

	/**
	 * Alertas de los últimos $dias días, de la más reciente a la más antigua.
	 *
	 * @return array{alertas: list<array<string, mixed>>, truncado: bool}
	 */
	public function recientes(int $dias): array {
		$dias = max(1, min(self::DIAS_MAXIMOS, $dias));
		$desde = time() - $dias * 86400;
		$base = $this->settings->archivoAlertas();

		$archivos = [$base];
		for ($n = 1; $n <= $dias + 1; $n++) {
			$archivos[] = $base . '.' . $n;
			$archivos[] = $base . '.' . $n . '.gz';
		}

		$alertas = new \SplMinHeap();
		$truncado = false;
		$secuencia = 0;
		foreach ($archivos as $archivo) {
			if (!is_file($archivo) || !is_readable($archivo)) {
				continue;
			}
			$ruta = str_ends_with($archivo, '.gz') ? 'compress.zlib://' . $archivo : $archivo;
			$h = @fopen($ruta, 'rb');
			if ($h === false) {
				continue;
			}
			while (($linea = fgets($h)) !== false) {
				$a = json_decode($linea, true);
				if (!is_array($a) || !isset($a['detectado'])) {
					continue;
				}
				$t = strtotime((string)$a['detectado']);
				if ($t === false || $t < $desde) {
					continue;
				}
				$prioridad = [$t, $secuencia++, $a];
				if ($alertas->count() < self::ALERTAS_MAXIMAS) {
					$alertas->insert($prioridad);
				} else {
					$truncado = true;
					if ($t > $alertas->top()[0]) {
						$alertas->extract();
						$alertas->insert($prioridad);
					}
				}
			}
			fclose($h);
			if ($truncado) {
				break;
			}
		}

		$recientes = [];
		while (!$alertas->isEmpty()) {
			$recientes[] = $alertas->extract()[2];
		}
		usort($recientes, fn ($x, $y) => strcmp((string)$y['detectado'], (string)$x['detectado']));
		return ['alertas' => $recientes, 'truncado' => $truncado];
	}

	/**
	 * Consulta con filtros, resumen y paginación.
	 *
	 * @param array{severidad?: string, regla?: string, autor?: string, accion?: string} $filtros
	 * @return array<string, mixed>
	 */
	public function consultar(int $dias, array $filtros, int $pagina, int $porPagina): array {
		['alertas' => $todas, 'truncado' => $truncado] = $this->recientes($dias);

		// Resumen del período completo (sin filtros), para los indicadores.
		$porSeveridad = ['critica' => 0, 'alta' => 0, 'media' => 0, 'baja' => 0];
		$porRegla = [];
		$porAutor = [];
		foreach ($todas as $a) {
			$sev = (string)($a['severidad'] ?? '');
			if (isset($porSeveridad[$sev])) {
				$porSeveridad[$sev]++;
			}
			$regla = (string)($a['regla_id'] ?? '');
			$porRegla[$regla] = ($porRegla[$regla] ?? 0) + 1;
			$autor = (string)($a['autor'] ?? '');
			if ($autor !== '') {
				$porAutor[$autor] = ($porAutor[$autor] ?? 0) + 1;
			}
		}
		arsort($porRegla);
		arsort($porAutor);

		$minimo = Settings::SEVERIDADES[$filtros['severidad'] ?? 'baja'] ?? 1;
		$regla = $filtros['regla'] ?? '';
		$autor = mb_strtolower(trim($filtros['autor'] ?? ''));
		$accion = $filtros['accion'] ?? '';

		$filtradas = array_values(array_filter($todas, function (array $a) use ($minimo, $regla, $autor, $accion): bool {
			if ((Settings::SEVERIDADES[$a['severidad'] ?? ''] ?? 0) < $minimo) {
				return false;
			}
			if ($regla !== '' && ($a['regla_id'] ?? '') !== $regla) {
				return false;
			}
			if ($autor !== '' && !str_contains(mb_strtolower((string)($a['autor'] ?? '')), $autor)) {
				return false;
			}
			if ($accion !== '' && ($a['accion'] ?? '') !== $accion) {
				return false;
			}
			return true;
		}));

		$porPagina = max(10, min(200, $porPagina));
		$total = count($filtradas);
		$paginas = max(1, (int)ceil($total / $porPagina));
		$pagina = max(1, min($paginas, $pagina));

		return [
			'alertas' => array_slice($filtradas, ($pagina - 1) * $porPagina, $porPagina),
			'total' => $total,
			'pagina' => $pagina,
			'paginas' => $paginas,
			'truncado' => $truncado,
			'resumen' => [
				'total' => count($todas),
				'por_severidad' => $porSeveridad,
				'por_regla' => $porRegla,
				'autores' => array_slice($porAutor, 0, 10, true),
			],
		];
	}

	/** @return array<string, int> alertas por regla en los últimos 7 días */
	public function conteoPorRegla(): array {
		$conteo = [];
		foreach ($this->recientes(7)['alertas'] as $a) {
			$r = (string)($a['regla_id'] ?? '');
			$conteo[$r] = ($conteo[$r] ?? 0) + 1;
		}
		return $conteo;
	}
}
