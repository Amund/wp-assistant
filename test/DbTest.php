<?php

namespace amund\WP_Assistant\Test;

use amund\WP_Assistant\Db;

// function get_the_title()
// {
//     return 'test';
// }

class DbTest
{
    function test_add_document()
    {
        $db = new Db('/tmp/wp_assistant_db_test.db');
        echo "Test test_instance passed.\n";
    }

    function test_4()
    {
        var_dump(class_exists('Db'));
        var_dump(get_the_title(73));
    }
}
