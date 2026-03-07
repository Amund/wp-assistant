<?php

/**
 * Plugin Name: Assistant
 * Description: Assistant for WordPress
 * Requires at least: 6.9
 * Requires PHP:      8.3
 * Author:            Dimitri Avenel
 * License:           MIT
 */

// composer require turso/libsql partitech/php-mistral league/html-to-markdown michelf/php-markdown

namespace amund\WP_Assistant;

if (!defined('ABSPATH')) {
    exit();
}

if (defined('DOING_AJAX') && DOING_AJAX && ! empty($_POST['action']) && ($_POST['action'] === 'heartbeat')) {
    return;
}

require_once __DIR__ . '/class/Assistant.php';
require_once __DIR__ . '/class/Back.php';
require_once __DIR__ . '/class/Chunker.php';
require_once __DIR__ . '/class/Cli.php';
require_once __DIR__ . '/class/Client.php';
require_once __DIR__ . '/class/Db.php';
require_once __DIR__ . '/class/Front.php';

add_action('plugins_loaded', function () {
    $plugin = new Plugin();

    $plugin->set('cli', fn() => new Cli($plugin));
    $plugin->set('assistant', fn() => new Assistant($plugin));
    $plugin->set('client', fn() => new Client($plugin));
    $plugin->set('back', fn() => new Back($plugin));
    $plugin->set('front', fn() => new Front($plugin));
    $plugin->set('db', function () {
        $path = WP_CONTENT_DIR . '/wp-assistant.db';
        if (!empty($_ENV['WP_ASSISTANT_DB_PATH'])) {
            $path = $_ENV['WP_ASSISTANT_DB_PATH'];
        }
        return new Db($path);
    });

    if (defined('WP_CLI') && WP_CLI) {
        \WP_CLI::add_command('assistant', $plugin->get('cli'));
    } else {
        $plugin->get('assistant');
        $plugin->get('front');
        if (is_admin()) {
            $plugin->get('back');
        }
    }
});

/*
TODO:
- Logs des questions/réponses dans une base turso séparée
*/

class Plugin
{
    private $bindings = [];

    public function set(string $id, callable $factory): void
    {
        $this->bindings[$id] = $factory;
    }

    public function get(string $id)
    {
        if (! isset($this->bindings[$id])) {
            throw new \Exception("Target binding [$id] does not exist.");
        }
        $factory = $this->bindings[$id];
        return $factory($this);
    }
}
