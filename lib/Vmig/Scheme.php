<?php

namespace Vmig;

class Scheme
{
    private $schemeData = array(
        'tables' => array(),
        'views'  => array(),
    );


    public function __construct($dump)
    {
        $this->loadFromDump($dump);
    }


    public function getData()
    {
        return $this->schemeData;
    }


    private function loadFromDump($dump)
    {
        $lines = explode("\n", $dump);

        $scheme = array(
            'tables' => array(),
            'views'  => array(),
        );
        $tableName = '';
        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];

            $matches = array();
            if (preg_match('@^CREATE TABLE `(.+)`@', $line, $matches)) {
                $tableName = $matches[1];

                $scheme['tables'][$tableName] = array(
                    'name'         => $tableName,
                    'fields'       => array(),
                    'keys'         => array(),
                    'foreign_keys' => array(),
                    'triggers'     => array(),
                    'props'        => '',
                );
            }

            if (preg_match('@CREATE VIEW `(.+?)` (AS .*);@', $line, $matches)) {
                $viewName = $matches[1];
                $view     = $matches[2];

                $viewName = preg_replace('@.+?`\.`(.+)@', '$1', $viewName);

                $scheme['views'][$viewName] = $view;
            }

            if (preg_match('@^\s+`(.+)` (.+)@', $line, $matches)) {
                $fieldName  = $matches[1];
                $fieldProps = $matches[2];

                if ($fieldProps[strlen($fieldProps) - 1] == ',') {
                    $fieldProps = substr($fieldProps, 0, strlen($fieldProps) - 1);
                }

                $scheme['tables'][$tableName]['fields'][$fieldName] = $fieldProps;
            }

            if (preg_match('@\s+(PRIMARY|UNIQUE)? KEY\s?(?:`(.*?)`)?\s?\((.*)\)@', $line, $matches)) {
                $indexName = 'PRIMARY';
                if (!empty($matches[2])) {
                    $indexName = $matches[2];
                }

                $unique = false;
                if ($indexName == 'PRIMARY' || $matches[1] == 'UNIQUE') {
                    $unique = true;
                }

                $fields = $matches[3];

                $scheme['tables'][$tableName]['keys'][$indexName] = array(
                    'name'   => $indexName,
                    'unique' => $unique,
                    'fields' => $fields,
                );
            }

            if (preg_match('@CONSTRAINT `(.+)` (FOREIGN KEY .+)@', $line, $matches)) {
                $indexName  = $matches[1];
                $indexProps = $matches[2];

                if ($indexProps[strlen($indexProps) - 1] == ',') {
                    $indexProps = substr($indexProps, 0, strlen($indexProps) - 1);
                }

                $scheme['tables'][$tableName]['foreign_keys'][$indexName] = array(
                    'name'  => $indexName,
                    'props' => $indexProps,
                );
            }

            if (preg_match('@(ENGINE=.+);@', $line, $matches)) {
                $scheme['tables'][$tableName]['props'] = $matches[1];
            }

            if (preg_match('@CREATE TRIGGER `(.+?)` [^`]+ ON `(.+?)`@', $line, $matches)) {
                $triggerName = $matches[1];
                $tableName   = $matches[2];

                $triggerSql = '';
                // load all lines until trigger ends
                while (!preg_match('{^-- trigger end: ' . preg_quote($triggerName) . '$}', $lines[$i])) {
                    if (!isset($lines[$i])) {
                        throw new Error("Cannot find end of trigger {$triggerName}");
                    }

                    $triggerSql .= $lines[$i] . "\n";

                    $i++;
                }
                $triggerSql = substr($triggerSql, 0, -1); // removing \n

                $scheme['tables'][$tableName]['triggers'][$triggerName] = $triggerSql;
            }
        }

        $this->schemeData = $scheme;
    }
}
