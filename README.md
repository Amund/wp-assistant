![GitHub Tag](https://img.shields.io/github/v/tag/Amund/wp-assistant)
![Static Badge](https://img.shields.io/badge/wordpress-plugin-blue)

# wp-assistant

**wp-assistant** is a WordPress plugin that add an AI assistant to helps visitors to find information on your website. It requires a Mistral API key to work, and it uses [Turso](https://turso.tech/) to store the embeddings. Turso is a SQLite database that requires [Foreign Function Interface](https://www.php.net/manual/en/book.ffi.php) php extension.

## Installation

1. Add repository to your `composer.json` file:
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