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
				$t->addColumn('clave_id', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => '']);
			}
		}
		return $schema;
	}
}
