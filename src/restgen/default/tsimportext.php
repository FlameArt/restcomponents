<?php
/**
 * Генератор CRUD Ajax
 */

use yii\db\ActiveRecordInterface;
use yii\helpers\StringHelper;
use yii\db\ActiveQuery;


/* @var $this yii\web\View */
/* @var $generator yii\gii\generators\crud\Generator
 * @var $tableSchema yii\db\TableSchema
 * @var $params
 * @var $primaryKey
 */

$controllerClass = StringHelper::basename($generator->controllerClass);
//$modelClass = StringHelper::basename($generator->modelClass);
$modelClass = $tableName;

$path = Yii::$app->controllerNamespace;

$tableSchema = $params['tableSchema'];

/* @var $class ActiveRecordInterface */
//$class = $generator->modelClass;
//$pks = $class::primaryKey();
//$urlParams = $generator->generateUrlParams();
//$actionParams = $generator->generateActionParams();
//$actionParamComments = $generator->generateActionParamComments();

$AllTypes = [];

?>

import Generated<?= $controllerClass ?> from "./generated/Generated<?= $controllerClass ?>";

<?php
// Поля с релятивным типом
$importList = [];

foreach ($tableSchema->columns as $column) {

   if(array_key_exists($column->name, $related)) {
      $rName = str_replace('common\\models\\DB\\', "", $related[$column->name]['class']);
      if($rName!==$controllerClass)
         $importList[]=$rName;
   }
}
$importList = array_unique($importList, SORT_STRING);
foreach ($importList as $rName) echo "import $rName from './$rName';\n";

?>

/* Расширенный класс модели <?= $controllerClass ?> */
export default class <?= $controllerClass ?> extends Generated<?= $controllerClass ?> {



}