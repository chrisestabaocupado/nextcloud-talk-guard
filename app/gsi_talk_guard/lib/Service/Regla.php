<?php

declare(strict_types=1);

namespace OCA\GsiTalkGuard\Service;

/** Regla de detección ya validada y compilada. */
final class Regla {
	public function __construct(
		public readonly string $id,
		public readonly string $nombre,
		public readonly string $tipo,          // regex | palabras
		public readonly string $patron,        // expresión PCRE compilable
		public readonly string $severidad,
		public readonly int $umbral,
		public readonly string $accion,        // alertar | enmascarar
		public readonly ?string $mascara,      // digitos | parcial | total | null
		public readonly ?string $reemplazo,
		public readonly ?string $validar,      // luhn | null
		public readonly bool $excluirInstitucionales,
		public readonly string $descripcionUsuario,
	) {
	}

	public function puedeEnmascarar(): bool {
		return $this->accion === 'enmascarar' && $this->tipo === 'regex' && $this->mascara !== null;
	}
}
