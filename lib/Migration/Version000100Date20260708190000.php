<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sclrit\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates oc_seclore_state — the authoritative protection state (SDD §6.1).
 */
class Version000100Date20260708190000 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if ($schema->hasTable('seclore_state')) {
			return null;
		}

		$table = $schema->createTable('seclore_state');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
		$table->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('seclore_file_id', Types::STRING, ['notnull' => false, 'length' => 128]);
		$table->addColumn('hot_folder_id', Types::STRING, ['notnull' => false, 'length' => 128]);
		$table->addColumn('policy_name', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('requested_by', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('attempts', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
		$table->addColumn('last_error', Types::TEXT, ['notnull' => false]);
		$table->addColumn('etag_before', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('request_id', Types::STRING, ['notnull' => false, 'length' => 32]);
		$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['file_id'], 'seclore_state_file_uniq');
		$table->addIndex(['status', 'updated_at'], 'seclore_state_status_upd');

		return $schema;
	}
}
