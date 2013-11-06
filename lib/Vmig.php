<?php

namespace Vmig;

require_once __DIR__ . '/Vmig/Config.php';
require_once __DIR__ . '/Vmig/Dump.php';
require_once __DIR__ . '/Vmig/Error.php';
require_once __DIR__ . '/Vmig/Scheme.php';
require_once __DIR__ . '/Vmig/SchemesDiff.php';
require_once __DIR__ . '/Vmig/MysqlConnection.php';

class Vmig
{
    /** @var Config */
    public $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function status()
    {
        $statusText = '';

        foreach ($this->config->databases as $db) {
            $dump       = $this->createDump($db);
            $schemeFrom = new Scheme($dump);

            $dumpFile = "{$this->config->schemesPath}/{$db}.scheme.sql";
            $dump     = file_exists($dumpFile) ? file_get_contents($dumpFile) : '';
            $schemeTo = new Scheme($dump);

            $diff   = new SchemesDiff($schemeFrom, $schemeTo, $db);
            $status = $diff->renderStatusText($db);

            if ($status) {
                $statusText .= $status;
            }
        }

        if ($statusText) {
            $statusText = "Database changes:" . $statusText;
        }

        $statusText .= $this->getMigrationsForStatus();

        if (!$this->config->noColor && class_exists('\PEAR') && class_exists('\Console_Color')) {
            $statusText = \Console_Color::convert($statusText);
        } else {
            $statusText = preg_replace('@%[ygrnm]@', '', $statusText);
        }

        echo $statusText;
    }

    private function getMigrationsForStatus()
    {
        list($migrationsDown, $migrationsUp) = $this->findMigrations();

        $renamedMigrations = $this->locateRenamedMigrations($migrationsDown, $migrationsUp);

        if (!count($migrationsUp) && !count($migrationsDown) && !count($renamedMigrations)) {
            return '';
        }

        $status = '';

        if (count($renamedMigrations)) {
            $status .= "\nMigrations to be renamed:\n";
            foreach ($renamedMigrations as $oldName => $newName) {
                $status .= " %m{$oldName} -> {$newName}%n\n";
            }
        }

        if (count($migrationsDown) || count($migrationsUp)) {
            $status .= "\nMigrations to be applied:\n";
        }
        foreach ($migrationsDown as $migrationName => $migration) {
            $color = '%r';
            if (array_key_exists($migrationName, $migrationsUp)) {
                $color = '%y';
            }

            $status .= " {$color}down {$migrationName}%n\n";
        }

        foreach ($migrationsUp as $migrationName => $migration) {
            $color = '%g';
            if (array_key_exists($migrationName, $migrationsDown)) {
                $color = '%y';
            }

            $status .= " {$color}up   {$migrationName}%n\n";
        }

        return $status;
    }


    public function resetDb($forWhat = array())
    {
        $migrations = $this->findMigrationsClassified();
        if (count($migrations['not_applied']) || count($migrations['old_unnecessary'])) {
            echo "Not applied migrations found.\n";
            foreach ($migrations['old_unnecessary'] as $name => $migration) {
                echo "down {$name} \n";
            }
            foreach ($migrations['not_applied'] as $name => $migration) {
                echo "up {$name} \n";
            }

            $answer = '';
            echo 'Reset anyway? (y/N) ';
            do {
                if (!empty($answer)) {
                    echo 'Type "y" or "n" ';
                }

                $answer = fgets(STDIN);
                $answer = rtrim(strtolower($answer));
                if ($answer == "n" || $answer == "") {
                    return;
                }
            } while ($answer != "y");
        }

        $migrationDown = $this->doCreateMigrations(false, $forWhat);
        if (empty($migrationDown)) {
            return;
        }

        $this->applyMigration($migrationDown);
    }


    public function createMigrations($force = false, $nameSuffix = '')
    {
        $mUp = $this->doCreateMigrations();
        if (empty($mUp) && $force === false) {
            return false;
        }

        $migrations = "-- Migration Up\n\n";
        $migrations .= $mUp . "\n\n";
        $migrations .= "-- Migration Down\n\n";
        $migrations .= $this->doCreateMigrations(false) . "\n\n";

        if (!file_exists($this->config->migrationsPath)) {
            if (!mkdir($this->config->migrationsPath, 0755, true)) { // rwxr-xr-x
                throw new Error('Unable to create migrations folder');
            }
        }

        $migrationName = time() . $nameSuffix . '.sql';
        $fname         = $this->config->migrationsPath . '/' . $migrationName;
        if (file_put_contents($fname, $migrations) === false) {
            throw new Error('Unable to write migration to "' . $fname . '"');
        }

        return $migrations;
    }


    public function diff($reverse = false)
    {
        return $this->doCreateMigrations(!$reverse);
    }


    public function approveMigration(array $filenames = array())
    {
        $this->createMigrationTableIfNecessary();
        $db = $this->getDb();

        if (empty($filenames)) {
            $migrations = $this->findMigrationsClassified();
            foreach ($migrations['old_unnecessary'] as $name => $migration) {
                $name = $db->escape($name);
                $db->query("
                    DELETE FROM `{$this->config->migrationDb}`.`{$this->config->migrationTable}`
                    WHERE name = '{$name}'
                ");
            }

            $toApprove = array_merge($migrations['not_applied'], $migrations['changed']);
        } else {
            $toApprove = array();
            foreach ($filenames as $filename) {
                $toApprove += $this->getMigrationsFromFiles($filename);
            }
        }

        $values = array();
        foreach ($toApprove as $filename => $migration) {
            $filename = $db->escape($filename);
            $query    = $db->escape($migration['query']);
            $sha1     = $db->escape($migration['sha1']);
            $values[] = "('{$filename}', '{$query}', '{$sha1}')";
        }

        if (count($values)) {
            $values = implode(', ', $values);
            $db->query("
                INSERT INTO `{$this->config->migrationDb}`.`{$this->config->migrationTable}` (name, query, sha1) VALUES {$values}
                ON DUPLICATE KEY UPDATE query = VALUES(query), sha1 = VALUES(sha1);
            ");
        }

        if (empty($filenames)) {
            $this->createNewDumps();
        }

        return true;
    }


    public function migrate()
    {
        $this->createMigrationTableIfNecessary();

        list($migrationsDown, $migrationsUp) = $this->findMigrations();

        $renamedMigrations = $this->locateRenamedMigrations($migrationsDown, $migrationsUp);

        $this->renameMigrations($renamedMigrations);
        $this->migrateDown($migrationsDown);
        $this->migrateUp($migrationsUp);

        if (count($migrationsDown) || count($migrationsUp) || count($renamedMigrations)) {
            echo "\n";
        }

        return true;
    }


    public function applyOneMigration($name, $direction, $source)
    {
        $fileMigration = $this->getMigrationsFromFiles($name);
        $dbMigration   = $this->getMigrationsFromDb($name);

        if (empty($source)) {
            // if source is not given, but the file is equal to db version,
            // there's no need to choose between two
            if ($fileMigration == $dbMigration) {
                $fileMigration = array();
            }

            if (count($fileMigration) && count($dbMigration)) {
                throw new Error("Db and file versions of {$name} are different.\nYou need to pick one with --from-file or --from-db option.");
            }

            $source = count($fileMigration) ? 'from-file' : 'from-db';
        }

        $migration = ($source == 'from-file') ? $fileMigration : $dbMigration;

        if (!count($migration)) {
            throw new Error("Migration {$name} not found");
        }

        if ($direction == 'up') {
            $this->migrateUp($migration);
        } else {
            $this->migrateDown($migration);
        }
    }


    private $db;

    /**
     * @return MysqlConnection
     */
    private function getDb()
    {
        if (!$this->db) {
            $this->db = new MysqlConnection($this->config->connection, $this->config->charset);
        }

        return $this->db;
    }

    private function createNewDumps()
    {
        foreach ($this->config->databases as $db) {
            $schemeFile = "{$this->config->schemesPath}/{$db}.scheme.sql";
            $this->createDump($db, $schemeFile);
        }

        return true;
    }


    private function doCreateMigrations($up = true, $forWhat = array())
    {
        if (!sizeof($forWhat)) {
            $databases = $this->config->databases;
        } else {
            // pick only DBs that are present in config->databases
            $databases = array_intersect(array_keys($forWhat), $this->config->databases);
        }

        $migrations = array(
            'add_triggers'      => array(),
            'drop_triggers'     => array(),
            'add_foreign_keys'  => array(),
            'drop_foreign_keys' => array(),
            'add_views'         => array(),
            'drop_views'        => array(),
            'alter_views'       => array(),
            'add_tables'        => array(),
            'drop_tables'       => array(),
            'alter_tables'      => array(),
        );

        foreach ($databases as $db) {
            $dump       = $this->createDump($db);
            $schemeFrom = new Scheme($dump);

            $dumpFile = "{$this->config->schemesPath}/{$db}.scheme.sql";
            $dump     = file_exists($dumpFile) ? file_get_contents($dumpFile) : '';
            $schemeTo = new Scheme($dump);

            if (!$up) {
                list($schemeFrom, $schemeTo) = array($schemeTo, $schemeFrom);
            }

            if (!array_key_exists($db, $forWhat)) {
                $tables = array();
            } else {
                $tables = $forWhat[$db];
            }

            $diff      = new SchemesDiff($schemeFrom, $schemeTo, $db, $tables);
            $migration = $diff->renderMigration();

            $migrations = array_merge_recursive($migrations, $migration);
        }

        $content = '';
        $content .= implode('', $migrations['drop_triggers']);
        $content .= implode('', $migrations['drop_foreign_keys']);
        $content .= implode('', $migrations['drop_views']);
        $content .= implode('', $migrations['drop_tables']);
        $content .= implode('', $migrations['add_tables']);
        $content .= implode('', $migrations['alter_tables']);
        $content .= implode('', $migrations['add_views']);
        $content .= implode('', $migrations['alter_views']);
        $content .= implode('', $migrations['add_foreign_keys']);
        $content .= implode('', $migrations['add_triggers']);

        return $content;
    }


    /**
     * @param string $dbname
     * @param string $dumpFile if a file is given, the dump is written into it; otherwise it is returned
     * @return string
     */
    private function createDump($dbname, $dumpFile = '')
    {
        // migrations table might be in a watched DB
        // so if it does not exist, it will be skipped on first run if we don't create it here
        $this->createMigrationTableIfNecessary();

        $dump = Dump::create($this->getDb(), $dbname);

        if ($dumpFile != '') {
            file_put_contents($dumpFile, $dump->sql);
        }

        return $dump->sql;
    }


    private function applyMigration($migration)
    {
        $migration = preg_replace('@-- Migration (Down|Up)@', '', $migration);
        $migration = trim($migration) . "\n";

        $this->getDb()->executeSqlScript($migration);
    }

    private function getMigrationsFromFiles($name = '')
    {
        $path = $this->config->migrationsPath;
        if (!file_exists($path) || !is_dir($path)) {
            return array();
        }

        $pattern = !empty($name) ? $name : '*';

        $fileMigrations = array();
        foreach (glob($path . '/' . $pattern) as $file) {
            if (is_file($file)) {
                $migrationQuery = file_get_contents($file);
                $fname          = pathinfo($file, PATHINFO_BASENAME);
                $fileMigrations[$fname] = array(
                    'query' => $migrationQuery,
                    'sha1'  => sha1($migrationQuery),
                );
            }
        }

        $fileMigrations = array_reverse($fileMigrations);
        return $fileMigrations;
    }


    private function getMigrationsFromDb($name = '')
    {
        $condition = array();
        if ($name) {
            $condition[] = "name='" . $this->getDb()->escape($name) . "'";
        }

        $condition = count($condition) ? ' WHERE ' . implode(' AND ', $condition) : '';

        $this->createMigrationTableIfNecessary();

        $r = $this->getDb()->query("
            SELECT `name`, `query`, `sha1`
            FROM `{$this->config->migrationDb}`.`{$this->config->migrationTable}`
            {$condition}
            ORDER BY `name` ASC
        ");

        $dbMigrations = array();
        while ($row = $r->fetch_assoc()) {
            $dbMigrations[$row['name']] = array(
                'query' => $row['query'],
                'sha1'  => $row['sha1'],
            );
        }
        $r->close();

        return $dbMigrations;
    }


    private function createMigrationTableIfNecessary()
    {
        $this->getDb()->query("
            CREATE TABLE IF NOT EXISTS `{$this->config->migrationDb}`.`{$this->config->migrationTable}` (
                `id` int(11) NOT NULL auto_increment,
                `name` varchar(255) NOT NULL default '',
                `query` longtext NOT NULL,
                `sha1` VARCHAR(40) NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=cp1251
        ");
    }

    /**
     * Locate renamed migrations
     *
     * @param array $migrationsDown A reference to Migrations Down array
     * @param array $migrationsUp   A reference to Migrations Up array
     * @return array $renamedMigrations Format: 'db_migration_name'=>'file_migration_name'
     */
    private function locateRenamedMigrations(&$migrationsDown, &$migrationsUp)
    {
        $renamedMigrations = array();

        foreach (array_reverse($migrationsDown) as $nameDown => $migrationDown) {
            // we need to reverse to get the right order: "down1..down2..down3" is compared with "up1..up2..up3";
            if (!list($nameUp, $migrationUp) = each($migrationsUp)) {
                // if there are no appropriate migrations from files
                break;
            }

            if ($migrationDown['sha1'] != $migrationUp['sha1']) {
                // if content differs
                break;
            }

            if ($nameDown != $nameUp) {
                // if content is equal, but name differs
                $renamedMigrations[$nameDown] = $nameUp;
            }

            unset($migrationsDown[$nameDown], $migrationsUp[$nameUp]);
        }
        return $renamedMigrations;
    }


    /**
     * Get an actual migration list.
     *
     * @return array ($db_migrations, $file_migrations)
     */
    private function findMigrations()
    {
        $dbMigrations   = $this->getMigrationsFromDb();
        $fileMigrations = $this->getMigrationsFromFiles();

        foreach ($dbMigrations as $dbMigName => $dbMigration) {
            if (!list($fileMigName, $fileMigration) = each($fileMigrations)) {
                break;
            }

            if ($dbMigName == $fileMigName && $dbMigration['sha1'] == $fileMigration['sha1']) {
                unset($dbMigrations[$dbMigName], $fileMigrations[$fileMigName]);
            } else {
                break;
            }
        }

        ksort($dbMigrations);
        $dbMigrations = array_reverse($dbMigrations);
        ksort($fileMigrations);

        return array($dbMigrations, $fileMigrations);
    }


    /**
     * Get an actual migration list and classify it.
     *
     * @return array ($renamed, $oldUnnecessary, $downAndUp, $changed, $notApplied)
     */
    private function findMigrationsClassified()
    {
        list($dbMigrations, $fileMigrations) = $this->findMigrations();

        $renamed = $this->locateRenamedMigrations($dbMigrations, $fileMigrations);

        $notApplied     = array();
        $oldUnnecessary = array();
        $downAndUp      = array();
        $changed        = array();

        foreach ($dbMigrations as $migrationName => $migration) {
            if (array_key_exists($migrationName, $fileMigrations)) {
                if ($fileMigrations[$migrationName]['sha1'] != $migration['sha1']) {
                    $changed[$migrationName] = $fileMigrations[$migrationName];
                } else {
                    $downAndUp[$migrationName] = $migration;
                }
            } else {
                $oldUnnecessary[$migrationName] = $migration;
            }
        }

        foreach ($fileMigrations as $migrationName => $migration) {
            if (!array_key_exists($migrationName, $dbMigrations)) {
                $notApplied[$migrationName] = $migration;
            }
        }

        ksort($renamed);
        ksort($notApplied);
        ksort($oldUnnecessary);
        ksort($downAndUp);
        ksort($changed);

        return array(
            'renamed'         => $renamed, // renamed
            'old_unnecessary' => $oldUnnecessary, // present only in DB
            'down_and_up'     => $downAndUp, // dependent on removed/changed/added
            'changed'         => $changed, // hash differs
            'not_applied'     => $notApplied, // present only in files
        );
    }


    private function migrateDown($migrations)
    {
        foreach ($migrations as $name => $migration) {
            $pos = strpos($migration['query'], '-- Migration Down');

            $downMigration = substr($migration['query'], $pos);

            echo "\n-- down: {$name}\n\n";

            if ($this->config->failOnDown) {
                throw new Error('Down migrations are forbidden by fail-on-down configuration option');
            }

            $this->applyMigration($downMigration);
            $this->disproveMigration($name);
        }
    }


    private function migrateUp($migrations)
    {
        foreach ($migrations as $name => $migration) {
            $pos = strpos($migration['query'], '-- Migration Down');

            $upMigration = substr($migration['query'], 0, $pos);

            echo "\n-- up: {$name}\n\n";
            $this->applyMigration($upMigration);
            $this->doApproveMigration($name, $migration);
        }
    }


    private function doApproveMigration($name, $migration)
    {
        $db = $this->getDb();

        $name  = $db->escape($name);
        $query = $db->escape($migration['query']);
        $sha1  = $db->escape($migration['sha1']);

        $db->query("
            INSERT INTO `{$this->config->migrationDb}`.`{$this->config->migrationTable}` (name, query, sha1)
            VALUES ('{$name}', '{$query}', '{$sha1}')
            ON DUPLICATE KEY UPDATE query = VALUES(query), sha1 = VALUES(sha1)
        ");
    }

    public function disproveMigration($name)
    {
        $db = $this->getDb();

        $name = $db->escape($name);
        $db->query("DELETE FROM `{$this->config->migrationDb}`.`{$this->config->migrationTable}` WHERE name = '{$name}'");
    }

    /**
     * Rename migrations in db
     *
     * @param array $renamedMigrations ($old_name => $new_name)
     */
    private function renameMigrations($renamedMigrations = array())
    {
        $db = $this->getDb();

        foreach ($renamedMigrations as $oldName => $newName) {
            echo "\n-- rename: {$oldName} -> {$newName}\n\n";

            $oldName = $db->escape($oldName);
            $newName = $db->escape($newName);

            $db->query("UPDATE `{$this->config->migrationDb}`.`{$this->config->migrationTable}` SET `name` = '{$newName}' WHERE `name` = '{$oldName}'");
        }
    }
}
