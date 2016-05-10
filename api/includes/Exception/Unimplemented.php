<?php
/**
 * Handles exceptions relating to unimplemented errors (HTTP Error 501)
 *
 * @package    phpbb.json
 * @subpackage exceptions
 * @license    http://opensource.org/licenses/gpl-license.php GNU Public License
 * @author     Phil Crumm pcrumm@p3net.net
 */

namespace phpBBJson\Exception;

class Unimplemented extends GenericException
{
    /**
     * Generate a proper response (and include the error code in the 'error' field) and quit
     *
     * @param string     $message  Error message
     * @param int        $code     Error code
     * @param \Exception $previous Previous unhandled exception
     */
    public function __construct($message = '', $code = 0, \Exception $previous = null)
    {
        $this->generate_response(HTTP_UNIMPLEMENTED, $message);
    }
}