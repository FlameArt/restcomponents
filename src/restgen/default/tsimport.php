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
$AllTypesGETFields = [];
$sortFields = [];

?>
import REST, { Rows, SavedObject } from 'flamerest';
import RESTTable from './RESTTable';
import { ref, watch } from 'vue';


import <?=$controllerClass?> from '@models/<?=$controllerClass?>';


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
foreach ($importList as $rName) echo "import $rName from '@models/$rName';\n";

?>

<?php

// ГЕНЕРИМ ПОЛЯ
$GeneratedFieldsStr = "";
foreach ($tableSchema->columns as $column) {
   $GeneratedFieldsStr .= "\n    public " . $column->name;

   $default = '';
   $type = 'string';
   switch ($column->type) {
      case 'string':
      case 'char':
      case 'text':
         $type = 'string';
         $default = "";
         break;
      case 'boolean':
         $type = 'boolean';
         $default = 'false';
         break;
      case 'smallint':
      case 'integer':
      case 'bigint':
      case 'float':
      case 'decimal':
         $type = 'number';
         $default = 0;
         break;
      case 'datetime':
      case 'timestamp':
      case 'time':
      case 'date':
         $type = 'string';
         $default = "";
         break;
      case 'binary':
         $type = 'object';
         $default = "{}";
         break;
      case 'money':
         $type = 'number';
         $default = "0";
         break;
      case 'json':
         $type = 'any';
         $default = "";
   }

   $default = $default === '' ? $default = "''" : $default;

   if (in_array($column->name, $filesFields)) {
      $default = "ref($default)";
      $type = 'any';
   } else $default = 'undefined';


   // Поле с релятивным типом
   $relType = "";
   $cleanRelType = "";
   if (array_key_exists($column->name, $related)) {
      $rName = str_replace('common\\models\\DB\\', "", $related[$column->name]['class']);
      $imports = "import $rName from './$rName';";
      $relType = " & $rName";
      $cleanRelType = $rName;
   }

   $AllTypes[] = $column->name . ($column->isPrimaryKey || $column->defaultValue !== null || $column->allowNull ? '?' : '') . ": " . $type;
   $AllTypesGETFields[] = $column->name . '?: string ' .($cleanRelType === '' ? "" : " | " . $cleanRelType);
   $AllTypesAllFields[] = $column->name . ': ' . ($cleanRelType === '' ? "''" : $cleanRelType . ".Fields");
    $DefaultClassesStr[] = '    public '.$column->name.': string ' .($cleanRelType === '' ? "" : " | " . $cleanRelType). ' = "";';

   $sortFields[] = '"'.$column->name.'"';
   $sortFields[] = '"-'.$column->name.'"';

   $GeneratedFieldsStr.= ($default === 'undefined' ? '!' : '') . ": " . $type . $relType . ($column->allowNull ? ' | null ' : '') . "";

   $GeneratedFieldsStr .= $default === 'undefined' ? '' : ' = ' . $default . ';';

}
?>

class <?= $params['tableName'] ?>FieldsDefault {
<?= implode("\n", $DefaultClassesStr)?>

}


export default class Generated<?= $controllerClass ?> extends RESTTable {

    /**
     * Название таблицы
     */
    public static tableName: string = "<?= $params['tableName'] ?>";

    /**
     * Ключевые поля
     */
    private static primaryKeys: string[] = [<?= str_replace("''","", "'".implode("','", $primaryKey)."'") ?>];

    /**
     * Поля
     */
<?= $GeneratedFieldsStr ?>


    /**
     * Набор всех полей для быстрого встраивания в функции получения
     */
    public static Fields = (assign: object = {}) => Object.assign(new <?= $params['tableName'] ?>FieldsDefault, assign);


    /**
     * Получить одну запись
     * @param IDOrWhere ID или набор условий [вернёт первую запись]
     * @param fields поля, которые надо вернуть [если не указаны, вернёт все доступные]
     * @returns
     */
    static async one(IDOrWhere: object | number | string, fields: {<?= implode(", ", $AllTypesGETFields) ?>} | Array<string> | null = null): Promise<<?= $controllerClass ?>|null> {
        return REST.one(this.tableName, IDOrWhere, fields, this.primaryKeys[0]);
    }

    /**
     * Параметры
     * @param params
     * @returns
     */
    static async all(params?: { where?: object, fields?: {<?= implode(", ", $AllTypesGETFields) ?>} | Array<string>, extfields?: object | Array<string>, sort?: Array<<?= implode("|", $sortFields) ?>>, page?: number, perPage?: number, tree?: number }): Promise<Rows<<?= $controllerClass ?>>> {
        return REST.all<<?= $controllerClass ?>>(this.tableName, params);
    }

    /**
     * Создать этот объект, инициализировав переменные
     * @param params
     */
    constructor(params?: {<?= implode(", ", $AllTypes) ?>}) {

        super();
        if(params) Object.assign(this, params);
      <?php foreach ($filesFields as $tField):?>
  watch(this.<?=$tField?>, (n, o)=>this.prepare())
      <?php endforeach; ?>

    }

    /**
     * Создать объект через инициализатор
     * @returns
     */
    public async create(): Promise<SavedObject<<?= $controllerClass ?>>> {
        const result = await REST.create<<?= $controllerClass ?>>(<?= $controllerClass ?>.tableName, this);
        if(result.data !== undefined)
            REST.fillObject(this, result.data)
        return result;
    }

    /**
     * Создать объект через прямой вызов функции
     * @param params
     */
    public static create(params: {<?= implode(", ", $AllTypes) ?>}, tree?: { appendTo?: number | string | null, insertAfter?: number | string | null, insertFirst?: number | string | null }): Promise<SavedObject<<?= $controllerClass ?>>> {
        return REST.create<<?= $controllerClass ?>>(<?= $controllerClass ?>.tableName, params, tree?.appendTo, tree?.insertAfter, tree?.insertFirst);
    }

    /**
     * Изменить значения из текущей модели
     * @param params
     */
    public async edit(): Promise<SavedObject<<?= $controllerClass ?>>> {
        const resp = await REST.edit<<?= $controllerClass ?>>(<?= $controllerClass ?>.tableName, (this as any)[<?= $controllerClass ?>.primaryKeys[0]], this);
        Object.assign(this, resp.data);
        return resp;
    }

    /**
     * Изменить значения через прямой вызов функции
     * @param params
     */
    public static async edit(ID: number | string, values: {<?= implode(", ", $AllTypes) ?>}, tree?: { appendTo?: number | string | null, insertAfter?: number | string | null, insertFirst?: number | string | null }): Promise<SavedObject<<?= $controllerClass ?>>> {
        return REST.edit<<?= $controllerClass ?>>(<?= $controllerClass ?>.tableName, ID, values, tree?.appendTo, tree?.insertAfter, tree?.insertFirst);
    }


    /**
     * Изменить значения через прямой вызов функции
     * @param params
     */
    public async save(): Promise<SavedObject<<?= $controllerClass ?>>> {
        if (this.primaryKeys.length !== this.primaryKeys.filter(r => this[r] === null).length)
            return this.create();
        else
            return this.edit();
    }


    /**
     * Удалить запись или набор записей
     * @param table
     * @param id
     * @param byFields
     */
    public static async delete(id: number | string | null, byFields?: object): Promise<boolean|Array<any>> {
        return REST.remove(<?= $controllerClass ?>.tableName, id ?? 0, byFields);
    }

    /**
     * Подготовить текущую запись к отправке, загрузить все файлы
     * @returns
     */
    public async prepare() {
        return REST.prepare(this);
    }



}