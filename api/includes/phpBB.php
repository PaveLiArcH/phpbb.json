<?php
/**
 * A container class for some phpBB functionality. This allows us to pass
 * around phpBB "instances" and not pollute the global namespace.
 *
 * @package phpbb.json
 * @license http://opensource.org/licenses/gpl-license.php GNU Public License
 * @author  Phil Crumm pcrumm@p3net.net
 */

namespace phpBBJson;

class phpBB
{
    private $db;
    private $auth;
    private $user;
    private $config;
    private $container;

    /**
     * @param \phpbb\db\driver\factory $db
     */
    public function set_db(\phpbb\db\driver\factory $db)
    {
        $this->db = $db;
    }

    /**
     * @return \phpbb\db\driver\factory
     */
    public function get_db()
    {
        return $this->db;
    }

    /**
     * @param \phpbb\auth\auth $auth
     */
    public function set_auth(\phpbb\auth\auth $auth)
    {
        $this->auth = $auth;
    }

    /**
     * @return \phpbb\auth\auth
     */
    public function get_auth()
    {
        return $this->auth;
    }

    /**
     * @param \phpbb\user $user
     */
    public function set_user(\phpbb\user $user)
    {
        $this->user = $user;
    }

    /**
     * @return \phpbb\user
     */
    public function get_user()
    {
        return $this->user;
    }

    public function set_config($config)
    {
        $this->config = $config;
    }

    public function get_config()
    {
        return $this->config;
    }

    /**
     * @return \Symfony\Component\DependencyInjection\ContainerBuilder
     */
    public function get_container()
    {
        return $this->container;
    }

    /**
     * @param \Symfony\Component\DependencyInjection\ContainerBuilder $container
     */
    public function set_container($container)
    {
        $this->container = $container;
    }
}