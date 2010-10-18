-- Migration Up

ALTER TABLE `vmig_test`.`test1`
  MODIFY `field2` varchar(255) NOT NULL default '' AFTER `field4`;



-- Migration Down

ALTER TABLE `vmig_test`.`test1`
  MODIFY `field2` varchar(255) NOT NULL default '' AFTER `field1`;



