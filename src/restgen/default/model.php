<?php
/**
 * This is the template for generating the model class of a specified table.
 */

/* @var $this yii\web\View */
/* @var $generator yii\gii\generators\model\Generator */
/* @var $tableName string full table name */
/* @var $className string class name */
/* @var $queryClassName string query class name */
/* @var $tableSchema yii\db\TableSchema */
/* @var $labels string[] list of attribute labels (name => label) */
/* @var $rules string[] list of validation rules */
/* @var $relations array list of relations (name => relation declaration) */

$className = "Table".$className;

echo "<?php\n";
?>

namespace <?= $generator->ns ?>\models;

use Yii;
use flameart\rest\behaviors\UploadBehavior;


/**
 * Ссылки на расширенные версии таблиц
 */
<?php
$rel_arr = [];
foreach ($relations as $name => $relation) {
	$str = $generator->ns . '\\' . $relation[1];
	if(!isset($rel_arr[$str])) {
		echo 'use ' . $str . ";\n";
		$rel_arr[$str]=true;
	}
}
?>

use yii\helpers\ArrayHelper;
use yii\helpers\Json;



/**
 * This is the model class for table "<?= $generator->generateTableName($tableName) ?>".
 *
<?php foreach ($tableSchema->columns as $column): ?>
 * @property <?= "{$column->phpType} \${$column->name}\n" ?>
<?php endforeach; ?>
<?php if (!empty($relations)): ?>
 *
<?php foreach ($relations as $name => $relation): ?>
 * @property <?= $relation[1] . ($relation[2] ? '[]' : '') . ' $' . lcfirst($name) . "\n" ?>
<?php endforeach; ?>
<?php endif; ?>
 */
class <?= $className ?> extends DefaultTable

{

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return '<?= $generator->generateTableName($tableName) ?>';
    }
<?php if ($generator->db !== 'db'): ?>

    /**
     * @return \yii\db\Connection the database connection used by this AR class.
     */
    public static function getDb()
    {
        return Yii::$app->get('<?= $generator->db ?>');
    }
<?php endif; ?>

    /**
     * Список всех полей ДБ с их оригинальными типами
     * @return array
     */
    public static function tableFields() {
        return [
<?php foreach ($tableSchema->columns as $column): ?>
            '<?=$column->name?>' => '<?=$column->type?>',
<?php endforeach; ?>
        ];
    }

    public static function relatedFields() {
        return <?= str_replace(["array", "(", ")", "\\\\","\n"], ["","[","]", "\\","\n        "], var_export($relations_list, true)) ?>;
    }


    /**
     * @inheritdoc
     */
    public function rules()
    {
        $arr = array_merge($this->rulesExt(), [<?= "\n            " . implode(",\n            ", $rules) . ",\n        " ?>]);

        // Отключаем проверку для загруженных файлов на корректность строки
        $fields = [];
        foreach ($this->behaviors() as $behavior)
           if(isset($behavior['class']) && $behavior['class'] === UploadBehavior::class || $behavior === UploadBehavior::class) $fields = $behavior['fieldsFolders'];

        $fields = array_keys($fields);
        foreach ($arr as &$item) {
           if(is_array($item[0])) {
              foreach ($item[0] as $key => $attr) {
                 if(in_array($attr, $fields) && $item[1] === 'string') {
                    unset($item[0][$key]);
                 }
              }
           }
        }

        return $arr;

    }

   /**
    * Дополнительные валидаторы, которые будут совмещены с оригинальными rules
    * @return array
    */
    public function rulesExt() {
       return [];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return array_fill_keys(array_keys($this->customFields()), '') +
        [
<?php foreach ($labels as $name => $label): ?>
            <?= "'$name' => " . $generator->generateString($label) . ",\n" ?>
<?php endforeach; ?>
        ];
    }

<?php foreach ($relations as $name => $relation): ?>

    /**
     * @return \yii\db\ActiveQuery
     */
    public function get<?= $name ?>()
    {
        <?= $relation[0] . "\n" ?>
    }
<?php endforeach; ?>

<?php if ($queryClassName): ?>
<?php
    $queryClassFullName = ($generator->ns === $generator->queryNs) ? $queryClassName : '\\' . $generator->queryNs . '\\' . $queryClassName;
    echo "\n";
?>
    /**
     * @inheritdoc
     * @return <?= $queryClassFullName ?> the active query used by this AR class.
     */
    public static function find()
    {
        return new <?= $queryClassFullName ?>(get_called_class());
    }
<?php endif; ?>

}
