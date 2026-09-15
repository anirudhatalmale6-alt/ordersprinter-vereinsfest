<?php
require_once('dbutils.php');
require_once('products.php');
require_once('commonutils.php');
require_once('printqueue.php');
require_once('utilities/userrights.php');
require_once('utilities/dsfinvktabs/bonpos.php');
require_once('utilities/dsfinvktabs/bonkopf.php');
require_once('utilities/dsfinvktabs/bonposorder.php');
require_once('utilities/dsfinvktabs/bonpospayment.php');
require_once('utilities/dsfinvktabs/bonkopforder.php');
require_once('utilities/dsfinvktabs/bonkopfpayment.php');
require_once('utilities/TypeAndProducts/QueueItemExtra.php');
require_once('items/bruttonetto.php');

// make compiler happy with use statements for classes that are used in this file but not explicitly required here
use CommonUtils as CommonUtils;
use BruttoNetto as BruttoNetto;
use DbUtils as DbUtils;
use Userrights as Userrights;
use DSFinvk as DSFinvk;
use Bill as Bill;
use EbonSync as EbonSync;

class QueueContent
{
	var $dbutils;
	var $commonUtils;
	var $userrights;

	public static $INTERNAL_CALL_NO = false;
	public static $INTERNAL_CALL_YES = true;

	// for incoming queue requests:
	const INTERNAL_ORDER = 1;
	const GUEST_ORDER = 2;

	const EXTRA_PRICE_COL = "price";
	const EXTRA_PURCHASE_COL = "purchasingprice";

	public static $lastSettingOfDisplayMode = 'all';

	function __construct()
	{
		$this->dbutils = new DbUtils();
		$this->commonUtils = new CommonUtils();
		$this->userrights = new Userrights();
	}

	function handleCommand($command)
	{
		header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
		header("Cache-Control: post-check=0, pre-check=0", false);
		header("Pragma: no-cache");

		if ($command == "getJsonTableNameFromId") {
			$this->getJsonTableNameFromId($_GET['tableid']);
			return;
		}

		if ($command == "addAndCashProdByRestCall") {
			$pdo = DbUtils::openDbAndReturnPdoStatic();
			$this->addAndCashProdByRestCall($pdo, $_POST);
			return;
		}
		// these command are only allowed for user with supply rights
		$cmdArray = array('getJsonAllPreparedProducts', 'getJsonLastDeliveredProducts', 'declareProductBeDelivered', 'declareMultipleProductsDelivered', 'declareProductNotBeDelivered');
		if (in_array($command, $cmdArray)) {
			if (!($this->userrights->hasCurrentUserRight('right_supply'))) {
				echo json_encode(array("status" => "ERROR", "msg" => "Fehlende Benutzerrechte"));
				return false;
			}
		}

		// these command are only allowed for user with kitchen or bar rights
		$cmdArray = array('declareProductBeCookingOrCooked', 'declareProductNOTBeCooked');
		if (in_array($command, $cmdArray)) {
			if (!($this->userrights->hasCurrentUserRight('right_kitchen')) && !($this->userrights->hasCurrentUserRight('right_bar'))) {
				echo "Benutzerrechte nicht ausreichend!";
				return false;
			}
		}

		// these command are only allowed for user with waiter rights
		$cmdArray = array('addProductListToQueue', 'cancelinpaydesk', 'removeProductsFromQueue', 'changeTable', 'getProdsForTableChange', 'updateliveorders');
		if (in_array($command, $cmdArray)) {
			if (!($this->userrights->hasCurrentUserRight('right_waiter'))) {
				echo json_encode(array("status" => "ERROR", "msg" => "Benutzerrechte nicht ausreichend!"));
				return false;
			}
		}

		// these command are only allowed for user with paydesk rights
		$cmdArray = array('getJsonProductsOfTableToPay', 'declarePaidCreateBillReturnBillId');
		if (in_array($command, $cmdArray)) {
			if (!($this->userrights->hasCurrentUserRight('right_paydesk'))) {
				echo json_encode(array("status" => "ERROR", "code" => ERROR_PAYDESK_NOT_AUTHOTRIZED, "msg" => ERROR_PAYDESK_NOT_AUTHOTRIZED_MSG));
				return false;
			}
		}

		// these command are only allowed for user with customersview rights
		$cmdArray = array('getliveorders');
		if (in_array($command, $cmdArray)) {
			if (!($this->userrights->hasCurrentUserRight('right_customersview'))) {
				echo json_encode(array("status" => "ERROR", "msg" => "Benutzerrechte nicht ausreichend!"));
				return false;
			}
		}

		if ($command == 'addProductListToQueue') {
			$order = new Orders($_POST);
			$orderOption = "";
			if (isset($_POST['orderoption'])) {
				$orderOption = $_POST['orderoption'];
			}
			$this->addProductListToQueue(null, $_POST["tableid"], $_POST["prods"], $_POST["print"], $_POST["payprinttype"], $order, $orderOption);
		} else if ($command == 'cancelinpaydesk') {
			$order = new Orders($_POST);
			$orderOption = "";
			if (isset($_POST['orderoption'])) {
				$orderOption = $_POST['orderoption'];
			}
			$this->cancelProductListToQueue($_POST["tableid"], $_POST["prods"], $order, $orderOption, $_POST['paydeskstornocode']);
		} else if ($command == 'updateliveorders') {
			$pdo = DbUtils::openDbAndReturnPdoStatic();
			$liveorders = array();
			if (isset($_POST['liveorders'])) {
				$liveorders = $_POST['liveorders'];
			}
			self::updateliveorders($pdo, $liveorders);
		} else if ($command == 'getliveorders') {
			$pdo = DbUtils::openDbAndReturnPdoStatic();
			self::getliveorders($pdo);
		} else if ($command == 'getRecords') {
			$pdo = DbUtils::openDbAndReturnPdoStatic();
			$this->getRecords($pdo, $_GET["tableid"]);
		} else if ($command == 'getAllTableRecords') {
			$this->getAllTableRecords();
		} else if ($command == 'getAllOrders') {
			$this->getAllOrders();
		} else if ($command == 'kitchenToCook') {
			$this->kitchenToCook();
		} else if ($command == 'declareProductBeCookingOrCooked') {
			$this->declareProductBeCookingOrCooked($_POST['queueid'], $_POST['workprinter']);
		} else if ($command == 'declareProductNotBeCooked') {
			$this->declareProductNotBeCooked($_POST['queueid']);
		} else if ($command == 'showProductsOfTableToPay') {
			$this->showProductsOfTableToPay($_GET['tableid']);
		} else if ($command == 'getJsonAllPreparedProducts') {
			$this->getJsonAllPreparedProducts();
		} else if ($command == 'declareProductBeDelivered') {
			$this->declareProductBeDelivered($_POST['queueid']);
		} else if ($command == 'declareMultipleProductsDelivered') {
			$this->declareMultipleProductsDelivered($_POST['queueids']);
		} else if ($command == 'declareProductNotBeDelivered') {
			$this->declareProductNotBeDelivered($_POST['queueid']);
		} else if ($command == 'getJsonLongNamesOfProdsForTableNotDelivered') {
			$this->getJsonLongNamesOfProdsForTableNotDelivered($_GET["tableid"]);
		} else if ($command == 'getUnpaidTables') {
			$this->getUnpaidTables();
		} else if ($command == 'getProdsForTableChange') {
			$this->getProdsForTableChange($_GET['tableId']);
		} else if ($command == 'changeTable') {
			$this->changeTable($_POST['fromTableId'], $_POST['toTableId'], $_POST['queueids']);
		} else if ($command == 'removeProductsFromQueue') {
			$this->removeProductsFromQueue($_POST["queueids"]);
		} else if ($command == 'getJsonAllQueueItemsToMake') {
			$workprinter = 0;
			if (isset($_GET['workprinter'])) {
				$workprinter = intval($_GET['workprinter']);
			}
			$this->getJsonAllQueueItemsToMake($_GET["kind"], intval($workprinter));
		} else if ($command == 'getJsonLastMadeItems') {
			$workprinter = 0;
			if (isset($_GET['workprinter'])) {
				$workprinter = intval($_GET['workprinter']);
			}
			$this->getJsonLastMadeItems($_GET["kind"], intval($workprinter));
		} else if ($command == 'getJsonLastDeliveredProducts') {
			$this->getJsonLastDeliveredProducts();
		} else if ($command == 'getJsonProductsOfTableToPay') {
			$this->getJsonProductsOfTableToPay($_GET['tableid']);
		} else if ($command == 'declarePaidCreateBillReturnBillId') {
			$pdo = DbUtils::openDbAndReturnPdoStatic();
			$tip = null;
			if (isset($_POST['tip'])) {
				$tip = $_POST['tip'];
			}
			$camefromordering = 0;
			if (isset($_POST['camefromordering'])) {
				$camefromordering = $_POST['camefromordering'];
			}
			$this->declarePaidCreateBillReturnBillId($pdo, $_POST['ids'], $_POST['tableid'], $_POST['paymentid'], $_POST['declareready'], $_POST['host'], self::$INTERNAL_CALL_NO, $_POST['reservationid'], $_POST['guestinfo'], $_POST['intguestid'], null, null, $tip, $camefromordering);
		} else {
			echo "Command not supported.";
		}
	}

	private static function setNewProductsToServe($pdo, $val)
	{
		self::setWorkItemFlag($pdo, "newproductstoserve", $val);
	}
	private static function getNewProductsToServe($pdo)
	{
		return self::getWorkItemFlag($pdo, "newproductstoserve");
	}
	private static function setNewFoodToCookFlag($pdo, $val)
	{
		self::setWorkItemFlag($pdo, "newfoodtocook", $val);
	}
	private static function getNewFoodToCookFlag($pdo)
	{
		return self::getWorkItemFlag($pdo, "newfoodtocook");
	}
	private static function setNewDrinkToCookFlag($pdo, $val)
	{
		self::setWorkItemFlag($pdo, "newdrinktocook", $val);
	}
	private static function getNewDrinkToCookFlag($pdo)
	{
		return self::getWorkItemFlag($pdo, "newdrinktocook");
	}

	private static function setWorkItemFlag($pdo, $item, $val)
	{
		$sql = "SELECT count(id) as countid FROM %work% WHERE item=?";
		$row = CommonUtils::getRowSqlObject($pdo, $sql, array($item));
		if ($row->countid == 0) {
			$sql = "INSERT INTO %work% (item,value,signature) VALUES (?,?,?)";
			CommonUtils::execSql($pdo, $sql, array($item, $val, null));
		} else {
			$sql = "UPDATE %work% SET value=? WHERE item=?";
			CommonUtils::execSql($pdo, $sql, array($val, $item));
		}
	}

	private static function getWorkItemFlag($pdo, $item)
	{
		$sql = "SELECT count(id) as countid FROM %work% WHERE item=?";
		$row = CommonUtils::getRowSqlObject($pdo, $sql, array($item));
		if ($row->countid == 0) {
			return 0;
		} else {
			$sql = "SELECT value FROM %work% WHERE item=?";
			$row = CommonUtils::getRowSqlObject($pdo, $sql, array($item));
			return $row->value;
		}
	}

	function getJsonTableNameFromId($tableid)
	{
		$pdo = DbUtils::openRepliDb();
		$commonUtils = new CommonUtils();
		echo json_encode($commonUtils->getTableNameFromId($pdo, $tableid));
	}

	function getUserName($userid)
	{
		$pdo = $this->dbutils->openDbAndReturnPdo();

		if (is_null($userid)) {
			return "";
		}
		$sql = "SELECT username FROM %user% WHERE id=?";
		$row = CommonUtils::getRowSqlObject($pdo, $sql, array($userid));
		if ($row != null) {
			return ($row->username);
		} else {
			return "";
		}
	}

	private function areBillExisting($pdo)
	{
		$sql = "SELECT count(id) as countid FROM %bill%";
		$row = CommonUtils::getRowSqlObject($pdo, $sql, null);
		$count = intval($row->countid);
		if ($count > 0) {
			return true;
		} else {
			return false;
		}
	}

	private static function getQueueIdOfNewestClosing($pdo)
	{
		$sql = "SELECT COALESCE(MAX(Q.id),0) as maxid FROM %queue% Q WHERE isclosed=?";
		$res = CommonUtils::fetchSqlAll($pdo, $sql, array(1));
		if (count($res) > 0) {
			return $res[0]['maxid'];
		} else {
			return 0;
		}
	}

	/*
	 * Get the queue items for the kitchen view that have to be still be cooked
	 * as a json element array
	 * 
	 * 1. It is sorted for ordertime
	 * 2. From this ordertime search for the distinct tables
	 * 3. Sort it that way that tables are grouped together
	 * 
	 * $kind=0 -> return only food elements, =1 -> return drinks
	 */
	private function getJsonAllQueueItemsToMake($kind, int $workprinter)
	{
		$pdo = DbUtils::openRepliDb();

		// To avoid ugly log in error_log in case of re-installation or import check for existence of some tables
		if (!$this->areBasicTablesAvailable($pdo)) {
			$ret = array("status" => "OK", "msg" => array("newproducts" => 0, "tocook" => array()));
			echo json_encode($ret);
			return;
		}

		Guestsync::sync($pdo);



		$result = $this->queryQueueItemsForKitchenBar($pdo, $kind, $workprinter, true);

		$resultarray = array();
		foreach ($result as $zeile) {
			$arr = array(
				"id" => $zeile['id'],
				"isguestorder" => $zeile['isguestorder'],
				"tableid" => $zeile['tableid'],
				"tablenr" => $zeile['tableno'],
				"longname" => $zeile['longname'],
				"option" => $zeile['anoption'],
				"prodid" => $zeile['prodid'],
				"cooking" => $zeile['cooking'],
				"pickupid" => $zeile['pickupid'],
				"waittime" => $zeile['waittime'],
				"username" => $zeile['username'],
				"bgcolor" => $zeile['bgcolor'],
				"fgcolor" => $zeile['fgcolor']
			);
			$resultarray[] = $arr;
		}

		$tablearray = array();
		$insertedTables = array();
		$table = (-1);
		if (count($resultarray) > 0) {
			for ($queue_index = 0; $queue_index < count($resultarray); $queue_index++) {
				$aTable = $resultarray[$queue_index]['tablenr'];
				if (($table <> $aTable) && !in_array($aTable, $insertedTables)) {
					$table = $aTable;
					$tableid = $resultarray[$queue_index]['tableid'];
					$maxWaitTime = $resultarray[$queue_index]['waittime'];
					$tableArr = array();
					for ($i = 0; $i < count($resultarray); $i++) {
						if ($resultarray[$i]['tablenr'] == $table) {
							$foundItem = $resultarray[$i];
							$waittimeofentry = intval($foundItem['waittime']);
							$waitIconMinStep = 1;
							if ($waittimeofentry <= 1) {
								$waitIconMinStep = 1;
							} else if ($waittimeofentry <= 20) {
								$waitIconMinStep = strval($waittimeofentry);
							} else if ($waittimeofentry <= 25) {
								$waitIconMinStep = 25;
							} else if ($waittimeofentry <= 30) {
								$waitIconMinStep = 30;
							} else if ($waittimeofentry <= 40) {
								$waitIconMinStep = 40;
							} else if ($waittimeofentry <= 50) {
								$waitIconMinStep = 50;
							} else {
								$waitIconMinStep = 60;
							}
							$waitIconMinStep = $waitIconMinStep . 'min.png';

							$extras = $this->getExtrasOfQueueItem($pdo, $foundItem['id']);

							$anEntryForThisTable = array(
								"id" => $foundItem['id'],
								"longname" => $foundItem['longname'],
								"isguestorder" => $foundItem['isguestorder'],
								"option" => $foundItem['option'],
								"prodid" => $foundItem['prodid'],
								"extras" => $extras,
								"cooking" => $this->getUserName($foundItem['cooking']),
								"pickupid" => $foundItem['pickupid'],
								"waiticon" => $waitIconMinStep,
								"waittime" => $waittimeofentry,
								"username" => $foundItem['username'],
								"bgcolor" => $foundItem['bgcolor'],
								"fgcolor" => $foundItem['fgcolor']
							);
							$tableArr[] = $anEntryForThisTable;
						}
					}
					if (($maxWaitTime > 20) && ($maxWaitTime < 60)) {
						if ($maxWaitTime >= 50) {
							$maxWaitTime = "> 50";
						} else if ($maxWaitTime >= 40) {
							$maxWaitTime = "> 40";
						} else if ($maxWaitTime >= 30) {
							$maxWaitTime = "> 30";
						} else if ($maxWaitTime >= 25) {
							$maxWaitTime = "> 25";
						} else {
							$maxWaitTime = "> 20";
						}
					} else if ($maxWaitTime <= 1) {
						$maxWaitTime = "1";
					}

					$tablearray[] = array("table" => $table, "tableid" => $tableid, "count" => count($tableArr), "queueitems" => $tableArr, "maxwaittime" => $maxWaitTime);
					$insertedTables[] = $aTable;
				}
			}
		}

		if ($kind == 0) {
			$newProductsToMake = self::getNewFoodToCookFlag($pdo);
			self::setNewFoodToCookFlag($pdo, 0);
		} else {
			$newProductsToMake = self::getNewDrinkToCookFlag($pdo);
			self::setNewDrinkToCookFlag($pdo, 0);
		}
		$ret = array("status" => "OK", "msg" => array("newproducts" => $newProductsToMake, "tocook" => $tablearray));
		echo json_encode($ret);
	}

	private function getExtrasOfQueueItemInTicketFormat($pdo, $queueid)
	{
		$pureExtrasStrings = $this->getExtrasOfQueueItem($pdo, $queueid);
		$extras = array();
		foreach ($pureExtrasStrings as $extraText) {
			$extras[] = array("name" => $extraText);
		}
		return $extras;
	}

	private function getExtrasOfQueueItem($pdo, $queueid)
	{
		if (is_null($pdo)) {
			$pdo = DbUtils::openDbAndReturnPdoStatic();
		}
		$sql = "SELECT CONCAT(amount,'x ',name) as name FROM %queueextras% WHERE queueid=?";
		$extras = CommonUtils::fetchSqlAll($pdo, $sql, array($queueid));
		$extrasarr = array();
		foreach ($extras as $anExtra) {
			$extrasarr[] = $anExtra["name"];
		}
		return $extrasarr;
	}

	private function getExtrasWithIdsOfQueueItem($pdo, $queueid)
	{
		if (is_null($pdo)) {
			$pdo = $this->dbutils->openDbAndReturnPdo();
		}
		$sql = "SELECT name,extraid,amount FROM %queueextras% WHERE queueid=?";
		$stmt = $pdo->prepare($this->dbutils->resolveTablenamesInSqlString($sql));
		$stmt->execute(array($queueid));
		$extras = $stmt->fetchAll();
		$extrasnames = array();
		$extrasids = array();
		$extrasamounts = array();
		foreach ($extras as $anExtra) {
			$extrasnames[] = $anExtra["name"];
			$extrasids[] = $anExtra["extraid"];
			$extrasamounts[] = $anExtra["amount"];
		}
		return array("extrasnames" => $extrasnames, "extrasids" => $extrasids, "extrasamounts" => $extrasamounts);
	}

	private static function getPhaseAndLongNameForWorkReceiptSql($pdo)
	{
		$unit = CommonUtils::caseOfSqlUnitSelection($pdo);
		$name = " (CASE WHEN P.nameonworkrec is null or P.nameonworkrec='' THEN PN.name ELSE P.nameonworkrec END) ";
		$phaseLongName = "CASE WHEN phase='0' THEN CONCAT($unit,$name) ";
		$phaseLongName .= " WHEN phase='1' THEN CONCAT('VORSP: ',CONCAT($unit,$name))";
		$phaseLongName .= " WHEN phase='2' THEN CONCAT('HAUPT: ',CONCAT($unit,$name))";
		$phaseLongName .= " WHEN phase='3' THEN CONCAT('NACHSP: ',CONCAT($unit,$name))";
		$phaseLongName .= " ELSE CONCAT($unit,PN.name) END";
		return $phaseLongName;
	}
	private static function getPhaseAndLongNameSql($pdo)
	{
		$unit = CommonUtils::caseOfSqlUnitSelection($pdo);
		$phaseLongName = "CASE WHEN phase='0' THEN CONCAT($unit,PN.name) ";
		$phaseLongName .= " WHEN phase='1' THEN CONCAT('VORSP: ',CONCAT($unit,PN.name))";
		$phaseLongName .= " WHEN phase='2' THEN CONCAT('HAUPT: ',CONCAT($unit,PN.name))";
		$phaseLongName .= " WHEN phase='3' THEN CONCAT('NACHSP: ',CONCAT($unit,PN.name))";
		$phaseLongName .= " ELSE CONCAT($unit,PN.name) END";
		return $phaseLongName;
	}

	private function getJobsToPrint($pdo, $kind, $printer, $queueIds)
	{
		if (is_null($queueIds) || (count($queueIds) == 0)) {
			return array();
		}
		$queueStr = implode(',', $queueIds);

		$decpoint = CommonUtils::getConfigValue($pdo, 'decpoint', '.');

		$groupWorkItems = 'groupworkitemsf';
		if ($kind == 1) {
			$groupWorkItems = 'groupworkitemsd';
		}
		$sql = "SELECT setting FROM %config% where name=?";
		$row = CommonUtils::getRowSqlObject($pdo, $sql, array($groupWorkItems));
		$groupworkitems = $row->setting;
		if (is_null($groupworkitems)) {
			$groupworkitems = 1;
		}

		$phaseLongName = self::getPhaseAndLongNameForWorkReceiptSql($pdo);
		$sql = "SELECT Q.id as id,Q.tablenr as tableid, Q.productid,($phaseLongName) as longname,";
		$sql .= "Q.price as price,Q.togo as togo,";
		$sql .= "anoption,PT.kind as kind,PT.printer as printer,fixbind,phase FROM %queue% Q,%prodnames% PN,%products% P,%prodtype% PT ";
		$sql .= "WHERE Q.prodnameid=PN.id AND PT.kind=? AND Q.id IN ($queueStr) AND productid=P.id AND P.category=PT.id ORDER BY phase,longname";
		$queueItems = CommonUtils::fetchSqlAll($pdo, $sql, array($kind));

		$jobs = array();
		foreach ($queueItems as $aQueueItem) {
			$thePrinter = $aQueueItem["printer"];
			$fixbind = $aQueueItem["fixbind"];

			$queueid = $aQueueItem["id"];
			$tableid = $aQueueItem["tableid"];

			if ($fixbind == 0) {
				if (!is_null($tableid) && ($tableid != 0)) {
					$sql = "SELECT printer FROM %resttables% T,%room% R WHERE T.id=? AND T.roomid=R.id";
					$r = CommonUtils::fetchSqlAll($pdo, $sql, array($tableid));
					if (count($r) > 0) {
						$roomPrinter = $r[0]["printer"];
						if (!is_null($roomPrinter)) {
							$thePrinter = $roomPrinter;
						}
					}
				} else {
					$roomPrinter = CommonUtils::getConfigValue($pdo, "togoworkprinter", 0);
					if ($roomPrinter != 0) {
						$thePrinter = $roomPrinter;
					}
				}
			}

			if ($thePrinter == $printer) {
				$extras = $this->getExtrasOfQueueItem($pdo, $queueid);
				$formattedPrice = number_format($aQueueItem["price"], 2, $decpoint, '');

				$theEntry = array(
					"id" => $queueid,
					"productid" => $aQueueItem["productid"],
					"longname" => $aQueueItem["longname"],
					"singleprod" => $aQueueItem["longname"],
					"price" => $formattedPrice,
					"togo" => $aQueueItem["togo"],
					"anoption" => $aQueueItem["anoption"],
					"kind" => $aQueueItem["kind"],
					"printer" => $aQueueItem["printer"],
					"phase" => $aQueueItem["phase"],
					"extras" => $extras
				);
				if ($groupworkitems == 1) {
					$this->grouping($jobs, $theEntry);
				} else {
					$jobs[] = $theEntry;
				}
			}
		}

		if ($groupworkitems == 1) {
			$jobidsOfThisJob = array();
			foreach ($jobs as &$aJob) {
				$aJob["singleprod"] = $aJob["longname"];
				$cnt = $aJob["count"];
				$aJob["longname"] = $cnt . "x " . $aJob["longname"];
				$aJob["allqueueids"] = $aJob["queueids"];
			}
		}

		return $jobs;
	}


	private function grouping(&$collection, $entry)
	{
		$extrasTxt = join(",", $entry["extras"]);
		$found = false;
		foreach ($collection as &$anEntry) {
			if (
				($anEntry["longname"] == $entry["longname"]) && ($anEntry["price"] == $entry["price"]) && ($anEntry["phase"] == $entry["phase"]) &&
				($anEntry["togo"] == $entry["togo"]) && ($anEntry["anoption"] == $entry["anoption"]) && ($anEntry["productid"] == $entry["productid"]) && (join(",", $anEntry["extras"]) == $extrasTxt)
			) {
				$found = true;
				$anEntry["count"] = $anEntry["count"] + 1;
				$anEntry["queueids"][] = $entry["id"];
			}
		}
		if (!$found) {
			$collection[] = array(
				"id" => $entry["id"],
				"productid" => $entry["productid"],
				"longname" => $entry["longname"],
				"phase" => $entry["phase"],
				"singleprod" => $entry["singleprod"],
				"printer" => $entry["printer"],
				"anoption" => $entry["anoption"],
				"price" => $entry["price"],
				"togo" => $entry["togo"],
				"kind" => $entry["kind"],
				"extras" => $entry["extras"],
				"count" => 1,
				"queueids" => array($entry["id"])
			);
		}
	}

	private function filterKindQueueIds($pdo, $idArr, $kind)
	{
		$retArr = array();
		if (is_null($idArr) || (count($idArr) == 0)) {
			return $retArr;
		}

		$sql = "SELECT Q.id as id,kind from %prodtype% T,%products% P,%queue% Q where Q.id=? AND productid=P.id AND category=T.id";
		$stmt = $pdo->prepare(DbUtils::substTableAlias($sql));
		foreach ($idArr as $id) {
			$stmt->execute(array($id));
			$row = $stmt->fetchObject();
			if ($row->kind == $kind) {
				$retArr[] = $id;
			}
		}
		return $retArr;
	}

	// ----------------------------------------------------------------------
	// SG HUETTENFELD - ANPASSUNG "BONSCHLEUDER" (Anirudha Talmale)
	//
	// Im Original entscheiden allein die Konfigurationswerte oneprodworkrecf
	// (Speisen) und oneprodworkrecd (Getraenke), ob je Artikel ein eigener
	// Arbeitsbon gedruckt wird. Diese Werte gelten fuer die ganze Installation.
	// Fuer das Vereinsfest werden aber beide Betriebsarten gleichzeitig
	// gebraucht: an der Kasse ein Bon je Artikel (5 Bier = 5 Bons), bei den
	// Bedienungen dagegen ein Sammelbon je Bestellung.
	//
	// Die Entscheidung haengt deshalb hier am buchenden Benutzer. In der
	// Konfigurationstabelle steht unter dem Namen 'singlebonusers' eine mit
	// Komma getrennte Liste von Benutzer-IDs (z.B. "2" fuer den Benutzer
	// Kasse). Wer darin steht, bekommt Einzelbons, alle anderen Sammelbons.
	// Ist der Eintrag leer oder nicht vorhanden, verhaelt sich die Funktion
	// exakt wie das Original.
	//
	// Nebeneffekt: die Originalfassung verliert die Getraenkebons, wenn
	// oneprodworkrecf=1 und oneprodworkrecd=0 gesetzt sind. Diese Fassung
	// behandelt Speisen und Getraenke getrennt und kann das nicht.
	//
	// NACH EINEM VERSIONSUPDATE VON ORDERSPRINTER MUSS DIESE FUNKTION
	// ERNEUT EINGESETZT WERDEN.
	// ----------------------------------------------------------------------
	private function isSingleBonUser($pdo, $userid)
	{
		$userList = CommonUtils::getConfigValue($pdo, 'singlebonusers', '');
		if (is_null($userList) || (trim($userList) === '')) {
			return null; // keine Benutzerliste gepflegt -> Originalverhalten
		}
		foreach (explode(',', $userList) as $anId) {
			if (trim($anId) !== '' && intval($anId) === intval($userid)) {
				return true;
			}
		}
		return false;
	}

	private function doWorkPrint($pdo, $theTableid, $insertedQueueIds, $username, $userid, $payPrintType, $lang, $declareReadyDelivered = true, string $orderOption = "")
	{
		$oneProdForEachWorkRecf = CommonUtils::getConfigValue($pdo, 'oneprodworkrecf', 0);
		$oneProdForEachWorkRecd = CommonUtils::getConfigValue($pdo, 'oneprodworkrecd', 0);

		$singleBonUser = $this->isSingleBonUser($pdo, $userid);
		if (!is_null($singleBonUser)) {
			$oneProdForEachWorkRecf = $singleBonUser ? 1 : 0;
			$oneProdForEachWorkRecd = $singleBonUser ? 1 : 0;
		}

		$foodIds = $this->filterKindQueueIds($pdo, $insertedQueueIds, 0);
		$drinkIds = $this->filterKindQueueIds($pdo, $insertedQueueIds, 1);

		if ($oneProdForEachWorkRecf == 1) {
			foreach ($foodIds as $aQueueId) {
				$this->doWorkPrintCore($pdo, $theTableid, array($aQueueId), $username, $userid, $payPrintType, $lang, $declareReadyDelivered, $orderOption);
			}
		} else {
			$this->doWorkPrintCore($pdo, $theTableid, $foodIds, $username, $userid, $payPrintType, $lang, $declareReadyDelivered, $orderOption);
		}

		if ($oneProdForEachWorkRecd == 1) {
			foreach ($drinkIds as $aQueueId) {
				$this->doWorkPrintCore($pdo, $theTableid, array($aQueueId), $username, $userid, $payPrintType, $lang, $declareReadyDelivered, $orderOption);
			}
		} else {
			$this->doWorkPrintCore($pdo, $theTableid, $drinkIds, $username, $userid, $payPrintType, $lang, $declareReadyDelivered, $orderOption);
		}
	}

	private function setWorkPrinter($pdo, $jobs, $printer)
	{
		$sql = "UPDATE %queue% SET workprinter=? WHERE id=?";
		foreach ($jobs as $j) {
			$queueIds = $j['allqueueids'] ?? array($j['id']);
			foreach ($queueIds as $queueid) {
				CommonUtils::execSql($pdo, $sql, array($printer, $queueid));
			}
		}
	}

	private function setWorkPrinterForNewQueueEntries($pdo, $insertedQueueIds)
	{
		$foodJobsPrinter1 = $this->getJobsToPrint($pdo, 0, 1, $insertedQueueIds);
		$foodJobsPrinter2 = $this->getJobsToPrint($pdo, 0, 2, $insertedQueueIds);
		$foodJobsPrinter3 = $this->getJobsToPrint($pdo, 0, 3, $insertedQueueIds);
		$foodJobsPrinter4 = $this->getJobsToPrint($pdo, 0, 4, $insertedQueueIds);
		$drinkJobsPrinter1 = $this->getJobsToPrint($pdo, 1, 1, $insertedQueueIds);
		$drinkJobsPrinter2 = $this->getJobsToPrint($pdo, 1, 2, $insertedQueueIds);
		$drinkJobsPrinter3 = $this->getJobsToPrint($pdo, 1, 3, $insertedQueueIds);
		$drinkJobsPrinter4 = $this->getJobsToPrint($pdo, 1, 4, $insertedQueueIds);

		$this->setWorkPrinter($pdo, $foodJobsPrinter1, 1);
		$this->setWorkPrinter($pdo, $foodJobsPrinter2, 2);
		$this->setWorkPrinter($pdo, $foodJobsPrinter3, 3);
		$this->setWorkPrinter($pdo, $foodJobsPrinter4, 4);
		$this->setWorkPrinter($pdo, $drinkJobsPrinter1, 1);
		$this->setWorkPrinter($pdo, $drinkJobsPrinter2, 2);
		$this->setWorkPrinter($pdo, $drinkJobsPrinter3, 3);
		$this->setWorkPrinter($pdo, $drinkJobsPrinter4, 4);

		return array(
			$foodJobsPrinter1,
			$foodJobsPrinter2,
			$foodJobsPrinter3,
			$foodJobsPrinter4,
			$drinkJobsPrinter1,
			$drinkJobsPrinter2,
			$drinkJobsPrinter3,
			$drinkJobsPrinter4
		);
	}

	private function doWorkPrintCore($pdo, $theTableid, $insertedQueueIds, $username, $userid, $payPrintType, $lang, $declareReadyDelivered = true, string $orderOption = "")
	{

		$printJobsByKindAndPrinter = $this->setWorkPrinterForNewQueueEntries($pdo, $insertedQueueIds);
		$foodJobsPrinter1 = $printJobsByKindAndPrinter[0];
		$foodJobsPrinter2 = $printJobsByKindAndPrinter[1];
		$foodJobsPrinter3 = $printJobsByKindAndPrinter[2];
		$foodJobsPrinter4 = $printJobsByKindAndPrinter[3];
		$drinkJobsPrinter1 = $printJobsByKindAndPrinter[4];
		$drinkJobsPrinter2 = $printJobsByKindAndPrinter[5];
		$drinkJobsPrinter3 = $printJobsByKindAndPrinter[6];
		$drinkJobsPrinter4 = $printJobsByKindAndPrinter[7];

		if ($payPrintType == "s") {
			$this->createAWorkReceiptAndQueueWorkPrint($pdo, $foodJobsPrinter1, $theTableid, 0, 1, $username, $userid, $lang, $orderOption);
			$this->createAWorkReceiptAndQueueWorkPrint($pdo, $foodJobsPrinter2, $theTableid, 0, 2, $username, $userid, $lang, $orderOption);
			$this->createAWorkReceiptAndQueueWorkPrint($pdo, $foodJobsPrinter3, $theTableid, 0, 3, $username, $userid, $lang, $orderOption);
			$this->createAWorkReceiptAndQueueWorkPrint($pdo, $foodJobsPrinter4, $theTableid, 0, 4, $username, $userid, $lang, $orderOption);
			$this->createAWorkReceiptAndQueueWorkPrint($pdo, $drinkJobsPrinter1, $theTableid, 1, 1, $username, $userid, $lang, $orderOption);
			$this->createAWorkReceiptAndQueueWorkPrint($pdo, $drinkJobsPrinter2, $theTableid, 1, 2, $username, $userid, $lang, $orderOption);
			$this->createAWorkReceiptAndQueueWorkPrint($pdo, $drinkJobsPrinter3, $theTableid, 1, 3, $username, $userid, $lang, $orderOption);
			$this->createAWorkReceiptAndQueueWorkPrint($pdo, $drinkJobsPrinter4, $theTableid, 1, 4, $username, $userid, $lang, $orderOption);
		}
		if ($declareReadyDelivered) {
			$printAndQueueJobs = CommonUtils::getConfigValue($pdo, "printandqueuejobs", 0);
			if ($printAndQueueJobs == 0) {
				$this->declareReadyAndDelivered($pdo, $insertedQueueIds);
			}
		}

		$result = array_merge($foodJobsPrinter1, $foodJobsPrinter2, $drinkJobsPrinter1, $drinkJobsPrinter2);
		return $result;
	}

	private function declareReadyAndDelivered($pdo, $queueids)
	{
		date_default_timezone_set(DbUtils::getTimeZone());
		$now = date('Y-m-d H:i:s');
		$germanTime = date('H:i');
		$sql = "UPDATE %queue% SET workprinted='1',readytime=?,delivertime=? WHERE id=?";
		$stmt = $pdo->prepare(DbUtils::substTableAlias($sql));
		foreach ($queueids as $anId) {
			$stmt->execute(array($now, $now, $anId));
		}
	}
	private function createAWorkReceiptAndQueueWorkPrint($pdo, $jobs, $theTableid, $kind, $printer, $user, $userid, $lang, string $orderOption, bool $isCancelBon = false)
	{
		if (count($jobs) < 1) {
			return;
		}
		date_default_timezone_set(DbUtils::getTimeZone());
		$germanTime = date('H:i');

		$resultarray = array();
		foreach ($jobs as $aJob) {
			$queueId = $aJob["id"];

			$extras = $this->getExtrasOfQueueItemInTicketFormat($pdo, $queueId);

			$togo = "";
			if ($aJob['togo'] == 1) {
				$togo = "To-Go";
			}

			$arr = array(
				"id" => $queueId,
				"longname" => $aJob['longname'],
				"singleprod" => $aJob['singleprod'],
				"price" => $aJob['price'],
				"togo" => $togo,
				"option" => $aJob['anoption'],
				"extras" => $extras,
				"ordertime" => $germanTime,
				"kind" => $kind,
				"printer" => $printer
			);
			if (isset($aJob["allqueueids"])) {
				$arr["allqueueids"] = $aJob["allqueueids"];
			}
			$resultarray[] = $arr;
		}

		if (is_null($theTableid) || ($theTableid == 0)) {
			$takeAwayStr = array("Zum Mitnehmen", "Take away", "Para llevar");
			$tablename = $takeAwayStr[$lang];
		} else {
			$sql = "SELECT tableno,%room%.abbreviation FROM %resttables%,%room% WHERE %resttables%.id=? AND %resttables%.roomid=%room%.id";
			$row = CommonUtils::getRowSqlObject($pdo, $sql, array($theTableid));

			if (is_null($row->abbreviation) || ($row->abbreviation == '')) {
				$tablename = $row->tableno;
			} else {
				$tablename = $row->abbreviation . "-" . $row->tableno;
			}
		}

		$isTogo = false;
		if (is_null($theTableid)) {
			$isTogo = true;
		}
		PrintQueue::queueWorkPrintJob($pdo, $tablename, $germanTime, $resultarray, $kind, $printer, $user, $userid, $isTogo, $orderOption, $isCancelBon);
	}

	private function queryQueueItemsForKitchenBar($pdo, $kind, int $workprinter, bool $toMake): array
	{
		$lastQueueIdInCls = self::getQueueIdOfNewestClosing($pdo);
		$phaseLongName = self::getPhaseAndLongNameSql($pdo);

		date_default_timezone_set(DbUtils::getTimeZone());
		$currentTime = date('Y-m-d H:i:s');

		$sql = "SELECT DISTINCT Q.id as id,IF(Q.orderuser IS NULL,'1','0') as isguestorder,"
			. "COALESCE(R.id,'-') as tableid,COALESCE(tablenr,'-') as tablenr,Q.unit,Q.pickupid as pickupid,"
			. "($phaseLongName) as longname,phase,"
			. "productid as prodid,anoption,COALESCE(tableno,'-') as tableno,readytime,"
			. "date_format(ordertime,'%Y-%m-%d %H:%i:00') as ordertime,"
			. "COALESCE(cooking,'0') as cooking,TIMESTAMPDIFF(MINUTE,ordertime,?) AS waittime,U.username as username,"
			. "COALESCE(P.bgcolor,'') as bgcolor,COALESCE(P.fgcolor,'') as fgcolor "
			. " FROM %queue% Q "
			. " INNER JOIN %prodnames% PN ON Q.prodnameid=PN.id "
			. " INNER JOIN %products% P ON Q.productid=P.id "
			. " INNER JOIN %prodtype% PT ON P.category=PT.id "
			. " LEFT OUTER JOIN %resttables% R ON Q.tablenr=R.id "
			. " LEFT OUTER JOIN %user% U ON Q.orderuser=U.id "
			. ""
			. " WHERE "
			. "Q.id > ? AND "
			. "(Q.isminusarticle IS NULL OR Q.isminusarticle='0') AND "
			. "Q.toremove = '0' AND "
			. "PT.kind= ? AND "
			. "Q.isclosed is null AND "
			. "Q.workprinted='0' AND "
			. "Q.printjobid IS NULL ";
		if ($workprinter != 0) {
			$sql .= " AND Q.workprinter='$workprinter' ";
		}
		if ($toMake) {
			$sql .= " AND readytime IS NULL "
				. "ORDER BY phase,ordertime,longname,anoption,cooking DESC,Q.id";
		} else {
			$sql .= " AND readytime IS NOT NULL AND delivertime IS NULL "
				. "ORDER BY phase,readytime DESC,longname,anoption LIMIT 10";
		}
		return CommonUtils::fetchSqlAll($pdo, $sql, array($currentTime, $lastQueueIdInCls, $kind));
	}

	private function areBasicTablesAvailable($pdo)
	{
		// To avoid ugly log in error_log in case of re-installation or import check for existence of some tables
		if (!CommonUtils::areAllTableExisting($pdo, array("%queue%", "%products%", "%prodtype%", "%resttables%"))) {
			return true;
		} else {
			return true;
		}
	}

	private function getJsonLastMadeItems($kind, int $workprinter)
	{
		$pdo = DbUtils::openRepliDb();

		if (!$this->areBasicTablesAvailable($pdo)) {
			$ret = array();
			echo json_encode($ret);
			return;
		}

		$result = $this->queryQueueItemsForKitchenBar($pdo, $kind, $workprinter, false);

		$resultarray = array();
		foreach ($result as $zeile) {
			$extras = $this->getExtrasOfQueueItem($pdo, $zeile['id']);

			$productid = $zeile['prodid'];
			$useConditions = $this->getUseKitchenAndSupplyForProd($pdo, $productid);
			if ($useConditions["usekitchen"] == 1) {
				$arr = array(
					"id" => $zeile['id'],
					"tablename" => $zeile['tableno'],
					"longname" => $zeile['longname'],
					"option" => $zeile['anoption'],
					"prodid" => $zeile['prodid'],
					"extras" => $extras,
					"readytime" => $zeile['readytime'],
					"bgcolor" => $zeile['bgcolor'],
					"fgcolor" => $zeile['fgcolor']
				);
				$resultarray[] = $arr;
			}
		}

		$resultarray = $this->appendProdsForBarKitchenAndAutoDelivery($pdo, $kind, $resultarray);

		echo json_encode($resultarray);
	}

	private function appendProdsForBarKitchenAndAutoDelivery($pdo, $kind, $resultarray)
	{
		$unit = CommonUtils::caseOfSqlUnitSelection($pdo);
		$sql = "SELECT DISTINCT Q.id as id,tableno as tablename,Q.unit,CONCAT($unit,PN.name) as longname,delivertime,productid as prodid,anoption,p.id as prodid,COALESCE(p.bgcolor,'') as bgcolor,COALESCE(p.fgcolor,'') as fgcolor ";
		$sql .= "FROM %queue% Q ";
		$sql .= "INNER JOIN %prodnames% PN ON Q.prodnameid=PN.id ";
		$sql .= "LEFT JOIN %bill% b ON Q.billid=b.id ";

		$sql .= "INNER JOIN %products% p ON Q.productid=p.id ";
		$sql .= "INNER JOIN %prodtype% t ON p.category=t.id AND t.kind=? AND t.usesupplydesk='0' AND t.usekitchen='1' ";
		$sql .= "LEFT JOIN %resttables% r ON Q.tablenr=r.id ";
		$sql .= "WHERE Q.workprinted='0' AND toremove <> '1' AND Q.readytime IS NOT NULL AND Q.toremove='0' ";
		$sql .= " AND (Q.billid is null OR (Q.billid=b.id AND b.closingid is null)) ";
		$sql .= "ORDER BY Q.delivertime DESC LIMIT 50";

		$stmt = $pdo->prepare(DbUtils::substTableAlias($sql));
		$stmt->execute(array($kind));
		$result = $stmt->fetchAll();
		foreach ($result as $zeile) {
			$extras = $this->getExtrasOfQueueItem($pdo, $zeile['id']);
			$deliveredProd = array(
				"id" => $zeile['id'],
				"tablename" => $zeile['tablename'],
				"longname" => $zeile['longname'],
				"option" => $zeile['anoption'],
				"prodid" => $zeile['prodid'],
				"extras" => $extras,
				"readytime" => $zeile['delivertime'],
				"bgcolor" => $zeile['bgcolor'],
				"fgcolor" => $zeile['fgcolor']
			);
			$resultarray[] = $deliveredProd;
		}
		return ($resultarray);
	}

	function getTableIdOfQueue($pdo, $queueid)
	{
		$sql = "SELECT tablenr as tableid FROM %queue% WHERE id=?";
		$row = CommonUtils::getRowSqlObject($pdo, $sql, array($queueid));
		return $row->tableid;
	}

	/*
	 * Kitchen can delare a product as being cooked: returns as echo in msg part: queueid, action: "c","r"
	 */
	function declareProductBeCookingOrCooked($queueid, $workprinter): void
	{
		if (is_numeric($queueid)) {
			$performedAction = "c";

			$pdo = DbUtils::openDbAndReturnPdoStatic();
			$oneclickcooked = CommonUtils::getConfigValue($pdo, 'oneclickcooked', 0);
			$pdo->beginTransaction();

			$delayDigiWorkPrint = CommonUtils::getConfigValue($pdo, 'delaydigiworkprint', 1);

			$sql = "SELECT cooking,productid FROM %queue% WHERE id=?";
			$row = CommonUtils::getRowSqlObject($pdo, $sql, array($queueid));
			if ($row != null) {
				$cooking = $row->cooking;
				$productid = $row->productid;


				if (is_null($cooking)) {
					if ($oneclickcooked == 1) {
						$action = 'r';
					} else {
						$action = 'c';
					}
				} else {
					$action = 'r';
				}

				if ($action == 'r') {
					self::reallyDeclareAsCooked($pdo, $queueid);
					$useConditions = $this->getUseKitchenAndSupplyForProd($pdo, $productid);
					$performedAction = "r";
					if ($useConditions["usesupply"] == 0) {
						self::declareProductBeDeliveredWithGivenPdo($pdo, $queueid);
					} else {
						self::setNewProductsToServe($pdo, 1);
					}

					$payprinttype = CommonUtils::getConfigValue($pdo, 'payprinttype', "l");
					$digiprintwork = CommonUtils::getConfigValue($pdo, 'digiprintwork', 1);
					if (($payprinttype === 's') && ($digiprintwork == 1)) {
						$theTableid = $this->getTableIdOfQueue($pdo, $queueid);
						if (is_null($theTableid)) {
							$theTableid = 0;
						}

						error_log("DelayDigi: $delayDigiWorkPrint");
						if ($delayDigiWorkPrint == 0) {
							$this->doWorkPrint($pdo, $theTableid, array($queueid), $_SESSION['currentuser'], $_SESSION['userid'], $payprinttype, $_SESSION['language'], false, "");
						} else {
							$printStatus = self::getPrintStatusOfSameKitchenOrBarSection($pdo, $theTableid, $queueid, $workprinter);
							if ($printStatus['readyforprint']) {
								$this->doWorkPrint($pdo, $theTableid, $printStatus['queueids'], $_SESSION['currentuser'], $_SESSION['userid'], $payprinttype, $_SESSION['language'], false, "");
								self::removeFlagForWaitForPrint($pdo, $printStatus['queueids']);
							}
						}
					}

					$this->checkAndMarkPickupReadyIfComplete($pdo, $queueid);

					$pdo->commit();
				} else if ($action == 'c') {
					$userid = CommonUtils::getUserId();
					$updSql = "UPDATE %queue% SET cooking=? WHERE id=?";
					$stmt = $pdo->prepare($this->dbutils->resolveTablenamesInSqlString($updSql));
					$stmt->execute(array($userid, $queueid));
					$pdo->commit();
					$performedAction = "c";

					$sql = "SELECT (CASE WHEN T.kind=0 THEN ? WHEN T.kind=1 THEN ? END) as kind from %products% P, %prodtype% T WHERE P.category=T.id AND P.id=?";
					$result = CommonUtils::fetchSqlAll($pdo, $sql, array(UsedFeatures::$Kitchen["id"], UsedFeatures::$Bar["id"], $productid));
					if (count($result) > 0) {
						$kind = $result[0]['kind'];
						UsedFeatures::noteUsedFeatureById($pdo, $kind);
					}
				}
				echo json_encode(array("status" => "OK", "msg" => array("queueid" => $queueid, "performedaction" => $performedAction)));
			} else {
				$pdo->rollBack();
			}
		} else {
			echo json_encode(array("status" => "ERROR", "code" => ERROR_GENERAL_ID_TYPE, "msg" => ERROR_GENERAL_ID_TYPE_MSG));
		}
	}

	private static function removeFlagForWaitForPrint($pdo, array $queueids)
	{
		$sql = "UPDATE %queue% SET waitforprint=null WHERE id=?";
		foreach ($queueids as $id) {
			CommonUtils::execSql($pdo, $sql, array($id));
		}
	}

	private static function getPrintStatusOfSameKitchenOrBarSection($pdo, $tableid, $queueid, $workprinter)
	{
		CommonUtils::execSql($pdo, "UPDATE %queue% SET waitforprint=? WHERE id=?", array(1, $queueid));
		$lastQueueIdInCls = self::getQueueIdOfNewestClosing($pdo);
		$togoCcheck = "";
		if (is_null($tableid) || ($tableid == 0)) {
			// togo
			$togoCcheck = " OR (tablenr is null) ";
		}

		if ($workprinter == 0) {
			// kitchen/bar is for all printers
			$workprinterConstraint = "";
		} else {
			$wprinter = 0;
			if (is_string($workprinter)) {
				$wprinter = intval($workprinter);
			} else if (is_int($workprinter)) {
				$wprinter = $workprinter;
			}
			$workprinterConstraint = " AND workprinter='$wprinter' ";
		}

		$sql = "SELECT T.kind from %queue% Q,%products% P,%prodtype% T WHERE Q.id=? AND Q.productid=P.id AND P.category=T.id";
		$res = CommonUtils::fetchSqlAll($pdo, $sql, array($queueid));
		$kind = 0;
		if (count($res) > 0) {
			$kind = $res[0]['kind'];
		}

		$sqlAllOfTable = "SELECT Q.id FROM %queue% Q ,%products% P,%prodtype% T "
			. "WHERE Q.id>? AND Q.toremove='0' "
			. $workprinterConstraint
			. "AND Q.delivertime IS NULL "
			. "AND Q.printjobid IS NULL "
			. "AND (tablenr=? $togoCcheck) AND Q.clsid IS NULL AND Q.isclosed IS NULL AND Q.ordertype=? "
			. "AND Q.productid=P.id AND P.category=T.id AND T.kind=?";
		$resultAllOfTable = CommonUtils::fetchSqlAll($pdo, $sqlAllOfTable, array($lastQueueIdInCls, $tableid, DbUtils::$ORDERTYPE_PRODUCT, $kind));

		$sqlWaitForPrint = $sqlAllOfTable . " AND waitforprint IS NOT NULL ";
		$resultAllOfTableWaitForPrint = CommonUtils::fetchSqlAll($pdo, $sqlWaitForPrint, array($lastQueueIdInCls, $tableid, DbUtils::$ORDERTYPE_PRODUCT, $kind));

		$countAll = count($resultAllOfTable);
		$countWaiting = count($resultAllOfTableWaitForPrint);

		if ($countAll == $countWaiting) {
			$queueIds = array();
			foreach ($resultAllOfTableWaitForPrint as $aQueueEntry) {
				$queueIds[] = $aQueueEntry['id'];
			}
			return array("readyforprint" => true, "queueids" => $queueIds);
		} else {
			return array("readyforprint" => false);
		}
	}

	private static function reallyDeclareAsCooked($pdo, $queueid)
	{
		date_default_timezone_set(DbUtils::getTimeZone());
		$readytime = date('Y-m-d H:i:s');
		$insertSql = "UPDATE %queue% SET readytime=? WHERE id=?";
		CommonUtils::execSql($pdo, $insertSql, array($readytime, $queueid));
	}

	private function checkAndMarkPickupReadyIfComplete($pdo, $queueid)
	{
		$sql = "SELECT pickupid FROM %queue% WHERE id=?";
		$row = CommonUtils::getRowSqlObject($pdo, $sql, array($queueid));
		if (($row == null) || is_null($row->pickupid)) {
			return;
		}
		$pickupid = $row->pickupid;

		$sql = "SELECT id FROM %queue% WHERE pickupid=? AND readytime IS NULL";
		$openItems = CommonUtils::fetchSqlAll($pdo, $sql, array($pickupid));
		if (count($openItems) > 0) {
			return;
		}

		$sql = "UPDATE %printjobs% SET pickready=? WHERE type=? AND pickupid=?";
		CommonUtils::execSql($pdo, $sql, array(1, PrintQueue::$PICKUP, $pickupid));
	}

	private function createPickupOnlyReceipt($pdo, $pickupid, $username, $userid)
	{
		date_default_timezone_set(DbUtils::getTimeZone());
		$germanTime = date('H:i');

		$sql = "SELECT Q.id as id,Q.tablenr as tablenr,Q.anoption as anoption,Q.togo as togo,Q.price as price,"
			. "PN.name as productname,T.kind as kind "
			. "FROM %queue% Q "
			. "INNER JOIN %prodnames% PN ON Q.prodnameid=PN.id "
			. "INNER JOIN %products% P ON Q.productid=P.id "
			. "INNER JOIN %prodtype% T ON P.category=T.id "
			. "WHERE Q.pickupid=?";
		$rows = CommonUtils::fetchSqlAll($pdo, $sql, array($pickupid));
		if (count($rows) < 1) {
			return;
		}

		$theTableid = $rows[0]['tablenr'];

		$resultarray = array();
		foreach ($rows as $aRow) {
			$extras = $this->getExtrasOfQueueItemInTicketFormat($pdo, $aRow['id']);
			$togo = "";
			if ($aRow['togo'] == 1) {
				$togo = "To-Go";
			}
			$resultarray[] = array(
				"id" => $aRow['id'],
				"longname" => $aRow['productname'],
				"singleprod" => $aRow['productname'],
				"price" => $aRow['price'],
				"togo" => $togo,
				"option" => $aRow['anoption'],
				"extras" => $extras,
				"ordertime" => $germanTime,
				"kind" => $aRow['kind']
			);
		}

		if (is_null($theTableid) || ($theTableid == 0)) {
			$takeAwayStr = array("Zum Mitnehmen", "Take away", "Para llevar");
			$tablename = $takeAwayStr[$_SESSION['language']];
		} else {
			$sql = "SELECT tableno,%room%.abbreviation FROM %resttables%,%room% WHERE %resttables%.id=? AND %resttables%.roomid=%room%.id";
			$row = CommonUtils::getRowSqlObject($pdo, $sql, array($theTableid));
			if (is_null($row->abbreviation) || ($row->abbreviation == '')) {
				$tablename = $row->tableno;
			} else {
				$tablename = $row->abbreviation . "-" . $row->tableno;
			}
		}

		PrintQueue::queuePickupOnlyJob($pdo, $tablename, $germanTime, $resultarray, $pickupid, $username, $userid);
	}

	/*
	 * Product is not cooked (undo of kitchen)
	 */
	function declareProductNotBeCooked($queueid)
	{
		if (is_numeric($queueid)) {
			$pdo = $this->dbutils->openDbAndReturnPdo();
			$pdo->beginTransaction();

			$sql = "SELECT id FROM  %queue% WHERE id=? AND readytime IS NOT NULL";
			$row = CommonUtils::getRowSqlObject($pdo, $sql, array($queueid));
			if ($row != null) {
				$foundid = $row->id;
				if ($foundid == $queueid) {
					$sql = "UPDATE %queue% SET readytime=?, delivertime=?, cooking=NULL,printjobid=null,waitforprint=null WHERE id=?";
					$stmt = $pdo->prepare($this->dbutils->resolveTablenamesInSqlString($sql));
					$stmt->execute(array(null, null, $queueid));
					$pdo->commit();
					echo json_encode(array("status" => "OK", "msg" => array("queueid" => $queueid, "performedaction" => "n")));
				} else {
					echo json_encode(array("status" => "ERROR", "code" => ERROR_DB_PAR_ACCESS, "msg" => ERROR_DB_PAR_ACCESS_MSG));
					$pdo->rollBack();
				}
			} else {
				$pdo->rollBack();
				echo json_encode(array("status" => "ERROR", "code" => ERROR_DB_PAR_ACCESS, "msg" => ERROR_DB_PAR_ACCESS_MSG));
			}
		} else {
			echo json_encode(array("status" => "ERROR", "code" => ERROR_GENERAL_ID_TYPE, "msg" => ERROR_GENERAL_ID_TYPE_MSG));
		}
	}

	private function findCategoryOfProd($pdo, $prodid)
	{
		$sql = "SELECT category FROM %products% WHERE id=?";
		$row = CommonUtils::getRowSqlObject($pdo, $sql, array($prodid));
		return $row->category;
	}

	private function getUseKitchenAndSupplyForProdInCat($pdo, $catid)
	{
		$sql = "SELECT usekitchen, usesupplydesk FROM %prodtype% WHERE id=?";
		$row = CommonUtils::getRowSqlObject($pdo, $sql, array($catid));
		return array("usekitchen" => $row->usekitchen, "usesupply" => $row->usesupplydesk);
	}

	private function getUseKitchenAndSupplyForProd($pdo, $prodid)
	{
		$catid = $this->findCategoryOfProd($pdo, $prodid);
		return $this->getUseKitchenAndSupplyForProdInCat($pdo, $catid);
	}

	private static function getUseKitchenAndSupplyForProdWithPdo($pdo, $prodid)
	{
		$sql = "SELECT usekitchen, usesupplydesk FROM %prodtype%,%products% WHERE %products%.category=%prodtype%.id AND %products%.id=?";
		$row = CommonUtils::getRowSqlObject($pdo, $sql, array($prodid));
		if ($row != null) {
			return array("usekitchen" => $row->usekitchen, "usesupply" => $row->usesupplydesk);
		} else {
			return array("usekitchen" => "1", "usesupply" => "1");
		}
	}

	private function getAllOrders()
	{
		if (!($this->userrights->hasCurrentUserRight('right_statistics'))) {
			echo json_encode(array("status" => "ERROR", "msg" => "Benutzerrechte nicht ausreichend"));
			return false;
		}
		$pdo = DbUtils::openRepliDb();
		$sql = "SELECT count(Q.id) as countid,productid,price,PN.name as productname,ordertime,COALESCE(username,'GAST') as username,COALESCE(toremove,0) as toremove,IF(billid is null,0,1) as ispaid,COALESCE(tableno,'To-Go') as tablename ";
		$sql .= " FROM %queue% Q INNER JOIN %prodnames% PN ON Q.prodnameid=PN.id LEFT JOIN %resttables% R ON Q.tablenr=R.id LEFT JOIN %user% U ON Q.orderuser=U.id ";
		$sql .= " WHERE isclosed is null ";
		$sql .= " group by ordertime,PN.name,productid,price,toremove,ispaid,tablename,username ORDER BY ordertime,tablename,ispaid";
		$allorders = CommonUtils::fetchSqlAll($pdo, $sql);

		$priceSumSql = CommonUtils::getUnpaidPriceSumSqlWithOpenGuestBillsAsUnpaid();
		$sql = "SELECT $priceSumSql as sumprice FROM %queue% Q LEFT JOIN %bill% B ON Q.billid=B.id WHERE toremove=?";
		$sumUnpaidRes = CommonUtils::fetchSqlAll($pdo, $sql, array(0));
		$sumUnpaid = $sumUnpaidRes[0]["sumprice"];

		$priceSumPaidSql = CommonUtils::getPaidPriceSumSqlWithOpenGuestBillsAsUnpaid();
		$sql = "SELECT $priceSumPaidSql as sumprice FROM %queue% Q LEFT JOIN %bill% B ON Q.billid=B.id WHERE toremove=?";
		$sumPaidRes = CommonUtils::fetchSqlAll($pdo, $sql, array(0));
		$sumPaid = $sumPaidRes[0]["sumprice"];

		$sumAll = (string) number_format($sumUnpaid + $sumPaid, 2, '.', '');

		$sql = "SELECT COALESCE(SUM(price),'0.00') as sumprice FROM %queue% Q WHERE isclosed is null AND toremove=? AND billid is null";
		$sumCancelledRes = CommonUtils::fetchSqlAll($pdo, $sql, array(1));
		$sumCancelled = $sumCancelledRes[0]["sumprice"];

		$result = array(
			"list" => $allorders,
			"sumall" => $sumAll,
			"sumunpaid" => $sumUnpaid,
			"sumpaid" => $sumPaid,
			"sumcancelled" => $sumCancelled
		);
		echo json_encode(array("status" => "OK", "msg" => $result));
	}

	function getAllTableRecords()
	{
		if (!($this->userrights->hasCurrentUserRight('right_statistics'))) {
			echo json_encode(array("status" => "ERROR", "msg" => "Benutzerrechte nicht ausreichend"));
			return false;
		}
		$pdo = DbUtils::openRepliDb();
		$sql = "SELECT id,roomname FROM %room% WHERE removed is null";
		$allrooms = CommonUtils::fetchSqlAll($pdo, $sql);
		$roomsOut = array();
		$sql = "SELECT id,tableno FROM %resttables% WHERE roomid=? AND removed is null";
		foreach ($allrooms as $aRoom) {

			$tablesOfRoom = CommonUtils::fetchSqlAll($pdo, $sql, array($aRoom["id"]));
			$tablesOut = array();
			foreach ($tablesOfRoom as $aTable) {
				$recordsOfThisTable = $this->getRecordsCore($pdo, $aTable["id"]);
				$tablesOut[] = array("tablename" => $aTable["tableno"], "records" => $recordsOfThisTable);
			}
			$roomsOut[] = array("roomname" => $aRoom["roomname"], "tables" => $tablesOut);
		}
		echo json_encode(array("status" => "OK", "msg" => $roomsOut));
	}

	function getRecords($pdo, $tableid)
	{
		if (!($this->userrights->hasCurrentUserRight('right_waiter')) && !($this->userrights->hasCurrentUserRight('right_paydesk'))) {
			return array("status" => "ERROR", "msg" => "Benutzerrechte nicht ausreichend");
		}
		$out = $this->getRecordsCore($pdo, $tableid);
		echo json_encode($out);
	}
	function getRecordsCore($pdo, $tableid)
	{
		if ($tableid != 0) {
			$sql = "SELECT id,date,TIME(date) as time,(IF(userid is null,'-',(SELECT username FROM %user% WHERE %user%.id=userid))) as username,action,tableid FROM %records% WHERE tableid=? ORDER BY date DESC";
			$entries = CommonUtils::fetchSqlAll($pdo, $sql, array($tableid));
		} else {
			$sql = "SELECT id,date,TIME(date) as time,(IF(userid is null,'-',(SELECT username FROM %user% WHERE %user%.id=userid))) as username,action,tableid FROM %records% WHERE tableid is null ORDER BY date DESC";
			$entries = CommonUtils::fetchSqlAll($pdo, $sql, null);
		}
		$records = array();
		foreach ($entries as $anEntry) {
			$sql = "SELECT queueid FROM %recordsqueue% WHERE recordid=?";
			$queueids = CommonUtils::fetchSqlAll($pdo, $sql, array($anEntry["id"]));
			$prods = array();
			foreach ($queueids as $queueid) {
				$prodNameWithMinus = "(CASE WHEN isminusarticle='1' THEN CONCAT(longname,' (Minusartikel)') ELSE longname END) ";
				$sql = "SELECT productid,$prodNameWithMinus as longname,COALESCE(anoption,'') as anoption FROM %products%,%queue% WHERE %queue%.id=? AND %queue%.productid=%products%.id";
				$prodInfo = CommonUtils::fetchSqlAll($pdo, $sql, array($queueid["queueid"]));
				if (count($prodInfo) == 0) {
					break;
				}

				$sql = "SELECT extraid,CONCAT(amount,'x ',name) as name FROM %queueextras% WHERE queueid=?";
				$extras = CommonUtils::fetchSqlAll($pdo, $sql, array($queueid["queueid"]));

				$extrasArr = array();
				foreach ($extras as $e) {
					$extrasArr[] = $e["name"];
				}
				$extrasStr = implode(',', $extrasArr);

				$prods[] = array("name" => $prodInfo[0]["longname"], "extras" => $extrasStr, "comment" => $prodInfo[0]["anoption"]);
			}
			$records[] = array("id" => $anEntry["id"], "time" => $anEntry["time"], "username" => $anEntry["username"], "action" => $anEntry["action"], "prods" => $prods);
		}
		return array("status" => "OK", "msg" => $records);
	}

	function addAndCashProdByRestCall($pdo, $data)
	{

		if (!isset($data["remotecode"])) {
			echo json_encode(array("status" => "ERROR", "msg" => "Remote code not sent in POST data"));
			return;
		}
		if (!isset($data["price"])) {
			echo json_encode(array("status" => "ERROR", "msg" => "Price not sent in POST data"));
			return;
		}
		if (!isset($data["prodid"])) {
			echo json_encode(array("status" => "ERROR", "msg" => "Product id not sent in POST data"));
			return;
		}
		if (!isset($data["userid"])) {
			echo json_encode(array("status" => "ERROR", "msg" => "User id not sent in POST data"));
			return;
		}
		$printer = -1;
		if (isset($data["printer"])) {
			$printer = $data["printer"];
		}

		$remotecode = $data["remotecode"];
		$prodid = $data["prodid"];
		$price = $data["price"];
		$userid = $data["userid"];

		$remote = CommonUtils::getConfigValue($pdo, 'remoteaccesscode', '');
		if (is_null($remote) || ($remote == '')) {
			echo json_encode(array("status" => "ERROR", "msg" => "No remote access code set in configuration. No booking allowed."));
			return;
		}

		if (md5($remotecode) != $remote) {
			echo json_encode(array("status" => "ERROR", "msg" => "Wrong access code given!"));
			return;
		}

		if (!is_numeric($price)) {
			echo json_encode(array("status" => "ERROR", "msg" => "Price not numerical!"));
			return;
		}

		date_default_timezone_set(DbUtils::getTimeZone());
		$ordertime = date('Y-m-d H:i:s');
		$aProd = array(
			"name" => "",
			"option" => "",
			"extras" => "",
			"prodid" => $prodid,
			"changedPrice" => "NO",
			"togo" => 0,
			"unit" => 0,
			"price" => $price,
			"unitamount" => 1
		);
		$doPrint = 0;
		if ($printer >= 0) {
			$doPrint = 1;
		}
		$ret = $this->addProductListToQueueCore($pdo, $ordertime, null, array($aProd), $doPrint, "s", $userid, 1, null, "", self::INTERNAL_ORDER);
		echo json_encode($ret);
	}

	private function cancelProductListToQueue($theTableid, $prods, $order, $orderOption, $cancelcode)
	{
		$pdo = DbUtils::openDbAndReturnPdoStatic();
		$configuredPaydeskStornoCode = CommonUtils::getConfigValue($pdo, "cancelinpaydesk", '');
		if (($configuredPaydeskStornoCode != '') && ($configuredPaydeskStornoCode != $cancelcode)) {
			echo json_encode(array("status" => "ERROR", "msg" => "Falscher Stornocode"));
			return;
		} else {
			$this->addProductListToQueue($pdo, $theTableid, $prods, 0, 's', $order, "");
		}
	}

	function addProductListToQueue($pdo, $theTableid, $prods, $doPrint, $payprinttype, $order = null, $orderOption = "")
	{
		date_default_timezone_set(DbUtils::getTimeZone());
		$ordertime = date('Y-m-d H:i:s');
		if (session_id() == '') {
			session_start();
		}
		$quickcash = $_SESSION['quickcash'];
		$userid = $_SESSION['userid'];
		session_write_close();
		if (is_null($pdo)) {
			$pdo = DbUtils::openDbAndReturnPdoStatic();
		}

		$ret = $this->addProductListToQueueCore($pdo, $ordertime, $theTableid, $prods, $doPrint, $payprinttype, $userid, $quickcash, $order, $orderOption, self::INTERNAL_ORDER);
		echo json_encode($ret);
	}

	private function calcPriceOfExtrasCore($pdo, ?array $extras, string $colname): ?float
	{
		if (is_null($extras) || is_string($extras)) {
			return 0.0;
		}
		$price = 0.0;
		for ($j = 0; $j < count($extras); $j++) {
			$anExtra = $extras[$j];
			if (isset($anExtra["extraid"])) {
				$extraid = $anExtra["extraid"];
			} else {
				$extraid = $anExtra["id"];
			}
			$extraamount = $anExtra["amount"];
			$row = CommonUtils::getRowSqlObject($pdo, "SELECT $colname as price FROM %extras% WHERE id=?", array($extraid));
			$thePrice = $row->price;
			if (!is_null($thePrice)) {
				$price += floatval($thePrice * $extraamount);
			} else {
				return null;
			}
		}
		return $price;
	}

	private function checkIfNotMoreThanAllowedShallBeOrdered($pdo, array $prods): array
	{
		$allowexceedamount = intval(CommonUtils::getConfigValue($pdo, "allowexceedamount", 0));
		if ($allowexceedamount == 1) {
			return array("status" => "OK");
		}

		$amounts = array();
		for ($i = 0; $i < count($prods); $i++) {
			$aProd = $prods[$i];
			$productid = intval($aProd["prodid"]);
			if (!array_key_exists($productid, $amounts)) {
				$amounts[$productid] = 1;
			} else {
				$amounts[$productid] = $amounts[$productid] + 1;
			}
		}
		$sql = "SELECT id,longname,amount from %products% P WHERE removed IS NULL OR removed='0'";
		$result = CommonUtils::fetchSqlAll($pdo, $sql);
		$allowedamounts = array();
		$fails = array();
		foreach ($result as $aResult) {
			$prodid = $aResult['id'];
			$prodmaxamount = $aResult['amount'];
			if (!is_null($prodmaxamount) && (isset($amounts[$prodid]))) {
				// that article is in the list of orders
				$orderedAmount = $amounts[$prodid];
				if ($prodmaxamount < $orderedAmount) {
					$diff = $orderedAmount - $prodmaxamount;
					$fails[] = $aResult['longname'] . " ($diff zuviel bestellt)";
				}
			}
		}
		if (count($fails) > 0) {
			return array("status" => "ERROR", "msg" => implode(",", $fails));
		} else {
			return array("status" => "OK");
		}
	}


	/**
	 * 
	 * Reduce the available amount of the article. If the amount is < 11 then create a Task
	 * @param mixed $pdo
	 * @param mixed $prodSpec	the product as it is specified in db (like in %products%)
	 * @return void
	 */
	private function reduceAmountOfProdAndCreateTask($pdo,$prodSpec,$unitamount) {
		if (!is_null($prodSpec->amount)) {
			$productid = $prodSpec->productid;
			if (is_null($prodSpec->unit) || ($prodSpec->unit == 0)) {
				$reduceAmount = 1;
			} else {
				$reduceAmount = round($unitamount);
			}
			$amount = max(($prodSpec->amount - $reduceAmount), 0);
			$sql = "UPDATE %products% SET amount=? WHERE id=?";
			CommonUtils::execSql($pdo, $sql, array($amount, $productid));

			if ($amount < 11) {
				Tasks::createTaskForEmptyInventory($pdo, $productid);
			}
		}
	}

	/**
	 * If we know the tax number for austria, we can find the real tax to pay. This will be ret
	 * @param mixed $pdo
	 * @param mixed $taxaustrianumber
	 * @return void real tax to pay
	 */
	private function getTaxToUseForAustria($pdo,$taxaustrianumber) {
		$configItem = "taxaustrianormal";
		switch ($taxaustrianumber) {
			case 1:
				$configItem = "taxaustrianormal";
				break;
			case 2:
				$configItem = "taxaustriaerm1";
				break;
			case 3:
				$configItem = "taxaustriaerm2";
				break;
			case 4:
				$configItem = "taxaustriaspecial";
				break;
			case 0:
				$configItem = "null";
				break;
			default:
				$configItem = "taxaustrianormal";
		}
		if ($configItem != "null") {
			$taxToUse = CommonUtils::getConfigValue($pdo, $configItem, "taxaustrianormal");
		} else {
			$taxToUse = 0.0;
		}
		return $taxToUse;
	}

	/*
	 * Add a product list to the queue as if it was ordered by the waiter.
	 * The ordertime is set by the time that this method is invoked.
	 *
	 * If product shall not be run over kitchen or supplydesk this is
	 * managed here as well
	 * 
	 */
	public function addProductListToQueueCore($pdo, $ordertime, $theTableid, $prods, $doPrint, $payprinttype, $userid, $quickcash, $order = null, $orderOption = "", $orderSource = self::INTERNAL_ORDER): array
	{
		if (intval($theTableid) == 0) {
			$theTableid = null; // togo room
		}

		$amountCheck = $this->checkIfNotMoreThanAllowedShallBeOrdered($pdo, $prods);
		if ($amountCheck['status'] != "OK") {
			return (array("status" => "ERROR", "msg" => "Mehr Artikel als verfügbar bestellt: " . $amountCheck['msg']));
		}

		if (!is_null($order)) {
			$order->setCreatorid($userid);
		}

		$printAndQueueJobs = 0;
		if ($orderSource == self::INTERNAL_ORDER) {
			$printAndQueueJobs = CommonUtils::getConfigValue($pdo, "printandqueuejobs", 0);
			if ($printAndQueueJobs == 1) {
				$doPrint = 1;
			}
		}

		CommonUtils::setTransactionSerializable($pdo);
		$pdo->beginTransaction();

		$workflowconfigfood = CommonUtils::getConfigValue($pdo, 'workflowconfig', DbUtils::$WORKFLOW_ONLY_DIGITAL);
		$workflowconfigdrinks = CommonUtils::getConfigValue($pdo, 'workflowconfigdrinks', DbUtils::$WORKFLOW_ONLY_DIGITAL);

		$austria = CommonUtils::getConfigValue($pdo, 'austria', 0);

		$commUtils = new CommonUtils();
		$currentPriceLevel = $commUtils->getCurrentPriceLevel($pdo);
		$currentPriceLevelId = $currentPriceLevel["id"];

		$insertedQueueIdsForPrint = array();
		$insertedQueueIdsTotal = array();

		$isTogoOrder = is_null($theTableid);
		$sharedPickupId = null;

		$orderid = null;
		if (!is_null($order) && ($order->isOrderSet())) {
			$orderid = $order->createOrderEntryInDb($pdo);
		} else {
			$orderOption = CommonUtils::truncateString($orderOption, 200);
			$sql = "INSERT INTO %orders% (creationdate,creatorid,remark,status) VALUES(NOW(),?,?,?)";
			CommonUtils::execSql($pdo, $sql, array($userid, $orderOption, OrdersManagement::$ORDER_OF_WORKRECEIPT));
			$orderid = $pdo->lastInsertId();
		}

		$recordid = self::addRecordEntry($pdo, $ordertime, $userid, $theTableid, T_ORDER);

		$tseEntries = array();
		$queueitemids = array();

		for ($i = 0; $i < count($prods); $i++) {
			$aProd = $prods[$i];
			$productid = intval($aProd["prodid"]);
			if ($orderSource == self::GUEST_ORDER) {
				$theOption = "";
				$theChangedPrice = "NO";
				$unitamount = 1;
				$togo = 0;
				$unit = 0;
				$phase = 0;
				$isminusarticle = 0;
			} else {
				$theOption = $aProd["option"];
				$theChangedPrice = $aProd["changedPrice"];
				$theChangedPrice = str_replace(',', '.', $theChangedPrice);
				$unitamount = $aProd["unitamount"];
				$phase = 0;
				if (isset($aProd["phase"])) {
					$phase = $aProd["phase"];
				}
				$isminusarticle = 0;
				if (isset($aProd['isminusarticle'])) {
					$isminusarticle = $aProd['isminusarticle'];
				}
			}

			$getPriceSql = "SELECT priceA,priceB,priceC,purchasingprice,longname,tax as prodtaxkey,togotax as prodtogotaxkey,taxaustria,amount,COALESCE(unit,0) as unit,T.kind,P.id as productid FROM %products% P INNER JOIN %prodtype% T ON P.category=T.id WHERE P.id=?";
			$row = CommonUtils::getRowSqlObject($pdo, $getPriceSql, array($productid));
			if ($row == null) {
				return (array("status" => "ERROR", "msg" => "Preise nicht vorhanden"));
			}

			$this->reduceAmountOfProdAndCreateTask($pdo,$row,$unitamount);
		
			$productname = $row->longname;
			if (isset($aProd['name'])) {
				$productname = $aProd['name'];
			}
			$tseProductname = $productname;
			$kind = $row->kind;
			$unit = intval($row->unit);

			$prodtaxkey = $row->prodtaxkey;
			$prodtogotaxkey = $row->prodtogotaxkey;

			$purchasingPrice = $row->purchasingprice;

			$basePrice = $this->getPriceDueToLevel($row->priceA, $row->priceB, $row->priceC, $currentPriceLevelId);
			$priceType = DbUtils::$PRICE_TYPE_BASE;
			if (($theChangedPrice == "NO") || (!is_numeric($theChangedPrice))) {
				$price = $basePrice;
			} else {
				if ($theChangedPrice < $basePrice) {
					$priceType = DBUtils::$PRICE_TYPE_DICOUNT;
				} else if ($theChangedPrice > $basePrice) {
					$priceType = DbUtils::$PRICE_TYPE_EXTRA_AMOUNT;
				}
				$price = $theChangedPrice;
			}

			if ($orderSource == self::GUEST_ORDER) {
				$togo = 0;
			} else {
				$togo = $aProd["togo"];
			}

			$taxkeytouse = $prodtaxkey;
			if (is_null($theTableid) || ($togo == 1)) {
				$taxkeytouse = $prodtogotaxkey;
			}
			$taxToUse = CommonUtils::getTaxFromKey($pdo, $taxkeytouse);

			if ($austria == 1) {
				$taxaustrianumber = $row->taxaustria;
				$taxToUse = $this->getTaxToUseForAustria($pdo,$taxaustrianumber);
			} else {
				$taxaustrianumber = null;
			}

			$extras = null;
			if (array_key_exists("extras", $aProd)) {
				if (!is_string($aProd["extras"])) {
					// if it is a string, then an empty but we want to use only real arrays
					$extras = $aProd["extras"];
				}
			}
			$purchasingPricesOfExtras = $this->calcPriceOfExtrasCore($pdo, $extras, self::EXTRA_PURCHASE_COL);

			if (($theChangedPrice == "NO") || (!is_numeric($theChangedPrice))) {
				if (!is_null($extras) && ($extras != "")) {
					$price += $this->calcPriceOfExtrasCore($pdo, $extras, self::EXTRA_PRICE_COL);
				}
			}

			if (is_numeric($unit) && CommonUtils::isUnitOfAmountTypeNotPieceNotVoucher($unit)) {
				if (!is_numeric($unitamount)) {
					$pdo->rollBack();
					CommonUtils::resetTransactionIsolationLevel($pdo);
					return (array("status" => "ERROR", "msg" => "Mengenangabe nicht numerisch"));
				} else {
					$price = $price * $unitamount;
					$basePrice *= $unitamount;
				}
			}

			if (is_null($theTableid) || (is_numeric($theTableid) && is_numeric($productid))) {
				$useConditions = self::getUseKitchenAndSupplyForProdWithPdo($pdo, $productid);

				if (CommonUtils::isUnitOfAmountTypeNotVoucher($unit)) {
					$ordertype = DbUtils::$ORDERTYPE_PRODUCT;
					$voucherid = null;
				} else if ($unit == CommonUtils::UnitId_EinzweckgutscheinKauf) {
					$ordertype = DbUtils::$ORDERTYPE_1ZweckKauf;
					$voucherid = vouchers::createEinZweckVoucher($pdo, $productname, $userid);
					if (is_null($voucherid)) {
						$pdo->rollBack();
						CommonUtils::resetTransactionIsolationLevel($pdo);
						error_log("Voucher could not be created");
						return (array("status" => "ERROR", "msg" => "Gutschein konnte nicht angelegt werden"));
					}
					$productname = "$voucherid - $productname";
				} else if ($unit == CommonUtils::UnitId_EinzweckgutscheinEinl) {
					$ordertype = DbUtils::$ORDERTYPE_1ZweckEinl;
					$voucherid = $theOption;
					$redeem = vouchers::redeemVoucher($pdo, $theOption, $userid);
					$theChangedPrice = "NO"; // the price is set to 0 for redeemed vouchers
					$prods[$i]["changedPrice"] = "NO"; // keep in sync, otherwise Bonpos::createEntriesInBonposTablesDueToOrder() would wrongly insert bonpospreisfindung rows
					if (!$redeem) {
						$pdo->rollBack();
						CommonUtils::resetTransactionIsolationLevel($pdo);
						error_log("Voucher with number $theOption could not be redeemed");
						return (array("status" => "ERROR", "msg" => "Gutschein mit Nummer " . $theOption . " kann nicht eingelöst werden."));
					} else {
						$taxkeytouse = 5;
						$taxToUse = 0.00;
						$productname = "$theOption - $productname";
					}
				}

				$prodnameid = self::createProdNameOrReturnId($pdo, $productname);

				$profit = null;
				if (!is_null($purchasingPrice) && !is_null($purchasingPricesOfExtras)) {
					$profit = CommonUtils::calcNettoFromBrutto($price,$taxToUse) - ($purchasingPrice + $purchasingPricesOfExtras);
				}


				$theOption = CommonUtils::truncateString($theOption, 150);
				$insertSql = "INSERT INTO `%queue%` (
				`id` , `tablenr`,`productid`,`pricelevel`, `price`,`baseprice`, `profit`,`pricetype`,`unit`,`unitamount`, `ordertype`, `voucherid`,`tax`,`taxkey`,`taxaustria`,`prodnameid`,`ordertime`,`orderuser`,`anoption`,`pricechanged`,`togo`,`readytime`,`delivertime`,`paidtime`,`billid`,`toremove`,`cooking`,`workprinted`,`orderid`,`phase`,`isminusarticle`)
				VALUES (
				NULL , ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, null, null, NULL,NULL,'0',NULL,'0',?,?,?)";

				CommonUtils::execSql($pdo, $insertSql, array($theTableid, $productid, $currentPriceLevelId, $price, $basePrice, $profit, $priceType, $unit, $unitamount, $ordertype, $voucherid, $taxToUse, $taxkeytouse, $taxaustrianumber, $prodnameid, $ordertime, $userid, $theOption, ($theChangedPrice == "NO" ? 0 : 1), $togo, $orderid, $phase, $isminusarticle));

				$queueid = $pdo->lastInsertId();
				$prods[$i]["queueid"] = $queueid;
				if ($orderSource == self::GUEST_ORDER) {
					$prods[$i]["unit"] = $unit;
					$prods[$i]["togo"] = $togo;
					$prods[$i]["name"] = $tseProductname;
					$prods[$i]["price"] = $price;
					$prods[$i]["changedPrice"] = $theChangedPrice;
				}

				$sql = "INSERT INTO %recordsqueue% (recordid,queueid) VALUES(?,?)";
				CommonUtils::execSql($pdo, $sql, array($recordid, $queueid));

				if ($unit == 0) {
					$orderAmount = 1;
				} else {
					$amountAsDouble = doubleval($unitamount);
					$orderAmount = strval($amountAsDouble);
				}
				$quotedProdname = self::quoteTseName($tseProductname);
				$tseEntries[] = $orderAmount . ";\"" . $quotedProdname . "\";" . number_format((float)$price, 2, '.', '');
				$queueitemids[] = $queueid;

				if (!is_null($extras) && ($extras != "")) {
					for ($j = 0; $j < count($extras); $j++) {
						$anExtra = $extras[$j];
						$aQueueItemExtra = new QueueItemExtra($anExtra["id"], $anExtra["name"], $anExtra["amount"]);
						$aQueueItemExtra->addExtraToQueueExtrasDbTable($pdo, $queueid);
					}
				}

				$workflowconfig = $workflowconfigfood;
				if ($kind == KIND_DRINKS) {
					$workflowconfig = $workflowconfigdrinks;
				}
				if (($workflowconfig == DbUtils::$WORKFLOW_WORK_WITH_SERVER) && ($doPrint == 0)) {
					self::reallyDeclareAsCooked($pdo, $queueid);
					self::declareProductBeDeliveredWithGivenPdo($pdo, $queueid);
				} else {
					$insertedQueueIdsTotal[] = $queueid;
					if ($useConditions["usekitchen"] == 0) {
						self::reallyDeclareAsCooked($pdo, $queueid);
						if ($useConditions["usesupply"] == 0) {
							self::declareProductBeDeliveredWithGivenPdo($pdo, $queueid);
						}
					} else {
						if ($isminusarticle != 1) {
							$workflowToApply = $workflowconfigfood;
							if ($kind == KIND_DRINKS) {
								$workflowToApply = $workflowconfigdrinks;
							}
							if (($workflowToApply == Dbutils::$WORKFLOW_ONLY_WORK) || (($workflowToApply == Dbutils::$WORKFLOW_ONLY_DIGITAL) && ($printAndQueueJobs == 1))) {
									$insertedQueueIdsForPrint[] = $queueid;
							} else {
									$pickupconfigitem = ($kind == KIND_DRINKS) ? "printpickupsdrinks" : "printpickups";
									$printpickupsForKind = CommonUtils::getConfigValue($pdo, $pickupconfigitem, 0);
									$isTogoItem = $isTogoOrder || ($togo == 1);
									$wantsPickup = ($printpickupsForKind == 1) || (($printpickupsForKind == 2) && $isTogoItem);
									if ($wantsPickup) {
											if (is_null($sharedPickupId)) {
													$sharedPickupId = Workreceipts::getNextPickupId($pdo);
											}
											$sql = "UPDATE %queue% SET pickupid=? WHERE id=?";
											CommonUtils::execSql($pdo, $sql, array($sharedPickupId, $queueid));
									}
							}
						}

						if (($printAndQueueJobs == 0) && ($doPrint == 0)) {
							self::setFlagForCooking($pdo, $productid);
						} else if (($printAndQueueJobs == 1) && ($doPrint == 1)) {
							self::setFlagForCooking($pdo, $productid);
						}
					}
				}
			}
		}

		$tseEntriesLine = implode("\r", $tseEntries);
		$signStatus = self::signAtTSE($pdo, $tseEntriesLine, $queueitemids, false);
		if ($signStatus["status"] != "OK") {
			$pdo->rollBack();
			CommonUtils::resetTransactionIsolationLevel($pdo);
			return (array("status" => "ERROR", "msg" => $signStatus["msg"]));
		}

		// now fill the bonpos table with the information that is already there
		$bonpos = new BonposOrder();
		$bonposinsertstatus = $bonpos->createEntriesInBonposTablesDueToOrder($pdo,$signStatus["opid"],$theTableid,$prods);
		if ($bonposinsertstatus["status"] != "OK") {
			$pdo->rollBack();
			error_log("Bonpos tables could not be filled with orders: " . $bonposinsertstatus["msg"]);
			return $bonposinsertstatus;
		}
		$bonkopf = new BonkopfOrder();
		$bonkopfstatus = $bonkopf->createEntriesInAllBonkopfTablesDueToOrder($pdo,$signStatus["opid"],$theTableid);
		if ($bonkopfstatus["status"] != "OK") {
			$pdo->rollBack();
			error_log("Bonkopf tables could not be filled with orders: " . $bonkopfstatus["msg"]);
			return $bonkopfstatus;
		}

		$generalQuickcash = CommonUtils::getConfigValue($pdo, "cashenabled", 1);
		if (($generalQuickcash == 1) || ($quickcash == 1)) {
			$idStr = join(',', $insertedQueueIdsTotal);
			$billid = $this->declarePaidCreateBillReturnBillId($pdo, $idStr, $theTableid, 1, 0, 0, self::$INTERNAL_CALL_YES, '', '', '', null, null);
			$forceprint = CommonUtils::getConfigValue($pdo, 'forceprint', 0);
			if ($forceprint == 1) {
				if (session_id() == '') {
					session_start();
				}
				$recprinter = $_SESSION['receiptprinter'];
				PrintQueue::internalQueueReceiptPrintjob($pdo, $billid, $recprinter);
			}
		}

		// for kitchen/bar
		$this->setWorkPrinterForNewQueueEntries($pdo, $insertedQueueIdsTotal);

		$pdo->commit();
		CommonUtils::resetTransactionIsolationLevel($pdo);

		if (!is_null($sharedPickupId)) {
			$this->createPickupOnlyReceipt($pdo, $sharedPickupId, $_SESSION['currentuser'], $userid);
		}

		if ($payprinttype == "s") {
			$this->doWorkPrint($pdo, $theTableid, $insertedQueueIdsForPrint, $_SESSION['currentuser'], $userid, $payprinttype, $_SESSION['language'], null, $orderOption);
			return (array("status" => "OK", "queueids" => $insertedQueueIdsTotal));
		} else {
			$this->doWorkPrint($pdo, $theTableid, $insertedQueueIdsForPrint, $_SESSION['currentuser'], $userid, $payprinttype, $_SESSION['language'], null, $orderOption);
			return (array("status" => "OK", "msg" => $result, "queueids" => $insertedQueueIdsTotal));
		}
	}

	private static function quoteTseName($txt)
	{
		return str_replace("\"", "\"\"", $txt);
	}
	private static function signAtTSE($pdo, $signTxtValue, $queueitemids, $isCancel): array
	{
		$sentToTseResult = Tse::sendOrdersToTSE($pdo, $signTxtValue);
		if ($sentToTseResult["status"] != "OK") {
			$detail = isset($sentToTseResult["msg"]) ? " " . $sentToTseResult["msg"] : "";
			return (array("status" => "ERROR", "msg" => "TSE-Signierung fehlgeschlagen. Vorgang konnte nicht ausgeführt werden." . $detail,"opid" => null));
		} else {
			$logtime = 0;
			$logtimestart = 0;
			$logtimefinish = 0;
			$trans = 0;
			$tseSignature = '';
			$pubkeyRef = null;
			$sigalgRef = null;
			$sigcounter = 0;
			$signtxt = $signTxtValue;
			$certificateRef = null;
			$serialNoRef = null;

			if ($sentToTseResult["usetse"] == DbUtils::$TSE_OK) {
				$logtime = $sentToTseResult["logtimestart"];
				$logtimestart = $sentToTseResult["logtimestart"];
				$logtimefinish = $sentToTseResult["logtimefinish"];
				$trans = $sentToTseResult["trans"];
				$sigcounter = $sentToTseResult["sigcounter"];
				$tseSignature = $sentToTseResult["signature"];
				$sigalgRef = CommonUtils::referenceValueInTseValuesTable($pdo, $sentToTseResult["sigalg"]);
				$pubkeyRef = CommonUtils::referenceValueInTseValuesTable($pdo, $sentToTseResult["publickey"]);
				$serialNoRef = CommonUtils::referenceValueInTseValuesTable($pdo, $sentToTseResult["serialno"]);
				$certificateRef = CommonUtils::referenceValueInTseValuesTable($pdo, $sentToTseResult["certificate"]);
			}

			$opid = Operations::createOperation(
				$pdo,
				DbUtils::$PROCESSTYPE_VORGANG,
				DbUtils::$OPERATION_IN_QUEUE_TABLE,
				$logtimestart,
				$logtimefinish,
				$trans,
				$signtxt,
				$tseSignature,
				$pubkeyRef,
				$sigalgRef,
				$serialNoRef,
				$certificateRef,
				$sigcounter,
				$sentToTseResult["usetse"]
			);

			if (!$isCancel) {
				$sql = "UPDATE %queue% SET opidok=?,logtime=?,poszeile=? WHERE id=?";
				$poszeile = 1;
				foreach ($queueitemids as $aQueueItemId) {
					CommonUtils::execSql($pdo, $sql, array($opid, $logtime, $poszeile, $aQueueItemId));
					$poszeile++;
				}
			} else {
				$sql = "UPDATE %queue% SET opidcancel=?,cancellogtime=? WHERE id=?";
				foreach ($queueitemids as $aQueueItemId) {
					CommonUtils::execSql($pdo, $sql, array($opid, $logtime, $aQueueItemId));
				}
			}
		}
		if (($sentToTseResult["usetse"] == DbUtils::$TSE_OK) || ($sentToTseResult["usetse"] == DbUtils::$TSE_KNOWN_ERROR) || ($sentToTseResult["usetse"] == DbUtils::$NO_TSE)) {
			return array("status" => "OK","opid" => $opid);
		} else {
			return array("status" => "ERROR", "msg" => "Error TSE sign process","opid" => $opid);
		}
	}

	public static function setFlagForCooking($pdo, $productid)
	{
		$beepordered = CommonUtils::getConfigValue($pdo, "beepordered", 0);
		if ($beepordered == 1) {
			$sql = "SELECT kind FROM %prodtype% T,%products% P where P.id=? AND P.category=T.id";
			$result = CommonUtils::fetchSqlAll($pdo, $sql, array($productid));
			if (count($result) > 0) {
				$kindOfNewProd = $result[0]['kind'];
				if ($kindOfNewProd == 0) {
					self::setNewFoodToCookFlag($pdo, 1);
				} else {
					self::setNewDrinkToCookFlag($pdo, 1);
				}
			}
		}
	}

	private static function addRecordEntry($pdo, string $date, $userid, $tableid, int $action): string
	{
		$sql = "INSERT INTO %records% (date,userid,tableid,action) VALUES(?,?,?,?)";
		CommonUtils::execSql($pdo, $sql, array($date, $userid, $tableid, $action));
		return $pdo->lastInsertId();
	}

	private function getPriceDueToLevel($priceA, $priceB, $priceC, $pricelevel)
	{
		if ($pricelevel == 2) {
			return $priceB;
		} else if ($pricelevel == 3) {
			return $priceC;
		}
		return $priceA;
	}


	/**
	 * 
	 * in the queue table there is a 
	 *  - column "workprinter" that contains the printer to which is was printed. Also set in case of digital order!
	 *  - column "printjobid" that points to the printjob itself - in case of digita order this is null!
	 * This method returns for each printer fopr food and for drinks the queueids.
	 * 
	 * Output is an array with the elements:
	 * 	status which can be OK or ERROR based on if there was an error
	 *  foodprinters, drinkprinters that each are two-dimensional arrays:
	 * 		as first there is the printer number. For each printer number there can be an array of each queueids that are for that printer
	 * @param mixed $pdo
	 * @param array $queueids
	 * @return void
	 */
	private function getPrintersOfQueueId($pdo, array $queueids): array {
		$sqlKind = "select Q.id as qid,PT.kind as kind from %prodtype% PT,%products% P,%queue% Q where Q.productid=P.id and P.category=PT.id and Q.id=?";
		$sqlPrinter = "select Q.workprinter FROM %queue% Q WHERE id=? AND printjobid is not null";

		try {
			// predefine max 10 work printers (maybe future need), each with an empty list of queueids
			$foodPrinters = array();
			$drinkPrinters = array();
			for ($i = 1; $i <= 10; $i++) {
				$foodPrinters[$i] = array();
				$drinkPrinters[$i] = array();
			}
			foreach ($queueids as $aQueueId) {
				// get the workprinter
				$kindRes = CommonUtils::fetchSqlAll($pdo,$sqlKind,array($aQueueId));
				$kind = $kindRes[0]['kind']; // 0=food,1=drink
				$printerRes = CommonUtils::fetchSqlAll($pdo,$sqlPrinter,array($aQueueId));
				$printer = null;
				if (count($printerRes) > 0) {
					$printer = $printerRes[0]["workprinter"]; // which can again be null
				}

				if (!is_null($printer)) {
					$wprinter = intval($printer);
					if ($wprinter >= 1 && $wprinter <= 10) {
						if ($kind == 0) {
							$foodPrinters[$wprinter][] = $aQueueId;
						} else {
							$drinkPrinters[$wprinter][] = $aQueueId;
						}
					}
				}
			}
			return array("status" => "OK","foodprinters" => $foodPrinters, "drinkprinters" => $drinkPrinters);
		} catch (Exception $ex) {
			error_log("Error retrieving work printer data: " . $ex->getMessage());
			return array("status" => "ERROR","msg" => $ex->getMessage());
		}
	}

	/*
	 * Do as if the product would have been removed from queue - but don't do it exactly,
	 * because then it would not appear in the reports any more. Instead declare the
	 * toremove = 1 (was never ordered...)
	 */
	function removeProductsFromQueue(array $queueids): array
	{
		$pdo = DbUtils::openDbAndReturnPdoStatic();
		CommonUtils::setTransactionSerializable($pdo);
		$pdo->beginTransaction();

		// queueids that shall be cancelled in one go (i.e. same BON_ID), but filter out the ones being in a bill or a closing
		$queueIdsToCancel = array();
		foreach($queueids as $aQueueId) {
			$sql = "SELECT COUNT(Q.id) as countid FROM %queue% Q WHERE (paidtime IS NOT NULL OR clsid IS NOT NULL) AND Q.id=?";
			$isAccountedRes = CommonUtils::fetchSqlAll($pdo,$sql,array($aQueueId));
			$isAccounted = ($isAccountedRes[0]["countid"] == 1 ? true: false);
			if (!$isAccounted) {
				$queueIdsToCancel[] = $aQueueId;
			}
		}

		if (count($queueIdsToCancel) == 0) {
			$pdo->rollBack();
			CommonUtils::resetTransactionIsolationLevel($pdo);
			echo json_encode(array("status" => "OK", "msg" => "Kein Artikel zum Entfernen gefunden"));
			return (array("status" => "OK", "msg" => "Kein Artikel zum Entfernen gefunden"));
		}

		$sql = "SELECT tablenr,productid FROM %queue% WHERE id=?";
		$result = CommonUtils::fetchSqlAll($pdo, $sql, array($queueIdsToCancel[0]));
		if (count($result) == 0) {
			$pdo->rollBack();
			CommonUtils::resetTransactionIsolationLevel($pdo);
			echo json_encode(array("status" => "ERROR", "msg" => "Artikelreferenz fehlerhaft"));
			return (array("status" => "ERROR", "msg" => "Artikelreferenz fehlerhaft"));
		}
		$theTableid = $result[0]["tablenr"];

		date_default_timezone_set(DbUtils::getTimeZone());
		$currentTime = date('Y-m-d H:i:s');
		$userid = CommonUtils::getUserId();

		$updateSql = "UPDATE %queue% SET toremove='1' WHERE id=? AND toremove='0'";
		foreach ($queueIdsToCancel as $aQueueId) {
			CommonUtils::execSql($pdo, $updateSql, array($aQueueId));
		}

		$cancelStatus = self::signAndRecordQueueItemsCancel($pdo, $queueIdsToCancel, $theTableid);
		if ($cancelStatus["status"] != "OK") {
			$pdo->rollBack();
			CommonUtils::resetTransactionIsolationLevel($pdo);
			echo json_encode($cancelStatus);
			return $cancelStatus;
		}

		$recordid = self::addRecordEntry($pdo, $currentTime, $userid, $theTableid, T_REMOVE);
		$recordsQueueSql = "INSERT INTO %recordsqueue% (recordid,queueid) VALUES(?,?)";
		$productAmountSql = "UPDATE %products% SET amount=IF(amount is null,null,amount+1) WHERE id=?";

		foreach ($queueIdsToCancel as $aQueueId) {
			Vouchers::handleRemoveQueueItem($pdo, $aQueueId);

			CommonUtils::execSql($pdo, $recordsQueueSql, array($recordid, $aQueueId));

			// Workreceipts::createCancelWorkReceipt($pdo, $aQueueId);

			$prodRes = CommonUtils::fetchSqlAll($pdo, "SELECT productid FROM %queue% WHERE id=?", array($aQueueId));
			CommonUtils::execSql($pdo, $productAmountSql, array($prodRes[0]["productid"]));
		}

		$pdo->commit();
		CommonUtils::resetTransactionIsolationLevel($pdo);

		$this->printCancellationWorkReceipts($pdo,$queueIdsToCancel);
		
		echo json_encode(array("status" => "OK", "msg" => "Artikel entfernt", "queueids" => $queueIdsToCancel));
		return (array("status" => "OK", "msg" => "Artikel entfernt", "queueids" => $queueIdsToCancel));
	}

	/**
	 * Check for each queueid in the array of queueIds if there was a work receipt printed. This is the case if
	 * table queue has a number in columns workprinter and printjobid
	 * The number in printjobid is the foreign key to table printjob. That table has the column printer. 
	 * So the article with that queueid has to be cancelled on printer with that number.
	 * 
	 * This method creates work receipts like it would be a normal print job of an order, i.e. using the
	 * templates as they are defined in the config table foodtemplate,drinktemplate.
	 * 
	 * To get that list we can use getPrintersOfQueueId
	 * @param mixed $pdo
	 * @param array $cancelledQueueids
	 * @return void
	 */
	public function printCancellationWorkReceipts($pdo,array $cancelledQueueids) {
		if (count($cancelledQueueids) == 0) {
			return;
		}

		$printerStatus = $this->getPrintersOfQueueId($pdo, $cancelledQueueids);
		if ($printerStatus["status"] != "OK") {
			return;
		}

		$theTableid = $this->getTableIdOfQueue($pdo, $cancelledQueueids[0]);
		$userid = CommonUtils::getUserId();
		$username = $this->getUserName($userid);
		$lang = $_SESSION['language'] ?? 0;

		for ($printer = 1; $printer <= 10; $printer++) {
			$foodIds = $printerStatus["foodprinters"][$printer];
			if (count($foodIds) > 0) {
				$jobs = $this->getJobsToPrint($pdo, 0, $printer, $foodIds);
				$this->createAWorkReceiptAndQueueWorkPrint($pdo, $jobs, $theTableid, 0, $printer, $username, $userid, $lang, "", true);
			}

			$drinkIds = $printerStatus["drinkprinters"][$printer];
			if (count($drinkIds) > 0) {
				$jobs = $this->getJobsToPrint($pdo, 1, $printer, $drinkIds);
				$this->createAWorkReceiptAndQueueWorkPrint($pdo, $jobs, $theTableid, 1, $printer, $username, $userid, $lang, "", true);
			}
		}
	}

	private static function getEntryForTseSignatureOfCancelItem($pdo,$queueid) {
		$sql = "SELECT (IF(unit=0,ROUND(0-unitamount),0-unitamount)) as unitamount,unit,PN.name as productname,price FROM %queue% Q,%prodnames% PN WHERE Q.id=? AND Q.prodnameid=PN.id";
		$result = CommonUtils::fetchSqlAll($pdo, $sql, array($queueid));
		$amount = $result[0]["unitamount"];
		$unit = $result[0]["unitamount"];
		if ($unit == 0) {
			$orderAmount = 1;
		} else {
			$amountAsDouble = doubleval($amount);
			$orderAmount = strval($amountAsDouble);
		}

		$prodname = self::quoteTseName($result[0]["productname"]);
		$price = $result[0]["price"];
		$signTxt = $orderAmount . ";\"" . $prodname . "\";" . $price;
		return $signTxt;
	}

	public static function signAndRecordQueueItemsCancel($pdo, array $queueIds, $theTableid): array {
		$tseEntries = array();
		foreach ($queueIds as $aQueueId) {
			$tseEntries[] = self::getEntryForTseSignatureOfCancelItem($pdo, $aQueueId);
		}
		$signTxt = implode("\r", $tseEntries);

		$signStatus = self::signAtTSE($pdo, $signTxt, $queueIds, true);
		if ($signStatus["status"] != "OK") {
			return array("status" => "ERROR", "msg" => "TSE-Signierung fehlgeschlagen: " . $signStatus["msg"]);
		}

		$bonpos = new BonposOrder();
		$bonposinsertstatus = $bonpos->createEntriesInBonposTablesDueToCancel($pdo, $signStatus["opid"], $theTableid, $queueIds);
		if ($bonposinsertstatus["status"] != "OK") {
			error_log("Bonpos tables could not be filled with cancels: " . $bonposinsertstatus["msg"]);
			return $bonposinsertstatus;
		}
		$bonkopf = new BonkopfOrder();
		$bonkopfstatus = $bonkopf->createEntriesInAllBonkopfTablesDueToCancel($pdo, $signStatus["opid"], $theTableid);
		if ($bonkopfstatus["status"] != "OK") {
			error_log("Bonkopf tables could not be filled with cancels: " . $bonkopfstatus["msg"]);
			return $bonkopfstatus;
		}
		return array("status" => "OK", "opid" => $signStatus["opid"]);
	}

	public static function signRemovalOfQueueItem($pdo, $queueid, $theTableid)
	{
		$cancelStatus = self::signAndRecordQueueItemsCancel($pdo, array($queueid), $theTableid);
		if ($cancelStatus["status"] != "OK") {
			return array("status" => "ERROR", "msg" => $cancelStatus["msg"], "opid" => $cancelStatus["opid"] ?? null);
		} else {
			return array("status" => "OK","opid" => $cancelStatus["opid"]);
		}
	}

	function getUnpaidTables()
	{
		$pdo = DbUtils::openDbAndReturnPdoStatic();
		$unpaidTables = array();
		$takeawayHasOpenBills = 0;
		try {
			$sql = "SELECT DISTINCT R.tableno as tablename FROM %queue% Q 
                                INNER JOIN %resttables% R ON Q.tablenr=R.id 
                                LEFT JOIN %bill% B ON Q.billid=B.id 
                                WHERE (paidtime is null AND (B.paymentid is null OR (B.paymentid <> ? AND B.paymentid <> ?))) AND Q.toremove='0' and tablenr=R.id AND isclosed is null";
			$result = CommonUtils::fetchSqlAll($pdo, $sql, array(DbUtils::$PAYMENT_GUEST, DbUtils::$PAYMENT_HOTEL));

			foreach ($result as $anUnpaidTable) {
				$unpaidTables[] = $anUnpaidTable["tablename"];
			}
			$sql = "SELECT COUNT(Q.id) as takeawayitems FROM %queue% Q "
				. "LEFT JOIN %bill% B ON Q.billid=B.id "
				. "WHERE tablenr is null AND (paidtime is null AND (B.paymentid is null OR (B.paymentid <> ? AND B.paymentid <> ?))) AND Q.toremove='0' AND isclosed is null";
			$result = CommonUtils::fetchSqlAll($pdo, $sql, array(DbUtils::$PAYMENT_GUEST, DbUtils::$PAYMENT_HOTEL));
			$cnt = $result[0]["takeawayitems"];

			if ($cnt != 0) {
				$takeawayHasOpenBills = 1;
			}
		} catch (Exception $ex) {
			error_log("GetUnpaidTables failed: " . $ex->getMessage());
		}
		echo json_encode(array("status" => "OK", "msg" => join(",", $unpaidTables), "takeawayhasopenbills" => $takeawayHasOpenBills));
	}

	public function numberOfUnpaidProductsForTable($pdo, $tableid, $orderuser = null) {
		$whereTableClause = " (Q.tablenr IS NULL OR Q.tablenr='0') ";
		$sqlArgs = array();
		if (!is_null($tableid)) {
			$whereTableClause = " Q.tablenr=? ";
			$sqlArgs[] = $tableid;
		}
		$orderUserClause = "";
		if (!is_null($orderuser)) {
			$orderUserClause = " AND Q.orderuser=? ";
			$sqlArgs[] = $orderuser;
		}
		$sql = "SELECT Q.id AS queueid,B.id as billid
			FROM %queue% Q
			LEFT JOIN %bill% B
			ON Q.billid = B.id
			WHERE Q.clsid IS NULL
			AND $whereTableClause 
			$orderUserClause
			AND (Q.toremove IS NULL OR Q.toremove = '0') 
			HAVING billid IS NULL
			";
		$res = CommonUtils::fetchSqlAll($pdo, $sql, $sqlArgs);
		return count($res);
	}


	/*
	 * Return as JSON structure all products that are assigned to a specified table, with the 
	 * specification that they are not delivered yet.
	 * 
	 * toremove must not be 1, because = 1 means that is is paid but was cancelled later
	 * by the waiter! (in a previous version such entries were deleted from queue, but then
	 * they won't appear in reports any more)
	 * 
	 * Return is: [
	 * 		{"queueid":"2","longname":"EL Greco 1 Person", "isReady":"1"},
	 * 		{"queueid":"5","longname":"Souvlaki","isReady":"0"}] 
	 * 				(a sample)
	 * 	
	 */
	function getJsonLongNamesOfProdsForTableNotDelivered($tableid)
	{
		if (is_numeric($tableid)) {
			$prods = array();

			$pdo = DbUtils::openDbAndReturnPdoStatic();

			$sql = "SELECT count(id) as countid FROM %bill%";
			$row = CommonUtils::getRowSqlObject($pdo, $sql, null);

			$whereClauseForCandidate = "%queue%.toremove='0' AND isclosed is null AND (%queue%.printjobid IS NULL OR %queue%.paidtime IS NULL)";
			if ($row->countid == 0) {
				$sql = "SELECT DISTINCT %queue%.id as quid, ordertime FROM %queue% "
					. "WHERE $whereClauseForCandidate AND ";
			} else {
				$sql = "SELECT DISTINCT %queue%.id as quid, ordertime FROM %queue%,%bill% "
					. "WHERE $whereClauseForCandidate AND "
					. " ((%queue%.billid is null AND %queue%.paidtime is null) OR (%queue%.billid=%bill%.id AND %bill%.closingid is null)) AND ";
			}

			if ($tableid == 0) {
				$sql .= "%queue%.tablenr is null ORDER BY ordertime, quid";
				$stmt = $pdo->prepare(DbUtils::substTableAlias($sql));
				$stmt->execute();
			} else {
				$sql .= "%queue%.tablenr=? ORDER BY ordertime, quid";
				$stmt = $pdo->prepare(DbUtils::substTableAlias($sql));
				$stmt->execute(array($tableid));
			}

			$allNotClosedQueueIds = $stmt->fetchAll();

			$resultids = array();
			$sql = "SELECT count(id) as countid FROM %queue% WHERE %queue%.id=? AND (%queue%.delivertime IS NULL ";
			$sql .= " OR %queue%.readytime IS NULL ";
			$sql .= " OR (%queue%.billid is null AND %queue%.paidtime is null)) ";
			$stmt = $pdo->prepare(DbUtils::substTableAlias($sql));
			foreach ($allNotClosedQueueIds as $aQueueId) {#
				$aQId = $aQueueId["quid"];

				$stmt->execute(array($aQId));
				$row = $stmt->fetchObject();
				if ($row->countid > 0) {
					$resultids[] = $aQId;
				}
			}

			$prods = array();
			$sql = "SELECT productid,readytime,paidtime,cooking,anoption,PN.name as productname,togo,pricechanged,price,unit,unitamount,COALESCE(isminusarticle,0) as isminusarticle FROM %queue% Q,%prodnames% PN WHERE Q.id=? AND Q.prodnameid=PN.id ORDER BY productid ";
			$stmt = $pdo->prepare(DbUtils::substTableAlias($sql));
			foreach ($resultids as $anId) {
				$stmt->execute(array($anId));
				$row = $stmt->fetchObject();

				$isReady = "1";
				$isDelivered = "1";
				$isPaid = "1";
				$isCooking = '1';
				if ($row->readytime == null) {
					$isReady = "0"; // not yet prepared by the kitchen
				}
				if ($row->paidtime == null) {
					$isPaid = "0"; // not yet paid
				}
				if (is_null($row->cooking)) {
					$isCooking = '0';
				}
				$extras = $this->getExtrasWithIdsOfQueueItem($pdo, $anId);

				$prodEntry = array(
					"id" => $anId,
					"prodid" => $row->productid,
					"longname" => $row->productname,
					"option" => $row->anoption,
					"pricechanged" => $row->pricechanged,
					"togo" => $row->togo,
					"isminusarticle" => $row->isminusarticle,
					"price" => $row->price,
					"unit" => $row->unit,
					"unitamount" => $row->unitamount,
					"extras" => $extras["extrasnames"],
					"extrasids" => $extras["extrasids"],
					"extrasamounts" => $extras["extrasamounts"],
					"isready" => $isReady,
					"isPaid" => $isPaid,
					"isCooking" => $isCooking
				);
				$prods[] = $prodEntry;
			}
			echo json_encode($prods);
		} else {
			return array();
		}
	}


	function getProdsForTableChange($tableid)
	{
		$pdo = DbUtils::openDbAndReturnPdoStatic();

		if ($tableid == 0) {
			$tableid = null;
		}

		$sql = "SELECT Q.id as queueid,PN.name as productname,T.kind FROM ";
		$sql .= "%queue% Q,%prodnames% PN,%prodtype% T,%products% P  WHERE Q.prodnameid=PN.id AND Q.productid=P.id AND P.category=T.id AND";
		$sql .= "(tablenr=? OR (tablenr IS NULL AND ? IS NULL)) AND Q.toremove='0' AND isclosed is null AND billid is null ";
		$stmt = $pdo->prepare(DbUtils::substTableAlias($sql));
		$stmt->execute(array($tableid, $tableid));
		$unpaidresultungrouped = $stmt->fetchAll();

		$sql = "SELECT Q.id as queueid,PN.name as productname,T.kind FROM ";
		$sql .= "%prodtype% T,%products% P,%prodnames% PN INNER JOIN %queue% Q ON PN.id=Q.prodnameid ";
		$sql .= "LEFT OUTER JOIN %bill% B ON Q.billid=B.id WHERE ";
		$sql .= "Q.productid=P.id AND P.category=T.id AND ";
		$sql .= "(tablenr=? OR (tablenr IS NULL AND ? IS NULL)) AND Q.toremove='0' AND isclosed is null AND billid is null AND (";
		$sql .= "Q.delivertime IS NULL OR ";
		$sql .= "(Q.delivertime IS NOT NULL AND workprinted='1')) ";
		$stmt = $pdo->prepare(DbUtils::substTableAlias($sql));
		$stmt->execute(array($tableid, $tableid));
		$undeliveredresultungrouped = $stmt->fetchAll();
		$merged = array();
		foreach ($unpaidresultungrouped as $entry) {
			$qid = $entry["queueid"];
			$prodname = $entry["productname"];
			$status = "unpaid";
			if ($this->isQueueIdInList($qid, $undeliveredresultungrouped)) {
				$status = "unpaid_undelivered";
			}
			$merged[] = array("queueid" => $qid, "productname" => $prodname, "status" => $status, "kind" => $entry['kind']);
		}

		echo json_encode(array("status" => "OK", "msg" => $merged));
	}

	function isQueueIdInList($queueid, $list)
	{
		foreach ($list as $entry) {
			if ($entry['queueid'] == $queueid) {
				return true;
			}
		}
		return false;
	}


	function changeTable($fromTableId, $toTableId, $queueids)
	{
		$ids = explode(",", $queueids);
		foreach ($ids as $id) {
			if (!is_numeric($id)) {
				echo json_encode(array("status" => "ERROR", "code" => NUMBERFORMAT_ERROR, "msg" => NUMBERFORMAT_ERROR_MSG));
				return;
			}
		}

		$pdo = DbUtils::openDbAndReturnPdoStatic();
		$pdo->beginTransaction();

		date_default_timezone_set(DbUtils::getTimeZone());
		$currentTime = date('Y-m-d H:i:s');
		$userid = CommonUtils::getUserId();

		$recordidFromTable = self::addRecordEntry($pdo, $currentTime, $userid, $fromTableId, T_FROM_TABLE);
		$recordidToTable = self::addRecordEntry($pdo, $currentTime, $userid, $toTableId, T_TO_TABLE);
		$sql = "INSERT INTO %recordsqueue% (recordid,queueid) VALUES(?,?)";
		foreach ($ids as $id) {
			CommonUtils::execSql($pdo, $sql, array($recordidFromTable, $id));
			CommonUtils::execSql($pdo, $sql, array($recordidToTable, $id));
		}

		$sql = "UPDATE %queue% SET tablenr=? WHERE id IN($queueids) AND tablenr=?";
		$stmt = $pdo->prepare(DbUtils::substTableAlias($sql));
		$stmt->execute(array($toTableId, $fromTableId));

		$pdo->commit();
		echo json_encode(array("status" => "OK"));
	}



	function getJsonProductsOfTableToPay($tableid)
	{
		$pdo = DbUtils::openDbAndReturnPdoStatic();


		$hasUserRightToPayAllOrders = CommonUtils::canUserPayAllOrders($pdo);
		if ($hasUserRightToPayAllOrders) {
			$filterOrderUserid = null;
		} else {
			$filterOrderUserid = CommonUtils::getUserId();
		}

		$currentUserId = CommonUtils::getUserId();

		if (is_null($filterOrderUserid)) {
			$showonlymyjobscheck = CommonUtils::getConfigValue($pdo, 'showonlymyjobscheck', 0);
			if ($showonlymyjobscheck == 1) {

				$sql = "SELECT showonlymyjobs FROM %user% WHERE id=?";
				$res = CommonUtils::fetchSqlAll($pdo, $sql, array($filterOrderUserid));
				if ((count($res) > 0) && ($res[0]['showonlymyjobs'] == 0)) {
					$filterOrderUserid = $currentUserId;
				}
			}
		}

		$unit = CommonUtils::caseOfSqlUnitSelection($pdo);

		$commentSql = "''";
		$commentInPaydesk = CommonUtils::getConfigValue($pdo, 'commentinpaydesk', 0);
		if ($commentInPaydesk != 0) {
			$commentSql = " CASE WHEN Q.anoption='' THEN '' ELSE CONCAT(' (',Q.anoption,')') END ";
		}
		$sql = "SELECT Q.id as id,Q.unit,Q.unitamount,CONCAT($unit,PN.name,$commentSql) as longname,Q.price as price,Q.tax,%prodtype%.kind as kind,
					%pricelevel%.name as pricelevelname,%products%.id as prodid,Q.togo as togo, ordertime,
					COALESCE(prodimageid,0) as prodimageid,COALESCE(printjobid,0) as printjobid,
					O.id as orderid,COALESCE(O.name,'') as ordername,COALESCE(O.remark,'') as orderoption,COALESCE(O.status,0) as orderstatus   
		FROM 
			%prodnames% PN INNER JOIN %queue% Q ON PN.id=Q.prodnameid 
			LEFT JOIN %bill% B ON Q.billid=B.id 
			INNER JOIN %products% ON Q.productid = %products%.id 
			INNER JOIN %pricelevel% ON Q.pricelevel = %pricelevel%.id 
			INNER JOIN %prodtype% ON %products%.category = %prodtype%.id 
			LEFT JOIN %orders% O ON Q.orderid=O.id ";
		if ($tableid == 0) {
			$sql .= "WHERE tablenr is null ";
		} else {
			$sql .= "WHERE tablenr = $tableid ";
		}
		if (!is_null($filterOrderUserid)) {
			$sql .= " AND Q.orderuser='$filterOrderUserid' ";
		}
		$sql .= "AND paidtime is null AND (B.paymentid is null OR (B.paymentid <> ? AND B.paymentid <> ?)) AND Q.toremove='0' AND isclosed is null "
			. "ORDER BY kind,ordertime, id";
		$result = CommonUtils::fetchSqlAll($pdo, $sql, array(DbUtils::$PAYMENT_GUEST, DbUtils::$PAYMENT_HOTEL));

		$prodsToPay = array();
		foreach ($result as $zeile) {
			$thePrice = $zeile['price'];
			$theTax = $zeile['tax'];
			$thePriceLevelName = $zeile['pricelevelname'];
			$longName = $zeile['longname'];
			$togo = $zeile["togo"];
			$queueid = $zeile['id'];
			$prodimageid = $zeile['prodimageid'];
			$printjobid = $zeile["printjobid"];
			$orderid = $zeile["orderid"];
			$ordername = $zeile["ordername"];
			$orderoption = $zeile["orderoption"];
			$orderstatus = $zeile["orderstatus"];
			$unit = $zeile["unit"];
			$unitamount = $zeile["unitamount"];

			$extras = $this->getExtrasOfQueueItem($pdo, $queueid);

			$prodId = $zeile['prodid'];
			$prodsToPay[] = array(
				"id" => $queueid,
				"prodid" => $prodId,
				"longname" => $longName,
				"pricelevelname" => $thePriceLevelName,
				"price" => $thePrice,
				"tax" => $theTax,
				"togo" => $togo,
				"prodimageid" => $prodimageid,
				"printjobid" => $printjobid,
				"orderid" => $orderid,
				"ordername" => $ordername,
				"orderoption" => $orderoption,
				"orderstatus" => $orderstatus,
				"unit" => $unit,
				"unitamount" => $unitamount,
				"extras" => $extras
			);
		}
		echo json_encode(array("status" => "OK", "msg" => $prodsToPay));
	}

	function displayBill($billtableitems, $totalPrice)
	{
		$currency = $this->commonUtils->getCurrency();
		$numberOfItemsToPay = count($billtableitems);
		if ($numberOfItemsToPay > 0) {
			echo "<br><br><table id=bill class=billtable>";
			echo "<tr><th>Speise/Getränk<th id=pricecolheader>Preis ($currency)</tr>";
			for ($i = 0; $i < $numberOfItemsToPay; $i++) {
				$aProductToPay = $billtableitems[$i];
				echo "<tr>";
				echo "<td>" . $aProductToPay['textOfButton'] . "<td id=pricecol>" . $aProductToPay['price'] . "</tr>";
			}
			echo "<tr><td id=totalprice colspan=2>Gesamtpreis: " . $totalPrice . " $currency </tr>";
		}

		echo "</table>";
	}



	static function declareProductBeDeliveredWithGivenPdo($pdo, $queueid)
	{
		if (is_numeric($queueid)) {
			date_default_timezone_set(DbUtils::getTimeZone());
			$delivertime = date('Y-m-d H:i:s');

			$updateSql = "UPDATE %queue% SET delivertime=? WHERE id=?";
			CommonUtils::execSql($pdo, $updateSql, array($delivertime, $queueid));

			$updateSql = "UPDATE %queue% SET readytime=? WHERE id=? AND readytime is NULL";
			CommonUtils::execSql($pdo, $updateSql, array($delivertime, $queueid));
		}
	}

	function declareProductBeDelivered($queueid)
	{
		if (is_numeric($queueid)) {
			$pdo = $this->dbutils->openDbAndReturnPdo();
			self::declareProductBeDeliveredWithGivenPdo($pdo, $queueid);
		}
	}

	function declareMultipleProductsDelivered($queueids)
	{
		$ids = explode(",", $queueids);
		$pdo = DbUtils::openDbAndReturnPdoStatic();
		$pdo->beginTransaction();

		for ($i = 0; $i < count($ids); $i++) {
			$aQueueId = $ids[$i];
			if (is_numeric($aQueueId)) {
				self::declareProductBeDeliveredWithGivenPdo($pdo, $aQueueId);
			}
		}
		$pdo->commit();
		echo json_encode(array("status" => "OK"));
	}

	function declareProductNotBeDelivered($queueid)
	{
		$pdo = DbUtils::openDbAndReturnPdoStatic();
		if (is_numeric($queueid)) {
			date_default_timezone_set(DbUtils::getTimeZone());
			$delivertime = date('Y-m-d H:i:s');
			$updateSql = "UPDATE %queue% SET delivertime=? WHERE id=?";
			$stmt = $pdo->prepare(DbUtils::substTableAlias($updateSql));
			$stmt->execute(array(null, $queueid));
		}
	}

	public function getAllPreparedProductsForTableidAsArray($pdo, $tableid)
	{
		if (!is_null($tableid)) {
			if (!is_numeric($tableid)) {
				return array("prods" => array(), "ids" => '');
			}
			$sql = "SELECT DISTINCT Q.id as id,tableno,longname,anoption,readytime,ordertime,COALESCE(P.bgcolor,'') as bgcolor,COALESCE(P.fgcolor,'') as fgcolor ";

			$sql .= "FROM %queue% Q,%products% P,%resttables% R ";

			$sql .= "WHERE (readytime IS NOT NULL and delivertime IS NULL ";
			$sql .= "AND Q.tablenr=R.id ";
			$sql .= "AND R.id=" . $tableid . " ";
		} else {
			$sql = "SELECT DISTINCT Q.id as id,'' as tableno,longname,anoption,readytime,ordertime,COALESCE(P.bgcolor,'') as bgcolor,COALESCE(P.fgcolor,'') as fgcolor ";

			$sql .= "FROM %queue% Q,%products% P ";

			$sql .= "WHERE (readytime IS NOT NULL and delivertime IS NULL ";
			$sql .= "AND Q.tablenr is null ";
		}

		$sql .= "AND Q.productid=P.id ";
		$sql .= "AND (Q.isminusarticle IS NULL OR Q.isminusarticle='0') ";
		$sql .= "AND Q.isclosed is null ";
		$sql .= "AND Q.clsid is null ";
		$sql .= "AND toremove = '0') ";

		$sql = $sql . " ORDER BY tableno,readytime,ordertime,longname";

		$dbresult = CommonUtils::fetchSqlAll($pdo, $sql, null);

		$arrayOfProdsForTable = array();
		$idsProdsOfTable = array(); // this is a hack! All queueids of a table redundant for "Deliver all"
		foreach ($dbresult as $zeile) {

			$extras = $this->getExtrasOfQueueItem(null, $zeile['id']);

			$anProdElem = array(
				"id" => $zeile['id'],
				"longname" => $zeile['longname'],
				"option" => $zeile['anoption'],
				"extras" => $extras,
				"status" => "ready_to_deliver",
				"bgcolor" => $zeile['bgcolor'],
				"fgcolor" => $zeile['fgcolor']
			);
			$arrayOfProdsForTable[] = $anProdElem;
			$idsProdsOfTable[] = $zeile['id'];
		}

		return array("prods" => $arrayOfProdsForTable, "ids" => join(',', $idsProdsOfTable));
	}

	public function numberOfProductsForTableNotDelivered($pdo, $tableid)
	{
		$sql = "SELECT %queue%.id as id ";
		if (!is_null($tableid)) {
			$sql .= "FROM %queue%,%resttables% ";
		} else {
			$sql .= "FROM %queue% ";
		}
		$argument = null;
		$sql .= "WHERE delivertime IS NULL ";
		$sql .= "AND %queue%.isclosed is null ";
		$sql .= "AND workprinted='0' ";
		$sql .= "AND toremove = '0' ";
		if (!is_null($tableid)) {
			$sql .= "AND %resttables%.id=? ";
			$sql .= "AND %queue%.tablenr=%resttables%.id ";
			$argument = array($tableid);
		} else {
			$sql .= "AND %queue%.tablenr is null ";
		}

		$dbresult = CommonUtils::fetchSqlAll($pdo, $sql, $argument);

		$numberOfProducts = count($dbresult);

		return $numberOfProducts;
	}

	function getJsonAllPreparedProducts()
	{
		$pdo = DbUtils::openRepliDb();

		$lastQueueIdInCls = self::getQueueIdOfNewestClosing($pdo);

		$sql = "SELECT DISTINCT tablenr ";

		$sql .= "FROM %queue% Q LEFT OUTER JOIN %resttables% R ON Q.tablenr=R.id ";
		$sql .= "WHERE (readytime IS NOT NULL and delivertime IS NULL ";
		$sql .= "AND Q.id > ? ";
		$sql .= "AND toremove = '0' AND ";
		$sql .= "Q.isclosed is null AND ";
		$sql .= "Q.workprinted='0') ";

		$sql .= " ORDER BY tablenr";

		$result = CommonUtils::fetchSqlAll($pdo, $sql, array($lastQueueIdInCls));

		$tablesToServe = array();
		foreach ($result as $zeile) {
			$tablesToServe[] = $zeile['tablenr'];
		}

		$preparedProds_incomplete_tables = array();
		$preparedProds = array();

		$commonUtils = new CommonUtils();

		foreach ($tablesToServe as $tableid) {
			$arrayOfProdsAndIdsOfATable = $this->getAllPreparedProductsForTableidAsArray($pdo, $tableid);
			$arrayOfProdsOfATable = $arrayOfProdsAndIdsOfATable['prods'];
			$numberOfProductsTotalToServe = $this->numberOfProductsForTableNotDelivered($pdo, $tableid);
			$numberOfReadyProducts = count($arrayOfProdsOfATable);

			if ($numberOfReadyProducts >= $numberOfProductsTotalToServe) {
				$tablestatus = "complete";
				$tableheadeline = $commonUtils->getTableNameFromId($pdo, $tableid);
				$preparedProds[] = array(
					"tableheadline" => $tableheadeline,
					"tableid" => $tableid,
					"tablestatus" => $tablestatus,
					"ids" => $arrayOfProdsAndIdsOfATable['ids'],
					"prodsOfTable" => $arrayOfProdsOfATable
				);
			} else {
				$tablestatus = "incomplete";
				$tableheadeline = $commonUtils->getTableNameFromId($pdo, $tableid);
				$preparedProds_incomplete_tables[] = array(
					"tableheadline" => $tableheadeline,
					"tableid" => $tableid,
					"tablestatus" => $tablestatus,
					"ids" => $arrayOfProdsAndIdsOfATable['ids'],
					"prodsOfTable" => $arrayOfProdsOfATable
				);
			}
		}

		$newProdsToServe = self::getNewProductsToServe($pdo);
		self::setNewProductsToServe($pdo, 0);

		$items = array_merge($preparedProds, $preparedProds_incomplete_tables);

		echo json_encode(array("items" => $items, "newproductstoserve" => $newProdsToServe));
	}

	/* 
	 * Return as JSON object a list of max 10 entries of products that
	 * have been delivered to a table
	 */
	function getJsonLastDeliveredProducts()
	{
		$pdo = DbUtils::openRepliDb();
		$lastQueueIdInCls = self::getQueueIdOfNewestClosing($pdo);

		$sql = "SELECT DISTINCT %queue%.id as id,tableno,longname,delivertime,ordertime,anoption,%products%.id as prodid,COALESCE(%products%.bgcolor,'') as bgcolor,COALESCE(%products%.fgcolor,'') as fgcolor ";

		$sql .= "FROM %queue%,%resttables%,%products% ";
		$sql .= "WHERE (delivertime IS NOT NULL ";
		$sql .= "AND %queue%.id > ? ";
		$sql .= "AND %queue%.productid=%products%.id ";
		$sql .= "AND %queue%.tablenr=%resttables%.id ";
		$sql .= "AND toremove = '0' AND ";
		$sql .= "%queue%.isclosed is null AND ";
		$sql .= "%queue%.workprinted='0') ";

		$sql .= "ORDER BY delivertime DESC,ordertime,longname LIMIT 10";

		$result1 = CommonUtils::fetchSqlAll($pdo, $sql, array($lastQueueIdInCls));

		$sql = "SELECT DISTINCT %queue%.id as id,'' as tableno,longname,delivertime,ordertime,anoption,%products%.id as prodid,COALESCE(%products%.bgcolor,'') as bgcolor,COALESCE(%products%.fgcolor,'') as fgcolor ";

		$sql .= "FROM %queue%,%products% ";
		$sql .= "WHERE (delivertime IS NOT NULL ";
		$sql .= "AND %queue%.id > ? ";
		$sql .= "AND %queue%.productid=%products%.id ";
		$sql .= "AND %queue%.tablenr is null ";
		$sql .= "AND toremove = '0' AND ";
		$sql .= "%queue%.isclosed is null AND ";
		$sql .= "%queue%.workprinted='0') ";

		$sql .= "ORDER BY delivertime DESC,ordertime,longname LIMIT 10";

		$result2 = CommonUtils::fetchSqlAll($pdo, $sql, array($lastQueueIdInCls));

		$result = array_merge($result1, $result2);

		$lastDeliveredProds = array();
		foreach ($result as $zeile) {
			$productid = $zeile['prodid'];
			$useConditions = $this->getUseKitchenAndSupplyForProd($pdo, $productid);
			if ($useConditions["usesupply"] == 1) {

				$extras = $this->getExtrasOfQueueItem(null, $zeile['id']);

				$deliveredProd = array(
					"id" => $zeile['id'],
					"longname" => $zeile['longname'],
					"option" => $zeile['anoption'],
					"extras" => $extras,
					"delivertime" => $zeile['delivertime'],
					"tablename" => $zeile['tableno'],
					"bgcolor" => $zeile['bgcolor'],
					"fgcolor" => $zeile['fgcolor']
				);
				$lastDeliveredProds[] = $deliveredProd;
			}
		}
		echo json_encode(array("status" => "OK", "msg" => $lastDeliveredProds));
	}



	private function getKindOfQueueItem($pdo, $queueid): int
	{
		$sql = 'select T.kind from %queue% Q,%prodtype% T,%products% P WHERE Q.productid=P.id AND P.category=T.id and Q.id=?';
		$kind = KIND_FOOD;
		$res = CommonUtils::fetchSqlAll($pdo, $sql, array($queueid));
		if (count($res) > 0) {
			$kind = $res[0]['kind'];
		}
		return $kind;
	}

	/**
	 * 
	 * Get the max billid and the next billuid due to the number range that depends on the paymentId
	 * 
	 * @param mixed $pdo
	 * @param int $paymentId
	 * @return void
	 */
	private function getNextBillIds($pdo): array
	{
		$nextBillIds = Bill::getNextBillIds($pdo);
		$maxId = $nextBillIds["maxid"];
		$nextBilluid = $nextBillIds["nextbilluid"];
		return array("maxId" => $maxId, "nextBillUid" => $nextBilluid);
	}

	/**
	 * If an eBon-URL is configue then calculate the reference
	 * @param mixed $pdo
	 * @param int $billid
	 * @param int $userId
	 * @param float $brutto
	 * @return string|null
	 */
	private function generateEbonReference($pdo, int $billid, int $userId, float $brutto) : ?string {
		$ebonUrl = CommonUtils::getPureEbonUrl($pdo);
		$needsebonupload = (trim($ebonUrl) == '' ? 0 : 1);
		if ($needsebonupload == 0) {
			return null;
		} else {
			$ebonRefenence = $billid . "-" . $userId . "-" . $brutto . "-" . md5(rand());
			if (strlen($ebonRefenence) > 50) {
				$ebonRefenence = substr($ebonRefenence, 0, 50);
			}
			return $ebonRefenence;
		}
	}

	private function doWorkflowForQueueIds($pdo, array $ids_array, ?string $paidtime, string $currentTime,int $billid, int $cameFromOrdering, int $declareready) {
		$workflowfood = CommonUtils::getConfigValue($pdo, 'workflowconfig', 0);
		$workflowdrinks = CommonUtils::getConfigValue($pdo, 'workflowconfigdrinks', 0);
		for ($i = 0; $i < count($ids_array); $i++) {
			$queueid = $ids_array[$i];

			if (is_numeric($queueid)) {
				$kind = $this->getKindOfQueueItem($pdo, $queueid);
				$digigopaysetready = CommonUtils::getConfigValue($pdo, 'digigopaysetready', 0);
				$workflowconfig = $workflowfood;
				if ($kind == KIND_DRINKS) {
					$workflowconfig = $workflowdrinks;
				}
				if (($cameFromOrdering == 1) && ($workflowconfig == 1)) {
					$declareready = $digigopaysetready;
				}

				if ($declareready == 0) {
					$updateSql = "UPDATE %queue% SET paidtime=?, billid=? WHERE id=?";
					$stmt = $pdo->prepare(DbUtils::substTableAlias($updateSql));
					$stmt->execute(array($paidtime, $billid, $queueid));
				} else {
					$updateSql = "UPDATE %queue% SET paidtime=?, billid=?,readytime=?,delivertime=? WHERE id=?";
					$stmt = $pdo->prepare(DbUtils::substTableAlias($updateSql));
					$stmt->execute(array($paidtime, $billid, $currentTime, $currentTime, $queueid));
				}
			}
		}
	}

	private function assignQueueItemsToBill($pdo, array $ids_array, int $billid) {
		for ($i = 0; $i < count($ids_array); $i++) {
			$queueid = $ids_array[$i];

			if (is_numeric($queueid)) {
				$billProdsSql = "INSERT INTO `%billproducts%` (`queueid`,`billid`) VALUES ( ?,?)";
				$stmt = $pdo->prepare($this->dbutils->resolveTablenamesInSqlString($billProdsSql));
				$stmt->execute(array($queueid, $billid));
			}
		}
	}

	private function assignQueueItemsToRecord($pdo, array $ids_array, int $recordid) {
		for ($i = 0; $i < count($ids_array); $i++) {
			$queueid = $ids_array[$i];

			if (is_numeric($queueid)) {
				$sql = "INSERT INTO %recordsqueue% (recordid,queueid) VALUES(?,?)";
				CommonUtils::execSql($pdo, $sql, array($recordid, $queueid));
			}
		}
	}

	/**
	 * Use the signing concepts cbird or Fiskaly Sign-AT
	 * @param mixed $pdo
	 * @param int $austriaEnabled
	 * @param int $austriabind
	 * @param array $ids_array
	 * @param int $paymentId
	 * @param int $billid
	 * @return bool
	 */
	private function doAustriaSigning($pdo, int $austriaEnabled, int $austriabind, array $ids_array, int $paymentId, int $billid): bool	 {
		$cbirdFolder = trim(CommonUtils::getConfigValue($pdo, 'cbirdfolder', ''));

		if (($austriaEnabled == 1) && ($austriabind == Rksv::FISKALY_SIGN_AT)) {
			$rksv = new Rksv();
			$standardV1 = $rksv->createStandardV1Schema($pdo, $ids_array, Rksv::RECEIPT_TYPE_NORMAL);
			$signRequest = SignAT::signReceipt($pdo, $standardV1);
			if ($signRequest['status'] == 'OK') {
				$fiskalysignresponse = $signRequest['msg'];
				$fiskalysignresponse->saveIntoBill($pdo, $billid);
			} else {
				echo json_encode($signRequest);
				return false;
			}
		} else if (($austriaEnabled == 1) && ($cbirdFolder != "")) {
			$idlist = join(",", $ids_array);
			$sql = "SELECT count(PN.name) as mycount,PN.name as productname,tax,price FROM %queue% Q,%prodnames% PN WHERE Q.id in ($idlist) AND Q.prodnameid=PN.id GROUP BY productid, price, tax";
			$cbirdEntries = CommonUtils::fetchSqlAll($pdo, $sql);
			if ($austriabind == Rksv::CBIRD) {
				// 0=nothing, 1 = cbird, 2=QRK (R2B), 3=QRK(Receipt) - hier 1=cbird was chosen


				if ($paymentId == 1) {
					$paymentText = "Bar";
				} else {
					$sql = "SELECT name from %payment% WHERE id=?";
					$r = CommonUtils::fetchSqlAll($pdo, $sql, array($paymentId));
					$paymentText = $r[0]["name"];
				}
				$positionen = array();
				foreach ($cbirdEntries as $aPosition) {
					$priceInCent = $aPosition["price"] * 100;
					$aPos = array(
						"bezeichnung" => $aPosition["productname"],
						"menge" => intval($aPosition["mycount"]),
						"einzelpreis" => intval($priceInCent),
						"ust" => intval($aPosition["tax"])
					);
					$positionen[] = $aPos;
				}
				$cbirdEntry = array("zahlungsmittel" => $paymentText, "positionen" => $positionen);
				$cbirdEntryJson = json_encode($cbirdEntry);
				$currentTime = date('Y-m-d');
				$longid = str_pad($billid, 10, '0', STR_PAD_LEFT);

				$filename = $cbirdFolder . "/" . $currentTime . "-" . $longid . ".json";
				file_put_contents($filename, $cbirdEntryJson);
			} else if (($austriabind == Rksv::QRK_R2B) || ($austriabind == Rksv::QRK_RECEIPT)) {
				self::sendBillToQRK($pdo, $paymentId, $billid);
			}
		}
		return true;
	}

	/**
	 * If a bill needs to be created on a set of items it is necessary to check that not by parallel processing
	 * (which can happen even with serialized transactions) some of the items were already paid. In this case we
	 * would let it pay multiple times. This method can check on this and serve as a helper method
	 * @param mixed $pdo
	 * @param array $ids_array	ids of the items in the queue table
	 * @return bool	true if all items are not paid, false if there are paid items
	 */
	private function areAllQueueItemsUnpaid($pdo, array $ids_array): bool {
		$allUnpaid = true;
		$queueIdList = str_repeat('?,', count($ids_array) - 1) . '?';
		$sql = "SELECT count(id) as countid FROM %queue% WHERE paidtime is not null AND id in ($queueIdList)";
		$counts = CommonUtils::fetchSqlAll($pdo, $sql, $ids_array);
		if (intval($counts[0]["countid"]) > 0) {
			$allUnpaid = false;
		}
		return $allUnpaid;
	}

	/**
	 * Return the tax that the user for the given userid has to pay for tips ad decimal number.
	 * @param mixed $pdo
	 * @param mixed $userid
	 * @return float
	 */
	private function getTipTaxOfUser($pdo, $userid): float {
		$tipTax = 0.0;
		$sql = "SELECT tiptax FROM %user% WHERE id=?";
		$res = CommonUtils::fetchSqlAll($pdo, $sql, array($userid));
		if (count($res) > 0) {
			$tipTax = floatval($res[0]['tiptax']);
		}
		return $tipTax;
	}

	/**
	 * Calculate the brutto and netto value of all articles with the given queue ids.
	 * @param mixed $pdo
	 * @param array $queue_ids_array
	 * @return array{brutto: mixed, netto: mixed}
	 */
	private function calcBruttoNettoOfArticles($pdo, array $queue_ids_array): BruttoNetto {
		$idlist = join("','", $queue_ids_array);
		$sql = "SELECT SUM(price) as brutto,ROUND(SUM(price/(1 + %queue%.tax/100.0)),6) as netto FROM %queue% WHERE id IN ('$idlist')";
		$row = CommonUtils::getRowSqlObject($pdo, $sql, null);
		$brutto = $row->brutto;
		$netto = $row->netto;
		return new BruttoNetto($brutto, $netto);
	}


	/*
	 * Test if all queue items with the given ids are not paid
	 * -> if there are paid items --> report error by return negative value
	 * 
	 * Set paid column with the given date
	 * Create bill
	 * Return a bill id
	 */
	public function declarePaidCreateBillReturnBillId($pdo, $ids, $tableid, $paymentId, $declareready, $host, $calledInternally = false, $reservationid = '', $guestinfo = '', $intguestid = '', $userid = null, $billdate = null, $tip = null, $cameFromOrdering = 0, ?array $origBillIdsToReference = null)
	{
		$austriaEnabled = CommonUtils::getConfigValue($pdo, "austria", 0);
		$austriabind = CommonUtils::getConfigValue($pdo, 'austriabind', 0);
		// Since I am not sure on how to handle the RKSV with guest payments I will not support it
		if (($austriaEnabled == 1) && (($paymentId == DbUtils::$PAYMENT_GUEST) || ($paymentId == DbUtils::$PAYMENT_HOTEL))) {
			echo json_encode(array("status" => "ERROR", "msg" => "Gastbuchungen werden im Österreich-Modus nicht unterstützt."));
			return false;
		}

		date_default_timezone_set(DbUtils::getTimeZone());
		$currentTime = date('Y-m-d H:i:s');
		if (!is_null($billdate)) {
			$currentTime = $billdate;
		}
		if (!is_null($tip) && !is_numeric($tip)) {
			$tip = null;
		} else {
			// parse to float
			$tip = floatval($tip);
		}

		if ($intguestid == '') {
			$intguestid = null;
		}
		if ($reservationid != "") {
			$reservationid = substr($reservationid, 0, 30);
		}
		if ($guestinfo != "") {
			$guestinfo = substr($guestinfo, 0, 30);
		}

		$recordAction = T_BILL;
		if (($paymentId == DbUtils::$PAYMENT_GUEST) || ($paymentId == DbUtils::$PAYMENT_HOTEL)) {
			$host = 0;
			$recordAction = T_BILLORDERBILL;
		}

		$printextras = CommonUtils::getConfigValue($pdo, 'printextras', 0);

		if (trim($ids) === "") {
			echo json_encode(array("status" => "ERROR", "msg" => "Keine Artikel zum Abrechnen"));
			return;
		}

		$ids = trim($ids, ",");

		$ids_array = explode(',', $ids);
		
		if (is_null($userid)) {
			$userid = CommonUtils::getUserId();
		}

		if (CommonUtils::callPlugin($pdo, "createBill", "replace")) {
			return;
		}
		CommonUtils::callPlugin($pdo, "createBill", "before");

		if (!$calledInternally) {
			CommonUtils::setTransactionSerializable($pdo);
			$pdo->beginTransaction();
		}

		$allUnpaid = $this->areAllQueueItemsUnpaid($pdo, $ids_array);

		$billid = (-1);

		if ($allUnpaid == false) {
			if (!$calledInternally) {
				echo json_encode(array("status" => "ERROR", "code" => ERROR_UNCLEAR_PAYSTATUS, "msg" => ERROR_UNCLEAR_PAYSTATUS_MSG));
				$pdo->rollBack();
				CommonUtils::resetTransactionIsolationLevel($pdo);
			}
			return false;
		}

		// at this point we are sure that all items are unpaid, so we can continue with bill creation and payment declaration
		
		$nextBillIds = $this->getNextBillIds($pdo);
		$maxId = $nextBillIds["maxId"];
		$nextBilluid = $nextBillIds["nextBillUid"];

		if (!is_null($maxId) && ($maxId > 0)) {
			$billid = intval($maxId) + 1;
		} else {
			$billid = 1;
		}

		if (!$this->commonUtils->verifyLastBillId($pdo, $billid)) {
			if (!$calledInternally) {
				echo json_encode(array("status" => "ERROR", "code" => ERROR_INCONSISTENT_DB, "msg" => ERROR_INCONSISTENT_DB_MSG));
				$pdo->rollBack();
				CommonUtils::resetTransactionIsolationLevel($pdo);
			}
			return false;
		} else {
			$this->commonUtils->setLastBillIdInWorkTable($pdo, $billid);
		}

		if (is_null($tableid)) {
			$tableid = 0;
		}

		// lets now calculate the brutto and netto values

		$bruttoNettoOfBillArticles = $this->calcBruttoNettoOfArticles($pdo, $ids_array);

		// Now let's calculate due to Kassensichv. We have two official examples in the spec:

		// Umsatz 19% 44,80€, Zahlung bar 50€ - Rest ist Trinkgeld an Arbeitnehmer 5,20€
		// Beleg^44.80_0.00_0.00_0.00_5.20^50.00:Bar
		// BON_TYP: "Beleg"
		// GV_TYP: "TrinkgeldAN" (0%) 5,20€
		// GV_TYP: „Umsatz“ (19%) 44,80€
		//
		// Umsatz 19% 44,80€, Zahlung bar 50€ - Rest ist Trinkgeld an Unternehmer/Arbeitgeber
		// 5,20€
		// Beleg^50.00_0.00_0.00_0.00_0.00^50.00:Bar
		// BON_TYP: "Beleg"
		// GV_TYP: "TrinkgeldAG" (19%) 5,20€
		// GV_TYP: „Umsatz“ (19%) 44,80€


		// So summary: isowner -> GV_TYP_TG_AG, otherwise GV_TYP_TG_AN
		//			   user has to pay taxes: adding to brutto and netto, otherwise not mentioning - is part of user

		// Now in case the user is the owner and the tip needs to be added as "Betriebseinnahme" we have to 
		// add it to brutto and netto.
		$isOwner = CommonUtils::isOwner($pdo, $userid);

		$gvTypOfBill = null;

		if (($paymentId == DbUtils::$PAYMENT_GUEST) || ($paymentId == DbUtils::$PAYMENT_HOTEL)) {
			$gvTypOfBill = DSFinvk::$GV_TYP_FORDERUNGSENTSTEHUNG;
		} else if (!is_null($intguestid) && ($intguestid != '')) {
			$gvTypOfBill = DSFinvk::$GV_TYP_FORDERUNGSAUFLOESUNG;
		} else {
			$gvTypOfBill = DSFinvk::$GV_TYP_UMSATZ; // normal beleg
		}

		// never mix TG and Lieferbon or take care that they are put into different positions
		if (!is_null($tip) && ($tip > 0)) {
			if ($isOwner) {
				$gvTypOfBill = DSFinvk::$GV_TYP_TG_AG;
			} else {
				$gvTypOfBill = DSFinvk::$GV_TYP_TG_AN;
			}
		}

		// if tip is to be added to bill
		$nettoOfTip = 0.0;
		$tiptax = 0.0;
		if (!is_null($tip)) {
			// calculate netto part of tip
			$tiptax = $this->getTipTaxOfUser($pdo, $userid);
			$nettoOfTip = CommonUtils::calcNettoFromBrutto($tip, (float)$tiptax);
		}
		
		if ($isOwner) {
			$bruttoNetto = new BruttoNetto(
				$bruttoNettoOfBillArticles->getBrutto() + $tip, 
				$bruttoNettoOfBillArticles->getNetto() + $nettoOfTip);
		} else {
			$bruttoNetto =  new BruttoNetto(
				$bruttoNettoOfBillArticles->getBrutto() + $tip, 
				$bruttoNettoOfBillArticles->getNetto());
		}
		// now let's calculate the bruttofortax: if the user has to pay taxes for the tip, then the bruttofortax is the 
		// sum of the brutto of the articles and the tip, otherwise only the brutto of the articles
		$bruttoForTax = $bruttoNettoOfBillArticles->getBrutto();
		if (!is_null($tip) && $isOwner) {
			$bruttoForTax += $tip;
		}

		$signature = CommonUtils::calcSignatureForBill($currentTime, $bruttoNetto->getBrutto(), $bruttoNetto->getNetto(), $userid);

		$ebonRefenence = $this->generateEbonReference($pdo, $billid, $userid, $bruttoNetto->getBrutto());
		$needsebonupload = (is_null($ebonRefenence) ? 0 : 1);

		$billInsertSql = "INSERT INTO `%bill%` (`id` ,`billuid`, `billdate`,`brutto`,`netto`,`bruttofortax`,`articlebrutto`,`articlenetto`,
			`tableid`,`paymentid`,`userid`,`ref`,`tax`,`host`,`reservationid`,`guestinfo`,`intguestid`,`printextras`,`signature`,
			`tipbrutto`,`tiptax`,`tipnetto`,`ebonref`,`needsebonupload`) VALUES (?,?,?,?,?,?,?,?,?,?,?,NULL,NULL,?,?,?,?,?,?,?,?,?,?,?)";
		$stmt = $pdo->prepare($this->dbutils->resolveTablenamesInSqlString($billInsertSql));
		$stmt->execute(array($billid, $nextBilluid, $currentTime, 
			$bruttoNetto->getBrutto(), 
			$bruttoNetto->getNetto(), 
			$bruttoForTax,
			$bruttoNettoOfBillArticles->getBrutto(), $bruttoNettoOfBillArticles->getNetto(),
			$tableid, $paymentId, $userid, $host, $reservationid, $guestinfo, $intguestid, $printextras, $signature, 
			$tip, $tiptax,$nettoOfTip, $ebonRefenence, $needsebonupload));

		$paidtime = $currentTime;
		if (($paymentId == DbUtils::$PAYMENT_GUEST) || ($paymentId == DbUtils::$PAYMENT_HOTEL)) {
			$paidtime = null;
		}

		$this->doWorkflowForQueueIds($pdo, $ids_array, $paidtime, $currentTime, $billid, $cameFromOrdering,$declareready);
		$this->assignQueueItemsToBill($pdo, $ids_array, $billid);
		$recordid = self::addRecordEntry($pdo, $currentTime, $userid, ($tableid == 0 ? null : $tableid), $recordAction);
		$this->assignQueueItemsToRecord($pdo, $ids_array, $recordid);

		Bill::setPositionsOfProductsOnBillProductsTable($pdo, $billid);

		$status = Bill::signOrdersBill($pdo, $billid, false,$gvTypOfBill);
		if ($status["status"] != "OK") {
			CommonUtils::log($pdo, "QUEUE", "Bill creation failed due to TSE error for bill with id=$billid from user $userid");

			if (!$calledInternally) {
				$pdo->rollBack();
				CommonUtils::resetTransactionIsolationLevel($pdo);
				echo json_encode(array("status" => "ERROR", "msg" => "TSE Error"));
			}
			return false;
		}

		$bonposPaymentStatus = (new BonposPayment())->createEntriesInBonposTablesDueToPayment($pdo, $billid);
		if ($bonposPaymentStatus["status"] != "OK") {
			CommonUtils::log($pdo, "QUEUE", "Bonpos tables could not be filled with payment data for bill with id=$billid: " . $bonposPaymentStatus["msg"]);
			if (!$calledInternally) {
				$pdo->rollBack();
				CommonUtils::resetTransactionIsolationLevel($pdo);
				echo json_encode(array("status" => "ERROR", "msg" => "Bonpos payment entry creation failed: " . $bonposPaymentStatus["msg"]));
			}
			return false;
		}

		$bonkopfPaymentStatus = (new BonkopfPayment())->createEntriesInAllBonkopfTablesDueToPayment($pdo, $billid, $origBillIdsToReference);
		if ($bonkopfPaymentStatus["status"] != "OK") {
			CommonUtils::log($pdo, "QUEUE", "Bonkopf tables could not be filled with payment data for bill with id=$billid: " . $bonkopfPaymentStatus["msg"]);
			if (!$calledInternally) {
				$pdo->rollBack();
				CommonUtils::resetTransactionIsolationLevel($pdo);
				echo json_encode(array("status" => "ERROR", "msg" => "Bonkopf payment entry creation failed: " . $bonkopfPaymentStatus["msg"]));
			}
			return false;
		}

		if (!$calledInternally) {
			$pdo->commit();
			CommonUtils::resetTransactionIsolationLevel($pdo);
		}

		$total = $bruttoNettoOfBillArticles->getBrutto();
		if (!is_null($tip)) {
			$total += $tip;
			$total = number_format($total, 2, '.', '');
		}
		$ebonUrl = CommonUtils::getPureEbonUrl($pdo);
		$billInfo = array("billid" => $billid, "date" => $currentTime, "brutto" => $bruttoNetto->getBrutto(), "total" => $total, "ebonurl" => $ebonUrl, "ebonref" => $ebonRefenence);

		// Rksv::signBill($pdo, $billid);


		// doAustriaSigning($pdo, int $austriaEnabled, int $austriabind, array $ids_array, int $paymentId, int $billid): bool	 {
		$austriaSign = $this->doAustriaSigning($pdo, $austriaEnabled, $austriabind, $ids_array, $paymentId, $billid);

		if ($austriaSign == false) {
			return false;
		}

		if (($paymentId != DbUtils::$PAYMENT_GUEST) && ($paymentId != DbUtils::$PAYMENT_HOTEL) && !$calledInternally) {
			(new EbonSync())->sync($pdo);
			self::updateEbonInfo($pdo, $ebonRefenence, $userid);
		}

		CommonUtils::callPlugin($pdo, "createBill", "after");

		CommonUtils::log($pdo, "QUEUE", "Created bill with id=$billid from user $userid");

		if (!$calledInternally) {
			echo json_encode(array("status" => "OK", "msg" => $billInfo));
		} else {
			return $billid;
		}

		
	}

	public static function sendBillToQRK($pdo, $paymentId, $billid)
	{
		$austriaEnabled = CommonUtils::getConfigValue($pdo, "austria", 0);
		$cbirdFolder = trim(CommonUtils::getConfigValue($pdo, 'cbirdfolder', ''));
		if (($austriaEnabled == 0) || ($cbirdFolder == "")) {
			return;
		}
		if (!self::isPaymentSupportedByQRK($paymentId)) {
			return;
		}
		$austriabind = CommonUtils::getConfigValue($pdo, 'austriabind', 0);
		if ($austriabind == 2) {
			self::sendBillToQRKAsR2B($pdo, $paymentId, $billid);
		} else if ($austriabind == 3) {
			self::sendBillToQRKAsReceipt($pdo, $paymentId, $billid);
		}

	}

	private static function sendBillToQRKAsReceipt($pdo, $paymentId, $billid)
	{
		$cbirdFolder = trim(CommonUtils::getConfigValue($pdo, 'cbirdfolder', ''));
		$qrkPaidby = strval(self::getPayedByForQRK($paymentId));
		$positionen = array();

		$sql = "SELECT count(queueid) as mycount,Q.id,PN.name as productname,tax,price FROM %queue% Q,%prodnames% PN,%billproducts% BP where ";
		$sql .= " Q.prodnameid=PN.id AND ";
		$sql .= " BP.queueid=Q.id AND ";
		$sql .= " BP.billid=? ";
		$sql .= " GROUP BY PN.name,tax,price";
		$entries = CommonUtils::fetchSqlAll($pdo, $sql, array($billid));

		foreach ($entries as $aPosition) {
			$thePrice = $aPosition["price"];
			$thePriceFormatted = number_format($thePrice, 2, '.', '');
			$theTax = $aPosition["tax"];
			$theTaxFormatted = number_format($theTax, 2, '.', '');
			$aPos = array(
				"count" => strval($aPosition["mycount"]),
				"name" => $aPosition["productname"],
				"gross" => $thePriceFormatted,
				"tax" => $theTaxFormatted
			);
			$positionen[] = $aPos;
		}
		$qrkbill = array("customerText" => "", "payedBy" => $qrkPaidby, "items" => $positionen);
		$qrkreceipt = array("receipt" => array($qrkbill));
		$qrkfilecontent = json_encode($qrkreceipt);
		$filename = $cbirdFolder . "/QRK_Receipt_" . $billid . ".json";
		file_put_contents($filename, $qrkfilecontent);
	}

	private static function sendBillToQRKAsR2B($pdo, $paymentId, $billid)
	{
		$cbirdFolder = trim(CommonUtils::getConfigValue($pdo, 'cbirdfolder', ''));
		$qrkPaidby = strval(self::getPayedByForQRK($paymentId));

		$bruttoDb = CommonUtils::fetchSqlAll($pdo, "SELECT brutto FROM %bill% WHERE id=?", array($billid));
		$brutto = $bruttoDb[0]["brutto"];

		$bruttoFormatted = number_format($brutto, 2, '.', '');
		$qrkPaidby = strval(self::getPayedByForQRK($paymentId));
		$qrkbill = array(
			"receiptNum" => strval($billid),
			"gross" => "$bruttoFormatted",
			"payedBy" => "$qrkPaidby",
			"customerText" => ""
		);
		$qrkcontainer = array("r2b" => array($qrkbill));
		$qrkfilecontent = json_encode($qrkcontainer);
		$filename = $cbirdFolder . "/QRK_R2B_" . $billid . ".json";
		file_put_contents($filename, $qrkfilecontent);
	}

	private static function isPaymentSupportedByQRK($paymentid)
	{
		if (($paymentid == 1) || ($paymentid == 2) || ($paymentid == 3)) {
			return true;
		} else {
			return false;
		}
	}
	private static function getPayedByForQRK($paymentid)
	{
		if ($paymentid == 1) {
			return 0;
		} else if ($paymentid == 2) {
			return 1;
		} else if ($paymentid == 3) {
			return 2;
		}
		return 0;
	}

	private static function createProdNameOrReturnId($pdo, $name)
	{
		$sql = "SELECT id FROM %prodnames% WHERE name=?";
		$result = CommonUtils::fetchSqlAll($pdo, $sql, array($name));
		if (count($result) === 0) {
			$sql = "INSERT INTO %prodnames% (name) VALUES(?)";
			CommonUtils::execSql($pdo, $sql, array($name));
			return $pdo->lastInsertId();
		} else {
			return $result[0]["id"];
		}
	}

	private static function updateEbonInfo($pdo, $value, $userid)
	{
		$ebonRes = CommonUtils::fetchSqlAll($pdo, "SELECT value FROM %work% WHERE item=? AND signature=?", array('ebon', $userid));
		if (count($ebonRes) == 0) {
			CommonUtils::execSql($pdo, "INSERT INTO %work% (item,value,signature) VALUES(?,?,?)", array('ebon', $value, $userid));
		} else {
			CommonUtils::execSql($pdo, "UPDATE %work% SET value=? WHERE item=? AND signature=?", array($value, 'ebon', $userid));
		}
	}

	private static function updateliveorders($pdo, $liveorders)
	{
		$userid = CommonUtils::getUserId();
		$sql = "DELETE FROM %liveorders% WHERE userid=?";
		CommonUtils::execSql($pdo, $sql, array($userid));
		$sql = "INSERT INTO %liveorders% (userid,prodname,price,tableid) VALUES(?,?,?,?)";
		foreach ($liveorders as $anOrder) {
			$prodnameToSave = CommonUtils::truncateString($anOrder["prodname"], 100);
			CommonUtils::execSql($pdo, $sql, array($userid, $prodnameToSave, $anOrder["price"], $anOrder["tableid"] ?? 0));
		}
		if (count($liveorders) > 0) {
			self::updateEbonInfo($pdo, '', $userid);
		}
		echo json_encode(array("status" => "OK", "msg" => "Liveorders updated successfully."));
	}

	private static function getliveorders($pdo)
	{
		try {
			$decpoint = CommonUtils::getConfigValue($pdo, 'decpoint', '.');
			$showtableforcustomers = CommonUtils::getConfigValue($pdo, 'showtableforcustomer', 0);
			$userid = CommonUtils::getUserId();
			$sql = "SELECT username FROM %user% WHERE id=?";
			$res = CommonUtils::fetchSqlAll($pdo, $sql, array($userid));
			if (count($res) == 0) {
				error_log("User with ID " . $userid . " not found in DB. Maybe an installation in the background.");
				echo json_encode(array("status" => "ERROR", "msg" => "Benutzer mit der ID $userid nicht in der DB gefunden."));
				return;
			}
			$username = $res[0]['username'];

			$sql = "SELECT count(L.id) as amount, L.prodname, REPLACE(SUM(L.price),'.','$decpoint') as price,R.tableno as tablename from %liveorders% L "
				. "LEFT JOIN %resttables% R ON R.id=L.tableid WHERE userid=? GROUP BY prodname ORDER BY L.id DESC,prodname";
			$liveorders = CommonUtils::fetchSqlAll($pdo, $sql, array($userid));
			if (count($liveorders) > 0) {
				$tablename = $liveorders[0]['tablename'];
			} else {
				$tablename = '';
			}

			$sql = "SELECT REPLACE(COALESCE(SUM(price),'0.00'),'.','$decpoint') as totalsum FROM %liveorders% WHERE userid=?";
			$sum = CommonUtils::fetchSqlAll($pdo, $sql, array($userid));
			$totalsum = $sum[0]["totalsum"];

			$ebonRes = CommonUtils::fetchSqlAll($pdo, "SELECT value FROM %work% WHERE item='ebon' AND signature=?", array($userid));
			$eBonUrl = CommonUtils::getPureEbonUrl($pdo);
			$ebonInfo = "";
			if (count($ebonRes) == 1) {
				$ebonInfo = $ebonRes[0]['value'];
			}

			$msg = array(
				"user" => $username,
				"tablename" => $tablename,
				"showtableforcustomer" => $showtableforcustomers,
				"sum" => $totalsum,
				"orders" => $liveorders,
				"ebon" => $ebonInfo,
				"ebonurl" => $eBonUrl
			);

			UsedFeatures::noteUsedFeature($pdo, UsedFeatures::$Customerview);
			echo json_encode(array("status" => "OK", "msg" => $msg));
		} catch (Exception $ex) {
			echo json_encode(array("status" => "ERROR", "msg" => $ex->getMessage()));
		}
	}
}