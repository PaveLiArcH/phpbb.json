<?php

/**
 * Handles actions related to individual topics.
 * @package phpbb.json
 * @license http://opensource.org/licenses/gpl-license.php GNU Public License
 * @author  Florin Pavel
 */

namespace phpBBJson\Modules;

class User extends Base
{
    /**
     * User login
     *
     * Data:
     * - username - (string)
     * - password - (string)
     * Result(JSON):
     * - user_ud - (integer)
     * @param \Slim\Http\Request  $request
     * @param \Slim\Http\Response $response
     * @param string[]            $args
     * @return \Slim\Http\Response
     * @throws \phpBBJson\Exception\Unauthorized
     */
    public function login($request, $response, $args)
    {
        $auth = $this->phpBB->get_auth();
        $db   = $this->phpBB->get_db();
        $user = $this->phpBB->get_user();
        $user->session_begin();
        $username = $request->getParam('username');
        $password = $request->getParam('password');
        $result   = $auth->login($username, $password, true);
        if ($username == "" || empty($username) || $password == "" || empty($password)) {
            throw new \phpBBJson\Exception\Unauthorized("One of the parameters is empty or null");
        }
        if ($result['status'] == LOGIN_SUCCESS) {
            $user_row = $result['user_row'];
            return $response->withJson(['user_id' => $user_row['user_id']]);
        } else {
            throw new \phpBBJson\Exception\Unauthorized("Login failed");
        }
    }

    /**
     * User login
     *
     * Data:
     * - username - (string)
     * - password - (string)
     * Result(JSON):
     * - user_ud - (integer)
     * @param \Slim\Http\Request  $request
     * @param \Slim\Http\Response $response
     * @param string[]            $args
     * @return \Slim\Http\Response
     * @throws \phpBBJson\Exception\Unauthorized
     */
    public function logout($request, $response, $args)
    {
        $user = $this->phpBB->get_user();
        $user->session_begin();
        if ($user->data['user_id'] != ANONYMOUS) {
            $user->session_kill(false);
        }
        return $response->withJson('logout');
    }

    /**
     * User login
     *
     * Data:
     * - username - (string)
     * - password - (string)
     * Result(JSON):
     * - user_ud - (integer)
     * @param \Slim\Http\Request  $request
     * @param \Slim\Http\Response $response
     * @param string[]            $args
     * @return \Slim\Http\Response
     * @throws \phpBBJson\Exception\Unauthorized
     */
    public function info($request, $response, $args)
    {
        $auth = $this->phpBB->get_auth();
        $db   = $this->phpBB->get_db();
        $user = $this->phpBB->get_user();
        $user->session_begin();
        if ($user->data['user_id'] == ANONYMOUS) {
            throw new \phpBBJson\Exception\Unauthorized("Must be authorized");
        }
        return $response->withJson(
            [
                'user_id'            => $user->data['user_id'],
                'username'           => $user->data['username'],
                'user_email'         => $user->data['user_email'],
                'user_birthday'      => $user->data['user_birthday'],
                'user_lang'          => $user->data['user_lang'],
                'user_timezone'      => $user->data['user_timezone'],
                'user_avatar'        => $user->data['user_avatar'],
                'user_avatar_type'   => $user->data['user_avatar_type'],
                'user_avatar_width'  => $user->data['user_avatar_width'],
                'user_avatar_height' => $user->data['user_avatar_height'],
                'user_from'          => $user->data['user_from'],
            ]
        );
    }

    /**
     * User login
     *
     * Data:
     * - username - (string)
     * - password - (string)
     * Result(JSON):
     * - user_ud - (integer)
     * @param \Slim\Http\Request  $request
     * @param \Slim\Http\Response $response
     * @param string[]            $args
     * @return \Slim\Http\Response
     * @throws \phpBBJson\Exception\Unauthorized
     */
    public function modify($request, $response, $args)
    {
        $auth = $this->phpBB->get_auth();
        $db   = $this->phpBB->get_db();
        $user = $this->phpBB->get_user();
        $user->session_begin();
        if ($user->data['user_id'] == ANONYMOUS) {
            throw new \phpBBJson\Exception\Unauthorized("Must be authorized");
        }
        return $response->withJson(
            [
                'user_id'            => $user->data['user_id'],
                'username'           => $user->data['username'],
                'user_email'         => $user->data['user_email'],
                'user_birthday'      => $user->data['user_birthday'],
                'user_lang'          => $user->data['user_lang'],
                'user_timezone'      => $user->data['user_timezone'],
                'user_avatar'        => $user->data['user_avatar'],
                'user_avatar_type'   => $user->data['user_avatar_type'],
                'user_avatar_width'  => $user->data['user_avatar_width'],
                'user_avatar_height' => $user->data['user_avatar_height'],
                'user_from'          => $user->data['user_from'],
            ]
        );
    }

    /**
     * User login
     *
     * Data:
     * - username - (string)
     * - password - (string)
     * Result(JSON):
     * - user_ud - (integer)
     * @param \Slim\Http\Request  $request
     * @param \Slim\Http\Response $response
     * @param string[]            $args
     * @return \Slim\Http\Response
     * @throws \phpBBJson\Exception\Unauthorized
     */
    public function search($request, $response, $args)
    {
        $auth = $this->phpBB->get_auth();
        $db   = $this->phpBB->get_db();
        $user = $this->phpBB->get_user();
        $user->session_begin();
        if ($user->data['user_id'] == ANONYMOUS) {
            throw new \phpBBJson\Exception\Unauthorized("Must be authorized");
        }
        $query   = strtolower($request->getParam('q', ''));
        $sql     = "
        	SELECT
        		user_id,
        		username,
            user_email,
        		user_avatar,
        		user_avatar_type,
        		user_avatar_width,
        		user_avatar_height
        	FROM " . USERS_TABLE . '
        	WHERE username_clean LIKE "%' . $db->sql_escape($query) . '%"';
        $query   = $db->sql_query($sql);
        $results = [];
        while ($row = $db->sql_fetchrow($query)) {
            $results['users'][] = [
                'user_id'            => $row['user_id'],
                'username'           => $row['username'],
                'user_email'         => $row['user_email'],
                'user_avatar'        => $row['user_avatar'],
                'user_avatar_type'   => $row['user_avatar_type'],
                'user_avatar_width'  => $row['user_avatar_width'],
                'user_avatar_height' => $row['user_avatar_height'],
            ];
        }
        return $response->withJson($results);
    }

    /**
     * @return \Closure
     */
    public function constructRoutes()
    {
        $self = $this;
        return function () use ($self) {
            /** @var \Slim\App $this */
            $this->get('', [$self, 'info']);
            $this->put('', [$self, 'modify']);
            $this->post('/login', [$self, 'login']);
            $this->get('/logout', [$self, 'logout']);
            $this->get('/search', [$self, 'search']);
            $this->get('/{userId}', [$self, 'info']);
        };
    }

    /**
     * @return string
     */
    public static function getGroup()
    {
        return '/user';
    }
}
