<?php

namespace phpBBJson\Modules;

use phpBBJson\phpBB;

/**
 * Class Module
 */
abstract class Base
{
    /**
     * @var phpBB
     */
    protected $phpBB;

    /**
     * Routable constructor.
     * @param $phpBB
     */
    public function __construct($phpBB)
    {
        $this->phpBB = $phpBB;
    }

    /**
     * @return string
     */
    public static function getGroup()
    {
        return '';
    }

    /**
     * @return \Closure
     */
    public function constructRoutes()
    {
        return function () {
        };
    }
}