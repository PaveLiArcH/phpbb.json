<?php
/**
 * The forum module handles actions related to individual forums.
 * @package phpbb.json
 * @license http://opensource.org/licenses/gpl-license.php GNU Public License
 * @author  Florin Pavel
 */
namespace phpBBJson\Modules;

class Forum extends Base
{
    /**
     * Lists statistics for a forum.
     * Data: forum_id - (integer)
     * Result(JSON):
     * - total_topics
     * - total_posts
     * - total_replies
     * @param \Slim\Http\Request $request
     * @param \Slim\Http\Response $response
     * @param string[] $args
     * @return \Slim\Http\Response
     * @throws \phpBBJson\Exception\InternalError
     */
    public function info($request, $response, $args)
    {
        $db = $this->phpBB->get_db();
        $forumId = !empty($args['forumId']) ? intval($args['forumId']) : null;
        if (!$forumId) {
            throw new \phpBBJson\Exception\InternalError("The forum you selected does not exist.");
        }
        $sql = "SELECT SUM(t.topic_posts_approved) AS replies, f.forum_posts_approved, f.forum_topics_approved FROM "
            . TOPICS_TABLE . " t, " . FORUMS_TABLE . " f WHERE f.forum_id = {$forumId} AND t.forum_id = f.forum_id";
        $result = $db->sql_query($sql);
        $row = $db->sql_fetchrow($result);
        $info = array(
            'total_topics' => $row['forum_topics_approved'],
            'total_posts' => $row['forum_posts_approved'],
            'total_replies' => $row['replies']
        );
        return $response->withJson($info);
    }

    /**
     * Get the currently authenticated user's permissions. You must be authenticated.
     * Data:
     * - forum_id - (integer)
     * - secret(string) - The authentication code
     * Result(JSON):
     * - can_see - (boolean) Can see the forum
     * - can_read - (boolean) Can read the forum
     * - can_post - (boolean) Can post topics to the forum
     * - can_reply - (boolean) Can reply to topics in the forum
     * @param \Slim\Http\Request $request
     * @param \Slim\Http\Response $response
     * @param string[] $args
     * @return \Slim\Http\Response
     * @throws \phpBBJson\Exception\InternalError
     */
    public function permissions($request, $response, $args)
    {
        $params = $request->getQueryParams();

        $db = $this->phpBB->get_db();
        $user = $this->phpBB->get_user();
        $auth = $this->phpBB->get_auth();

        $forum_id = !empty($args['forumId']) ? intval($args['forumId']) : null;

        if (!$forum_id) {
            throw new \phpBBJson\Exception\InternalError("'forumId' is null");
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

        $permissions = array(
            'can_see' => ($auth->acl_get('f_list', $forum_id)) ? true : false,
            'can_read' => ($auth->acl_get('f_read', $forum_id)) ? true : false,
            'can_post' => ($auth->acl_get('f_post', $forum_id)) ? true : false,
            'can_reply' => ($auth->acl_get('f_reply', $forum_id)) ? true : false
        );

        return $response->withJson($permissions);
    }

    /**
     * List topics and subforums in a forum
     * Data:
     * - forum_id - (integer) The numeric ID of the forum
     * - per_page - (integer, optional: defaults to board setting) The number of topics to return
     * - page - (integer, optional: defaults to 1) the page to display. Uses the per_page setting to determine offset
     * - secret(string, optional) - The authentication code
     * Result: Two JSON array containing topic and subforum data
     * @param \Slim\Http\Request $request
     * @param \Slim\Http\Response $response
     * @param string[] $args
     * @return \Slim\Http\Response
     * @throws \phpBBJson\Exception\InternalError
     * @throws \phpBBJson\Exception\Unauthorized
     */
    public function topicList($request, $response, $args)
    {
        $params = $request->getQueryParams();

        $db = $this->phpBB->get_db();
        $user = $this->phpBB->get_user();
        $auth = $this->phpBB->get_auth();
        $config = $this->phpBB->get_config();
        $results = array();

        $forum_id = !empty($args['forumId']) ? intval($args['forumId']) : null;

        if (!$forum_id) {
            throw new \phpBBJson\Exception\InternalError("The forum you selected does not exist.");
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
        $auth->acl($userdata);

        $sql_from = FORUMS_TABLE . ' f';
        $lastread_select = '';

        // Grab appropriate forum data
        if ($config['load_db_lastread'] && $secret != null) {
            $sql_from .= ' LEFT JOIN ' . FORUMS_TRACK_TABLE . ' ft ON (ft.user_id = ' . $user_id . '
		AND ft.forum_id = f.forum_id)';
            $lastread_select .= ', ft.mark_time';
        }

        if ($secret != null) {
            $sql_from .= ' LEFT JOIN ' . FORUMS_WATCH_TABLE . ' fw ON (fw.forum_id = f.forum_id AND fw.user_id = ' . $user_id . ')';
            $lastread_select .= ', fw.notify_status';
        }

        $sql = "SELECT f.* $lastread_select
	FROM $sql_from
	WHERE f.forum_id = $forum_id";
        $result = $db->sql_query($sql);
        $forum_data = $db->sql_fetchrow($result);
        $db->sql_freeresult($result);

        if (!$forum_data) {
            throw new \phpBBJson\Exception\InternalError("The forum you selected does not exist.");
        }

        // Permissions check
        if (!$auth->acl_gets('f_list', 'f_read', $forum_id) || ($forum_data['forum_type'] == FORUM_LINK &&
                $forum_data['forum_link'] && !$auth->acl_get('f_read', $forum_id))
        ) {
            if ($user_id != 1) {
                throw new \phpBBJson\Exception\Unauthorized("You are not authorised to read this forum.");
            }
            throw new \phpBBJson\Exception\Unauthorized(
                "The board requires you to be registered and logged in to view this forum."
            );
        }

        $sql_array = array(
            'SELECT' => 'f.*',
            'FROM' => array(
                FORUMS_TABLE => 'f'
            ),
            'LEFT_JOIN' => array(),
        );

        if ($secret != null) {
            $sql_array['LEFT_JOIN'][] = array(
                'FROM' => array(
                    FORUMS_TRACK_TABLE => 'ft'
                ),
                'ON' => 'ft.user_id = ' . $user_id . ' AND ft.forum_id = f.forum_id'
            );
            $sql_array['SELECT'] .= ', ft.mark_time';
        }

        $sql = $db->sql_build_query(
            'SELECT',
            array(
                'SELECT' => $sql_array['SELECT'],
                'FROM' => $sql_array['FROM'],
                'LEFT_JOIN' => $sql_array['LEFT_JOIN'],
                'WHERE' => 'parent_id = ' . $forum_id
            )
        );

        $result = $db->sql_query($sql);

        $forums = array();
        while ($row = $db->sql_fetchrow($result)) {

            $subforum_id = $row['forum_id'];

            // Category with no members
            if ($row['forum_type'] == 0 && ($row['left_id'] + 1 == $row['right_id'])) {
                continue;
            }

            // Skip branch
            if (isset($right_id)) {
                if ($row['left_id'] < $right_id) {
                    continue;
                }
                unset($right_id);
            }

            if (!$auth->acl_get('f_list', $subforum_id)) {
                // if the user does not have permissions to list this forum, skip everything until next branch
                $right_id = $row['right_id'];
                continue;
            }

            $forums[] = array(
                'forum_id' => $row['forum_id'],
                'parent_id' => $row['parent_id'],
                'forum_name' => $row['forum_name'],
                'unread' => ($row['mark_time'] == null) ? true : false,
                'total_topics' => $row['forum_topics'],
                'total_posts' => $row['forum_posts'],
                'last_poster_id' => $row['forum_last_poster_id'],
                'last_poster_name' => $row['forum_last_poster_name'],
                'last_post_topic_id' => $row['forum_last_post_id'],
                'last_post_topic_name' => $row['forum_last_post_subject'],
                'last_post_time' => $row['forum_last_post_time']
            );
        }

        if (count($forums) > 0) {
            $results['subforums'] = $forums;
        }

        $per_page = !empty($params['per_page']) ? $params['per_page'] : null;
        $page = !empty($args['page']) ? $args['page'] : 1;

        // Is a forum specific topic count required?
        if ($forum_data['forum_topics_per_page'] && $per_page == null) {
            $config['topics_per_page'] = $forum_data['forum_topics_per_page'];
        } elseif ($per_page != null) {
            $config['topics_per_page'] = $per_page;
        }

        $limit = $config['topics_per_page'];
        $total_topics = $forum_data['forum_topics'];

        $total_pages = ceil($total_topics / $limit);
        $set_limit = $page * $limit - ($limit);

        $sql_array2 = array(
            'SELECT' => 't.*',
            'FROM' => array(
                TOPICS_TABLE => 't'
            ),
            'LEFT_JOIN' => array(),
        );

        if ($secret != null && $user_id != 1) {

            if ($config['load_db_track']) {
                $sql_array2['LEFT_JOIN'][] = array(
                    'FROM' => array(TOPICS_POSTED_TABLE => 'tp'),
                    'ON' => 'tp.topic_id = t.topic_id AND tp.user_id = ' . $user_id
                );
                $sql_array2['SELECT'] .= ', tp.topic_posted';
            }

            if ($config['load_db_lastread']) {
                $sql_array2['LEFT_JOIN'][] = array(
                    'FROM' => array(TOPICS_TRACK_TABLE => 'tt'),
                    'ON' => 'tt.topic_id = t.topic_id AND tt.user_id = ' . $user_id
                );
                $sql_array2['SELECT'] .= ', tt.mark_time';
            }
        }

        $sql_approved = ($auth->acl_get('m_approve', $forum_id)) ? '' : 'AND t.topic_approved = 1';

        $sql_where = "t.forum_id = " . $forum_id;
        $sql = $db->sql_build_query(
            'SELECT',
            array(
                'SELECT' => $sql_array2['SELECT'],
                'FROM' => $sql_array2['FROM'],
                'LEFT_JOIN' => $sql_array2['LEFT_JOIN'],
                'WHERE' => $sql_where,
                'ORDER_BY' => 'topic_time DESC'
            )
        );
        $result = $db->sql_query_limit($sql, $limit, $set_limit);
        $topics = array();
        while ($row = $db->sql_fetchrow($result)) {
            $topics[] = array(
                'topic_id' => $row['topic_id'],
                'topic_title' => $row['topic_title'],
                'topic_author_username' => $row['topic_first_poster_name'],
                'topic_time' => $row['topic_time'],
                'topic_last_reply_username ' => $row['topic_last_poster_name'],
                'topic_last_reply_id' => $row['topic_last_post_id'],
                'topic_last_reply_time' => $row['topic_last_post_time'],
                'topic_num_replies' => $row['topic_replies'],
                'topic_unread' => ($row['mark_time'] != null) ? true : false,
                'topic_posted' => ($row['topic_posted']) ? true : false,
                'topic_locked' => ($row['topic_status'] == ITEM_LOCKED) ? true : false,
                'topic_status' => ($row['topic_status'] == ITEM_LOCKED) ? 'locked' : ($row['topic_status'] == ITEM_MOVED) ? 'shadow' : 'normal',
            );
        }

        if (count($topics) > 0) {
            $results['topics'] = $topics;
        }

        return $response->withJson($results);
    }

    /**
     * Create a new topic. You must be authenticated.
     * Data:
     * - secret (string) - The authentication code
     * - forum_id (integer)
     * - topic_title (string)
     * - topic_body (string)
     * @param \Slim\Http\Request $request
     * @param \Slim\Http\Response $response
     * @param string[] $args
     * @return \Slim\Http\Response
     * @throws \phpBBJson\Exception\InternalError
     * @throws \phpBBJson\Exception\Unauthorized
     * Result(JSON): topic_id - (integer) The ID of the newly created topic
     */
    public function newTopic($request, $response, $args)
    {
        $params = $request->getQueryParams();
        $body = $request->getParsedBody();

        global $phpEx, $phpbb_root_path;
        include($phpbb_root_path . 'includes/functions_posting.' . $phpEx);
        include($phpbb_root_path . 'includes/message_parser.' . $phpEx);
        $db = $this->phpBB->get_db();
        $user = $this->phpBB->get_user();
        $auth = $this->phpBB->get_auth();
        $config = $this->phpBB->get_config();

        $secret = isset($params['secret']) && \phpBBJson\apiHelpers::verifySecret($params['secret']) ? $params['secret'] : null;
        $user_id = null;
        if ($secret != null) {
            $user_id = \phpBBJson\apiHelpers::getIdFromSecret($secret);
            $userdata = \phpBBJson\apiHelpers::userdata($user_id);
            $auth->acl($userdata);
            $user->session_begin();
            $user->data = array_merge($user->data, $userdata);

            $forum_id = !empty($args['forumId']) ? $args['forumId'] : null;
            if ($auth->acl_get('f_post', $forum_id)) {
                $uid = $bitfield = $flags = '';
                $message = $request->getParam('topic_body');
                $subject = $request->getParam('topic_title');
                generate_text_for_storage($message, $uid, $bitfield, $flags, true);

                $data = array(
                    // General Posting Settings
                    'forum_id' => $forum_id,
                    // The forum ID in which the post will be placed. (int)
                    'topic_id' => 0,
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
                    'post_time' => 1,
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
                submit_post('post', $subject, $userdata['username'], POST_NORMAL, $poll, $data);

                return $response->withJson(['topic_id' => $data['topic_id']]);
            } else {
                throw new \phpBBJson\Exception\Unauthorized(
                    "You are not authorised to post a new topic in this forum."
                );
            }
        } else {
            throw new \phpBBJson\Exception\Unauthorized("You are not authorised to access this area.");
        }
    }

    /**
     * Create a new forum. You must be authenticated.
     * Data:
     * - secret (string) - The authentication code
     * - forum_id (integer)
     * - topic_title (string)
     * - topic_body (string)
     * @param \Slim\Http\Request $request
     * @param \Slim\Http\Response $response
     * @param string[] $args
     * @return \Slim\Http\Response
     * @throws \phpBBJson\Exception\InternalError
     * @throws \phpBBJson\Exception\Unauthorized
     * Result(JSON): topic_id - (integer) The ID of the newly created topic
     */
    public function newForum($request, $response, $args)
    {
        $params = $request->getQueryParams();
        $body = $request->getParsedBody();

        global $phpEx, $phpbb_root_path;
        include($phpbb_root_path . 'includes/functions_posting.' . $phpEx);
        include($phpbb_root_path . 'includes/functions_admin.' . $phpEx);
        include($phpbb_root_path . 'includes/message_parser.' . $phpEx);
        $db = $this->phpBB->get_db();
        $user = $this->phpBB->get_user();
        $auth = $this->phpBB->get_auth();
        $config = $this->phpBB->get_config();

        $lock = new \phpbb\lock\db('nestedset_forum_lock', $config, $db);
        $set = new \phpbb\tree\nestedset_forum($db, $lock, 'phpbb_forums');

        $secret = isset($params['secret']) && \phpBBJson\apiHelpers::verifySecret($params['secret']) ? $params['secret'] : null;
        $user_id = null;
        /*if ($secret != null) {
            $user_id  = \phpBBJson\getIdFromSecret($secret);
            $userdata = \phpBBJson\userdata($user_id);
            $auth->acl($userdata);
            $user->session_begin();
            $user->data = array_merge($user->data, $userdata);*/

        $parent_id = !empty($args['forumId']) ? $args['forumId'] : null;
        //if ($auth->acl_get('f_post', $parent_id)) {
        $uid = $bitfield = $flags = '';
        $name = $request->getParam('forum_name');
        //generate_text_for_storage($name, $uid, $bitfield, $flags, true);

        $forum = $set->insert(array('forum_name' => $name, 'forum_desc' => '', 'forum_rules' => '',
            'forum_type' => 1, 'forum_flags' => 96));
        $set->change_parent($forum['forum_id'], $parent_id);
        copy_forum_permissions($parent_id, $forum['forum_id'], false);
        return $response->withJson(['forum_id' => $forum['forum_id']]);
        /*} else {
            throw new \phpBBJson\Exception\Unauthorized(
                "You are not authorised to create a new forum."
            );
        }*/
        /*} else {
            throw new \phpBBJson\Exception\Unauthorized("You are not authorised to access this area.");
        }*/
    }

    /**
     * @return \Closure
     */
    public function constructRoutes()
    {
        $self = $this;
        return function () use ($self) {
            /** @var \Slim\App $this */
            $this->get('/{forumId}', [$self, 'info']);
            $this->get('/{forumId}/permissions', [$self, 'permissions']);
            $this->get('/{forumId}/topics', [$self, 'topicList']);
            $this->get('/{forumId}/topics/{page}', [$self, 'topicList']);
            $this->post('/{forumId}/topics', [$self, 'newTopic']);
            $this->post('', [$self, 'newForum']);
            $this->post('/{forumId}', [$self, 'newForum']);
        };
    }

    /**
     * @return string
     */
    public static function getGroup()
    {
        return '/forum';
    }
}