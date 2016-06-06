<?php

namespace phpBBJson;

use Slim\Http\Headers;
use Slim\Http\Request;
use Zend\Diactoros\ServerRequest;

class apiHelpers
{
    public static function getIdFromSecret($secret)
    {
        global $phpbb;
        $db = $phpbb->get_db();
        $sql = "SELECT `user_id` FROM " . API_SECRET . " WHERE secret = '{$secret}'";
        $result = $db->sql_query($sql);
        $row = $db->sql_fetchrow($result);

        return $row['user_id'];
    }

    public static function verifySecret($secret)
    {
        global $phpbb;
        $db = $phpbb->get_db();
        $sql = "SELECT COUNT(*) AS num_count FROM " . API_SECRET . " WHERE secret = '{$secret}'";
        $result = $db->sql_query($sql);
        $count = $db->sql_fetchfield('num_count');

        if ($count > 0) {
            return true;
        } else {
            throw new \phpBBJson\Exception\InternalError("Your secret code is not valid");
        }
    }

    public static function userdata($user_id)
    {
        global $phpbb;
        $db = $phpbb->get_db();
        $sql = "SELECT * FROM " . USERS_TABLE . " WHERE `user_id` = {$user_id}";
        $result = $db->sql_query($sql);

        return $db->sql_fetchrow($result);
    }

    /**
     * @param ServerRequest $request
     * @return Request
     */
    public static function adaptRequest(ServerRequest $request)
    {
        return new Request(
            $request->getMethod(), $request->getUri(), new Headers($request->getHeaders()),
            $request->getCookieParams(), $request->getServerParams(), $request->getBody(), $request->getUploadedFiles()
        );
    }
}