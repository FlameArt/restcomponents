<?php


namespace flameart\rest\behaviors\RelationBehaviors\actions;



use Yii;
use yii\helpers\Json;
use yii\rest\Action;
use yii\web\ForbiddenHttpException;
use yii\web\ServerErrorHttpException;
use flameart\rest\behaviors\UploadBehavior;

/**
 * DeleteAction implements the API endpoint for deleting a model.
 *
 * For more details and usage information on DeleteAction, see the [guide article on rest controllers](guide:rest-controllers).
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class DeleteAction extends Action
{
   /**
    * Deletes a model.
    * @param mixed $id id of the model to be deleted.
    * @throws ServerErrorHttpException on failure.
    */
   public function run($id)
   {

      $data = Json::decode(\Yii::$app->request->getRawBody(), true);

      $model = null;
      if (count($data) === 0)
         $model = $this->findModel($id);
      else
         $model = new $this->modelClass;

      // Проверка доступа первичная
      if ($this->checkAccess) {
         call_user_func($this->checkAccess, $this->id, $model);
      }

      // Проверка доступа базовая
      // Тут будет throw forbidden если условиям не подходит
      $model->filterFieldsByRole('delete', $model);

      if (isset($model->behaviors['MaterializedPath'])) {
         if (Yii::$app->request->get("withoutChildrens") === null) {
            $model->deleteAll($model->getDescendants(null, true)->where);
         }
      }

      // Удаляем одиночную запись по id
      elseif (count($data) === 0) {
         if ($model->delete() === false)
            throw new ServerErrorHttpException('Failed to delete the object for unknown reason.');
      }

      // Удаляем любое число записей по запросу
      elseif (count($data) > 0) {
         $this->MassDelete($data);
      }

      else
         throw new ServerErrorHttpException('Failed to delete the object for unknown reason.');

      // Удаление успешно: удаляем загруженные файлы
      foreach ($model->behaviors() as $behavior)
         if(is_string($behavior) && $behavior === UploadBehavior::class || isset($behavior['class']) && $behavior['class'] === UploadBehavior::class)
            UploadBehavior::RemoveUnsavedFiles($model);



      Yii::$app->getResponse()->setStatusCode(204);
   }

   /**
    * Удаление группы записей
    */
   private function MassDelete($data)
   {

      if (!isset(\Yii::$app->user))
         $role = 'Console';
      else
         if (\Yii::$app->user->isGuest)
            $role = 'Guest';
         else {
            $role = \Yii::$app->authManager->getRolesByUser(\Yii::$app->user->id);
            $role = reset($role)->name;
         }

      $model = (new $this->modelClass);
      $rules = (new $this->modelClass)->ACCESS_RULES();
      if (isset($rules[$role]['delete'])) {
         if ($rules[$role]['delete'] === 'self') {
            return ($this->modelClass)::deleteAll(array_merge($data, [$model->USER_ATTRIBUTE=> \Yii::$app->user->id]));
         }
         elseif ($rules[$role]['delete'] === '*' || is_callable($rules[$role]['delete']) && ($rules[$role]['delete']($this, $data)) === true) {
            return ($this->modelClass)::deleteAll($data);
         }
         else
            throw new ForbiddenHttpException('Записи нельзя удалять. Настройте доступы для удаления');
      } else {
         throw new ForbiddenHttpException('Записи нельзя удалять. Настройте доступы для удаления');
      }

   }

}
