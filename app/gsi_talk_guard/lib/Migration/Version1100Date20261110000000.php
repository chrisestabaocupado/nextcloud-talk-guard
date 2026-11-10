<?php

declare(strict_types=1);

namespace OCA\GsiTalkGuard\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Identificador de la clave usada por cada nueva evidencia cifrada. */
class Version1100Date20261110000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('gsitg_evidencias')) {
			$t = $schema->getTable('gsitg_evidencias');
			if (!$t->hasColumn('clave_id')) {
				// Las filas creadas antes de esta migración no tienen clave_id.
				// Nextcloud no permite añadir una columna STRING NOT NULL con
				// un valor por defecto vacío; NULL representa correctamente esas
				// evidencias antiguas y el lector ya lo admite.
				$t->addColumn('clave_id', Types::STRING, ['notnull' => false, 'length' => 32]);
			} else {
				// También corrige una columna creada parcialmente por un intento
				// anterior, si la base de datos llegó a conservarla como NOT NULL.
				$t->getColumn('clave_id')->setNotnull(false);
				$t->getColumn('clave_id')->setDefault(null);
			}
		}
		return $schema;
	}
}
