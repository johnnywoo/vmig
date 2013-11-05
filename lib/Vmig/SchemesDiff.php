<?php

namespace Vmig;

class SchemesDiff
{
    private $diffData;
    private $dbName;


    public function __construct($scheme1, $scheme2, $dbName, $tables = array())
    {
        $this->dbName = $dbName;
        $this->createDiff($scheme1, $scheme2, $tables);
    }


    public function getData()
    {
        return $this->diffData;
    }


    public function renderMigration()
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

        foreach ($this->diffData as $changeName => $changes) {
            foreach ($changes as $dbActionName => $dbAction) {
                switch ($changeName) {
                    case 'add_tables':
                        $migration['add_tables'][] = $this->makeCreateTable($dbAction);
                        break;
                    case 'drop_tables':
                        $migration['drop_tables'][] = $this->makeDropTable($dbAction);
                        break;
                    case 'alter_tables':
                        $migration['alter_tables'][] = $this->makeAlterTable($dbActionName, $dbAction);
                        break;
                    case 'add_keys':
                        $migration['add_foreign_keys'][] = $this->makeAddForeignKey($dbAction['index'], $dbAction['table_name']);
                        break;
                    case 'drop_keys':
                        $migration['drop_foreign_keys'][] = $this->makeDropForeignKey($dbAction['index'], $dbAction['table_name']);
                        break;
                    case 'modify_keys':
                        $migration['drop_foreign_keys'][] = $this->makeDropForeignKey($dbAction['old'], $dbAction['table_name']);
                        $migration['add_foreign_keys'][]  = $this->makeAddForeignKey($dbAction['new'], $dbAction['table_name']);
                        break;
                    case 'add_triggers':
                        $migration['add_triggers'][] = $this->makeAddTrigger($dbAction['trigger']);
                        break;
                    case 'drop_triggers':
                        $migration['drop_triggers'][] = $this->makeDropTrigger($dbActionName);
                        break;
                    case 'modify_triggers':
                        $migration['drop_triggers'][] = $this->makeDropTrigger($dbActionName);
                        $migration['add_triggers'][]  = $this->makeAddTrigger($dbAction['new']);
                        break;
                    case 'add_views':
                        $migration['add_views'][] = $this->makeAddView($dbActionName, $dbAction);
                        break;
                    case 'alter_views':
                        $migration['alter_views'][] = $this->makeAlterView($dbActionName, $dbAction);
                        break;
                    case 'drop_views':
                        $migration['drop_views'][] = $this->makeDropView($dbActionName);
                        break;
                }
            }
        }

        return $migration;
    }


    public function renderStatusText($dbName)
    {
        $status = '';

        $diffData = $this->diffData;
        foreach ($diffData as $changeName => $changes) {
            if ($changeName == 'add_keys' || $changeName == 'drop_keys' || $changeName == 'modify_keys') {
                foreach ($changes as $dbActionName => $dbAction) {
                    $diffData['alter_tables'][$dbAction['table_name']][$changeName . '_f'][$dbActionName] = $dbAction;
                }
                unset($diffData[$changeName]);
            }

            if ($changeName == 'add_triggers' || $changeName == 'drop_triggers' || $changeName == 'modify_triggers') {
                foreach ($changes as $dbActionName => $dbAction) {
                    $diffData['alter_tables'][$dbAction['table_name']][$changeName][$dbActionName] = $dbAction;
                }
                unset($diffData[$changeName]);
            }
        }

        foreach ($diffData as $changeName => $changes) {
            foreach ($changes as $dbActionName => $dbAction) {
                switch ($changeName) {
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

                $action = '';
                if ($changeName == 'add_views' || $changeName == 'drop_views' || $changeName == 'alter_views') {
                    $action = '(view)';
                }

                $status .= "\n {$act} {$dbName}.{$dbActionName} {$action}%n\n";

                if ($changeName == 'alter_tables') {
                    $status .= $this->generateStatusTextForAlterTables($dbAction);
                }
            }
        }

        return $status;
    }


    private function generateStatusForTrigger($changeName, $dbAction)
    {
        if ($changeName != 'modify_triggers') {
            return '(' . $this->triggerSummary($dbAction['trigger']) . ')';
        }

        $newSummary = $this->triggerSummary($dbAction['new']);
        $oldSummary = $this->triggerSummary($dbAction['old']);
        if ($newSummary != $oldSummary) {
            return "({$newSummary}) -- was ({$oldSummary})";
        }

        return "({$newSummary})";
    }

    private function triggerSummary($sql)
    {
        if (preg_match('/^ \s* CREATE \s+ TRIGGER \s+ \S+ \s+ (\S+) \s+ (\S+) \s+ ON/six', $sql, $m)) {
            return strtolower($m[1] . ' ' . $m[2]);
        }
        return '???';
    }

    private function generateStatusTextForAlterTables($dbAction)
    {
        $status = '';
        foreach ($dbAction as $tableChangeName => $tableChanges) {
            switch ($tableChangeName) {
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
            foreach ($tableChanges as $actionName => $action) {
                $actionDesc = null;

                if (is_string($action)) {
                    $actionDesc = $action;
                }

                if ($tableChangeName == 'modify_field') {
                    $actionDesc = $action['new'] . ' -- was ' . $action['old'];
                }

                if ($tableChangeName == 'modify_key') {
                    $actionName = 'KEY ' . $actionName;
                    $actionDesc = '(' . $action['new']['fields'] . ') -- was (' . $action['old']['fields'] . ')';
                }

                if ($tableChangeName == 'add_key' || $tableChangeName == 'drop_key') {
                    if ($actionName == 'PRIMARY') {
                        $actionName .= ' KEY';
                    } else {
                        $actionName = 'KEY ' . $actionName;
                    }
                    $actionDesc = "({$action['fields']})";
                }

                if ($tableChangeName == 'add_keys_f' || $tableChangeName == 'drop_keys_f') {
                    $actionDesc = $action['index']['props'];
                }

                if ($tableChangeName == 'modify_keys_f') {
                    $actionName = 'KEY ' . $actionName;
                    $actionDesc = '(' . $action['new']['props'] . ') -- was (' . $action['old']['props'] . ')';
                }

                if (in_array($tableChangeName, array('add_triggers', 'drop_triggers', 'modify_triggers'))) {
                    $actionName = 'TRIGGER ' . $actionName;
                    $actionDesc = $this->generateStatusForTrigger($tableChangeName, $action);
                }

                if ($actionDesc === null) {
                    throw new Error('Unknown change type "' . $tableChangeName . '"');
                }

                $status .= $this->fixCarriageReturns("    {$act} {$actionName}%n {$actionDesc}\n");
            }
        }

        return $status;
    }

    private function fixCarriageReturns($text)
    {
        return str_replace("\r", '%r\\r%n', $text);
    }


    /**
     * @param Scheme $scheme1
     * @param Scheme $scheme2
     * @param array $tables
     */
    private function createDiff(Scheme $scheme1, Scheme $scheme2, $tables = array())
    {
        $scheme1Data = $scheme1->getData();
        $scheme2Data = $scheme2->getData();

        $this->diffData = array();

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

        foreach ($scheme2Data['tables'] as $tableName => $table) {
            if (sizeof($tables) && !in_array($tableName, $tables)) {
                continue;
            }

            if (!array_key_exists($tableName, $scheme1Data['tables'])) {
                $changes['drop_tables'][$tableName] = $table;
            }

            foreach ($table['foreign_keys'] as $indexName => $index) {
                if (!isset($scheme1Data['tables'][$tableName]['foreign_keys'][$indexName])) {
                    $changes['drop_keys'][$indexName] = array(
                        'table_name' => $tableName,
                        'index'      => $index,
                    );
                }
            }

            foreach ($table['triggers'] as $triggerName => $triggerSql) {
                if (!isset($scheme1Data['tables'][$tableName]['triggers'][$triggerName])) {
                    $changes['drop_triggers'][$triggerName] = array(
                        'table_name' => $tableName,
                        'trigger'    => $triggerSql,
                    );
                }
            }
        }

        foreach ($scheme1Data['tables'] as $tableName => $table) {
            if (sizeof($tables) && !in_array($tableName, $tables)) {
                continue;
            }

            if (!array_key_exists($tableName, $scheme2Data['tables'])) {
                $changes['add_tables'][$tableName] = $table;
            } else if ($table !== $scheme2Data['tables'][$tableName]) {
                $tableChanges = $this->diffTables($table, $scheme2Data['tables'][$tableName]);
                if ($tableChanges) {
                    $changes['alter_tables'][$tableName] = $tableChanges;
                }
            }

            foreach ($table['foreign_keys'] as $indexName => $index) {
                $fks =& $scheme2Data['tables'][$tableName]['foreign_keys']; // ref to avoid notices
                if (!isset($fks[$indexName])) {
                    $changes['add_keys'][$indexName] = array(
                        'table_name' => $tableName,
                        'index'      => $index,
                    );
                } else if ($index != $fks[$indexName]) {
                    $changes['modify_keys'][$indexName] = array(
                        'table_name' => $tableName,
                        'old'        => $fks[$indexName],
                        'new'        => $index,
                    );
                }
            }

            foreach ($table['triggers'] as $triggerName => $triggerSql) {
                $triggers =& $scheme2Data['tables'][$tableName]['triggers']; // ref to avoid notices
                if (!isset($triggers[$triggerName])) {
                    $changes['add_triggers'][$triggerName] = array(
                        'table_name' => $tableName,
                        'trigger'    => $triggerSql,
                    );
                } else if ($triggerSql != $triggers[$triggerName]) {
                    $changes['modify_triggers'][$triggerName] = array(
                        'table_name' => $tableName,
                        'old'        => $triggers[$triggerName],
                        'new'        => $triggerSql,
                    );
                }
            }
        }

        foreach ($scheme2Data['views'] as $viewName => $view) {
            if (!array_key_exists($viewName, $scheme1Data['views'])) {
                $changes['drop_views'][$viewName] = $view;
            }
        }

        foreach ($scheme1Data['views'] as $viewName => $view) {
            if (!array_key_exists($viewName, $scheme2Data['views'])) {
                $changes['add_views'][$viewName] = $view;
            } else if ($view != $scheme2Data['views'][$viewName]) {
                $changes['alter_views'][$viewName] = array(
                    'old' => $scheme2Data['views'][$viewName],
                    'new' => $view,
                );
            }
        }

        $this->diffData = $changes;
    }


    private function diffTables($table1, $table2)
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

        $emptyChanges = $changes;

        foreach ($table2['fields'] as $name => $fieldProps) {
            if (!array_key_exists($name, $table1['fields'])) {
                $changes['drop_field'][$name] = $fieldProps;
            }
        }

        $prevField = null;
        foreach ($table1['fields'] as $name => $fieldProps) {
            $addProps = ' FIRST';
            if ($prevField) {
                $addProps = " AFTER `{$prevField}`";
            }

            if (!array_key_exists($name, $table2['fields'])) {
                $changes['add_field'][$name] = $fieldProps . $addProps;
            } else if (strcasecmp($fieldProps, $table2['fields'][$name]) != 0) {
                $changes['modify_field'][$name] = array(
                    'old' => $table2['fields'][$name],
                    'new' => $fieldProps,
                );
            }
            $prevField = $name;
        }

        foreach ($table2['keys'] as $indexName => $index) {
            if (!array_key_exists($indexName, $table1['keys'])) {
                $changes['drop_key'][$indexName] = $index;
            }
        }

        foreach ($table1['keys'] as $indexName => $index) {
            if (!array_key_exists($indexName, $table2['keys'])) {
                $changes['add_key'][$indexName] = $index;
            } else if ($index != $table2['keys'][$indexName]) {
                $changes['modify_key'][$indexName] = array(
                    'old' => $table2['keys'][$indexName],
                    'new' => $index,
                );
            }
        }

        if ($table1['props'] != $table2['props']) {
            $changes['props'] = array(
                'old' => $table2['props'],
                'new' => $table1['props'],
            );
        }

        $changes = $this->checkFieldsPositions($table1, $table2, $changes);

        if ($changes === $emptyChanges) {
            $changes = false;
        }
        return $changes;
    }


    private function checkFieldsPositions($table1, $table2, $changes)
    {
        $pos       = 1;
        $table1Pos = array();
        foreach ($table1['fields'] as $fieldName => $fieldProps) {
            $table1Pos[$fieldName] = $pos;
            $pos++;
        }

        $table2Pos        = array();
        $table2WithPos    = array();
        $table1PrevFields = array();
        $prevField        = null;
        foreach ($table2['fields'] as $fieldName => $fieldProps) {
            $table1PrevFields[$fieldName] = $prevField;

            $prevField = $fieldName;

            if (!array_key_exists($fieldName, $table1Pos)) {
                continue;
            }

            $table2WithPos[$table1Pos[$fieldName]] = array(
                'field_name'  => $fieldName,
                'field_props' => $fieldProps,
            );

            $table2Pos[] = $table1Pos[$fieldName];
        }

        $sequence = $this->findLongestSequence($table2Pos);
        $sequence[99999] = 99999;

        foreach ($table2WithPos as $pos => $field) {
            if (in_array($pos, $sequence)) {
                continue;
            }

            $prevPos = -1;
            foreach ($sequence as $sPos) {
                if ($pos < $sPos) {
                    $fieldName = $table2WithPos[$pos]['field_name'];

                    $addProps = ' FIRST';
                    if ($prevPos != -1) {
                        $addProps = " AFTER `{$table2WithPos[$prevPos]['field_name']}`";
                    }

                    $oldProps = ' FIRST';
                    if (array_key_exists($fieldName, $table1PrevFields) && $table1PrevFields[$fieldName] != null) {
                        $oldProps = " AFTER `{$table1PrevFields[$fieldName]}`";
                    }

                    $changes['modify_field'][$fieldName] = array(
                        'old' => "{$table1['fields'][$fieldName]}{$oldProps}",
                        'new' => "{$table2['fields'][$fieldName]}{$addProps}",
                    );

                    $sequence[] = $pos;
                    sort($sequence);
                    break;
                }
                $prevPos = $sPos;
            }
        }

        return $changes;
    }


    private function findLongestSequence($arr)
    {
        $allSequences = array();

        foreach ($arr as $key => $value) {
            $count  = 0;
            $count2 = 0;
            for ($i = 0; $i < count($arr); $i++) {
                if ($arr[$i] < $value && $i < $key) {
                    $count++;
                }
                if ($arr[$i] > $value && $i > $key) {
                    $count2++;
                }
            }
            if ($count2 > $count) {
                $count = $count2;
            }

            foreach ($allSequences as &$sequence) {
                if ($value > $sequence[count($sequence) - 1]) {
                    $sequence[] = $value;
                    $count--;
                }

                if ($count <= 0) {
                    break;
                }
            }

            while ($count > 0) {
                $allSequences[] = array($value);
                $count--;
            }
        }

        $maxSequence = array();
        $maxLength   = 0;
        foreach ($allSequences as $sequence) {
            $currSequence = count($sequence);
            if ($currSequence > $maxLength) {
                $maxLength   = $currSequence;
                $maxSequence = $sequence;
            }
        }

        if (empty($maxSequence)) {
            $maxSequence = array($arr[0]);
        }

        return $maxSequence;
    }


    private function makeCreateTable($table)
    {
        $tableName = $table['name'];
        $lines     = array();

        foreach ($table['fields'] as $fieldName => $fieldProps) {
            $lines[] = "  {$fieldName} {$fieldProps}";
        }

        foreach ($table['keys'] as $indexName => $index) {
            if ($indexName == 'PRIMARY') {
                $lines[] = "  PRIMARY KEY ({$index['fields']})";
            } else if (!empty($index['unique'])) {
                $lines[] = "  UNIQUE KEY `{$indexName}` ({$index['fields']})";
            } else {
                $lines[] = "  KEY `{$indexName}` ({$index['fields']})";
            }
        }

        $migration = "CREATE TABLE `{$this->dbName}`.`{$tableName}` (\n";
        $migration .= join(",\n", $lines) . "\n";
        $migration .= ") {$table['props']};\n\n";

        return $migration;
    }


    private function makeDropTable($table)
    {
        $tableName = $table['name'];
        $migration = "DROP TABLE `{$this->dbName}`.`{$tableName}`;\n\n";
        return $migration;
    }


    private function makeAlterTable($tableName, $tableChanges)
    {
        $migration = array();

        foreach ($tableChanges as $changeName => $changes) {
            if (!is_array($changes)) {
                continue;
            }

            if ($changeName == 'props') {
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
                if (array_key_exists('new', $changes)) {
                    // 'props' can be array(array 'old', array 'new'), or an empty array.
                    $migration[] = $changes['new'];
                }
                continue;
            }

            foreach ($changes as $fieldName => $fieldProps) {
                switch ($changeName) {
                    case 'add_field':
                        $migration[] = "ADD COLUMN `{$fieldName}` {$fieldProps}";
                        break;
                    case 'drop_field':
                        $migration[] = "DROP COLUMN `{$fieldName}`";
                        break;
                    case 'modify_field':
                        $migration[] = "MODIFY `{$fieldName}` {$fieldProps['new']}";
                        break;
                    case 'add_key':
                        $migration[] = $this->makeAddKey($fieldProps, $tableName);
                        break;
                    case 'drop_key':
                        $migration[] = $this->makeDropKey($fieldProps, $tableName);
                        break;
                    case 'modify_key':
                        $migration[] = $this->makeDropKey($fieldProps['old'], $tableName);
                        $migration[] = $this->makeAddKey($fieldProps['new'], $tableName);
                        break;
                }
            }
        }

        if ($migration) {
            return "ALTER TABLE `{$this->dbName}`.`{$tableName}`\n  " . implode(",\n  ", $migration) . ";\n\n";
        }

        return false;
    }


    private function makeDropKey($index, $tableName)
    {
        if ($index['name'] == 'PRIMARY') {
            $migration = "DROP PRIMARY KEY";
        } else {
            $migration = "DROP INDEX `{$index['name']}`";
        }

        return $migration;
    }


    private function makeAddKey($index, $tableName)
    {
        if ($index['name'] == 'PRIMARY') {
            $migration = "ADD PRIMARY KEY ({$index['fields']})";
        } else if ($index['unique']) {
            $migration = "ADD UNIQUE `{$index['name']}` ({$index['fields']})";
        } else {
            $migration = "ADD INDEX `{$index['name']}` ({$index['fields']})";
        }

        return $migration;
    }


    private function makeAddForeignKey($index, $tableName)
    {
        return "ALTER TABLE `{$this->dbName}`.`{$tableName}` ADD CONSTRAINT `{$index['name']}` {$index['props']};\n";
    }


    private function makeDropForeignKey($index, $tableName)
    {
        return "ALTER TABLE `{$this->dbName}`.`{$tableName}` DROP FOREIGN KEY `{$index['name']}`;\n";
    }


    const SQL_DELIMITER = ';';

    private function makeAddTrigger($triggerSql)
    {
        $delimiter = static::SQL_DELIMITER;
        while (strpos($triggerSql, $delimiter) !== false) {
            $delimiter .= static::SQL_DELIMITER;
        }

        $sql = "USE `{$this->dbName}`;\n";
        // ; is a default delimiter in MySQL
        // so if the trigger is one statement only, we can have less noise in the migration
        if ($delimiter != ';') {
            $sql .= "DELIMITER {$delimiter}\n";
        }
        $sql .= "{$triggerSql}{$delimiter}\n";
        if ($delimiter != ';') {
            $sql .= "DELIMITER ;\n";
        }
        $sql .= "\n";
        return $sql;
    }


    private function makeDropTrigger($triggerName)
    {
        return "DROP TRIGGER `{$this->dbName}`.`{$triggerName}`;\n\n";
    }


    private function makeDropView($viewName)
    {
        return "DROP VIEW `{$this->dbName}`.`{$viewName}`;\n";
    }


    private function makeAddView($viewName, $view)
    {
        // View declaration may contain table references inside (view x as select * from table).
        // Those references may omit database name, so we need to set default DB here.
        return "USE `{$this->dbName}`;\n"
            . "CREATE VIEW `{$this->dbName}`.`{$viewName}` {$view};\n"
        ;
    }


    private function makeAlterView($viewName, $view)
    {
        return "USE `{$this->dbName}`;\n"
            . "ALTER VIEW `{$this->dbName}`.`{$viewName}` {$view['new']};\n"
        ;
    }
}
