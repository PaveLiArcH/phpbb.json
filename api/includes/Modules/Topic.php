<?php

/**
 * Handles actions related to individual topics.
 * @package phpbb.json
 * @license http://opensource.org/licenses/gpl-license.php GNU Public License
 * @author  Florin Pavel
 */

namespace phpBBJson\Modules;

class Topic extends Base
{
    /**
     * List statistics for a topic
     *
     * Data: topic_id - (integer)
     * Result(JSON):
     * - forum_id - (integer)
     * - total_replies - (integer)
     * @param \Slim\Http\Request $request
     * @param \Slim\Http\Response $response
     * @param string[] $args
     * @return \Slim\Http\Response
     * @throws \phpBBJson\Exception\InternalError
     */
    public function info($request, $response, $args)
    {
        $db = $this->phpBB->get_db();
        $secret = isset($params['secret']) && \phpBBJson\apiHelpers::verifySecret($params['secret']) ? $params['secret'] : null;
        $topic_id = !empty($args['topicId']) ? $args['topicId'] : null;
        if ($topic_id == null) {
            throw new \phpBBJson\Exception\InternalError("The topic you selected does not exist.");
        }
        $sql = "SELECT topic_posts_approved, forum_id FROM " . TOPICS_TABLE . " WHERE topic_id = {$topic_id}";
        $result = $db->sql_query($sql);
        $row = $db->sql_fetchrow($result);
        $info = array(
            'forum_id' => $row['forum_id'],
            'total_replies' => $row['topic_posts_approved']
        );
        return $response->withJson($info);
    }

    /**
     * List all posts in a topic, sorted chronologically (oldest first).
     * @param \Slim\Http\Request $request
     * @param \Slim\Http\Response $response
     * @param string[] $args
     * @return \Slim\Http\Response
     * @throws \phpBBJson\Exception\InternalError
     */
    public function postList($request, $response, $args)
    {
        define('PHPBB_USE_BOARD_URL_PATH', true);

        $db = $this->phpBB->get_db();
        $user = $this->phpBB->get_user();
        $auth = $this->phpBB->get_auth();
        $config = $this->phpBB->get_config();
        $phpbb_container = $this->phpBB->get_container();
        /** @var \phpbb\feed\helper $phpbb_feed_helper */
        $phpbb_feed_helper = $phpbb_container->get('feed.helper');

        $results = array();

        $topic_id = !empty($args['topicId']) ? $args['topicId'] : null;
        if ($topic_id == null) {
            throw new \phpBBJson\Exception\InternalError("The topic you selected does not exist.");
        } else {
            $topic_id = intval($topic_id);
        }

        $sort = $request->getParam('sort', 'ASC');
        $sort = strtoupper($sort);
        switch ($sort) {
            case 'ASC':
            case 'DESC':
                break;
            default:
                $sort = 'ASC';
                break;
        }

        $limit = intval($request->getParam('limit'));
        if ($limit > 0) {
            $limit = "LIMIT {$limit}";
        } else {
            $limit = '';
        }

        $olderThan = intval($request->getParam('olderThan'));
        if ($olderThan > 0) {
            $olderThan = " AND post_time > {$olderThan}";
        } else {
            $olderThan = '';
        }

        $secret = isset($params['secret']) && \phpBBJson\apiHelpers::verifySecret($params['secret']) ? $params['secret'] : null;
        $user_id = null;
        if ($secret != null) {
            $user_id = \phpBBJson\apiHelpers::getIdFromSecret($secret);
            $userdata = \phpBBJson\apiHelpers::userdata($user_id);
        } else {
            $user->session_begin();
            $userdata = $user->data;
            $user_id = $userdata['user_id'];
        }
        $user->setup('viewtopic');
        $auth->acl($userdata);

        // get forum id and topic title
        $obj = $db->sql_fetchrow(
            $db->sql_query("SELECT forum_id, topic_title FROM " . TOPICS_TABLE . " WHERE topic_id = " . $topic_id)
        );
        $forum_id = $obj['forum_id'];
        $topic_title = $obj['topic_title'];

        // get forum title
        $obj = $db->sql_fetchrow(
            $db->sql_query("SELECT forum_name FROM " . FORUMS_TABLE . " WHERE forum_id = " . $forum_id)
        );
        $forum_title = $obj['forum_name'];

        $sql = "
        	SELECT COUNT(*) AS posts_count
        	FROM " . POSTS_TABLE . "
        	WHERE topic_id = " . $topic_id . " AND post_visibility = 1 {$olderThan}
        ";
        $result = $db->sql_query($sql);
        $row = $db->sql_fetchrow($result);
        $postsCount = $row['posts_count'];

        // get topic posts
        $sql = "
        	SELECT 
        		post_id, 
        		post_time,
        		post_text,
        		bbcode_uid,
        		bbcode_bitfield,
        		" . USERS_TABLE . ".username, 
        		" . USERS_TABLE . ".user_id,
        		" . USERS_TABLE . ".user_avatar as avatar,
        		" . USERS_TABLE . ".user_avatar_type as avatar_type,
        		" . USERS_TABLE . ".user_avatar_width as avatar_width,
        		" . USERS_TABLE . ".user_avatar_height as avatar_height
        	FROM " . POSTS_TABLE . "
        	LEFT OUTER JOIN " . USERS_TABLE . " ON " . USERS_TABLE . ".user_id = " . POSTS_TABLE . ".poster_id
        	WHERE topic_id = " . $topic_id . " AND post_visibility = 1 {$olderThan}
        	ORDER BY post_time {$sort}
        	{$limit}
        ";

        $query = $db->sql_query($sql);
        $results = array(
            'forum_id' => $forum_id,
            'forum_name' => $forum_title,
            'topic_id' => $topic_id,
            'topic_title' => $topic_title,
            'posts_count' => $postsCount
        );

        while ($row = $db->sql_fetchrow($query)) {        // Allow all combinations
            $options = 7;
            if ($row['enable_bbcode'] !== null && $row['enable_smilies'] !== null && $row['enable_magic_url'] !== null) {
                $options = ($row['enable_bbcode'] ? OPTION_FLAG_BBCODE : 0) + ($row['enable_smilies'] ? OPTION_FLAG_SMILIES : 0) + ($row['enable_smilies'] ? OPTION_FLAG_LINKS : 0);
            }
            $phpbb_avatar_manager = $phpbb_container->get('avatar.manager');
            $driver = $phpbb_avatar_manager->get_driver($row['avatar_type'], true);
            if ($driver)
            {
                $avatar_data = $driver->get_data($row);
            }
            else
            {
                $avatar_data['src'] = '';
            }
            $results['posts'][] = array(
                'post_id' => $row['post_id'],
                'author_id' => $row['user_id'],
                'author_username' => $row['username'],
                'author_avatar' => $avatar_data,
                'timestamp' => $row['post_time'],
                'post_text' => censor_text($phpbb_feed_helper->generate_content($row['post_text'], $row['bbcode_uid'], $row['bbcode_bitfield'], $options, $forum_id, [])),
            );
        }

        return $response->withJson($results);
    }

    /**
     * Get the currently authenticated user's permissions. User must be authenticated.
     * @param \Slim\Http\Request $request
     * @param \Slim\Http\Response $response
     * @param string[] $args
     * @return \Slim\Http\Response
     * @throws \phpBBJson\Exception\InternalError
     */
    public function permissions($request, $response, $args)
    {
        $db = $this->phpBB->get_db();
        $user = $this->phpBB->get_user();
        $auth = $this->phpBB->get_auth();

        $topic_id = !empty($args['topicId']) ? $args['topicId'] : null;
        if ($topic_id == null) {
            throw new \phpBBJson\Exception\InternalError("The topic you selected does not exist.");
        }

        $secret = isset($params['secret']) && \phpBBJson\apiHelpers::verifySecret($params['secret']) ? $params['secret'] : null;
        $user_id = null;
        if ($secret != null) {
            $user_id = \phpBBJson\apiHelpers::getIdFromSecret($secret);
            $userdata = \phpBBJson\apiHelpers::userdata($user_id);
        } else {
            $user->session_begin();
            $userdata = $user->data;
        }
        $auth->acl($userdata);

        $obj = $db->sql_fetchrow(
            $db->sql_query("SELECT forum_id FROM " . TOPICS_TABLE . " WHERE topic_id = " . $topic_id)
        );
        $forum_id = $obj['forum_id'];

        $permissions = array(
            'can_see' => ($auth->acl_get('f_list', $forum_id)) ? true : false,
            'can_read' => ($auth->acl_get('f_read', $forum_id)) ? true : false,
            'can_post' => ($auth->acl_get('f_post', $forum_id)) ? true : false,
            'can_reply' => ($auth->acl_get('f_reply', $forum_id)) ? true : false
        );

        return $response->withJson($permissions);
    }

    /**
     * Post a reply to a topic. User must be authenticated
     * @param \Slim\Http\Request $request
     * @param \Slim\Http\Response $response
     * @param string[] $args
     * @return \Slim\Http\Response
     * @throws \phpBBJson\Exception\InternalError
     * @throws \phpBBJson\Exception\Unauthorized
     */
    public function reply($request, $response, $args)
    {
        global $phpEx, $phpbb_root_path;
        include($phpbb_root_path . 'includes/functions_posting.' . $phpEx);
        include($phpbb_root_path . 'includes/message_parser.' . $phpEx);

        $db = $this->phpBB->get_db();
        $user = $this->phpBB->get_user();
        $auth = $this->phpBB->get_auth();
        $config = $this->phpBB->get_config();

        $topic_id = !empty($args['topicId']) ? $args['topicId'] : null;
        if ($topic_id == null) {
            throw new \phpBBJson\Exception\InternalError("The topic you selected does not exist.");
        }
        $obj = $db->sql_fetchrow(
            $db->sql_query("SELECT forum_id, topic_title FROM " . TOPICS_TABLE . " WHERE topic_id = " . $topic_id)
        );
        $forum_id = $obj['forum_id'];

        $secret = isset($params['secret']) && \phpBBJson\apiHelpers::verifySecret($params['secret']) ? $params['secret'] : null;
        $user_id = null;
        if ($secret != null) {
            $user_id = \phpBBJson\apiHelpers::getIdFromSecret($secret);
            $userdata = \phpBBJson\apiHelpers::userdata($user_id);
            $auth->acl($userdata);
            $user->session_begin();
            $user->data = array_merge($user->data, $userdata);
        } else {
            $user->session_begin();
            $userdata = $user->data;
            $auth->acl($userdata);
        }

        if ($auth->acl_get('f_reply', $forum_id)) {
            $uid = $bitfield = $flags = '';
            $message = $request->getParam('topic_body');
            $subject = "Re: " . $obj['topic_title'];
            generate_text_for_storage($message, $uid, $bitfield, $flags, true, true, true);

            $data = array(
                // General Posting Settings
                'forum_id' => $forum_id,
                // The forum ID in which the post will be placed. (int)
                'topic_id' => $topic_id,
                // Post a new topic or in an existing one? Set to 0 to create a new one, if not, specify your topic ID here instead.
                'icon_id' => false,
                // The Icon ID in which the post will be displayed with on the viewforum, set to false for icon_id. (int)
                // Defining Post Options
                'enable_bbcode' => true,
                // Enable BBcode in this post. (bool)
                'enable_smilies' => true,
                // Enabe smilies in this post. (bool)
                'enable_urls' => true,
                // Enable self-parsing URL links in this post. (bool)
                'enable_sig' => true,
                // Enable the signature of the poster to be displayed in the post. (bool)
                // Message Body
                'message' => $message,
                // Your text you wish to have submitted. It should pass through generate_text_for_storage() before this. (string)
                'message_md5' => md5($message),
                // The md5 hash of your message
                // Values from generate_text_for_storage()
                'bbcode_bitfield' => $bitfield,
                // Value created from the generate_text_for_storage() function.
                'bbcode_uid' => $uid,
                // Value created from the generate_text_for_storage() function.
                // Other Options
                'post_edit_locked' => 0,
                // Disallow post editing? 1 = Yes, 0 = No
                'topic_title' => $subject,
                // Subject/Title of the topic. (string)
                // Email Notification Settings
                'notify_set' => false,
                // (bool)
                'notify' => false,
                // (bool)
                'post_time' => 0,
                // Set a specific time, use 0 to let submit_post() take care of getting the proper time (int)
                'forum_name' => '',
                // For identifying the name of the forum in a notification email. (string)
                // Indexing
                'enable_indexing' => true,
                // Allow indexing the post? (bool)
                // 3.0.6
                'force_approved_state' => true,
                // Allow the post to be submitted without going into unapproved queue
                // 3.1-dev, overwrites force_approve_state
                'force_visibility' => true,
                // Allow the post to be submitted without going into unapproved queue, or make it be deleted
                'topic_first_poster_colour' => $userdata['user_colour']
            );

            $poll = array();
            $result = submit_post('reply', $subject, $userdata['username'], POST_NORMAL, $poll, $data);
            preg_match("/p=\d(.*?)\b/", $result, $matches);
            $post_id = explode('=', $matches[0]);
            $post_id = $post_id[1];

            return $response->withJson(['post_id' => $post_id]);
        } else {
            throw new \phpBBJson\Exception\Unauthorized(
                "You do not have necessary permissions to post in this topic!"
            );
        }
    }

    /**
     * @return \Closure
     */
    public function constructRoutes()
    {
        $self = $this;
        return function () use ($self) {
            /** @var \Slim\App $this */
            $this->get('/{topicId}', [$self, 'info']);
            $this->get('/{topicId}/permissions', [$self, 'permissions']);
            $this->get('/{topicId}/posts', [$self, 'postList']);
            $this->get('/{topicId}/posts/{page}', [$self, 'postList']);
            $this->post('/{topicId}/posts', [$self, 'reply']);
        };
    }

    /**
     * @return string
     */
    public static function getGroup()
    {
        return '/topic';
    }
}