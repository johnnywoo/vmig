-- Migration Up

USE `vmig_test`;
CREATE VIEW `vmig_test`.`view1` AS (select `test1`.`id` AS `iid` from `test1`);


-- Migration Down

DROP VIEW `vmig_test`.`view1`;


