<?php

namespace Vmig;

// you need to setup .my.cnf with correct connection info for mysql
// mysql user should be able to create/drop database named "vmig_test"

require_once __DIR__ . '/../lib/Vmig.php';

class tVmig extends \PHPUnit_Framework_TestCase
{
    /** @var MysqlConnection */
    private $db;

    /** @var Config */
    private $config;

    private $testDbname = '';

    private $migrationsScript = '';

    private $samples = array();

    public function __construct($name = null, array $data = array(), $dataName = '')
    {
        parent::__construct($name, $data, $dataName);

        $path         = dirname(__FILE__);
        $samplesPath = $path . '/sample_migrations';
        $this->config = Config::find($path);

        $this->db = new MysqlConnection('');
        $this->db->query('SELECT 1'); // will throw if unable to connect

        $this->testDbname = reset($this->config->databases);

        //fill the samples array. If a required sample is missing, the according test will finish with an error (Undefined index)
        foreach (glob($samplesPath . '/*.*') as $fname) {
            $this->samples[pathinfo($fname, PATHINFO_FILENAME)] = file_get_contents($fname);
        }

        $this->migrationsScript = 'php ' . dirname(dirname(__FILE__)) . '/vmig.php --config=' . escapeshellarg($path . '/' . Config::DEFAULT_CONF_FILE);

        // just in case
        $this->tearDown();
    }

    protected function setUp()
    {
        $this->db->query("DROP DATABASE IF EXISTS {$this->testDbname}");
        $this->db->query("CREATE DATABASE {$this->testDbname} CHARACTER SET = cp1251");

        $this->db->query(
            "
            CREATE TABLE `{$this->testDbname}`.`test1` (
                `id` int(11) NOT NULL auto_increment,
                `field1` int(11) NOT NULL default '0',
                `field2` varchar(255) NOT NULL default '',
                `field3` int(11) NOT NULL default '0',
                `field4` int(11) NOT NULL default '0',
                `field5` int(11) NOT NULL default '0',
                PRIMARY KEY  (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=cp1251;
        "
        );

        $this->exec('approve');
    }

    protected function tearDown()
    {
        $this->db->query("DROP DATABASE IF EXISTS {$this->testDbname}");
        $this->db->query("DROP TABLE IF EXISTS {$this->config->migrationDb}.{$this->config->migrationTable}");

        $this->removeMigrationsFiles();
        $this->cleanDir($this->config->schemesPath);
    }


    //
    // TESTS
    //

    function test_createMigrations()
    {
        $this->db->query("ALTER TABLE `{$this->testDbname}`.`test1` ADD COLUMN `field100` int(11) NOT NULL;");
        $this->db->query("ALTER TABLE `{$this->testDbname}`.`test1` ADD COLUMN `field101` varchar(255) NOT NULL;");
        $this->exec('create create');
        $migration = $this->getLastMigrationFromFiles();

        $this->assertEquals($migration, $this->samples['create_approve_migrateUp']);
    }


    function test_approveMigrations()
    {
        $this->db->query("ALTER TABLE `{$this->testDbname}`.`test1` ADD COLUMN `field100` int(11) NOT NULL;");
        $this->db->query("ALTER TABLE `{$this->testDbname}`.`test1` ADD COLUMN `field101` varchar(255) NOT NULL;");
        $this->exec('create approve');
        $this->exec('approve');
        $migration = $this->getLastMigrationFromDb();

        $this->assertEquals($migration, $this->samples['create_approve_migrateUp']);
    }

    function test_approveSelectedMigrations()
    {
        // status = 0
        $this->assertEquals(false, $this->getLastMigrationFromDb());

        // create 2 migrations with bad sql
        file_put_contents($this->config->migrationsPath . '/a.sql', 'invalid sql');
        file_put_contents($this->config->migrationsPath . '/b.sql', 'invalid sql 2');

        // approve one
        $this->exec('approve a.sql');
        $this->assertEquals('invalid sql', $this->getLastMigrationFromDb());

        // approve all
        $this->exec('approve');
        $this->assertEquals('invalid sql 2', $this->getLastMigrationFromDb());
    }


    function test_reset()
    {
        $scheme1 = file_get_contents($this->config->schemesPath . '/' . $this->testDbname . '.scheme.sql');

        $this->db->query("ALTER TABLE `{$this->testDbname}`.`test1` ADD COLUMN `field100` int(11) NOT NULL;");
        $this->db->query("ALTER TABLE `{$this->testDbname}`.`test1` ADD COLUMN `field101` varchar(255) NOT NULL;");
        $this->execReset();

        $scheme2 = file_get_contents($this->config->schemesPath . '/' . $this->testDbname . '.scheme.sql');

        $this->assertEquals($scheme1, $scheme2);
    }


    function test_migrateUp()
    {
        $this->db->query("ALTER TABLE `{$this->testDbname}`.`test1` ADD COLUMN `field100` int(11) NOT NULL;");
        $this->db->query("ALTER TABLE `{$this->testDbname}`.`test1` ADD COLUMN `field101` varchar(255) NOT NULL;");
        $this->exec('create -A migrate_up');
        $this->execReset();

        try {
            // diff exits with non-empty code if there are differences
            // our exec() will think there's an error and throw
            $this->exec('diff');
        } catch (\Exception $e) {
            $this->assertEquals('diff', '');
        }
        $this->assertFalse($this->getLastMigrationFromDb());

        $this->exec('migrate');
        $migration = $this->getLastMigrationFromDb();
        $this->assertEquals($migration, $this->samples['create_approve_migrateUp']);
    }

    function test_migrateUpNoExec()
    {
        file_put_contents($this->config->migrationsPath . '/a.sql', $this->samples['insertRow']);

        $this->exec('up -n a.sql');

        $res = $this->db->query("SELECT count(*) as n FROM `{$this->testDbname}`.`test1`");
        $row = $res->fetch_assoc();
        $this->assertEquals(0, $row['n']);
        $this->assertEquals($this->samples['insertRow'], $this->getLastMigrationFromDb());
    }

    function test_migrateDown()
    {
        $this->db->query("ALTER TABLE `{$this->testDbname}`.`test1` ADD COLUMN `field100` int(11) NOT NULL;");
        $this->db->query("ALTER TABLE `{$this->testDbname}`.`test1` ADD COLUMN `field101` varchar(255) NOT NULL;");
        $this->exec('create migrate_down');
        $this->exec('approve');
        $this->removeMigrationsFiles();
        $this->exec('migrate');

        $this->assertFalse($this->getLastMigrationFromDb());
    }

    function test_migrateDownNoExec()
    {
        file_put_contents($this->config->migrationsPath . '/a.sql', $this->samples['insertRow']);
        $this->exec('migrate');

        $res = $this->db->query("SELECT count(*) as n FROM `{$this->testDbname}`.`test1`");
        $row = $res->fetch_assoc();
        $this->assertEquals(1, $row['n']);

        $this->exec('down -n a.sql');

        $res = $this->db->query("SELECT count(*) as n FROM `{$this->testDbname}`.`test1`");
        $row = $res->fetch_assoc();
        $this->assertEquals(1, $row['n']);
        $this->assertFalse($this->getLastMigrationFromDb());
    }


    function test_createTableDown()
    {
        $this->db->query(
            "
            CREATE TABLE `{$this->testDbname}`.`test100` (
                `id` int(11) NOT NULL auto_increment,
                PRIMARY KEY  (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=cp1251;
        "
        );
        $this->exec('create table_down');
        $this->exec('approve');
        $this->removeMigrationsFiles();
        $this->exec('migrate');

        $this->assertFalse($this->getLastMigrationFromDb());
    }


    function test_createTableUp()
    {
        $this->db->query(
            "
            CREATE TABLE `{$this->testDbname}`.`test100` (
                `id` int(11) NOT NULL auto_increment,
                PRIMARY KEY  (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=cp1251;
        "
        );
        $this->exec('create table_up');
        $this->execReset();
        $this->exec('migrate');

        $migration = $this->getLastMigrationFromDb();
        $this->assertEquals($migration, $this->samples['createTableUp']);
    }


    function test_addIndexDown()
    {
        $this->db->query("ALTER TABLE `{$this->testDbname}`.`test1` ADD INDEX `field2` (`field2`(10));");
        $this->exec('create index_down');
        $this->exec('approve');
        $this->removeMigrationsFiles();
        $this->exec('migrate');

        $this->assertFalse($this->getLastMigrationFromDb());
    }


    function test_addIndexUp()
    {
        $this->db->query("ALTER TABLE `{$this->testDbname}`.`test1` ADD INDEX `field2` (`field2`(10));");
        $this->exec('create index_up');
        $this->execReset();
        $this->exec('migrate');

        $migration = $this->getLastMigrationFromDb();
        $this->assertEquals($migration, $this->samples['addIndexUp']);
    }


    function test_addForeignKeyDown()
    {
        $this->db->query(
            "
            CREATE TABLE `{$this->testDbname}`.`test100` (
                `id` int(11) NOT NULL auto_increment,
                `field1` int(11) NOT NULL default '0',
                PRIMARY KEY  (`id`),
                KEY `field1` (`field1`)
            ) ENGINE=InnoDB DEFAULT CHARSET=cp1251;
        "
        );
        $this->db->query("ALTER TABLE `{$this->testDbname}`.`test100` ADD CONSTRAINT `FK_test` FOREIGN KEY (`field1`) REFERENCES `test1` (`id`) ON DELETE CASCADE ON UPDATE CASCADE");

        $status = $this->exec('--no-color status');
        $this->assertEquals($status, $this->samples['addForeignKey_status']);

        $this->exec('create fk_down');
        $this->exec('approve');
        $this->removeMigrationsFiles();
        $this->exec('migrate');

        $this->assertFalse($this->getLastMigrationFromDb());
    }


    function test_addForeignKeyUp()
    {
        $this->db->query(
            "
            CREATE TABLE `{$this->testDbname}`.`test100` (
                `id` int(11) NOT NULL auto_increment,
                `field1` int(11) NOT NULL default '0',
                PRIMARY KEY  (`id`),
                KEY `field1` (`field1`)
            ) ENGINE=InnoDB DEFAULT CHARSET=cp1251;
        "
        );
        $this->db->query("ALTER TABLE `{$this->testDbname}`.`test100` ADD CONSTRAINT `FK_test` FOREIGN KEY (`field1`) REFERENCES `test1` (`id`) ON DELETE CASCADE ON UPDATE CASCADE");

        $status = $this->exec('--no-color status');
        $this->assertEquals($status, $this->samples['addForeignKey_status']);

        $this->exec('create fk_up');
        $this->execReset();
        $this->exec('migrate');

        $migration = $this->getLastMigrationFromDb();
        $this->assertEquals($migration, $this->samples['addForeignKeyUp']);
    }


    function test_sortForeignKeys()
    {
        $this->db->query(
            "
            CREATE TABLE `{$this->testDbname}`.`test100` (
                `id` int(11) NOT NULL auto_increment,
                `field1` int(11) NOT NULL default '0',
                `field2` int(11) NOT NULL default '0',
                PRIMARY KEY  (`id`),
                KEY `field1` (`field1`),
                KEY `field2` (`field2`)
            ) ENGINE=InnoDB DEFAULT CHARSET=cp1251;
        "
        );
        $this->db->query("ALTER TABLE `{$this->testDbname}`.`test100` ADD CONSTRAINT `FK_test1` FOREIGN KEY (`field1`) REFERENCES `test1` (`id`) ON DELETE CASCADE ON UPDATE CASCADE");
        $this->db->query("ALTER TABLE `{$this->testDbname}`.`test100` ADD CONSTRAINT `FK_test2` FOREIGN KEY (`field2`) REFERENCES `test1` (`id`) ON DELETE CASCADE ON UPDATE CASCADE");

        $this->exec('create fk_sort');

        $dumpFile = "{$this->config->schemesPath}/{$this->testDbname}.scheme.sql";
        $dump      = file_exists($dumpFile) ? file_get_contents($dumpFile) : '';

        $this->assertEquals($dump, $this->samples['sortForeignKeys_dump']);
    }


    function test_addViewDown()
    {
        $this->db->query("CREATE VIEW `{$this->testDbname}`.`view1` AS (SELECT id AS iid FROM `{$this->testDbname}`.`test1`);");
        $this->exec('create view_down');
        $this->exec('approve');

        $this->removeMigrationsFiles();
        $this->exec('migrate');

        $this->assertFalse($this->getLastMigrationFromDb());
    }


    function test_addViewUp()
    {
        $this->db->query("CREATE VIEW `{$this->testDbname}`.`view1` AS (SELECT id AS iid FROM `{$this->testDbname}`.`test1`);");

        $this->exec('create view_up');
        $this->execReset();
        $this->exec('migrate');

        $migration = $this->getLastMigrationFromDb();
        $this->assertEquals($migration, $this->samples['addViewUp']);
    }


    function test_fieldsPos34()
    {
        // field3 <-> field4
        $this->db->query("ALTER TABLE `{$this->testDbname}`.`test1` MODIFY `field3` int(11) NOT NULL default '0' AFTER `field4`");
        $this->exec('create pos34');
        $this->exec('approve');
        $migration = $this->getLastMigrationFromDb();
        $this->assertEquals($migration, $this->samples['fieldsPos34']);
    }


    function test_fieldsPos2after4()
    {
        $this->db->query("ALTER TABLE `{$this->testDbname}`.`test1` MODIFY `field2` varchar(255) NOT NULL default '' AFTER `field4`");
        $this->exec('create pos2after4');
        $this->exec('approve');
        $migration = $this->getLastMigrationFromDb();
        $this->assertEquals($migration, $this->samples['fieldsPos2after4']);
    }


    function test_fieldsPos15()
    {
        // field1 <-> field5
        $this->db->query("ALTER TABLE `{$this->testDbname}`.`test1` MODIFY `field1` int(11) NOT NULL default '0' AFTER `field5`");
        $this->db->query("ALTER TABLE `{$this->testDbname}`.`test1` MODIFY `field5` int(11) NOT NULL default '0' FIRST");
        $this->exec('create pos15');
        $this->exec('approve');
        $migration = $this->getLastMigrationFromDb();
        $this->assertEquals($migration, $this->samples['fieldsPos15']);
    }


    function test_fieldsPos4AfterDeleted()
    {
        $this->db->query("ALTER TABLE `{$this->testDbname}`.`test1` MODIFY `field5` int(11) NOT NULL default '0' AFTER `field2`");
        $this->db->query("ALTER TABLE `{$this->testDbname}`.`test1` DROP COLUMN `field2`");
        $this->exec('create pos4after_deleted');
        $this->exec('approve');
        $migration = $this->getLastMigrationFromDb();
        $this->assertEquals($migration, $this->samples['fieldsPos4AfterDeleted']);
    }

    function test_changeEngineDown()
    {
        $this->db->query("ALTER TABLE `{$this->testDbname}`.`test1` ENGINE=MyISAM;");
        $this->exec('create engine_down');
        $this->removeMigrationsFiles();
        $this->exec('migrate');

        $this->assertFalse($this->getLastMigrationFromDb());
    }


    function test_changeEngineUp()
    {
        $this->db->query("ALTER TABLE `{$this->testDbname}`.`test1` ENGINE=MyISAM;");
        $this->exec('create -A engine_up');
        $this->execReset();
        $this->exec('migrate');

        $migration = $this->getLastMigrationFromDb();
        $this->assertEquals($migration, $this->samples['changeEngineUp']);
    }


    function test_noColorFlag()
    {
        if (!class_exists('PEAR') || !class_exists('Console_Color')) {
            $this->markTestSkipped('Class Console_Color does not exist, noColor test skipped.');
        }
        $this->db->query("ALTER TABLE `{$this->testDbname}`.`test1` ADD COLUMN `field200` int(11) NOT NULL;");
        $statusColored     = $this->exec('status');
        $statusNotColored = $this->exec('--no-color status');
        $this->assertNotEquals($statusColored, $statusNotColored);
    }


    function test_locateRenamed()
    {
        $this->db->query("ALTER TABLE `{$this->testDbname}`.`test1` ADD COLUMN `field301` int(1) NOT NULL;");
        $this->exec('create create_301');
        $this->db->query("ALTER TABLE `{$this->testDbname}`.`test1` ADD COLUMN `field302` int(1) NOT NULL;");
        $this->exec('create create_302');
        $this->db->query("ALTER TABLE `{$this->testDbname}`.`test1` ADD COLUMN `field303` int(1) NOT NULL;");
        $this->exec('create create_303');
        $this->db->query("ALTER TABLE `{$this->testDbname}`.`test1` ADD COLUMN `field304` int(1) NOT NULL;");
        $this->exec('create create_304');

        $files      = glob($this->config->migrationsPath . '/*');
        $migrations = array();

        $i = 0; //let's rename them to 0001..0004
        foreach ($files as $file) {
            $i++;
            $newName = sprintf('%04d', $i) . substr(pathinfo($file, PATHINFO_BASENAME), 10);
            rename($file, $this->config->migrationsPath . '/' . $newName);
            $migrations[] = $newName;
        }
        $this->exec('migrate');

        rename($this->config->migrationsPath . '/0002_create_302.sql', $this->config->migrationsPath . '/0002_create_302_renamed1.sql'); //rename second migration

        $status = $this->exec('--no-color status'); //only rename - no changed migrations
        $this->assertEquals($status, $this->samples['locateRenamed_status1']);

        $migrateOut = $this->exec('migrate');
        $this->assertEquals($migrateOut, $this->samples['locateRenamed_migrate1']);

        rename($this->config->migrationsPath . '/0002_create_302_renamed1.sql', $this->config->migrationsPath . '/0002_create_302_renamed2.sql'); //rename it back (it becomes renamed again)

        file_put_contents($this->config->migrationsPath . '/0003_create_303.sql', ' ', FILE_APPEND); //change a migration AFTER renamed one.

        $status = $this->exec('--no-color status');
        $this->assertEquals($status, $this->samples['locateRenamed_status2']);

        $migrateOut = $this->exec('migrate');
        $this->assertEquals($migrateOut, $this->samples['locateRenamed_migrate2']);

        rename($this->config->migrationsPath . '/0002_create_302_renamed2.sql', $this->config->migrationsPath . '/0002_create_302_renamed3.sql'); //...and again :)

        file_put_contents($this->config->migrationsPath . '/0001_create_301.sql', ' ', FILE_APPEND); //change a migration BEFORE renamed one. (now it wouldn't act as renamed, because of possible dependencies. So it will be down-up'ed)

        $status = $this->exec('--no-color status');
        $this->assertEquals($status, $this->samples['locateRenamed_status3']);

        $migrateOut = $this->exec('migrate');
        $this->assertEquals($migrateOut, $this->samples['locateRenamed_migrate3']);
    }

    //
    // TOOLS
    //

    private function exec($command, $prefix = '')
    {
        $cmd = $prefix . $this->migrationsScript . ' ' . $command;
        exec($cmd, $out, $err);
        if ($err) {
            throw new \Exception('Error in exec: ' . $cmd);
        }
        return join("\n", $out);
    }

    private function execReset()
    {
        return $this->exec('reset', 'echo y | ');
    }

    private function removeMigrationsFiles()
    {
        $this->cleanDir($this->config->migrationsPath);
    }

    private function cleanDir($dir)
    {
        foreach (glob($dir . '/*') as $f) {
            unlink($f);
        }
    }

    private function getLastMigrationFromFiles()
    {
        $files = glob($this->config->migrationsPath . '/*');
        if (empty($files)) {
            return false;
        }
        $lastFile = array_pop($files);
        return file_get_contents($lastFile);
    }

    private function getLastMigrationFromDb()
    {
        $result = $this->db->query(
            "
            SELECT `query`
            FROM `{$this->config->migrationDb}`.`{$this->config->migrationTable}`
            ORDER BY `name`
            DESC LIMIT 1"
        );
        if (!$result->num_rows) {
            return false;
        }
        $row = $result->fetch_assoc();
        return $row['query'];
    }
}
