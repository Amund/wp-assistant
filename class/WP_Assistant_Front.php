<?php

use Michelf\Markdown;

class WP_Assistant_Front
{
    public static function init()
    {
        add_action('wp_ajax_wp_assistant_answer', [self::class, 'answer']);
        add_action('wp_ajax_nopriv_wp_assistant_answer', [self::class, 'answer']);
        add_action('wp_assistant_form', [self::class, 'form']);
    }

    public static function form()
    {
?>
        <div id="wp-assistant-question">
            <input type="text" placeholder="Comment puis-je vous aider ?" value="">
            <button class="button">Ok</button>
            <div></div>
        </div>
        <style>
            #wp-assistant-question {
                display: grid;
                grid-template-columns: auto min-content;
                grid-template-areas: 'input button' 'output output';

                input {
                    grid-area: input;
                    width: 100%;
                }

                button {
                    grid-area: button;
                    margin-bottom: 0;
                }

                div {
                    grid-area: output;

                    &:empty {
                        display: none;
                    }
                }
            }
        </style>
        <script>
            jQuery(document).ready(function($) {
                $('#wp-assistant-question button').on('click', function() {
                    const question = $('#wp-assistant-question input').val()
                    if (question.trim() === '') return

                    var data = {
                        action: 'wp_assistant_answer',
                        question: question,
                        security: '<?= wp_create_nonce('wp_assistant_answer') ?>',
                    }

                    $.ajax({
                        url: '<?= admin_url('admin-ajax.php') ?>',
                        type: 'POST',
                        data: data,
                        beforeSend: function() {
                            $('#wp-assistant-question button').prop('disabled', true)
                            $('#wp-assistant-question div').html('<p>Chargement...</p>')
                        },
                        success: function(response) {
                            $('#wp-assistant-question button').prop('disabled', false)
                            if (response.success) {
                                $('#wp-assistant-question div').html('<p>' + response.data + '</p>')
                            } else {
                                $('#wp-assistant-question div').html('<p>Error: ' + response.data + '</p>')
                            }
                        },
                        error: function(xhr, status, error) {
                            $('#wp-assistant-question button').prop('disabled', false);
                            $('#wp-assistant-question div').html('<p>Error ' + xhr.status + ' ' + error + '</p>')
                        }
                    })
                })
                $('#wp-assistant-question input').on('keypress', function(e) {
                    if (e.which === 13) {
                        $('#wp-assistant-question button').click();
                    }
                })
            });
        </script>
<?php
    }

    public static function answer()
    {
        check_ajax_referer('wp_assistant_answer', 'security');

        if (!isset($_POST['question'])) {
            wp_send_json_error('Aucune demande');
        }

        $question = sanitize_text_field($_POST['question']);
        $response = WP_Assistant::rag_answer($question);

        if ($response === NULL) {
            wp_send_json_error('response_error');
        }

        if (is_array($response)) {
            error_log(print_r($response, true));
            wp_send_json_error('response_error');
        }

        $response = preg_replace('#^```(?:json)?\s*#', '', $response);
        $response = preg_replace('#\s*```$#', '', $response);

        $json = json_decode($response, true);
        if ($json === NULL) {
            error_log(var_export($response, true));
            wp_send_json_error('json_error');
        }

        $message = Markdown::defaultTransform($json['message']);

        $post_ids = $json['post_ids'];
        if (is_array($post_ids) && !empty($post_ids)) {
            $posts = '<ul>';
            foreach ($post_ids as $post_id) {
                $post_html = '<a href="' . get_permalink($post_id) . '">' . get_the_title($post_id) . '</a>';
                $post_html = apply_filters('wp_assistant_answer_item', $post_html, $post_id);
                $posts .= '<li>' . $post_html . '</li>';
            }
            $posts .= '</ul>';
        } else {
            $posts = '';
        }

        wp_send_json_success($message . $posts);
    }
}

// add_filter('wp_assistant_answer_item', function ($post_html, $post_id) {
//     return '<a class="item" href="' . get_permalink($post_id) . '">' . get_the_title($post_id) . '</a>';
// }, 10, 2);
