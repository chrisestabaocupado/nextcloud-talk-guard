<?php

declare(strict_types=1);

namespace OCA\GsiTalkGuard\Service;

/**
 * Cambios puntuales sobre el JSON de reglas desde la interfaz web:
 * la acción de una regla y los dominios institucionales.
 * Todo cambio se valida con MotorReglas::interpretar() antes de guardarse.
 */
class EditorReglas {
	public function __construct(
		private Settings $settings,
	) {
	}

	/** @return array<string, mixed> */
	private function actual(): array {
		$json = $this->settings->reglasPersonalizadas() ?? Settings::reglasPredeterminadas();
		$datos = json_decode($json, true);
		if (!is_array($datos)) {
			throw new \InvalidArgumentException('Las reglas guardadas no son JSON válido. Restablézcalas con occ gsi-talk-guard:reglas:importar --predeterminadas.');
		}
		return $datos;
	}

	/** @param array<string, mixed> $datos */
	private function guardar(array $datos): void {
		$json = (string)json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		MotorReglas::interpretar($json); // lanza InvalidArgumentException si algo no es válido
		$this->settings->guardarReglas($json);
	}

	/** @return string la acción anterior */
	public function cambiarAccion(string $id, string $accion): string {
		if (!in_array($accion, ['alertar', 'enmascarar'], true)) {
			throw new \InvalidArgumentException('Acción inválida.');
		}
		$datos = $this->actual();
		foreach ($datos['reglas'] as $i => $regla) {
			if (($regla['id'] ?? '') === $id) {
				$anterior = (string)($regla['accion'] ?? 'alertar');
				$datos['reglas'][$i]['accion'] = $accion;
				$this->guardar($datos);
				return $anterior;
			}
		}
		throw new \InvalidArgumentException('No existe la regla ' . $id . '.');
	}

	/** @return list<string> */
	public function dominios(): array {
		return array_values(array_map('strval', $this->actual()['dominios_institucionales'] ?? []));
	}

	/** @param list<string> $dominios */
	public function cambiarDominios(array $dominios): void {
		$limpios = [];
		foreach ($dominios as $d) {
			$d = strtolower(trim((string)$d));
			if ($d === '') {
				continue;
			}
			if (!preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $d)) {
				throw new \InvalidArgumentException('Dominio inválido: ' . $d);
			}
			$limpios[$d] = true;
		}
		$datos = $this->actual();
		$datos['dominios_institucionales'] = array_keys($limpios);
		$this->guardar($datos);
	}
}
