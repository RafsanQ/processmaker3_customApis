<?php
/**
 * class.custom_api.php
 *  
 */

  class custom_apiClass extends PMPlugin {
    function __construct() {
      set_include_path(
        PATH_PLUGINS . 'custom_api' . PATH_SEPARATOR .
        get_include_path()
      );
    }

    function setup()
    {
    }

    function getFieldsForPageSetup()
    {
    }

    function updateFieldsForPageSetup()
    {
    }

  }
?>