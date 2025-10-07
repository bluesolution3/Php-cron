<?php

namespace Crons;

abstract class AbstractCron {
    use \Traits\Db;
    use \Traits\Debug;

    protected $f3;

    public function __construct() {
        $this->f3 = \Base::instance();

        $this->connectToDb(false);
    }

    protected function log(string $message): void {
        $cronName = get_class($this);
        $cronName = substr($cronName, strrpos($cronName, '\\') + 1);
        echo sprintf('[%s] %s%s', $cronName, $message, PHP_EOL);
    }
}
