<?php

echo "<?php namespace common\models\DB\models;


class Tables {
   
   const all = " . str_replace(["array", "(", ")", "\\\\", "\n"], ["","[","]", "\\", "\n"], var_export($arr, true)) . ";}";
