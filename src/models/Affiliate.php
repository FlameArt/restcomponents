<?php

namespace flameart\rest\models;

/**
 * Реферальная программа
 * @package flameart\rest\models
 */
class Affiliate
{

   // Шаблон аффилейтской ссылки только с ID аффилейта
   public $link_template_affid = '/v{AFFID}';

   // Шаблон аффилейтской ссылки с ID аффилейта и ID ссылки, по которой он пришёл
   public $link_template_affid_afflink = '/v{AFFID}i{AFFLINK}';

   /**
    * Получить конечную ссылку на аффилейт
    * @param null $afflinkID
    * @return string|string[]
    */
   public function GetAffiliateLinkString($afflinkID = null)
   {

      // Только авторизованным юзерам позволено генерить ссылку
      if (\Yii::$app->user->isGuest) throw new \yii\web\ForbiddenHttpException("User is not registered");

      if ($afflinkID === null)
         return str_replace('{AFFID}', \Yii::$app->user->id, $this->link_template_affid);
      else
         return str_replace(['{AFFID}', '{AFFLINK}'], [\Yii::$app->user->id, $afflinkID], $this->link_template_affid_afflink);

   }


   public function GetAffiliateDataFromURL($url = null)
   {

      $url = $url ?? $_SERVER['REQUEST_URI'];

      $affid = str_replace("\{AFFID\}", "([0-9]*)", preg_quote($this->link_template_affid, '/'));
      $affidlinkid = str_replace(["\{AFFID\}", "\{AFFLINK\}"], "([0-9]*)", preg_quote($this->link_template_affid_afflink, '/'));

      $result = null;

      // По ссылке с ID юзера и ID ссылки
      if (preg_match("/$affidlinkid/i", $url, $result) === 1) {
         return [
            'affID' => $result[1],
            'affLink' => $result[2],
         ];
      }

      // По чистой ссылке ID юзера
      if (preg_match("/$affid/i", $url, $result) === 1) {
         return [
            'affID' => $result[1],
            'affLink' => 0,
         ];
      }

      // По-умолчанию он не перешёл ни по какой реферальной ссылке, устанавливаем все значения в 0 [пришёл нативно]
      return [
         'affID' => 0,
         'affLink' => 0,
      ];

   }

   public static function GetAffiliateDataFromCookies()
   {
      return [
         'affID'=> \Yii::$app->response->cookies->getValue('aff_id') ?? 0,
         'affLink' => \Yii::$app->response->cookies->getValue('aff_link') ?? 0,
      ];
   }


}