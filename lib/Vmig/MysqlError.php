<?php

namespace Vmig;

require_once __DIR__ . '/Error.php';

class MysqlError extends Error
{
    const ER_EMPTY_QUERY = 1065;
}
