<?php
/**
 * The Board module handles requests concerning the "root" of the phpBB installation--statistics, the forum list, etc.
 *
 * @package phpbb.json
 * @license http://opensource.org/licenses/gpl-license.php GNU Public License
 * @author  Florin Pavel
 */

namespace phpBBJson\Modules;

class Service extends Base
{
    /**
     * @param \Slim\Http\Request $request
     * @param \Slim\Http\Response $response
     * @param string[] $args
     * @return \Slim\Http\Response
     * @throws \phpBBJson\Exception\InternalError
     */
    public function utf8CleanString($request, $response, $args)
    {
        return $response->withJson(['text' => utf8_clean_string($request->getParam('text'))]);
    }

    /**
     * @param \Slim\Http\Request $request
     * @param \Slim\Http\Response $response
     * @param string[] $args
     * @return \Slim\Http\Response
     * @throws \phpBBJson\Exception\InternalError
     */
    public function emailHash($request, $response, $args)
    {
        return $response->withJson(['text' => phpbb_email_hash($request->getParam('text'))]);
    }

    /**
     * @param \Slim\Http\Request $request
     * @param \Slim\Http\Response $response
     * @param string[] $args
     * @return \Slim\Http\Response
     * @throws \phpBBJson\Exception\InternalError
     */
    public function uniqueId($request, $response, $args)
    {
        return $response->withJson(['text' => unique_id($request->getParam('text'))]);
    }

    /**
     * @return \Closure
     */
    public function constructRoutes()
    {
        $self = $this;
        return function () use ($self) {
            /** @var \Slim\App $this */
            $this->post('/utf8_clean_string', [$self, 'utf8CleanString']);
            $this->post('/email_hash', [$self, 'emailHash']);
            $this->post('/unique_id', [$self, 'uniqueId']);
        };
    }

    /**
     * @return string
     */
    public static function getGroup()
    {
        return '/service';
    }
}
