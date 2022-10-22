<?php

namespace flameart\rest\controllers;

use Yii;
use yii\helpers\Json;
use yii\web\Controller;
use yii\filters\VerbFilter;
use yii\filters\AccessControl;
use yii\filters\ContentNegotiator;
use yii\web\Response;
use flameart\rest\models\Affiliate;


/**
 * Site controller
 */
class SiteController extends Controller
{
   /**
    * {@inheritdoc}
    */
   public function behaviors()
   {
      return [
         'access' => [
            'class' => AccessControl::class,
            'rules' => [
               [
                  'actions' => ['error', 'index', 'fastindex'],
                  'allow' => true,
               ],
            ],
         ],

         // JSON формат вывода
         'contentNegotiator' => [
            'class' => ContentNegotiator::class,
            'formats' => [
               'application/json' => Response::FORMAT_JSON,
            ],
         ]
      ];
   }

   /**
    * Displays homepage.
    *
    */
   public function actionIndex()
   {
      return [
         "Welcome" => "Welcome!"
      ];
   }

   public function actionError()
   {
      $exception = Yii::$app->errorHandler->exception;
      if ($exception !== null) {
         if (YII_DEBUG)
            return [
               'errors' => $exception,
               'message' => $exception->getMessage(),
               'file' => $exception->getFile(),
               'line' => $exception->getLine(),
               'stack' => $exception->getTrace(),
            ];
         else
            return [
               'errors' => $exception->getCode(),
            ];
      }
      else
         return ['errors'=>'Unknown'];
   }

   /**
    * Тут можно сделать preload авторизационных и других данных,
    * и настроить nginx, чтобы индексный SPA файл он отдавал пропуская через этот скрипт
    * сразу с заполненными данными
    */
   public function actionFastindex()
   {

      // Если не грузим юзера (требует подключения к БД) - отдача метода 0мс

      // формат выдачи
      Yii::$app->response->format = Response::FORMAT_HTML;

      // Устанавливаем каждому анону хеш, по которому его можно идентифицировать в статистике
      if(!Yii::$app->response->cookies->has('user_hash'))
         Yii::$app->response->cookies->add(new yii\web\Cookie([
            'name'=> "user_hash",
            'value' => md5(microtime(true).$_SERVER['HTTP_USER_AGENT'].$_SERVER['REMOTE_ADDR']),
            'expire' => (new \DateTime())->modify('+50 years')->getTimestamp(),
            'path' => '/',
            'secure' => true,
            'httpOnly' => true,
            'sameSite' => 'Strict'
         ]));

      // Устанавливаем впервые зашедшим в куки аффилейтскую ссылку, либо пустую аффилейтку, если зашёл без неё, чтобы нельзя было переназначить
      $affiliate = (new Affiliate())->GetAffiliateDataFromURL();
      if(!Yii::$app->response->cookies->has('aff_id') && isset($affiliate['affID']))
         Yii::$app->response->cookies->add(new yii\web\Cookie([
            'name'=> "aff_id",
            'value' => $affiliate['affID'],
            'expire' => (new \DateTime())->modify('+50 years')->getTimestamp(),
            'path' => '/',
            'secure' => false,
            'httpOnly' => true,
            'sameSite' => 'Strict',
         ]));

      if(!Yii::$app->response->cookies->has('aff_link') && isset($affiliate['affLink']))
         Yii::$app->response->cookies->add(new yii\web\Cookie([
            'name'=> "aff_link",
            'value' => $affiliate['affLink'],
            'expire' => (new \DateTime())->modify('+50 years')->getTimestamp(),
            'path' => '/',
            'secure' => false,
            'httpOnly' => true,
            'sameSite' => 'Strict',
         ]));

      // Получаем исходный файл (должен быть открыт для чтения и в openbasedir)
      $index = file_get_contents(Yii::getAlias('@app')."/../../public/dist/index.html");

      // Предзагружаем в выдачу сразу любые данные
      $index = str_replace("{{{PRELOAD_STATE_REPLACE}}}", Json::encode([
         //'User' => (new AuthController(null, null))->getUser()
      ]), $index);

      // Быстро отдаём
      return $index;
   }


}
