<?php

namespace flameart\rest\controllers;

// Imports
use common\models\behaviors\RelationBehaviors\MaterializedPathBehavior;
use yii\db\Query;
use yii\filters\AccessControl;
use yii\helpers\Json;
use yii\rest\ActiveController;
use yii\data\ActiveDataProvider;
use \yii\db\ActiveRecord;
use yii\db\ActiveQuery;
use yii\db\Expression;
use yii\web\NotFoundHttpException;
use yii\base\ErrorException;



/**
 * REST API Controller Objects */
class ActiveRestController extends ActiveController
{

   public $AUTH_ENABLED = true;

   /**
    * @var array Поля, которые не надо выводить при запросе
    */
   public $filterFields = [];

   /**
    * @var array Поля, которые надо расширить
    */
   public $extendFields = [];

   /**
    * @var array[] По-умолчанию всё могут смотреть все авторизованные, изменять только админы [если включена авторизация]
    */
   public $roles = [
      [
         'allow' => true,
         'actions' => ['view', 'index'],
         'roles' => ['?', '@'],
      ],
      [
         'allow' => true,
         'roles' => ['MainUser', 'Admin'],
      ],
      [
         'allow' => true,
         'actions' => ['create', 'update', 'delete'],
         'roles' => ['Admin'],
      ],
   ];

   /**
    * @var array Прокешировать общее число страниц для следующих контроллеров на сутки
    */
   public $cachePaginationForPages = [];

   /**
    * Добавляем к стандартным собственный обработчик
    * @return array
    */
   public function actions()
   {
      $this->AUTH_ENABLED = \Yii::$app->params['AUTH'];
      $actions = parent::actions();
      $actions['index']['prepareDataProvider'] = [$this, 'prepareDataProvider'];
      $actions['create']= [
         'class' => 'common\models\behaviors\RelationBehaviors\actions\CreateAction',
         'modelClass' => $this->modelClass,
         'checkAccess' => [$this, 'checkAccess'],
         'scenario' => $this->createScenario,
      ];
      $actions['update']= [
         'class' => 'common\models\behaviors\RelationBehaviors\actions\UpdateAction',
         'modelClass' => $this->modelClass,
         'checkAccess' => [$this, 'checkAccess'],
         'scenario' => $this->updateScenario,
      ];
      $actions['delete']= [
         'class' => 'common\models\behaviors\RelationBehaviors\actions\DeleteAction',
         'modelClass' => $this->modelClass,
         'checkAccess' => [$this, 'checkAccess'],
      ];
      return $actions;
   }

   /**
    * Добавляем возможность запостить json в index action
    * @return array
    */
   protected function verbs()
   {
      $verbs = parent::verbs();
      $verbs['index'] = ['GET', 'HEAD', 'POST'];
      return $verbs;
   }

   // Поведедение для авторизованных юзеров или нет
   public function behaviors()
   {

      $behaviors = parent::behaviors();

      $behaviors['access'] = [
         'class' => AccessControl::class,
         'rules' => $this->AUTH_ENABLED ? $this->roles : [
            [
               'allow' => true,
               'actions' => ['index', 'view'],
               'roles' => ['?'],
            ],
         ],
      ];

      // Проверяем доступы к полям при редактировании и создании записи
      // Правило всегда применяется первым и отдаёт false, чтобы шёл по вручную прописанным правилам, или throw Forbidden в случае запрета на редактирование
      if ($this->AUTH_ENABLED)
         $behaviors['access']['rules'] = array_merge([[
            'allow' => true,
            'actions' => ['create', 'update'],
            'matchCallback' => function ($rule, $action) {
               // Всегда возвращаем false, что правило не применено, чтобы он в случае отсутствия исключения шёл по правилам дальше
               return false;
            }
         ]], $behaviors['access']['rules']);


      return $behaviors;
   }

   public function prepareDataProvider()
   {

      // TODO: get One запись надо убрать все лишние запросы на count итд
      // SEC: запрос поля типа звёздочка

      ini_set('memory_limit', -1);
      set_time_limit(-1);

      // Отдаём заголовки, чтобы можно было принять паджинацию
      header("Access-Control-Expose-Headers: X-Pagination-Current-Page,X-Pagination-Per-Page,X-Pagination-Page-Count,X-Pagination-Total-Count");

      // Данные
      $data = Json::decode(\Yii::$app->request->getRawBody(), true);

      // Данные таблиц
      $AllTables = require(\Yii::getAlias("@common") . '/models/DB/models/Tables.php');

      /**
       * Экземпляр модели
       * @var $DBModel ActiveRecord
       */
      $ModelClass = ($this->modelClass);
      $DBModel = new $ModelClass();

      /**
       * Создаём новый поиск
       * @var $DB Query
       */
      $current_table = $DBModel::tableName();
      $DB = (new Query())->from($current_table);

      // Если указано получение конкретного дерева по ID
      $approvedItems = [];

      // TODO: предусмотрена возможность получения мультидеревьев, но нужен рекурсивный анализ всей вложенности $data[tree]
      if(isset($data['tree'])) {

         $MPBehavior = $DBModel->behaviors()["MaterializedPath"];
         $MPBehavior['itemAttribute'] = $AllTables[$current_table]['primaryKey'][0];
         $DB->attachBehavior('mp', $MPBehavior);

         // Тут нужно найти корневой элемент юзера [он может быть только один]
         if(intval($data['tree']) === 0) {
            $node = $DBModel->find()->andWhere([$DBModel->USER_ATTRIBUTE => \Yii::$app->user->id, $DBModel->behaviors['MaterializedPath']->depthAttribute => 0])->one();
            // Если корневого элемента нет - создаём его
            if($node === null) {
               $node = new $this->modelClass();
               $node->makeRoot();
               $node->save();
            }
         }
         else
            $node = $DBModel::findOne(['id' =>$data['tree']]);

         if($node === null) throw new NotFoundHttpException("Tree id not found");

         $newq = $node->getDescendants();
         $DB->where($newq->where)->orderBy($newq->orderBy);

         $approvedItems[] = $current_table .".". $MPBehavior['pathAttribute'];
         $approvedItems[] = $current_table .".". $MPBehavior['depthAttribute'];
         $approvedItems[] = $current_table .".". $MPBehavior['treeAttribute'];
         $approvedItems[] = $current_table .".". $MPBehavior['itemAttribute'];
         $approvedItems[] = $current_table .".". $MPBehavior['sortable']['sortAttribute'];

      }

      // Список полей
      $DBFields = $DBModel::tableFields();

      // Сортировка
      if (isset($data['sort'])) {

         // Спилитим несколько параметров
         $tsort = $data['sort'];
         if (is_string($tsort)) $tsort = explode(',', $tsort);

         // Фильтруем допустимые поля сортировки
         $forFilterItems = [];
         foreach ($tsort as $sortitem) $forFilterItems[] = substr($sortitem, 0, 1) === '-' ? substr($sortitem, 1) : $sortitem;
         $filteredFields = $DBModel->filterFieldsByRole('view', $DBModel, false, $forFilterItems, $approvedItems);

         foreach ($tsort as $sortitem) {
            if (substr($sortitem, 0, 1) === '-') {
               if (in_array(substr($sortitem, 1), $filteredFields))
                  $DB->addOrderBy([$current_table.".".substr($sortitem, 1) => SORT_DESC]);
            } elseif (in_array($sortitem, $filteredFields))
               $DB->addOrderBy([$current_table.".".$sortitem => SORT_ASC]);
         }
      }

      // Удалить дубликаты, но нужно указывать одно поле в Fields
      if (isset($data['RemoveDuplicates']))
         $DB->distinct();

      // Паджинация
      // TODO: более эффективная паджинация без offset, а через id>300 LIMIT 10
      $pagination = [];
      if (isset($data['page']))
         $pagination['page'] = (int)($data['page']) - 1;
      if (isset($data['per-page'])) {
         $pagination['pageSize'] = (int)$data['per-page'];
         if ($pagination['pageSize'] > 1000) $pagination['pageSize'] = 1000;
      }

      // Поля
      $RelatedFields = [];
      $RelatedTables = [];
      $hasRelation = false;

      // По-умолчанию получаем все столбцы с доступами
      if(!isset($data['fields']))
         $data['fields'] = $DBModel->filterFieldsByRole('view', $DBModel, false, array_keys($DBModel->tableFields()), $approvedItems);

      // Объединяем поля с extfields
      if(isset($data['extfields'])) {
         foreach ($data['extfields'] as $fieldName=>$fieldVal)
            if(!is_numeric($fieldName))
               $data['fields'][$fieldName] = $fieldVal;
            else
               $data['fields'][$fieldVal] = array_keys($AllTables[$AllTables[$DBModel::tableName()]['related'][$fieldVal]['table']]['columns']);
      }

      // Указываем отдельные поля
      if (isset($data['fields']) && is_array($data['fields'])) {

         $selectedFields = [];
         $joinFields = [];
         $relatedTables = [];
         $joinAliasesAll = []; // пара "путь до ключа"-"номер алиаса"


         // Ассоциативный массив или номерной
         $is_assoc = function (array $arr) {
            if (array() === $arr) return false;
            return array_keys($arr) !== range(0, count($arr) - 1);
         };

         $recursive_join = function ($fields, $table, $tableAlias = null, $joinPath = "") use (&$selectedFields, &$joinFields, &$DBModel, &$hasRelation, &$recursive_join, &$is_assoc, &$joinAliasesAll) {

            foreach ($fields as $key => $value) {

               if (is_numeric($key)) {
                  $selectedFields[($tableAlias ?? "") . ("___" . $table::tableName() . "___" . $value)] = $table::tableName() . "." . $value;
               } elseif (is_string($key) && is_string($value)) {
                  $selectedFields[($tableAlias ?? "") . ("___" . $table::tableName() . "___" . $key) ] = $table::tableName() . "." . $key;
               } // релятивные поля
               elseif (is_array($value)) {

                  $relatedTable = $table->relatedFields()[$key];
                  $classTable = (new $relatedTable['class']);

                  $joinAlias = "`__Alias__".count($joinFields)."__".$relatedTable['table']."__`";
                  $joinPath = $joinPath.".".$key;
                  $joinAliasesAll[$joinPath]=count($joinFields);

                  $joinFields[] = [
                     $key,

                     // Обычный вариант для проверки полей
                     $relatedTable['table'],
                     "`" . $table::tableName() . "`." . $key . "=" . "`" . $relatedTable['table'] . "`." . $relatedTable['key'],
                     "`" . $relatedTable['table'] . "`." . $relatedTable['key'],

                     // Вариант с алиасами для запроса
                     $joinAlias,
                     "`" . ($tableAlias ?? $table::tableName()) . "`." . $key . "=" . $joinAlias . "." . $relatedTable['key'],
                     $joinAlias . "." . $relatedTable['key'],

                  ];

                  $hasRelation = true;

                  $recursive_join($value, $classTable, str_replace("`", "", $joinAlias), $joinPath);

               } //обычные поля вместе с релативными
               else {
                  $selectedFields[$table::tableName() . "." . $key] = "___" . $table::tableName() . "___" . $key;
               }
            }

         };

         $recursive_join($data['fields'], $DBModel);

         // Добавляем поля с фильтрацией тех, к которым есть доступ
         $filteredFields = $DBModel->filterFieldsByRole('view', $DBModel, false, array_values($selectedFields), $approvedItems);
         $filteredFieldsArr = [];
         foreach ($selectedFields as $keyField => $field) {
            if(!in_array($field, $filteredFields)) continue;
            if(strpos($keyField, "__Alias__") === false)
               $filteredFieldsArr[$keyField] = $selectedFields[$keyField];
            else
               $filteredFieldsArr[$keyField] = "`".explode("___", "___".$keyField)[1] . "__`." . explode(".", $selectedFields[$keyField])[1];
         }

         $DB->select($filteredFieldsArr);

         // После фильтрации делаем join только одобренных полей
         foreach ($joinFields as $keyF=>$field)
            if (in_array(str_replace("`", "", $field[3]), $filteredFields))
               $DB->leftJoin($field[1] . " " . $field[4], $field[5], []);

      }


      $recursive_output = function (&$format, &$row, &$filledRow, &$current_table, &$rowTables, &$tree_relates, &$filledLinks, &$isAppend, $joinPath) use (&$recursive_output, &$AllTables, &$joinAliasesAll) {

         foreach ($format as $key => $value) {
            if (is_array($value)) {
               $filledRow[$key] = [];
               $joinPath = $joinPath.".".$key;
               $recursive_output($value, $row, $filledRow[$key], $AllTables[$current_table]['related'][$key]['table'], $rowTables, $tree_relates, $filledLinks, $isAppend, $joinPath);
            } else {
               $key = is_numeric($key) ? $value : $key;

               // find alias
               $findedTable = $current_table;
               if(strlen($joinPath)>0) {

                  $findedAliasN = array_key_exists($joinPath, $joinAliasesAll) ? $joinAliasesAll[$joinPath] : null;
                  if($findedAliasN === null) throw  new ErrorException("Неизвестное поведение при подборе алиаса");

                  foreach ($rowTables as $rtKey=>$rtValue) {
                     if(strpos($rtKey, "__Alias__")!== false) {
                        $splittedAlias = explode("__", $rtKey);
                        if(intval($splittedAlias[2]) === $findedAliasN) {
                           $findedTable = $rtKey;
                           break;
                        }

                     }
                  }
               }


               if (isset($rowTables[$findedTable]) && array_key_exists($key,$rowTables[$findedTable])) {
                  $filledRow[$key] = $rowTables[$findedTable][$key];
                  if($AllTables[$current_table]['columns'][$key]==='json')
                     try {
                        $filledRow[$key] = Json::decode($filledRow[$key], true);
                     }
                     catch(\Exception $ex){
                        $filledRow = null;
                     }
               }
            }
         }

         $id = $row[$current_table.".".$AllTables[$current_table]['primaryKey'][0]] ?? null;
         $filledLinks[$id] = &$filledRow;

         if(isset($row['m_parent']) && isset($filledLinks[$row['m_parent']])) {
            if(!isset($filledLinks[$row['m_parent']]['children'])) $filledLinks[$row['m_parent']]['children'] = [];
            $filledLinks[$row['m_parent']]['children'][] = &$filledRow;
            $isAppend = false;
         }
         if(isset($row['m_parent']))
            $filledRow['m_parent'] = $row['m_parent'];

         return $filledRow;
      };

      $this->response->on('beforeSend', function ($res) use (&$MPBehavior, &$AllTables, &$data, &$recursive_output, &$current_table, &$joinAliasesAll) {

         // Преобразуем колонки с реляциями в один join запрос в соответствующий запросу бесконечно вложенный формат
         $items = $res->sender->data;
         $datagraph = [];

         if($res->sender->statusCode < 400) {

            // Если режим отображения дерева, то отображаем его
            $tree_relates = [];
            $minDepth = -1;
            if(isset($data['tree'])) {
               foreach ($items as &$row) {
                  $path = MaterializedPathBehavior::getParentPathInternal($row["___" . $current_table . "___" . [$MPBehavior['pathAttribute']][0]], ".", true);
                  $key = array_pop($path);
                  if (!isset($tree_relates[$current_table])) $tree_relates[$current_table] = [];
                  if (!isset($tree_relates[$current_table][$key])) $tree_relates[$current_table][$key] = [];
                  $tree_relates[$current_table][$key][] = &$row;
                  $row['m_parent'] = $key;
                  if($minDepth === -1 || $minDepth>intval($row["___" . $current_table . "___" .$MPBehavior['depthAttribute']])) $minDepth = intval($row["___" . $current_table . "___" .$MPBehavior['depthAttribute']]);
               }
            }


            $filledLinks = [];

            foreach ($items as $rowkey => &$row) {

               // Заполняем все поля этой строчки
               $rowTables = [];
               $thisRow = [];
               foreach ($row as $key => &$value) {
                  $splitted = explode("___", $key);
                  if (count($splitted) > 1) {
                     if(strpos($key, "__Alias__") === false) {
                        $rowTables[$splitted[1]][$splitted[2]] = &$value;
                        $thisRow[$splitted[1] . "." . $splitted[2]] = &$value;
                     }
                     else {
                        $splittedAlias = explode("__", $splitted[0]);
                        $rowTables[$splitted[0]][$splitted[2]] = &$value;
                        $thisRow[$splitted[1] . "." . $splitted[2]] = &$value;
                     }
                  }
                  elseif ($key === 'm_parent')
                     $thisRow[$key] = $value;
               }

               if ($MPBehavior !== null && isset($tree_relates[$current_table][$thisRow[$current_table.".".$MPBehavior['itemAttribute']]])) {
                  $thisRow['children'] = &$tree_relates[$current_table][$thisRow[$current_table.".".$MPBehavior['itemAttribute']]];
               }

               // Заполняем модель
               $result = []; $isAppend = true;
               $recursive_output($data['fields'], $thisRow, $result, $current_table, $rowTables, $tree_relates, $filledLinks, $isAppend, "");

               if($isAppend)
                  $datagraph[] = &$result;

               unset($result);

            }

            // После организации всех join, если режим дерева, то вставляем детей каждого элемента

            $res->sender->data = $datagraph;
         }

      });

      // Указываем фильтры
      if (isset($data['where'])) {

         $acceptedFields = $DBModel->filterFieldsByRole('view', $DBModel, false, array_keys($DBModel->tableFields()), $approvedItems);

         foreach ($data['where'] as $key => $value) {

            // Поиск по обычному полю
            if (!array_key_exists($key, $DBFields) || $DBFields[$key] !== 'json') {

               // Если есть extend поля, то при отсутствии указания на конкретную таблицу, нужно её указать, чтобы избежать перекрёстных where условий
               $pureKey = $key;
               if ($hasRelation) {
                  if (strpos($key, ".") === false)
                     $key = $DBModel::tableName() . "." . $key;
               }

               // Массивные значения добавляем как условия, т.к. это может быть типа LIKE или NOT IN
               if (is_array($value)) {

                  // Если указано, что это фуллтекст поиск, то ищем фуллтекстово
                  if ($value[0] === 'FULLTEXT' && in_array($value[1], $acceptedFields))
                     $DB->andWhere(new Expression("MATCH (" . \Yii::$app->db->quoteColumnName($value[1]) . ") AGAINST (:thisValue IN BOOLEAN MODE)", [':thisValue' => $value[2] . "*"]));

                  // любой другой вариант поиска
                  else
                     // TODO: тут надо бы тоже фильтровать, но придётся идти рекурсивно по всем вложенным значениям
                     $DB->andWhere($value);

               } elseif (in_array($pureKey, $acceptedFields)) // обычные значения в списке
                  $DB->andWhere([$key => $value]);


            } else {

               // Поиск по JSON-полю
               if (is_array($value) === false || count($value) === 0) continue;

               // Генерим условие ИЛИ для каждого элемента
               // Совместимо с MySQL 5.7, в 8.0 можно использовать супербыстрый оператор MEMBER OF()
               $OR = null;
               foreach ($value as $item) {
                  if (is_string($item))
                     $OR[] = new Expression("JSON_CONTAINS(" . \Yii::$app->db->quoteColumnName($key) . ",\"" . str_replace("\'", "", \Yii::$app->db->quoteValue($item) . "\")"));
                  else
                     $OR[] = new Expression("JSON_CONTAINS(" . \Yii::$app->db->quoteColumnName($key) . "," . \Yii::$app->db->quoteValue(json_encode($item)) . ")");
               }

               // Склеиваем предыдущие условия AND через внутренний OR
               $DB->andWhere(array_merge(['OR'], $OR));

            }

         }
      }

      // Добавляем в модель специфические обработчики, если нужно
      $DB = $this->ExtendQuery($DB, $data);

      // Экспорт xlsx
      if (isset($data['format'])) {
         if ($data['format'] === 'xlsx') {
            $DB->limit(null);
            $DB->offset(null);
            $export_params = [
               'class' => 'codemix\excelexport\ExcelFile',
               'fileOptions' => ['directory' => \Yii::getAlias('@runtime')],
               'sheets' => [
                  'info' => [
                     'class' => 'codemix\excelexport\ActiveExcelSheet',
                     'query' => $DB,
                     'attributes' => $data['fields'],
                     'titles' => $data['titles']
                  ]
               ]
            ];
            $export_params = $this->CustomExporter($export_params, 'info', $data['format'], $DB);
            $file = \Yii::createObject($export_params);
            \Yii::$app->response->format = \yii\web\Response::FORMAT_HTML;
            $file->send('test.xlsx');
            exit;
         }
      }

      // Отдаём ActiveDataProvider, который поддерживает авто-пагинацию и сортировку
      return new ActiveDataProviderExt([
         'query' => $DB,
         'pagination' => $pagination,
         'isCacheCount' => in_array(\Yii::$app->controller->id, $this->cachePaginationForPages)
      ]);
   }

   /**
    * Расширить поиск по модели
    * @param $model ActiveQuery
    * @param $data array json-запрос, который был получен
    * @return ActiveQuery
    */
   public function ExtendQuery($model, $data)
   {
      return $model;
   }

   /**
    * Кастомизация экспорта
    * @param $params
    * @param $default_sheetname
    * @param $format
    * @param $model
    * @return mixed
    */
   public function CustomExporter($params, $default_sheetname, $format, $model)
   {
      return $params;
   }

}