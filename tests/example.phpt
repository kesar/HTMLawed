--TEST--
XSS Quick Test
--STDIN--
<SCRIPT>alert('XSS')</SCRIPT>
--FILE--
<?php
require 'vendor/autoload.php';
echo htmLawed(stream_get_contents(STDIN), array('safe'=>1));
?>
--EXPECT--
alert('XSS')