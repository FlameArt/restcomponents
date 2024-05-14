<?php


namespace flameart\rest\behaviors;


use flameart\rest\models\UploadedFileExtended;
use yii\db\ActiveRecord;
use yii\db\BaseActiveRecord;
use yii\web\UploadedFile;

/**
 * Позволяет автоматически загружать и обновлять файлы, помечая новые имена в соответствующих полях БД
 * @package flameart\rest\behaviors
 */
class UploadBehavior extends \yii\base\Behavior
{

   public $fieldsFolders = [
      'avatar' => '/usersdata/avatars/',
      'files' => '/usersdata/uploads/'
   ];

   public function events()
   {
      return [
         BaseActiveRecord::EVENT_BEFORE_VALIDATE => 'beforeValidate',
         BaseActiveRecord::EVENT_AFTER_VALIDATE => 'afterValidate',
         BaseActiveRecord::EVENT_AFTER_UPDATE => 'afterSave',
         BaseActiveRecord::EVENT_AFTER_INSERT => 'afterSave',
      ];
   }

   public function beforeValidate()
   {

      foreach ($this->owner->rules() as $rule) {
         if ($rule[1] === 'file' || $rule[1] === 'image') {
            if (is_array($rule[0])) {
               foreach ($rule[0] as $attr) {
                  $thisAttr = $this->owner->getAttribute($attr);
                  $attrValues = [];
                  if ($thisAttr !== null && is_array($thisAttr)) {
                     foreach ($thisAttr as $thisFile) {

                        // Существующие файлы
                        if (isset($thisFile['file'])) {

                           // Короткая валидация с удалением повреждённых записей
                           // TODO: можно валидацию сделать чётче, чтобы с других источников файлы не подставлялись
                           if (preg_match("/^([A-Za-z0-9_.\/]*)$/", $thisFile['file']) === 0) continue;
                           if (preg_match("/^([A-Za-z0-9_]*)$/", $thisFile['id']) === 0) continue;
                           if (count($thisFile) !== 2) continue;

                           $attrValues[] = $thisFile;

                           continue;
                        }

                        // Новые файлы
                        $newfile = tempnam(sys_get_temp_dir(), "_tmp");
                        $bytes = file_get_contents(str_replace(' ', '+', $thisFile['data']));
                        file_put_contents($newfile, $bytes);
                        $attrValues[] = new UploadedFileExtended(['id' => $thisFile['id'], 'name' => $thisFile['name'], 'tempName' => $newfile, 'type' => \yii\helpers\FileHelper::getMimeType($newfile), 'size' => filesize($newfile)]);
                     }
                     $this->owner->setAttribute($attr, $attrValues);
                  }
               }
            }
         }
      }
   }

   /**
    * После валидации перемещаем данные в постоянную папку
    * @throws \yii\base\Exception
    */
   public function afterValidate()
   {
      foreach ($this->owner->rules() as $rule) {
         if ($rule[1] === 'file' || $rule[1] === 'image') {
            if (is_array($rule[0])) {
               foreach ($rule[0] as $attr) {
                  $thisAttr = $this->owner->getAttribute($attr);
                  $attrValues = [];
                  if ($thisAttr !== null && is_array($thisAttr)) {
                     foreach ($thisAttr as $thisFile) {
                        // новые файлы, прошедшие валидацию
                        if (!(is_array($thisFile) && isset($thisFile['file']))) {
                           $newFilename = '';
                           for ($i = 0; $i < 10; $i++) {
                              $newFilename = trim($this->fieldsFolders[$attr], "/\\") . "/" . preg_replace("/([^A-Za-z0-9_])/", "_", \Yii::$app->security->generateRandomString(32)) . "." . $thisFile->extension;
                              if (!file_exists(\Yii::getAlias('@app') . "/../" . $newFilename)) break;
                           }
                           $attrValues[] = [
                              'file' => $newFilename,
                              'id' => $thisFile->id,
                              'name' => $thisFile->name
                           ];

                           if (count($this->owner->errors) > 0)
                              unlink($thisFile->tempName);
                           else {
                              rename($thisFile->tempName, \Yii::getAlias('@app') . "/../" . $newFilename);
                              chmod(\Yii::getAlias('@app') . "/../" . $newFilename, 0640);
                           }
                        }
                        else {
                           // Старые файлы
                           $attrValues[] = $thisFile;
                        }
                     }
                     $this->owner->setAttribute($attr, $attrValues);
                  }
               }
            }
         }
      }
   }

   public function afterSave()
   {
      if (count($this->owner->errors) > 0) {
         self::RemoveUnsavedFiles($this->owner);
      }
   }

   /**
    * @param ActiveRecord $model
    * @throws \yii\base\Exception
    */
   public static function RemoveUnsavedFiles($model)
   {

      foreach ($model->rules() as $rule) {
         if ($rule[1] === 'file' || $rule[1] === 'image') {
            if (is_array($rule[0])) {
               foreach ($rule[0] as $attr) {
                  $thisAttr = $model->getAttribute($attr);
                  $thisAttr = is_string($thisAttr) ? explode(";", $thisAttr) : [];
                  if ($thisAttr !== null && is_array($thisAttr)) {
                     foreach ($thisAttr as $thisFile) {
                        if ($thisFile === '') continue;
                        $thisFile = \Yii::getAlias('@app') . "/../" . $thisFile;
                        if (file_exists($thisFile)) {
                           unlink($thisFile);
                        }
                     }
                  }
               }
            }
         }
      }
   }

}