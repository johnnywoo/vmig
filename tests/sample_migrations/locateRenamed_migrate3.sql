
-- down: 0004_create_304.sql

ALTER TABLE `vmig_test`.`test1`
  DROP COLUMN `field304`;

-- down: 0003_create_303.sql

ALTER TABLE `vmig_test`.`test1`
  DROP COLUMN `field303`;

-- down: 0002_create_302_renamed2.sql

ALTER TABLE `vmig_test`.`test1`
  DROP COLUMN `field302`;

-- down: 0001_create_301.sql

ALTER TABLE `vmig_test`.`test1`
  DROP COLUMN `field301`;

-- up: 0001_create_301.sql

ALTER TABLE `vmig_test`.`test1`
  ADD COLUMN `field301` int(1) NOT NULL AFTER `field5`;

-- up: 0002_create_302_renamed3.sql

ALTER TABLE `vmig_test`.`test1`
  ADD COLUMN `field302` int(1) NOT NULL AFTER `field301`;

-- up: 0003_create_303.sql

ALTER TABLE `vmig_test`.`test1`
  ADD COLUMN `field303` int(1) NOT NULL AFTER `field302`;

-- up: 0004_create_304.sql

ALTER TABLE `vmig_test`.`test1`
  ADD COLUMN `field304` int(1) NOT NULL AFTER `field303`;
