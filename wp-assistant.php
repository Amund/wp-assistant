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

if (!defined('ABSPATH')) {
    exit();
}

if (defined('DOING_AJAX') && DOING_AJAX && ! empty($_POST['action']) && ($_POST['action'] === 'heartbeat')) {
    return;
}

require_once __DIR__ . '/class/WP_Assistant.php';
require_once __DIR__ . '/class/WP_Assistant_Client.php';
require_once __DIR__ . '/class/WP_Assistant_Back.php';
require_once __DIR__ . '/class/WP_Assistant_Front.php';

define('WP_ASSISTANT_DB_PATH', WP_CONTENT_DIR . '/../../assistant.db');

add_action('plugins_loaded', [WP_Assistant_Front::class, 'init']);

if (is_admin()) {
    add_action('plugins_loaded', [WP_Assistant_Back::class, 'init']);
}

/*
TODO:
- Batch de génération des descriptions manquantes en base MySQL
- Gestion des fournisseurs/modèles (embed, chat), clé d'api - Wordpress AI SDK
- Affichage conditionnel du bouton de génération de description
- Gestion des tokens, quotas fournisseurs ?
*/
