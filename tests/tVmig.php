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

	private $samples = array();

	public function __construct($name = NULL, array $data = array(), $dataName = '')
	{
		parent::__construct($name, $data, $dataName);

		$path = dirname(__FILE__);
		$samples_path = $path.'/sample_migrations';
		$this->config = Vmig_Config::find($path);

		$this->db = new Vmig_MysqlConnection('');
		$this->db->query('SELECT 1'); // will throw if unable to connect

		$this->test_dbname = reset($this->config->databases);

		//fill the samples array. If a required sample is missing, the according test will finish with an error (Undefined index)
		foreach(glob($samples_path.'/*.*') as $fname)
		{
			$this->samples[pathinfo($fname, PATHINFO_FILENAME)] = file_get_contents($fname);
		}

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

		$this->assertEquals($migration, $this->samples['create_approve_migrateUp']);
	}


	function test_approveMigrations()
	{
		$this->db->query("ALTER TABLE `{$this->test_dbname}`.`test1` ADD COLUMN `field100` int(11) NOT NULL;");
		$this->db->query("ALTER TABLE `{$this->test_dbname}`.`test1` ADD COLUMN `field101` varchar(255) NOT NULL;");
		$this->exec('create approve');
		$this->exec('approve');
		$migration = $this->get_last_migration_from_base();

		$this->assertEquals($migration, $this->samples['create_approve_migrateUp']);
	}

	function test_approveSelectedMigrations()
	{
		// status = 0
		$this->assertEquals(false, $this->get_last_migration_from_base());

		// create 2 migrations with bad sql
		file_put_contents($this->config->migrations_path . '/a.sql', 'invalid sql');
		file_put_contents($this->config->migrations_path . '/b.sql', 'invalid sql 2');

		// approve one
		$this->exec('approve a.sql');
		$this->assertEquals('invalid sql', $this->get_last_migration_from_base());

		// approve all
		$this->exec('approve');
		$this->assertEquals('invalid sql 2', $this->get_last_migration_from_base());
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
		$this->exec('create -A migrate_up');
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
		$this->assertEquals($migration, $this->samples['create_approve_migrateUp']);
	}

	function test_migrateUpNoExec()
	{
		file_put_contents($this->config->migrations_path.'/a.sql', $this->samples['insertRow']);

		$this->exec('up -n a.sql');

		$res = $this->db->query("SELECT count(*) as n FROM `{$this->test_dbname}`.`test1`");
		$row = $res->fetch_assoc();
		$this->assertEquals(0, $row['n']);
		$this->assertEquals($this->samples['insertRow'], $this->get_last_migration_from_base());
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

	function test_migrateDownNoExec()
	{
		file_put_contents($this->config->migrations_path.'/a.sql', $this->samples['insertRow']);
		$this->exec('migrate');

		$res = $this->db->query("SELECT count(*) as n FROM `{$this->test_dbname}`.`test1`");
		$row = $res->fetch_assoc();
		$this->assertEquals(1, $row['n']);

		$this->exec('down -n a.sql');

		$res = $this->db->query("SELECT count(*) as n FROM `{$this->test_dbname}`.`test1`");
		$row = $res->fetch_assoc();
		$this->assertEquals(1, $row['n']);
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
		$this->assertEquals($migration, $this->samples['createTableUp']);
	}


	function test_addIndexDown()
	{
		$this->db->query("ALTER TABLE `{$this->test_dbname}`.`test1` ADD INDEX `field2` (`field2`(10));");
		$this->exec('create index_down');
		$this->exec('approve');
		$this->remove_migrations_files();
		$this->exec('migrate');

		$this->assertFalse($this->get_last_migration_from_base());
	}


	function test_addIndexUp()
	{
		$this->db->query("ALTER TABLE `{$this->test_dbname}`.`test1` ADD INDEX `field2` (`field2`(10));");
		$this->exec('create index_up');
		$this->exec_reset();
		$this->exec('migrate');

		$migration = $this->get_last_migration_from_base();
		$this->assertEquals($migration, $this->samples['addIndexUp']);
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

		$status = $this->exec('--no-color status');
		$this->assertEquals($status, $this->samples['addForeignKey_status']);

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

		$status = $this->exec('--no-color status');
		$this->assertEquals($status, $this->samples['addForeignKey_status']);

		$this->exec('create fk_up');
		$this->exec_reset();
		$this->exec('migrate');

		$migration = $this->get_last_migration_from_base();
		$this->assertEquals($migration, $this->samples['addForeignKeyUp']);
	}


	function test_sortForeignKeys()
	{
		$this->db->query("
			CREATE TABLE `{$this->test_dbname}`.`test100` (
				`id` int(11) NOT NULL auto_increment,
				`field1` int(11) NOT NULL default '0',
				`field2` int(11) NOT NULL default '0',
				PRIMARY KEY  (`id`),
				KEY `field1` (`field1`),
				KEY `field2` (`field2`)
			) ENGINE=InnoDB DEFAULT CHARSET=cp1251;
		");
		$this->db->query("ALTER TABLE `{$this->test_dbname}`.`test100` ADD CONSTRAINT `FK_test1` FOREIGN KEY (`field1`) REFERENCES `test1` (`id`) ON DELETE CASCADE ON UPDATE CASCADE");
		$this->db->query("ALTER TABLE `{$this->test_dbname}`.`test100` ADD CONSTRAINT `FK_test2` FOREIGN KEY (`field2`) REFERENCES `test1` (`id`) ON DELETE CASCADE ON UPDATE CASCADE");

		$this->exec('create fk_sort');

		$dump_file = "{$this->config->schemes_path}/{$this->test_dbname}.scheme.sql";
		$dump = file_exists($dump_file) ? file_get_contents($dump_file) : '';

		$this->assertEquals($dump, $this->samples['sortForeignKeys_dump']);
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
		$this->assertEquals($migration, $this->samples['addViewUp']);
	}


	function test_fieldsPos34()
	{
		// field3 <-> field4
		$this->db->query("ALTER TABLE `{$this->test_dbname}`.`test1` MODIFY `field3` int(11) NOT NULL default '0' AFTER `field4`");
		$this->exec('create pos34');
		$this->exec('approve');
		$migration = $this->get_last_migration_from_base();
		$this->assertEquals($migration, $this->samples['fieldsPos34']);
	}


	function test_fieldsPos2after4()
	{
		$this->db->query("ALTER TABLE `{$this->test_dbname}`.`test1` MODIFY `field2` varchar(255) NOT NULL default '' AFTER `field4`");
		$this->exec('create pos2after4');
		$this->exec('approve');
		$migration = $this->get_last_migration_from_base();
		$this->assertEquals($migration, $this->samples['fieldsPos2after4']);
	}


	function test_fieldsPos15()
	{
		// field1 <-> field5
		$this->db->query("ALTER TABLE `{$this->test_dbname}`.`test1` MODIFY `field1` int(11) NOT NULL default '0' AFTER `field5`");
		$this->db->query("ALTER TABLE `{$this->test_dbname}`.`test1` MODIFY `field5` int(11) NOT NULL default '0' FIRST");
		$this->exec('create pos15');
		$this->exec('approve');
		$migration = $this->get_last_migration_from_base();
		$this->assertEquals($migration, $this->samples['fieldsPos15']);
	}


	function test_fieldsPos4AfterDeleted()
	{
		$this->db->query("ALTER TABLE `{$this->test_dbname}`.`test1` MODIFY `field5` int(11) NOT NULL default '0' AFTER `field2`");
		$this->db->query("ALTER TABLE `{$this->test_dbname}`.`test1` DROP COLUMN `field2`");
		$this->exec('create pos4after_deleted');
		$this->exec('approve');
		$migration = $this->get_last_migration_from_base();
		$this->assertEquals($migration, $this->samples['fieldsPos4AfterDeleted']);
	}

	function test_changeEngineDown()
	{
		$this->db->query("ALTER TABLE `{$this->test_dbname}`.`test1` ENGINE=MyISAM;");
		$this->exec('create engine_down');
		$this->remove_migrations_files();
		$this->exec('migrate');

		$this->assertFalse($this->get_last_migration_from_base());
	}


	function test_changeEngineUp()
	{
		$this->db->query("ALTER TABLE `{$this->test_dbname}`.`test1` ENGINE=MyISAM;");
		$this->exec('create -A engine_up');
		$this->exec_reset();
		$this->exec('migrate');

		$migration = $this->get_last_migration_from_base();
		$this->assertEquals($migration, $this->samples['changeEngineUp']);
	}


	function test_noColorFlag()
	{
		if(!class_exists('PEAR') || !class_exists('Console_Color'))
		{
			$this->markTestSkipped('Class Console_Color does not exist, noColor test skipped.');
		}
		$this->db->query("ALTER TABLE `{$this->test_dbname}`.`test1` ADD COLUMN `field200` int(11) NOT NULL;");
		$status_colored = $this->exec('status');
		$status_not_colored = $this->exec('--no-color status');
		$this->assertNotEquals($status_colored, $status_not_colored);
	}


	function test_locateRenamed()
	{
		$this->db->query("ALTER TABLE `{$this->test_dbname}`.`test1` ADD COLUMN `field301` int(1) NOT NULL;");
		$this->exec('create create_301');
		$this->db->query("ALTER TABLE `{$this->test_dbname}`.`test1` ADD COLUMN `field302` int(1) NOT NULL;");
		$this->exec('create create_302');
		$this->db->query("ALTER TABLE `{$this->test_dbname}`.`test1` ADD COLUMN `field303` int(1) NOT NULL;");
		$this->exec('create create_303');
		$this->db->query("ALTER TABLE `{$this->test_dbname}`.`test1` ADD COLUMN `field304` int(1) NOT NULL;");
		$this->exec('create create_304');

		$files = glob($this->config->migrations_path.'/*');
		$migrations = array();

		$i = 0; //let's rename them to 0001..0004
		foreach($files as $file)
		{
			$i++;
			$new_name = sprintf('%04d', $i).substr(pathinfo($file, PATHINFO_BASENAME),10);
			rename($file, $this->config->migrations_path . '/' . $new_name);
			$migrations[] = $new_name;
		}
		$this->exec('migrate');


		rename($this->config->migrations_path . '/0002_create_302.sql', $this->config->migrations_path . '/0002_create_302_renamed1.sql'); //rename second migration

		$status = $this->exec('--no-color status'); //only rename - no changed migrations
		$this->assertEquals($status, $this->samples['locateRenamed_status1']);

		$migrate_out = $this->exec('migrate');
		$this->assertEquals($migrate_out, $this->samples['locateRenamed_migrate1']);


		rename($this->config->migrations_path . '/0002_create_302_renamed1.sql', $this->config->migrations_path . '/0002_create_302_renamed2.sql'); //rename it back (it becomes renamed again)

		file_put_contents($this->config->migrations_path . '/0003_create_303.sql', ' ', FILE_APPEND); //change a migration AFTER renamed one.

		$status = $this->exec('--no-color status');
		$this->assertEquals($status, $this->samples['locateRenamed_status2']);

		$migrate_out = $this->exec('migrate');
		$this->assertEquals($migrate_out, $this->samples['locateRenamed_migrate2']);


		rename($this->config->migrations_path . '/0002_create_302_renamed2.sql', $this->config->migrations_path . '/0002_create_302_renamed3.sql'); //...and again :)

		file_put_contents($this->config->migrations_path . '/0001_create_301.sql', ' ', FILE_APPEND); //change a migration BEFORE renamed one. (now it wouldn't act as renamed, because of possible dependencies. So it will be down-up'ed)

		$status = $this->exec('--no-color status');
		$this->assertEquals($status, $this->samples['locateRenamed_status3']);

		$migrate_out = $this->exec('migrate');
		$this->assertEquals($migrate_out, $this->samples['locateRenamed_migrate3']);
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