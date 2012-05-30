<?php

// optional dependency: pear package Console_Color

require_once dirname(__FILE__) . '/lib/Vmig/Config.php';
require_once dirname(__FILE__) . '/lib/Vmig.php';
require_once dirname(__FILE__) . '/vendor/cliff/lib/Cliff.php';

use cliff\Cliff;
use cliff\Exception_ParseError;

define('EXIT_OK',       0);
define('EXIT_MODIFIED', 1);
define('EXIT_ERROR',    2);

Cliff::$error_exit_code = EXIT_ERROR;
Cliff::run(Cliff::config()

	->desc('
		Vmig: MySQL database migrations manager

		Migrations config is read from `.vmig.cnf`, which is searched in current
		working directory and its parents.

		Note: you should not symlink vmig executable into your PATH, because it calls
		vmig.php relative to its own position and therefore link would not work.
		Instead, you should either make an alias or a proxy script with absolute path.
	')

	->option('--config', 'Use this file as config instead of searching for .vmig.cnf')
	->option('--connection', '
		MySQL connection DSN (mysql://user:pass@host:port)

		If not given, default parameters are read from mysql console client
		(essentially from ~/.my.cnf). You may review them by running
		`mysql --print-defaults`.
	')
	->option('--mysql-client', '
		MySQL client filename to run migrations with

		Default is just `mysql`, i.e. assuming the client is in PATH.
	')
	->option('--databases', 'List of database names which are watched for migrations')
	->flag('--fail-on-down', '
		Makes vmig fail whenever it has to roll a migration down

		Useful in production environment.
	')
	->option('--migrations-path', 'Path to migrations folder')
	->option('--schemes-path', 'Path to schemes folder')
	->option('--migrations-table', '
		Table name for migration data

		Format: `db.table`. The table will be created if not exists; database must exist.
	')
	->flag('--no-color', 'No color in the output')

	//
	// COMMANDS
	//

	->command('init', Cliff::config()
		->desc('Places default .vmig.cnf in current working directory')
	)

	->command('diff d', Cliff::config()
		->desc('
			Compares dump and database

			Exits with status 1 if changes present; prints found differences as SQL
			(commands to convert dumped scheme into actual).
		')
		->flag('--reverse -r', '
			Invert diff direction

			Print commands to convert actual scheme into dumped.
		')
	)

	->command('create c', Cliff::config()
		->desc('
			Creates a new migration by comparing dump and database

			The migration is placed in migrations_path from the config and also printed to stdout.
			If dump and database are equal, the empty migration is not created.
			If `name` is given, it is used as a name for the migration.
			Otherwise current git branch name is used (except for master).
			After being created, the migration is approved.
		')
		->flag('--force -f', 'Create an empty migration even if no changes were found')
		->flag('--no-approve -A', '
			If set, the migration will not be auto-approved

			You have to run "vmig approve" after that.
		')
		->param('name', array(
			'Migration name that goes into its filename',
			'is_required' => false,
		))
	)

	->command('reset r', Cliff::config()
		->desc('
			Alters database to structure from dump

			You can tell vmig what to reset by setting db1, db2 etc.
		')
		->many_params('db_or_table', array(
			'Database or table names to reset

			Format: `db[.table]`.',
			'is_required' => false,
		))
	)

	->command('approve a', Cliff::config()
		->desc('
			Marks migrations as applied without actually running them

			If filenames are not given, all existing migrations will be marked.
			In this case database schemes will be dumped.
		')
		->many_params('filename', array(
			'Names of migrations to approve (without paths!)',
			'is_required' => false,
		))
	)

	->command('migrate m', Cliff::config()
		->desc('
			Migrates the database to current version (as defined by migration files)

			1. Finds applied migrations that are not present in filesystem, runs them down.
			2. Finds non-applied migrations, runs them up.
		')
	)

	->command('status s', Cliff::config()
		->desc('
			Shows database changes and out-of-sync migrations in human-readable format

			Install Console_Color package from PEAR to get colored output.
		')
	)

	->command('up', Cliff::config()
		->desc('
			Apply one migration

			If migration with given filename exists both in db and file,
			you need to specify source with one of parameters; otherwise parameter is optional.
		')
		->flag('--from-file -f',  'Use migration from filesystem')
		->flag('--from-db -d',    'Use migration from database')
		->flag('--no-execute -n', 'Do not execute any SQL, only manage approved status')
		->many_params('filename', 'Names of migrations to apply (without paths!)')
	)

	->command('down', Cliff::config()
		->desc('
			Rollback one migration

			If migration with given filename exists both in db and file,
			you need to specify source with one of parameters; otherwise parameter is optional.
		')
		->flag('--from-file -f',  'Use migration from filesystem')
		->flag('--from-db -d',    'Use migration from database')
		->flag('--no-execute -n', 'Do not execute any SQL, only manage approved status')
		->many_params('filename', 'Names of migrations to rollback (without paths!)')
	)
);


$command = $_REQUEST['command'];
$command_options = $_REQUEST[$command];

$options = array_filter($_REQUEST, function($val) {
	return !is_null($val);
});

$config = Vmig_Config::find(getcwd(), $options);
$vmig = new Vmig($config);

switch($command)
{
	case 'init':
		echo "Creating default config in ".Vmig_Config::DEFAULT_CONF_FILE."\n";
		copy(dirname(__FILE__).'/example.vmig.cnf', Vmig_Config::DEFAULT_CONF_FILE);

		$config = Vmig_Config::load(Vmig_Config::DEFAULT_CONF_FILE);
		if(!file_exists($config->schemes_path))
		{
			echo "Creating directory for db schemes: {$config->schemes_path}\n";
			mkdir($config->schemes_path, 0777, true);
		}
		else
		{
			echo "Using directory for db schemes: {$config->schemes_path}\n";
		}

		if(!file_exists($config->migrations_path))
		{
			echo "Creating directory for migrations: {$config->migrations_path}\n";
			mkdir($config->migrations_path, 0777, true);
		}
		else
		{
			echo "Using directory for migrations: {$config->migrations_path}\n";
		}
		break;

	case 'diff':
	case 'd':
		$is_reverse = $command_options['reverse'];
		$diff = $vmig->diff($is_reverse);
		if(!empty($diff))
		{
			if($is_reverse)
				echo "\n-- These queries will destroy your changes to database:\n\n";
			else
				echo "\n-- These queries were applied to your database:\n\n";

			echo $diff;
			exit(EXIT_MODIFIED);
		}
		break;

	case 'create':
	case 'c':
		$is_force   = $command_options['force'];
		$no_approve = $command_options['no-approve'];
		$name       = $command_options['name'];

		if($name === null)
		{
			// assuming git branch name
			$name = exec('git branch --no-color 2>/dev/null | sed -e \'/^[^*]/d\' -e \'s/* \(.*\)/\1/\'');
			if($name == 'master')
				$name = '';

			// if there is no name, let's ask for it
			if($name == '')
			{
				do
				{
					echo "Please enter a name for the migration (filename will be NNNNNNNNNN_name.sql):\n";
					$name = fgets(STDIN);
					$name = trim($name);
				} while (!preg_match('/^\w+$/', $name)); // don't allow empty names and weird chars
			}
		}

		$sql = $vmig->create_migrations($is_force, $name ? '_' . $name : '');
		if(trim($sql) != '')
		{
			echo $sql;
			if($no_approve)
			{
				echo "-- Do not forget to APPROVE the created migration before committing!\n";
				echo "-- Otherwise database dump in the repository will be out of sync.\n";
			}
			else
			{
				echo "-- Approving created migration...\n";
				$vmig->approve_migration();
			}
		}
		break;

	case 'reset':
	case 'r':
		$for_what = array();
		foreach($command_options['db_or_table'] as $v)
		{
			@list($db, $table) = explode('.', $v);
			if($db)
			{
				if($table && (!array_key_exists($db, $for_what) || sizeof($for_what[$db]) > 0))
				{
					$for_what[$db][] = $table;
				}
				else
				{
					$for_what[$db] = array();
				}
			}
		}
		$vmig->reset_db($for_what);
		break;

	case 'approve':
	case 'a':
		$vmig->approve_migration($command_options['filename']);
		break;

	case 'migrate':
	case 'm':
		$vmig->migrate();
		break;

	case 'status':
	case 's':
		$vmig->status();
		break;

	case 'up':
	case 'down':
		$is_from_file = $command_options['from-file'];
		$is_from_db   = $command_options['from-db'];
		$no_execute   = $command_options['no-execute'];
		$files        = $command_options['filename'];

		if(!count($files))
			throw new Vmig_Error('Migration name is not specified');

		if($no_execute)
		{
			if($command == 'down')
			{
				foreach($files as $file_name)
				{
					$vmig->disprove_migration($file_name);
				}
			}
			else
			{
				$vmig->approve_migration($files);
			}
			break;
		}

		if($is_from_file && $is_from_db)
			throw new Exception_ParseError('Options --from-file and --from-db cannot be used at the same time');

		$source = null;
		if($is_from_file)
			$source = 'from-file';
		if($is_from_db)
			$source = 'from-db';

		foreach($files as $arg)
		{
			$vmig->apply_one_migration($arg, $command, $source);
		}
		break;

	// Cliff makes sure there is a valid command
}

exit(EXIT_OK);
