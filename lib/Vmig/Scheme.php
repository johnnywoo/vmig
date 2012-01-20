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
		foreach($lines as $line)
		{
			$matches = array();
			if(preg_match('@^CREATE TABLE `(.+)`@', $line, $matches))
			{
				$table_name = $matches[1];

				$scheme['tables'][$table_name] = array(
					'name'         => $table_name,
					'fields'       => array(),
					'keys'         => array(),
					'foreign_keys' => array(),
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
		}

		$this->_scheme_data = $scheme;
	}
}
