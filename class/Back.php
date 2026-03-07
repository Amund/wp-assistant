<?php

namespace amund\WP_Assistant;

class Back
{
    public $plugin;

    public function __construct($plugin)
    {
        $this->plugin = $plugin;

        Client::check_api_key();
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_ajax_wp_assistant_index_all', [$this, 'index_all_ajax']);
        add_action('wp_ajax_wp_assistant_get_posts', [$this, 'get_posts_ajax']);
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
                <?php
                $available_languages = $assistant->get_available_languages();
                $available_post_types = $assistant->get_available_post_types();
                $get_posts_nonce = wp_create_nonce('wp_assistant_get_posts');
                ?>
                <div class="filters">
                    <label for="filter-post-type">Type de contenu:</label>
                    <select id="filter-post-type">
                        <option value="">Tous</option>
                        <?php foreach ($available_post_types as $slug => $label) : ?>
                            <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label for="filter-lang">Langue:</label>
                    <select id="filter-lang">
                        <option value="">Toutes</option>
                        <?php foreach ($available_languages as $code => $name) : ?>
                            <option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($name); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label for="filter-outdated">État indexation:</label>
                    <select id="filter-outdated">
                        <option value="">Tous</option>
                        <option value="false">À jour</option>
                        <option value="true">Dépassé</option>
                    </select>

                    <button id="filter-apply" class="button button-secondary">Filtrer</button>
                    <button id="filter-reset" class="button button-link">Réinitialiser</button>
                </div>

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
                    <tbody id="posts-container">
                        <!-- Posts will be loaded via AJAX -->
                    </tbody>
                </table>

                <div id="pagination"></div>

                <button id="wp-assistant-index-all" class="button button-primary">Tout indexer</button>
                <div id="wp-assistant-results"></div>

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
                        min-height: 50vh;
                    }
                }

                .filters {
                    margin: 2em 0;
                    display: flex;
                    flex-wrap: wrap;
                    align-items: center;
                    gap: 1em;
                }

                .filters label {
                    font-weight: bold;
                }

                .filters select,
                .filters button {
                    height: 32px;
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

                #pagination {
                    margin: 1em 0;
                    text-align: center;
                }

                #pagination button {
                    margin: 0 2px;
                }

                #pagination .current-page {
                    display: inline-block;
                    margin: 0 10px;
                    font-weight: bold;
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
                var currentPage = 1;
                var filters = {
                    post_type: '',
                    lang: '',
                    outdated: ''
                };

                // Load posts function
                function loadPosts(page) {
                    var data = {
                        action: 'wp_assistant_get_posts',
                        security: '<?php echo $get_posts_nonce; ?>',
                        page: page,
                        per_page: 50,
                        post_type: filters.post_type,
                        lang: filters.lang,
                        outdated: filters.outdated
                    };

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: data,
                        beforeSend: function() {
                            $('#posts-container').html('<tr><td colspan="5">Chargement...</td></tr>');
                        },
                        success: function(response) {
                            if (response.success) {
                                $('#posts-container').html(response.data.rows.join(''));
                                updatePagination(response.data);
                            } else {
                                $('#posts-container').html('<tr><td colspan="5">Erreur: ' + response.data + '</td></tr>');
                            }
                        },
                        error: function(xhr, status, error) {
                            $('#posts-container').html('<tr><td colspan="5">Erreur: ' + error + '</td></tr>');
                        }
                    });
                }

                // Update pagination
                function updatePagination(data) {
                    var pagination = $('#pagination');
                    pagination.empty();
                    if (data.total_pages <= 1) return;

                    // Previous button
                    if (data.current_page > 1) {
                        $('<button>').text('« Précédent').addClass('button').on('click', function() {
                            loadPosts(data.current_page - 1);
                        }).appendTo(pagination);
                    }

                    // Page indicator
                    $('<span>').addClass('current-page').text('Page ' + data.current_page + ' sur ' + data.total_pages).appendTo(pagination);

                    // Next button
                    if (data.current_page < data.total_pages) {
                        $('<button>').text('Suivant »').addClass('button').on('click', function() {
                            loadPosts(data.current_page + 1);
                        }).appendTo(pagination);
                    }
                }

                // Apply filters
                $('#filter-apply').on('click', function() {
                    filters.post_type = $('#filter-post-type').val();
                    filters.lang = $('#filter-lang').val();
                    filters.outdated = $('#filter-outdated').val();
                    currentPage = 1;
                    loadPosts(currentPage);
                });

                // Reset filters
                $('#filter-reset').on('click', function() {
                    $('#filter-post-type, #filter-lang, #filter-outdated').val('');
                    filters.post_type = '';
                    filters.lang = '';
                    filters.outdated = '';
                    currentPage = 1;
                    loadPosts(currentPage);
                });

                // Load initial posts
                loadPosts(currentPage);

                // Keep existing index all functionality
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
                            // Reload posts after indexing
                            loadPosts(currentPage);
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

    public function index_all_ajax()
    {
        check_ajax_referer('wp_assistant_index_all', 'security');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized user');
        }

        $assistant = $this->plugin->get('assistant');
        $posts = $assistant->get_posts();

        $total = count($posts);
        $success = 0;
        $failed = 0;
        $skipped = 0;

        $report = [];

        foreach ($posts as $post) {
            if (!$post['outdated']) {
                $skipped++;
                $report[] = sprintf('Post #%d "%s" (%s) : déjà à jour', $post['id'], $post['title'], $post['lang']);
                continue;
            }

            $result = $assistant->index_post($post['id']);
            if ($result) {
                $success++;
                $report[] = sprintf('Post #%d "%s" (%s) : indexé avec succès', $post['id'], $post['title'], $post['lang']);
            } else {
                $failed++;
                $report[] = sprintf('Post #%d "%s" (%s) : échec de l\'indexation', $post['id'], $post['title'], $post['lang']);
            }
        }

        $html = '<div class="notice notice-info"><p>Indexation terminée.</p></div>';
        $html .= '<ul>';
        foreach ($report as $line) {
            $html .= '<li>' . esc_html($line) . '</li>';
        }
        $html .= '</ul>';
        $html .= sprintf('<p><strong>Résumé :</strong> %d succès, %d échecs, %d ignorés (déjà à jour).</p>', $success, $failed, $skipped);

        wp_die($html);
    }

    /**
     * Handle AJAX request for filtered posts.
     */
    public function get_posts_ajax()
    {
        check_ajax_referer('wp_assistant_get_posts', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized user');
        }

        $assistant = $this->plugin->get('assistant');

        $args = [
            'post_type' => !empty($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : '',
            'lang' => !empty($_POST['lang']) ? sanitize_text_field($_POST['lang']) : '',
            'outdated' => isset($_POST['outdated']) ? ($_POST['outdated'] === 'true' ? true : ($_POST['outdated'] === 'false' ? false : null)) : null,
            'page' => !empty($_POST['page']) ? max(1, (int) $_POST['page']) : 1,
            'per_page' => !empty($_POST['per_page']) ? max(1, (int) $_POST['per_page']) : 50,
        ];

        $result = $assistant->get_filtered_posts($args);

        // Generate HTML rows
        $icons = [
            'ok' => '<i class="dashicons dashicons-yes" style="color: green;"></i>',
            'ko' => '<i class="dashicons dashicons-no-alt" style="color: red;"></i>',
            'debug' => '<i class="dashicons dashicons-admin-tools"></i>',
        ];
        $rows = [];
        foreach ($result['posts'] as $post) {
            $outdated_icon = $post['outdated'] ? $icons['ko'] : $icons['ok'];
            $rows[] = strtr(
                '<tr><td>[type]</td><td>[lang]</td><td><a href="[url]" target="_blank">[title]</a></td><td>[outdated]</td><td>[debug]</td></tr>',
                [
                    '[url]' => esc_url($post['url']),
                    '[title]' => esc_html($post['title']),
                    '[type]' => esc_html($post['post_type']),
                    '[lang]' => esc_html($post['lang']),
                    '[outdated]' => $outdated_icon,
                    '[debug]' => $icons['debug'],
                ]
            );
        }

        wp_send_json_success([
            'rows' => $rows,
            'total_posts' => $result['total_posts'],
            'total_pages' => $result['total_pages'],
            'current_page' => $result['current_page'],
        ]);
    }
}
