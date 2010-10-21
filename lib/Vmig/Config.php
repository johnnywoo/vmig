<?

class Vmig_Config
{
	const DEFAULT_CONF_FILE = '.vmig.cnf';

	/**
	 * @param string $cwd
	 * @param array $options
	 * @return Vmig_Config
	 */
	public static function find($cwd, array $options = array())
	{
		// CLI paths are relative to CWD, not config
		if(isset($options['migrations-path']))
			$options['migrations-path'] = self::absolutize_path($options['migrations-path'], $cwd);
		if(isset($options['schemes-path']))
			$options['schemes-path'] = self::absolutize_path($options['schemes-path'], $cwd);

		if(isset($options['config']))
		{
			$file = $options['config'];
		}
		else
		{
			while(true)
			{
				$file = $cwd.DIRECTORY_SEPARATOR.self::DEFAULT_CONF_FILE;
				if(file_exists($file))
					break; // found

				$next = dirname($cwd);
				if($next == $cwd)
				{
					// config file is not found
					if(self::is_config_required($options))
						throw new Vmig_Error('Not a vmig project: '.self::DEFAULT_CONF_FILE.' is not found');

					return new static($options, $cwd);
				}
				$cwd = $next;
			}
		}
		return static::load($file, $options);
	}

	// we do not need config file if all required parameters were specified in CLI args
	private static function is_config_required($options)
	{
		foreach(self::$required_fields as $name)
		{
			if(empty($options[$name]))
				return false;
		}
		return true;
	}

	/**
	 * @param string $file
	 * @param array $options
	 * @return Vmig_Config
	 */
	public static function load($file, array $options = array())
	{
		$params = parse_ini_file($file);
		if(!is_array($params))
			throw new Vmig_Error('Unable to read config from "'.$file.'"');

		$options += $params; // CLI options override config

		return new static($options, dirname($file));
	}

	private static $required_fields = array('databases', 'migrations-path', 'schemes-path', 'migrations-table');

	public $migrations_path = '';
	public $schemes_path    = '';
	public $migration_db    = '';
	public $migration_table = '';
	public $connection      = '';
	public $fail_on_down    = '';
	public $no_color    = '';

	public $databases = array();

	public function __construct(array $params, $dir = '')
	{
		foreach(self::$required_fields as $name)
		{
			if(empty($params[$name]))
				throw new Vmig_Error('Config parameter "'.$name.'" is not set');
		}

		$this->migrations_path = self::absolutize_path($params['migrations-path'], $dir);
		$this->schemes_path    = self::absolutize_path($params['schemes-path'], $dir);

		$parts = explode('.', $params['migrations-table']);
		if(count($parts) != 2)
		{
			throw new Vmig_Error('Config parameter "migrations-table" should be set as "dbname.tablename"');
		}
		$this->migration_db    = $parts[0];
		$this->migration_table = $parts[1];

		$this->databases = preg_split('/\s+/', $params['databases'], -1, PREG_SPLIT_NO_EMPTY);

		// mysql driver will try to find the connection in .my.cnf
		// so this is not a required parameter
		$this->connection = isset($params['connection']) ? $params['connection'] : '';

		$this->fail_on_down = isset($params['fail-on-down']) && strtolower($params['fail-on-down']) != 'no';
		$this->no_color = isset($params['no-color']) && strtolower($params['no-color']) != 'no';
	}

	private static function absolutize_path($path, $cwd)
	{
		if($cwd == '')
			return $path;

		if(preg_match('@^([a-z]+:[/\\\\]|/)@i', $path))
			return $path;

		return $cwd.DIRECTORY_SEPARATOR.$path;
	}
}