<?php

/**
 * Handles actions related to individual topics.
 * @package phpbb.json
 * @license http://opensource.org/licenses/gpl-license.php GNU Public License
 * @author  Florin Pavel
 */

namespace phpBBJson\Modules;

use parse_message;
use phpBBJson\Exception\InternalError;
use phpBBJson\Exception\NotFound;
use phpBBJson\Exception\Unauthorized;

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
        $topic_id = !empty($args['topicId']) ? intval($args['topicId']) : null;
        if (!$topic_id) {
            throw new InternalError("The topic you selected does not exist.");
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
     * @throws \phpBBJson\Exception\NotFound
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

        $topic_id = !empty($args['topicId']) ? intval($args['topicId']) : null;
        if (!$topic_id) {
            throw new NotFound("The topic you selected does not exist.");
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

        $user->setup('viewtopic');

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

        while ($row = $db->sql_fetchrow($query)) {
            // Allow all combinations
            $options = 7;
            if ($row['enable_bbcode'] !== null && $row['enable_smilies'] !== null && $row['enable_magic_url'] !== null) {
                $options = ($row['enable_bbcode'] ? OPTION_FLAG_BBCODE : 0) + ($row['enable_smilies'] ? OPTION_FLAG_SMILIES : 0) + ($row['enable_smilies'] ? OPTION_FLAG_LINKS : 0);
            }
            $phpbb_avatar_manager = $phpbb_container->get('avatar.manager');
            $driver = $phpbb_avatar_manager->get_driver($row['avatar_type'], true);
            if ($driver) {
                $avatar_data = $driver->get_data($row);
            } else {
                $avatar_data['src'] = '';
            }
            $results['posts'][] = array(
                'post_id' => $row['post_id'],
                'author_id' => $row['user_id'],
                'author_username' => $row['username'],
                'author_avatar' => $avatar_data,
                'timestamp' => $row['post_time'],
                'post_text' => censor_text(
                    $phpbb_feed_helper->generate_content(
                        $row['post_text'],
                        $row['bbcode_uid'],
                        $row['bbcode_bitfield'],
                        $options,
                        $forum_id,
                        []
                    )
                ),
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
     * @throws \phpBBJson\Exception\NotFound
     */
    public function permissions($request, $response, $args)
    {
        $db = $this->phpBB->get_db();
        $auth = $this->phpBB->get_auth();

        $topic_id = !empty($args['topicId']) ? intval($args['topicId']) : null;
        if (!$topic_id) {
            throw new NotFound("The topic you selected does not exist.");
        }

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
     * @throws \phpBBJson\Exception\NotFound
     * @throws \phpBBJson\Exception\Unauthorized
     */
    public function reply($request, $response, $args)
    {
        global $phpbb_root_path;
        include($phpbb_root_path . 'includes/functions_posting.php');
        include($phpbb_root_path . 'includes/message_parser.php');

        $db = $this->phpBB->get_db();
        $user = $this->phpBB->get_user();
        $auth = $this->phpBB->get_auth();
        $config = $this->phpBB->get_config();

        $topic_id = !empty($args['topicId']) ? $args['topicId'] : null;
        if ($topic_id == null) {
            throw new NotFound("The topic you selected does not exist.");
        }
        $obj = $db->sql_fetchrow(
            $db->sql_query("SELECT forum_id, topic_title FROM " . TOPICS_TABLE . " WHERE topic_id = " . $topic_id)
        );
        $forum_id = $obj['forum_id'];

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
                'topic_first_poster_colour' => $user->data['user_colour']
            );

            $poll = array();
            $result = submit_post('reply', $subject, $user->data['username'], POST_NORMAL, $poll, $data);
            preg_match('/p=\d(.*?)\b/', $result, $matches);
            $post_id = explode('=', $matches[0]);
            $post_id = $post_id[1];

            return $response->withJson(['post_id' => $post_id]);
        } else {
            throw new Unauthorized("You do not have necessary permissions to post in this topic!");
        }
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
        global $phpbb_root_path;
        include_once($phpbb_root_path . 'includes/functions_posting.php');
        include_once($phpbb_root_path . 'includes/message_parser.php');

        $user = $this->phpBB->get_user();
        $user_id = $user->data['user_id'];
        $auth = $this->phpBB->get_auth();

        $forum_id = !empty($args['forumId']) ? $args['forumId'] : null;

        if ($user_id != ANONYMOUS) {
            if ($auth->acl_get('f_post', $forum_id)) {
                $uid = $bitfield = $flags = '';
                $message = $request->getParam('topic_body');
                $subject = $request->getParam('topic_title');
                generate_text_for_storage($message, $uid, $bitfield, $flags, true);

                $data = [
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
                    'topic_first_poster_colour' => $user->data['user_colour']
                ];
                $poll = array();
                submit_post('post', $subject, $user->data['username'], POST_NORMAL, $poll, $data);

                return $response->withJson(['topic_id' => $data['topic_id']]);
            } else {
                throw new Unauthorized(
                    "You are not authorised to post a new topic in this forum."
                );
            }
        } else {
            throw new Unauthorized("You are not authorised to access this area.");
        }
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
     * @throws InternalError
     * @throws NotFound
     * @throws Unauthorized
     * Result(JSON): topic_id - (integer) The ID of the newly created topic
     */
    public function updateTopic($request, $response, $args)
    {
        global $phpbb_root_path, $phpbb_container;
        include_once($phpbb_root_path . 'includes/functions_posting.php');
        include_once($phpbb_root_path . 'includes/message_parser.php');

        $db = $this->phpBB->get_db();
        $user = $this->phpBB->get_user();
        $auth = $this->phpBB->get_auth();
        $config = $this->phpBB->get_config();

        $user_id = $user->data['user_id'];

        $topic_id = !empty($args['topicId']) ? intval($args['topicId']) : null;

        if (!$topic_id) {
            throw new NotFound("The topic you selected does not exist.");
        }

        // Force forum id
        $sql = 'SELECT forum_id, topic_first_post_id FROM ' . TOPICS_TABLE . ' WHERE topic_id = ' . $topic_id;
        $result = $db->sql_query($sql);
        $row = $db->sql_fetchrow();
        $forum_id = intval($row['forum_id']);
        $post_id = intval($row['topic_first_post_id']);
        $db->sql_freeresult($result);

        $sql = 'SELECT f.*, t.*, p.*, u.username, u.username_clean, u.user_sig, u.user_sig_bbcode_uid, u.user_sig_bbcode_bitfield
          FROM ' . POSTS_TABLE . ' p, ' . TOPICS_TABLE . ' t, ' . FORUMS_TABLE . ' f, ' . USERS_TABLE . " u
          WHERE p.post_id = $post_id
            AND t.topic_id = p.topic_id
            AND u.user_id = p.poster_id
            AND f.forum_id = t.forum_id";

        $result = $db->sql_query($sql);
        $post_data = $db->sql_fetchrow($result);
        $db->sql_freeresult($result);

        if (!$post_data) {
            throw new InternalError("Unable to modify topic.");
        }
        if (!$auth->acl_get('f_read', $forum_id)) {
            throw new Unauthorized("Unable to access topic.");
        }
        if (!$auth->acl_gets('f_edit', 'm_edit', $forum_id)) {
            throw new Unauthorized("You need extra permissions to modify topic.");
        }
        // Forum/Topic locked?
        if (($post_data['forum_status'] == ITEM_LOCKED || (isset($post_data['topic_status']) && $post_data['topic_status'] == ITEM_LOCKED)) && !$auth->acl_get('m_edit', $forum_id)) {
            throw new Unauthorized(($post_data['forum_status'] == ITEM_LOCKED) ? "Forum is locked." : "Topic is locked.");
        }

        // Can we edit this post ... if we're a moderator with rights then always yes
        // else it depends on editing times, lock status and if we're the correct user
        if (!$auth->acl_get('m_edit', $forum_id)) {
            $s_cannot_edit = $user->data['user_id'] != $post_data['poster_id'];
            $s_cannot_edit_time = $config['edit_time'] && $post_data['post_time'] <= time() - ($config['edit_time'] * 60);
            $s_cannot_edit_locked = $post_data['post_edit_locked'];

            if ($s_cannot_edit) {
                throw new Unauthorized('Edit disallowed for this user');
            } else if ($s_cannot_edit_time) {
                throw new Unauthorized('Post edition time passed');
            } else if ($s_cannot_edit_locked) {
                throw new Unauthorized('Post is locked and cannot be modified');
            }
        }

        $post_data['post_edit_locked'] = isset($post_data['post_edit_locked']) ? (int)$post_data['post_edit_locked'] : 0;
        $post_data['post_subject_md5'] = isset($post_data['post_subject']) ? md5($post_data['post_subject']) : '';
        $post_data['topic_time_limit'] = isset($post_data['topic_time_limit'])
            ? (($post_data['topic_time_limit'])
                ? (int)$post_data['topic_time_limit'] / 86400
                : (int)$post_data['topic_time_limit'])
            : 0;
        $post_data['poll_length'] = (!empty($post_data['poll_length'])) ? (int)$post_data['poll_length'] / 86400 : 0;
        $post_data['poll_start'] = (!empty($post_data['poll_start'])) ? (int)$post_data['poll_start'] : 0;
        $post_data['icon_id'] = !isset($post_data['icon_id']) ? 0 : (int)$post_data['icon_id'];
        $post_data['poll_options'] = array();

        // Get Poll Data
        if ($post_data['poll_start']) {
            $sql = 'SELECT poll_option_text FROM ' . POLL_OPTIONS_TABLE . " WHERE topic_id = $topic_id ORDER BY poll_option_id";
            $result = $db->sql_query($sql);

            while ($row = $db->sql_fetchrow($result)) {
                $post_data['poll_options'][] = trim($row['poll_option_text']);
            }
            $db->sql_freeresult($result);
        }

        $message_parser = new parse_message();
        /* @var $plupload \phpbb\plupload\plupload */
        $plupload = $phpbb_container->get('plupload');

        $message_parser->set_plupload($plupload);

        if (isset($post_data['post_text'])) {
            $message_parser->message = &$post_data['post_text'];
            unset($post_data['post_text']);
        }

        // Do we want to edit our post ?
        if ($post_data['bbcode_uid']) {
            $message_parser->bbcode_uid = $post_data['bbcode_uid'];
        }

        $message_parser->message = utf8_normalize_nfc($request->getParam('topic_body', $message_parser->message));

        // HTML, BBCode, Smilies, Images and Flash status
        $bbcode_status = ($config['allow_bbcode'] && $auth->acl_get('f_bbcode', $forum_id)) ? true : false;
        $smilies_status = ($config['allow_smilies'] && $auth->acl_get('f_smilies', $forum_id)) ? true : false;
        $img_status = ($bbcode_status && $auth->acl_get('f_img', $forum_id)) ? true : false;
        $url_status = ($config['allow_post_links']) ? true : false;
        $flash_status = ($bbcode_status && $auth->acl_get('f_flash', $forum_id) && $config['allow_post_flash']) ? true : false;
        $quote_status = true;

        $message_md5 = md5($message_parser->message);
        $message_parser->parse($post_data['enable_bbcode'],
            ($config['allow_post_links']) ? $post_data['enable_urls'] : false, $post_data['enable_smilies'],
            $img_status, $flash_status, $quote_status, $config['allow_post_links']);

        $post_data['bbcode_bitfield'] = $message_parser->bbcode_bitfield;
        $post_data['bbcode_uid'] = $message_parser->bbcode_uid;
        $post_data['message'] = $message_parser->message;

        $post_data['poll_last_vote'] = (isset($post_data['poll_last_vote'])) ? $post_data['poll_last_vote'] : 0;

        $poll = array(
            'poll_title' => $post_data['poll_title'],
            'poll_length' => $post_data['poll_length'],
            'poll_max_options' => $post_data['poll_max_options'],
            'poll_option_text' => $post_data['poll_option_text'],
            'poll_start' => $post_data['poll_start'],
            'poll_last_vote' => $post_data['poll_last_vote'],
            'poll_vote_change' => $post_data['poll_vote_change'],
            'enable_bbcode' => $post_data['enable_bbcode'],
            'enable_urls' => $post_data['enable_urls'],
            'enable_smilies' => $post_data['enable_smilies'],
            'img_status' => $img_status
        );

        $data = array(
            'topic_title' => $request->getParam('topic_title', (empty($post_data['topic_title'])) ? $post_data['post_subject'] : $post_data['topic_title']),
            'topic_first_post_id' => (isset($post_data['topic_first_post_id'])) ? (int)$post_data['topic_first_post_id'] : 0,
            'topic_last_post_id' => (isset($post_data['topic_last_post_id'])) ? (int)$post_data['topic_last_post_id'] : 0,
            'topic_time_limit' => (int)$post_data['topic_time_limit'],
            'topic_attachment' => (isset($post_data['topic_attachment'])) ? (int)$post_data['topic_attachment'] : 0,
            'post_id' => (int)$post_id,
            'topic_id' => (int)$topic_id,
            'forum_id' => (int)$forum_id,
            'icon_id' => (int)$post_data['icon_id'],
            'poster_id' => (int)$post_data['poster_id'],
            'enable_sig' => (bool)$post_data['enable_sig'],
            'enable_bbcode' => (bool)$post_data['enable_bbcode'],
            'enable_smilies' => (bool)$post_data['enable_smilies'],
            'enable_urls' => (bool)$post_data['enable_urls'],
            'enable_indexing' => (bool)$post_data['enable_indexing'],
            'message_md5' => (string)$message_md5,
            'post_checksum' => (isset($post_data['post_checksum'])) ? (string)$post_data['post_checksum'] : '',
            'post_edit_reason' => $post_data['post_edit_reason'],
            'post_edit_user' => $user->data['user_id'],
            'forum_parents' => $post_data['forum_parents'],
            'forum_name' => $post_data['forum_name'],
            'notify' => false,
            'notify_set' => $post_data['notify_set'],
            'poster_ip' => (isset($post_data['poster_ip'])) ? $post_data['poster_ip'] : $user->ip,
            'post_edit_locked' => (int)$post_data['post_edit_locked'],
            'bbcode_bitfield' => $message_parser->bbcode_bitfield,
            'bbcode_uid' => $message_parser->bbcode_uid,
            'message' => $message_parser->message,
            'attachment_data' => $message_parser->attachment_data,
            'filename_data' => $message_parser->filename_data,
            'topic_status' => $post_data['topic_status'],

            'topic_visibility' => (isset($post_data['topic_visibility'])) ? $post_data['topic_visibility'] : false,
            'post_visibility' => (isset($post_data['post_visibility'])) ? $post_data['post_visibility'] : false,
        );

        submit_post('edit', $request->getParam('topic_title', $post_data['post_subject']), $post_data['username'], $post_data['topic_type'], $poll, $data, true, true);
        return $response->withJson(['topic_id' => $data['topic_id']]);
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
    public function moveTopic($request, $response, $args)
    {
        global $phpbb_root_path;
        include_once($phpbb_root_path . 'includes/functions_admin.php');

        $user = $this->phpBB->get_user();
        $user_id = $user->data['user_id'];
        $auth = $this->phpBB->get_auth();

        $forum_id = !empty($args['forumId']) ? intval($args['forumId']) : null;
        $topic_id = !empty($args['topicId']) ? intval($args['topicId']) : null;

        if ($user_id != ANONYMOUS) {
            if ($auth->acl_get('f_post', $forum_id)) {
                move_topics([$topic_id], $forum_id);
                return $response->withJson(['topic_id' => $topic_id]);
            } else {
                throw new Unauthorized(
                    "You are not authorised to post a new topic in this forum."
                );
            }
        } else {
            throw new Unauthorized("You are not authorised to access this area.");
        }
    }

    /**
     * Removes a topic. You must be authenticated.
     * Data:
     * - secret (string) - The authentication code
     * - forum_id (integer)
     * - topic_title (string)
     * - topic_body (string)
     * @param \Slim\Http\Request $request
     * @param \Slim\Http\Response $response
     * @param string[] $args
     * @return \Slim\Http\Response
     * @throws InternalError
     * @throws Unauthorized
     * Result(JSON): topic_id - (integer) The ID of the newly created forum
     */
    public function removeTopic($request, $response, $args)
    {
        global $phpbb_root_path;
        //include_once($phpbb_root_path . 'includes/functions_posting.php');
        //include_once($phpbb_root_path . 'includes/functions_admin.php');
        //include_once($phpbb_root_path . 'includes/functions_acp.php');
        include_once($phpbb_root_path . 'includes/functions_mcp.php');
        //include_once($phpbb_root_path . 'includes/acp/acp_forums.php');

        $user = $this->phpBB->get_user();
        $auth = $this->phpBB->get_auth();
        $cache = $this->phpBB->get_cache();

        $topic_id = !empty($args['topicId']) ? intval($args['topicId']) : null;

        $topic_ids = [$topic_id];

        $data = phpbb_get_topic_data($topic_ids);

        if (count($data) != 1) {
            throw new InternalError("Incorrect number of removing topics: " . count($data));
        }

        $row = reset($data);
        $topic_id = key($data);

        if (!$auth->acl_get('a_forumdel', $row['forum_id']) && !$auth->acl_get('m_softdelete', $row['forum_id'])) {
            throw new Unauthorized("You are not authorised to delete this topic.");
        }

        $phpbb_container = $this->phpBB->get_container();
        /* @var $phpbb_content_visibility \phpbb\content_visibility */
        $phpbb_content_visibility = $phpbb_container->get('content.visibility');
        $return = $phpbb_content_visibility->set_topic_visibility(ITEM_DELETED, $topic_id, $row['forum_id'], $user->data['user_id'], time(), "deleted by forum api");

        return $response->withJson($return);
    }

    /**
     * @return \Closure
     */
    public function constructRoutes()
    {
        $self = $this;
        return function () use ($self) {
            /** @var \Slim\App $this */
            $this->get('/{topicId}/permissions', [$self, 'permissions']);
            $this->get('/{topicId}/posts/{page}', [$self, 'postList']);
            $this->get('/{topicId}/posts', [$self, 'postList']);
            $this->get('/{topicId}/moveTo/{forumId}', [$self, 'moveTopic']); //move_topics functions_admins.php
            $this->get('/{topicId}', [$self, 'info']);
            $this->post('/{topicId}/posts', [$self, 'reply']);
            $this->post('/{forumId}', [$self, 'newTopic']);
            $this->put('/{topicId}', [$self, 'updateTopic']);
            $this->delete('/{topicId}', [$self, 'removeTopic']);
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
