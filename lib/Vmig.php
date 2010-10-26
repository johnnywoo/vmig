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

	/**
	 * Locate renamed migrations
	 *
	 * @param &array $migrations_up   A reference to Migrations Up array
	 * @param &array $migrations_down A reference to Migrations Down array
	 * @return array $renamed_migrations Format: 'db_migration_name'=>'file_migration_name'
	 */
	private function _locate_renamed_migrations(&$migrations_down, &$migrations_up)
	{
		$renamed_migrations = array();

		$count = 0;
		foreach(array_reverse($migrations_down) as $name => $migration_down) // we need to reverse to get the right order "3..2..1..1..2..3";
		{
			$up_equiv = each($migrations_up);
			if($up_equiv == false) // if there are no appropriate migrations from files
			{
				break;
			}

			if(sha1($migration_down) != sha1($up_equiv['value'])) // if content differs
			{
				break;
			}
			elseif($name != $up_equiv['key']) // if content is equal, but name differs
			{
				$renamed_migrations[$name] = $up_equiv['key'];

				unset($migrations_down[$name]);
				unset($migrations_up[$up_equiv['key']]);
			}
			else
			{
				$count++; // count of equal migrations, that will be unset because nothing is changed before them - just renamed
			}
		}

		if(array_slice($migrations_up, 0, $count) === array_slice(array_reverse($migrations_down), 0, $count)) // remove the identical parts from up and down
		{
			$migrations_down = array_slice($migrations_down, 0, count($migrations_down) - $count);
			$migrations_up = array_slice($migrations_up, $count);
		}

		return $renamed_migrations;
	}

	private function _get_migrations_for_status()
	{
		$unnecessary_migrations = $this->_find_old_unnecessary_migrations();
		list($to_changed_db_migrations, $to_changed_file_migrations) = $this->_find_migrations_from_last_to_first_changed();
		$down_and_up_migrations = $this->_find_down_and_up_migrations();
		$not_applied_migrations = $this->_find_not_applied_migrations();

		$migrations_down = array();
		$migrations_down = array_merge($migrations_down, $unnecessary_migrations, $to_changed_db_migrations);
		ksort($migrations_down);
		$migrations_down = array_reverse($migrations_down);

		$migrations_up = array();
		$migrations_up = array_merge($migrations_up, $to_changed_file_migrations, $down_and_up_migrations, $not_applied_migrations);
		ksort($migrations_up);

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
		$not_applied_migrations = $this->_find_not_applied_migrations();
		$unnecessary_migrations = $this->_find_old_unnecessary_migrations();
		if(count($not_applied_migrations) || count($unnecessary_migrations))
		{
			echo "Not applied migrations found.\n";
			foreach($unnecessary_migrations as $name => $migration)
			{
				echo "down {$name} \n";
			}
			foreach($not_applied_migrations as $name => $migration)
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

		$old_migrations = $this->_find_old_unnecessary_migrations();
		foreach($old_migrations as $name => $migration)
		{
			$name = $this->get_db()->escape($name);
			$this->get_db()->query("DELETE FROM `{$this->config->migration_db}`.`{$this->config->migration_table}` WHERE name='{$name}';");
		}

		$migrations = $this->_find_not_applied_migrations();
		$changed_migrations = $this->_find_changed_migrations();
		$migrations = array_merge($migrations, $changed_migrations);

		$values = array();
		foreach($migrations as $file_name => $migration)
		{
			$file_name = $this->get_db()->escape($file_name);
			$migration = $this->get_db()->escape($migration);
			$sha1 = $this->get_db()->escape(sha1_file($this->config->migrations_path . '/' . $file_name));
			$values[] = "('{$file_name}', '{$migration}', '{$sha1}')";
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

		echo "  done\n";
		return true;
	}


	function migrate()
	{
		$this->_create_migration_table_if_necessary();

		$unnecessary_migrations = $this->_find_old_unnecessary_migrations();
		list($to_changed_db_migrations, $to_changed_file_migrations) = $this->_find_migrations_from_last_to_first_changed();
		$down_and_up_migrations = $this->_find_down_and_up_migrations();
		$not_applied_migrations = $this->_find_not_applied_migrations();

		$migrations_down = array();
		$migrations_down = array_merge($migrations_down, $unnecessary_migrations, $to_changed_db_migrations);
		ksort($migrations_down);
		$migrations_down = array_reverse($migrations_down);

		$migrations_up = array();
		$migrations_up = array_merge($migrations_up, $to_changed_file_migrations, $down_and_up_migrations, $not_applied_migrations);
		ksort($migrations_up);

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
		$file_migration = $this->_get_migration_from_file_by_name($name);
		$db_migration   = $this->_get_migration_from_db_by_name($name);

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


	private function _get_migrations_from_files()
	{
		$path = $this->config->migrations_path;
		if(!file_exists($path) || !is_dir($path))
			return array();

		$files_migrations = scandir($path);
		foreach($files_migrations as $key => $file)
		{
			if(!is_file($path . '/' . $file))
				unset($files_migrations[$key]);
		}

		return $files_migrations;
	}


	private function _get_migrations_from_db($desc = false, $with_sha1 = false, $name = '', $sha1 = '')
	{
		$addition = 'ASC';
		if($desc)
		{
			$addition = 'DESC';
		}

		$condition = array();
		if($name)
		{
			$condition[] = "name='" . $this->get_db()->escape($name) . "'";
		}
		if($sha1)
		{
			$condition[] = "sha1='" . $this->get_db()->escape($sha1) . "'";
		}

		$condition = sizeof($condition) ? ' WHERE '.implode(' AND ', $condition) : '';

		$this->_create_migration_table_if_necessary();

		$r = $this->get_db()->query("SELECT `name`, `query`, `sha1` FROM `{$this->config->migration_db}`.`{$this->config->migration_table}` {$condition} ORDER BY `name` " . $addition);
		$db_migrations = array();
		while($row = $r->fetch_assoc())
		{
			if(!$with_sha1)
				$db_migrations[$row['name']] = $row['query'];
			else
			{
				$db_migrations[$row['name']] = array(
					'query' => $row['query'],
					'sha1'  => $row['sha1'],
				);
			}
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


	private function _find_not_applied_migrations()
	{
		$files_migrations = $this->_get_migrations_from_files();
		if(!$files_migrations)
			return array();

		$db_migrations = $this->_get_migrations_from_db();
		$db_migration_names = array_keys($db_migrations);

		$diff = array_diff($files_migrations, $db_migration_names);

		$migrations = array();
		$path = $this->config->migrations_path;
		foreach($diff as $migration_name)
		{
			$migrations[$migration_name] = file_get_contents($path . '/' . $migration_name);
		}

		return $migrations;
	}


	private function _find_old_unnecessary_migrations()
	{
		$first_migration = $this->_find_migration_for_down();

		if(!$first_migration)
			return array();

		$migrations = $this->_find_migrations_from_last_to_specified_from_db($first_migration);

		return $migrations;
	}


	private function _find_down_and_up_migrations()
	{
		$first_migration = $this->_find_migration_for_down();
		if(!$first_migration)
			return array();

		$migrations = $this->_find_migrations_from_last_to_specified_from_db($first_migration);
		$migrations = array_reverse($migrations);

		$files_migrations = $this->_get_migrations_from_files();
		$files_migrations = array_flip($files_migrations);
		$migrations = array_intersect_key($migrations, $files_migrations);

		return $migrations;
	}


	private function _find_migration_for_down()
	{
		$db_migrations = $this->_get_migrations_from_db();
		if(!$db_migrations)
			return false;

		$files_migrations = array_values($this->_get_migrations_from_files());
		$db_migration_names = array_keys($db_migrations);

		$first_migration = false;
		foreach($db_migration_names as $key => $name)
		{
			if(!isset($files_migrations[$key]) || $files_migrations[$key] != $name)
			{
				$first_migration = $name;
				break;
			}
		}

		return $first_migration;
	}


	private function _find_changed_migrations()
	{
		$db_migrations = $this->_get_migrations_from_db(false, true);
		if(!$db_migrations)
			return array();

		$files_migrations = $this->_get_migrations_from_files();
		$migrations = array();
		foreach($files_migrations as $file_name)
		{
			$file_sha1 = sha1_file($this->config->migrations_path . '/' . $file_name);
			if(key_exists($file_name, $db_migrations) && $file_sha1 != $db_migrations[$file_name]['sha1'])
				$migrations[$file_name] = $db_migrations[$file_name]['query'];
		}

		return $migrations;
	}


	private function _find_migrations_from_last_to_first_changed()
	{
		$changed_migrations = $this->_find_changed_migrations();
		if(!count($changed_migrations))
			return array(array(), array());

		$migration_names = array_keys($changed_migrations);
		$first_changed_migration = array_shift($migration_names);
		$db_migrations = $this->_find_migrations_from_last_to_specified_from_db($first_changed_migration);
		$file_migrations = $this->_find_migrations_from_last_to_specified_from_files($first_changed_migration);
		return array($db_migrations, $file_migrations);
	}


	private function _find_migrations_from_last_to_specified_from_db($migration_name)
	{
		$db_migrations = $this->_get_migrations_from_db(true);
		$migrations = array();
		foreach($db_migrations as $name => $migration)
		{
			$migrations[$name] = $migration;

			if($name == $migration_name)
				break;
		}

		return $migrations;
	}


	private function _find_migrations_from_last_to_specified_from_files($migration_name)
	{
		$file_migrations = $this->_get_migrations_from_files();
		$migrations = array();
		foreach(array_reverse($file_migrations) as $name)
		{
			$migrations[$name] = file_get_contents($this->config->migrations_path . '/' . $name);

			if($name == $migration_name)
				break;
		}

		return $migrations;
	}


	private function _migrate_down($migrations)
	{
		foreach($migrations as $name => $migration)
		{
			$pos = strpos($migration, '-- Migration Down');
			$down_migration = substr($migration, $pos);

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
			$pos = strpos($migration, '-- Migration Down');
			$up_migration = substr($migration, 0, $pos);

			echo "\n-- up: {$name}\n\n";
			$this->_apply_migration($up_migration);
			$this->_approve_migration($name, $migration);
		}
	}


	private function _approve_migration($name, $migration)
	{
		$db = $this->get_db();

		$name      = $db->escape($name);
		$migration = $db->escape($migration);
		$sha1      = $db->escape(sha1_file($this->config->migrations_path . '/' . $name));

		$db->query("
			INSERT INTO `{$this->config->migration_db}`.`{$this->config->migration_table}` (name, query, sha1)
			VALUES ('{$name}', '{$migration}', '{$sha1}')
			ON DUPLICATE KEY UPDATE query = VALUES(query), sha1 = VALUES(sha1)
		");
	}

	/**
	 * Rename migration in db
	 * @param  $old_name
	 * @param  $new_name
	 */
	private function _rename_migrations($renamed_migrations = array())
	{
		$db = $this->get_db();

		foreach($renamed_migrations as $old_name => $new_name)
		{
			$old_name      = $db->escape($old_name);
			$new_name      = $db->escape($new_name);

			$msg = "\n--rename: {$old_name} -> {$new_name}\n\n";
			if(class_exists('PEAR') && class_exists('Console_Color'))
				$msg = Console_Color::convert($msg);
			else
				$msg = preg_replace('@%[ygrn]@', '', $msg);

			echo $msg;

			$db->query("UPDATE `{$this->config->migration_db}`.`{$this->config->migration_table}` SET `name`='{$new_name}' WHERE `name`='{$old_name}'");
		}

		if(count($renamed_migrations)) {
			echo "done.\n";
		}
	}


	private function _get_migration_from_file_by_name($name)
	{
		$migration_path = $this->config->migrations_path . '/' . $name;
		if(!file_exists($migration_path))
			return array();

		$migration = file_get_contents($migration_path);
		return array($name => $migration);
	}


	private function _get_migration_from_db_by_name($name)
	{
		return $this->_get_migrations_from_db(false, false, $name);
	}
}
