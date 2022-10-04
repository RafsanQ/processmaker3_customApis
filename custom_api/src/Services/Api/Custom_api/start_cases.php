<?php

namespace Services\Api\Custom_api;

use Luracast\Restler\RestException;
use ProcessMaker\Services\Api;
use \ProcessMaker\Util;
use \ProcessMaker\Services\Api\Cases;

/**
 * @protected
 */
class Start_cases extends Api
{



  /**
   * Get usr_uid wise process list for start case
   *  
   * @url GET {usr_uid}/start-cases
   *
   * @param string $usr_uid {@from path}
   * @param string $type_view  {ignored}
   * @return array
   *
   */
  function doGetCasesListStarCaseByUser(
    $usr_uid = ''
  ) {

    $type_view = '';
    try {

      $case = new \ProcessMaker\BusinessModel\Cases();

      $response = $case->getCasesListStarCase($usr_uid, $type_view);

      return $response;
    } catch (\Exception $e) {
      throw new RestException(Api::STAT_APP_EXCEPTION, $e->getMessage());
    }
  }



  /**
   * @url GET /start_cases/userid/:uid
   */

  public function userid($uid)
  {
    return $this->doGetCasesListStarCaseByUser($uid);
  }



}
