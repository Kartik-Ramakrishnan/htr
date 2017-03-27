<?php


class MigrateHunter {

    /* @var $attribute Mage_Eav_Model_Attribute */

    const DEFAULT_STORE = 1;

    private $fileName;
    private $store;


    /**
     * CreateCustomer constructor.
     */
    public function __construct() {
        $this->init();
    }

    protected function init() {
        ini_set('display_errors', 1);
        ini_set('memory_limit', '1024M');
        $rootDir = __DIR__ . '/..';
        require_once($rootDir . '/app/Mage.php'); //Path to Magento
        Mage::app();
    }

    /**
     * @param $message
     * @param array $row
     */
    protected function log($message, array $row = null) {
        echo $message . "<br>";

        if ($row) {
            try {
                $handle = fopen($this->getLogFileName(), "a");
                fputcsv($handle, array($row[0], $message));
                fclose($handle);
            } catch (Exception $e) {
                fclose($handle);
            }

        }

    }

    protected function getLogFileName() {
        if (!$this->fileName) {
            $this->fileName = "hunter_import_exceptions_" . time() . ".csv";
        }

        return $this->fileName;
    }

    public function delete() {
        Mage::register('isSecureArea', true);

        $customers = Mage::getModel("customer/customer")->getCollection();
        foreach ($customers as $customer) {
            try {
                $this->deleteCustomer($customer);
            } catch (Exception $e) {
                $this->log("Exception Occured" . $e->getMessage() . "\n");
            }
        }
    }

    protected function deleteCustomer(Mage_Customer_Model_Customer $customer) {

        $this->deleteAddress($customer);
        $customer->delete();
    }

    /**
     * @param Mage_Customer_Model_Customer $customer
     */
    protected function deleteAddress(Mage_Customer_Model_Customer $customer) {
        $addresses = $customer->getAddresses();
        foreach ($addresses as $address) {
            $address->delete();
        }
    }

    public function create() {

        $rows = $this->getHunters();

        foreach ($rows as $row) {
            try {
                $customerProfile = $this->getCustomerProfile($row);
                $hunterProfile = $this->getHunterProfile($row);
                $customer = $this->createCustomer($customerProfile, $hunterProfile);
                $this->createAddress($customer, $customerProfile);
            } catch (Exception $e) {
                $this->log("Exception occured - " . $e->getMessage(), $row);
                print_r($e->getTraceAsString());
            }
        }
    }

    protected function getData($SQL, $fetchMode = PDO::FETCH_ASSOC) {
        $config = array(
            'host'     => 'localhost',
            'username' => 'htr_magento',
            'password' => 'mage123',
            'dbname'   => 'htr_migrated'
        );

        try {
            $DBH = new PDO("mysql:host=" . $config['host'] . ";dbname=" . $config['dbname'] . "", "" . $config['username'] . "", $config['password']);
        } catch (Exception $e) {
            die("Error.Couldn't set database connection.");
        }

        $ST = $DBH->query($SQL);
        $ST->setFetchMode($fetchMode);

        return $ST->fetchAll();

    }

    protected function getHunters() {
        $SQL = "select distinct ORD_CUS_ID from `order`";

        return $this->getData($SQL);
    }

    protected function getCustomerProfile(array $row) {
        $customerId = $this->getValue($row, "ord_cus_id");
        $SQL = "select * from customer where cus_id = $customerId";

        $customerProfile = $this->getData($SQL);

        if (empty($customerProfile)) {
            throw new Exception("Could not retrieve Customer data for customer id " . $customerId);
        }

        return $customerProfile[0];
    }

    protected function getHunterProfile(array $row) {
        $customerId = $this->getValue($row, "ord_cus_id");
        $SQL = "select * from hunter_profile a, customer_profile b where a.hpo_cpr_id = b.cpr_id and b.cpr_cus_id = $customerId";

        $hunterProfile = $this->getData($SQL);

        if (empty($hunterProfile)) {
            throw new Exception("Could not retrieve Hunter data for customer id " . $customerId);
        }

        return $hunterProfile[0];
    }

    protected function getHunterSpecialty(array $row, $specialtyType) {
        $hunterId = $this->getValue($row, "HPO_ID", "Hunter Id");
        $SQL = "Select HSP_NAME from hunter_specialty where hsp_hpo_id = $hunterId and hsp_type = '$specialtyType'";

        return $this->getData($SQL);
    }

    protected function getHunterGameSpeciality(array $row) {
        return $this->getHunterSpecialty($row, "GAME");
    }

    protected function getHunterFishSpeciality(array $row) {
        return $this->getHunterSpecialty($row, "FISH");
    }

    protected function getHunterWeaponSpeciality(array $row) {
        return $this->getHunterSpecialty($row, "WEAPON");
    }


    protected function createCustomer(array $customerProfile, array $hunterProfile) {

        $id = $this->getValue($customerProfile, "cus_id", "Customer Id");
        $this->log("Creating Customer -  $id");

        $customer = Mage::getModel("customer/customer")
            ->setGroupId(1)// Hunters
            ->setFirstname($this->getFirstName($customerProfile))
            ->setLastname($this->getLastName($customerProfile))
            ->setEmail($this->getValue($customerProfile, "cus_email"))
            ->setWebsite($this->getValue($customerProfile, "cus_website", "", true))
            ->setReferredBy($this->getReferredBy($customerProfile))
            ->setProfession($this->getValue($hunterProfile, "hpo_profession", "", true))
            ->setIncomeRange($this->getIncomeRange($hunterProfile))
            ->setHuntingExperience($this->getHuntingExperience($hunterProfile))
            ->setFishingExperience($this->getFishingExperience($hunterProfile))
            ->setHuntedManagedLand($this->getBooleanValue($hunterProfile, "hpo_hunt_game_mng"), "", true)
            ->setHasPreviousLease($this->getBooleanValue($hunterProfile, "hpo_previous_lease"), "", true)
            ->setPreviousLeaseType($this->getPreviousLeaseType($hunterProfile))
            ->setCurrentLeaseLength($this->getValue($hunterProfile, "hpo_current_lease_length", "", true))
            ->setHasCurrentLeaseEnded($this->getBooleanValue($hunterProfile, "hpo_current_lease_end", "", true))
            ->setCurrentLeaseEndReason($this->getValue($hunterProfile, "hpo_current_lease_end_reason", "", true))
            ->setPreferTrophy($this->getBooleanValue($hunterProfile, "hpo_pref_trophy", "", true))
            ->setPreferGameManagement($this->getBooleanValue($hunterProfile, "hpo_pref_game_mng", "", true))
            ->setPartyStrength($this->getValue($hunterProfile, "hpo_pref_party_num", "", true))
            ->setPartyRelation($this->getValue($hunterProfile, "hpo_pref_party_rel", "", true))
            ->setPartnerNeeds($this->getValue($hunterProfile, "hpo_partner_needs", "", true))
            ->setLeaseNeeds($this->getValue($hunterProfile, "hpo_lease_needs", "", true))
            ->setHarvestSpecies($this->getHarvestSpecies($hunterProfile))
            ->setFishSpecies($this->getFishSpecies($hunterProfile))
            ->setHuntingMethod($this->getHuntingMethod($hunterProfile))
            ->setStore($this->getStore())
            ->setPassword($this->getValue($customerProfile, "cus_password"))
            ->save()
        ;

        return $customer;
    }


    protected function createAddress(Mage_Customer_Model_Customer $customer, array $customerProfile) {

        $region = $this->getState($customerProfile);

        if ($region instanceof Mage_Directory_Model_Region) {
            $region = $region->getId();
        }
        Mage::getModel("customer/address")
            ->setFirstname($this->getFirstName($customerProfile))
            ->setLastname($this->getLastName($customerProfile))
            ->setStreet($this->getStreet($customerProfile))
            ->setCity($this->getValue($customerProfile, "CUS_CITY", "City"))
            ->setCountryId($this->getCountry($customerProfile)->getId())
            ->setRegion($region)
            ->setPostcode($this->getValue($customerProfile, "CUS_POSTAL_CODE", "Postal Code"))
            ->setTelephone($this->getValue($customerProfile, "CUS_DAY_PHONE", "", true))
            ->setNightPhone($this->getValue($customerProfile, "CUS_NIGHT_PHONE", "", true))
            ->setIsDefaultBilling(true)
            ->setIsDefaultShipping(true)
            ->setCustomer($customer)
            ->save()
        ;

    }

    protected function getStore() {
        if (!$this->store) {
            $this->store = Mage::app()->getStore(self::DEFAULT_STORE);
        }

        return $this->store;
    }

    /**
     * @param array $row
     * @param string $columnName
     * @param string $alias
     * @param bool $canBeEmpty |false
     * @return string
     * @throws Exception
     */
    protected function getValue(array $row, $columnName, $alias = "", $canBeEmpty = false) {

        $value = trim($row[strtoupper($columnName)]);

        if (!$canBeEmpty && !$value) {
            throw new Exception(($alias ? $alias : $columnName) . " cannot be empty");
        }

        return $value;
    }

    protected function getBooleanValue(array $row, $columnName, $alias = "", $canBeEmpty = false) {

        $value = $this->getValue($row, $columnName, $alias, $canBeEmpty);

        if (!$value) {
            return false;
        }

        switch (strtoupper($value)) {
            case "Y":
            case "YES":
                return true;
            default:
                return false;
        }

    }

    protected function getFirstName(array $row) {
        $value = $this->getValue($row, "cus_first_name", "", true);

        if (!$value) {
            $email = $this->getValue($row, "cus_email");

            return $this->getPart($email, 0, "@");
        }

        return $value;

    }

    protected function getLastName(array $row) {
        $value = $this->getValue($row, "cus_last_name", "", true);

        if (!$value) {
            $email = $this->getValue($row, "cus_email");

            return $this->getPart($email, 1, "@");
        }

        return $value;

    }

    protected function getPart($value, $index, $delimiter = ",") {
        $values = explode($delimiter, $value);

        if (!is_array($values)) {
            throw new Exception("Could not split '$value' with '$delimiter' and return '$index'th value");
        }

        if (count($values) - 1 < $index) {
            throw new exception("Could not return '$index'th value of '$value' after splitting with '$delimiter'");
        }

        return $values[$index];
    }

    protected function getReferredBy(array $row) {
        $value = $this->getValue($row, "cus_refer_method", "", true);
        /* @var $attribute Mage_Eav_Model_Attribute */
        $attribute = Mage::getModel("eav/config")->getAttribute("customer", "referred_by");

        switch ($value) {
            case "Alta Vista":
                return $attribute->getSource()->getOptionId("Search Engine - Alta Vista");
            case "AOL":
                return $attribute->getSource()->getOptionId("Search Engine - AOL");
            case "Classified Ad":
            case "Magazine Ad":
            case "Classified-Other":
                return $attribute->getSource()->getOptionId("Classified Ad - Other");
            case "C|NET":
                return $attribute->getSource()->getOptionId("Search Engine - C|NET");
            case "Dallas Morning News":
                return $attribute->getSource()->getOptionId("Classified Ad - Dallas Morning News");
            case "Direct Mail":
                return $attribute->getSource()->getOptionId("Direct Mail");
            case "Duck Central":
                return $attribute->getSource()->getOptionId("Website - Duck Central");
            case "E-mail":
                return $attribute->getSource()->getOptionId("E-mail");
            case "Google":
                return $attribute->getSource()->getOptionId("Search Engine - Google");
            case "Hotbot":
                return $attribute->getSource()->getOptionId("Search Engine - Hotbot");
            case "Houston Chronicle":
                return $attribute->getSource()->getOptionId("Classified Ad - Houston Chronicle");
            case "HuntTexasDeer":
                return $attribute->getSource()->getOptionId("Website - HuntTexasDeer");
            case "Infospace":
                return $attribute->getSource()->getOptionId("Search Engine - Infospace");
            case "Javelina Hunter":
                return $attribute->getSource()->getOptionId("Website - Javelina Hunter");
            case "Lycos":
                return $attribute->getSource()->getOptionId("Search Engine - Lycos");
            case "MSN":
                return $attribute->getSource()->getOptionId("Search Engine - MSN");
            case "Netscape":
                return $attribute->getSource()->getOptionId("Search Engine - Netscape");
            case "GoTo":
            case "Overture/GoTo":
                return $attribute->getSource()->getOptionId("Search Engine - Overture/GoTo");
            case "Search Engine":
            case "Search Engine-Other":
                return $attribute->getSource()->getOptionId("Search Engine - Other");
            case "Texas Trophy Hunters":
                return $attribute->getSource()->getOptionId("Website - Texas Trophy Hunters");
            case "Thrifty Nickel":
                return $attribute->getSource()->getOptionId("Classified Ad - Thrifty Nickel");
            case "TV":
                return $attribute->getSource()->getOptionId("TV");
            case "Website":
            case "Website-Other":
                return $attribute->getSource()->getOptionId("Website - Other");
            case "Word of Mouth":
                return $attribute->getSource()->getOptionId("Word Of Mouth");
            case "Yahoo":
                return $attribute->getSource()->getOptionId("Search Engine - Yahoo");
            case "none":
            default:
                return $attribute->getSource()->getOptionId("Search Engine - Other");
        }
    }

    protected function getEAVValue($model, $attributeName, $value) {
        $attribute = Mage::getModel("eav/config")->getAttribute($model, $attributeName);
        if ($value) {
            return $attribute->getSource()->getOptionId($value);
        } else {
            return $value;
        }
    }

    protected function getIncomeRange(array $row) {
        $value = $this->getValue($row, "hpo_income_range", "", true);

        return $this->getEAVValue("customer", "income_range", $value);
    }

    protected function getHuntingExperience(array $row) {
        $value = $this->getValue($row, "hpo_num_years_hunt", "", true);

        return $this->getEAVValue("customer", "hunting_experience", $value);
    }

    protected function getFishingExperience(array $row) {
        $value = $this->getValue($row, "hpo_num_years_fish", "", true);

        return $this->getEAVValue("customer", "fishing_experience", $value);
    }

    protected function getPreviousLeaseType(array $row) {
        $value = $this->getValue($row, "hpo_current_lease_type", "", true);

        return $this->getEAVValue("customer", "previous_lease_type", $value);
    }

    protected function getMultiSelectEAV($model, $attributeName, array $values) {
        $attribute = Mage::getModel("eav/config")->getAttribute($model, $attributeName);
        if ($values) {
            return array_map(array($attribute->getSource(), 'getOptionId'), $values);
        } else {
            return $values;
        }
    }

    protected function getHarvestSpecies(array $row) {
        return $this->getMultiSelectEAV("customer", "harvest_species", $this->getHunterGameSpeciality($row));
    }

    protected function getFishSpecies(array $row) {
        return $this->getMultiSelectEAV("customer", "fish_species", $this->getHunterFishSpeciality($row));
    }

    protected function getHuntingMethod(array $row) {
        return $this->getMultiSelectEAV("customer", "hunting_method", $this->getHunterWeaponSpeciality($row));
    }


    protected function getStreet(array $row) {
        $street = array();
        $street[] = $this->getValue($row, "CUS_ADDR_L1", "Address Line 1");

        $street2 = $this->getValue($row, "CUS_ADDR_L2", "", true);
        if ($street2) {
            $street[] = $street2;
        }

        return $street;
    }

    /**
     * @param array $row
     * @return Mage_Directory_Model_Region
     * @throws Exception
     */
    protected function getState(array $row) {
        $value = $this->getValue($row, "CUS_STATE", "State", true);

        if (!$value) {
            $zipCode = $this->getValue($row, "CUS_POSTAL_CODE", "Postal Code");
            $value = $this->getStateForZipCode($zipCode);
        }

        if ($value) {
            $country = $this->getCountry($row);
            $regions = $country->getRegions();

            return $this->getRegion($regions, $value);
        } else {
            throw new Exception("Could not locate state for value '$value'");
        }
    }

    protected function getStateForZipCode($zipCode) {
        $SQL = "select state from zip_codes where zip = '$zipCode'";
        $results = $this->getData($SQL);

        if ($results && count($results) > 0) {
            $result = $results[0];
        }

        if (!empty($result)) {
            return $result["state"];
        } else {
            throw new Exception("Could not locate state for Zip '$zipCode'");
        }

    }

    protected function getCountryForZipCode($zipCode) {
        $SQL = "select country from zip_codes where zip = '$zipCode'";
        $results = $this->getData($SQL);

        if ($results && count($results) > 0) {
            $result = $results[0];
        }

        if (!empty($result)) {
            return $result["country"];
        } else {
            throw new Exception("Could not locate country for Zip '$zipCode'");
        }
    }

    /**
     * @param Mage_Directory_Model_Resource_Region_Collection $regions
     * @param string $value
     * @return Mage_Directory_Model_Region | string
     * @throws Exception
     */
    protected function getRegion(Mage_Directory_Model_Resource_Region_Collection $regions, $value) {

        if ($regions->count() == 0) {
            return $value;
        }

        /* @var $region Mage_Directory_Model_Region */
        foreach ($regions as $region) {
            if ($region->getCode() == $value) {
                return $region;
            }
        }

        foreach ($regions as $region) {
            if ($region->getName() == $value) {
                return $region;
            }
        }

        throw new Exception("Could not locate state '$value' in list of states");
    }

    /**
     * @param array $row
     * @return Mage_Directory_Model_Country
     * @throws Exception
     */
    protected function getCountry(array $row) {
        $value = $this->getValue($row, "CUS_COUNTRY", "Country", true);

        if (!$value) {
            $zipCode = $this->getValue($row, "CUS_POSTAL_CODE", "Postal Code");
            $value = $this->getCountryForZipCode($zipCode);
        }

        $country = Mage::getModel("directory/country")->loadByCode($value);

        if (!$country->getId()) {
            throw new Exception("Could not load country for code '$value'");
        }

        return $country;
    }


}

$obj = new MigrateHunter();
$obj->create();