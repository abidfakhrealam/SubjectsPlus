<?php

namespace SubjectsPlus\Control;

/**
 *   @file Querier.php
 *   @brief A class for database access
 *
 *   @author jlittle
 *   @date April, 2014
 *   @todo
 */

// Fetch styles: http://www.php.net/manual/en/pdostatement.fetch.php
// Example:

/*

     $q = new Querier;
     $rows = $q->query('SELECT * FROM department');
     print_r($rows);

     foreach ($rows as $value) {

     echo $value['name'];

     }

*/

use PDO;

#[\AllowDynamicProperties] // PHP 8.2+ compatibility
class Querier  {

    private $_query;
    private $_connection;

    /** one connection per request, shared by every Querier (see constructor). */
    private static $_shared = null;

    function __construct() {
        // SubjectsPlus creates a new Querier for almost every query. Each one used to open
        // another persistent PDO object, which also reset any transaction in progress on that
        // connection. Reuse a single connection for the whole request instead.
        if (self::$_shared instanceof PDO) {
            $this->_connection = self::$_shared;
            return;
        }

        // This creates the connection by reading the config.php

    	global $hname;
    	global $uname;
    	global $pword;
    	global $dbName_SPlus;
        global $db_port;
        global $db_cert_path;

	    if( isset($db_port) ) {

	    } else {
	      $db_port = "3306";
	    }

	    $dsn = 'mysql:dbname=' . $dbName_SPlus . ';host=' . $hname . ';port=' . $db_port . ';charset=utf8';

	    if( isset($db_cert_path) && $db_cert_path != null ) {
		    $options = array(
			    PDO::ATTR_PERSISTENT => true,
			    PDO::MYSQL_ATTR_SSL_CA => $db_cert_path,
			    // PHP 8 changed the PDO default to ERRMODE_EXCEPTION. SubjectsPlus 4.x checks errorInfo()
			    // after each query, so keep the PHP 7 behaviour to avoid fatal errors on harmless failures.
			    PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
		    );
	    } else {
		    $options = array(
			    PDO::ATTR_PERSISTENT => true,
			    PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT, // see note above
		    );

	    }

	    try {
		    $this->_connection = new PDO($dsn, $uname, $pword, $options);
		    // a persistent connection keeps attributes that earlier requests changed (e.g. a
		    // class setting FETCH_ASSOC as the default), so set the expected ones every request.
		    $this->_connection->setAttribute( PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_BOTH );
		    $this->_connection->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT );
		    // SubjectsPlus was written for MySQL 5.x. MySQL 8 (e.g. WampServer's default) adds
		    // ONLY_FULL_GROUP_BY and zero-date checks that make several older queries fail. Use the
		    // same rules as MariaDB's defaults (what this version is tested with) unless config says otherwise.
		    global $sp_db_sql_mode;
		    $sp_mode = isset( $sp_db_sql_mode ) ? (string) $sp_db_sql_mode : 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
		    if ( $sp_mode !== 'server' && preg_match( '/^[A-Z_,]*$/', $sp_mode ) ) {
			    $this->_connection->exec( "SET SESSION sql_mode = '" . $sp_mode . "'" );
		    }
		    self::$_shared = $this->_connection;

	    } catch (\PDOException $e) {
		    // log the details for the administrator instead of showing them to visitors.
		    error_log( 'SubjectsPlus database connection failed: ' . $e->getMessage() );
		    if ( ! headers_sent() ) {
			    http_response_code( 503 );
		    }
		    echo "<h1>There was a problem connecting to the database.</h1>";
		    echo "<p>Please try again later. If you are the administrator, check the database settings in control/includes/config.php and the PHP error log.</p>";
		    exit( 1 ); // stop here instead of failing later with a fatal error on every query
	    }

    }

    /** write a failed query to the PHP error log (and show it when $sp_debug is on). */
    private static function logFailure( $connection, $sql ) { // $connection: PDO or PDOStatement
        global $sp_debug;
        $info = $connection->errorInfo();
        $msg  = 'SubjectsPlus query failed: [' . ( isset( $info[1] ) ? $info[1] : '?' ) . '] ' . ( isset( $info[2] ) ? $info[2] : '' )
              . ' -- ' . preg_replace( '/\s+/', ' ', substr( (string) $sql, 0, 500 ) );
        error_log( $msg );
        if ( ! empty( $sp_debug ) ) {
            echo '<pre class="sp-debug">' . htmlspecialchars( $msg ) . '</pre>';
        }
    }

    public function query($sql, $fetch_style = NULL) {

        // Default is numbered array
        if ($fetch_style === NULL) {
            $fetch_style = PDO::FETCH_BOTH;
        }

        $connection = $this->_connection;

           $result = $connection->query($sql);

        if (!$result) {
            // upstream stopped the whole page here without a word (pages were cut off half-way,
            // e.g. on MySQL 8). Log the problem for the administrator and carry on with no rows.
            self::logFailure( $connection, $sql );
            return array();
          //  echo "<p><h2>Woah! There was a problem with that query.</h2> Maybe this will help: ";
          //  print_r($connection->errorInfo());
          //  echo "</p>";
          //  echo $sql;

            $rows = NULL;
        } else {

           $rows = $result->fetchAll($fetch_style);

        }

        return $rows;
    }

	public function prepareStatement ($sql, $params) {
		$connection = $this->_connection;


		$statement = $connection->prepare($sql);
		foreach ($params as $param=>$value){
			// bindValue, not bindParam: bindParam binds $value by reference, so every
			// placeholder ended up with the LAST value in $params.
			$statement->bindValue($param, $value, is_int($value) ? PDO::PARAM_INT : (is_null($value) ? PDO::PARAM_NULL : PDO::PARAM_STR));
		}
		return $statement;
	}


	public function queryWithPreparedStatement($sql, $fetch_style = NULL, $params = array()) {

		// Default is numbered array
		if ($fetch_style === NULL) {
			$fetch_style = PDO::FETCH_BOTH;
		}

		$query = $sql;
		$sql = $this->prepareStatement($sql, $params);
		if (!$sql || !$sql->execute()) {
			self::logFailure( $sql ? $sql : $this->_connection, $query ); // log instead of silently stopping
			return array();
			//  echo "<p><h2>Woah! There was a problem with that query.</h2> Maybe this will help: ";
			//  print_r($connection->errorInfo());
			//  echo "</p>";
			//  echo $sql;

			$rows = NULL;
		} else {
			$rows = $sql->fetchAll($fetch_style);
		}


		return $rows;
	}

    public function exec($sql) {
        $connection = $this->_connection;
        $result = $connection->exec($sql);
        return $result;

    }

    public function fetch($sql) {
        $connection = $this->_connection;
        $result = $connection->query($sql);
        return $result ? $result->fetchColumn() : false; // PDO has no fetchColumn(); the statement does

    }

    public function num_rows($sql) {
        $connection = $this->_connection;
        $result = $connection->query($sql);
        return $result ? $result->rowCount() : 0; // count() on a PDOStatement is a TypeError on PHP 8
    }


    public function quote($string) {
        $connection = $this->_connection;
        $quoted_string = $connection->quote((string) $string); // null quoted as '' (same result, no PHP 8.1+ notice)
        return $quoted_string;

    }

	public function getConnection() {
		$connection = $this->_connection;
		return $connection;
	}

    public function last_id() {
        $connection = $this->_connection;
        $last_id = $connection->lastInsertId();
        return $last_id;
    }

	public function errorInfo()
	{
		return $this->_connection->errorInfo();
	}

    public function getSingleById($sql, $factory, $id) {
        $statement = $this->_connection->prepare($sql);
        $statement->bindParam(':id', $id);
        $statement->execute();
        $result = $statement->fetch();

        return $factory::create($result);

    }
    public function getArrayById($sql, $factory, $id) {
        $objects = array();
        $statement = $this->_connection->prepare($sql);
        $statement->bindParam(':id', $id);
        $statement->execute();
        $results = $statement->fetchAll();


        foreach ($results as $result) {
            $objects[] = $factory::create($result);

        }

        return $objects;

    }
}
