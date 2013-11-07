<?php

namespace Vmig;

class Config
{
    const DEFAULT_CONF_FILE = '.vmig.cnf';

    /**
     * @param string $cwd
     * @param array $options
     * @throws Error
     * @return Config
     */
    public static function find($cwd, array $options = array())
    {
        // CLI paths are relative to CWD, not config
        if (isset($options['migrations-path'])) {
            $options['migrations-path'] = self::absolutizePath($options['migrations-path'], $cwd);
        }
        if (isset($options['schemes-path'])) {
            $options['schemes-path'] = self::absolutizePath($options['schemes-path'], $cwd);
        }

        $file = '';
        if (isset($options['config'])) {
            $file = $options['config'];
        } else {
            while (true) {
                $file = $cwd . DIRECTORY_SEPARATOR . self::DEFAULT_CONF_FILE;
                if (file_exists($file)) {
                    break;
                } // found

                $next = dirname($cwd);
                if ($next == $cwd) {
                    // config file is not found
                    if (self::isConfigRequired($options)) {
                        throw new Error('Not a vmig project: ' . self::DEFAULT_CONF_FILE . ' is not found');
                    }

                    return new static($options, $cwd);
                }
                $cwd = $next;
            }
        }
        return static::load($file, $options);
    }

    // we do not need config file if all required parameters were specified in CLI args
    private static function isConfigRequired($options)
    {
        foreach (self::$requiredFields as $name) {
            if (isset($options[$name])) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param string $file
     * @param array $options
     * @throws Error
     * @return Config
     */
    public static function load($file, array $options = array())
    {
        $params = parse_ini_file($file);
        if (!is_array($params)) {
            throw new Error('Unable to read config from "' . $file . '"');
        }

        $options += $params; // CLI options override config

        return new static($options, dirname($file));
    }

    private static $requiredFields = array('migrations-path', 'schemes-path');

    const DEFAULT_MIGRATIONS_TABLE_FOR_SINGLE_DB = 'migrations';

    public $migrationsPath = '';
    public $schemesPath    = '';
    public $migrationDb    = '';
    public $migrationTable = '';
    public $connection     = '';
    public $mysqlClient    = '';
    public $failOnDown     = '';
    public $noColor        = false;
    public $charset        = '';
    public $singleDatabase = '';
    public $namePrefix     = '';
    public $databases      = array();

    public function __construct(array $params, $dir = '')
    {
        foreach (self::$requiredFields as $name) {
            if (empty($params[$name])) {
                throw new Error('Config parameter "' . $name . '" is not set');
            }
        }

        $this->migrationsPath = self::absolutizePath($params['migrations-path'], $dir);
        $this->schemesPath    = self::absolutizePath($params['schemes-path'], $dir);

        if (empty($params['single-database']) && empty($params['databases'])) {
            throw new Error('Please set up "single-database" or "databases" in config or vmig arguments');
        }

        if (!empty($params['single-database']) && !empty($params['databases'])) {
            throw new Error('Config parameters "single-database" and "databases" are mutually exclusive');
        }

        if (!empty($params['databases'])) {
            $this->databases = preg_split('/\s+/', $params['databases'], -1, PREG_SPLIT_NO_EMPTY);
        } else {
            $this->singleDatabase = $params['single-database'];
        }

        $parts = explode('.', empty($params['migrations-table']) ? '' : $params['migrations-table']);
        if (count($parts) != 2) {
            if ($this->singleDatabase) {
                // single-database mode: we have a default database and table
                if (empty($params['migrations-table'])) {
                    $parts = array(static::DEFAULT_MIGRATIONS_TABLE_FOR_SINGLE_DB);
                }
                array_unshift($parts, $this->singleDatabase);
            } else {
                throw new Error('Config parameter "migrations-table" should be set as "dbname.tablename"');
            }
        }

        $this->migrationDb    = $parts[0];
        $this->migrationTable = $parts[1];

        $this->namePrefix = isset($params['name-prefix']) ? $params['name-prefix'] : '';


        // mysql driver will try to find the connection in .my.cnf
        // so this is not a required parameter
        $this->connection = isset($params['connection']) ? $params['connection'] : '';

        $this->mysqlClient = isset($params['mysql-client']) ? $params['mysql-client'] : 'mysql';

        $this->charset = isset($params['charset']) ? $params['charset'] : 'cp1251';

        // boolean values: "", "no" and false is off; "yes" and true is on (strings from file config, bool from CLI options)
        $this->failOnDown = (!empty($params['fail-on-down']) && strtolower($params['fail-on-down']) != 'no');
        $this->noColor    = (!empty($params['no-color']) && strtolower($params['no-color']) != 'no');
    }

    /**
     * @return array realDbname => alias
     */
    public function getDatabases()
    {
        if ($this->singleDatabase) {
            return array($this->singleDatabase => 'db');
        }
        return array_combine($this->databases, $this->databases);
    }

    private static function absolutizePath($path, $cwd)
    {
        if ($cwd == '') {
            return $path;
        }

        if (preg_match('@^([a-z]+:[/\\\\]|/)@i', $path)) {
            return $path;
        }

        return $cwd . DIRECTORY_SEPARATOR . $path;
    }
}
