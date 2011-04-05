<?

require_once dirname(__FILE__) . '/Vmig/Config.php';
require_once dirname(__FILE__) . '/Vmig/Dump.php';
require_once dirname(__FILE__) . '/Vmig/Error.php';
require_once dirname(__FILE__) . '/Vmig/Scheme.php';
require_once dirname(__FILE__) . '/Vmig/SchemesDiff.php';
require_once dirname(__FILE__) . '/Vmig/MysqlConnection.php';

class Vmig
{
	/** @var Vmig_Config */
	public $config;

	function __construct(Vmig_Config $config)
	{
		$this->config = $config;
	}

	function status()
	{
		$status_text = '';

		foreach($this->config->databases as $db)
		{
			$dump = $this->_create_dump($db);
			$scheme_from = new Vmig_Scheme($dump);

			$dump_file = "{$this->config->schemes_path}/{$db}.scheme.sql";
			$dump = file_exists($dump_file) ? file_get_contents($dump_file) : '';
			$scheme_to = new Vmig_Scheme($dump);

			$diff = new Vmig_SchemesDiff($scheme_from, $scheme_to, $db);
			$status = $diff->render_status_text($db);

			if($status)
				$status_text .= $status;
		}

		if($status_text)
			$status_text = "Database changes:".$status_text;

		$status_text .= $this->_get_migrations_for_status();

		if(!$this->config->no_color && class_exists('PEAR') && class_exists('Console_Color'))
			$status_text = Console_Color::convert($status_text);
		else
			$status_text = preg_replace('@%[ygrnm]@', '', $status_text);

		echo $status_text;
	}

	private function _get_migrations_for_status()
	{
		list($migrations_down, $migrations_up) = $this->_find_migrations();

		$renamed_migrations = $this->_locate_renamed_migrations($migrations_down, $migrations_up);

		if(!count($migrations_up) && !count($migrations_down) && !count($renamed_migrations))
			return '';

		$status = '';

		if(count($renamed_migrations))
		{
			$status .= "\nMigrations to be renamed:\n";
			foreach($renamed_migrations as $old_name => $new_name)
			{
				$status .= " %m{$old_name} -> {$new_name}%n\n";
			}
		}

		if(count($migrations_down) || count($migrations_up))
		{
			$status .= "\nMigrations to be applied:\n";
		}
		foreach($migrations_down as $migration_name => $migration)
		{
			$color = '%r';
			if(array_key_exists($migration_name, $migrations_up))
				$color = '%y';

			$status .= " {$color}down {$migration_name}%n\n";
		}

		foreach($migrations_up as $migration_name => $migration)
		{
			$color = '%g';
			if(array_key_exists($migration_name, $migrations_down))
				$color = '%y';

			$status .= " {$color}up   {$migration_name}%n\n";
		}

		return $status;
	}


	function reset_db($for_what = array())
	{
		$migrations = $this->_find_migrations_classified();
		if(count($migrations['not_applied']) || count($migrations['old_unnecessary']))
		{
			echo "Not applied migrations found.\n";
			foreach($migrations['old_unnecessary'] as $name => $migration)
			{
				echo "down {$name} \n";
			}
			foreach($migrations['not_applied'] as $name => $migration)
			{
				echo "up {$name} \n";
			}

			$answer = '';
			echo 'Reset anyway? (y/N) ';
			do
			{
				if(!empty($answer))
					echo 'Type "y" or "n" ';

				$answer = fgets(STDIN);
				$answer = rtrim(strtolower($answer));
				if($answer == "n" || $answer == "")
					return false;

			} while ($answer != "y");
		}

		$migration_down = $this->_create_migrations(false, $for_what);
		if(empty($migration_down))
			return false;

		$this->_apply_migration($migration_down);
	}


	public function create_migrations($force = false, $name_suffix = '')
	{
		$m_up = $this->_create_migrations();
		if(empty($m_up) && $force === false)
			return false;

		$migrations = "-- Migration Up\n\n";
		$migrations .= $m_up . "\n\n";
		$migrations .= "-- Migration Down\n\n";
		$migrations .= $this->_create_migrations(false) . "\n\n";

		if(!file_exists($this->config->migrations_path))
		{
			if(!mkdir($this->config->migrations_path, 0755, true)) // rwxr-xr-x
				throw new Vmig_Error('Unable to create migrations folder');
		}

		$migration_name = time() . $name_suffix . '.sql';
		$fname = $this->config->migrations_path . '/' . $migration_name;
		if(file_put_contents($fname, $migrations) === false)
			throw new Vmig_Error('Unable to write migration to "'.$fname.'"');

		return $migrations;
	}


	function diff($reverse = false)
	{
		return $this->_create_migrations(!$reverse);
	}


	function approve_migration()
	{
		$this->_create_migration_table_if_necessary();

		$migrations = $this->_find_migrations_classified();
		foreach($migrations['old_unnecessary'] as $name => $migration)
		{
			$name = $this->get_db()->escape($name);
			$this->get_db()->query("DELETE FROM `{$this->config->migration_db}`.`{$this->config->migration_table}` WHERE name='{$name}';");
		}

		$to_approve = array_merge($migrations['not_applied'], $migrations['changed']);

		$values = array();
		foreach($to_approve as $file_name => $migration)
		{
			$file_name = $this->get_db()->escape($file_name);
			$query     = $this->get_db()->escape($migration['query']);
			$sha1      = $this->get_db()->escape($migration['sha1']);
			$values[] = "('{$file_name}', '{$query}', '{$sha1}')";
		}

		if(count($values))
		{
			$values = implode(', ', $values);
			$this->get_db()->query("
				INSERT INTO `{$this->config->migration_db}`.`{$this->config->migration_table}` (name, query, sha1) VALUES {$values}
				ON DUPLICATE KEY UPDATE query=VALUES(query), sha1=VALUES(sha1);
			");
		}

		$this->_create_new_dumps();

		return true;
	}


	function migrate()
	{
		$this->_create_migration_table_if_necessary();

		list($migrations_down, $migrations_up) = $this->_find_migrations();

		$renamed_migrations = $this->_locate_renamed_migrations($migrations_down, $migrations_up);

		if(count($renamed_migrations))
			$this->_rename_migrations($renamed_migrations);
		if(count($migrations_down))
			$this->_migrate_down($migrations_down);
		if(count($migrations_up))
			$this->_migrate_up($migrations_up);

		if(count($migrations_down) || count($migrations_up) || count($renamed_migrations))
			echo "\n";

		return true;
	}


	public function apply_one_migration($name, $direction, $source)
	{
		$file_migration = $this->_get_migrations_from_files($name);
		$db_migration   = $this->_get_migrations_from_db($name);

		if(empty($source))
		{
			// if source is not given, but the file is equal to db version,
			// there's no need to choose between two
			if($file_migration == $db_migration)
				$file_migration = array();

			if(count($file_migration) && count($db_migration))
				throw new Vmig_Error("Db and file versions of {$name} are different.\nYou need to pick one with --from-file or --from-db option.");

			$source = count($file_migration) ? 'from-file' : 'from-db';
		}

		$migration = ($source == 'from-file') ? $file_migration : $db_migration;

		if(!count($migration))
			throw new Vmig_Error("Migration {$name} not found");

		if($direction == 'up')
			$this->_migrate_up($migration);
		else
			$this->_migrate_down($migration);
	}



	private $db;

	/**
	 * @return Vmig_MysqlConnection
	 */
	private function get_db()
	{
		if(!$this->db)
		{
			$this->db = new Vmig_MysqlConnection($this->config->connection);
		}
		return $this->db;
	}

	private function _create_new_dumps()
	{
		foreach($this->config->databases as $db)
		{
			$scheme_file = "{$this->config->schemes_path}/{$db}.scheme.sql";
			$this->_create_dump($db, $scheme_file);
		}

		return true;
	}


	function _create_migrations($up = true, $for_what = array())
	{
		if(!sizeof($for_what))
		{
			$databases = $this->config->databases;
		}
		else
		{
			$databases = array_intersect(array_keys($for_what), $this->config->databases); //pick only those DBs, that are present in config->databases
		}
		$scheme_from = array();
		$scheme_to = array();
		$migrations = array(
			'add_foreign_keys'  => array(),
			'drop_foreign_keys' => array(),
			'add_views'         => array(),
			'drop_views'        => array(),
			'alter_views'       => array(),
			'add_tables'        => array(),
			'drop_tables'       => array(),
			'alter_tables'      => array(),
		);

		foreach($databases as $db)
		{
			$dump = $this->_create_dump($db);
			$scheme_from = new Vmig_Scheme($dump);

			$dump_file = "{$this->config->schemes_path}/{$db}.scheme.sql";
			$dump = file_exists($dump_file) ? file_get_contents($dump_file) : '';
			$scheme_to = new Vmig_Scheme($dump);

			if(!$up)
				list($scheme_from, $scheme_to) = array($scheme_to, $scheme_from);

			if(!array_key_exists($db, $for_what))
			{
				$tables = array();
			}
			else
			{
				$tables = $for_what[$db];
			}
			$diff = new Vmig_SchemesDiff($scheme_from, $scheme_to, $db, $tables);
			$migration = $diff->render_migration();

			$migrations = array_merge_recursive($migrations, $migration);
		}

		$m_content = '';
		$m_content .= implode('', $migrations['drop_foreign_keys']);
		$m_content .= implode('', $migrations['drop_views']);
		$m_content .= implode('', $migrations['drop_tables']);
		$m_content .= implode('', $migrations['add_tables']);
		$m_content .= implode('', $migrations['alter_tables']);
		$m_content .= implode('', $migrations['add_views']);
		$m_content .= implode('', $migrations['alter_views']);
		$m_content .= implode('', $migrations['add_foreign_keys']);

		return $m_content;
	}


	/**
	 * @param string $url MySQL DSN
	 * @param string $dump_file if a file is given, the dump is written into it; otherwise it is returned
	 * @return string
	 */
	private function _create_dump($dbname, $dump_file = '')
	{
		// migrations table might be in a watched DB
		// so if it does not exist, it will be skipped on first run if we don't create it here
		$this->_create_migration_table_if_necessary();

		$dump = Vmig_Dump::create($this->get_db(), $dbname);

		if($dump_file != '')
			file_put_contents($dump_file, $dump->sql);

		return $dump->sql;
	}


	private function _apply_migration($migration)
	{
		$migration = preg_replace('@-- Migration (Down|Up)@', '', $migration);
		$migration = trim($migration)."\n";

		echo $migration;

		try
		{
			$this->get_db()->multi_query($migration);
		}
		catch(Vmig_MysqlError $e)
		{
			// ignore empty query error
			if($e->getCode() != Vmig_MysqlError::ER_EMPTY_QUERY)
			{
				throw $e;
			}
		}
	}

	private function _get_migrations_from_files($name = '')
	{
		$path = $this->config->migrations_path;
		if(!file_exists($path) || !is_dir($path))
			return array();

		$pattern = !empty($name) ? $name : '*';

		$file_migrations = array();
		foreach(glob($path . '/' . $pattern) as $file)
		{
			if(is_file($file))
			{
				$migration_query = file_get_contents($file);
				$fname = pathinfo($file, PATHINFO_BASENAME);
				$file_migrations[$fname] = array(
					'query' => $migration_query,
					'sha1'  => sha1($migration_query),
				);
			}
		}

		$file_migrations = array_reverse($file_migrations);
		return $file_migrations;
	}


	private function _get_migrations_from_db($name = '')
	{
		$addition = 'ASC';

		$condition = array();
		if($name)
		{
			$condition[] = "name='" . $this->get_db()->escape($name) . "'";
		}

		$condition = count($condition) ? ' WHERE '.implode(' AND ', $condition) : '';

		$this->_create_migration_table_if_necessary();

		$r = $this->get_db()->query("SELECT `name`, `query`, `sha1` FROM `{$this->config->migration_db}`.`{$this->config->migration_table}` {$condition} ORDER BY `name` " . $addition);
		$db_migrations = array();
		while($row = $r->fetch_assoc())
		{
			$db_migrations[$row['name']] = array(
				'query' => $row['query'],
				'sha1'  => $row['sha1'],
			);
		}
		$r->close();

		return $db_migrations;
	}


	private function _create_migration_table_if_necessary()
	{
		$this->get_db()->query("
			CREATE TABLE IF NOT EXISTS `{$this->config->migration_db}`.`{$this->config->migration_table}` (
				`id` int(11) NOT NULL auto_increment,
				`name` varchar(255) NOT NULL default '',
				`query` text NOT NULL,
				`sha1` VARCHAR(40) NOT NULL,
				PRIMARY KEY (`id`),
				UNIQUE KEY `name` (`name`)
			) ENGINE=InnoDB DEFAULT CHARSET=cp1251
		");
	}

	/**
	 * Locate renamed migrations
	 *
	 * @param &array $migrations_down A reference to Migrations Down array
	 * @param &array $migrations_up   A reference to Migrations Up array
	 * @return array $renamed_migrations Format: 'db_migration_name'=>'file_migration_name'
	 */
	private function _locate_renamed_migrations(&$migrations_down, &$migrations_up)
	{
		$renamed_migrations = array();

		foreach(array_reverse($migrations_down) as $name_down => $migration_down) // we need to reverse to get the right order: "down1..down2..down3" is compared with "up1..up2..up3";
		{
			if(!list($name_up, $migration_up) = each($migrations_up)) // if there are no appropriate migrations from files
				break;

			if($migration_down['sha1'] != $migration_up['sha1']) // if content differs
			{
				break;
			}
			elseif($name_down != $name_up) // if content is equal, but name differs
			{
				$renamed_migrations[$name_down] = $name_up;
			}
			unset($migrations_down[$name_down], $migrations_up[$name_up]);
		}
		return $renamed_migrations;
	}


	/**
	 * Get an actual migration list.
	 *
	 * @return array ($db_migrations, $file_migrations)
	 */
	private function _find_migrations()
	{
		$db_migrations = $this->_get_migrations_from_db();
		$file_migrations = $this->_get_migrations_from_files();

		foreach($db_migrations as $db_mig_name => $db_migration)
		{
			if(!list($file_mig_name, $file_migration) = each($file_migrations))
				break;

			if($db_mig_name == $file_mig_name && $db_migration['sha1'] == $file_migration['sha1'])
				unset($db_migrations[$db_mig_name], $file_migrations[$file_mig_name]);
			else
				break;
		}

		ksort($db_migrations);
		$db_migrations = array_reverse($db_migrations);
		ksort($file_migrations);

		return array($db_migrations, $file_migrations);
	}


	/**
	 * Get an actual migration list and classify it.
	 *
	 * @return array ($renamed, $old_unnecessary, $down_and_up, $changed, $not_applied)
	 */
	private function _find_migrations_classified()
	{
		list($db_migrations, $file_migrations) = $this->_find_migrations();

		$renamed = $this->_locate_renamed_migrations($db_migrations, $file_migrations);

		$not_applied = array();
		$old_unnecessary = array();
		$down_and_up = array();
		$changed = array();

		foreach($db_migrations as $migration_name => $migration)
		{
			if(array_key_exists($migration_name, $file_migrations))
			{
				if($file_migrations[$migration_name]['sha1'] != $migration['sha1'])
					$changed[$migration_name] = $file_migrations[$migration_name];
				else
					$down_and_up[$migration_name] = $migration;
			}
			else
			{
				$old_unnecessary[$migration_name] = $migration;
			}
		}

		foreach($file_migrations as $migration_name => $migration)
		{
			if(!array_key_exists($migration_name, $db_migrations))
				$not_applied[$migration_name] = $migration;
		}

		ksort($renamed);
		ksort($not_applied);
		ksort($old_unnecessary);
		ksort($down_and_up);
		ksort($changed);

		return array(
			'renamed'         => $renamed,          // renamed
			'old_unnecessary' => $old_unnecessary,  // present only in DB
			'down_and_up'     => $down_and_up,      // dependent on removed/changed/added
			'changed'         => $changed,          // hash differs
			'not_applied'     => $not_applied,      // present only in files
		);
	}


	private function _migrate_down($migrations)
	{
		foreach($migrations as $name => $migration)
		{
			$pos = strpos($migration['query'], '-- Migration Down');
			$down_migration = substr($migration['query'], $pos);

			echo "\n-- down: {$name}\n\n";

			if($this->config->fail_on_down)
				throw new Vmig_Error('Down migrations are forbidden by fail-on-down configuration option');

			$this->_apply_migration($down_migration);
			$name = $this->get_db()->escape($name);
			$this->get_db()->query("DELETE FROM `{$this->config->migration_db}`.`{$this->config->migration_table}` WHERE name = '{$name}'");
		}
	}


	private function _migrate_up($migrations)
	{
		foreach($migrations as $name => $migration)
		{
			$pos = strpos($migration['query'], '-- Migration Down');
			$up_migration = substr($migration['query'], 0, $pos);

			echo "\n-- up: {$name}\n\n";
			$this->_apply_migration($up_migration);
			$this->_approve_migration($name, $migration);
		}
	}


	private function _approve_migration($name, $migration)
	{
		$db = $this->get_db();

		$name  = $db->escape($name);
		$query = $db->escape($migration['query']);
		$sha1  = $db->escape($migration['sha1']);

		$db->query("
			INSERT INTO `{$this->config->migration_db}`.`{$this->config->migration_table}` (name, query, sha1)
			VALUES ('{$name}', '{$query}', '{$sha1}')
			ON DUPLICATE KEY UPDATE query = VALUES(query), sha1 = VALUES(sha1)
		");
	}

	/**
	 * Rename migrations in db
	 *
	 * @param array $renamed_migrations ($old_name => $new_name)
	 */
	private function _rename_migrations($renamed_migrations = array())
	{
		$db = $this->get_db();

		foreach($renamed_migrations as $old_name => $new_name)
		{
			echo "\n-- rename: {$old_name} -> {$new_name}\n\n";

			$old_name = $db->escape($old_name);
			$new_name = $db->escape($new_name);

			$db->query("UPDATE `{$this->config->migration_db}`.`{$this->config->migration_table}` SET `name` = '{$new_name}' WHERE `name` = '{$old_name}'");
		}
	}
}
