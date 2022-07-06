<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace common\models\behaviors\RelationBehaviors\actions;

use common\models\behaviors\UploadBehavior;
use http\Exception\InvalidArgumentException;
use Yii;
use yii\base\Model;
use yii\helpers\Json;
use yii\helpers\Url;
use yii\rest\Action;
use yii\web\ForbiddenHttpException;
use yii\web\ServerErrorHttpException;

/**
 * CreateAction implements the API endpoint for creating a new model from the given data.
 *
 * For more details and usage information on CreateAction, see the [guide article on rest controllers](guide:rest-controllers).
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class CreateAction extends Action
{
   /**
    * @var string the scenario to be assigned to the new model before it is validated and saved.
    */
   public $scenario = Model::SCENARIO_DEFAULT;
   /**
    * @var string the name of the view action. This property is needed to create the URL when the model is successfully created.
    */
   public $viewAction = 'view';


   /**
    * Creates a new model.
    * @return \yii\db\ActiveRecordInterface the model newly created
    * @throws ServerErrorHttpException if there is any error when creating the model
    */
   public function run()
   {

      if ($this->checkAccess) {
         call_user_func($this->checkAccess, $this->id);
      }

      /* @var $model \yii\db\ActiveRecord */
      $model = new $this->modelClass([
         'scenario' => $this->scenario,
      ]);

      $data = Json::decode(\Yii::$app->request->getRawBody(), true);

      // Сравниваем полученные для изменения поля с разрешёнными
      $accepted_fields = $model->filterFieldsByRole('edit', $model, false, array_keys($data));
      if(count($accepted_fields) !== count($data))
         throw new ForbiddenHttpException("Forbidden");

      $model->load(Yii::$app->getRequest()->getBodyParams(), '');

      $modelClass = null;
      $AllTables = require(\Yii::getAlias("@common") . '/models/DB/models/Tables.php');

      $appendTo = Yii::$app->request->get('appendTo');
      $insertAfter = Yii::$app->request->get('insertAfter');
      $insertFirst = Yii::$app->request->get('insertFirst');

      if( $appendTo !== null ) {
         $appendTo = intval($appendTo);
         $node = null;
         if($appendTo === 0) {
            $modelClass = $modelClass ?? (new $this->modelClass());
            $node = $modelClass->find()->andWhere([$modelClass->USER_ATTRIBUTE=>Yii::$app->user->id, $modelClass->behaviors['MaterializedPath']->depthAttribute=> 0])->one();
            if($node === null) {
               $node = new $this->modelClass();
               $node->makeRoot();
               $node->save();
            }
            $model->appendTo($node);
         }
         else {
            $modelClass = $modelClass ?? (new $this->modelClass());
            $node = $modelClass->find()->andWhere([$AllTables[$modelClass->tableName()]['primaryKey'][0]=>$appendTo])->one();
            if($node === null) throw new \HttpRequestException("AppendTo key is unknown");
            $model->appendTo($node);
         }
      }

      if( $insertAfter !== null ) {
         $insertAfter = intval($insertAfter);
         $modelClass = $modelClass ?? (new $this->modelClass());
         $node = $modelClass->find()->andWhere([$AllTables[$modelClass->tableName()]['primaryKey'][0] => $insertAfter])->one();
         if($node === null) throw new \HttpRequestException("Insert After key is unknown");
         $model->insertAfter($node);
      }

      if( $insertFirst !== null ) {
         $insertFirst = intval($insertFirst);
         $modelClass = $modelClass ?? (new $this->modelClass());
         $node = $modelClass->find()->andWhere([$AllTables[$modelClass->tableName()]['primaryKey'][0] => $insertFirst])->one();
         if($node === null) throw new \HttpRequestException("Insert Root key is unknown");
         $model->prependTo($node);
      }


      $newException = null;
      try {
         if ($model->save() === true) {
            $response = Yii::$app->getResponse();
            $response->setStatusCode(201);

            $accepted_fields = $model->filterFieldsByRole('view', $model);
            foreach ($model->attributes as $key=>$attr)
               if(!in_array($key, $accepted_fields))
                  unset($model->$key);
            return $model;

         }
      }
      catch (\Exception $exception) {
         $newException = $exception;
      }

      // Если есть ошибки после добавления в базу [когда валидаторы успешно пройдены] - удаляем временные файлы, если таковые имеются
      foreach ($model->behaviors() as $behavior)
         if(is_string($behavior) && $behavior === UploadBehavior::class || isset($behavior['class']) && $behavior['class'] === UploadBehavior::class)
            UploadBehavior::RemoveUnsavedFiles($model);

      // Известная и неизвестная ошибка
      if($model->hasErrors()) return $model;
      if($newException !== null) throw $newException;
      throw new ServerErrorHttpException('Failed to create the object for unknown reason.');

   }
}
