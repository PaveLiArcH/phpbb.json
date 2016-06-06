<?php
/**
 * The forum module handles actions related to individual forums.
 * @package phpbb.json
 * @license http://opensource.org/licenses/gpl-license.php GNU Public License
 * @author  Florin Pavel
 */
namespace phpBBJson\Modules;

use phpBBJson\Exception\BadFormat;
use phpBBJson\Exception\NotFound;
use phpBBJson\Exception\Unauthorized;

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
     * @throws \phpBBJson\Exception\NotFound
     */
    public function info($request, $response, $args)
    {
        $db = $this->phpBB->get_db();
        $forumId = !empty($args['forumId']) ? intval($args['forumId']) : null;
        if (!$forumId) {
            throw new NotFound("Unable to collect info on empty forum id.");
        }
        $sql = "SELECT SUM(t.topic_posts_approved) AS replies, f.forum_posts_approved, f.forum_topics_approved FROM "
            . TOPICS_TABLE . " t, " . FORUMS_TABLE . " f WHERE f.forum_id = {$forumId} AND t.forum_id = f.forum_id";
        $result = $db->sql_query($sql);
        $row = $db->sql_fetchrow($result);
        if (!$row) {
            throw new NotFound("The forum you selected does not exist.");
        }
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
     * @throws \phpBBJson\Exception\NotFound
     */
    public function permissions($request, $response, $args)
    {
        $auth = $this->phpBB->get_auth();
        $forumId = !empty($args['forumId']) ? intval($args['forumId']) : null;

        if (!$forumId) {
            throw new NotFound("Unable to get permissions on empty forum id");
        }

        $permissions = [
            'can_see' => ($auth->acl_get('f_list', $forumId)) ? true : false,
            'can_read' => ($auth->acl_get('f_read', $forumId)) ? true : false,
            'can_post' => ($auth->acl_get('f_post', $forumId)) ? true : false,
            'can_reply' => ($auth->acl_get('f_reply', $forumId)) ? true : false
        ];

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
     * @throws \phpBBJson\Exception\NotFound
     * @throws \phpBBJson\Exception\Unauthorized
     */
    public function topicList($request, $response, $args)
    {
        $params = $request->getQueryParams();

        $db = $this->phpBB->get_db();
        $user = $this->phpBB->get_user();
        $user_id = $user->data['user_id'];
        $auth = $this->phpBB->get_auth();
        $config = $this->phpBB->get_config();
        $results = array();

        $forumId = !empty($args['forumId']) ? intval($args['forumId']) : null;

        if (!$forumId) {
            throw new NotFound("The forum you selected does not exist.");
        }

        $sql_from = FORUMS_TABLE . ' f';
        $lastread_select = '';

        // Grab appropriate forum data
        if ($config['load_db_lastread'] && $user_id != ANONYMOUS) {
            $sql_from .= ' LEFT JOIN ' . FORUMS_TRACK_TABLE . ' ft ON (ft.user_id = ' . $user_id . '
		AND ft.forum_id = f.forum_id)';
            $lastread_select .= ', ft.mark_time';
        }

        if ($user_id != ANONYMOUS) {
            $sql_from .= ' LEFT JOIN ' . FORUMS_WATCH_TABLE . ' fw ON (fw.forum_id = f.forum_id AND fw.user_id = ' . $user_id . ')';
            $lastread_select .= ', fw.notify_status';
        }

        $sql = "SELECT f.* $lastread_select
	FROM $sql_from
	WHERE f.forum_id = $forumId";
        $result = $db->sql_query($sql);
        $forum_data = $db->sql_fetchrow($result);
        $db->sql_freeresult($result);

        if (!$forum_data) {
            throw new NotFound("The forum you selected does not exist.");
        }

        // Permissions check
        if (!$auth->acl_gets('f_list', 'f_read', $forumId) || ($forum_data['forum_type'] == FORUM_LINK &&
                $forum_data['forum_link'] && !$auth->acl_get('f_read', $forumId))
        ) {
            if ($user_id != 1) {
                throw new Unauthorized("You are not authorised to read this forum.");
            }
            throw new Unauthorized(
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

        if ($user_id != ANONYMOUS) {
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
                'WHERE' => 'parent_id = ' . $forumId
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

        if ($user_id != ANONYMOUS) {
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

        $sql_approved = ($auth->acl_get('m_approve', $forumId)) ? '' : 'AND t.topic_approved = 1';

        $sql_where = "t.forum_id = " . $forumId;
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
        global $phpbb_root_path;
        include_once($phpbb_root_path . 'includes/functions_posting.php');
        include_once($phpbb_root_path . 'includes/message_parser.php');

        $user = $this->phpBB->get_user();
        $user_id = $user->data['user_id'];
        $auth = $this->phpBB->get_auth();

        $forum_id = !empty($args['forumId']) ? intval($args['forumId']) : null;

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
     * @throws BadFormat
     * @throws Unauthorized
     * Result(JSON): topic_id - (integer) The ID of the newly created forum
     */
    public function newForum($request, $response, $args)
    {
        global $phpbb_root_path;
        include_once($phpbb_root_path . 'includes/functions_posting.php');
        include_once($phpbb_root_path . 'includes/functions_admin.php');
        include_once($phpbb_root_path . 'includes/functions_acp.php');
        include_once($phpbb_root_path . 'includes/message_parser.php');
        include_once($phpbb_root_path . 'includes/acp/acp_forums.php');

        $db = $this->phpBB->get_db();
        $auth = $this->phpBB->get_auth();
        $cache = $this->phpBB->get_cache();

        if (!$auth->acl_get('a_forumadd')) {
            throw new Unauthorized("You are not authorised to create a new forum.");
        }

        $parent_id = !empty($args['parentForumId']) ? intval($args['parentForumId']) : 0;

        $forum_data = [
            'parent_id' => $parent_id ? $parent_id : $request->getParam('forum_parent_id', 0),
            'forum_type' => $request->getParam('forum_type', FORUM_POST),
            'type_action' => $request->getParam('type_action', ''),
            'forum_status' => $request->getParam('forum_status', ITEM_UNLOCKED),
            'forum_parents' => '',
            'forum_name' => utf8_normalize_nfc($request->getParam('forum_name', '')),
            'forum_link' => $request->getParam('forum_link', ''),
            'forum_link_track' => $request->getParam('forum_link_track', false),
            'forum_desc' => utf8_normalize_nfc($request->getParam('forum_desc', '')),
            'forum_desc_uid' => '',
            'forum_desc_options' => 7,
            'forum_desc_bitfield' => '',
            'forum_rules' => utf8_normalize_nfc($request->getParam('forum_rules', '')),
            'forum_rules_uid' => '',
            'forum_rules_options' => 7,
            'forum_rules_bitfield' => '',
            'forum_rules_link' => $request->getParam('forum_rules_link', ''),
            'forum_image' => $request->getParam('forum_image', ''),
            'forum_style' => $request->getParam('forum_style', 0),
            'display_subforum_list' => $request->getParam('display_subforum_list', false),
            'display_on_index' => $request->getParam('display_on_index', false),
            'forum_topics_per_page' => $request->getParam('topics_per_page', 0),
            'enable_indexing' => $request->getParam('enable_indexing', true),
            'enable_icons' => $request->getParam('enable_icons', false),
            'enable_prune' => $request->getParam('enable_prune', false),
            'enable_post_review' => $request->getParam('enable_post_review', true),
            'enable_quick_reply' => $request->getParam('enable_quick_reply', true),
            'enable_shadow_prune' => $request->getParam('enable_shadow_prune', false),
            'prune_days' => $request->getParam('prune_days', 7),
            'prune_viewed' => $request->getParam('prune_viewed', 7),
            'prune_freq' => $request->getParam('prune_freq', 1),
            'prune_old_polls' => $request->getParam('prune_old_polls', false),
            'prune_announce' => $request->getParam('prune_announce', false),
            'prune_sticky' => $request->getParam('prune_sticky', false),
            'prune_shadow_days' => $request->getParam('prune_shadow_days', 7),
            'prune_shadow_freq' => $request->getParam('prune_shadow_freq', 1),
            'forum_password' => utf8_normalize_nfc($request->getParam('forum_password', '')),
            'forum_password_confirm' => utf8_normalize_nfc($request->getParam('forum_password_confirm', '')),
            'forum_password_unset' => $request->getParam('forum_password_unset', false),
        ];

        // Use link_display_on_index setting if forum type is link
        if ($forum_data['forum_type'] == FORUM_LINK) {
            $forum_data['display_on_index'] = $request->getParam('link_display_on_index', false);
        }

        // Linked forums and categories are not able to be locked...
        if ($forum_data['forum_type'] == FORUM_LINK || $forum_data['forum_type'] == FORUM_CAT) {
            $forum_data['forum_status'] = ITEM_UNLOCKED;
        }

        $forum_data['show_active'] = ($forum_data['forum_type'] == FORUM_POST) ? $request->getParam('display_recent', true) : $request->getParam('display_active', false);

        // Get data for forum rules if specified...
        if ($forum_data['forum_rules']) {
            generate_text_for_storage($forum_data['forum_rules'], $forum_data['forum_rules_uid'],
                $forum_data['forum_rules_bitfield'], $forum_data['forum_rules_options'],
                $request->getParam('rules_parse_bbcode', true), $request->getParam('rules_parse_urls', true),
                $request->getParam('rules_parse_smilies', true));
        }

        // Get data for forum description if specified
        if ($forum_data['forum_desc']) {
            generate_text_for_storage($forum_data['forum_desc'], $forum_data['forum_desc_uid'],
                $forum_data['forum_desc_bitfield'], $forum_data['forum_desc_options'],
                $request->getParam('desc_parse_bbcode', true), $request->getParam('desc_parse_urls', true),
                $request->getParam('desc_parse_smilies', true));
        }

        $acp_forums = new \acp_forums();
        $acp_forums->parent_id = $parent_id;

        $errors = $acp_forums->update_forum_data($forum_data);

        if (count($errors)) {
            throw new BadFormat("Following errors occured: " . implode(',', $errors));
        }

        $forum_perm_from = $request->getParam('forum_perm_from', $parent_id);

        $cache->destroy('sql', FORUMS_TABLE);

        if ($forum_perm_from && $forum_perm_from != $forum_data['forum_id']) {
            copy_forum_permissions($forum_perm_from, $forum_data['forum_id'], false);
            phpbb_cache_moderators($db, $cache, $auth);
        }

        $auth->acl_clear_prefetch();

        unset($forum_data['forum_password']);
        unset($forum_data['forum_password_confirm']);
        unset($forum_data['forum_password_unset']);

        return $response->withJson($forum_data);
    }

    /**
     * Modify existing forum data. You must be authenticated.
     * Data:
     * - secret (string) - The authentication code
     * - forum_id (integer)
     * - topic_title (string)
     * - topic_body (string)
     * @param \Slim\Http\Request $request
     * @param \Slim\Http\Response $response
     * @param string[] $args
     * @return \Slim\Http\Response
     * @throws BadFormat
     * @throws Unauthorized
     * Result(JSON): topic_id - (integer) The ID of the modified topic
     */
    public function modifyForum($request, $response, $args)
    {
        global $phpbb_root_path;
        include_once($phpbb_root_path . 'includes/functions_posting.php');
        include_once($phpbb_root_path . 'includes/functions_admin.php');
        include_once($phpbb_root_path . 'includes/functions_acp.php');
        include_once($phpbb_root_path . 'includes/message_parser.php');
        include_once($phpbb_root_path . 'includes/acp/acp_forums.php');

        $db = $this->phpBB->get_db();
        $auth = $this->phpBB->get_auth();
        $cache = $this->phpBB->get_cache();

        if (!$auth->acl_get('a_forumadd')) {
            throw new Unauthorized("You are not authorised to create a new forum.");
        }

        $forum_id = !empty($args['forumId']) ? intval($args['forumId']) : 0;

        $acp_forums = new \acp_forums();

        $forum_data = $acp_forums->get_forum_info($forum_id);

        $forum_data['forum_password_confirm'] = $forum_data['forum_password'];

        $forum_rules_data = array(
            'text' => $forum_data['forum_rules'],
            'allow_bbcode' => true,
            'allow_smilies' => true,
            'allow_urls' => true
        );

        $forum_desc_data = array(
            'text' => $forum_data['forum_desc'],
            'allow_bbcode' => true,
            'allow_smilies' => true,
            'allow_urls' => true
        );
        if ($forum_data['forum_rules']) {
            if (!isset($forum_data['forum_rules_uid'])) {
                // Before we are able to display the preview and plane text, we need to parse our $request->variable()'d value...
                $forum_data['forum_rules_uid'] = '';
                $forum_data['forum_rules_bitfield'] = '';
                $forum_data['forum_rules_options'] = 0;

                generate_text_for_storage($forum_data['forum_rules'], $forum_data['forum_rules_uid'], $forum_data['forum_rules_bitfield'], $forum_data['forum_rules_options'], $request->getParam('rules_allow_bbcode', true), $request->getParam('rules_allow_urls', true), $request->getParam('rules_allow_smilies', true));
            }

            // Generate preview content
            $forum_rules_preview = generate_text_for_display($forum_data['forum_rules'], $forum_data['forum_rules_uid'], $forum_data['forum_rules_bitfield'], $forum_data['forum_rules_options']);

            // decode...
            $forum_rules_data = generate_text_for_edit($forum_data['forum_rules'], $forum_data['forum_rules_uid'], $forum_data['forum_rules_options']);
        }
        // Parse desciption if specified
        if ($forum_data['forum_desc']) {
            if (!isset($forum_data['forum_desc_uid'])) {
                // Before we are able to display the preview and plane text, we need to parse our $request->variable()'d value...
                $forum_data['forum_desc_uid'] = '';
                $forum_data['forum_desc_bitfield'] = '';
                $forum_data['forum_desc_options'] = 0;

                generate_text_for_storage($forum_data['forum_desc'], $forum_data['forum_desc_uid'], $forum_data['forum_desc_bitfield'], $forum_data['forum_desc_options'], $request->getParam('desc_allow_bbcode', true), $request->getParam('desc_allow_urls', true), $request->getParam('desc_allow_smilies', true));
            }

            // decode...
            $forum_desc_data = generate_text_for_edit($forum_data['forum_desc'], $forum_data['forum_desc_uid'], $forum_data['forum_desc_options']);
        }


        $forum_data = [
            'forum_id' => $forum_id,
            'parent_id' => $request->getParam('forum_parent_id', $forum_data['parent_id']),
            'forum_type' => $request->getParam('forum_type', $forum_data['forum_type']),
            'type_action' => $request->getParam('type_action', $forum_data['type_action']),
            'forum_status' => $request->getParam('forum_status', $forum_data['forum_status']),
            'forum_parents' => '',
            'forum_name' => utf8_normalize_nfc($request->getParam('forum_name', $forum_data['forum_name'])),
            'forum_link' => $request->getParam('forum_link', $forum_data['forum_link']),
            'forum_link_track' => $request->getParam('forum_link_track', $forum_data['forum_flags'] & FORUM_FLAG_LINK_TRACK),
            'forum_desc' => utf8_normalize_nfc($request->getParam('forum_desc', $forum_desc_data['text'])),
            'forum_rules' => utf8_normalize_nfc($request->getParam('forum_rules', $forum_rules_data['text'])),
            'forum_rules_link' => $request->getParam('forum_rules_link', $forum_data['forum_rules_link']),
            'forum_image' => $request->getParam('forum_image', $forum_data['forum_image']),
            'forum_style' => $request->getParam('forum_style', $forum_data['forum_style']),
            'display_subforum_list' => $request->getParam('display_subforum_list', $forum_data['display_subforum_list']),
            'display_on_index' => $request->getParam('display_on_index', $forum_data['display_on_index']),
            'forum_topics_per_page' => $request->getParam('topics_per_page', $forum_data['forum_topics_per_page']),
            'enable_indexing' => $request->getParam('enable_indexing', $forum_data['enable_indexing']),
            'enable_icons' => $request->getParam('enable_icons', $forum_data['enable_icons']),
            'enable_prune' => $request->getParam('enable_prune', $forum_data['enable_prune']),
            'enable_post_review' => $request->getParam('enable_post_review', $forum_data['forum_flags'] & FORUM_FLAG_POST_REVIEW),
            'enable_quick_reply' => $request->getParam('enable_quick_reply', $forum_data['forum_flags'] & FORUM_FLAG_QUICK_REPLY),
            'enable_shadow_prune' => $request->getParam('enable_shadow_prune', $forum_data['enable_shadow_prune']),
            'prune_days' => $request->getParam('prune_days', $forum_data['prune_days']),
            'prune_viewed' => $request->getParam('prune_viewed', $forum_data['prune_viewed']),
            'prune_freq' => $request->getParam('prune_freq', $forum_data['prune_freq']),
            'prune_old_polls' => $request->getParam('prune_old_polls', $forum_data['forum_flags'] & FORUM_FLAG_PRUNE_POLL),
            'prune_announce' => $request->getParam('prune_announce', $forum_data['forum_flags'] & FORUM_FLAG_PRUNE_ANNOUNCE),
            'prune_sticky' => $request->getParam('prune_sticky', $forum_data['forum_flags'] & FORUM_FLAG_PRUNE_STICKY),
            'prune_shadow_days' => $request->getParam('prune_shadow_days', $forum_data['prune_shadow_days']),
            'prune_shadow_freq' => $request->getParam('prune_shadow_freq', $forum_data['prune_shadow_freq']),
            'forum_password' => utf8_normalize_nfc($request->getParam('forum_password', $forum_data['forum_password'])),
            'forum_password_confirm' => utf8_normalize_nfc($request->getParam('forum_password_confirm', $forum_data['forum_password'])),
            'forum_password_unset' => $request->getParam('forum_password_unset', !empty($forum_data['forum_password'])),
        ];

        // Use link_display_on_index setting if forum type is link
        if ($forum_data['forum_type'] == FORUM_LINK) {
            $forum_data['display_on_index'] = $request->getParam('link_display_on_index', $forum_data['display_on_index']);
        }

        // Linked forums and categories are not able to be locked...
        if ($forum_data['forum_type'] == FORUM_LINK || $forum_data['forum_type'] == FORUM_CAT) {
            $forum_data['forum_status'] = ITEM_UNLOCKED;
        }

        $forum_data['show_active'] = ($forum_data['forum_type'] == FORUM_POST)
            ? $request->getParam('display_recent', ($forum_data['forum_flags'] & FORUM_FLAG_ACTIVE_TOPICS))
            : $request->getParam('display_active', ($forum_data['forum_flags'] & FORUM_FLAG_ACTIVE_TOPICS));

        // Get data for forum rules if specified...
        if ($forum_data['forum_rules']) {
            generate_text_for_storage($forum_data['forum_rules'], $forum_data['forum_rules_uid'],
                $forum_data['forum_rules_bitfield'], $forum_data['forum_rules_options'],
                $request->getParam('rules_parse_bbcode', $forum_rules_data['allow_bbcode']),
                $request->getParam('rules_parse_urls', $forum_rules_data['allow_urls']),
                $request->getParam('rules_parse_smilies', $forum_rules_data['allow_smilies']));
        }

        // Get data for forum description if specified
        if ($forum_data['forum_desc']) {
            generate_text_for_storage($forum_data['forum_desc'], $forum_data['forum_desc_uid'],
                $forum_data['forum_desc_bitfield'], $forum_data['forum_desc_options'],
                $request->getParam('desc_parse_bbcode', $forum_desc_data['allow_bbcode']),
                $request->getParam('desc_parse_urls', $forum_desc_data['allow_urls']),
                $request->getParam('desc_parse_smilies', $forum_desc_data['allow_smilies']));
        }

        $acp_forums = new \acp_forums();
        $acp_forums->parent_id = $forum_data['parent_id'];

        $errors = $acp_forums->update_forum_data($forum_data);

        if (count($errors)) {
            throw new BadFormat("Following errors occured: " . implode(',', $errors));
        }

        $forum_perm_from = $request->getParam('forum_perm_from', 0);

        $cache->destroy('sql', FORUMS_TABLE);

        if ($forum_perm_from && $forum_perm_from != $forum_data['forum_id']) {
            copy_forum_permissions($forum_perm_from, $forum_data['forum_id'], true);
            phpbb_cache_moderators($db, $cache, $auth);
        }

        $auth->acl_clear_prefetch();

        unset($forum_data['forum_password']);
        unset($forum_data['forum_password_confirm']);
        unset($forum_data['forum_password_unset']);

        return $response->withJson($forum_data);
    }

    /**
     * Removes a forum. You must be authenticated.
     * Data:
     * - secret (string) - The authentication code
     * - forum_id (integer)
     * - topic_title (string)
     * - topic_body (string)
     * @param \Slim\Http\Request $request
     * @param \Slim\Http\Response $response
     * @param string[] $args
     * @return \Slim\Http\Response
     * @throws BadFormat
     * @throws Unauthorized
     * Result(JSON): topic_id - (integer) The ID of the newly created forum
     */
    public function removeForum($request, $response, $args)
    {
        global $phpbb_root_path;
        include_once($phpbb_root_path . 'includes/functions_posting.php');
        include_once($phpbb_root_path . 'includes/functions_admin.php');
        include_once($phpbb_root_path . 'includes/functions_acp.php');
        include_once($phpbb_root_path . 'includes/message_parser.php');
        include_once($phpbb_root_path . 'includes/acp/acp_forums.php');

        $auth = $this->phpBB->get_auth();
        $cache = $this->phpBB->get_cache();

        if (!$auth->acl_get('a_forumdel')) {
            throw new Unauthorized("You are not authorised to delete a forum.");
        }

        $forum_id = !empty($args['forumId']) ? intval($args['forumId']) : null;

        $action_subforums = $request->getParam('action_subforums', '');
        $subforums_to_id = $request->getParam('subforums_to_id', 0);
        $action_posts = $request->getParam('action_posts', '');
        $posts_to_id = $request->getParam('posts_to_id', 0);

        $acp_forums = new \acp_forums();

        $errors = $acp_forums->delete_forum($forum_id, $action_posts, $action_subforums, $posts_to_id, $subforums_to_id);

        if (sizeof($errors)) {
            throw new BadFormat("Following errors occured: " . implode(',', $errors));
        }

        $auth->acl_clear_prefetch();
        $cache->destroy('sql', FORUMS_TABLE);

        return $response->withJson(['removed_forum_id' => $forum_id]);
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
            $this->post('/{parentForumId}', [$self, 'newForum']);
            $this->put('/{forumId}', [$self, 'modifyForum']);
            $this->delete('/{forumId}', [$self, 'removeForum']);
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