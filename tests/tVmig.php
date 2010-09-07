<?php

// you need to setup .my.cnf with correct connection info for mysql
// mysql user should be able to create/drop database named "vmig_test"

require_once dirname(__FILE__).'/../lib/Vmig.php';

class tVmig extends PHPUnit_Framework_TestCase
{
	/** @var Vmig_MysqlConnection */
	private $db;

	/** @var Vmig_Config */
	private $config;

	private $test_dbname = '';

	private $migrations_script = '';

	public function __construct($name = NULL, array $data = array(), $dataName = '')
	{
		parent::__construct($name, $data, $dataName);

		$path = dirname(__FILE__);
		$this->config = Vmig_Config::find($path);

		$this->db = new Vmig_MysqlConnection('');
		$this->db->query('SELECT 1'); // will throw if unable to connect

		$this->test_dbname = reset($this->config->databases);

		$this->migrations_script = 'php '.dirname(dirname(__FILE__)).'/vmig.php --config='.escapeshellarg($path.'/'.Vmig_Config::DEFAULT_CONF_FILE);

		// just in case
		$this->tearDown();
	}

	protected function setUp()
	{
		$this->db->query("DROP DATABASE IF EXISTS {$this->test_dbname}");
		$this->db->query("CREATE DATABASE {$this->test_dbname} CHARACTER SET = cp1251");

		$this->db->query("
			CREATE TABLE `{$this->test_dbname}`.`test1` (
				`id` int(11) NOT NULL auto_increment,
				`field1` int(11) NOT NULL default '0',
				`field2` varchar(255) NOT NULL default '',
				`field3` int(11) NOT NULL default '0',
				`field4` int(11) NOT NULL default '0',
				`field5` int(11) NOT NULL default '0',
				PRIMARY KEY  (`id`)
			) ENGINE=InnoDB DEFAULT CHARSET=cp1251;
		");

		$this->exec('approve');
	}

	protected function tearDown()
	{
		$this->db->query("DROP DATABASE IF EXISTS {$this->test_dbname}");
		$this->db->query("DROP TABLE IF EXISTS {$this->config->migration_db}.{$this->config->migration_table}");

		$this->remove_migrations_files();
		$this->clean_dir($this->config->schemes_path);
	}


	//
	// TESTS
	//

	function test_createMigrations()
	{
		$this->db->query("ALTER TABLE `{$this->test_dbname}`.`test1` ADD COLUMN `field100` int(11) NOT NULL;");
		$this->db->query("ALTER TABLE `{$this->test_dbname}`.`test1` ADD COLUMN `field101` varchar(255) NOT NULL;");
		$this->exec('create create');
		$migration = $this->get_last_migration_from_files();

		$this->assertContains('ADD COLUMN `field100` int(11) NOT NULL', $migration);
		$this->assertContains('ADD COLUMN `field101` varchar(255) NOT NULL', $migration);
		$this->assertContains('DROP COLUMN `field100`', $migration);
		$this->assertContains('DROP COLUMN `field101`', $migration);
	}


	function test_approveMigrations()
	{
		$this->db->query("ALTER TABLE `{$this->test_dbname}`.`test1` ADD COLUMN `field100` int(11) NOT NULL;");
		$this->db->query("ALTER TABLE `{$this->test_dbname}`.`test1` ADD COLUMN `field101` varchar(255) NOT NULL;");
		$this->exec('create approve');
		$this->exec('approve');
		$migration = $this->get_last_migration_from_base();

		$this->assertContains('ADD COLUMN `field100` int(11) NOT NULL', $migration);
		$this->assertContains('ADD COLUMN `field101` varchar(255) NOT NULL', $migration);
		$this->assertContains('DROP COLUMN `field100`', $migration);
		$this->assertContains('DROP COLUMN `field101`', $migration);
	}


	function test_reset()
	{
		$scheme1 = file_get_contents($this->config->schemes_path . '/' . $this->test_dbname . '.scheme.sql');

		$this->db->query("ALTER TABLE `{$this->test_dbname}`.`test1` ADD COLUMN `field100` int(11) NOT NULL;");
		$this->db->query("ALTER TABLE `{$this->test_dbname}`.`test1` ADD COLUMN `field101` varchar(255) NOT NULL;");
		$this->exec_reset();

		$scheme2 = file_get_contents($this->config->schemes_path . '/' . $this->test_dbname . '.scheme.sql');

		$this->assertEquals($scheme1, $scheme2);
	}


	function test_migrateUp()
	{
		$this->db->query("ALTER TABLE `{$this->test_dbname}`.`test1` ADD COLUMN `field100` int(11) NOT NULL;");
		$this->db->query("ALTER TABLE `{$this->test_dbname}`.`test1` ADD COLUMN `field101` varchar(255) NOT NULL;");
		$this->exec('create migrate_up');
		$this->exec_reset();

		try
		{
			// diff exits with non-empty code if there are differences
			// our exec() will think there's an error and throw
			$this->exec('diff');
		}
		catch(Exception $e)
		{
			$this->assertEquals('diff', '');
		}
		$this->assertFalse($this->get_last_migration_from_base());

		$this->exec('migrate');
		$migration = $this->get_last_migration_from_base();
		$this->assertContains('ADD COLUMN `field100` int(11) NOT NULL', $migration);
		$this->assertContains('ADD COLUMN `field101` varchar(255) NOT NULL', $migration);
		$this->assertContains('DROP COLUMN `field100`', $migration);
		$this->assertContains('DROP COLUMN `field101`', $migration);
	}


	function test_migrateDown()
	{
		$this->db->query("ALTER TABLE `{$this->test_dbname}`.`test1` ADD COLUMN `field100` int(11) NOT NULL;");
		$this->db->query("ALTER TABLE `{$this->test_dbname}`.`test1` ADD COLUMN `field101` varchar(255) NOT NULL;");
		$this->exec('create migrate_down');
		$this->exec('approve');
		$this->remove_migrations_files();
		$this->exec('migrate');

		$this->assertFalse($this->get_last_migration_from_base());
	}


	function test_createTableDown()
	{
		$this->db->query("
			CREATE TABLE `{$this->test_dbname}`.`test100` (
				`id` int(11) NOT NULL auto_increment,
				PRIMARY KEY  (`id`)
			) ENGINE=InnoDB DEFAULT CHARSET=cp1251;
		");
		$this->exec('create table_down');
		$this->exec('approve');
		$this->remove_migrations_files();
		$this->exec('migrate');

		$this->assertFalse($this->get_last_migration_from_base());
	}


	function test_createTableUp()
	{
		$this->db->query("
			CREATE TABLE `{$this->test_dbname}`.`test100` (
				`id` int(11) NOT NULL auto_increment,
				PRIMARY KEY  (`id`)
			) ENGINE=InnoDB DEFAULT CHARSET=cp1251;
		");
		$this->exec('create table_up');
		$this->exec_reset();
		$this->exec('migrate');

		$migration = $this->get_last_migration_from_base();
		$this->assertContains("CREATE TABLE `{$this->test_dbname}`.`test100` (", $migration);
	}


	function test_addIndexDown()
	{
		$this->db->query("ALTER TABLE `{$this->test_dbname}`.`test1` ADD INDEX `field1` (`field1`);");
		$this->exec('create index_down');
		$this->exec('approve');
		$this->remove_migrations_files();
		$this->exec('migrate');

		$this->assertFalse($this->get_last_migration_from_base());
	}


	function test_addIndexUp()
	{
		$this->db->query("ALTER TABLE `{$this->test_dbname}`.`test1` ADD INDEX `field1` (`field1`);");
		$this->exec('create index_up');
		$this->exec_reset();
		$this->exec('migrate');

		$migration = $this->get_last_migration_from_base();
		$this->assertContains("ADD INDEX `field1` (`field1`)", $migration);
	}


	function test_addForeignKeyDown()
	{
		$this->db->query("
			CREATE TABLE `{$this->test_dbname}`.`test100` (
				`id` int(11) NOT NULL auto_increment,
				`field1` int(11) NOT NULL default '0',
				PRIMARY KEY  (`id`),
				KEY `field1` (`field1`)
			) ENGINE=InnoDB DEFAULT CHARSET=cp1251;
		");
		$this->db->query("ALTER TABLE `{$this->test_dbname}`.`test100` ADD CONSTRAINT `FK_test` FOREIGN KEY (`field1`) REFERENCES `test1` (`id`) ON DELETE CASCADE ON UPDATE CASCADE");

		$this->exec('create fk_down');
		$this->exec('approve');
		$this->remove_migrations_files();
		$this->exec('migrate');

		$this->assertFalse($this->get_last_migration_from_base());
	}


	function test_addForeignKeyUp()
	{
		$this->db->query("
			CREATE TABLE `{$this->test_dbname}`.`test100` (
				`id` int(11) NOT NULL auto_increment,
				`field1` int(11) NOT NULL default '0',
				PRIMARY KEY  (`id`),
				KEY `field1` (`field1`)
			) ENGINE=InnoDB DEFAULT CHARSET=cp1251;
		");
		$this->db->query("ALTER TABLE `{$this->test_dbname}`.`test100` ADD CONSTRAINT `FK_test` FOREIGN KEY (`field1`) REFERENCES `test1` (`id`) ON DELETE CASCADE ON UPDATE CASCADE");

		$this->exec('create fk_up');
		$this->exec_reset();
		$this->exec('migrate');

		$migration = $this->get_last_migration_from_base();
		$this->assertContains("CONSTRAINT `FK_test` FOREIGN KEY (`field1`) REFERENCES `test1` (`id`)", $migration);
	}


	function test_addViewDown()
	{
		$this->db->query("CREATE VIEW `{$this->test_dbname}`.`view1` AS (SELECT id AS iid FROM `{$this->test_dbname}`.`test1`);");
		$this->exec('create view_down');
		$this->exec('approve');

		$this->remove_migrations_files();
		$this->exec('migrate');

		$this->assertFalse($this->get_last_migration_from_base());
	}


	function test_addViewUp()
	{
		$this->db->query("CREATE VIEW `{$this->test_dbname}`.`view1` AS (SELECT id AS iid FROM `{$this->test_dbname}`.`test1`);");

		$this->exec('create view_up');
		$this->exec_reset();
		$this->exec('migrate');

		$migration = $this->get_last_migration_from_base();
		$this->assertContains("CREATE VIEW `{$this->test_dbname}`.`view1` AS (select `test1`.`id` AS `iid` from `test1`);", $migration);
	}


	function test_fieldsPos34()
	{
		// field3 <-> field4
		$this->db->query("ALTER TABLE `{$this->test_dbname}`.`test1` MODIFY `field3` int(11) NOT NULL default '0' AFTER `field4`");
		$this->exec('create pos34');
		$this->exec('approve');
		$migration = $this->get_last_migration_from_base();
		$this->assertContains("MODIFY `field4` int(11) NOT NULL default '0' AFTER `field2`", $migration);
	}


	function test_fieldsPos2after4()
	{
		$this->db->query("ALTER TABLE `{$this->test_dbname}`.`test1` MODIFY `field2` varchar(255) NOT NULL default '' AFTER `field4`");
		$this->exec('create pos2after4');
		$this->exec('approve');
		$migration = $this->get_last_migration_from_base();
		$this->assertContains("MODIFY `field2` varchar(255) NOT NULL default '' AFTER `field4`", $migration);
	}


	function test_fieldsPos15()
	{
		// field1 <-> field5
		$this->db->query("ALTER TABLE `{$this->test_dbname}`.`test1` MODIFY `field1` int(11) NOT NULL default '0' AFTER `field5`");
		$this->db->query("ALTER TABLE `{$this->test_dbname}`.`test1` MODIFY `field5` int(11) NOT NULL default '0' FIRST");
		$this->exec('create pos15');
		$this->exec('approve');
		$migration = $this->get_last_migration_from_base();
		$this->assertContains("MODIFY `field1` int(11) NOT NULL default '0' AFTER `field4`", $migration);
		$this->assertContains("MODIFY `field5` int(11) NOT NULL default '0' FIRST", $migration);
	}


	function test_fieldsPos4AfterDeleted()
	{
		$this->db->query("ALTER TABLE `{$this->test_dbname}`.`test1` MODIFY `field5` int(11) NOT NULL default '0' AFTER `field2`");
		$this->db->query("ALTER TABLE `{$this->test_dbname}`.`test1` DROP COLUMN `field2`");
		$this->exec('create pos4after_deleted');
		$this->exec('approve');
		$migration = $this->get_last_migration_from_base();
		$this->assertContains("MODIFY `field5` int(11) NOT NULL default '0' AFTER `field1`", $migration);
	}


	//
	// TOOLS
	//

	private function exec($command, $prefix = '')
	{
		$cmd = $prefix.$this->migrations_script.' '.$command;
		exec($cmd, $out, $err);
		if($err)
			throw new Exception('Error in exec: '.$cmd);
		return join("\n", $out);
	}

	private function exec_reset()
	{
		return $this->exec('reset', 'echo y | ');
	}

	private function remove_migrations_files()
	{
		$this->clean_dir($this->config->migrations_path);
	}

	private function clean_dir($dir)
	{
		foreach(glob($dir.'/*') as $f)
		{
			unlink($f);
		}
	}

	private function remove_migrations_from_base()
	{
		$this->db->query("DELETE FROM `{$this->config->migration_db}`.`{$this->config->migration_table}`");
	}

	private function get_last_migration_from_files()
	{
		$files = glob($this->config->migrations_path.'/*');
		if(empty($files))
			return false;
		$last_file = array_pop($files);
		return file_get_contents($last_file);
	}

	private function get_last_migration_from_base()
	{
		$result = $this->db->query("
			SELECT `query`
			FROM `{$this->config->migration_db}`.`{$this->config->migration_table}`
			ORDER BY `name`
			DESC LIMIT 1"
		);
		if(!$result->num_rows)
			return false;
		$row = $result->fetch_assoc();
		return $row['query'];
	}
}