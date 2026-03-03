<?php

namespace amund\WP_Assistant;

use Libsql\Database;
use Html2Text\Html2Text;
use amund\WP_Assistant\Chunker;

class Assistant
{
    public $db;
    public $hash = 'md5';
    public $chunk_size = 1000;
    public $chunk_overlap = 0;

    public function __construct()
    {
        // $this->post = get_post($post_id);
    }

    public function text(int $post_id): string
    {
        $value = apply_filters('the_content', get_the_content(null, false, $post_id));
        $value = new Html2Text($value, ['do_links' => 'none', 'width' => 80]);
        $value = $value->getText();
        $value = trim($value);
        $value = preg_replace('#\\\\n#', "\n", $value);
        $value = preg_replace('#\n{3,}#', "\n\n", $value);
        return $value;
    }

    public function hash($text): string
    {
        return hash($this->hash, $text);
    }

    public function chunk($text): array
    {
        return Chunker::chunk($text, $this->chunk_size, $this->chunk_overlap);
    }
}
