-- Migration Up

CREATE TABLE `vmig_test`.`test100` (
  id int(11) NOT NULL AUTO_INCREMENT,
  field1 int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `field1` (`field1`)
) ENGINE=InnoDB DEFAULT CHARSET=cp1251;

ALTER TABLE `vmig_test`.`test100` ADD CONSTRAINT `FK_test` FOREIGN KEY (`field1`) REFERENCES `test1` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;


-- Migration Down

ALTER TABLE `vmig_test`.`test100` DROP FOREIGN KEY `FK_test`;
DROP TABLE `vmig_test`.`test100`;



