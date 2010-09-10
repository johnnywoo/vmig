<?

require_once dirname(__FILE__).'/Error.php';

class Vmig_MysqlError extends Vmig_Error
{
	const ER_EMPTY_QUERY = 1065;
}