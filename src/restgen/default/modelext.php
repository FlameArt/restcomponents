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

// Определяем: есть ли поле user, которое надо заполнять при создании записи
$USER_FILL = false;
foreach ($tableSchema->columns as $column){
	if(strtolower($column->name) == "user") {
		$USER_FILL = true;
		break;
	}
}


echo "<?php\n";
?>

namespace <?= $generator->ns ?>;

use Yii;
use yii\db\Expression;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use common\models\behaviors\UploadBehavior;
use common\models\behaviors\RelationBehaviors\MaterializedPathBehavior;





/**
 * Расширенный класс таблицы <?= $tableName ?>
 */
class <?=$className?> extends models\Table<?= $className ?>

{

    /**
     * Дополнительные поля в выдачу
     * @return array
     */
    public function customFields() {
        return [
            // 'testField' => function() { return 'test Value'; }
        ];
    }

    /**
     * @var array Роли, которым доступны конкретные поля, и что они могут с ними делать: смотреть/редактировать
     */
    public function ACCESS_RULES()
    {
        return [
            'MainUser' => [
                'view' => '*',
                'edit' => '*',
                'delete' => '*',
            ],
            'Guest' => [
                'view' => '*',
                'edit' => [],
                'delete' => null,
            ],
        ];
    }

    /**
     * Валидаторы [в дополнение к базовым на основе БД]
     * Файлы: [['avatar'], 'file', 'extensions' => ['png', 'gif', 'jpg', ], 'maxSize' => 1024*1024, 'maxFiles' => 4]
    */
    public function rulesExt() {
       return [];
    }


    /**
     * Поведения для таблицы <?= $tableName ?>

     * @return array
     */
	public function behaviors()
	{
		return [

<?php if($USER_FILL): ?>
			// Автоматическое заполнение поля user при создании и обновлении записи: указать поле
			[
				'class' => BlameableBehavior::class,
				'createdByAttribute' => 'user', # Заполнять пользователя при создании, false - не учитывать
				'updatedByAttribute' => false, # Заполнять пользователя при обновлении, false - не учитывать
			],
<?php endif; ?>

			// Автоматический учёт времени: указываются поля дат создания и изменения в таблице
			/*
			[
				'class' => TimestampBehavior::class,
				'createdAtAttribute' => 'created_at', # Дата создания, false - не учитывать
				'updatedAtAttribute' => 'changed_at', # Дата изменения, false - не учитывать
				'value' => new Expression('NOW()'),
			],
			*/


			// Nested Sortable: быстрая сортировка дерева через Materialize Path
			/*
			"MaterializedPath" => [
				'class' => MaterializedPathBehavior::class,
				'delimiter' => '.',
				'pathAttribute' => 'm_path',
				'depthAttribute' => 'm_depth',
                'treeAttribute' => 'm_tree',
				'sortable' => [
					'sortAttribute' => 'm_sort'
                ]
            ]
			*/

            [
                'class' => UploadBehavior::class,
                'fieldsFolders' => [
                    'avatar' => '/usersdata/avatar/',
                    'files' => '/usersdata/uploads/'
                ]
            ]


		];
	}

}
