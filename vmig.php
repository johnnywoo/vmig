<?php

// dependency: pear package Console_Getopt
// optional dependency: pear package Console_Color

require_once dirname(__FILE__) . '/lib/Vmig/Config.php';
require_once dirname(__FILE__) . '/lib/Vmig.php';

define('EXIT_OK',       0);
define('EXIT_MODIFIED', 1);
define('EXIT_ERROR',    2);

try
{
	$options = array(
		'help',
		'config=',
		'databases=',
		'migrations-path=',
		'schemes-path=',
		'migrations-table=',
		'connection=',
	);
	list($settings, $args) = args('h', $options);

	if(empty($args))
	{
		$cmd = (isset($settings['h']) || isset($settings['help'])) ? 'help' : 'usage';
		$args = array($cmd);
	}

	$command = array_shift($args);

	if(!in_array($command, array('usage', 'help', 'init')))
	{
		$config = Vmig_Config::find(getcwd(), $settings);
		$vmig = new Vmig($config);
	}

	switch($command)
	{
		case 'usage':
		case 'help':
			fwrite(STDERR, trim(file_get_contents(dirname(__FILE__).'/'.$command.'.txt'))."\n");
			exit(EXIT_ERROR);
			break;

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
			list($settings, $args) = args('', array('reverse'), $args);
			$is_reverse = isset($settings['reverse']);
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
			list($settings, $args) = args('', array('force'), $args);
			$is_force = isset($settings['force']);

			if(!empty($args))
			{
				$branch_name = reset($args);
			}
			else
			{
				$branch_name = exec('git branch --no-color 2>/dev/null | sed -e \'/^[^*]/d\' -e \'s/* \(.*\)/\1/\'');
				if($branch_name == 'master')
					$branch_name = ''; // vmig will ask for a name
			}

			$sql = $vmig->create_migrations($is_force, $branch_name ? '_' . $branch_name : '');
			if(trim($sql) != '')
			{
				echo $sql;
				echo "-- Do not forget to APPROVE the created migration before committing!\n";
				echo "-- Otherwise database dump in the repository will be out of sync.\n";
			}
			break;

		case 'reset':
		case 'r':
			$vmig->reset_db();
			break;

		case 'approve':
		case 'a':
			$vmig->approve_migration();
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
			list($settings, $args) = args('', array('from-file', 'from-db'), $args);

			if(!count($args))
				throw new Vmig_Error('Migration name is not specified');

			if(isset($settings['from-file']) && isset($settings['from-db']))
				throw new Vmig_Error('Options --from-file and --from-db cannot be used at the same time');

			$source = null;
			if(isset($settings['from-file']))
				$source = 'from-file';
			if(isset($settings['from-db']))
				$source = 'from-db';

			foreach($args as $arg)
			{
				$vmig->apply_one_migration($arg, $command, $source);
			}
			break;

		default:
			throw new Vmig_Error('Unknown command: '.$command);
	}
}
catch(Exception $e)
{
	fwrite(STDERR, $e->getMessage()."\n");
	exit(EXIT_ERROR);
}

exit(EXIT_OK);



/**
 * @param string $short
 * @param array $long
 * @param array $argv if not given, global $argv is used
 * @return array array(settings=>array(), args=>array())
 */
function args($short = '', array $long = array(), $argv = null)
{
	@include_once 'Console/Getopt.php';
	if(!class_exists('Console_Getopt'))
		throw new Vmig_Error('PEAR package Console_Getopt must be installed and visible in include_path');

	$cg = new Console_Getopt();

	if(is_null($argv))
	{
		$argv = $cg->readPHPArgv();
		array_shift($argv); // removing script name
	}

	$params = $cg->getopt2($argv, $short, $long);
	if(PEAR::isError($params))
		throw new Vmig_Error('Error: ' . $params->getMessage());

	$settings = array();
	foreach($params[0] as $row)
	{
		$name = preg_replace('/^--/', '', $row[0]);
		$settings[$name] = is_null($row[1]) ? '' : $row[1];
	}

	return array(
		$settings,
		$params[1],
	);
}
