<?php
/**
 * Handles errors relating to nonexistent content (HTTP Error 404)
 *
 * @package    phpbb.json
 * @subpackage exceptions
 * @license    http://opensource.org/licenses/gpl-license.php GNU Public License
 * @author     Phil Crumm pcrumm@p3net.net
 */

namespace phpBBJson\Exception;

use Symfony\Component\HttpFoundation\Response;

class NotFound extends GenericException
{
    /**
     * Generate a proper response (and include the error code in the 'error' field) and quit
     *
     * @param string $message Error message
     * @param int $code Error code
     * @param \Exception $previous Previous unhandled exception
     */
    public function __construct($message = '', $code = 0, \Exception $previous = null)
    {
        $this->generate_response(Response::HTTP_NOT_FOUND, $message);
    }
}