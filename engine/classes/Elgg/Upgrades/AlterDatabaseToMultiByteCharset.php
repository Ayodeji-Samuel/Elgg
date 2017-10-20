<?php

namespace Elgg\Upgrades;

use Elgg\Upgrade\Batch;
use Elgg\Upgrade\Result;
use League\Flysystem\Exception;

/**
 * Updates database charset to utf8mb4
 */
class AlterDatabaseToMultiByteCharset implements Batch {

	private $utf8mb4_tables = [
		// InnoDB
		'access_collection_membership',
		'access_collections',
		'annotations',
		'api_users',
		'config',
		'entities',
		'entity_relationships',
		'metadata',
		'private_settings',
		'queue',
		'river',
		'system_log',
		'users_remember_me_cookies',
		'users_sessions',
		// MEMORY
		'hmac_cache',
		'users_apisessions',
	];

	// Columns with utf8 encoding and utf8_general_ci collation
	// $table => [
	//   $column => $index
	// ]

	private $non_mb4_columns = [
		'config' => [
			'name' => [
				'primary' => true,
				'name' => 'name',
				'unique' => false,
			],
		],
		'queue' => [
			'name' => [
				'primary' => false,
				'name' => "name",
				'unique' => false,
			],
		],
		'users_sessions' => [
			'session' => [
				'primary' => true,
				'name' => 'session',
				'unique' => false,
			],
		],
		'hmac_cache' => [
			'hmac' => [
				'primary' => true,
				'name' => 'hmac',
				'unique' => false,
			],
		],
	];

	/**
	 * {@inheritdoc}
	 */
	public function getVersion() {
		return 2017080900;
	}

	/**
	 * {@inheritdoc}
	 */
	public function needsIncrementOffset() {
		return false;
	}

	/**
	 * {@inheritdoc}
	 */
	public function shouldBeSkipped() {

		$config = _elgg_services()->dbConfig->getConnectionConfig();
		$rows = get_data("SHOW TABLE STATUS FROM `{$config['database']}`");

		$prefixed_table_names = array_map(function ($t) use ($config) {
			return "{$config['prefix']}{$t}";
		}, $this->utf8mb4_tables);

		foreach ($rows as $row) {
			if (in_array($row->Name, $prefixed_table_names) && $row->Collation !== 'utf8mb4_general_ci') {
				return false;
			}
		}

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function countItems() {
		return 1;
	}

	/**
	 * {@inheritdoc}
	 */
	public function run(Result $result, $offset) {

		$config = _elgg_services()->dbConfig->getConnectionConfig();

		try {
			update_data("
				ALTER DATABASE
    			`{$config['database']}`
    			CHARACTER SET = utf8mb4
    			COLLATE = utf8mb4_unicode_ci
			");

			foreach ($this->utf8mb4_tables as $table) {
				if (!empty($this->non_mb4_columns[$table])) {
					foreach ($this->non_mb4_columns[$table] as $column => $index) {
						if ($index) {
							if ($index['primary']) {
								update_data("
									ALTER TABLE {$config['prefix']}{$table}
									DROP PRIMARY KEY
								");
							} else {
								update_data("
									ALTER TABLE {$config['prefix']}{$table}
									DROP KEY {$index['name']}
								");
							}
						}
					}
				}

				update_data("
					ALTER TABLE {$config['prefix']}{$table}
					CONVERT TO CHARACTER SET utf8mb4
					COLLATE utf8mb4_general_ci
				");

				if (!empty($this->non_mb4_columns[$table])) {
					foreach ($this->non_mb4_columns[$table] as $column => $index) {
						update_data("
							ALTER TABLE {$config['prefix']}{$table}
							MODIFY $column VARCHAR(255)
							CHARACTER SET utf8
							COLLATE utf8_unicode_ci
						");

						if (!$index) {
							continue;
						}

						$sql = "ADD";
						if ($index['unique']) {
							$sql .= " UNIQUE ({$index['name']})";
						} else if ($index['primary']) {
							$sql .= " PRIMARY KEY ({$index['name']})";
						} else {
							$sql .= " KEY {$index['name']} ($column)";
						}

						update_data("
							ALTER TABLE {$config['prefix']}{$table}
							$sql
						");
					}
				}
			}

		} catch (\Exception $e) {
			$result->addFailures();
			$result->addError($e->getMessage());
			return $result;
		}

		$result->addSuccesses();

		return $result;

	}

}
