-- Migration Up

ALTER TABLE `vmig_test`.`test1`
  DROP COLUMN `field2`,
  MODIFY `field5` int(11) NOT NULL default '0' AFTER `field1`;



-- Migration Down

ALTER TABLE `vmig_test`.`test1`
  ADD COLUMN `field2` varchar(255) NOT NULL default '' AFTER `field1`,
  MODIFY `field5` int(11) NOT NULL default '0' AFTER `field4`;



