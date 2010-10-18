-- Migration Up

ALTER TABLE `vmig_test`.`test1`
  MODIFY `field1` int(11) NOT NULL default '0' AFTER `field4`,
  MODIFY `field5` int(11) NOT NULL default '0' FIRST;



-- Migration Down

ALTER TABLE `vmig_test`.`test1`
  MODIFY `field5` int(11) NOT NULL default '0' AFTER `field4`,
  MODIFY `field1` int(11) NOT NULL default '0' AFTER `id`;



