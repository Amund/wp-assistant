![GitHub Tag](https://img.shields.io/github/v/tag/Amund/wp-assistant)
![Static Badge](https://img.shields.io/badge/wordpress-plugin-blue)

# wp-assistant

**wp-assistant** is a WordPress plugin that add an AI assistant to helps visitors to find information on your website. It requires a Mistral API key to work, and it uses [Turso](https://turso.tech/) to store the embeddings. Turso is a SQLite database that requires [Foreign Function Interface](https://www.php.net/manual/en/book.ffi.php) php extension.

## Installation

1. Add repository to your wordpress `composer.json` file:
```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/Amund/wp-assistant.git"
        }
    ]
}
```

2. Run the following command to install the plugin:
```sh
composer require amund/wp-assistant
```

3. Activate the plugin in WordPress

## Usage

Add assistant's form to your theme :

```php
do_action('wp_assistant_form');
// or
echo do_shortcode('[wp_assistant_form]');
```

The form placeholder and button texts are updatable with filters: `wp_assistant_placeholder` and `wp_assistant_button`.
```php
add_filter('wp_assistant_placeholder', fn() => 'What can I do for you ?');
add_filter('wp_assistant_button', fn() => 'Give me answer !');
```

By default, responses are a list of simple links wrapped in `<ul>` and `li` tags</ul>. Those links can be overrided using a filter `wp_assistant_answer_item`, to add classes, attributes, thumbnails, ...

```php
add_filter('wp_assistant_answer_item', function ($post_html, $post_id) {
    return '<a class="my-answer-item" href="' . get_permalink($post_id) . '">' . get_the_title($post_id) . '</a>';
}, 10, 2);
```
