-- Migration Up

ALTER TABLE `vmig_test`.`test1`
  ADD INDEX `field2` (`field2`(10));



-- Migration Down

ALTER TABLE `vmig_test`.`test1`
  DROP INDEX `field2`;



