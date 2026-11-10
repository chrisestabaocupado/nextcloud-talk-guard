<?php

declare(strict_types=1);

namespace OCA\GsiTalkGuard\Service;

/** Coincidencias de una regla en un texto. */
final class Hallazgo {
	/** @param list<string> $coincidencias */
	public function __construct(
		public readonly Regla $regla,
		public readonly array $coincidencias,
	) {
	}

	/** @return list<string> fragmentos aptos para una alerta (enmascarados si la regla lo indica) */
	public function fragmentos(int $maximo = 5): array {
		$salida = [];
		foreach (array_slice($this->coincidencias, 0, $maximo) as $c) {
			$salida[] = $this->regla->mascara !== null
				? MotorReglas::enmascararValor($c, $this->regla->mascara, $this->regla->reemplazo)
				: $c;
		}
		return $salida;
	}
}
