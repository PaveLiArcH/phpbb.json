<?php
/**
 * @package phpbb.json
 * @license http://opensource.org/licenses/gpl-license.php GNU Public License
 */

/**
 * We suppose, that this table is available:
 * CREATE TABLE `ruranobe_forum`.`phpbb_api_secret` (
 * `user_id` MEDIUMINT(8) NOT NULL COMMENT '',
 * `secret` VARCHAR(255) NOT NULL COMMENT '',
 * PRIMARY KEY (`user_id`, `secret`)  COMMENT '');
 */

require_once './vendor/autoload.php';
require_once '../vendor/autoload.php';

use phpBBJson\Modules\Authentication;
use phpBBJson\Modules\Board;
use phpBBJson\Modules\Forum;
use phpBBJson\Modules\Service;
use phpBBJson\Modules\Topic;
use phpBBJson\Modules\User;
use Slim\Http\Uri;
use Symfony\Bridge\PsrHttpMessage\Factory\DiactorosFactory;

// Set the gears in motion
include('bootstrap.php');

/** @var \phpbb\symfony_request $symfony_request */

// Create and configure Slim app
$app = new \Slim\App(
	[
		'settings' => [
			'displayErrorDetails' => true,
		],
		'request' => \phpBBJson\apiHelpers::adaptRequest((new DiactorosFactory())->createRequest($symfony_request)->withUri(
			new Uri(
				$symfony_request->getScheme(),
				$symfony_request->getHost(),
				$symfony_request->getPort(),
				$symfony_request->getPathInfo(),
				$symfony_request->getQueryString()
			)
		))
	]
);

// Define app routes
$app->group(Board::getGroup(), (new Board($phpbb))->constructRoutes());
$app->group(Forum::getGroup(), (new Forum($phpbb))->constructRoutes());
$app->group(Topic::getGroup(), (new Topic($phpbb))->constructRoutes());
$app->group(Authentication::getGroup(), (new Authentication($phpbb))->constructRoutes());
$app->group(Service::getGroup(), (new Service($phpbb))->constructRoutes());
$app->group(User::getGroup(), (new User($phpbb))->constructRoutes());

// Run app
$app->run();