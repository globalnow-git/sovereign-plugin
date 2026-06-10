<?php
$file = "/var/www/html/wp-content/plugins/sovereign-builder/advanced/class-schema-designer.php";
echo "Lines: " . substr_count(file_get_contents($file), "\n") . "\n";
echo strpos(file_get_contents($file), "ob_start") !== false ? "HAS OB_START - broken\n" : "CLEAN\n";
echo strpos(file_get_contents($file), "<<<JS") !== false ? "HAS HEREDOC - broken\n" : "NO HEREDOC\n";

