<?php

/**
 * User: kartik
 * Date: 2/8/17
 * Time: 09:30
 */
class MigratedDataCleanup {

    /* @var $connection PDO */
    private $connection;
    private $tables = array();

    public function __construct() {
        $this->init();
    }

    /**
     * @param string $message
     */
    private function log($message) {
        echo $message . "\n";
    }

    private function init() {
        ini_set('display_errors', 1);
        ini_set('memory_limit', '1024M');

        $config = array(
            'host' => '127.0.0.1',
            'username' => 'htr_migrated',
            'password' => 'htr_migrated',
            'dbname' => 'htr_migrated'
        );

        try {
            $this->connection = new PDO("mysql:host=" . $config['host'] . ";dbname=" . $config['dbname'] . "", "" . $config['username'] . "", $config['password']);
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (Exception $e) {
            die("Error. Couldn't set database connection.");
        }
    }

    /**
     * @param string $SQL
     * @param bool $fetchOne
     * @return array|mixed
     */
    private function getData($SQL, $fetchOne = false) {
        /* @var $statement PDOStatement */
        $statement = $this->connection->query($SQL);
        $statement->setFetchMode(PDO::FETCH_ASSOC);

        if ($fetchOne) {
            return $statement->fetch();
        } else {
            return $statement->fetchAll();
        }
    }

    /**
     * @return array
     */
    private function getTables() {
        $SQL = "Select table_name from information_schema.tables where table_schema = 'htr_migrated'";
        $rows = $this->getData($SQL);
        $tables = array();

        foreach ($rows as $row) {
            $tables[] = $row["table_name"];
        }
        $this->log(count($tables) . " tables found to process");
        return $tables;
    }

    /**
     * @param string $table
     * @return string
     */
    private function getPrimaryKeyColumnName($table) {
        $SQL = "Select column_name from information_schema.columns where table_schema='htr_migrated' and table_name = '$table' and column_key='PRI'";
        $row = $this->getData($SQL, true);
        $this->log("Primary Key for table '$table' is '" . $row["column_name"] . "'");

        return $row["column_name"];
    }

    /**
     * @param string $table
     * @return array
     */
    private function getTextColumns($table) {
        $SQL = "Select column_name from information_schema.columns where table_schema ='htr_migrated' ";
        $SQL .= " and table_name = '$table' and data_type in ('char', 'varchar', 'text')";
        $rows = $this->getData($SQL);
        $columns = array();

        foreach ($rows as $row) {
            $columns[] = $row["column_name"];
        }
        $this->log(count($columns) . " text columns found for cleanup in table '$table' - " . implode(",", $columns));
        return $columns;
    }

    /**
     * @param string $table
     * @param string $pkColumnName
     * @param array $columns
     * @return array|mixed
     */
    private function getTableData($table, $pkColumnName, array $columns) {
        $SQL = "Select $pkColumnName, " . implode(", ", $columns) . " from `$table`";
        return $this->getData($SQL);
    }

    /**
     * @param string $table
     * @param string $pkColumnName
     * @param array $columns
     * @return PDOStatement
     */
    private function getUpdateStatement($table, $pkColumnName, array $columns) {
        $SQL = "Update `$table` set ";
        $counter = 0;

        foreach ($columns as $column) {
            if ($counter > 0) {
                $SQL .= ", ";
            }
            $SQL .= $column . " = ? ";
            $counter++;
        }

        $SQL .= " Where $pkColumnName = ? ";
        $this->log("Prepared Update SQL - $SQL");
        return $this->connection->prepare($SQL);
    }

    public function clean() {
        $tables = $this->getTables();
        foreach ($tables as $table) {
            $this->cleanTable($table);
        }
    }

    private function cleanTable($table) {
        $this->log("Processing table $table ...");
        $pkColumnName = $this->getPrimaryKeyColumnName($table);
        $textColumns = $this->getTextColumns($table);

        if (count($textColumns) == 0) {
            $this->log("No text columns found to process table '$table'. Skipping");
            return;
        }

        $statement = $this->getUpdateStatement($table, $pkColumnName, $textColumns);

        if ($statement->errorCode()) {
            print_r($statement->errorInfo());
            $this->log("Could not generate Update statement for table. Skipping");
            return;
        }

        $rows = $this->getTableData($table, $pkColumnName, $textColumns);
        $rowCount = count($rows);
        $rowCounter = 0;
        $this->connection->beginTransaction();

        try {
            foreach ($rows as $row) {
                $this->log("Processing $rowCounter of $rowCount");
                $rowCounter++;
                $values = array();

                foreach ($textColumns as $textColumn) {
                    $values[] = trim($row[$textColumn]);
                }

                $values[] = $row[$pkColumnName];
                $statement->execute($values);
            }
            $this->connection->commit();
        } catch (Exception $e) {
            $this->connection->rollBack();
            $this->log("Exception occured - " . $e->getMessage());
        }

    }
}

$c = new MigratedDataCleanup();
$c->clean();
