<?php

echo "<?php return " . str_replace(["array", "(", ")", "\\\\", "\n"], ["","[","]", "\\", "\n"], var_export($arr, true)) . ";";
