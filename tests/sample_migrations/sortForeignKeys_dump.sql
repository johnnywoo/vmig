
-- test1
CREATE TABLE `test1` (
  `id` int(11) NOT NULL auto_increment,
  `field1` int(11) NOT NULL default '0',
  `field2` varchar(255) NOT NULL default '',
  `field3` int(11) NOT NULL default '0',
  `field4` int(11) NOT NULL default '0',
  `field5` int(11) NOT NULL default '0',
  PRIMARY KEY  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=cp1251;

-- test100
CREATE TABLE `test100` (
  `id` int(11) NOT NULL auto_increment,
  `field1` int(11) NOT NULL default '0',
  `field2` int(11) NOT NULL default '0',
  PRIMARY KEY  (`id`),
  KEY `field1` (`field1`),
  KEY `field2` (`field2`),
  CONSTRAINT `FK_test1` FOREIGN KEY (`field1`) REFERENCES `test1` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `FK_test2` FOREIGN KEY (`field2`) REFERENCES `test1` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=cp1251;
