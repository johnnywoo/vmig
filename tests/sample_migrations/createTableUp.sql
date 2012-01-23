-- Migration Up

CREATE TABLE `vmig_test`.`test100` (
  id int(11) NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=cp1251;



-- Migration Down

DROP TABLE `vmig_test`.`test100`;



