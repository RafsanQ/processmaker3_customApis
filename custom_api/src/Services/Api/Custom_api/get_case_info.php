<?php

namespace Services\Api\Custom_api;

use Luracast\Restler\RestException;
use OAuth2\Request;
use ProcessMaker\Services\Api;
use \ProcessMaker\Util\DateTime;
use \ProcessMaker\BusinessModel\Validator;


/**
 * @protected
 */
class get_case_info extends Api
{


    private $formatFieldNameInUppercase = true;

    private $arrayFieldIso8601 = [
      "del_init_date",
      "del_finish_date",
      "del_task_due_date",
      "del_risk_date",
      "del_delegate_date",
      "app_create_date",
      "app_update_date",
      "app_finish_date",
      "del_delegate_date",
      "note_date"
  ];


     /**
     * Get the name of the field according to the format
     *
     * @param string $fieldName Field name
     *
     * return string Return the field name according the format
     */
    public function getFieldNameByFormatFieldName($fieldName)
    {
        try {
            return ($this->formatFieldNameInUppercase)? strtoupper($fieldName) : strtolower($fieldName);
        } catch (\Exception $e) {
            throw $e;
        }
    }

   /**
     * Throw the exception "The Case doesn't exist"
     *
     * @param string $applicationUid        Unique id of Case
     * @param string $fieldNameForException Field name for the exception
     *
     * @return void
     */
    private function throwExceptionCaseDoesNotExist($applicationUid, $fieldNameForException)
    {
        throw new \Exception(\G::LoadTranslation(
            'ID_CASE_DOES_NOT_EXIST', [$fieldNameForException, $applicationUid]
        ));
    }



    public function throwExceptionIfNotExistsCase($applicationUid, $delIndex, $fieldNameForException)
    {
        try {
            $obj = \ApplicationPeer::retrieveByPK($applicationUid);

            $flag = is_null($obj);

            if (!$flag && $delIndex > 0) {
                $obj = \AppDelegationPeer::retrieveByPK($applicationUid, $delIndex);

                $flag = is_null($obj);
            }

            // If this AppUid does not exist
            if ($flag) {
                // $this->throwExceptionCaseDoesNotExist($applicationUid, $fieldNameForException);
                return "ID_CASE_DOES_NOT_EXIST";
            }
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function getCaseInfo($applicationUid, $userUid)
    {

        try {
            $solrEnabled = 0;
            if (($solrEnv = \System::solrEnv()) !== false) {
                \G::LoadClass("AppSolr");
                $appSolr = new \AppSolr(
                    $solrEnv["solr_enabled"],
                    $solrEnv["solr_host"],
                    $solrEnv["solr_instance"]
                );
                if ($appSolr->isSolrEnabled() && $solrEnv["solr_enabled"] == true) {
                    //Check if there are missing records to reindex and reindex them
                    $appSolr->synchronizePendingApplications();
                    // $solrEnabled = 1;
                    // Disable this. We do not need Solr.
                }
            }
            if ($solrEnabled == 1) {
                try {
                    \G::LoadClass("searchIndex");
                    $arrayData = array();
                    $delegationIndexes = array();
                    $columsToInclude = array("APP_UID");
                    $solrSearchText = null;
                    //Todo
                    $solrSearchText = $solrSearchText . (($solrSearchText != null)? " OR " : null) . "(APP_STATUS:TO_DO AND APP_ASSIGNED_USERS:" . $userUid . ")";
                    $delegationIndexes[] = "APP_ASSIGNED_USER_DEL_INDEX_" . $userUid . "_txt";
                    //Draft
                    $solrSearchText = $solrSearchText . (($solrSearchText != null)? " OR " : null) . "(APP_STATUS:DRAFT AND APP_DRAFT_USER:" . $userUid . ")";
                    //Index is allways 1
                    $solrSearchText = "($solrSearchText)";
                    //Add del_index dynamic fields to list of resulting columns
                    $columsToIncludeFinal = array_merge($columsToInclude, $delegationIndexes);
                    $solrRequestData = \Entity_SolrRequestData::createForRequestPagination(
                        array(
                            "workspace"  => $solrEnv["solr_instance"],
                            "startAfter" => 0,
                            "pageSize"   => 1000,
                            "searchText" => $solrSearchText,
                            "numSortingCols" => 1,
                            "sortCols" => array("APP_NUMBER"),
                            "sortDir"  => array(strtolower("DESC")),
                            "includeCols"  => $columsToIncludeFinal,
                            "resultFormat" => "json"
                        )
                    );
                    //Use search index to return list of cases
                    $searchIndex = new \BpmnEngine_Services_SearchIndex($appSolr->isSolrEnabled(), $solrEnv["solr_host"]);
                    //Execute query
                    $solrQueryResult = $searchIndex->getDataTablePaginatedList($solrRequestData);
                    //Get the missing data from database
                    $arrayApplicationUid = array();
                    foreach ($solrQueryResult->aaData as $i => $data) {
                        $arrayApplicationUid[] = $data["APP_UID"];
                    }
                    $aaappsDBData = $appSolr->getListApplicationDelegationData($arrayApplicationUid);
                    foreach ($solrQueryResult->aaData as $i => $data) {
                        //Initialize array
                        $delIndexes = array(); //Store all the delegation indexes
                        //Complete empty values
                        $applicationUid = $data["APP_UID"]; //APP_UID
                        //Get all the indexes returned by Solr as columns
                        for ($i = count($columsToInclude); $i <= count($data) - 1; $i++) {
                            if (is_array($data[$columsToIncludeFinal[$i]])) {
                                foreach ($data[$columsToIncludeFinal[$i]] as $delIndex) {
                                    $delIndexes[] = $delIndex;
                                }
                            }
                        }
                        //Verify if the delindex is an array
                        //if is not check different types of repositories
                        //the delegation index must always be defined.
                        if (count($delIndexes) == 0) {
                            $delIndexes[] = 1; // the first default index
                        }
                        //Remove duplicated
                        $delIndexes = array_unique($delIndexes);
                        //Get records
                        foreach ($delIndexes as $delIndex) {
                            $aRow = array();
                            //Copy result values to new row from Solr server
                            $aRow["APP_UID"] = $data["APP_UID"];
                            //Get delegation data from DB
                            //Filter data from db
                            $indexes = $appSolr->aaSearchRecords($aaappsDBData, array(
                                "APP_UID" => $applicationUid,
                                "DEL_INDEX" => $delIndex
                            ));
                            foreach ($indexes as $index) {
                                $row = $aaappsDBData[$index];
                            }
                            if (!isset($row)) {
                                continue;
                            }
                            \G::LoadClass('wsBase');
                            $ws = new \wsBase();
                            $fields = $ws->getCaseInfo($applicationUid, $row["DEL_INDEX"]);
                            $array = json_decode(json_encode($fields), true);
                            if ($array ["status_code"] != 0) {
                                throw (new \Exception($array ["message"]));
                            } else {
                                $array['app_uid'] = $array['caseId'];
                                $array['app_number'] = $array['caseNumber'];
                                $array['app_name'] = $array['caseName'];
                                $array['app_status'] = $array['caseStatus'];
                                $array['app_init_usr_uid'] = $array['caseCreatorUser'];
                                $array['app_init_usr_username'] = trim($array['caseCreatorUserName']);
                                $array['pro_uid'] = $array['processId'];
                                $array['pro_name'] = $array['processName'];
                                $array['app_create_date'] = $array['createDate'];
                                $array['app_update_date'] = $array['updateDate'];
                                $array['current_task'] = $array['currentUsers'];
                                for ($i = 0; $i<=count($array['current_task'])-1; $i++) {
                                    $current_task = $array['current_task'][$i];
                                    $current_task['usr_uid'] = $current_task['userId'];
                                    $current_task['usr_name'] = trim($current_task['userName']);
                                    $current_task['tas_uid'] = $current_task['taskId'];
                                    $current_task['tas_title'] = $current_task['taskName'];
                                    $current_task['del_index'] = $current_task['delIndex'];
                                    $current_task['del_thread'] = $current_task['delThread'];
                                    $current_task['del_thread_status'] = $current_task['delThreadStatus'];
                                    unset($current_task['userId']);
                                    unset($current_task['userName']);
                                    unset($current_task['taskId']);
                                    unset($current_task['taskName']);
                                    unset($current_task['delIndex']);
                                    unset($current_task['delThread']);
                                    unset($current_task['delThreadStatus']);
                                    $aCurrent_task[] = $current_task;
                                }
                                unset($array['status_code']);
                                unset($array['message']);
                                unset($array['timestamp']);
                                unset($array['caseParalell']);
                                unset($array['caseId']);
                                unset($array['caseNumber']);
                                unset($array['caseName']);
                                unset($array['caseStatus']);
                                unset($array['caseCreatorUser']);
                                unset($array['caseCreatorUserName']);
                                unset($array['processId']);
                                unset($array['processName']);
                                unset($array['createDate']);
                                unset($array['updateDate']);
                                unset($array['currentUsers']);
                                $current_task = json_decode(json_encode($aCurrent_task), false);
                                $oResponse = json_decode(json_encode($array), false);
                                $oResponse->current_task = $current_task;
                            }
                            //Return
                            return $oResponse;
                        }
                    }
                } catch (\InvalidIndexSearchTextException $e) {
                    $arrayData = array();
                    $arrayData[] = array ("app_uid" => $e->getMessage(),
                                          "app_name" => $e->getMessage(),
                                          "del_index" => $e->getMessage(),
                                          "pro_uid" => $e->getMessage());
                    throw (new \Exception($arrayData));
                }
            } else {
                \G::LoadClass("wsBase");

                //Verify data
                if($this->throwExceptionIfNotExistsCase($applicationUid, 0, $this->getFieldNameByFormatFieldName("APP_UID")) == "ID_CASE_DOES_NOT_EXIST"){
                    return "ID_CASE_DOES_NOT_EXIST";
                }

                $criteria = new \Criteria("workflow");

                $criteria->addSelectColumn(\AppDelegationPeer::APP_UID);
                $criteria->add(\AppDelegationPeer::APP_UID, $applicationUid);
                // $criteria->add(\AppDelegationPeer::USR_UID, $userUid);

                $rsCriteria = \AppDelegationPeer::doSelectRS($criteria);

                if (!$rsCriteria->next()) {
                    throw new \Exception(\G::LoadTranslation("ID_NO_PERMISSION_NO_PARTICIPATED"));
                }

                //Get data
                $ws = new \wsBase();

                $fields = $ws->getCaseInfo($applicationUid, 0);
                $array = json_decode(json_encode($fields), true);

                if ($array ["status_code"] != 0) {
                    throw (new \Exception($array ["message"]));
                } else {
                    $array['app_uid'] = $array['caseId'];
                    $array['app_number'] = $array['caseNumber'];
                    $array['app_name'] = $array['caseName'];
                    $array["app_status"] = $array["caseStatus"];
                    $array['app_init_usr_uid'] = $array['caseCreatorUser'];
                    $array['app_init_usr_username'] = trim($array['caseCreatorUserName']);
                    $array['pro_uid'] = $array['processId'];
                    $array['pro_name'] = $array['processName'];
                    $array['app_create_date'] = $array['createDate'];
                    $array['app_update_date'] = $array['updateDate'];
                    $array['current_task'] = $array['currentUsers'];

                    $aCurrent_task = array();

                    for ($i = 0; $i<=count($array['current_task'])-1; $i++) {
                        $current_task = $array['current_task'][$i];
                        $current_task['usr_uid'] = $current_task['userId'];
                        $current_task['usr_name'] = trim($current_task['userName']);
                        $current_task['tas_uid'] = $current_task['taskId'];
                        $current_task['tas_title'] = $current_task['taskName'];
                        $current_task['del_index'] = $current_task['delIndex'];
                        $current_task['del_thread'] = $current_task['delThread'];
                        $current_task['del_thread_status'] = $current_task['delThreadStatus'];
                        $current_task["del_init_date"] = $current_task["delInitDate"] . "";
                        $current_task["del_task_due_date"] = $current_task["delTaskDueDate"];
                        unset($current_task['userId']);
                        unset($current_task['userName']);
                        unset($current_task['taskId']);
                        unset($current_task['taskName']);
                        unset($current_task['delIndex']);
                        unset($current_task['delThread']);
                        unset($current_task['delThreadStatus']);
                        $aCurrent_task[] = $current_task;
                    }
                    unset($array['status_code']);
                    unset($array['message']);
                    unset($array['timestamp']);
                    unset($array['caseParalell']);
                    unset($array['caseId']);
                    unset($array['caseNumber']);
                    unset($array['caseName']);
                    unset($array['caseStatus']);
                    unset($array['caseCreatorUser']);
                    unset($array['caseCreatorUserName']);
                    unset($array['processId']);
                    unset($array['processName']);
                    unset($array['createDate']);
                    unset($array['updateDate']);
                    unset($array['currentUsers']);
                }
                $current_task = json_decode(json_encode($aCurrent_task), false);
                $oResponse = json_decode(json_encode($array), false);
                $oResponse->current_task = $current_task;
                //Return
                return $oResponse;
            }
        } catch (\Exception $e) {
            throw $e;
        }
    }


   /**
     * 
     *
     * @param string $app_uid {@min 32}{@max 32}
     */
    function doGetCaseInfo($app_uid)
    {
        try {
            $this->formatFieldNameInUppercase = false;

            $caseInfo = $this->getCaseInfo($app_uid, $this->getUserId());
            $caseInfo = DateTime::convertUtcToIso8601($caseInfo, $this->arrayFieldIso8601);
            return $caseInfo;
        } catch (\Exception $e) {
            throw new RestException(Api::STAT_APP_EXCEPTION, $e->getMessage());
        }
    }


    /**
     * @url GET /get-case-info/app-uid/:appUid
     */

    public function app_uid($appUid)
    {
        return $this->doGetCaseInfo($appUid);
    }


    /**
     * @url POST /get-case-info/list-app-info/
     */

    public function post_list_app_info (array $app_uids)
    {
        $outputList = array();

        foreach($app_uids as $app_uid){
        $outputList[] = $this->doGetCaseInfo($app_uid);
        }

        return $outputList;
    }




}
