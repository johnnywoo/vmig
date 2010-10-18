-- Migration Up

ALTER TABLE `vmig_test`.`test1`
  ADD INDEX `field1` (`field1`);



-- Migration Down

ALTER TABLE `vmig_test`.`test1`
  DROP INDEX `field1`;



