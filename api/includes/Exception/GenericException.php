<?php
/**
 * Generic exception format for errors
 *
 * @package    phpbb.json
 * @subpackage exceptions
 * @license    http://opensource.org/licenses/gpl-license.php GNU Public License
 * @author     Phil Crumm pcrumm@p3net.net
 */

namespace phpBBJson\Exception;

class GenericException extends \Exception
{
    /**
     * Generate a response and quit.
     *
     * @param string $header  HTTP header to output
     * @param string $message Error message to output
     */
    protected function generate_response($header, $message)
    {
        /*$response = new \phpBBJson\Response();
        $response->set_header(HTTP_BAD_FORMAT);
        $response->set_data(array('error', $message));
        
        $response->response();
        
        // Throw away any stored response
        while (ob_get_level() > 1)
        {
            ob_end_clean();
        }*/
    }
}