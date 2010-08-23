<?php

define('EXIT_OK',       0);
define('EXIT_MODIFIED', 1);
define('EXIT_ERROR',    2);

$config_file = dirname(__FILE__).'/migrations.conf';

$params = $argv;
array_shift($params);

// no command = print usage and die
if(empty($params[0]))
{
	$f = basename(__FILE__);
	fwrite(STDERR, "
Migrations config is read from 'migrations.conf'.
Config parameters:

  migrations_table = dbname.tblname
    Required: table name for migration data. The table will be created if not exists;
    database should exist.

  migrations_path = path
    Required: path to migrations folder. Relative to dirname of config.

  schemes_path = path
    Required: path to schemes folder. Relative to dirname of config.

  databases = dbname dbname dbname
    Required: list of database names which are watched for migrations.

  database = mysql://user:pass@host:port
    Optional: MySQL connection. Default parameters are read from mysql console client (essentially from .my.cnf).
    You may review them by running 'mysql --print-defaults'.

Available commands:

  php $f diff [--reverse]
    Compares dump and database, exits with status 1 if changes present;
    prints found differences as SQL (commands to convert dumped scheme into actual).
    Options:
      --reverse   invert diff direction; print commands to convert actual scheme into dumped

  php $f create [--force]
    Creates a new migration by comparing dump and database.
    The migration is placed in migrations_path from the config
    and also printed to stdout.
    Options:
      --force     create an empty migration if no changes were found

  php $f reset
    Alters database to structure from dump.

  php $f approve
    Marks all existing migrations as applied without actually running them.
    Also dumps database scheme.

  php $f migrate
    Migrates the database to current version (as defined by migration files).
    1. Finds applied migrations that are not present in filesystem, runs them down.
    2. Finds non-applied migrations, runs them up.

  php $f status
    Shows database changes and out-of-sync migrations in human-readable format.
    Install Console_Color package from PEAR to get colored output.

  php $f up [--from-file | --from-db] {filename}
  php $f down [--from-file | --from-db] {filename}
    Apply or rollback one migration.

");
	exit(EXIT_ERROR);
}



try
{
	$command = array_shift($params);
	$command_options = str_replace('--', '', $params);

	$m_tool = new MigrationsTool($config_file);

	switch($command)
	{
		case 'diff':
		case 'd':
			$reverse = in_array('reverse', $command_options);
			$diff = $m_tool->diff($reverse);
			if(!empty($diff))
			{
				if($reverse)
					echo "\n-- These queries will destroy your changes to database:\n\n";
				else
					echo "\n-- These queries were applied to your database:\n\n";

				echo $diff;
				exit(EXIT_MODIFIED);
			}
			break;

		case 'create':
		case 'c':
			$force = in_array('force', $command_options);
			$branch_name = exec('git branch --no-color 2>/dev/null | sed -e \'/^[^*]/d\' -e \'s/* \(.*\)/\1/\'');
			$sql = $m_tool->create_migrations($force, $branch_name ? "_" . $branch_name : '');
			if(trim($sql) != '')
			{
				echo $sql;
				echo "-- Do not forget to APPROVE the created migration before committing!\n";
				echo "-- Otherwise database dump in the repository will be out of sync.\n";
			}
			break;

		case 'reset':
		case 'r':
			$m_tool->reset_db();
			break;

		case 'approve':
		case 'a':
			$m_tool->approve_migration();
			break;

		case 'migrate':
		case 'm':
			$m_tool->migrate();
			break;

		case 'status':
		case 's':
			$m_tool->status();
			break;

		case 'up':
		case 'down':
			$direction = $command;
			$source = null;
			if(!count($command_options) || (count($command_options) == 1 && ($command_options[0] == 'from-file' || $command_options[0] == 'from-db')))
			{
				throw new Exception("Migration name is not specified");
			}
			else if(count($command_options) == 1)
			{
				$migration_name = $command_options[0];
			}
			else if(count($command_options) == 2)
			{
				$source = $command_options[0];
				$migration_name = $command_options[1];
			}

			$m_tool->apply_one_migration($migration_name, $direction, $source);
			break;

		default:
			throw new Exception('Unknown command: '.$command);
	}
}
catch(Exception $e)
{
	fwrite(STDERR, $e->getMessage()."\n");
	exit(EXIT_ERROR);
}

exit(EXIT_OK);





class MigrationsTool
{
	private $_migrations_path = '';
	private $_schemes_path    = '';
	private $_migration_base  = '';
	private $_migration_table = '';
	private $_databases       = array();
	private $_database        = '';

	private $_config_required_fields = array('databases', 'migrations_path', 'schemes_path', 'migrations_table');


	function __construct($config_file)
	{
		$config = $this->_parse_conf($config_file);

		$base_path = dirname($config_file);
		$this->_migrations_path = $this->_absolutize_path($config['migrations_path'], $base_path);
		$this->_schemes_path    = $this->_absolutize_path($config['schemes_path'], $base_path);

		$migrations_bt = explode('.', $config['migrations_table']);
		if(count($migrations_bt) != 2)
		{
			throw new Exception('Config parameter "migrations_table" should be set as "dbname.tablename"');
		}
		$this->_migration_base  = $migrations_bt[0];
		$this->_migration_table = $migrations_bt[1];

		$this->_databases = preg_split('/\s+/', $config['databases'], -1, PREG_SPLIT_NO_EMPTY);

		if(isset($config['database'])) $this->_database = $config['database'];
	}

	private function _absolutize_path($path, $cwd)
	{
		if(preg_match('@^([a-z]+:[/\\\\]|/)@i', $path))
			return $path;

		return $cwd.'/'.$path;
	}

	function status()
	{
		$status_text = '';

		foreach($this->_databases as $db)
		{
			$url = $this->get_db()->get_dsn() . '/' . $db;
			$dump = $this->_create_dump($url);
			$scheme_from = new MigrationsTool_Scheme($dump);

			$dump = file_get_contents("$this->_schemes_path/{$db}.scheme.sql");
			$scheme_to = new MigrationsTool_Scheme($dump);

			$diff = new MigrationsTool_SchemesDiff($scheme_from, $scheme_to, $db);
			$status = $diff->render_status_text($db);

			if($status)
				$status_text .= $status;
		}

		if($status_text)
			$status_text = "Database changes:".$status_text;

		$status_text .= $this->_get_migrations_for_status();

		if(class_exists('PEAR') && class_exists('Console_Color'))
			$status_text = Console_Color::convert($status_text);
		else
			$status_text = preg_replace('@%[ygrn]@', '', $status_text);

		echo $status_text;
	}


	private function _get_migrations_for_status()
	{
		$unnecessary_migrations = $this->_find_old_unnecessary_migrations();
		$to_changed_migration = $this->_find_migrations_from_last_to_first_changed();
		$down_and_up_migrations = $this->_find_down_and_up_migrations();
		$not_applied_migrations = $this->_find_not_applied_migrations();

		$migrations_down = array();
		$migrations_down = array_merge($migrations_down, $unnecessary_migrations);
		$migrations_down = array_merge($migrations_down, $to_changed_migration);
		ksort($migrations_down);
		$migrations_down = array_reverse($migrations_down);

		$migrations_up = array();
		$migrations_up = array_merge($migrations_up, $to_changed_migration);
		$migrations_up = array_merge($migrations_up, $down_and_up_migrations);
		$migrations_up = array_merge($migrations_up, $not_applied_migrations);
		ksort($migrations_up);

		if(!count($migrations_up) && !count($migrations_down))
			return '';

		$status = "\nMigrations to be applied:\n";
		foreach($migrations_down as $migration_name => $migration)
		{
			$color = '%r';
			if(key_exists($migration_name, $migrations_up))
				$color = '%y';

			$status .= " {$color}down {$migration_name}%n\n";
		}

		foreach($migrations_up as $migration_name => $migration)
		{
			$color = '%g';
			if(key_exists($migration_name, $migrations_down))
				$color = '%y';

			$status .= " {$color}up   {$migration_name}%n\n";
		}

		return $status;
	}


	function reset_db()
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

		$migration_down = $this->_create_migrations(false);
		if(empty($migration_down))
			return false;

		$this->_apply_migration($migration_down);
	}


	function create_migrations($force = false, $name_suffix = '')
	{
		$m_up = $this->_create_migrations();
		if(empty($m_up) && $force === false)
			return false;

		$migrations = "-- Migration Up\n\n";
		$migrations .= $m_up . "\n\n";
		$migrations .= "-- Migration Down\n\n";
		$migrations .= $this->_create_migrations(false) . "\n\n";

		if(!file_exists($this->_migrations_path))
		{
			if(!mkdir($this->_migrations_path, 0755, true)) // rwxr-xr-x
				throw new Exception('Unable to create migrations folder');
		}

		$migration_name = time() . $name_suffix . '.sql';
		$fname = $this->_migrations_path . '/' . $migration_name;
		if(file_put_contents($fname, $migrations) === false)
			throw new Exception('Unable to write migration to "'.$fname.'"');

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
			$this->get_db()->query("DELETE FROM `{$this->_migration_base}`.`{$this->_migration_table}` WHERE name='{$name}';");
		}

		$migrations = $this->_find_not_applied_migrations();
		$changed_migrations = $this->_find_changed_migrations();
		$migrations = array_merge($migrations, $changed_migrations);

		$values = array();
		foreach($migrations as $file_name => $migration)
		{
			$file_name = $this->get_db()->escape($file_name);
			$migration = $this->get_db()->escape($migration);
			$sha1 = $this->get_db()->escape(sha1_file($this->_migrations_path . '/' . $file_name));
			$values[] = "('{$file_name}', '{$migration}', '{$sha1}')";
		}

		if(count($values))
		{
			$values = implode(', ', $values);
			$this->get_db()->query("
				INSERT INTO `{$this->_migration_base}`.`{$this->_migration_table}` (name, query, sha1) VALUES {$values}
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

		$old_migrations = $this->_find_old_unnecessary_migrations();
		if(count($old_migrations))
			$this->_migrate_down($old_migrations);

		$changed_migrations = $this->_find_migrations_from_last_to_first_changed();
		if(count($changed_migrations))
			$this->_migrate_down($changed_migrations);

		$new_migrations = $this->_find_not_applied_migrations();
		if(count($new_migrations))
			$this->_migrate_up($new_migrations);

		if(count($old_migrations) || count($changed_migrations) || count($new_migrations))
			echo "\n";

		return true;
	}


	function apply_one_migration($name, $direction, $source)
	{
		$file_migration = $this->_get_migration_from_file_by_name($name);
		$db_migration = $this->_get_migration_from_db_by_name($name);

		if($source === null && count($file_migration) && count($db_migration))
			throw new Exception("From file or from db?");

		$migration = array();
		if($source == 'from-file' || ($source === null && !count($db_migration)))
			$migration = $file_migration;

		if($source == 'from-db' || ($source === null && !count($file_migration)))
			$migration = $db_migration;

		if(!count($migration))
			throw new Exception("Migration {$name} not found");

		if($direction == 'up')
			$this->_migrate_up($migration);
		else
			$this->_migrate_down($migration);

		return true;
	}



	private $db;

	/**
	 * @return MigrationsTool_MysqlConnection
	 */
	private function get_db()
	{
		if(!$this->db)
		{
			$this->db = new MigrationsTool_MysqlConnection($this->_database);
		}
		return $this->db;
	}

	private function _create_new_dumps()
	{
		$dsn = $this->get_db()->get_dsn();
		foreach($this->_databases as $db)
		{
			$scheme_file = "{$this->_schemes_path}/{$db}.scheme.sql";
			$this->_create_dump($dsn.'/'.$db, $scheme_file);
		}

		return true;
	}


	function _create_migrations($up = true)
	{
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

		foreach($this->_databases as $db)
		{
			$url = $this->get_db()->get_dsn() . '/' . $db;
			$dump = $this->_create_dump($url);
			$scheme_from = new MigrationsTool_Scheme($dump);

			$dump = file_get_contents("$this->_schemes_path/{$db}.scheme.sql");
			$scheme_to = new MigrationsTool_Scheme($dump);

			if(!$up)
				list($scheme_from, $scheme_to) = array($scheme_to, $scheme_from);

			$diff = new MigrationsTool_SchemesDiff($scheme_from, $scheme_to, $db);
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


	private function _parse_conf($config_file)
	{
		if(!file_exists($config_file))
			throw new Exception('Config file '.$config_file.' does not exist');

		$config = parse_ini_file($config_file);

		if(empty($config))
			throw new Exception('Cannot load config from '.$config_file);

		foreach($this->_config_required_fields as $name)
		{
			if(empty($config[$name]))
				throw new Exception('Config parameter "'.$name.'" is not set');
		}

		return $config;
	}


	/**
	 * @param string $url MySQL DSN
	 * @param string $dump_file if a file is given, the dump is written into it; otherwise it is returned
	 * @return string
	 */
	private function _create_dump($url, $dump_file = '')
	{
		$script = dirname(__FILE__) . '/db-scheme-dump.php';
		$cmd = 'php '.escapeshellarg($script).' '.escapeshellarg($url);

		if($dump_file != '')
			shell_exec($cmd.' > '.escapeshellarg($dump_file));
		else
			return shell_exec($cmd);
	}


	private function _apply_migration($migration)
	{
		$migration = preg_replace('@-- Migration (Down|Up)@', '', $migration);
		$migration = trim($migration)."\n";

		echo $migration;
		$this->get_db()->multi_query($migration);
	}


	private function _get_migrations_from_files()
	{
		$path = $this->_migrations_path;
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


	private function _get_migrations_from_db($desc = false, $with_sha1 = false, $name = '')
	{
		$addition = 'ASC';
		if($desc)
			$addition = 'DESC';

		$condition = '1=1';
		if($name)
			$condition = "name='" . $this->get_db()->escape($name) . "'";

		$r = $this->get_db()->query("SELECT `name`, `query`, `sha1` FROM `{$this->_migration_base}`.`{$this->_migration_table}` WHERE {$condition} ORDER BY `name` " . $addition);
		$db_migrations = array();
		while($row = $r->fetch_assoc())
		{
			if(!$with_sha1)
				$db_migrations[$row['name']] = $row['query'];
			else
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
			CREATE TABLE IF NOT EXISTS `{$this->_migration_base}`.`{$this->_migration_table}` (
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
		$path = $this->_migrations_path;
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

		$migrations = $this->_find_migrations_from_last_to_specified($first_migration);

		return $migrations;
	}


	private function _find_down_and_up_migrations()
	{
		$first_migration = $this->_find_migration_for_down();
		if(!$first_migration)
			return array();

		$migrations = $this->_find_migrations_from_last_to_specified($first_migration);
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
			$file_sha1 = sha1_file($this->_migrations_path . '/' . $file_name);
			if(key_exists($file_name, $db_migrations) && $file_sha1 != $db_migrations[$file_name]['sha1'])
				$migrations[$file_name] = $db_migrations[$file_name]['query'];
		}

		return $migrations;
	}


	private function _find_migrations_from_last_to_first_changed()
	{
		$changed_migrations = $this->_find_changed_migrations();
		if(!count($changed_migrations))
			return array();

		$migration_names = array_keys($changed_migrations);
		$first_changed_migration = array_shift($migration_names);
		$migrations = $this->_find_migrations_from_last_to_specified($first_changed_migration);
		return $migrations;
	}


	private function _find_migrations_from_last_to_specified($migration_name)
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


	private function _migrate_down($migrations)
	{
		foreach($migrations as $name => $migration)
		{
			$pos = strpos($migration, '-- Migration Down');
			$down_migration = substr($migration, $pos);

			echo "\n-- down: {$name}\n\n";
			$this->_apply_migration($down_migration);
			$name = $this->get_db()->escape($name);
			$this->get_db()->query("DELETE FROM `{$this->_migration_base}`.`{$this->_migration_table}` WHERE name = '{$name}'");
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
		$sha1      = $db->escape(sha1_file($this->_migrations_path . '/' . $name));

		$db->query("
			INSERT INTO `{$this->_migration_base}`.`{$this->_migration_table}` (name, query, sha1)
			VALUES ('{$name}', '{$migration}', '{$sha1}')
			ON DUPLICATE KEY UPDATE query = VALUES(query), sha1 = VALUES(sha1)
		");
	}


	private function _get_migration_from_file_by_name($name)
	{
		$migration_path = $this->_migrations_path . '/' . $name;
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


class MigrationsTool_MysqlConnection
{
	public $host     = '';
	public $port     = '';
	public $user     = '';
	public $password = '';

	/**
	 * @var mysqli
	 */
	private $_connection;

	/**
	 * @param string $dsn mysql://user:pass@host:port
	 */
	public function __construct($dsn)
	{
		$p = parse_url($dsn);
		if(isset($p['scheme']) && strtolower($p['scheme']) == 'mysql')
		{
			$this->host     = (string) @$p['host'];
			$this->port     = (string) @$p['port'];
			$this->user     = (string) @$p['user'];
			$this->password = (string) @$p['pass'];
		}

		$this->_load_connection_defaults();
	}

	public function get_dsn()
	{
		$server = $this->host;
		if($this->port != '')
			$server .= ':'.$this->port;

		$auth = '';
		if($this->user != '')
		{
			$auth = $this->user;
			if($this->password != '')
				$auth .= ':'.$this->password;

			$auth .= '@';
		}

		return 'mysql://'.$auth.$server;
	}

	/**
	 * @throws Exception
	 * @param string $sql
	 * @return mysqli_result
	 */
	public function query($sql)
	{
		$this->_connect();
		$result = $this->_connection->query($sql);
		if(!$result)
			throw new Exception('DB error: '.$this->_connection->error);
		return $result;
	}

	/**
	 * @throws Exception
	 * @param string $sql
	 * @return mysqli_result
	 */
	public function multi_query($sql)
	{
		$this->_connect();
		$this->_connection->multi_query($sql);

		do
		{
			if($result = $this->_connection->use_result())
				$result->close();
		} while ($this->_connection->next_result());

		if($this->_connection->errno)
			throw new Exception('DB error: '.$this->_connection->error);

		return true;
	}

	public function escape($str)
	{
		$this->_connect();
		return $this->_connection->real_escape_string($str);
	}


	private function _load_connection_defaults()
	{
		// no need to load if everything is specified
		if(empty($this->host) || empty($this->port) || empty($this->user) || empty($this->password))
		{
			// loading defaults from the mysql client
			$line = exec('mysql --print-defaults', $ret, $code);
			// non-zero code = error
			if($code) return;

			// simple and stupid
			if(preg_match_all('/--(\w+)=(\S+)/', $line, $mm, PREG_SET_ORDER))
			{
				foreach($mm as $row)
				{
					$name = $row[1];
					if(isset($this->$name) && $this->$name == '')
						$this->$name = $row[2];
				}
			}

			if (empty($this->host)) $this->host = '127.0.0.1';
		}
	}

	private function _connect()
	{
		if(empty($this->_connection))
		{
			$this->_connection = new mysqli($this->host, $this->user, $this->password, '', intval($this->port));
			if(mysqli_connect_error())
				throw new Exception('Connect Error ' . mysqli_connect_error());

			$this->query('SET NAMES cp1251');
		}
	}

}


class MigrationsTool_Scheme
{
	private $_scheme_data = array(
		'tables' => array(),
		'views'  => array(),
	);


	public function __construct($dump)
	{
		$this->_load_from_dump($dump);
	}


	public function get_data()
	{
		return $this->_scheme_data;
	}


	private function _load_from_dump($dump)
	{
		$lines = explode("\n", $dump);

		$scheme = array(
			'tables' => array(),
			'views'  => array(),
		);
		$table_name = '';
		foreach($lines as $line)
		{
			$matches = array();
			if(preg_match('@^CREATE TABLE `(.+)`@', $line, $matches))
			{
				$table_name = $matches[1];

				$scheme['tables'][$table_name] = array(
					'name'         => $table_name,
					'fields'       => array(),
					'keys'         => array(),
					'foreign_keys' => array(),
					'props'        => '',
				);
			}

			if(preg_match('@CREATE VIEW `(.+?)` (AS .*);@', $line, $matches))
			{
				$view_name = $matches[1];
				$view      = $matches[2];

				$view_name = preg_replace('@.+?`\.`(.+)@', '$1', $view_name);

				$scheme['views'][$view_name] = $view;
			}

			if(preg_match('@^\s+`(.+)` (.+)@', $line, $matches))
			{
				$field_name  = $matches[1];
				$field_props = $matches[2];

				if($field_props[strlen($field_props) - 1] == ',')
					$field_props = substr($field_props, 0, strlen($field_props) - 1);

				$scheme['tables'][$table_name]['fields'][$field_name] = $field_props;
			}

			if(preg_match('@\s+(PRIMARY|UNIQUE)? KEY\s?(?:`(.*)`)?\s?\((.*)\)@', $line, $matches))
			{
				$index_name = 'PRIMARY';
				if(!empty($matches[2]))
					$index_name = $matches[2];

				$unique = false;
				if($index_name == 'PRIMARY' || $matches[1] == 'UNIQUE')
					$unique = true;

				$fields = $matches[3];

				$scheme['tables'][$table_name]['keys'][$index_name] = array(
					'name'   => $index_name,
					'unique' => $unique,
					'fields' => $fields,
				);
			}

			if(preg_match('@CONSTRAINT `(.+)` (FOREIGN KEY .+)@', $line, $matches))
			{
				$index_name  = $matches[1];
				$index_props = $matches[2];

				if($index_props[strlen($index_props) - 1] == ',')
					$index_props = substr($index_props, 0, strlen($index_props) - 1);

				$scheme['tables'][$table_name]['foreign_keys'][$index_name] = array(
					'name'  => $index_name,
					'props' => $index_props,
				);
			}

			if(preg_match('@(ENGINE=.+);@', $line, $matches))
			{
				$scheme['tables'][$table_name]['props'] = $matches[1];
			}
		}

		$this->_scheme_data = $scheme;
	}
}


class MigrationsTool_SchemesDiff
{
	private $_diff_data;
	private $_db_name;


	public function __construct($scheme1, $scheme2, $db_name)
	{
		$this->_db_name = $db_name;
		$this->_create_diff($scheme1, $scheme2);
	}


	public function get_data()
	{
		return $this->_diff_data;
	}


	public function render_migration()
	{
		$migration = array(
			'add_foreign_keys'  => array(),
			'drop_foreign_keys' => array(),
			'add_views'         => array(),
			'drop_views'        => array(),
			'alter_views'       => array(),
			'add_tables'        => array(),
			'drop_tables'       => array(),
			'alter_tables'      => array(),
		);

		foreach($this->_diff_data as $change_name => $changes)
		{
			foreach($changes as $db_action_name => $db_action)
			{
				switch($change_name)
				{
					case 'add_tables':
						$migration['add_tables'][] = $this->_m_create_table($db_action);
						break;
					case 'drop_tables':
						$migration['drop_tables'][] = $this->_m_drop_table($db_action);
						break;
					case 'alter_tables':
						$migration['alter_tables'][] = $this->_m_alter_table($db_action_name, $db_action);
						break;
					case 'add_keys':
						$migration['add_foreign_keys'][] = $this->_m_add_foreign_key($db_action['index'], $db_action['table_name']);
						break;
					case 'drop_key':
						$migration['drop_foreign_keys'][] = $this->_m_drop_foreign_key($db_action['index'], $db_action['table_name']);
						break;
					case 'modify_keys':
						$migration['drop_foreign_keys'][] = $this->_m_drop_foreign_key($db_action['old'], $db_action['table_name']);
						$migration['add_foreign_keys'][] = $this->_m_add_foreign_key($db_action['new'], $db_action['table_name']);
						break;
					case 'add_views':
						$migration['add_views'][] = $this->_m_add_view($db_action_name, $db_action);
						break;
					case 'alter_views':
						$migration['alter_views'][] = $this->_m_alter_view($db_action_name, $db_action);
						break;
					case 'drop_views':
						$migration['drop_views'][] = $this->_m_drop_view($db_action_name);
						break;
				}
			}
		}

		return $migration;
	}


	public function render_status_text($db_name)
	{
		$status = '';

		$diff_data = $this->_diff_data;
		foreach($diff_data as $change_name => $changes)
		{
			if($change_name == 'add_keys' || $change_name == 'drop_keys' || $change_name == 'modify_keys')
			{
				foreach($changes as $db_action_name => $db_action)
				{
					if(key_exists($db_action['table_name'], $diff_data['alter_tables']))
						$diff_data['alter_tables'][$db_action['table_name']][$change_name.'_f'][$db_action_name] = $db_action;
				}
			}
		}


		foreach($diff_data as $change_name => $changes)
		{
			foreach($changes as $db_action_name => $db_action)
			{
				switch($change_name)
				{
					case 'add_tables':
					case 'add_views':
						$act = "%g+";
						break;
					case 'drop_tables':
					case 'drop_views':
						$act = "%r-";
						break;
					default:
						$act = "%y~";
				}

				$_action = '';
				if($change_name == 'add_views' || $change_name == 'drop_views' || $change_name == 'alter_views')
					$_action = '(view)';

				if($change_name != 'add_keys' && $change_name != 'drop_keys' && $change_name != 'modify_keys')
				{
					$status .= "\n {$act} {$db_name}.{$db_action_name} {$_action}%n\n";
				}

				if($change_name == 'alter_tables')
					$status .= $this->_generate_status_text_for_alter_tables($db_action);
			}
		}

		return $status;
	}


	private function _generate_status_text_for_alter_tables($db_action)
	{
		$status = '';

		foreach($db_action as $table_change_name => $table_changes)
		{
			switch($table_change_name)
			{
				case 'add_field':
				case 'add_key':
				case 'add_keys_f':
					$act = "%g+";
					break;
				case 'drop_field':
				case 'drop_key':
				case 'drop_keys_f':
					$act = "%r-";
					break;
				default:
					$act = "%y~";
			}
			foreach($table_changes as $action_name => $action)
			{
				$_action = $action;
				if($table_change_name == 'modify_field')
					$_action = $action['new'] . ' -- was ' . $action['old'];

				if($table_change_name == 'add_key' || $table_change_name == 'drop_key')
				{
					if($action_name == 'PRIMARY')
						$action_name = $action_name . ' KEY';
					else
						$action_name = 'KEY ' . $action_name;
					$_action = "({$action['fields']})";
				}

				if($table_change_name == 'add_keys_f' || $table_change_name == 'drop_keys_f')
				{
					$_action = $action['index']['props'];
				}

				$status .= "    {$act} {$action_name}%n {$_action}\n";
			}
		}

		return $status;
	}


	/**
	 * @param  $scheme1 MigrationsTool_Scheme
	 * @param  $scheme2 MigrationsTool_Scheme
	 */
	private function _create_diff($scheme1, $scheme2)
	{
		$scheme1_data = $scheme1->get_data();
		$scheme2_data = $scheme2->get_data();

		$this->_diff_data = array();

		$changes = array(
			'add_tables' => array(),
			'alter_tables' => array(),
			'drop_tables' => array(),
			'add_views' => array(),
			'drop_views' => array(),
			'alter_views' => array(),
			'drop_keys' => array(),
			'add_keys' => array(),
			'modify_keys' => array(),
		);

		foreach($scheme2_data['tables'] as $table_name => $table)
		{
			if(!key_exists($table_name, $scheme1_data['tables']))
			{
				$changes['drop_tables'][$table_name] = $table;
			}

			foreach($table['foreign_keys'] as $index_name => $index)
			{
				if(!key_exists($table_name, $scheme1_data['tables']) || !key_exists($index_name, $scheme1_data['tables'][$table_name]['foreign_keys']))
					$changes['drop_keys'][$index_name] = array(
						'table_name' => $table_name,
						'index' => $index,
					);
			}
		}

		foreach($scheme1_data['tables'] as $table_name => $table)
		{
			if(!key_exists($table_name, $scheme2_data['tables']))
			{
				$changes['add_tables'][$table_name] = $table;
			}
			else if($table !== $scheme2_data['tables'][$table_name])
			{
				$table_changes = $this->_diff_tables($table, $scheme2_data['tables'][$table_name]);
				if($table_changes)
					$changes['alter_tables'][$table_name] = $table_changes;
			}

			foreach($table['foreign_keys'] as $index_name => $index)
			{
				if(!key_exists($table_name, $scheme2_data['tables']) || !key_exists($index_name, $scheme2_data['tables'][$table_name]['foreign_keys']))
				{
					$changes['add_keys'][$index_name] = array(
						'table_name' => $table_name,
						'index' => $index,
					);
				}
				if(key_exists($table_name, $scheme2_data['tables']) && key_exists($index_name, $scheme2_data['tables'][$table_name]['foreign_keys']) && $index != $scheme2_data['tables'][$table_name]['foreign_keys'][$index_name])
				{
					$changes['modify_keys'][$index_name] = array(
						'table_name' => $table_name,
						'old' => $scheme2_data['tables'][$table_name]['foreign_keys'][$index_name],
						'new' => $index,
					);
				}
			}
		}

		foreach($scheme2_data['views'] as $view_name => $view)
		{
			if(!key_exists($view_name, $scheme1_data['views']))
				$changes['drop_views'][$view_name] = $view;
		}

		foreach($scheme1_data['views'] as $view_name => $view)
		{
			if(!key_exists($view_name, $scheme2_data['views']))
			{
				$changes['add_views'][$view_name] = $view;
			}
			else if($view != $scheme2_data['views'][$view_name])
			{
				$changes['alter_views'][$view_name] = array(
					'old' => $scheme2_data['views'][$view_name],
					'new' => $view,
				);
			}
		}

		$this->_diff_data = $changes;
	}


	private function _diff_tables($table1, $table2)
	{
		$changes = array(
			'add_field' => array(),
			'drop_field' => array(),
			'modify_field' => array(),
			'add_key' => array(),
			'drop_key' => array(),
			'modify_key' => array(),
			'props' => array(),
		);
		$empty_changes = $changes;

		foreach($table2['fields'] as $name => $field_props)
		{
			if(!key_exists($name, $table1['fields']))
				$changes['drop_field'][$name] = $field_props;
		}

		$prev_field = null;
		foreach($table1['fields'] as $name => $field_props)
		{
			$add_props = ' FIRST';
			if($prev_field)
				$add_props = " AFTER `{$prev_field}`";

			if(!key_exists($name, $table2['fields']))
				$changes['add_field'][$name] = $field_props . $add_props;
			else if(strcasecmp($field_props, $table2['fields'][$name]) != 0)
			{
				$changes['modify_field'][$name] = array(
					'old' => $table2['fields'][$name],
					'new' => $field_props,
				);
			}
			$prev_field = $name;
		}

		foreach($table2['keys'] as $index_name => $index)
		{
			if(!key_exists($index_name, $table1['keys']))
				$changes['drop_key'][$index_name] = $index;
		}

		foreach($table1['keys'] as $index_name => $index)
		{
			if(!key_exists($index_name, $table2['keys']))
				$changes['add_key'][$index_name] = $index;
			else if($index != $table2['keys'][$index_name])
				$changes['modify_key'][$index_name] = array(
					'old' => $table2['keys'][$index_name],
					'new' => $index,
				);
		}

		if($table1['props'] != $table2['props'])
			$changes['props'] = array(
				'old' => $table2['props'],
				'new' => $table1['props'],
			);

		$changes = $this->_check_fields_positions($table1, $table2, $changes);

		if($changes === $empty_changes)
			$changes = false;
		return $changes;
	}


	private function _check_fields_positions($table1, $table2, $changes)
	{
		$pos = 1;
		$table1_pos = array();
		foreach($table1['fields'] as $field_name => $field_props)
		{
			$table1_pos[$field_name] = $pos;
			$pos++;
		}

		$table2_pos = array();
		$table2_with_pos = array();
		$table1_prev_fields = array();
		$prev_field = null;
		foreach($table2['fields'] as $field_name => $field_props)
		{
			$table1_prev_fields[$field_name] = $prev_field;
			$prev_field = $field_name;

			if(!key_exists($field_name, $table1_pos))
				continue;

			$table2_with_pos[$table1_pos[$field_name]] = array(
				'field_name' => $field_name,
				'field_props' => $field_props,
			);

			$table2_pos[] = $table1_pos[$field_name];
		}

		$sequence = $this->_find_longest_sequence($table2_pos);
		$sequence[99999] = 99999;

		foreach($table2_with_pos as $pos => $field)
		{
			if(in_array($pos, $sequence))
				continue;

			$prev_pos = -1;
			foreach($sequence as $key => $s_pos)
			{
				if($pos < $s_pos)
				{
					$field_name = $table2_with_pos[$pos]['field_name'];

					$add_props = ' FIRST';
					if($prev_pos != -1)
						$add_props = " AFTER `{$table2_with_pos[$prev_pos]['field_name']}`";

					$old_props = ' FIRST';
					if(key_exists($field_name, $table1_prev_fields) && $table1_prev_fields[$field_name] != null)
					{
						$old_props = " AFTER `{$table1_prev_fields[$field_name]}`";
					}

					$changes['modify_field'][$field_name] = array(
						'old' => "{$table1['fields'][$field_name]}{$old_props}",
						'new' => "{$table2['fields'][$field_name]}{$add_props}",
					);
					$sequence[] = $pos;
					sort($sequence);
					break;
				}
				$prev_pos = $s_pos;
			}
		}

		return $changes;
	}


	private function _find_longest_sequence($arr)
	{
		$all_sequences = array();

		foreach($arr as $key => $value)
		{
			$count = 0;
			$count2 = 0;
			for($i = 0; $i < count($arr); $i++)
			{
				if($arr[$i] < $value && $i < $key)
					$count++;
				if($arr[$i] > $value && $i > $key)
					$count2++;
			}
			if($count2 > $count)
				$count = $count2;

			foreach($all_sequences as &$sequence)
			{
				if($value > $sequence[count($sequence)-1])
				{
					$sequence[] = $value;
					$count--;
				}

				if($count <= 0)
					break;
			}

			while($count > 0)
			{
				$all_sequences[] = array($value);
				$count--;
			}
		}

		$max_sequence = array();
		$max_length = 0;
		foreach($all_sequences as $sequence)
		{
			$curr_sequence = count($sequence);
			if($curr_sequence > $max_length)
			{
				$max_length = $curr_sequence;
				$max_sequence = $sequence;
			}
		}

		if(empty($max_sequence))
			$max_sequence = array($arr[0]);

		return $max_sequence;
	}


	private function _m_create_table($table)
	{
		$table_name = $table['name'];
		$lines = array();

		foreach($table['fields'] as $field_name => $field_props)
		{
			$lines[] = "  {$field_name} {$field_props}";
		}

		foreach($table['keys'] as $index_name => $index)
		{
			if($index_name == 'PRIMARY')
				$lines[] = "  PRIMARY KEY ({$index['fields']})";
			else if(!empty($index['unique']))
				$lines[] = "  UNIQUE KEY `{$index_name}` ({$index['fields']})";
			else
				$lines[] = "  KEY `{$index_name}` ({$index['fields']})";
		}

		$migration = "CREATE TABLE `{$this->_db_name}`.`{$table_name}` (\n";
		$migration .= join(",\n", $lines) . "\n";
		$migration .= ") {$table['props']};\n\n";

		return $migration;
	}


	private function _m_drop_table($table)
	{
		$table_name = $table['name'];
		$migration = "DROP TABLE `{$this->_db_name}`.`{$table_name}`;\n\n";
		return $migration;
	}


	private function _m_alter_table($table_name, $table_changes)
	{
		$migration = array();

		foreach($table_changes as $change_name => $changes)
		{
			if(!is_array($changes))
				continue;

			foreach($changes as $field_name => $field_props)
			{
				switch($change_name)
				{
					case 'add_field':
						$migration[] = "ADD COLUMN `{$field_name}` {$field_props}";
						break;
					case 'drop_field':
						$migration[] = "DROP COLUMN `{$field_name}`";
						break;
					case 'modify_field':
						$migration[] = "MODIFY `{$field_name}` {$field_props['new']}";
						break;
					case 'add_key':
						$migration[] = $this->_m_add_key($field_props, $table_name);
						break;
					case 'drop_key':
						$migration[] = $this->_m_drop_key($field_props, $table_name);
						break;
					case 'modify_key':
						$migration[] = $this->_m_drop_key($field_props, $table_name);
						$migration[] = $this->_m_add_key($field_props, $table_name);
						break;
					case 'props':
						$migration[] = $field_props;
				}
			}
		}

		if ($migration)
			return "ALTER TABLE `{$this->_db_name}`.`{$table_name}`\n  " . implode(",\n  ", $migration) . ";\n\n";

		return false;
	}


	private function _m_drop_key($index, $table_name)
	{
		if($index['name'] == 'PRIMARY')
			$migration = "DROP PRIMARY KEY";
		else
			$migration = "DROP INDEX `{$index['name']}`";

		return $migration;
	}


	private function _m_add_key($index, $table_name)
	{
		if($index['name'] == 'PRIMARY')
			$migration = "ADD PRIMARY KEY ({$index['fields']})";
		else if($index['unique'])
			$migration = "ADD UNIQUE `{$index['name']}` ({$index['fields']})";
		else
			$migration = "ADD INDEX `{$index['name']}` ({$index['fields']})";

		return $migration;
	}


	private function _m_add_foreign_key($index, $table_name)
	{
		return "ALTER TABLE `{$this->_db_name}`.`{$table_name}` ADD CONSTRAINT `{$index['name']}` {$index['props']};\n";
	}


	private function _m_drop_foreign_key($index, $table_name)
	{
		return "ALTER TABLE `{$this->_db_name}`.`{$table_name}` DROP FOREIGN KEY `{$index['name']}`;\n";
	}


	private function _m_drop_view($view_name)
	{
		return "DROP VIEW `{$this->_db_name}`.`{$view_name}`\n";
	}


	private function _m_add_view($view_name, $view)
	{
		// View declaration may contain table references inside (view x as select * from table).
		// Those references may omit database name, so we need to set default DB here.
		return "USE `{$this->_db_name}`;\n".
			"CREATE VIEW `{$this->_db_name}`.`{$view_name}` {$view};\n";
	}


	private function _m_alter_view($view_name, $view)
	{
		return "USE `{$this->_db_name}`;\n".
			"ALTER VIEW `{$this->_db_name}`.`{$view_name}` {$view['new']};\n";
	}
}