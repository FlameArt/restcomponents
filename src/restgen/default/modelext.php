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
$CREATED_EXISTS = false;
$UPDATED_EXISTS = false;
foreach ($tableSchema->columns as $column){
	if(strtolower($column->name) == "user") {
		$USER_FILL = true;
	}
	if(strtolower($column->name) == "created_at") {
		$CREATED_EXISTS = true;
	}
	if(strtolower($column->name) == "updated_at") {
        $UPDATED_EXISTS = true;
	}
}




echo "<?php\n";
?>

namespace <?= $generator->ns ?>;

use Yii;
use yii\db\Expression;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use flameart\rest\behaviors\UploadBehavior;
use flameart\rest\behaviors\RelationBehaviors\MaterializedPathBehavior;





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
                'create' => '*',
                'edit' => '*',
                'delete' => '*',
                'rowsFilter' => function($model){
                   <?php if(!$USER_FILL &&
                      $className!=='UserSettings' &&
                      $className!=='UserNotifications' &&
                      $className!=='UserMessages' &&
                      $className!=='UserPushTokens' &&
                      $className!=='UserOrders' &&
                      $className!=='UserSubscribtions'
                   ): ?>//<?php endif;?> $model->andWhere(['user'=>Yii::$app->user->id]);
                   <?php if($className ==='UserMessages'): ?>
                       $model->orWhere(['user_from'=> Yii::$app->user->id]);
                       $model->orWhere(['user_to'=> Yii::$app->user->id]);
                       $model->orderBy(['id'=>SORT_ASC]);
                   <?php endif;?>
                },
            ],
            'Guest' => [
                'view' => '<?php if($className === 'UserSettings' || $className === 'UserNotifications') echo '[]'; else echo '*'?>',
                'create' => [],
                'edit' => [],
                'delete' => null,
                'rowsFilter' => function($model){
                },
            ],
            'Admin' => [
                'view' => '*',
                'create' => '*',
                'edit' => '*',
                'delete' => '*',
                'rowsFilter' => function($model){
                },
            ],
        ];
    }

    /**
     * Валидаторы [в дополнение к базовым на основе БД]
     * Файлы [поле в базе должно быть вида JSON]:
     * [['avatar'], 'file', 'extensions' => ['png', 'gif', 'jpg', 'jfif' ], 'maxSize' => 1024*1024*10, 'maxFiles' => 4]
    */
    public function rulesExt() {
<?= $CREATED_EXISTS || $UPDATED_EXISTS || $USER_FILL ? '       $this->RemoveFieldsFromRule($this->default_rules, "required", [' . ($USER_FILL ? "'user'," : ''). ($CREATED_EXISTS ? "'created_at'," : ''). ($UPDATED_EXISTS ? "'updated_at'," : '')."] );" : ''?>
       return [];
    }


    /**
     * Поведения для таблицы <?= $tableName ?>

     * @return array
     */
	public function behaviors()
	{
		return [

            // Поля для загрузки файлов
            [
                'class' => UploadBehavior::class,
                'fieldsFolders' => [
                    'avatar' => '/usersdata/avatar/',
                    'files' => '/usersdata/uploads/'
                ]
            ],

<?php if($USER_FILL): ?>
			// Автоматическое заполнение поля user при создании и обновлении записи: указать поле
			[
				'class' => BlameableBehavior::class,
				'createdByAttribute' => 'user', # Заполнять пользователя при создании, false - не учитывать
				'updatedByAttribute' => false, # Заполнять пользователя при обновлении, false - не учитывать
			],
<?php endif; ?>

			// Автоматический учёт времени: указываются поля дат создания и изменения в таблице
			<?= !$CREATED_EXISTS && !$UPDATED_EXISTS ? '/*' : '' ?>
			[
				'class' => TimestampBehavior::class,
				'createdAtAttribute' => <?= $CREATED_EXISTS ? "'created_at'" : 'false' ?>, # Дата создания, false - не учитывать
				'updatedAtAttribute' => <?= $UPDATED_EXISTS ? "'updated_at'" : 'false' ?>, # Дата изменения, false - не учитывать
				'value' => new Expression('NOW()'),
			],
			<?= !$CREATED_EXISTS && !$UPDATED_EXISTS ? '*/' : '' ?>



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
            ],
			*/


		];
	}

	public function afterSave($insert, $changedAttributes)
    {
      parent::afterSave($insert, $changedAttributes);

      /**
       * Добавляем подписку на элемент этой таблицы

      if($insert) {
         $newsub = new Subscribtions();
         $newsub->item = $this->id;
         $newsub->itemTable = self::tableName();
         $newsub->price = 0;
         $newsub->periodDays = 0;
         $newsub->periodMonths = 1;
         $newsub->trialDays = 3;
         $newsub->isForeverItem = 0;
         $newsub->save();
      }
      */
   }


}
