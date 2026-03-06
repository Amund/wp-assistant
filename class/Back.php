<?php

namespace amund\WP_Assistant;

class Back
{
    public $plugin;

    public function __construct($plugin)
    {
        $this->plugin = $plugin;

        // WP_Assistant_Client::check_api_key();
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function admin_menu()
    {
        add_options_page('Assistant', 'Assistant', 'manage_options', 'wp_assistant', [$this, 'options_page']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'apd_settings_link');
        function apd_settings_link(array $links)
        {
            $url = get_admin_url() . "options-general.php?page=my-plugin";
            $settings_link = '<a href="' . $url . '">' . __('Settings', 'textdomain') . '</a>';
            $links[] = $settings_link;
            return $links;
        }
    }

    public function register_settings()
    {
        register_setting('wp_assistant_options', 'wp_assistant_answer_prompt');
    }

    public function options_page()
    {
        $assistant = $this->plugin->get('assistant');
        $client = $this->plugin->get('client');

        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'general';

        $icons = [
            'ok' => '<i class="dashicons dashicons-yes" style="color: green;"></i>',
            'ko' => '<i class="dashicons dashicons-no-alt" style="color: red;"></i>',
            'debug' => '<i class="dashicons dashicons-admin-tools"></i>',
        ];
        $posts = $assistant->get_posts();
        foreach ($posts as $i => $post) {
            $outdated = $post['outdated'] ? $icons['ko'] : $icons['ok'];
            $posts[$i]['link'] = strtr(
                '<tr><td>[type]</td><td>[lang]</td><td><a href="[url]" target="_blank">[title]</a></td><td>[outdated]</td><td>[debug]</td></tr>',
                [
                    '[url]' => $post['url'],
                    '[title]' => $post['title'],
                    '[type]' => $post['post_type'],
                    '[lang]' => $post['lang'],
                    '[outdated]' => $outdated,
                    '[debug]' => $icons['debug'],
                ],
            );
        }

        $answer_prompt = $client::get_answer_prompt();
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
                            <th scope="row">
                                Prompt de réponse
                                <p class="description">Le prompt utilisé pour générer les réponses. Vous pouvez utiliser les variables suivantes, qui seront remplacées par leur véritable valeur lors de l'exécution du prompt :
                                    <br>[LANG] Langue actuelle
                                    <br>[CONTENT] Contenu des pages présélectionnées
                                    <br>[QUERY] Question de l'utilisateur
                                </p>
                            </th>
                            <td>
                                <textarea name="wp_assistant_answer_prompt" rows="15" cols="50"><?php echo esc_textarea($answer_prompt); ?></textarea>
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
                            <th>Lang</th>
                            <th>Contenu</th>
                            <th>Index</th>
                            <th>Debug</th>
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

                <pre>
                    <?php
                    // Fonction utilitaire pour afficher les chunks avec leurs tailles
                    function displayChunks(array $chunks, $id): void
                    {
                        echo "\n##############################\n";
                        echo "[$id] Nombre de chunks : " . count($chunks) . "\n\n";
                        foreach ($chunks as $i => $chunk) {
                            echo "=== Chunk " . ($i + 1) . " (" . mb_strlen($chunk) . " caractères) ===\n";
                            echo $chunk . "\n\n";
                        }
                    }

                    foreach ([68, 65, 73, 63, 72, 71, 70, 66, 67, 69] as $id) {
                        // foreach ([63] as $id) {
                        $text = $assistant->text($id);
                        // var_dump("$text");

                        try {
                            $chunks = Chunker::chunk($text, 1000, 0);
                            displayChunks($chunks, $id);
                        } catch (\Exception $e) {
                            echo "Erreur : " . $e->getMessage();
                        }
                    }
                    ?>
                </pre>

            <?php endif; ?>
        </div>
        <style>
            .wp-assistant {
                .general {
                    textarea {
                        width: 100%;
                        resize: vertical;
                        min-height: 50vh;
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

    /*    public function index_all_ajax()
    {
        check_ajax_referer('wp_assistant_index_all', 'security');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized user');
        }


        wp_die();
    }*/
}
