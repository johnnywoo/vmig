<?

class Vmig_SchemesDiff
{
	private $_diff_data;
	private $_db_name;


	public function __construct($scheme1, $scheme2, $db_name, $tables = array())
	{
		$this->_db_name = $db_name;
		$this->_create_diff($scheme1, $scheme2, $tables);
	}


	public function get_data()
	{
		return $this->_diff_data;
	}


	public function render_migration()
	{
		$migration = array(
			'add_triggers'      => array(),
			'drop_triggers'     => array(),
			'add_foreign_keys'  => array(),
			'drop_foreign_keys' => array(),
			'add_views'         => array(),
			'drop_views'        => array(),
			'alter_views'       => array(),
			'add_tables'        => array(),
			'drop_tables'       => array(),
			'alter_tables'      => array(),
		);

		foreach($this->_diff_data as $change_name => $changes)
		{
			foreach($changes as $db_action_name => $db_action)
			{
				switch($change_name)
				{
					case 'add_tables':
						$migration['add_tables'][] = $this->_m_create_table($db_action);
						break;
					case 'drop_tables':
						$migration['drop_tables'][] = $this->_m_drop_table($db_action);
						break;
					case 'alter_tables':
						$migration['alter_tables'][] = $this->_m_alter_table($db_action_name, $db_action);
						break;
					case 'add_keys':
						$migration['add_foreign_keys'][] = $this->_m_add_foreign_key($db_action['index'], $db_action['table_name']);
						break;
					case 'drop_keys':
						$migration['drop_foreign_keys'][] = $this->_m_drop_foreign_key($db_action['index'], $db_action['table_name']);
						break;
					case 'modify_keys':
						$migration['drop_foreign_keys'][] = $this->_m_drop_foreign_key($db_action['old'], $db_action['table_name']);
						$migration['add_foreign_keys'][]  = $this->_m_add_foreign_key($db_action['new'], $db_action['table_name']);
						break;
					case 'add_triggers':
						$migration['add_triggers'][] = $this->_m_add_trigger($db_action['trigger']);
						break;
					case 'drop_triggers':
						$migration['drop_triggers'][] = $this->_m_drop_trigger($db_action_name);
						break;
					case 'modify_triggers':
						$migration['drop_triggers'][] = $this->_m_drop_trigger($db_action_name);
						$migration['add_triggers'][]  = $this->_m_add_trigger($db_action['new']);
						break;
					case 'add_views':
						$migration['add_views'][] = $this->_m_add_view($db_action_name, $db_action);
						break;
					case 'alter_views':
						$migration['alter_views'][] = $this->_m_alter_view($db_action_name, $db_action);
						break;
					case 'drop_views':
						$migration['drop_views'][] = $this->_m_drop_view($db_action_name);
						break;
				}
			}
		}

		return $migration;
	}


	public function render_status_text($db_name)
	{
		$status = '';

		$diff_data = $this->_diff_data;
		foreach($diff_data as $change_name => $changes)
		{
			if($change_name == 'add_keys' || $change_name == 'drop_keys' || $change_name == 'modify_keys')
			{
				foreach($changes as $db_action_name => $db_action)
				{
					$diff_data['alter_tables'][$db_action['table_name']][$change_name.'_f'][$db_action_name] = $db_action;
				}
				unset($diff_data[$change_name]);
			}

			if($change_name == 'add_triggers' || $change_name == 'drop_triggers' || $change_name == 'modify_triggers')
			{
				foreach($changes as $db_action_name => $db_action)
				{
					$diff_data['alter_tables'][$db_action['table_name']][$change_name][$db_action_name] = $db_action;
				}
				unset($diff_data[$change_name]);
			}
		}


		foreach($diff_data as $change_name => $changes)
		{
			foreach($changes as $db_action_name => $db_action)
			{
				switch($change_name)
				{
					case 'add_tables':
					case 'add_views':
						$act = "%g+";
						break;
					case 'drop_tables':
					case 'drop_views':
						$act = "%r-";
						break;
					default:
						$act = "%y~";
				}

				$_action = '';
				if($change_name == 'add_views' || $change_name == 'drop_views' || $change_name == 'alter_views')
					$_action = '(view)';

				$status .= "\n {$act} {$db_name}.{$db_action_name} {$_action}%n\n";

				if($change_name == 'alter_tables')
					$status .= $this->_generate_status_text_for_alter_tables($db_action);
			}
		}

		return $status;
	}


	private function _generate_status_for_trigger($change_name, $db_action)
	{
		if($change_name != 'alter_triggers')
			return '(' . $this->_trigger_summary($db_action['trigger']) . ')';

		$new_summary = $this->_trigger_summary($db_action['new']);
		$old_summary = $this->_trigger_summary($db_action['old']);
		if($new_summary != $old_summary)
			return "({$new_summary}) -- was ({$old_summary})";

		return "({$new_summary})";
	}

	private function _trigger_summary($sql)
	{
		if(preg_match('/^ \s* CREATE \s+ TRIGGER \s+ \S+ \s+ (\S+) \s+ (\S+) \s+ ON/six', $sql, $m))
			return strtolower($m[1] . ' ' . $m[2]);
		return '???';
	}

	private function _generate_status_text_for_alter_tables($db_action)
	{
		$status = '';
		foreach($db_action as $table_change_name => $table_changes)
		{
			switch($table_change_name)
			{
				case 'add_field':
				case 'add_key':
				case 'add_keys_f':
				case 'add_triggers':
					$act = "%g+";
					break;
				case 'drop_field':
				case 'drop_key':
				case 'drop_keys_f':
				case 'drop_triggers':
					$act = "%r-";
					break;
				default:
					$act = "%y~";
			}
			foreach($table_changes as $action_name => $action)
			{
				$_action = null;

				if(is_string($action))
					$_action = $action;

				if($table_change_name == 'modify_field')
					$_action = $action['new'] . ' -- was ' . $action['old'];

				if($table_change_name == 'modify_key')
				{
					$action_name = 'KEY ' . $action_name;
					$_action = '(' . $action['new']['fields'] . ') -- was (' . $action['old']['fields'] . ')';
				}

				if($table_change_name == 'add_key' || $table_change_name == 'drop_key')
				{
					if($action_name == 'PRIMARY')
						$action_name .= ' KEY';
					else
						$action_name = 'KEY ' . $action_name;
					$_action = "({$action['fields']})";
				}

				if($table_change_name == 'add_keys_f' || $table_change_name == 'drop_keys_f')
					$_action = $action['index']['props'];

				if($table_change_name == 'modify_keys_f')
				{
					$action_name = 'KEY ' . $action_name;
					$_action = '(' . $action['new']['props'] . ') -- was (' . $action['old']['props'] . ')';
				}

				if(in_array($table_change_name, array('add_triggers', 'drop_triggers', 'modify_triggers')))
				{
					$action_name = 'TRIGGER ' . $action_name;
					$_action = $this->_generate_status_for_trigger($table_change_name, $action);
				}

				if(is_null($_action))
					throw new Vmig_Error('Unknown change type "'.$table_change_name.'"');

				$status .= $this->fix_carriage_returns("    {$act} {$action_name}%n {$_action}\n");
			}
		}

		return $status;
	}

	private function fix_carriage_returns($text)
	{
		return str_replace("\r", '%r\\r%n', $text);
	}


	/**
	 * @param Vmig_Scheme $scheme1
	 * @param Vmig_Scheme $scheme2
	 * @param array $tables
	 */
	private function _create_diff(Vmig_Scheme $scheme1, Vmig_Scheme $scheme2, $tables = array())
	{
		$scheme1_data = $scheme1->get_data();
		$scheme2_data = $scheme2->get_data();

		$this->_diff_data = array();

		$changes = array(
			'add_tables'      => array(),
			'alter_tables'    => array(),
			'drop_tables'     => array(),
			'add_views'       => array(),
			'drop_views'      => array(),
			'alter_views'     => array(),
			'drop_keys'       => array(),
			'add_keys'        => array(),
			'modify_keys'     => array(),
			'drop_triggers'   => array(),
			'add_triggers'    => array(),
			'modify_triggers' => array(),
		);

		foreach($scheme2_data['tables'] as $table_name => $table)
		{
            if(sizeof($tables) && !in_array($table_name, $tables))
                continue;

			if(!key_exists($table_name, $scheme1_data['tables']))
				$changes['drop_tables'][$table_name] = $table;

			foreach($table['foreign_keys'] as $index_name => $index)
			{
				if(!isset($scheme1_data['tables'][$table_name]['foreign_keys'][$index_name]))
				{
					$changes['drop_keys'][$index_name] = array(
						'table_name' => $table_name,
						'index'      => $index,
					);
				}
			}

			foreach($table['triggers'] as $trigger_name => $trigger_sql)
			{
				if(!isset($scheme1_data['tables'][$table_name]['triggers'][$trigger_name]))
				{
					$changes['drop_triggers'][$trigger_name] = array(
						'table_name' => $table_name,
						'trigger'    => $trigger_sql,
					);
				}
			}
		}

		foreach($scheme1_data['tables'] as $table_name => $table)
		{
            if(sizeof($tables) && !in_array($table_name, $tables))
                continue;

			if(!key_exists($table_name, $scheme2_data['tables']))
			{
				$changes['add_tables'][$table_name] = $table;
			}
			else if($table !== $scheme2_data['tables'][$table_name])
			{
				$table_changes = $this->_diff_tables($table, $scheme2_data['tables'][$table_name]);
				if($table_changes)
					$changes['alter_tables'][$table_name] = $table_changes;
			}

			foreach($table['foreign_keys'] as $index_name => $index)
			{
				$fks =& $scheme2_data['tables'][$table_name]['foreign_keys']; // ref to avoid notices
				if(!isset($fks[$index_name]))
				{
					$changes['add_keys'][$index_name] = array(
						'table_name' => $table_name,
						'index'      => $index,
					);
				}
				else if($index != $fks[$index_name])
				{
					$changes['modify_keys'][$index_name] = array(
						'table_name' => $table_name,
						'old'        => $fks[$index_name],
						'new'        => $index,
					);
				}
			}

			foreach($table['triggers'] as $trigger_name => $trigger_sql)
			{
				$triggers =& $scheme2_data['tables'][$table_name]['triggers']; // ref to avoid notices
				if(!isset($triggers[$trigger_name]))
				{
					$changes['add_triggers'][$trigger_name] = array(
						'table_name' => $table_name,
						'trigger'    => $trigger_sql,
					);
				}
				else if($trigger_sql != $triggers[$trigger_name])
				{
					$changes['modify_triggers'][$trigger_name] = array(
						'table_name' => $table_name,
						'old'        => $triggers[$trigger_name],
						'new'        => $trigger_sql,
					);
				}
			}
		}

		foreach($scheme2_data['views'] as $view_name => $view)
		{
			if(!key_exists($view_name, $scheme1_data['views']))
				$changes['drop_views'][$view_name] = $view;
		}

		foreach($scheme1_data['views'] as $view_name => $view)
		{
			if(!key_exists($view_name, $scheme2_data['views']))
			{
				$changes['add_views'][$view_name] = $view;
			}
			else if($view != $scheme2_data['views'][$view_name])
			{
				$changes['alter_views'][$view_name] = array(
					'old' => $scheme2_data['views'][$view_name],
					'new' => $view,
				);
			}
		}

		$this->_diff_data = $changes;
	}


	private function _diff_tables($table1, $table2)
	{
		$changes = array(
			'add_field'    => array(),
			'drop_field'   => array(),
			'modify_field' => array(),
			'add_key'      => array(),
			'drop_key'     => array(),
			'modify_key'   => array(),
			'props'        => array(),
		);
		$empty_changes = $changes;

		foreach($table2['fields'] as $name => $field_props)
		{
			if(!key_exists($name, $table1['fields']))
				$changes['drop_field'][$name] = $field_props;
		}

		$prev_field = null;
		foreach($table1['fields'] as $name => $field_props)
		{
			$add_props = ' FIRST';
			if($prev_field)
				$add_props = " AFTER `{$prev_field}`";

			if(!key_exists($name, $table2['fields']))
			{
				$changes['add_field'][$name] = $field_props . $add_props;
			}
			else if(strcasecmp($field_props, $table2['fields'][$name]) != 0)
			{
				$changes['modify_field'][$name] = array(
					'old' => $table2['fields'][$name],
					'new' => $field_props,
				);
			}
			$prev_field = $name;
		}

		foreach($table2['keys'] as $index_name => $index)
		{
			if(!key_exists($index_name, $table1['keys']))
				$changes['drop_key'][$index_name] = $index;
		}

		foreach($table1['keys'] as $index_name => $index)
		{
			if(!key_exists($index_name, $table2['keys']))
			{
				$changes['add_key'][$index_name] = $index;
			}
			else if($index != $table2['keys'][$index_name])
			{
				$changes['modify_key'][$index_name] = array(
					'old' => $table2['keys'][$index_name],
					'new' => $index,
				);
			}
		}

		if($table1['props'] != $table2['props'])
			$changes['props'] = array(
				'old' => $table2['props'],
				'new' => $table1['props'],
			);

		$changes = $this->_check_fields_positions($table1, $table2, $changes);

		if($changes === $empty_changes)
			$changes = false;
		return $changes;
	}


	private function _check_fields_positions($table1, $table2, $changes)
	{
		$pos = 1;
		$table1_pos = array();
		foreach($table1['fields'] as $field_name => $field_props)
		{
			$table1_pos[$field_name] = $pos;
			$pos++;
		}

		$table2_pos = array();
		$table2_with_pos = array();
		$table1_prev_fields = array();
		$prev_field = null;
		foreach($table2['fields'] as $field_name => $field_props)
		{
			$table1_prev_fields[$field_name] = $prev_field;
			$prev_field = $field_name;

			if(!key_exists($field_name, $table1_pos))
				continue;

			$table2_with_pos[$table1_pos[$field_name]] = array(
				'field_name'  => $field_name,
				'field_props' => $field_props,
			);

			$table2_pos[] = $table1_pos[$field_name];
		}

		$sequence = $this->_find_longest_sequence($table2_pos);
		$sequence[99999] = 99999;

		foreach($table2_with_pos as $pos => $field)
		{
			if(in_array($pos, $sequence))
				continue;

			$prev_pos = -1;
			foreach($sequence as $s_pos)
			{
				if($pos < $s_pos)
				{
					$field_name = $table2_with_pos[$pos]['field_name'];

					$add_props = ' FIRST';
					if($prev_pos != -1)
						$add_props = " AFTER `{$table2_with_pos[$prev_pos]['field_name']}`";

					$old_props = ' FIRST';
					if(key_exists($field_name, $table1_prev_fields) && $table1_prev_fields[$field_name] != null)
					{
						$old_props = " AFTER `{$table1_prev_fields[$field_name]}`";
					}

					$changes['modify_field'][$field_name] = array(
						'old' => "{$table1['fields'][$field_name]}{$old_props}",
						'new' => "{$table2['fields'][$field_name]}{$add_props}",
					);
					$sequence[] = $pos;
					sort($sequence);
					break;
				}
				$prev_pos = $s_pos;
			}
		}

		return $changes;
	}


	private function _find_longest_sequence($arr)
	{
		$all_sequences = array();

		foreach($arr as $key => $value)
		{
			$count = 0;
			$count2 = 0;
			for($i = 0; $i < count($arr); $i++)
			{
				if($arr[$i] < $value && $i < $key)
					$count++;
				if($arr[$i] > $value && $i > $key)
					$count2++;
			}
			if($count2 > $count)
				$count = $count2;

			foreach($all_sequences as &$sequence)
			{
				if($value > $sequence[count($sequence)-1])
				{
					$sequence[] = $value;
					$count--;
				}

				if($count <= 0)
					break;
			}

			while($count > 0)
			{
				$all_sequences[] = array($value);
				$count--;
			}
		}

		$max_sequence = array();
		$max_length = 0;
		foreach($all_sequences as $sequence)
		{
			$curr_sequence = count($sequence);
			if($curr_sequence > $max_length)
			{
				$max_length = $curr_sequence;
				$max_sequence = $sequence;
			}
		}

		if(empty($max_sequence))
			$max_sequence = array($arr[0]);

		return $max_sequence;
	}


	private function _m_create_table($table)
	{
		$table_name = $table['name'];
		$lines = array();

		foreach($table['fields'] as $field_name => $field_props)
		{
			$lines[] = "  {$field_name} {$field_props}";
		}

		foreach($table['keys'] as $index_name => $index)
		{
			if($index_name == 'PRIMARY')
				$lines[] = "  PRIMARY KEY ({$index['fields']})";
			else if(!empty($index['unique']))
				$lines[] = "  UNIQUE KEY `{$index_name}` ({$index['fields']})";
			else
				$lines[] = "  KEY `{$index_name}` ({$index['fields']})";
		}

		$migration = "CREATE TABLE `{$this->_db_name}`.`{$table_name}` (\n";
		$migration .= join(",\n", $lines) . "\n";
		$migration .= ") {$table['props']};\n\n";

		return $migration;
	}


	private function _m_drop_table($table)
	{
		$table_name = $table['name'];
		$migration = "DROP TABLE `{$this->_db_name}`.`{$table_name}`;\n\n";
		return $migration;
	}


	private function _m_alter_table($table_name, $table_changes)
	{
		$migration = array();

		foreach($table_changes as $change_name => $changes)
		{
			if(!is_array($changes))
				continue;
			elseif($change_name == 'props')
			{
				/*
				 * change 'props' applies to table, not to field. So it looks like:
				 * 	array(
				 * 		'old'=>'...',
				 * 		'new'=>'...'
				 * 	),
				 *
				 * rather than field change:
				 * 	array(
				 * 		'field_name' => array(
				 * 			'old'=>'...',
				 * 			'new'=>'...'
				 * 		)
				 * 	)
				 */
				if(array_key_exists('new', $changes)) // 'props' can be array(array 'old', array 'new'), or an empty array.
					$migration[] = $changes['new'];
				continue;
			}

			foreach($changes as $field_name => $field_props)
			{
				switch($change_name)
				{
					case 'add_field':
						$migration[] = "ADD COLUMN `{$field_name}` {$field_props}";
						break;
					case 'drop_field':
						$migration[] = "DROP COLUMN `{$field_name}`";
						break;
					case 'modify_field':
						$migration[] = "MODIFY `{$field_name}` {$field_props['new']}";
						break;
					case 'add_key':
						$migration[] = $this->_m_add_key($field_props, $table_name);
						break;
					case 'drop_key':
						$migration[] = $this->_m_drop_key($field_props, $table_name);
						break;
					case 'modify_key':
						$migration[] = $this->_m_drop_key($field_props['old'], $table_name);
						$migration[] = $this->_m_add_key($field_props['new'], $table_name);
						break;
				}
			}
		}

		if($migration)
			return "ALTER TABLE `{$this->_db_name}`.`{$table_name}`\n  " . implode(",\n  ", $migration) . ";\n\n";

		return false;
	}


	private function _m_drop_key($index, $table_name)
	{
		if($index['name'] == 'PRIMARY')
			$migration = "DROP PRIMARY KEY";
		else
			$migration = "DROP INDEX `{$index['name']}`";

		return $migration;
	}


	private function _m_add_key($index, $table_name)
	{
		if($index['name'] == 'PRIMARY')
			$migration = "ADD PRIMARY KEY ({$index['fields']})";
		else if($index['unique'])
			$migration = "ADD UNIQUE `{$index['name']}` ({$index['fields']})";
		else
			$migration = "ADD INDEX `{$index['name']}` ({$index['fields']})";

		return $migration;
	}


	private function _m_add_foreign_key($index, $table_name)
	{
		return "ALTER TABLE `{$this->_db_name}`.`{$table_name}` ADD CONSTRAINT `{$index['name']}` {$index['props']};\n";
	}


	private function _m_drop_foreign_key($index, $table_name)
	{
		return "ALTER TABLE `{$this->_db_name}`.`{$table_name}` DROP FOREIGN KEY `{$index['name']}`;\n";
	}


	private function _m_add_trigger($trigger_sql)
	{
		$delimiter = ';;';
		while(strpos($trigger_sql, $delimiter) !== false)
		{
			$delimiter .= ';';
		}

		return "USE `{$this->_db_name}`;\n".
			"DELIMITER {$delimiter}\n".
			"{$trigger_sql}{$delimiter}\n".
			"DELIMITER ;\n\n";
	}


	private function _m_drop_trigger($trigger_name)
	{
		return "DROP TRIGGER `{$this->_db_name}`.`{$trigger_name}`;\n\n";
	}


	private function _m_drop_view($view_name)
	{
		return "DROP VIEW `{$this->_db_name}`.`{$view_name}`;\n";
	}


	private function _m_add_view($view_name, $view)
	{
		// View declaration may contain table references inside (view x as select * from table).
		// Those references may omit database name, so we need to set default DB here.
		return "USE `{$this->_db_name}`;\n".
			"CREATE VIEW `{$this->_db_name}`.`{$view_name}` {$view};\n";
	}


	private function _m_alter_view($view_name, $view)
	{
		return "USE `{$this->_db_name}`;\n".
			"ALTER VIEW `{$this->_db_name}`.`{$view_name}` {$view['new']};\n";
	}
}