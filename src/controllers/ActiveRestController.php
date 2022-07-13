<?php

namespace flameart\rest\controllers;

// Imports
use flameart\rest\behaviors\RelationBehaviors\MaterializedPathBehavior;
use yii\db\Exception;
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
         'class' => 'flameart\rest\behaviors\RelationBehaviors\actions\CreateAction',
         'modelClass' => $this->modelClass,
         'checkAccess' => [$this, 'checkAccess'],
         'scenario' => $this->createScenario,
      ];
      $actions['update']= [
         'class' => 'flameart\rest\behaviors\RelationBehaviors\actions\UpdateAction',
         'modelClass' => $this->modelClass,
         'checkAccess' => [$this, 'checkAccess'],
         'scenario' => $this->updateScenario,
      ];
      $actions['delete']= [
         'class' => 'flameart\rest\behaviors\RelationBehaviors\actions\DeleteAction',
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
         $joinAliasesAllFormat = []; // тут оригинальные форматы вывода (до замены на фактические поля)


         // Ассоциативный массив или номерной
         $is_assoc = function (array $arr) {
            if (array() === $arr) return false;
            return array_keys($arr) !== range(0, count($arr) - 1);
         };

         $recursive_join = function (&$fields, $table, $tableAlias = null, $joinPath = "") use (&$selectedFields, &$joinFields, &$DBModel, &$hasRelation, &$recursive_join, &$is_assoc, &$joinAliasesAll, &$AllTables, &$joinAliasesAllFormat) {

            foreach ($fields as $key => $value) {

               if (is_numeric($key)) {
                  $selectedFields[($tableAlias ?? "") . ("___" . $table::tableName() . "___" . $value)] = $table::tableName() . "." . $value;
               } elseif (is_string($key) && is_string($value)) {
                  $selectedFields[($tableAlias ?? "") . ("___" . $table::tableName() . "___" . $key) ] = $table::tableName() . "." . $key;
               } // релятивные поля
               elseif (is_array($value)) {

                  $aliasesArr = [];

                  if(array_key_exists("____joinFilter____", $value) && $value['____joinFilter____']===true) {
                     $relatedTable = [
                        'table' => $value['table'],
                        'class' => $AllTables[$value['table']]['class'],
                        'key' => null,
                        'join' => $value,
                        'alias' => ''
                     ];
                  }
                  else
                     $relatedTable = $table->relatedFields()[$key];

                  $classTable = (new $relatedTable['class']);

                  $joinAlias = "`__Alias__".count($joinFields)."__".$relatedTable['table']."__`";
                  $newJoinPath = $joinPath.".".$key;
                  $joinAliasesAll[$newJoinPath]=count($joinFields);

                  $ON_param = '';
                  $ON_keys = [];

                  $joinType = "left";

                  if(isset($relatedTable['join'])) {
                     foreach ($value['on'] as $keyOn=>$valOn) {
                        if(is_array($valOn))
                           $valOn =  "`" . ($tableAlias ?? $table::tableName()) . "`." . $valOn['value'];

                        $ON_param.=" AND " . $joinAlias . "." . $keyOn . "=" . $valOn ;
                        $ON_keys[] = $value['table'] . "." . $keyOn;// TODO: тут опасное присваивание
                     }
                     $ON_param = substr($ON_param, 5);
                     $joinAliasesAllFormat[$newJoinPath] = $value;
                     $joinType = $value['type'];
                     $value = $AllTables[$value['table']]['columns'];
                     $fields[$key] = array_keys( $value );
                  }
                  else {
                     $ON_param = "`" . ($tableAlias ?? $table::tableName()) . "`." . $key . "=" . $joinAlias . "." . $relatedTable['key'];
                     $ON_keys[] = "`" . $relatedTable['table'] . "`." . $relatedTable['key'];
                  }

                  // TODO: протестить безопасность по полям на новой механике

                  $joinFields[] = [
                     $key,

                     // Обычный вариант для проверки полей
                     $relatedTable['table'],
                     "`" . $table::tableName() . "`." . $key . "=" . "`" . $relatedTable['table'] . "`." . $relatedTable['key'],
                     //"`" . $relatedTable['table'] . "`." . $relatedTable['key'],
                     $ON_keys,

                     // Вариант с алиасами для запроса
                     $joinAlias,
                     //str_replace("`","", $joinAlias),
                     $ON_param,
                     //[($tableAlias ?? $table::tableName()) . "." . $key => $joinAlias . "." . $relatedTable['key']],
                     $joinAlias . "." . $relatedTable['key'],

                     $joinType

                  ];


                  $hasRelation = true;

                  $recursive_join($value, $classTable, str_replace("`", "", $joinAlias), $newJoinPath);

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
         foreach ($joinFields as $keyF=>$field) {
            $findedJoin = true;
            foreach ($field[3] as $joinName) {
               if (!in_array(str_replace("`", "", $joinName), $filteredFields)) {
                  throw new Exception("Forbidden fields");
               }
            }
            if($findedJoin) {
               switch ($field[7]) {
                  case "left":
                     $DB->leftJoin($field[1] . " " . $field[4], $field[5], []);
                     break;
                  case "right":
                     $DB->rightJoin($field[1] . " " . $field[4], $field[5], []);
                     break;
                  case "inner":
                     $DB->innerJoin($field[1] . " " . $field[4], $field[5], []);
                     break;
               }
            }
         }

      }


      $recursive_output = function (&$format, &$row, &$filledRow, $current_table, &$rowTables, &$tree_relates, &$filledLinks, &$isAppend, $joinPath) use (&$recursive_output, &$AllTables, &$joinAliasesAll, &$joinAliasesAllFormat) {

         // find alias
         $findedTable = $current_table;
         $findedAliases = $this->findAlias($joinPath, $joinAliasesAll, $rowTables, $current_table);
         if($findedAliases !== null) {
            $findedTable = $findedAliases[0];
            $current_table = $findedAliases[1];
         }

         foreach ($format as $key => &$value) {
            if (is_array($value)) {
               $filledRow[$key] = [];
               $newJoinPath = $joinPath.".".$key;
               if (isset($rowTables[$findedTable]) && array_key_exists($key,$rowTables[$findedTable]) && $rowTables[$findedTable][$key]===null)
                  $filledRow[$key] = null;
               else {

                  // [Грубый подход] Если все записи - нули, значит связи нет
                  // т.к. поля, с которыми он должен был сопоставиться - тоже нули (вместе со всеми полями)
                  // но можно и в $joinAliasesAllFormat поискать только те поля что сопоставляли, хотя это ничего не решает, и даже лучше когда все нули - это гарантирует что записи нет даже если ключ null
                  $findedNulls = true;
                  $findedAliasesNextStep = $this->findAlias($newJoinPath, $joinAliasesAll, $rowTables, $current_table);
                  if($findedAliasesNextStep !== null) {
                     $findedTableNS = $findedAliasesNextStep[0];
                     $current_tableNS = $findedAliasesNextStep[1];
                     foreach ($rowTables[$findedTableNS] as $rtNSKey=>$rtNSVal) {
                        if($rtNSVal!==null) {$findedNulls = false; break;}
                     }
                  }
                  if($findedNulls)
                     $filledRow[$key] = null;
                  else
                     $recursive_output($value, $row, $filledRow[$key], isset($AllTables[$current_table]['related'][$key]['table']) ? $AllTables[$current_table]['related'][$key]['table'] : null, $rowTables, $tree_relates, $filledLinks, $isAppend, $newJoinPath);
               }
            } else {
               $key = is_numeric($key) ? $value : $key;

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
      $ExtendedController = 'rest\controllers\ActiveDataProviderExt';
      return new $ExtendedController([
         'query' => $DB,
         'pagination' => $pagination,
         'isCacheCount' => in_array(\Yii::$app->controller->id, $this->cachePaginationForPages)
      ]);
   }

   /**
    * Поиск алиаса join-поля
    * @param $joinPath
    * @param $joinAliasesAll
    * @param $rowTables
    * @param $current_table
    * @return array|null
    * @throws ErrorException
    */
   public function findAlias($joinPath, &$joinAliasesAll, &$rowTables, $current_table) {
      $findedTable = null;
      if(strlen($joinPath)>0) {

         $findedAliasN = array_key_exists($joinPath, $joinAliasesAll) ? $joinAliasesAll[$joinPath] : null;
         if($findedAliasN === null) throw  new ErrorException("Неизвестное поведение при подборе алиаса");

         foreach ($rowTables as $rtKey=>$rtValue) {
            if(strpos($rtKey, "__Alias__")!== false) {
               $splittedAlias = explode("__", $rtKey);
               if(intval($splittedAlias[2]) === $findedAliasN) {
                  $findedTable = $rtKey;
                  if($current_table === null) $current_table = $splittedAlias[3];
                  return [$findedTable, $current_table];
               }

            }
         }
      }
      return null;
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