-- Migration Up

ALTER TABLE `vmig_test`.`test1`
  MODIFY `field4` int(11) NOT NULL DEFAULT '0' AFTER `field2`;



-- Migration Down

ALTER TABLE `vmig_test`.`test1`
  MODIFY `field3` int(11) NOT NULL DEFAULT '0' AFTER `field2`;



