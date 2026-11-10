<?php

declare(strict_types=1);

namespace OCA\GsiTalkGuard\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Tablas de evidencias cifradas y registro de accesos a evidencias. */
class Version1000Date20261110000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('gsitg_evidencias')) {
			$t = $schema->createTable('gsitg_evidencias');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('alerta_id', Types::STRING, ['notnull' => true, 'length' => 128]);
			$t->addColumn('mensaje_id', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('sala_token', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('autor', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('huella', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('contenido', Types::TEXT, ['notnull' => true]);
			$t->addColumn('creado', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['alerta_id'], 'gsitg_ev_alerta');
		}

		if (!$schema->hasTable('gsitg_accesos')) {
			$t = $schema->createTable('gsitg_accesos');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('alerta_id', Types::STRING, ['notnull' => true, 'length' => 128]);
			$t->addColumn('caso', Types::STRING, ['notnull' => true, 'length' => 128]);
			$t->addColumn('solicitante', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('resultado', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('fecha', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
		}
		return $schema;
	}
}
