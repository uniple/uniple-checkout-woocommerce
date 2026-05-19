<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap (= pure function unit tests のみ、 WP / WC 環境不要)。
 *
 * UnipleClient::toIntegerJpyc, UnipleClient::verifySignature, UserAgent::build,
 * SettingsSanitizer は WP 依存最小化のため stub を提供。 残り (= Gateway /
 * Webhook controller 等) は WP_Mock / Brain Monkey を別途導入予定。
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

defined('ABSPATH') || define('ABSPATH', __DIR__.'/wp/');

require_once __DIR__.'/stubs/wp-stubs.php';
require_once __DIR__.'/../src/Plugin.php';
require_once __DIR__.'/../src/Api/UserAgent.php';
require_once __DIR__.'/../src/Api/UnipleClient.php';
require_once __DIR__.'/../src/Admin/SettingsSanitizer.php';
