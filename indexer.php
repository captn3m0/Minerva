#! /usr/bin/php
<?php
  /**
   * FileName.php
   * @brief  : 
   * @author : Capt. Nemo
   * @date   :
   * @version:
   */

define("ROOT_DIR",'/media/Data/eBooks/');
require_once './aws-sdk-for-php/sdk.class.php';
require("rb.php");
require("./isbn/ISBN.php");
require("MinveraIndexer.php");

$indexer = new MinervaIndexer(ROOT_DIR);
$indexer->start();
