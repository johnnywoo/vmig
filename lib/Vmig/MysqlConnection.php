<?php

namespace Vmig;

require_once __DIR__ . '/MysqlError.php';

class MysqlConnection
{
    public $host     = '';
    public $port     = '';
    public $user     = '';
    public $password = '';
    public $charset  = '';

    public $mysqlClient = 'mysql';

    /**
     * @var \mysqli
     */
    private $connection;

    /**
     * @param string $dsn mysql://user:pass@host:port
     * @param string $charset
     * @param string $mysqlClient
     */
    public function __construct($dsn, $charset = 'utf8', $mysqlClient = 'mysql')
    {
        $p = parse_url($dsn);
        if (isset($p['scheme']) && strtolower($p['scheme']) == 'mysql') {
            $this->host     = (string) @$p['host'];
            $this->port     = (string) @$p['port'];
            $this->user     = (string) @$p['user'];
            $this->password = (string) @$p['pass'];
        }
        $this->charset = $charset;

        $this->mysqlClient = $mysqlClient;

        $this->loadConnectionDefaults();
    }

    public function getDsn()
    {
        $server = $this->host;
        if ($this->port != '') {
            $server .= ':' . $this->port;
        }

        $auth = '';
        if ($this->user != '') {
            $auth = $this->user;
            if ($this->password != '') {
                $auth .= ':' . $this->password;
            }

            $auth .= '@';
        }

        return 'mysql://' . $auth . $server;
    }

    /**
     * @param string $sql
     * @throws MysqlError
     * @return \mysqli_result
     */
    public function query($sql)
    {
        $this->connect();
        $result = $this->connection->query($sql);
        if (!$result) {
            throw new MysqlError('DB error: ' . $this->connection->error, $this->connection->errno);
        }
        return $result;
    }

    /**
     * @param string $sql
     * @throws Error
     * @throws MysqlError
     */
    public function executeSqlScript($sql)
    {
        if ($this->charset) {
            $this->connect();
            $sql = "SET NAMES " . $this->connection->real_escape_string($this->charset) . ";\n\n{$sql}";
        }

        $tmp = tempnam(PATH_SEPARATOR, 'vmig_');
        if ($tmp === false) {
            throw new Error('Unable to create a temporary file');
        }

        file_put_contents($tmp, $sql);

        echo $sql;

        $cmd = escapeshellcmd($this->mysqlClient);
        $cmd .= $this->makeMysqlClientArgs();
        $cmd .= ' <' . escapeshellarg($tmp) . ' 2>&1';

        exec($cmd, $out, $err);

        unlink($tmp);

        if ($err) {
            throw new MysqlError(join("\n", $out));
        }
    }

    private function makeMysqlClientArgs()
    {
        $args = '';

        if ($this->host != '') {
            $args .= ' --host=' . escapeshellarg($this->host);
        }

        if ($this->port != '') {
            $args .= ' --port=' . escapeshellarg($this->port);
        }

        if ($this->user != '') {
            $args .= ' --user=' . escapeshellarg($this->user);
        }

        if ($this->password != '') {
            $args .= ' --password=' . escapeshellarg($this->password);
        }

        return $args;
    }

    public function escape($str)
    {
        $this->connect();
        return $this->connection->real_escape_string($str);
    }


    private function loadConnectionDefaults()
    {
        // no need to load if everything is specified
        if (empty($this->host) || empty($this->port) || empty($this->user) || empty($this->password)) {
            // loading defaults from the mysql client
            $line = exec(escapeshellcmd($this->mysqlClient) . ' --print-defaults', $ret, $code);
            // non-zero code = error
            if ($code) {
                return;
            }

            // simple and stupid
            if (preg_match_all('/--(\w+)=(\S+)/', $line, $mm, PREG_SET_ORDER)) {
                foreach ($mm as $row) {
                    $name = $row[1];
                    if (isset($this->$name) && $this->$name == '') {
                        $this->$name = $row[2];
                    }
                }
            }

            if (empty($this->host)) {
                $this->host = '127.0.0.1';
            }
        }
    }

    private function connect()
    {
        if (empty($this->connection)) {
            $this->connection = new \mysqli($this->host, $this->user, $this->password, '', intval($this->port));
            if (mysqli_connect_error()) {
                throw new Error('Connect Error ' . mysqli_connect_error());
            }

            $charset = $this->connection->real_escape_string($this->charset);
            $this->query("SET NAMES {$charset}");
        }
    }
}
