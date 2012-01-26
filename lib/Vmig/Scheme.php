<?

class Vmig_Scheme
{
	private $_scheme_data = array(
		'tables' => array(),
		'views'  => array(),
	);


	public function __construct($dump)
	{
		$this->_load_from_dump($dump);
	}


	public function get_data()
	{
		return $this->_scheme_data;
	}


	private function _load_from_dump($dump)
	{
		$lines = explode("\n", $dump);

		$scheme = array(
			'tables' => array(),
			'views'  => array(),
		);
		$table_name = '';
		for($i = 0; $i < count($lines); $i++)
		{
			$line = $lines[$i];

			$matches = array();
			if(preg_match('@^CREATE TABLE `(.+)`@', $line, $matches))
			{
				$table_name = $matches[1];

				$scheme['tables'][$table_name] = array(
					'name'         => $table_name,
					'fields'       => array(),
					'keys'         => array(),
					'foreign_keys' => array(),
					'triggers'     => array(),
					'props'        => '',
				);
			}

			if(preg_match('@CREATE VIEW `(.+?)` (AS .*);@', $line, $matches))
			{
				$view_name = $matches[1];
				$view      = $matches[2];

				$view_name = preg_replace('@.+?`\.`(.+)@', '$1', $view_name);

				$scheme['views'][$view_name] = $view;
			}

			if(preg_match('@^\s+`(.+)` (.+)@', $line, $matches))
			{
				$field_name  = $matches[1];
				$field_props = $matches[2];

				if($field_props[strlen($field_props) - 1] == ',')
					$field_props = substr($field_props, 0, strlen($field_props) - 1);

				$scheme['tables'][$table_name]['fields'][$field_name] = $field_props;
			}

			if(preg_match('@\s+(PRIMARY|UNIQUE)? KEY\s?(?:`(.*?)`)?\s?\((.*)\)@', $line, $matches))
			{
				$index_name = 'PRIMARY';
				if(!empty($matches[2]))
					$index_name = $matches[2];

				$unique = false;
				if($index_name == 'PRIMARY' || $matches[1] == 'UNIQUE')
					$unique = true;

				$fields = $matches[3];

				$scheme['tables'][$table_name]['keys'][$index_name] = array(
					'name'   => $index_name,
					'unique' => $unique,
					'fields' => $fields,
				);
			}

			if(preg_match('@CONSTRAINT `(.+)` (FOREIGN KEY .+)@', $line, $matches))
			{
				$index_name  = $matches[1];
				$index_props = $matches[2];

				if($index_props[strlen($index_props) - 1] == ',')
					$index_props = substr($index_props, 0, strlen($index_props) - 1);

				$scheme['tables'][$table_name]['foreign_keys'][$index_name] = array(
					'name'  => $index_name,
					'props' => $index_props,
				);
			}

			if(preg_match('@(ENGINE=.+);@', $line, $matches))
				$scheme['tables'][$table_name]['props'] = $matches[1];

			if(preg_match('@CREATE TRIGGER `(.+?)` [^`]+ ON `(.+?)`@', $line, $matches))
			{
				$trigger_name = $matches[1];
				$table_name   = $matches[2];

				$trigger_sql = '';
				// load all lines until trigger ends
				while(!preg_match('{^-- trigger end: '.preg_quote($trigger_name).'$}', $lines[$i]))
				{
					if(!isset($lines[$i]))
						throw new Vmig_Error("Cannot find end of trigger {$trigger_name}");

					$trigger_sql .= $lines[$i]."\n";

					$i++;
				}
				$trigger_sql = substr($trigger_sql, 0, -1); // removing \n

				$scheme['tables'][$table_name]['triggers'][$trigger_name] = $trigger_sql;
			}
		}

		$this->_scheme_data = $scheme;
	}
}
