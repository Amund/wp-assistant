<?php

namespace amund\WP_Assistant;

use Exception;

/**
 * WP Assistant CLI commands.
 */
class Cli
{
    private $plugin;

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * Launch all tests, all tests of a specific class, or just one test.
     *
     * ## OPTIONS
     *
     * [<class>]
     * : The test class name.
     * 
     * [<method>]
     * : The test method name.
     *
     */
    public function test($args)
    {
        try {
            if (version_compare(PHP_VERSION, '7.4.0', '<')) {
                throw new Exception('PHP 7.4 or higher is required');
            }

            $path = __DIR__ . '/../test/';
            $results = [];

            switch (count($args)) {
                case 0:
                    $files = glob($path . '*.php');
                    $classes = array_map(function ($file) {
                        return basename($file, '.php');
                    }, $files);
                    foreach ($classes as $class) {
                        $results = [
                            ...$results,
                            ...$this->testClass($path, $class),
                        ];
                    }
                    break;
                case 1:
                    [$class] = $args;
                    $results = [
                        ...$this->testClass($path, $class),
                    ];
                    break;
                case 2:
                    [$class, $method] = $args;
                    $results = [
                        ...$this->testMethod($path, $class, $method),
                    ];
                    break;
            }

            $stats['[total]'] = count($results);
            $stats['[passed]'] = count(array_filter($results, function ($r) {
                return $r === true;
            }));
            $errors = array_filter($results, function ($r) {
                return $r !== true;
            });
            $stats['[errors]'] = count($errors);
            $stats = strtr('Total: [total] - Passed: [passed] - Errors: [errors]', $stats);

            if (!empty($errors)) {
                if (isset($method)) {
                    // single method => message + trace
                    $keys = array_keys($errors);
                    $e = $errors[$keys[0]];
                    \WP_CLI::error((string) $e);
                } else {
                    // multiple methods => only message
                    \WP_CLI::line();
                    $keys = array_keys($errors);
                    foreach ($keys as $key) {
                        $e = $errors[$key];
                        \WP_CLI::line($key . ' : ' . $e->getMessage());
                    }
                    \WP_CLI::line();
                    \WP_CLI::error($stats);
                }
            } else {
                \WP_CLI::success($stats);
            }
        } catch (\Throwable $e) {
            \WP_CLI::error($e->__toString());
        }
    }

    private function testClass($path, $class)
    {
        if (!file_exists($path . $class . '.php')) {
            throw new \Exception("File $path$class.php not found");
        }
        require_once $path . $class . '.php';
        if (!class_exists(__NAMESPACE__ . '\\Test\\' . $class)) {
            throw new \Exception("Class $class not found");
        }
        $methods = get_class_methods(__NAMESPACE__ . '\\Test\\' . $class);
        $results = [];
        foreach ($methods as $method) {
            if (strpos($method, 'test') === 0) {
                $results = [
                    ...$results,
                    ...$this->testMethod($path, $class, $method),
                ];
            }
        }
        return $results;
    }

    private function testMethod($path, $class, $method)
    {
        if (!file_exists($path . $class . '.php')) {
            throw new \Exception("File $path$class.php not found");
        }
        require_once $path . $class . '.php';
        if (!class_exists(__NAMESPACE__ . '\\Test\\' . $class)) {
            throw new \Exception("Class $class not found");
        }
        $results = [];
        try {
            $test = new (__NAMESPACE__ . '\\Test\\' . $class)();
            if (method_exists($test, 'setUp')) {
                $test->setUp();
            }
            $result = $test->$method();
            if (is_callable($result)) $result();
            $results[$class . '::' . $method] = true;
        } catch (\Throwable $e) {
            $results[$class . '::' . $method] = $e;
        } finally {
            if (isset($test) && method_exists($test, 'tearDown')) {
                $test->tearDown();
            }
        }
        return $results;
    }

    /**
     * Transform a post to text.
     *
     * ## OPTIONS
     *
     * <post_id>
     * : The test class name.
     *
     */
    public function post($args)
    {
        if (!is_array($args) || count($args) !== 1 || !is_numeric($args[0])) {
            \WP_CLI::error('Missing post_id');
        }

        list($post_id) = $args;
        $post_id = (int) $post_id;
        $post = get_post($post_id);
        if (empty($post)) {
            \WP_CLI::error('Post not found');
        }

        $assistant = $this->plugin->get('assistant');
        $text = $assistant->text($post_id);
        if (empty($text)) {
            \WP_CLI::warning('No text found');
        } else {
            $hash = $assistant->hash($text);
            $chunks = $assistant->chunk($text);
            $count = count($chunks);
            foreach ($chunks as $i => $chunk) {
                $num = $i + 1;
                \WP_CLI::log(str_pad(" $num/$count", 80, '-', STR_PAD_LEFT));
                \WP_CLI::log($chunk);
            }
            \WP_CLI::log(str_pad('', 80, '-', STR_PAD_LEFT));
            \WP_CLI::success("Post_ID: $post_id, Chunks: $count, Hash: $hash");
        }
    }

    /**
     * Index posts for the assistant.
     *
     * ## OPTIONS
     *
     * [<post_id>]
     * : The ID of the post to index. If not provided, index all published posts.
     *
     * [--force]
     * : Force re-indexing even if the post is already up to date.
     *
     * @when after_wp_load
     */
    public function index($args, $assoc_args)
    {
        $assistant = $this->plugin->get('assistant');
        $force = isset($assoc_args['force']) && $assoc_args['force'];

        if (!empty($args)) {
            // Index specific post
            $post_id = (int) $args[0];
            $post = get_post($post_id);
            if (empty($post)) {
                \WP_CLI::error("Post {$post_id} not found.");
            }

            if ($post->post_status !== 'publish') {
                \WP_CLI::warning("Post {$post_id} is not published. Skipping.");
                return;
            }

            \WP_CLI::log("Indexing post {$post_id}: " . get_the_title($post_id));
            $success = $assistant->index_post($post_id);
            if ($success) {
                \WP_CLI::success("Post {$post_id} indexed successfully.");
            } else {
                \WP_CLI::error("Failed to index post {$post_id}.");
            }
        } else {
            // Index all published posts
            $posts = $assistant->get_posts();
            $total = count($posts);
            \WP_CLI::log("Found {$total} published posts.");

            $success_count = 0;
            $fail_count = 0;
            $skip_count = 0;

            foreach ($posts as $post_data) {
                $post_id = $post_data['id'];
                $title = $post_data['title'];
                $outdated = $post_data['outdated'];

                if (!$force && !$outdated) {
                    \WP_CLI::log("Skipping post {$post_id} ({$title}) - already up to date.");
                    $skip_count++;
                    continue;
                }

                \WP_CLI::log("Indexing post {$post_id}: {$title}");
                $success = $assistant->index_post($post_id);
                if ($success) {
                    $success_count++;
                    \WP_CLI::log("  ✓ Success");
                } else {
                    $fail_count++;
                    \WP_CLI::log("  ✗ Failed");
                }
            }

            \WP_CLI::success("Indexing completed: {$success_count} succeeded, {$fail_count} failed, {$skip_count} skipped.");
        }
    }
}
