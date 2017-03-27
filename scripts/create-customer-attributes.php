<?php


class CustomerAttributes {

    const COLUMN_ENTITY = 0;
    const COLUMN_LABEL = 1;
    const COLUMN_CODE = 2;
    const COLUMN_TYPE = 3;
    const COLUMN_INPUT = 4;
    const COLUMN_REQUIRED = 5;
    const COLUMN_SORTORDER = 6;
    const COLUMN_OPTIONS = 7;

    const ENTITY_CUSTOMER = "customer";
    const ENTITY_CUSTOMERADDRESS = "customer_address";

    const TYPE_SELECT = "select";
    const TYPE_MULTISELECT = "multiselect";

    const SOURCEMODEL_TABLE = "eav/entity_attribute_source_table";
    const BACKEND_ARRAY = "eav/entity_attribute_backend_array";

    public function __construct() {
        $this->init();
    }

    private function init() {
        ini_set('display_errors', 1);
        ini_set('memory_limit', '1024M');
        $rootDir = __DIR__ . '/..';
        require_once($rootDir . '/app/Mage.php'); //Path to Magento
        Mage::app();
    }

    public function delete(array $rows) {

        $setup = new Mage_Eav_Model_Entity_Setup("core_setup");
        $setup->startSetup();
        foreach ($rows as $row) {
            $setup->removeAttribute($this->getEntity($row), $row[self::COLUMN_CODE]);
        }
        $setup->endSetup();

    }

    public function create(array $rows) {
        $setup = new Mage_Eav_Model_Entity_Setup("core_setup");
        $setup->startSetup();

        foreach ($rows as $row) {
            $this->createAttribute($setup, $row);
        }

        $setup->endSetup();
    }

    private function createAttribute(Mage_Eav_Model_Entity_Setup $setup, array $row) {
        $attribute = $this->getAttributeArray($row);
        $attributeCode = $row[self::COLUMN_CODE];
        $setup->addAttribute($this->getEntity($row), $attributeCode, $attribute);

        Mage::getSingleton("eav/config")
            ->getAttribute($this->getEntity($row), $attributeCode)
            ->setSortOrder($this->getSortOrder($row))
            ->setData("used_in_forms", $this->getForms($row))
            ->save()
        ;

    }

    private function getAttributeArray(array $row) {
        $attribute = array(
            "label"                      => $this->getLabel($row),
            "type"                       => $this->getType($row),
            "input"                      => $this->getInput($row),
            "backend"                    => $this->getBackEnd($row),
            "global"                     => Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_GLOBAL,
            "required"                   => $this->getRequired($row),
            "position"                   => $this->getSortOrder($row),
            "visible"                    => true,
            "user_defined"               => true,
            "searchable"                 => false,
            "filterable"                 => false,
            "comparable"                 => false,
            "visible_on_front"           => false,
            "visible_in_advanced_search" => false,
            "unique"                     => false
        );

        if ($this->isDropDownType($row)) {
            $attribute["option"] = $this->getOptions($row);
            $attribute["source"] = $this->getSourceModel($row);
        }

        return $attribute;
    }

    private function getBackEnd(array $row) {
        if ($this->isDropDownType($row)) {
            return self::BACKEND_ARRAY;
        } else {
            return false;
        }
    }

    private function getValue(array $row, $key, $columnName, $canBeEmpty = false) {
        $value = $row[$key];

        if (!$canBeEmpty && !$value) {
            throw new Exception("Column $columnName cannot be empty");
        }

        return $value;
    }

    private function getEntity(array $row) {
        $value = strtolower($this->getValue($row, self::COLUMN_ENTITY, "Attribute entity", true));

        if (!$value) {
            return self::ENTITY_CUSTOMER;
        }

        if ($value == "customer") {
            return self::ENTITY_CUSTOMER;
        } elseif ($value == "address") {
            return self::ENTITY_CUSTOMERADDRESS;
        } else {
            throw new Exception("Unknown Entity, can be only one of Customer or Address");
        }

    }

    private function getLabel(array $row) {
        return $this->getValue($row, self::COLUMN_LABEL, "Attribute label");
    }

    private function getCode(array $row) {
        return $this->getValue($row, self::COLUMN_CODE, "Attribute code");
    }

    private function getType(array $row) {
        return $this->getValue($row, self::COLUMN_TYPE, "Attribute type");
    }

    private function getInput(array $row) {
        return $this->getValue($row, self::COLUMN_INPUT, "Attribute input");
    }

    private function getRequired(array $row) {
        $value = $this->getValue($row, self::COLUMN_REQUIRED, "Attribute required", true);

        if (!$value) {
            return false;
        }

        if ($value == "Y") {
            return true;
        } else {
            return false;
        }
    }

    private function getSortOrder(array $row) {
        return $this->getValue($row, self::COLUMN_SORTORDER, "Attribute sort order", true);
    }

    private function getOptions(array $row) {
        $value = $this->getValue($row, self::COLUMN_OPTIONS, "Attribute options", true);

        if (!$value) {
            return false;
        }

        if ($this->isDropDownType($row)) {
            return array("values" => explode(",", $row[self::COLUMN_OPTIONS]));
        }
    }

    private function getSourceModel(array $row) {
        if ($this->isDropDownType($row)) {
            return self::SOURCEMODEL_TABLE;
        } else {
            return false;
        }
    }

    private function isDropDownType(array $row) {
        $value = $this->getInput($row);

        return ($value == self::TYPE_SELECT || $value == self::TYPE_MULTISELECT);
    }

    private function getForms(array $row) {
        if ($this->getEntity($row) == self::ENTITY_CUSTOMER) {
            return array("adminhtml_customer");
        } else {
            return array("adminhtml_customer_address");
        }
    }

    /**
     * @param string $message
     */
    private function log($message) {
        echo $message . "\n";
    }


}

$scriptName = "create-customer-attributes";

if (php_sapi_name() == "cli") {


    if (count($argv) < 2) {
        echo "usage: php $scriptName full_path_to_filename_with_data [delete] \n \t delete - Y,N\n";
        die;
    }

    $fileName = $argv[1];
    if (!file_exists($fileName)) {
        echo "Could not locate file '$fileName' \n";
        die;
    }
} else {
    $fileName = $_GET["filename"];

    if (!$fileName) {
        echo "Usage : $scriptName.php/?filename=name_of_file_here";
        die;
    }

    if (!file_exists($fileName)) {
        echo "Could not locate file '$fileName' \n";
        die;
    }
}
try {
    $fileHandle = fopen($fileName, "r");
    $contents = array();
    $skipFirstRow = true;

    while (!feof($fileHandle)) {
        if ($skipFirstRow) {
            $firstRow = fgetcsv($fileHandle);
            $skipFirstRow = false;
            continue;
        }
        $contents[] = fgetcsv($fileHandle);
    }
    fclose($fileHandle);
} catch (Exception $e) {
    echo $e->getMessage() . "\n";
    fclose($fileHandle);
    die;
}
$c = new CustomerAttributes();

if (count($argv) > 2 && $argv[2] == "Y") {
    $c->delete($contents);
} else {
    $c->create($contents);
}