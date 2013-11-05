<?php

namespace Vmig;

class Dump
{
    public static function create(MysqlConnection $db, $dbname)
    {
        $dump = '';

        $db->query('USE ' . $dbname);

        // loading tables list
        $tables = array();
        $r = $db->query('SHOW TABLES');
        while ($row = $r->fetch_array()) {
            $tables[] = reset($row);
        }
        sort($tables);

        foreach ($tables as $table) {
            $dump .= "\n-- $table\n";

            $rr  = $db->query('SHOW CREATE TABLE `' . $table . '`');
            $row = $rr->fetch_row();
            $sql = $row[1];

            // removing a_i part
            $sql = preg_replace("/\s+AUTO_INCREMENT=\S*([^\r\n].*?)$/is", '$1', $sql);

            // removing view params
            $sql = preg_replace('/^(CREATE )[^\n]*? (VIEW)/', '$1$2', $sql);

            // let's sort the CONSTRAINTs, if any.
            $sqlArray    = explode("\n", $sql);
            $constraints = array();

            $constraintsPos = 0; // position of the last CONSTRAINT. After ksort, all CONSTRAINTs will be put there

            foreach ($sqlArray as $stringNum => $sqlString) {
                if (preg_match("/^(\s*CONSTRAINT `([^`]*)`[^,]*),?$/i", $sqlString, $res)) {
                    $constraints[$res[2]] = $res[1];
                    $constraintsPos = $stringNum;
                    unset($sqlArray[$stringNum]);
                }
            }

            if ($constraintsPos > 0) {
                ksort($constraints); // by symbol_name
                $sqlArray[$constraintsPos] = implode(",\n", $constraints);
                ksort($sqlArray); // by string_num
            }

            $dump .= implode("\n", $sqlArray) . ";\n";
        }

        $r = $db->query('SHOW TRIGGERS FROM `' . $dbname . '`');
        while ($row = $r->fetch_array()) {
            $name = reset($row);

            $rr         = $db->query('SHOW CREATE TRIGGER `' . $dbname . '`.`' . $name . '`');
            $crRow      = $rr->fetch_array();
            $triggerSql = $crRow[2];

            // removing trigger params
            $triggerSql = preg_replace('/^(CREATE )[^\n]*? (TRIGGER)/', '$1$2', $triggerSql);

            $dump .= "\n-- trigger: {$name}\n{$triggerSql}\n-- trigger end: {$name}\n";
        }

        return new self($dump);
    }

    public $sql = '';

    public function __construct($sql)
    {
        $this->sql = $sql;
    }
}
