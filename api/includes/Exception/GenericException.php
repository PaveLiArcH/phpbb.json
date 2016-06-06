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
    protected $status;

    /**
     * Generate a response and quit.
     *
     * @param string $status HTTP status to output
     * @param string $message Error message to output
     */
    protected function generate_response($status, $message)
    {
        $this->status = $status;
        $this->message = $message;
    }

    /**
     * @param $response \Slim\Http\Response
     * @return \Slim\Http\Response
     */
    public function respond($response) {
        return $response->withJson(['error' => $this->message], $this->status);
    }
}