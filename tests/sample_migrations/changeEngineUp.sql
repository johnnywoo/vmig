-- Migration Up

ALTER TABLE `vmig_test`.`test1`
  ENGINE=MyISAM DEFAULT CHARSET=cp1251;



-- Migration Down

ALTER TABLE `vmig_test`.`test1`
  ENGINE=InnoDB DEFAULT CHARSET=cp1251;



