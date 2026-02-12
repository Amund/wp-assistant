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

// if (!defined('WP_ASSISTANT_DB_PATH')) throw new Exception('WP_ASSISTANT_DB_PATH is not defined');


// require_once __DIR__ . '/class/Assistant.php';
// require_once __DIR__ . '/class/Back.php';
// require_once __DIR__ . '/class/Chunker.php';
require_once __DIR__ . '/class/Cli.php';
// require_once __DIR__ . '/class/Client.php';
require_once __DIR__ . '/class/Db.php';
// require_once __DIR__ . '/class/Front.php';

define('WP_ASSISTANT_DB_PATH', WP_CONTENT_DIR . '/../../assistant.db');

// add_action('plugins_loaded', [Front::class, 'init']);

if (is_admin()) {
    // add_action('plugins_loaded', [Back::class, 'init']);
}

if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('assistant', 'amund\\WP_Assistant\\Cli');
}

/*
TODO:
- Batch de génération des descriptions manquantes en base MySQL
- Gestion des fournisseurs/modèles (embed, chat), clé d'api - Wordpress AI SDK
- Affichage conditionnel du bouton de génération de description
- Gestion des tokens, quotas fournisseurs ?
*/
