
--rename: 0002_create_302_renamed1.sql -> 0002_create_302_renamed2.sql

done.

-- down: 0004_create_304.sql

ALTER TABLE `vmig_test`.`test1`
  DROP COLUMN `field304`;

-- down: 0003_create_303.sql

ALTER TABLE `vmig_test`.`test1`
  DROP COLUMN `field303`;

-- up: 0003_create_303.sql

ALTER TABLE `vmig_test`.`test1`
  ADD COLUMN `field303` int(1) NOT NULL AFTER `field302`;

-- up: 0004_create_304.sql

ALTER TABLE `vmig_test`.`test1`
  ADD COLUMN `field304` int(1) NOT NULL AFTER `field303`;
