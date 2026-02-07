<?php

class WP_Assistant_Back
{
    static function init()
    {
        WP_Assistant_Client::check_api_key();
        add_action('admin_menu', [self::class, 'admin_menu']);
        add_action('admin_init', [self::class, 'register_settings']);
        add_action('add_meta_boxes', [self::class, 'add_teaser']);
        add_action('save_post', [self::class, 'save_teaser'], 9);
        add_action('wp_ajax_wp_assistant_generate_teaser', [self::class, 'generate_teaser']);
        add_action('wp_ajax_wp_assistant_index_all', [self::class, 'index_all_ajax']);
    }

    static function admin_menu()
    {
        add_options_page('Assistant', 'Assistant', 'manage_options', 'wp_assistant', [self::class, 'options_page']);
    }

    static function register_settings()
    {
        register_setting('wp_assistant_options', 'wp_assistant_teaser_prompt');
        register_setting('wp_assistant_options', 'wp_assistant_answer_prompt');
    }

    static function options_page()
    {
        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'general';

        $icons = [
            'ok' => '<i class="dashicons dashicons-yes" style="color: green;"></i>',
            'ko' => '<i class="dashicons dashicons-no-alt" style="color: red;"></i>',
        ];
        $posts = WP_Assistant::get_posts();
        foreach ($posts as $i => $post) {
            $wp_teaser = $post['teaser'];
            $db_teaser = WP_Assistant::db_get_teaser($post['id']);
            $icon = $wp_teaser == $db_teaser ? $icons['ok'] : $icons['ko'];
            $posts[$i]['link'] = strtr(
                '<tr><td>[type]</td><td><a href="[url]" target="_blank">[title]</a></td><td>[teaser]</td><td>[icon]</td></tr>',
                [
                    '[url]' => $posts[$i]['url'],
                    '[title]' => $posts[$i]['title'],
                    '[type]' => $posts[$i]['post_type'],
                    '[teaser]' => $posts[$i]['teaser'],
                    '[icon]' => $icon,
                ],
            );
        }

        $teaser_prompt = WP_Assistant::get_teaser_prompt();
        $answer_prompt = WP_Assistant::get_answer_prompt();
?>
        <div class="wrap wp-assistant">
            <h1>WP Assistant</h1>

            <h2 class="nav-tab-wrapper">
                <a href="?page=wp_assistant&tab=general" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>">Général</a>
                <a href="?page=wp_assistant&tab=contents" class="nav-tab <?php echo $active_tab == 'contents' ? 'nav-tab-active' : ''; ?>">Contenus</a>
                <a href="?page=wp_assistant&tab=test" class="nav-tab <?php echo $active_tab == 'test' ? 'nav-tab-active' : ''; ?>">Test</a>
            </h2>

            <?php if ($active_tab == 'general') : ?>

                <!-- <button id="wp-assistant-index-all" class="button button-primary">Tout indexer</button> -->
                <!-- <div id="wp-assistant-results"></div> -->
                <form method="post" action="options.php">
                    <?php settings_fields('wp_assistant_options'); ?>
                    <table class="form-table general">
                        <tr valign="top">
                            <th scope="row">Prompt système: Description de page</th>
                            <td>
                                <textarea name="wp_assistant_teaser_prompt" rows="5" cols="50"><?php echo esc_textarea($teaser_prompt); ?></textarea>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Prompt système: Générateur de réponse</th>
                            <td>
                                <textarea name="wp_assistant_answer_prompt" rows="5" cols="50"><?php echo esc_textarea($answer_prompt); ?></textarea>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button(); ?>
                </form>

            <?php elseif ($active_tab == 'contents') : ?>

                <table class="posts">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Contenu</th>
                            <th>Description</th>
                            <th>Index</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($posts as $p) echo $p['link']; ?>
                    </tbody>
                </table>

            <?php elseif ($active_tab == 'test') : ?>

                <div class="test">
                    <?php do_action('wp_assistant_form') ?>
                </div>

            <?php endif; ?>
        </div>
        <style>
            .wp-assistant {
                .general {
                    textarea {
                        width: 100%;
                        resize: vertical;
                        min-height: 8em;
                    }
                }

                .posts {
                    border-collapse: collapse;
                    margin-block: 2em;
                    width: 100%;

                    th,
                    td {
                        padding: 0.25em 0.5em;
                        border: 1px solid #c3c4c7;
                        vertical-align: top;
                    }
                }

                .test {
                    margin-block: 2em;
                }

                .form-table td:last-child {
                    padding: 15px 0 15px 10px;
                }

                p.submit {
                    text-align: right;
                }
            }
        </style>
        <script>
            jQuery(document).ready(function($) {
                $('#wp-assistant-index-all').on('click', function() {
                    var data = {
                        'action': 'wp_assistant_index_all',
                        'security': '<?= wp_create_nonce("wp_assistant_index_all") ?>'
                    };

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: data,
                        beforeSend: function() {
                            $('#wp-assistant-index-all').prop('disabled', true);
                            $('#wp-assistant-results').html('<p>Indexation en cours...</p>');
                        },
                        success: function(response) {
                            $('#wp-assistant-index-all').prop('disabled', false);
                            $('#wp-assistant-results').html(response);
                        },
                        error: function(xhr, status, error) {
                            $('#wp-assistant-index-all').prop('disabled', false);
                            $('#wp-assistant-results').html('<p>Error: ' + error + '</p>');
                        }
                    });
                });
            });
        </script>
    <?php
    }

    static function index_all_ajax()
    {
        check_ajax_referer('wp_assistant_index_all', 'security');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized user');
        }


        wp_die();
    }

    static function add_teaser()
    {
        add_meta_box(
            'wp_assistant_teaser',
            'Assistant',
            [self::class, 'display_teaser'],
            WP_Assistant::get_post_types(),
            'side',
            'high'
        );
    }

    static function display_teaser($post)
    {
        $teaser_value = get_post_meta($post->ID, '_wp_assistant_teaser', true);
    ?>
        <textarea name="wp_assistant_teaser" style="width:100%;min-height:250px;resize:vertical;"><?= esc_textarea($teaser_value) ?></textarea>
        <button id="generate-teaser" class="components-button is-compact is-tertiary">Génération automatique...</button>
        <div id="generate-teaser-results"></div>
        <script>
            jQuery(document).ready(function($) {
                $('#generate-teaser').on('click', function() {
                    var data = {
                        action: 'wp_assistant_generate_teaser',
                        post_id: <?= $post->ID ?>,
                        security: '<?= wp_create_nonce('wp_assistant_generate_teaser') ?>',
                    }

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: data,
                        beforeSend: function() {
                            $('#generate-teaser').prop('disabled', true)
                            $('#generate-teaser-results').html('<p>Génération en cours...</p>')
                        },
                        success: function(response) {
                            $('#generate-teaser').prop('disabled', false)
                            $('[name="wp_assistant_teaser"]').val(response)
                            $("#generate-teaser-results").html("")
                        },
                        error: function(xhr, status, error) {
                            $('#generate-teaser').prop('disabled', false)
                            $('#generate-teaser-results').html('<p>Erreur: ' + error + '</p>')
                        }
                    })
                })
            })
        </script>
<?php
    }

    static function save_teaser($post_id)
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if (isset($_POST['wp_assistant_teaser'])) {
            $value = stripslashes(sanitize_text_field($_POST['wp_assistant_teaser']));
            $old_value = get_post_meta($post_id, '_wp_assistant_teaser', true);
            $db_teaser = WP_Assistant::db_get_teaser($post_id);
            if ($value != $old_value || $value != $db_teaser) {
                WP_Assistant::db_update_post($post_id, $value);
            }
        }
    }

    static function generate_teaser()
    {
        check_ajax_referer('wp_assistant_generate_teaser', 'security');

        if (!current_user_can('edit_post', $_POST['post_id'])) {
            wp_die('Unauthorized user');
        }

        $post_id = intval($_POST['post_id']);
        if ($post_id) {
            $text = WP_Assistant::text_content($post_id);
            if (!empty($text)) {
                $value = WP_Assistant_Client::generate_teaser($text);
                $value = sanitize_text_field($value);
                $old_value = get_post_meta($post_id, '_wp_assistant_teaser', true);
                if ($value != $old_value) {
                    WP_Assistant::db_update_post($post_id, $value);
                }
            }
            echo $value;
        }

        wp_die();
    }
}
