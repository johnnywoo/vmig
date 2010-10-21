<?

class Vmig_Dump
{
	public static function create(Vmig_MysqlConnection $db, $dbname)
	{
		// loadload
		$dump = '';

		$db->query('USE '.$dbname);

		// loading tables list
		$tables = array();
		$r = $db->query('SHOW TABLES');
		while($row = $r->fetch_array())
		{
			$tables[] = reset($row);
		}
		sort($tables);

		foreach($tables as $table)
		{
			$dump .= "\n-- $table\n";

			$rr = $db->query('SHOW CREATE TABLE `'.$table.'`');
			$row = $rr->fetch_row();
			$sql = $row[1];

			// removing a_i part
			$sql = preg_replace("/\s+AUTO_INCREMENT=\S*([^\r\n].*?)$/is", '$1', $sql);

			// removing view params
			$sql = preg_replace('/^(CREATE )[^\n]*? (VIEW)/', '$1$2', $sql);

			//let's sort the CONSTRAINTs, if any.
			$sql_array = explode("\n", $sql);
			$constraints = array();
			$constraints_pos = 0; // position of the last CONSTRAINT. After ksort, all CONSTRAINTs will be put there
			foreach($sql_array as $string_num=>$sql_string)
			{
				if(preg_match("/\s+CONSTRAINT `([^`]*)`[^,]*(,?)$/i", $sql_string, $res))
				{
					$constraints[$res[1]] = (!empty($res[2]) ? substr($sql_string, 0, -1) : $sql_string);
					$constraints_pos = $string_num;
					unset($sql_array[$string_num]);
				}
			}

			if($constraints_pos > 0)
			{
				ksort($constraints); // by symbol_name
				$sql_array[$constraints_pos] = implode(",\n", $constraints);
				ksort($sql_array); // by string_num
			}

			$dump .=  implode("\n", $sql_array).";\n";
		}

		return new self($dump);
	}

	public static function load_from_file($file)
	{
		if(!is_readable($file))
			throw new Vmig_Error('Unable to read dump from "'.$file.'"');

		return new self(file_get_contents($file));
	}

	public $sql = '';

	public function __construct($sql)
	{
		$this->sql = $sql;
	}
}