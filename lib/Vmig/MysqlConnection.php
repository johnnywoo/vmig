<?

require_once dirname(__FILE__).'/MysqlError.php';

class Vmig_MysqlConnection
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
			throw new Vmig_MysqlError('DB error: '.$this->_connection->error, $this->_connection->errno);

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
				throw new Vmig_Error('Connect Error ' . mysqli_connect_error());

			$this->query('SET NAMES cp1251');
		}
	}

}
