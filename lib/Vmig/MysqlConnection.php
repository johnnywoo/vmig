<?

require_once dirname(__FILE__).'/MysqlError.php';

class Vmig_MysqlConnection
{
	public $host     = '';
	public $port     = '';
	public $user     = '';
	public $password = '';
	public $charset  = '';

	public $mysql_client = 'mysql';

	/**
	 * @var mysqli
	 */
	private $_connection;

	/**
	 * @param string $dsn mysql://user:pass@host:port
	 * @param string $charset
	 * @param string $mysql_client
	 */
	public function __construct($dsn, $charset = 'cp1251', $mysql_client = 'mysql')
	{
		$p = parse_url($dsn);
		if(isset($p['scheme']) && strtolower($p['scheme']) == 'mysql')
		{
			$this->host     = (string) @$p['host'];
			$this->port     = (string) @$p['port'];
			$this->user     = (string) @$p['user'];
			$this->password = (string) @$p['pass'];
		}
		$this->charset = $charset;

		$this->mysql_client = $mysql_client;

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
	 * @param string $sql
	 * @return mysqli_result
	 */
	public function query($sql)
	{
		$this->_connect();
		$result = $this->_connection->query($sql);
		if(!$result)
			throw new Vmig_MysqlError('DB error: '.$this->_connection->error, $this->_connection->errno);
		return $result;
	}

	/**
	 * @param string $sql
	 */
	public function execute_sql_script($sql)
	{
		if($this->charset)
		{
			$this->_connect();
			$sql = "SET NAMES ".$this->_connection->real_escape_string($this->charset).";\n\n$sql";
		}

		echo $sql;

		$cmd = escapeshellcmd($this->mysql_client);
		$cmd .= $this->_make_mysql_client_args();
		$cmd .= ' --execute=' . escapeshellarg($sql).' 2>&1';

		exec($cmd, $out, $error_code);
		if($error_code)
			throw new Vmig_MysqlError(join("\n", $out));
	}

	private function _make_mysql_client_args()
	{
		$args = '';

		if($this->host != '')
			$args .= ' --host=' . escapeshellarg($this->host);

		if($this->port != '')
			$args .= ' --port=' . escapeshellarg($this->port);

		if($this->user != '')
			$args .= ' --user=' . escapeshellarg($this->user);

		if($this->password != '')
			$args .= ' --password=' . escapeshellarg($this->password);

		return $args;
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
			$line = exec(escapeshellcmd($this->mysql_client).' --print-defaults', $ret, $code);
			// non-zero code = error
			if($code)
				return;

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
				throw new Vmig_Error('Connect Error ' . mysqli_connect_error());

			$charset = $this->_connection->real_escape_string($this->charset);
			$this->query("SET NAMES {$charset}");
		}
	}

}
