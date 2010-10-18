-- Migration Up

ALTER TABLE `vmig_test`.`test1`
  ADD COLUMN `field100` int(11) NOT NULL AFTER `field5`,
  ADD COLUMN `field101` varchar(255) NOT NULL AFTER `field100`;



-- Migration Down

ALTER TABLE `vmig_test`.`test1`
  DROP COLUMN `field100`,
  DROP COLUMN `field101`;



