<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace flameart\rest\restgen;

use Yii;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;
use yii\db\Connection;
use yii\db\Schema;
use yii\db\TableSchema;
use yii\gii\CodeFile;
use yii\helpers\Inflector;
use yii\base\NotSupportedException;

/**
 * This generator will generate one or multiple ActiveRecord classes for the specified database table.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class Generator extends \yii\gii\Generator
{
   const RELATIONS_NONE = 'none';
   const RELATIONS_ALL = 'all';
   const RELATIONS_ALL_INVERSE = 'all-inverse';

   public $db = 'db';
   public $ns = 'common\models\DB';
   public $tableName;
   public $modelClass;
   public $baseClass = 'yii\db\ActiveRecord';
   public $generateRelations = self::RELATIONS_ALL;
   public $generateLabelsFromComments = true;
   public $useTablePrefix = false;
   public $useSchemaName = true;
   public $generateQuery = false;
   public $queryNs = 'common\models\DB';
   public $queryClass;
   public $queryBaseClass = 'yii\db\ActiveQuery';
   public $controllerClass;

   # Все колонки связанных таблиц
   public $full_relations = [];


   /**
    * @inheritdoc
    */
   public function getName()
   {
      return 'REST Tables Generator';
   }

   /**
    * @inheritdoc
    */
   public function getDescription()
   {
      return 'Создаёт красивые модели таблиц и контроллеры REST для них';
   }

   /**
    * @inheritdoc
    */
   public function rules()
   {
      return array_merge(parent::rules(), [
         [['db', 'ns', 'baseClass', 'queryNs', 'queryClass', 'queryBaseClass'], 'filter', 'filter' => 'trim'],
         [['ns', 'queryNs'], 'filter', 'filter' => function ($value) {
            return trim($value, '\\');
         }],

         [['db', 'ns', 'tableName', 'baseClass', 'queryNs', 'queryBaseClass'], 'required'],
         [['db', 'queryClass'], 'match', 'pattern' => '/^\w+$/', 'message' => 'Only word characters are allowed.'],
         [['ns', 'baseClass', 'queryNs', 'queryBaseClass'], 'match', 'pattern' => '/^[\w\\\\]+$/', 'message' => 'Only word characters and backslashes are allowed.'],
         [['db'], 'validateDb'],
         [['ns', 'queryNs'], 'validateNamespace'],
         [['tableName'], 'validateTableName'],
         [['baseClass'], 'validateClass', 'params' => ['extends' => ActiveRecord::class]],
         [['queryBaseClass'], 'validateClass', 'params' => ['extends' => ActiveQuery::class]],
         [['generateRelations'], 'in', 'range' => [self::RELATIONS_NONE, self::RELATIONS_ALL, self::RELATIONS_ALL_INVERSE]],
         [['generateLabelsFromComments', 'useTablePrefix', 'useSchemaName', 'generateQuery'], 'boolean'],
         [['enableI18N'], 'boolean'],
         [['messageCategory'], 'validateMessageCategory', 'skipOnEmpty' => false],
      ]);
   }

   /**
    * @inheritdoc
    */
   public function attributeLabels()
   {
      return array_merge(parent::attributeLabels(), [
         'ns' => 'Namespace',
         'db' => 'Database Connection ID',
         'tableName' => 'Выберите таблицы',
         'baseClass' => 'Base Class',
         'generateRelations' => 'Создать связи с другими таблицами',
         'generateLabelsFromComments' => 'Generate Labels from DB Comments',
         'generateQuery' => 'Generate ActiveQuery',
         'queryNs' => 'ActiveQuery Namespace',
         'queryClass' => 'ActiveQuery Class',
         'queryBaseClass' => 'ActiveQuery Base Class',
         'useSchemaName' => 'Use Schema Name',
      ]);
   }

   /**
    * @inheritdoc
    */
   public function hints()
   {
      return array_merge(parent::hints(), [
         'ns' => 'This is the namespace of the ActiveRecord class to be generated, e.g., <code>app\models</code>',
         'db' => 'This is the ID of the DB application component.',
         'tableName' => '',
         'modelClass' => 'This is the name of the ActiveRecord class to be generated. The class name should not contain
                the namespace part as it is specified in "Namespace". You do not need to specify the class name
                if "Table Name" ends with asterisk, in which case multiple ActiveRecord classes will be generated.',
         'baseClass' => 'This is the base class of the new ActiveRecord class. It should be a fully qualified namespaced class name.',
         'generateRelations' => 'This indicates whether the generator should generate relations based on
                foreign key constraints it detects in the database. Note that if your database contains too many tables,
                you may want to uncheck this option to accelerate the code generation process.',
         'generateLabelsFromComments' => 'This indicates whether the generator should generate attribute labels
                by using the comments of the corresponding DB columns.',
         'useTablePrefix' => 'This indicates whether the table name returned by the generated ActiveRecord class
                should consider the <code>tablePrefix</code> setting of the DB connection. For example, if the
                table name is <code>tbl_post</code> and <code>tablePrefix=tbl_</code>, the ActiveRecord class
                will return the table name as <code>{{%post}}</code>.',
         'useSchemaName' => 'This indicates whether to include the schema name in the ActiveRecord class
                when it\'s auto generated. Only non default schema would be used.',
         'generateQuery' => 'This indicates whether to generate ActiveQuery for the ActiveRecord class.',
         'queryNs' => 'This is the namespace of the ActiveQuery class to be generated, e.g., <code>app\models</code>',
         'queryClass' => 'This is the name of the ActiveQuery class to be generated. The class name should not contain
                the namespace part as it is specified in "ActiveQuery Namespace". You do not need to specify the class name
                if "Table Name" ends with asterisk, in which case multiple ActiveQuery classes will be generated.',
         'queryBaseClass' => 'This is the base class of the new ActiveQuery class. It should be a fully qualified namespaced class name.',
      ]);
   }

   /**
    * @inheritdoc
    */
   public function autoCompleteData()
   {
      $db = $this->getDbConnection();
      if ($db !== null) {
         return [
            'tableName' => function () use ($db) {
               return $db->getSchema()->getTableNames();
            },
         ];
      } else {
         return [];
      }
   }

   /**
    * @inheritdoc
    */
   public function requiredTemplates()
   {
      // @todo make 'query.php' to be required before 2.1 release
      return ['model.php'/*, 'query.php'*/];
   }

   /**
    * @inheritdoc
    */
   public function stickyAttributes()
   {
      return array_merge(parent::stickyAttributes(), ['ns', 'db', 'baseClass', 'generateRelations', 'generateLabelsFromComments', 'queryNs', 'queryBaseClass']);
   }

   /**
    * Returns the `tablePrefix` property of the DB connection as specified
    *
    * @return string
    * @since 2.0.5
    * @see getDbConnection
    */
   public function getTablePrefix()
   {
      $db = $this->getDbConnection();
      if ($db !== null) {
         return $db->tablePrefix;
      } else {
         return '';
      }
   }

   /**
    * @inheritdoc
    */
   public function generate()
   {
      $files = [];
      $relations = $this->generateRelations();
      $db = $this->getDbConnection();
      $newTables = [];

      $notGenerate = [
         '__sessions',
         '__usersauthservices',
         '__migrations',
         Yii::$app->components['authManager']['itemTable'],
         Yii::$app->components['authManager']['itemChildTable'],
         Yii::$app->components['authManager']['assignmentTable'],
         Yii::$app->components['authManager']['ruleTable'],
      ];

      $onlyGenerateTS = [
         'user'
      ];

      foreach ($this->getTableNames() as $tableName) {

         // Не генерим REST для таблицы Users [для безопасности паролей и конф. данных, для этой таблицы нужен отдельный особый контроллер]
         if (in_array($tableName, $notGenerate)) continue;

         // model :
         $modelClassName = $this->generateClassName($tableName);
         $queryClassName = ($this->generateQuery) ? $this->generateQueryClassName($modelClassName) : false;
         $tableSchema = $db->getTableSchema($tableName);
         $params = [
            'tableName' => $tableName,
            'className' => $modelClassName,
            'queryClassName' => $queryClassName,
            'tableSchema' => $tableSchema,
            'labels' => $this->generateLabels($tableSchema),
            'rules' => $this->generateRules($tableSchema),
            'relations' => isset($relations[$tableName]) ? $relations[$tableName] : [],
            'full_relations' => isset($this->full_relations[$tableName]) ? $this->full_relations[$tableName] : [],
            'relations_list' => $this->generateRelationsFields($tableSchema),
         ];
         if (!in_array($tableName, $onlyGenerateTS))
            $files[] = new CodeFile(
               Yii::getAlias('@' . str_replace('\\', '/', $this->ns)) . '/models/Table' . $modelClassName . '.php',
               $this->render('model.php', $params)
            );

         $newCols = []; foreach ($db->getTableSchema($tableName)->columns as $newCol) $newCols[$newCol->name]=$newCol->type;

         $newTables[$tableName] = [
            'class' => $this->ns . '\\' . $modelClassName,
            'table' => $tableName,
            'primaryKey' => $db->getTableSchema($tableName)->primaryKey,
            'related' => $params['relations_list'],
            'columns' => $newCols
         ];


         // Расширение модели
         $renderfile = "";
         $filename = Yii::getAlias('@' . str_replace('\\', '/', $this->ns)) . '/' . $modelClassName . '.php';

         // Расширенные модели только создаются новые, но их нельзя перезаписать:
         // расширения специально созданы, чтобы менять только базовые модели.
         if (!file_exists($filename)) {

            // Модель пользователя
            if (strpos($modelClassName, "Users") === 0) {
               $renderfile = "users.php";
            } // Обычная модель
            else {
               $renderfile = "modelext.php";
            }

            // Рендерим файлы
            if (!in_array($tableName, $onlyGenerateTS))
               $files[] = new CodeFile(
                  $filename,
                  $this->render($renderfile, $params)
               );

         }

         // Модель ActiveQuery
         if ($queryClassName) {
            $params['className'] = $queryClassName;
            $params['modelClassName'] = $modelClassName;
            if (!in_array($tableName, $onlyGenerateTS))
               $files[] = new CodeFile(
                  Yii::getAlias('@' . str_replace('\\', '/', $this->queryNs)) . '/' . $queryClassName . '.php',
                  $this->render('query.php', $params)
               );
         }

         # ГЕНЕРИМ REST КОНТРОЛЛЕР
         $controllerClass = strtolower($modelClassName);

         // Преобразуем название таблицы в название текущего класса
         $this->controllerClass = \yii\helpers\Inflector::id2camel($controllerClass, "_");

         // Версионирование: создаём контроллеры для каждой из версий, если их нет
         $GlobalRestConfigClass = 'rest\controllers\GlobalRestConfig';
         $versions = ($GlobalRestConfigClass)::versions;

         foreach ($versions as $version) {

            # Базовый и расширяемый классы контроллера
            $controllerFileExt = Yii::getAlias('@rest') . '/controllers/api/' . $version . '/' . $this->controllerClass . 'Controller.php';

            if (!file_exists($controllerFileExt)) {

               # Генерим базовый класс
               if (!in_array($tableName, $onlyGenerateTS))
                  $files[] = new CodeFile($controllerFileExt, $this->render('controller.php', ['tableName' => $tableName, 'controllerClass' => $this->controllerClass, 'apiversion' => $version]));

            }

         }

         // Создаём Typescript ORM Imports
         if (class_exists(('common\\models\\DB\\' . $this->controllerClass))) {
            $controllerFileExt = Yii::getAlias('@rest') . "/TSImports/generated/Generated" . $this->controllerClass . ".ts";
            $controllerExtendedFileExt = Yii::getAlias('@rest') . "/TSImports/" . $this->controllerClass . ".ts";
            $controllerDefaultFileExt = Yii::getAlias('@rest') . "/TSImports/generated/RESTTable.ts";
            $nClass = ('common\\models\\DB\\' . $this->controllerClass);
            $testClass = new $nClass;
            $crules = $testClass->rulesExt();
            $filesFields = [];
            $related = $nClass::relatedFields();
            foreach ($crules as $crule)
               if ($crule[1] === 'image' || $crule[1] === 'file') $filesFields = array_merge($filesFields, is_array($crule[0]) ? $crule[0] : [$crule[0]]);

            $files[] = new CodeFile($controllerFileExt, $this->render('tsimport.php', ['tableName' => $tableName, 'controllerClass' => $this->controllerClass, 'params' => $params, 'primaryKey' => $db->getTableSchema($tableName)->primaryKey, 'filesFields' => $filesFields, 'related' => $related]));
            if(!file_exists($controllerExtendedFileExt))
               $files[] = new CodeFile($controllerExtendedFileExt, $this->render('tsimportext.php', ['tableName' => $tableName, 'controllerClass' => $this->controllerClass, 'params' => $params, 'primaryKey' => $db->getTableSchema($tableName)->primaryKey, 'filesFields' => $filesFields, 'related' => $related]));
            $files[] = new CodeFile($controllerDefaultFileExt, $this->render('tsdefault.php', ['tableName' => $tableName, 'controllerClass' => $this->controllerClass, 'params' => $params, 'primaryKey' => $db->getTableSchema($tableName)->primaryKey, 'filesFields' => $filesFields, 'related' => $related]));
         }

      }

      # Файл всех таблиц
      $arr = [];
      $alltables = $db->getSchema()->getTableNames();
      foreach ($alltables as $thistable) {
         $defaultClass = $this->ns . '\\' . $this->generateClassName($thistable);
         $userClass = $this->ns . '\\' . $this->generateClassName($thistable);
         if (!isset($newTables[$thistable]) && (class_exists($defaultClass) || class_exists($userClass))) {
            $ModelClass = class_exists($defaultClass) ? $defaultClass : $userClass;
            $DBModel = new $ModelClass();
            $Related = $DBModel::relatedFields();
            $arr[$thistable] = [
               'class' => $DBModel::className(),
               'table' => $DBModel::tableName(),
               'primaryKey' => $db->getTableSchema($thistable)->primaryKey,
               'related' => $Related,
               'columns' => $DBModel::tableFields()
            ];
         } elseif (isset($newTables[$thistable])) {
            $arr[$thistable] = $newTables[$thistable];
         }
      }
      $files[] = new CodeFile(Yii::getAlias('@' . str_replace('\\', '/', $this->ns)) . '/models/Tables.php', $this->render('tables.php', ['arr' => $arr]));

      return $files;
   }


   /**
    * Генерим список связей для таблиц
    * @param $table
    * @return array
    */
   public function generateRelationsFields($table)
   {

      $relatedList = [];

      $db = $this->getDbConnection();

      // Exist rules for foreign keys
      foreach ($table->foreignKeys as $refs) {

         $refTable = $refs[0];
         $refTableSchema = $db->getTableSchema($refTable);
         if ($refTableSchema === null) {
            // Foreign key could point to non-existing table: https://github.com/yiisoft/yii2-gii/issues/34
            continue;
         }

         $targetAttributes = [];
         foreach ($refs as $key => $value) {
            $targetAttributes[] = [$key, $value];
         }

         $className = $this->ns . '\\' . $this->generateClassName($refTable);
         $relatedList[$targetAttributes[1][0]] = [
            'table' => $refs[0],
            'class' => $className,
            'key' => $targetAttributes[1][1]
         ];
      }

      return $relatedList;

   }

   /**
    * Generates the attribute labels for the specified table.
    * @param \yii\db\TableSchema $table the table schema
    * @return array the generated attribute labels (name => label)
    */
   public function generateLabels($table)
   {
      $labels = [];
      foreach ($table->columns as $column) {
         if ($this->generateLabelsFromComments && !empty($column->comment)) {
            $labels[$column->name] = $column->comment;
         } elseif (!strcasecmp($column->name, 'id')) {
            $labels[$column->name] = 'ID';
         } else {
            $label = Inflector::camel2words($column->name);
            if (!empty($label) && substr_compare($label, ' id', -3, 3, true) === 0) {
               $label = substr($label, 0, -3) . ' ID';
            }
            $labels[$column->name] = $label;
         }
      }

      return $labels;
   }

   /**
    * Generates validation rules for the specified table.
    * @param \yii\db\TableSchema $table the table schema
    * @return array the generated validation rules
    */
   public function generateRules($table)
   {
      $types = [];
      $lengths = [];
      foreach ($table->columns as $column) {
         if ($column->autoIncrement) {
            continue;
         }
         if (!$column->allowNull && $column->defaultValue === null) {
            $types['required'][] = $column->name;
         }
         if (!$column->isPrimaryKey && $column->name !== 'user') $types['safe'][] = $column->name;
         switch ($column->type) {
            case Schema::TYPE_SMALLINT:
            case Schema::TYPE_INTEGER:
            case Schema::TYPE_BIGINT:
               $types['integer'][] = $column->name;
               break;
            case Schema::TYPE_BOOLEAN:
               $types['boolean'][] = $column->name;
               break;
            case Schema::TYPE_FLOAT:
            case 'double': // Schema::TYPE_DOUBLE, which is available since Yii 2.0.3
            case Schema::TYPE_DECIMAL:
            case Schema::TYPE_MONEY:
               $types['number'][] = $column->name;
               break;
            case Schema::TYPE_DATE:
            case Schema::TYPE_TIME:
            case Schema::TYPE_DATETIME:
            case Schema::TYPE_TIMESTAMP:
               //$types['safe'][] = $column->name;
               break;
            case Schema::TYPE_JSON:
               break;
            default: // strings
               if ($column->size > 0) {
                  $lengths[$column->size][] = $column->name;
               } else {
                  $types['string'][] = $column->name;
               }
         }
      }
      $rules = [];
      foreach ($types as $type => $columns) {
         $rules[] = "[['" . implode("', '", $columns) . "'], '$type']";
      }
      foreach ($lengths as $length => $columns) {
         $rules[] = "[['" . implode("', '", $columns) . "'], 'string', 'max' => $length]";
      }

      $db = $this->getDbConnection();

      // Unique indexes rules
      try {
         $uniqueIndexes = $db->getSchema()->findUniqueIndexes($table);
         foreach ($uniqueIndexes as $uniqueColumns) {
            // Avoid validating auto incremental columns
            if (!$this->isColumnAutoIncremental($table, $uniqueColumns)) {
               $attributesCount = count($uniqueColumns);

               if ($attributesCount === 1) {
                  $rules[] = "[['" . $uniqueColumns[0] . "'], 'unique']";
               } elseif ($attributesCount > 1) {
                  $labels = array_intersect_key($this->generateLabels($table), array_flip($uniqueColumns));
                  $lastLabel = array_pop($labels);
                  $columnsList = implode("', '", $uniqueColumns);
                  $rules[] = "[['$columnsList'], 'unique', 'targetAttribute' => ['$columnsList'], 'message' => 'The combination of " . implode(', ', $labels) . " and $lastLabel has already been taken.']";
               }
            }
         }
      } catch (NotSupportedException $e) {
         // doesn't support unique indexes information...do nothing
      }

      // Exist rules for foreign keys
      foreach ($table->foreignKeys as $refs) {
         $refTable = $refs[0];
         $refTableSchema = $db->getTableSchema($refTable);
         if ($refTableSchema === null) {
            // Foreign key could point to non-existing table: https://github.com/yiisoft/yii2-gii/issues/34
            continue;
         }
         $refClassName = $this->generateClassName($refTable);
         unset($refs[0]);
         $attributes = implode("', '", array_keys($refs));
         $targetAttributes = [];
         foreach ($refs as $key => $value) {
            $targetAttributes[] = "'$key' => '$value'";
         }
         $targetAttributes = implode(', ', $targetAttributes);
         $rules[] = "[['$attributes'], 'exist', 'skipOnError' => true, 'targetClass' => $refClassName::class, 'targetAttribute' => [$targetAttributes]]";
      }


      return $rules;
   }

   /**
    * Generates relations using a junction table by adding an extra viaTable().
    * @param \yii\db\TableSchema the table being checked
    * @param array $fks obtained from the checkJunctionTable() method
    * @param array $relations
    * @return array modified $relations
    */
   private function generateManyManyRelations($table, $fks, $relations)
   {
      $db = $this->getDbConnection();

      foreach ($fks as $pair) {
         list($firstKey, $secondKey) = $pair;
         $table0 = $firstKey[0];
         $table1 = $secondKey[0];
         unset($firstKey[0], $secondKey[0]);
         $className0 = $this->generateClassName($table0);
         $className1 = $this->generateClassName($table1);
         $table0Schema = $db->getTableSchema($table0);
         $table1Schema = $db->getTableSchema($table1);

         $link = $this->generateRelationLink(array_flip($secondKey));
         $viaLink = $this->generateRelationLink($firstKey);
         $relationName = $this->generateRelationName($relations, $table0Schema, key($secondKey), true);
         $relations[$table0Schema->fullName][$relationName] = [
            "return \$this->hasMany($className1::className(), $link)->viaTable('"
            . $this->generateTableName($table->name) . "', $viaLink);",
            $className1,
            true,
         ];

         $link = $this->generateRelationLink(array_flip($firstKey));
         $viaLink = $this->generateRelationLink($secondKey);
         $relationName = $this->generateRelationName($relations, $table1Schema, key($firstKey), true);
         $relations[$table1Schema->fullName][$relationName] = [
            "return \$this->hasMany($className0::className(), $link)->viaTable('"
            . $this->generateTableName($table->name) . "', $viaLink);",
            $className0,
            true,
         ];
      }

      return $relations;
   }

   /**
    * @return string[] all db schema names or an array with a single empty string
    * @throws NotSupportedException
    * @since 2.0.5
    */
   protected function getSchemaNames()
   {
      $db = $this->getDbConnection();
      $schema = $db->getSchema();
      if ($schema->hasMethod('getSchemaNames')) { // keep BC to Yii versions < 2.0.4
         try {
            $schemaNames = $schema->getSchemaNames();
         } catch (NotSupportedException $e) {
            // schema names are not supported by schema
         }
      }
      if (!isset($schemaNames)) {
         if (false && ($pos = strpos($this->tableName, '.')) !== false) {
            $schemaNames = [substr($this->tableName, 0, $pos)];
         } else {
            $schemaNames = [''];
         }
      }
      return $schemaNames;
   }

   /**
    * @return array the generated relation declarations
    */
   protected function generateRelations()
   {
      if ($this->generateRelations === self::RELATIONS_NONE) {
         return [];
      }

      $db = $this->getDbConnection();

      $relations = [];
      foreach ($this->getSchemaNames() as $schemaName) {
         foreach ($db->getSchema()->getTableSchemas($schemaName) as $table) {
            $className = $this->generateClassName($table->fullName);
            foreach ($table->foreignKeys as $refs) {
               $refTable = $refs[0];
               $refTableSchema = $db->getTableSchema($refTable);
               if ($refTableSchema === null) {
                  // Foreign key could point to non-existing table: https://github.com/yiisoft/yii2-gii/issues/34
                  continue;
               }
               unset($refs[0]);
               $fks = array_keys($refs);
               $refClassName = $this->generateClassName($refTable);

               // Добавляем колонки связанных таблиц в выдачу
               $this->full_relations[$table->fullName][$fks[0]] = ['columns' => $refTableSchema->columns, 'tableModel' => $refClassName];

               // Add relation for this table
               $link = $this->generateRelationLink(array_flip($refs));
               $relationName = $this->generateRelationName($relations, $table, $fks[0], false);
               $relations[$table->fullName][$relationName] = [
                  "return \$this->hasOne($refClassName::className(), $link);",
                  $refClassName,
                  false,
               ];

               // Add relation for the referenced table
               $hasMany = $this->isHasManyRelation($table, $fks);
               $link = $this->generateRelationLink($refs);
               $relationName = $this->generateRelationName($relations, $refTableSchema, $className, $hasMany);
               $relations[$refTableSchema->fullName][$relationName] = [
                  "return \$this->" . ($hasMany ? 'hasMany' : 'hasOne') . "($className::className(), $link);",
                  $className,
                  $hasMany,
               ];
            }

            if (($junctionFks = $this->checkJunctionTable($table)) === false) {
               continue;
            }

            $relations = $this->generateManyManyRelations($table, $junctionFks, $relations);
         }
      }

      if ($this->generateRelations === self::RELATIONS_ALL_INVERSE) {
         return $this->addInverseRelations($relations);
      }

      return $relations;
   }

   /**
    * Adds inverse relations
    *
    * @param array $relations relation declarations
    * @return array relation declarations extended with inverse relation names
    * @since 2.0.5
    */
   protected function addInverseRelations($relations)
   {
      $relationNames = [];
      foreach ($this->getSchemaNames() as $schemaName) {
         foreach ($this->getDbConnection()->getSchema()->getTableSchemas($schemaName) as $table) {
            $className = $this->generateClassName($table->fullName);
            foreach ($table->foreignKeys as $refs) {
               $refTable = $refs[0];
               $refTableSchema = $this->getDbConnection()->getTableSchema($refTable);
               unset($refs[0]);
               $fks = array_keys($refs);

               $leftRelationName = $this->generateRelationName($relationNames, $table, $fks[0], false);
               $relationNames[$table->fullName][$leftRelationName] = true;
               $hasMany = $this->isHasManyRelation($table, $fks);
               $rightRelationName = $this->generateRelationName(
                  $relationNames,
                  $refTableSchema,
                  $className,
                  $hasMany
               );
               $relationNames[$refTableSchema->fullName][$rightRelationName] = true;

               $relations[$table->fullName][$leftRelationName][0] =
                  rtrim($relations[$table->fullName][$leftRelationName][0], ';')
                  . "->inverseOf('" . lcfirst($rightRelationName) . "');";
               $relations[$refTableSchema->fullName][$rightRelationName][0] =
                  rtrim($relations[$refTableSchema->fullName][$rightRelationName][0], ';')
                  . "->inverseOf('" . lcfirst($leftRelationName) . "');";
            }
         }
      }
      return $relations;
   }

   /**
    * Determines if relation is of has many type
    *
    * @param TableSchema $table
    * @param array $fks
    * @return boolean
    * @since 2.0.5
    */
   protected function isHasManyRelation($table, $fks)
   {
      $uniqueKeys = [$table->primaryKey];
      try {
         $uniqueKeys = array_merge($uniqueKeys, $this->getDbConnection()->getSchema()->findUniqueIndexes($table));
      } catch (NotSupportedException $e) {
         // ignore
      }
      foreach ($uniqueKeys as $uniqueKey) {
         if (count(array_diff(array_merge($uniqueKey, $fks), array_intersect($uniqueKey, $fks))) === 0) {
            return false;
         }
      }
      return true;
   }

   /**
    * Generates the link parameter to be used in generating the relation declaration.
    * @param array $refs reference constraint
    * @return string the generated link parameter.
    */
   protected function generateRelationLink($refs)
   {
      $pairs = [];
      foreach ($refs as $a => $b) {
         $pairs[] = "'$a' => '$b'";
      }

      return '[' . implode(', ', $pairs) . ']';
   }

   /**
    * Checks if the given table is a junction table, that is it has at least one pair of unique foreign keys.
    * @param \yii\db\TableSchema the table being checked
    * @return array|boolean all unique foreign key pairs if the table is a junction table,
    * or false if the table is not a junction table.
    */
   protected function checkJunctionTable($table)
   {
      if (count($table->foreignKeys) < 2) {
         return false;
      }
      $uniqueKeys = [$table->primaryKey];
      try {
         $uniqueKeys = array_merge($uniqueKeys, $this->getDbConnection()->getSchema()->findUniqueIndexes($table));
      } catch (NotSupportedException $e) {
         // ignore
      }
      $result = [];
      // find all foreign key pairs that have all columns in an unique constraint
      $foreignKeys = array_values($table->foreignKeys);
      for ($i = 0; $i < count($foreignKeys); $i++) {
         $firstColumns = $foreignKeys[$i];
         unset($firstColumns[0]);

         for ($j = $i + 1; $j < count($foreignKeys); $j++) {
            $secondColumns = $foreignKeys[$j];
            unset($secondColumns[0]);

            $fks = array_merge(array_keys($firstColumns), array_keys($secondColumns));
            foreach ($uniqueKeys as $uniqueKey) {
               if (count(array_diff(array_merge($uniqueKey, $fks), array_intersect($uniqueKey, $fks))) === 0) {
                  // save the foreign key pair
                  $result[] = [$foreignKeys[$i], $foreignKeys[$j]];
                  break;
               }
            }
         }
      }
      return empty($result) ? false : $result;
   }

   /**
    * Generate a relation name for the specified table and a base name.
    * @param array $relations the relations being generated currently.
    * @param \yii\db\TableSchema $table the table schema
    * @param string $key a base name that the relation name may be generated from
    * @param boolean $multiple whether this is a has-many relation
    * @return string the relation name
    */
   protected function generateRelationName($relations, $table, $key, $multiple)
   {
      if (!empty($key) && substr_compare($key, 'id', -2, 2, true) === 0 && strcasecmp($key, 'id')) {
         $key = rtrim(substr($key, 0, -2), '_');
      }
      if ($multiple) {
         $key = Inflector::pluralize($key);
      }
      $name = $rawName = Inflector::id2camel($key, '_');
      $i = 0;
      while (isset($table->columns[lcfirst($name)])) {
         $name = $rawName . ($i++);
      }
      while (isset($relations[$table->fullName][$name])) {
         $name = $rawName . ($i++);
      }

      return $name;
   }

   /**
    * Validates the [[db]] attribute.
    */
   public function validateDb()
   {
      if (!Yii::$app->has($this->db)) {
         $this->addError('db', 'There is no application component named "db".');
      } elseif (!Yii::$app->get($this->db) instanceof Connection) {
         $this->addError('db', 'The "db" application component must be a DB connection instance.');
      }
   }

   /**
    * Validates the namespace.
    *
    * @param string $attribute Namespace variable.
    */
   public function validateNamespace($attribute)
   {
      $value = $this->$attribute;
      $value = ltrim($value, '\\');
      $path = Yii::getAlias('@' . str_replace('\\', '/', $value), false);
      if ($path === false) {
         $this->addError($attribute, 'Namespace must be associated with an existing directory.');
      }
   }

   /**
    * Validates the [[modelClass]] attribute.
    */
   public function validateModelClass()
   {
      if ($this->isReservedKeyword($this->modelClass)) {
         $this->addError('modelClass', 'Class name cannot be a reserved PHP keyword.');
      }
      if ((empty($this->tableName) || substr_compare($this->tableName, '*', -1, 1)) && $this->modelClass == '') {
         $this->addError('modelClass', 'Model Class cannot be blank if table name does not end with asterisk.');
      }
   }

   /**
    * Validates the [[tableName]] attribute.
    */
   public function validateTableName()
   {
      if ($this->tableName === '*') {
         $allTables = $this->getDbConnection()->getSchema()->tableNames;
         $this->tableName = $allTables;
      }
      if (count($this->tableName) == 0) {
         $this->addError('tableName', 'Должна быть выбрана хотя бы одна таблица');
         return;
      }
      $tables = $this->getTableNames();
      if (empty($tables)) {
         $this->addError('tableName', "Таблиц с такими именами не существует");
      } else {
         foreach ($tables as $table) {
            $class = $this->generateClassName($table);
            if ($this->isReservedKeyword($class)) {
               $this->addError('tableName', "Таблица $table, класс который будет '$class' является зарезервированным ключевым словом в PHP ");
               break;
            }
         }
      }
   }

   protected $tableNames;
   protected $classNames;

   /**
    * @return array the table names that match the pattern specified by [[tableName]].
    */
   protected function getTableNames()
   {
      if ($this->tableNames !== null) {
         return $this->tableNames;
      }
      $db = $this->getDbConnection();
      if ($db === null) {
         return [];
      }
      $tableNames = [];

      foreach ($this->tableName as $mytablename) {

         if (strpos($mytablename, '*') !== false) {
            if (($pos = strrpos($mytablename, '.')) !== false) {
               $schema = substr($mytablename, 0, $pos);
               $pattern = '/^' . str_replace('*', '\w+', substr($mytablename, $pos + 1)) . '$/';
            } else {
               $schema = '';
               $pattern = '/^' . str_replace('*', '\w+', $mytablename) . '$/';
            }

            foreach ($db->schema->getTableNames($schema) as $table) {
               if (preg_match($pattern, $table)) {
                  $tableNames[] = $schema === '' ? $table : ($schema . '.' . $table);
               }
            }
         } elseif (($table = $db->getTableSchema($mytablename, true)) !== null) {
            $tableNames[] = $mytablename;
            $this->classNames[$mytablename] = $this->modelClass;
         }

      }

      return $this->tableNames = $tableNames;
   }

   /**
    * Generates the table name by considering table prefix.
    * If [[useTablePrefix]] is false, the table name will be returned without change.
    * @param string $tableName the table name (which may contain schema prefix)
    * @return string the generated table name
    */
   public function generateTableName($tableName)
   {
      if (!$this->useTablePrefix) {
         return $tableName;
      }

      $db = $this->getDbConnection();
      if (preg_match("/^{$db->tablePrefix}(.*?)$/", $tableName, $matches)) {
         $tableName = '{{%' . $matches[1] . '}}';
      } elseif (preg_match("/^(.*?){$db->tablePrefix}$/", $tableName, $matches)) {
         $tableName = '{{' . $matches[1] . '%}}';
      }
      return $tableName;
   }

   /**
    * Generates a class name from the specified table name.
    * @param string $tableName the table name (which may contain schema prefix)
    * @param boolean $useSchemaName should schema name be included in the class name, if present
    * @return string the generated class name
    */
   protected function generateClassName($tableName, $useSchemaName = null)
   {
      if (isset($this->classNames[$tableName])) {
         return $this->classNames[$tableName];
      }

      $schemaName = '';
      $fullTableName = $tableName;
      if (($pos = strrpos($tableName, '.')) !== false) {
         if (($useSchemaName === null && $this->useSchemaName) || $useSchemaName) {
            $schemaName = substr($tableName, 0, $pos) . '_';
         }
         $tableName = substr($tableName, $pos + 1);
      }

      $db = $this->getDbConnection();
      $patterns = [];
      $patterns[] = "/^{$db->tablePrefix}(.*?)$/";
      $patterns[] = "/^(.*?){$db->tablePrefix}$/";

      $className = $tableName;
      foreach ($patterns as $pattern) {
         if (preg_match($pattern, $tableName, $matches)) {
            $className = $matches[1];
            break;
         }
      }

      return $this->classNames[$fullTableName] = Inflector::id2camel($schemaName . $className, '_');
   }

   /**
    * Generates a query class name from the specified model class name.
    * @param string $modelClassName model class name
    * @return string generated class name
    */
   protected function generateQueryClassName($modelClassName)
   {
      $queryClassName = $this->queryClass;
      if (empty($queryClassName) || strpos($this->tableName, '*') !== false) {
         $queryClassName = $modelClassName . 'Query';
      }
      return $queryClassName;
   }

   /**
    * @return Connection the DB connection as specified by [[db]].
    */
   protected function getDbConnection()
   {
      return Yii::$app->get($this->db, false);
   }

   /**
    * Checks if any of the specified columns is auto incremental.
    * @param \yii\db\TableSchema $table the table schema
    * @param array $columns columns to check for autoIncrement property
    * @return boolean whether any of the specified columns is auto incremental.
    */
   protected function isColumnAutoIncremental($table, $columns)
   {
      foreach ($columns as $column) {
         if (isset($table->columns[$column]) && $table->columns[$column]->autoIncrement) {
            return true;
         }
      }

      return false;
   }
}
