<?php


namespace flameart\rest\models;

use Yii;
use yii\db\ActiveRecord;
use yii\helpers\ArrayHelper;
use yii\web\ForbiddenHttpException;
use common\models\DB\models\Tables;

class DefaultTable extends ActiveRecord
{


   // Тип ограничений
   // Белый список всех кто может что-то делать (whiteList), остальным всё запрещено
   const ACCESS_RULES_WHITE_LIST = 1;
   // Фильтрация только указанных ролей, всем не указанным ролям - всё разрешено [удобно для быстрого старта]
   const ACCESS_RULES_FILTER_LIST = 0;

   public $ACCESS_RULES_TYPE = self::ACCESS_RULES_FILTER_LIST;

   /**
    * @var string Поле, которое будет сравниваться с User ID, чтобы определить, что юзер имеет право просматривать/редактировать свои данные
    */
   public $USER_ATTRIBUTE = 'user';

   /**
    * @var array Роли, которым доступны конкретные поля, и что они могут с ними делать: смотреть/редактировать
    */
   public function ACCESS_RULES()
   {
      return [
         'MainUser' => [
            'view' => '*',
            'edit' => '*',
            'delete' => '*'
         ],
         'Guest' => [
            'view' => '*',
            'edit' => [],
            'delete' => null
         ],
      ];
   }

   /**
    * @inheritdoc
    */
   public static function tableName()
   {
      return 'none';
   }

   public function customFields()
   {
      return [
      ];
   }

   public static function getRole() {
      if (!isset(\Yii::$app->user))
         $role = 'Console';
      else
         if (\Yii::$app->user->isGuest)
            $role = 'Guest';
         else {
            $role = \Yii::$app->authManager->getRolesByUser(\Yii::$app->user->id);
            $role = reset($role)->name;
         }
         return $role;
   }

   /**
    * @param $mode
    * @param $model
    * @param bool $isFilter
    * @param string|array $fields
    * @return array
    */
   public function filterFieldsByRole($mode, $model, $isFilter = true, $fields = '*', $approvedItems = [])
   {

      $role = self::getRole();

      $unsetList = [];
      $setList = [];
      $currentRules = $this->ACCESS_RULES();
      $currentTable = self::tableName();
      $fields = $fields === '*' ? array_keys($this->tableFields()) : $fields;

      // Находим все связанные с запросом таблицы
      $AttrAll = array_unique(array_merge(array_keys($this->attributes) , $fields));
      $AllTables = Tables::all;

      $rules = $currentRules;



      if (isset($rules[$role])) {

         if(($mode !== 'create') && $rules[$role][$mode] === 'self' && $mode === 'edit') {
            if(!$model->hasAttribute($this->USER_ATTRIBUTE) || $model->getAttribute($this->USER_ATTRIBUTE) !== Yii::$app->user->id) {
               throw new ForbiddenHttpException("Forbidden");
            }
         }

         if($mode === 'create') $mode = 'edit';

         // Звёздочка позволяет проделывать все операции, соотв. unsetlist = []
         foreach ($AttrAll as $attrKey) {

            $thisTable = $currentTable;
            $thisAttr = $attrKey;
            $rules = $currentRules;

            // Если поля связаны
            $splitted = explode(".",$attrKey);
            if(count($splitted)>1 && $splitted[0] !== $currentTable) {
               $thisTable = $splitted[0];
               $thisAttr = $splitted[1];
               $class = $AllTables[$thisTable]['class'];
               $DBClass = new $class();
               $rules = $DBClass->ACCESS_RULES();
               $attrKey = $thisAttr;
            }

            $finded = false;
               if ($rules[$role][$mode] === '*' || $rules[$role][$mode] === 'self' || !\Yii::$app->params['AUTH']) { // TODO: тут потом разобраться
                  $finded = true;
               } else
                  foreach ($rules[$role][$mode] as $key => $value) {
                     if (is_numeric($key) && $value === $attrKey) {
                        $finded = true;
                        break;
                     }
                     if (is_string($key) && $key === $attrKey) {
                        if (is_callable($value) && !$value($this) || $value === 'self' && (!$model->hasAttribute($this->USER_ATTRIBUTE) || $model->getAttribute($this->USER_ATTRIBUTE) !== Yii::$app->user->id)) {
                           if ($mode === 'edit') {
                              throw new ForbiddenHttpException("Forbidden");
                           }
                           $finded = false;
                           break;
                        }
                        $finded = true;
                        break;
                     }
                  }
               // Отдаём все доступные поля
               $attrKey = count($splitted) > 1 ? implode(".", $splitted) : $attrKey;
               if (!$finded) $unsetList[] = $attrKey;
               if ($finded) $setList[] = $attrKey;
            }
      }

      if (is_array($fields)) {
         $filteredList = [];
         foreach ($setList as $item) if (in_array($item, $fields) || in_array($item, $approvedItems)) $filteredList[] = $item;
         return $filteredList;
      }

      return $isFilter ? $unsetList : $setList;

   }

   public function filterRowsByRole($model) {
      $role = self::getRole();
      $rules = $this->ACCESS_RULES();
      if(isset($rules[$role]['rowsFilter']) && is_callable($rules[$role]['rowsFilter'])) {
         $rules[$role]['rowsFilter']($model);
         $x=4;
      }
   }

   /**
    * Список всех полей ДБ с их оригинальными типами
    * @return array
    */
   public static function tableFields()
   {
      return [
      ];
   }


   /**
    * Связанные поля и параметры связи
    * @return array
    */
   public static function relatedFields()
   {
      return [
      ];
   }

   /**
    * Запрашиваем у движка дополнительные поля из выдачи, указанные в ExtraFields, которые должны быть выведены
    * @return array
    */
   public function fields()
   {

      $arr = [];
      $need_fields = (Yii::$app->controller)->extendFields;

      foreach ($need_fields as $field)
         $arr[] = $field . "_";

      return array_merge(parent::fields(), $arr, $this->customFields());
   }

   public function attributes()
   {
      return array_keys($this->tableFields());
   }

   /**** ОПЕРАЦИИ С ДЕРЕВЬЯМИ ****/

   /**
    * Вернуть всех потомков записи в виде дерева
    *
    * @param null $rootID Может принимать значения:
    *                        NULL                - вернуть всё дерево начиная с первого элемента для текущего пользователя
    *                        ID элемента        - вернуть дерево конкретного элемента
    *                        Массив запроса []    - массив с запросом для поиска корневого элемента
    *                        ActiveRecord        - уже найденная запись, поиск не нужен
    *
    * @param null $depth глубина ветвей
    * @param array $filters Массив фильтров в формате Yii ['!=','status','0']
    * @param array $mixtable Обогатить каждый элемент дерева этой таблицей [leftjoin для дерева, но быстрее]
    * @param array $mixtableConditions Условия поиска строк для обогащения. Формат: ['Параметр строки дерева'=>'Параметр строки таблицы'], например ['id'=>'task','user'=>'user']
    * @param string $mixPrefix Префикс для выходных обогащённых параметров
    * @return array
    */
   public static function getTree($rootID = null, $depth = null, &$filters = [], &$mixtable = [], &$mixtableConditions = [], &$mixPrefix = "joined")
   {

      // Ищем корневой элемент
      $root = [];

      // Корневой элемент не указан - ищем корень для пользователя
      if ($rootID === NULL) {

         $root = self::findOne([
            'sort_m_path_depth' => 0,
            'user' => Yii::$app->user->id
         ]);

      } // Массив запроса -> ищем по нему
      elseif (is_array($rootID)) {
         $root = self::findOne($rootID);
      } // Выслан целый объект
      elseif (is_object($rootID)) {
         $root = $rootID;
      } // Корневой элемент указан по ID
      else {
         $root = self::findOne($rootID);
      }

      // Не найден корневой элемент пользователя - ошибка
      if ($root == NULL) return [];

      // Найден - строим для элемента дерево
      $tree = $root->populateTree($depth)->children;

      // Рекурсивно перебираем дерево, чтобы вытащить детей из relations в массив, который можно отдать через JSON
      // А также обогощаем его инфой пользователя
      $arrTree = self::getTreeArray($tree, $filters, $mixtable, $mixtableConditions, $mixPrefix);

      // Возвращаем структуированный результат
      return [
         'tree' => $arrTree,
         'rootid' => $root->id
      ];

   }

   /**
    * Получить дерево как массив с детьми
    * На выходе получаем обычный массив, т.к. ассоциативный не держит порядок элементов
    * @param $data
    * @param $filters array Массив фильтров в формате Yii ['!=','status','0']
    * @param $mixtable array Данные для обогащения
    * @param $mixtableConditions array Условия поиска строки для обогащения
    * @param $mixPrefix string Префикс для выходных обогащённых параметров
    * @return array
    */
   public static function getTreeArray(&$data, &$filters, &$mixtable, &$mixtableConditions, &$mixPrefix)
   {

      // Перебираем элементы объекта
      $done_arr = [];
      foreach ($data as $item) {

         // Получаем основное значение
         $this_arr = ArrayHelper::toArray($item->attributes);

         // Удаляем колонки сортировки, кроме уровня вложенности (он может пригодится)
         unset($this_arr['sort_m_path']);
         unset($this_arr['sort_m_path_sort']);

         // Проводим его через фильтр
         if (self::filterTreeArray($this_arr, $filters) === false) continue;

         // Обогащаем данные
         if (count($mixtable) > 0)
            self::enrichmentTreeElement($this_arr, $mixtable, $mixtableConditions, $mixPrefix);

         // Получаем детей
         if (isset($item['children']))
            $this_arr['children'] = self::getTreeArray($item['children'], $filters, $mixtable, $mixtableConditions, $mixPrefix);

         // Добавляем результат в выходной массив
         $done_arr[] = $this_arr;

      }

      // Возвращаем итоговый массив
      return $done_arr;

   }

   /**
    * Фильтрация данных дерева
    * @param $item
    * @param $filters array Массив фильтров в формате Yii ['!=','status','0']
    * @return boolean
    */
   private static function filterTreeArray($item, $filters)
   {

      foreach ($filters as $filter) {

         switch ($filter[0]) {
            case '!=':
            {
               if ($item[$filter[1]] != $filter[2]) break;
               return false;
            }
            case '>':
            {
               if ((int)$item[$filter[1]] > (int)$filter[2]) break;
               return false;
            }
            case '<':
            {
               if ((int)$item[$filter[1]] < (int)$filter[2]) break;
               return false;
            }
         }

      }

      return true;

   }


   /**
    * Обогатить строку дерева данными другой таблицы [быстрый аналог leftjoin для деревьев]
    * @param $row
    * @param $mixtable
    * @param $mixtableConditions
    * @param $mixname
    * @return array;
    */
   public static function enrichmentTreeElement(&$row, &$mixtable, &$mixtableConditions, &$mixPrefix)
   {

      // Перебираем все строки таблицы обогащения [оптимизированным способом под PHP7]
      for ($i = 0; $i < count($mixtable); $i++) {

         // Перебираем все условия поиска
         $finded = true;
         foreach ($mixtableConditions as $key => $item) {

            // Ключ не совпадает - пропускаем всю строку
            if (!($mixtable[$i][$key] == $item || $mixtable[$i][$key] == $row[$item])) {
               $finded = false;
            }

         }

         # После перебора всех значений, если совпадение по всем параметрам - заполняем значениями из микс таблицы
         if ($finded) {

            foreach ($mixtable[$i] as $attr => $value) {
               $row[$mixPrefix . $attr] = $value;
            }

            # Выходим из поиска, по одному совпадению на строку
            return $row;

         }

      }

      # Цикл не нашёл значения для обогащения, заполняем атрибуты пустыми значениями (берём с первого элемента)
      foreach ($mixtable[0] as $attr => $value) {
         $row[$mixPrefix . $attr] = NULL;
      }

      return $row;

   }


   /**
    * Вывести элементы в виде списка, т.е. пар значений id:name
    * Без указания параметров - выводится вся таблица
    *
    * @param null $arrayORrootelement Может быть массивом объектов ActiveRecord или
    *                                    рутовым элементом, детей которого надо получить и вернуть
    * @return array
    */
   public static function getList($arrayORrootelement = null)
   {

      // Вычисляем тип элемента
      $items = [];

      // Готовый массив -> сразу переходим к обработке
      if (is_array($arrayORrootelement)) {
         $items = $arrayORrootelement;
      } // Не задан -> выбираем элементы всей таблицы
      elseif ($arrayORrootelement == NULL) {
         $items = self::find()->all();
      } // ID рутового элемента -> выбираем всех детей
      elseif (is_numeric($arrayORrootelement)) {
         $root = self::findOne((int)$arrayORrootelement);
         if ($root == NULL) return [];
         $items = $root->getDescendants(1)->all();
      } // Неопознанный тип
      else {
         return [];
      }

      // перебираем элементы
      $list = [];
      foreach ($items as $item) {
         $list[] = $item['name'];
      }

      // возвращаем элементы
      return $list;

   }

   /**
    * Получить в виде массива объектов, где ключи массива - ID элементов, а его значения - объекты ActiveRecord
    * @param array $where Фильтр для выборки
    * @return array
    */
   public static function getArray($where = [])
   {

      # Ищем все элементы по фильтру
      $items = self::find()->where($where)->all();

      # Выходной массив
      $data = [];

      # Сопоставляем id и объекты
      foreach ($items as $item) {
         $data[(int)$item->id] = $item;
      }

      # Возвращаем результат
      return $data;

   }

}