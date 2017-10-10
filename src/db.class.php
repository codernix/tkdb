<?php

/*
 * Author:		Aboubakr Seddik Ouahabi (aboubakr@codernix.com || codernix.com@gmail.com)
 * Project:		TawfeekCMS DB (TKDB)
 * License:		MIT
 * Copyright (c) 2017 <ِAboubakr Seddik Ouahabi>

    Permission is hereby granted, free of charge, to any person obtaining a copy
    of this software and associated documentation files (the "Software"), to deal
    in the Software without restriction, including without limitation the rights
    to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
    copies of the Software, and to permit persons to whom the Software is
    furnished to do so, subject to the following conditions:

    The above copyright notice and this permission notice shall be included in all
    copies or substantial portions of the Software.

    THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
    IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
    FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
    AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
    LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
    OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
    SOFTWARE.
 */

define('INTERFACE_1',   1);         //Only interface 1 features are enabled by default
define('INTERFACE_2',   2);         //Interface 2 features are enabled too
define('SQL_STATEMENT', 0b1000); //Used to add ` to variables to indicate they're meant for an SQL query
define('SQL_DEBUG',     TRUE);
define('DB_REPORTING',  FALSE);
define('DB_PREFIX',     'MySQLi');

/*
 * concatenation constants
 */

define('LA',    ' AND ');   //SQL statement conjunction with AND
define('LO',    ' OR ');    //SQL statement conjunction with OR
define('EQ',    ' = ');

//For UNION | UNION ALL | JOIN ...etc
define('UNION_ALL', ' UNION ALL ');
define('UA',        UNION_ALL);     //Just a shorter Alias
define('U',         ' UNION ');

/*
 * This is the returned Value class, so instead of returning an array, we return an object that
 * This container expect the Data to be an Array of the sort of:
 *      array(
           0 =>
          array (
            $sought_value => 'something',
          ),
           1 =>
          array (
            $sought_value => 'some_value',
          ),
           2 =>
          array (
            $sought_value => 'more_values',
          )
        )
 */
class VALUE_CONTAINER{
    public $data,       //This will hold the data in its original state
    $val,               //This will give direct access to the sought value
    $EOF    = FALSE;    //States whether the iteration has ended yet

    private $count  = 1,        //Count how many element to expect to make it easier to iterate through the values
    $level  = 1,                //The current iteration level
    $soughtVal;                 //The value to be expected by default

    //Build up the Value Container
    public function __construct($data, $sought_val = null)
    {
        $this->data = $data;
        if(!is_null($sought_val)){
            //retain the default returned value name
            $this->soughtVal    = $sought_val;
            //In case more than one value was returned
            if(is_array($data) && ($this->count = count($data)) > 1){
                $this->val      = $data[0][$sought_val];

            }else{
                $this->val      = $data[0][$sought_val];
                $this->EOF      = true;
            }
        }
    }

    //Iterate to the next value
    //Using next() and next(1) will give the same effect
    public function next($level = null){

        //Move the level to x times set by $level, and if it exceeds the actual count number it will return a null
        for($i = 1; $i < $level; ++$i){
            ++$this->level;
        }

        if(!$this->EOF && $this->level <= $this->count){
            $this->val  = $this->data[$this->level][$this->soughtVal];
            ++$this->level;
        }
        else
            return null;

        if($this->level == $this->count) $this->EOF = false;

        return $this;
    }

    //Store the object into an external $variable
    //This does what affect do, but stores the data directly to the external container
    public function store(&$externalData){
        $externalData = $this;
        return $this;
    }
}

/*
 * To resolve the case of running several queries before freeing any one of them, and the need to use different resources without overriding the established ones,
 * we'll resolve to use resource_containers, which can expand to carry another instances themselves. (nested ones)
 */
class DATA_CONTAINER{
    public
        $query_resource     = null,
        $sql_statement      = null,
        $error              = null,
        $caller_object      = null; //DB Instance that made the call

    public function __construct($caller_object){
        $this->caller_object    = &$caller_object;
    }

    // Fetch a Query Resource directly
    //Fetch a query, the result is retrieved through an Associative array by default, unless it's set to another recognized MySQLi one
    public function fetch($query = NULL, $type = MYSQLI_ASSOC)
    {
        $query  = (is_null($query)) ? $this->query_resource : $query;

        //make sure the query was executed without errors
        if (!isset($this->error)) return $query->fetch_array($type);
        //else return null;
        else $this->release();
    }

    //When loading results into objects, this is more aesthetic to be used
    public function next($query = NULL, $type = MYSQLI_ASSOC){
        $query  = $query ?? $this->query_resource;
        return $this->fetch($query, $type);
    }

    //mysqli_resource->fetch_all()
    private function fetch_all($type = MYSQLI_ASSOC){return $this->fetchAll($type);}
    private function fetchAll($type = MYSQLI_ASSOC){
        //make sure the query was executed without errors
        if(method_exists('$this->query_resource','fetch_all')) if (!isset($this->error)) return $this->query_resource->fetch_all($type);
            else return null;
        else { //This is fallback, just in case
            $result = null;
            while($data = $this->query_resource->fetch_array($type)){
                $result[]=$data;
            }
            if (!isset($this->error)) return $result;
            //else return null;
        }
    }

    //Extract the query's data into an array
    public function extract($column = null)
    {
        while($data = $this->fetch()){$ret[] = (is_null($column)) ? $data : $data[$column];}
        return $ret;
    }

    //Releases the object's result
    public function release()
    {
        $this->query_resource->free();
    }

    //Free the query result memory
    public function end()
    {
        $this->query_resource->free_result();
    }

    //After a get() call, we can process the data as we see fit using this method
    //This fetches all the results into an associative array, in case we want to process each value alone, we better use action()
    public function process($function){

        $data = $this->fetchAll();
        call_user_func($function, $data);
        $this->release();
        return $this;
    }

    //This is a dummy function, that we need to use in case we have an unrelated Operation that we want to do regardless of any DB resource
    //For example: we have used ::stored($data) and we want to alter that data
    public function operate(&$data, $function){
        call_user_func_array($function, array($data));
        return $this;
    }

    //The same as Process, except the external used variable is modified by its Address/Reference, so it can be used outside of the scope of the object itself
    public function processEx($function, &$externalVar){
        $data = $this->fetchAll();
        call_user_func_array($function, array($data, $externalVar));
        $this->release();
        return $this;
    }
    
    //This is the same as processEx, except it takes only one variable, which will contain the result and will be accessible from outside of the scope of the object
    public function affect($function, &$externalData){
        $externalData = $this->fetchAll();
        call_user_func_array($function, array($externalData));
        $this->release();
        return $this;
    }
    
    //This does what affect do, but stores the data directly to the external container
    public function store(&$externalData){
        $externalData = $this->fetchAll();
        $this->release();
        return $this;
    }

    //A test method
    public function here(){echo "Here";}

    //The same as store, only saves one result per call, and needs to re-run a fetch()
    public function  save(&$externalData){
        $externalData       = clone $this;
        $result             = $this->fetch();
        foreach($result as $column => $value) $externalData->{$column} = $value;
        $externalData->next();
        die(DB::dissect($externalData));

        //Because this most likely is going to be called from the DB object, we need to pass on the legacy
        //$externalData = (object) array_merge((array)$externalData, (array)$this);
        $externalData->this = $this;

        DB::dissect($externalData->this->next());

        //$object       = (object) (array_merge((array) $externalData->data, (array) $externalData->this));
        $object     = clone $this;
        $object->data   = $externalData->data;

        DB::dissect($object->data);
        DB::dissect($object->next());
        //DB::dissect($externalData->next());
        return $externalData;
        return $this;
    }

    //In case we want to dissect the data being fetched directly. Very useful while debugging
    public function dissect(){
        echo DB::dissect($this->fetchAll());
        $this->release();
        return $this;
    }

    //This is preferable as it's not heavier on the resources, and in case we meet a certain condition, we can just exit()
    //and save us from keep fetch()-ing the DB.
    public function action($function){
        while($result = $this->fetch()){
            call_user_func($function, $result);
        }
        $this->release();
        return $this;
    }

    //Just apply an action that has nothing to do with the query itself
    public function apply($function){
        call_user_func($function);
        return $this;
    }

    //Catch an error and throw a msg or something
    //Original Code deleted for now
    public function qCatch($function){
        call_user_func($function);
        return $this;
    }

    //Assign the fetch results to an external $variable
    public function assign_result(&$result){return $this->assignResult($result);}
    public function assignResult(&$result){
        $result = $this->fetch();
        $this->release();
    }

    //Returned number of found records
    public function count_records(){return $this->countRecords();}
    public function countRecords(){
        return $this->query_resource->num_rows;
    }

    //This function is called after an SQL query has been launched by parent instance->run()
    //It is supposed to use the parent instance without creating a new one all over
    public function data()
    {
        return $this->caller_object;
    }

    //get the last insertedID
    //If an argument is passed, it'll be accessed by reference to store the ID,
    //otherwise --if null-- it will return the last ID instead of continuing the chaining
    public function lastID(&$id){
        if(!is_null($id)) {
            $id = $this->data()->lastID();
            return $this;
        }
        else
            return $this->data()->lastID();
    }
}

/*
 * The DB Class is designed to be abstract the maximum, and it aims to make the SQL queries more human-readable
 * , hence the DB is so far made to always start by DB::table(), except few cases
 */

//MySQLi implementation
class DB
{
    static
        $_instance          = null,  //The DB connection instance/resource
        $_db_object         = null,  //The initiated DB object, which allows multiple objects' creation
        $_report            = null,  //Used to display reports when SQL_REPORT is true
        $_secondary_table   = null,  //Secondary table in case of UNION/JOIN/SELECT more than one table. It can be array or string
        $db_engine          = null;  //Object that holds DB engine, version number, and other info

    public static
        $interface              = INTERFACE_1,  //This demonstrates whether the second interface can be used or not, it accepts INTERFACE_2 too
        $chaining_level         = null,         //This is used when INTERFACE_2 is set, and it defines the calling object's role, i.e. if it's the name of the column to be retrieved
        $connection             = null, //The connection resource
        $prev_query_resource    = null, //Last run query resource
        $query_resource         = null, //Current query resource
        $table                  = null, //The table to run queries on
        $limit                  = null, //Set the query's SQL LIMIT
        $column                 = null, //This is used when we set INTERFACE_2. This is a String
        $search_for_fields      = null, //The fields to put in the SELECT and search for them
        $sql_where              = null, //Holds the SQL WHERE condition
        $sql_order_by           = null, //The sql statement ORDER BY
        $sql_debug              = false, //The last SQL Debug
        $sql_statement          = null, //The last SQL statement
        $query_type             = null, //What sort of query we're dealing with, i.e. SELECT | INSERT | DELETE
        $sql_query              = null, //The last SQL Query Resource
        $error                  = null; //The last error (could be SQL related, or something else like logic, parsing or even PHP related)

    public function __construct($db_params = null)
    {
        if(is_null($db_params)) global $db_params;

        //Connection resource
        $this->connection = new mysqli($db_params->host,$db_params->username,$db_params->password,$db_params->dbname);
        if($this->connection->connect_errno)
        {
            if (static::$sql_debug || SQL_DEBUG) static::$error  = $this->connection->connect_error;
            else static::$error = "Cannot connect to DB;l;l;.";

            die("\r".static::$error);
        }

        return $this;
    }

    /*
     * Generic implemented methods
     * TODO: to be filled with more data
     */
    //Echo General/Global Debug info
    static function globalDebugInfo()
    {
        echo "\rCalling Classname: ".__CLASS__."\r";
    }

    //Since we can't access static::var::another_var, we use this function
    //This was doing some nasty code, not sure why I'm still leaving it here though
    static function assign_static(&$destination_var, $source_var) { return static::assignStatic($destination_var,$source_var);}
    static function assignStatic(&$destination_var, $source_var){
        $destination_var = $source_var;
    }

    //Convert the DB params' array into an object
    static function factory(&$param_array)
    {
        if(is_array($param_array)){
            $params = new stdClass();
            foreach($param_array as $param => $value){
                $params->$param = $value;
            }
            $param_array = $params;
        }
    }

    //To prohibit the singleton cloning
    public function __clone() {
        throw new Exception("Only one singleton is allowed at a time!");
    }

    //Initialiaze the object class
    public function initialize()
    {
        //Keep a record of the last seen error
        $this->last_error   = (isset($this->error)) ? $this->error : null;
        $this->table = $this->limit = $this->sql = $this->error = $this->sql_debug = $this->query_resource = $this->sql_order_by = $this->sql_where = $this->sql_warning = NULL;
        $this->sql_debug    = FALSE;

        return $this; //Enable the chaining at all levels
    }

    //Create a DB instance Singleton
    //Stop blaming Singletons for your UNITTesting issues
    private static function get_instance() { //Alias for the camelCase method
        return static::getInstance();
    }
    private static function getInstance()
    {
        if(!isset(static::$_instance)) static::$_instance = new self();
        return static::$_instance;
    }

    //Get the actual connection resource
    private function get_connection(){return $this->getConnection();}
    private function getConnection(){
        return static::getInstance()->connection;
    }

    //get the last inserted id
    public function lastID(){
        return static::getInstance()->connection->insert_id;
    }

    //To make more connection established at one time, like in the case to copy from DB to DB from different servers
    //The code is omitted for now
    static function more($db_params, $order = null /*Optional. In case you want more than 2 connections*/){
        return null;
    }

    //Close the MySQLi connection
    public function close()
    {
        if(static::getInstance()->getConnection()->close()) static::$_report = "Connection closed successfully.";
        else static::$_report = "An error has occurred while closing the connection";
        if (DB_REPORTING) echo "\r".static::$_report; //For debug reasons
    }

    //Set the table to work on
    static function setTable($table_name){ return static::table($table_name);} //Alias for backward compatibility
    static function set_table($table_name){ return static::table($table_name);} //Alias for backward compatibility
    static function table($table_name)
    {
        static::getInstance()->table = $table_name;    //Set the table to work on as Static
        return static::getInstance();
    }

    /*
     * In case we need to use more than one table, i.e. UNION | UNION ALL | JOIN ...etc
     * We rather use this Method
     *
     */
    static function union($table_array, $unionType = U){
        //Initializations
        $select     = "SELECT %s FROM `%s` {{WHERE:%s}} {{ORDER_BY:%s}}"; //The third string is a WHERE container, and the 4th is an Order By one

        //We must process an array, otherwise exit
        if(!is_array($table_array)) {
            static::getInstance()->error = "Trying to run a UNION on a single or no Table at all";
            return null;
        }

        //We check first, if only table names are given, then we SELECT ALL/*
        if(1 === static::countDim($table_array)){
            foreach($table_array as $table){
                $sql[]  = sprintf($select, '*', $table);
            }
        }else{
            //Let's get each table and its requested columns
            foreach ($table_array as $table => $columns){
                //Checks if we have any Conditions for the WHERE clause
                if(is_array)
                //Concatenate the columns accordingly
                $columns = ((null === $columns && ( !isset(static::getInstance()->search_for_fields) || null === static::getInstance()->search_for_fields)) || (!is_array($columns) && $columns === '*')) ? '*' : static::translateSelectColumns((!is_null($columns)) ? $columns : static::getInstance()->search_for_fields);
                $sql[]  = sprintf($select, $columns, $table, $table, $table);
            }
        }

        $sql    = implode($unionType, $sql);//return $sql;
        static::getInstance()->sql = $sql;
        return static::getInstance();
    }

    //unite() is the same as union() except it runs a get() directly
    static function unite($table_array, $conditions = null, $union_type = U){
        //This just to allow switching places between the method's arguments
        if(isset($conditions)){
            if(!is_array($conditions)){
                $unionType  = $conditions;
                $conditions = null;
            }
            else{
                $unionType  = $union_type;
            }

            if(isset($union_type) && $union_type !== U && $union_type !== UA && is_array($union_type)){
                $conditions = $union_type;
            }
        }
        return static::getInstance()->union($table_array, $unionType)->
        ucondition($conditions)->get();
    }

    //An Alias for a UNION ALL
    static function unionAll($table_array){
        return static::union($table_array, UA);
    }

    /*
     * Retrieve the table we're working on
     */

    public function getTable(){
        return static::getInstance()->table;
    }

    //Count a given table's rows
    static function count_table_rows($table){return static::countTableRows($table);}
    static function countTableRows($table){
        $table  = self::varToSql($table);
        return static::query("SELECT COUNT(1) FROM $table")->fetch()['COUNT(1)'];
    }

    //Count table rows another syntax
    static function count_rows(){return static::countRows();}
    static function countRows()
    {
        return static::getInstance()->one("COUNT(1)");
    }

    //Get one column from a pre-set table
    public function one($column)
    {
        $resource   = $this->get($column);
        $needed_val = (self::isCompound($column)) ? explode('.',$column)[1] : $column;
        $result     = ($resource->countRecords() > 0) ? $resource->fetch()[$needed_val] : null;
        $resource->release();
        return $result;
    }

    //Get a whole row
    public function row(){
        $resource   = $this->get('*');
        $data       = $resource->fetch();
        $resource->release();
        return $data;
    }

    //Run a raw SQL statement
    public static function raw($sql){
        return static::getInstance()->query($sql);
    }

    //Get a full column from a table
    public function column($column)
    {
        $resource   = $this->get($column);
        $result     = $resource->extract($column);
        $resource->release();
        return $result;
    }

    /********************************************************************************************************
     ** Set an sql WHERE clause
     ** where: the value of the key can be !null which is the same as true, which will return IS NOT NULL
     ** However, if we use null we'll get a IS NULL
     ********************************************************************************************************/
    public function where($where_array)
    {
        //the "$where_array" can be either an array [id => value, name => value2] or passed as JSON {id: value, name: value2}
        //$this->sql_where = (is_null($where_array)) ? NULL: ((!is_array($where_array)) ? ' WHERE'.CORE::link_array('=', json_decode($where_array)) : ' WHERE '.CORE::link_array('=', $where_array));

        static::getInstance()->sql_where = (is_null($where_array)) ? NULL: ((!is_array($where_array)) ? NULL : ' WHERE '.self::linkArray('= ', $where_array, SQL_STATEMENT));

        //For more clarified debug purposes
        if(!is_array($where_array)) static::getInstance()->error .= "\r\nAn error occurred while passing parameters to the where() method: $where_array is not an array.\r\n";

        return static::getInstance();
    }

    /*
     * This is also setting the SQL WHERE Clause, however, it's used when the conditions are more complex than a simple Equals to
     * @condition: we're allowed here to use the comparatif functions E() B() S() BE() SE() ...etc
     */
    public function condition($conditions){
        //If we set a single where condition
        if (count($conditions,COUNT_RECURSIVE) == 1)

            foreach ($conditions as $column => $condition){
                static::getInstance()->sql_where = " WHERE `$column`".$condition->operator.$condition->var;
            }
        else{
            static::getInstance()->sql_where = " WHERE ";
            foreach ($conditions as $column => $condition){
                static::getInstance()->sql_where .= $condition->conjunction."`$column`".$condition->operator.$condition->var;
            }
        }

        return $this;
    }

    //This is the Condition for a Union or Join, when we have more than one table, each has its own independent SQL Statement
    public function ucondition($conditions){
        //If no array was passed, then just leave everything as is, but register a Warning
        if(!is_array($conditions)) {
            //To-do: add the warning to the initialization and declaration
            $this->warning[]    = "There were no valid conditions for the SQL statement";
            return $this;
        }
        //otherwise
        foreach ($conditions as $table => $rules){
            $table  = "{{WHERE:$table}}";
            $where  = "WHERE ";
            foreach ($rules as $column => $condition){
                $where .= $condition->conjunction."`$column`".$condition->operator.$condition->var;
            }

            $this->sql  = str_replace($table, $where, $this->sql);
        }

        //Sanitize the rest of the query if a statement had no conditions. We have to get rid of the {{WHERE:table}}
        static::sanitizeWhere($this->sql);
        //return $this->sql;
        return $this;
    }

    //Sanitize any left {{WHERE:table}}
    private static function sanitizeWhere(&$where){
        $where = preg_replace('/\{\{WHERE:.*?\}\}/', '', $where);
    }

    //Sanitize any left {{ORDER_BY:table}}
    private static function sanitizeOrder(&$order){
        $order = preg_replace('/\{\{ORDER_BY:.*?\}\}/', '', $order);
    }

    /**
     * @param $limit
     * @return $this to keep chaining
     *
     */
    public function limit($limit){
        if(static::isRange($limit)){
            $this->limit    = " LIMIT ".((count($limit) === 1) ? $limit : $limit[0].",".$limit[1]);
        }
        return $this;
    }

    /*
     * This method is used to check whether a string is a range or not. This can be used to check whether the argument is an acepted SQL LIMIT
     */
    static function isRange(&$range, $alter_value = true, $strict = false){
        //If it's an array, let's quit right away
        if(is_array($range)) return false;

        //If $strict is true and the range has a space without the delimiter ".." it won't be considered a range, otherwise the spaces will be deleted and the numericals will be concatenated
        if($strict === true && !strpos($range,"..") && strpos($range, " ")) return false;
        //Getting rid of any mis-added spaces
        $range  = preg_replace('/\s+/', '', $range);

        //Making sure the Range is not a 0 or "0"
        if(strlen($range) == 1 && intval($range) === 0 ) return false;

        //Make sure if a single string is passed, it won't be treated as as a limit
        //if(strlen($range) == 1 && !is_numeric($range) === 0 ) return false;

        if( (strpos($range, '..') && (is_numeric(($limits = explode("..", $range))[0])) && is_numeric($limits[1]) && (($count = count($limits)) <= 2)) || (!strpos($range, "..") && is_numeric($range))) {
            $range  = ($count == 2 && $alter_value === true) ? $limits : $range;
            return true;
        }
        else
            return false;
    }

    //A raw where
    public function raw_where($where_stat){return $this->rawWhere($where_stat);}
    public function rawWhere($where_stat){
        static::getInstance()->sql_where = ' WHERE '.$where_stat;
        return static::getInstance();
    }

    //Used when UPDATE to set values
    private static function set($array_vals){
        return ' SET '.self::linkArray('= ', $array_vals, SQL_STATEMENT);
    }
    
    //Used when INSERT INTO is needed, Prepare the InsertionVals
    private static function prepInsert($array_vals){
        if(!is_array($array_vals)) return false;
        foreach($array_vals as $column => $val){
            $columns[]      = "`".$column."`";
            $vals[]         = "'".$val."'";
        }
        $columns            = "(".implode(',', $columns).")";
        $vals               = "(".implode(',', $vals).")";

        return ($columns." VALUES ".$vals);
    }

    //SQL statement ordered by *** ASC
    public function ascOrder($order_by){return $this->asc_order($order_by);}
    public function asc_order($order_by)
    {
        $this->sql_order_by	= ' ORDER BY '.$order_by.' ASC';
        return $this;
    }

    //SQL statement ordered by *** DESC
    public function descOrder($order_by){return $this->desc_order($order_by);}
    public function desc_order($order_by)
    {
        $this->sql_order_by	= ' ORDER BY '.$order_by.' DESC';
        return $this;
    }

    /*
     * Running and manipulating SQL Queries' methods
     */

    private static function query($sql = null)
    {
        $sql            = (is_null($sql)) ? static::getInstance()->sql_statement : $sql;

        $data_container = new DATA_CONTAINER(static::getInstance());

        if(($data_container->query_resource = static::getInstance()->get_connection()->query($sql)) === false && !is_null($data_container->query_resource))
        {
            static::getInstance()->error  = $data_container->error = $data_container->query_resource->error;

            if(static::$sql_debug || SQL_DEBUG) die('Cannot run query, error: '.static::getInstance()->error."\r\n\r\n\r\nSQL: ".static::getInstance()->sql_statement);
            else die('An error has occurred.');
        }
        else return ($data_container);
    }

    //Analyze the query's SQL
    public function query_analyze($sql){return $this->queryAnalyze($sql);}  //Alias
    public function queryAnalyze($sql)
    {
        //Keep a record of the last sql query
        static::getInstance()->sql_statement	= $sql;

        //In case we're debugging the code, we'll display the SQL to be executed if the SQL_REPORTING is enabled
        if(DB_REPORTING) echo "\rSQL: ".static::getInstance()->sql_statement."\r";

        //Set the query type: SELECT, INSERT, DELETE, UPDATE
        $sql_array	= explode(' ', $sql);

        switch(strtoupper($sql_array[0]))
        {
            case 'SELECT':
                static::getInstance()->query_type	= 'SELECT';
                break;

            case 'UPDATE':
                static::getInstance()->query_type = 'UPDATE';
                break;

            case 'INSERT':
                static::getInstance()->query_type = 'INSERT';
                break;

            case 'DELETE':
                static::getInstance()->query_type	= 'DELETE';
                break;

            default:
                static::getInstance()->query_type	= UNDEFINED;
                break;
        }
    }

    //Run the Query
    public function run()
    {
        return $this->get();
    }

    //count the dimension of an array
    private static function count_dim($array){return static::countDim($array);}
    private static function countDim($array){
        if(is_array(reset($array)))
            return (static::countDim(reset($array)) + 1);
        //Otherwise
        return 1;
    }

    //Convert a single value to SQL compatible
    private function valueToSql($value){
        //To avoid getting a "SELECT `COUNT(1)`" problem
        return (strpos($value, '(') ? "$value" : "`$value`");
    }

    //Convert an Array to SQL SELECT columns or an SQL param that needs a (`), and takes care of checking whether the input is a string or an array and acts accordingly
    private static function varToSql($var){return self::translateSelectColumns($var);}
    private static function translate_select_columns($columns){ return self::translateSelectColumns($columns);}
    private static function translateSelectColumns($columns){
        return ((is_array($columns) && count($columns) === 1) ? ((self::isCompound($columns[0])) ? self::compoundToSql($columns[0]) : self::valueToSql($columns[0])) :
                                                                (is_array($columns) ? (self::arrayToSql($columns)) : ((self::isCompound($columns)) ? self::compoundToSql($columns) : self::valueToSql($columns))));
    }

    //Convert a compound value to an acceptable SQL form, i.e. 'table.column' => `table`.`column`
    private static function compoundToSql($compound){
        $array  = explode('.',$compound);
        $table  = self::varToSql($array[0]);
        $column = self::varToSql($array[1]);
        return $table.'.'.$column;
    }

    //Convert table/tables to SQL form
    private static function tableToSql($table, $glue = ','){
        return ((!is_array($table)) ? (self::isCompound($table) ? self::compoundToSql($table) : self::varToSql($table)) : self::arrayToSql($table, $glue));
    }

    //Convert an array to SQL form
    private static function arrayToSql($array, $glue = ','){
        foreach($array as $element){
            $sqlTable[] = self::isCompound($element) ? self::compoundToSql($element) : self::varToSql($element);
        }
        return implode($glue,$sqlTable);
    }

    //Check if a value is a compound one: table.column
    private static function is_compound($value){return static::isCompound(value);}
    private static function isCompound($value){
        return (strpos($value,'.'));
    }

    //This expects the user has already enter the column to get and the search criteria
    //This method frees the query result right away, it doesn't need a DB::end()
    //This is always a SELECT query
    //If DB::get() doesn't pass any parameter, and DB::retrieve() doesn't set any either, it will assume the SELECT is a SELECT *
    public function get($column = null) //$column could be a single column or an array of columns
    {
        //If a UNION or JOIN was called then the DB::$sql_statement would have been already set and no need to go through the process of a regular SELECT
        if(isset($this->sql) && !is_null($this->sql))
            static::getInstance()->sql_statement = $this->sql;
        else{
            $column = ((null === $column && ( !isset(static::getInstance()->search_for_fields) || null === static::getInstance()->search_for_fields)) || (!is_array($column) && $column === '*')) ? '*' : static::translateSelectColumns((!is_null($column)) ? $column : static::getInstance()->search_for_fields);
            static::getInstance()->sql_statement    = "SELECT $column FROM ".self::tableToSql(static::getInstance()->table).((!isset(static::getInstance()->sql_where) || is_null(static::getInstance()->sql_where)) ? null : static::getInstance()->sql_where).((!isset(static::getInstance()->sql_order_by) || is_null(static::getInstance()->sql_order_by)) ? null : static::getInstance()->sql_order_by).static::getInstance()->limit;
        }

        static::sanitize(static::getInstance()->sql_statement);  //Get rid of any SQL Injections/escaped chars
        static::queryAnalyze(static::getInstance()->sql_statement); //Set DB::$sql_type to SELECT
        $query_object   = static::getInstance()->query(static::getInstance()->sql_statement);
        $this->initialize();

        return $query_object;
    }

    //Update a given row's dara
    public function update($array_vals){
        //$array_vals = self::set($array_vals);//static::translateSelectColumns($array_vals);
        static::getInstance()->sql_statement    = "UPDATE ".self::tableToSql(static::getInstance()->table).self::set($array_vals).((!isset(static::getInstance()->sql_where) || is_null(static::getInstance()->sql_where)) ? null : static::getInstance()->sql_where);
        static::sanitize(static::getInstance()->sql_statement);     //Get rid of any SQL Injections/escaped chars
        static::queryAnalyze(static::getInstance()->sql_statement); //Set DB::$sql_type to UPDATE

        $query_object   = static::getInstance()->query(static::getInstance()->sql_statement);
        $this->initialize();

        return $query_object;
    }
    
    //Insert values into a table
    public function addVal($array_vals){
        static::getInstance()->sql_statement    = "INSERT INTO `".static::getInstance()->table."` ".static::prepInsert($array_vals)." ".static::getInstance()->sql_where;
        return static::getInstance()->query();
    }

    //Insert new values for a a table (Backward compatibility)
    public function insert($data){
        static::getInstance()->sql_statement    = "INSERT INTO `".static::getInstance()->table."` ".static::prepInsert($data)." ".static::getInstance()->sql_where;
        return static::getInstance()->query();
    }

    //update a column by concatenation with a separator
    public function concat_ws($columns_array, $separator = ','){ return $this->concatWs($columns_array,$separator);}
    public function concatWs($columns_array, $separator = ',')
    {
        foreach($columns_array as $column => $value)
        {
            $concat[]		= "`$column` = CONCAT_WS('$separator',`$column`,'$value')";
        }

        $statement		= implode(',',$concat);

        return static::query("UPDATE ".static::getInstance()->table." SET ".$statement." ".static::getInstance()->sql_where)->qCatch(function(){
            $err = static::getInstance()->error;
            if(isset($err)){return $err;}
            return $this;
        });
    }

    //In case we want to call a DB::get(), but we don't want to run a query right away, in case we want to inject other values
    public function ret($fields){return $this->retrieve($fields);}
    public function retrieve($fields)
    {
        static::getInstance()->search_for_fields = $fields;
        return static::getInstance();
    }

    //This method prepares the SQL statement and applies all the set rules
    private function prepare()
    {
        $column     = static::translateSelectColumns(static::getInstance()->search_for_fields);
        $sql		= "SELECT $column FROM `{$this->table}`".(!isset($this->sql_where) || is_null($this->sql_where)?null:$this->sql_where).(!isset($this->sql_order_by) || is_null($this->sql_order_by)?null:$this->sql_order_by);
        static::getInstance()->sql_statement = $sql;

        return static::getInstance();
    }

    //Initialize the query's data to ready it for the next use
    private static function init_query(){return static::initQuery();}
    private static function initQuery(){
        static::getInstance()->error                = null;
        static::getInstance()->chaining_level       = null;
        static::getInstance()->prev_query_resource  = (isset(static::getInstance()->query_resource)) ? static::getInstance()->query_resource : null;
        static::getInstance()->sql_statement        = null;
        static::getInstance()->search_for_fields    = null;
        static::getInstance()->sql_where            = null;
        static::getInstance()->sql_order_by         = null;
    }

    //Sanitize and escape any potential escaped chars
    static function sanitize(&$sql) {
        static::sanitizeOrder($sql);
        static::sanitizeWhere($sql);
        static::getInstance()->connection->real_escape_string($sql);
    }

    /*
     * Non-DB related methods
     */

    //Extra routines, useful for some DB operations
    //explode an array, and link the keys & values with more than a delimiter
    static function linkArray($delimiter = '= ', $array, $var_type = null){return self::link_array($delimiter, $array, $var_type);}
    static function link_array($delimiter = '= ', $array, $var_type = null)
    {
        $link 	= NULL;
        $began	= FALSE;
        $glue   = ($var_type == SQL_STATEMENT) ? ' AND ' : ', ';
        //link the values by 'AND' or ',' by default
        foreach ($array as $key => $val)
        {
            $link 	.= (($began) ? $glue:NULL).((!self::isCompound($key)) ? self::varToSql($key) : self::compoundToSql($key)).(($val === NULL) ? " IS NULL" : (($val === !NULL) ? " IS NOT NULL" : $delimiter.((!self::isCompound($val) || (self::isCompound($val) && is_numeric($val))) ? "'".$val."'" : self::compoundToSql($val))));

            $began	= TRUE;
        }
        return $link;
    }
    private static function assign_glue($type){
        switch($type){
            case SQL_STATEMENT:
                return ' AND ';
            case SQL_UPDATE:
                return '';
        }
    }

    //Fill in an array
    static function fill_in_array($array){return static::fillInArray($array);}
    static function fillInArray($array){

    }

    //Debugging methods
    static function analyze()
    {
        $args = func_get_args();
        $args[0] = "<pre>" . $args[0] . "</pre>\n";

        for ($i = 1, $l = count($args); $i < $l; $i++)
        {
            $args[$i] = htmlspecialchars(var_export($args[$i], true));
        }
        call_user_func_array('printf', $args);
    }

    //An easier way of the analyze() function
    static function dissect($data)
    {
        return self::analyze('%s',$data);
    }

    /*
     * This is the second interface of the TKDB
     * Here starts the second use approach to the Framework
     */

    /*
     * @Method:     DB::use($tableName)
     * @Parameter:  $tableName is a string referring to the table to operate on
     */
    static function use($table_name)
    {
        static::getInstance()->interface    = INTERFACE_2;  //Enable interface 2 features
        static::getInstance()->table        = $table_name;  //Set the table to work on as Static
        static::getInstance()->chaining_level   = "getColumn";

        return static::getInstance();
    }

    //Start implementing the methods that use PHP reserved names
    public function __call($name, $arguments)
    {
        /*
         * If the method doesn't require applying changes to the original $variable passed as an argument, then the args should be clones and work on the clones only
         */
        if(static::getInstance()->interface === INTERFACE_2 && !is_null(static::getInstance()->chaining_level)){
            //switch the chaining level and call the suitable functionality
            switch(static::getInstance()->chaining_level){

                //Use: DB::use(table)->column(limit = null, where = null, callback = null)
                case 'getColumn':

                    //re-initialize the chaining level
                    static::getInstance()->interface = INTERFACE_1; //To make sure the next query will use INTERFACE_1 unless explicitly stated otherwise
                    static::getInstance()->chaining_level = null;   //Nothing is expected after a column is retrieved
                    //Let's get the targeted column name
                    $column     = $name;

                    //Let's parse the passed arguments
                    foreach ($arguments as $arg){

                        //Clone the $arg to avoid messing with the cases of WHERE and the Callback, since teh isRange() passes the arg by Reference and convert it to an acceptable Range Array
                        $argClone   = $arg;

                        //Check for setting the Limit
                        if(!is_callable($arg) && DB::isRange($argClone,false)) {
                            static::getInstance()->limit($argClone);
                        }

                        //Check for WHERE conditions
                        if(is_array($arg)){
                            static::getInstance()->condition($arg);
                        }

                        //Check for Order By
                        if(!is_array($arg) && !is_callable($arg) && !DB::isRange($arg) && (strpos($arg, " ") === false)){
                            static::getInstance()->ascOrder("$arg");
                        }

                        //Check for Callbacks. This can be used when the returned value has more than one result
                        if(is_callable($arg)){
                            $callBack   = $arg;
                        }
                    }

                    //If we pass a Callback, then let's run it here
                    //Also, if it's callable, we won't need to return a VALUE_CONTAINER object, since we'll process the data directly
                    //Keep in mind this returns an Upper Level Chaining Object
                    if(isset($callBack)){
                        return static::getInstance()->table(static::getInstance()->table)->get($column)->store($data)->operate($data, function() use ($data, $callBack, $column){
                            //Store the $data in a single dimension array
                            foreach ($data as $dimension){
                                $collector[]  = $dimension[$column];
                            }

                            return $callBack($collector);
                        });
                    } else {
                        static::getInstance()->table(static::getInstance()->table)->get($column)->store($data);
                        $obj = new VALUE_CONTAINER($data, $column);

                        return $obj;
                    }

                    break;

                /*
                 * Operations related to the DB structure
                 */
                case 'showTableColumns':

                    break;

                case 'cloneDB':
                    break;

                case 'showTables':

                    break;

                case 'showDBs':
                    break;
            }
        }
        else{
            echo "Called Method doesn't exist<br>name: $name.<br>Arguments: ".DB::dissect($arguments);
        }
    }
}

//This class is returned when a Linker is used for where
class LINKER{
    public $conjunction = null, //This could be AND | OR | NOT NULL...etc
        $operator = null,           //This could be = | > | < | >= ...etc
        $var;                       //The variable we're using for the comparison

    public function __construct($linker, $operator = null)
    {
        $this->conjunction  = (!is_null($linker)) ? $linker : null;
        $this->operator     = (!is_null($operator)) ? $operator : EQ;   //If we don't set the operator, it will assume it's an Equals
    }
}
//Comparison functions

//When the column and the compared to value are equals
function E($var, $linker = null){return similarTo($var, $linker);}          //E:Equals
function sameAs($var, $linker = null){return similarTo($var, $linker);}
function same($var, $linker = null){return similarTo($var, $linker);}
function similarTo($var, $linker = null){
    $Linker         = new LINKER($linker); //Capital L
    $Linker->var    = "'$var'";

    return $Linker;
}

//A simple !=
function NE($var, $linker = null){
    $Linker         = new LINKER($linker, " != "); //Capital L
    $Linker->var    = "'$var'";

    return $Linker;
}

//When the column is bigger than the compared value
function B($var, $linker = null){return biggerThan($var, $linker);}          //B:Bigger
function big($var, $linker = null){return biggerThan($var, $linker);}
function biggerThan($var, $linker = null){
    $Linker         = new LINKER($linker, " > "); //Capital L
    $Linker->var    = "'$var'";

    return $Linker;
}

//When the column is bigger or equal than the compared value
function BE($var, $linker = null){return biggerOrEqual($var, $linker);}      //BE:Bigger or Equals
function biggerOrEqual($var, $linker = null){
    $Linker         = new LINKER($linker, " >= "); //Capital L
    $Linker->var    = "'$var'";

    return $Linker;
}

function S($var, $linker = null){return smallerThan($var, $linker);}         //S:Smaller
function small($var, $linker = null){return smallerThan($var, $linker);}
function smallerThan($var, $linker = null){
    $Linker         = new LINKER($linker, " < "); //Capital L
    $Linker->var    = "'$var'";

    return $Linker;
}

function SE($var, $linker = null){return smallerOrEqual($var, $linker);}     //SE:Smaller or equals
function smallerOrEqual($var, $linker = null){
    $Linker         = new LINKER($linker, " <= "); //Capital L
    $Linker->var    = "'$var'";

    return $Linker;
}

//Not in a list/array
function NI($var, $linker = null){return notIn($var, $linker);}
function notIn($var, $linker = null){
    $Linker         = new LINKER($linker, " NOT IN ");
    $Linker->var    = "($var)";

    return $Linker;
}
?>
