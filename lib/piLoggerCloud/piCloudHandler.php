<?php 

namespace piLoggerCloud;

class piCloudHandler {
    
   // class variables holding the mysql and the cassandra connection
   protected $mysqlConnection;
   protected $cassandraConnection;
    
   // empty constructor
   function __construct() { }
    
   // setter function for mysql connection
   function setMysqlConnection($mysqlConnection){
      $this->mysqlConnection = $mysqlConnection;
   }
    
   // setter function for cassandra connection
   function setCassandraConnection($cassandraConnection){
      $this->cassandraConnection = $cassandraConnection;
   }
    
     
   // save a new data point to the cassandra database
   function saveNewDataPoint($sensorId,$probeTime, $probeValue){
	   	    
      // prepare values for SQL insert
      $value = new \Cassandra\Float($probeValue);
      $timestamp = new \Cassandra\Timestamp($probeTime);
      $day = date('Y-m-d',$probeTime);
	   	
      // execute the statement with above values    
      $this->cassandraConnection->execute(
         new \Cassandra\SimpleStatement("INSERT INTO sensordata (sensor_id,day,probe_time,probe_value) VALUES (?,?,?,?)"),
         new \Cassandra\ExecutionOptions(array('arguments' => array($sensorId,$day,$timestamp,$value )))
         );   
   }
   
    
   // check if a given sensor is authenticated by verifying the given token
   function isSensorAuthenticated($sensorId, $authToken){
	    
      // prepare SQL statement
      $stmt = $this->mysqlConnection->prepare('select 1 from sensor s
                                                join device d on (d.did = s.attached) 
                                                join user u on (d.owner = u.uid)
                                                where u.authtoken = :token and s.identifier = :sensor ');
		
      // bind variables and execute										
      $stmt->execute(array(':token' => $authToken, ':sensor' => $sensorId ));										
		
      // if we got a mysql row back we can return true
      if ($stmt->rowCount() == 1){
         return true;
      }
		
      // if we come up to this point we have to return false
      return false;
   }
    
   
   // function that creates a new sensor based on the arguments
   function createNewSensor($sensorId, $devIdent, $devName, $devType, $authToken){
      
      // prepare SQL statement
      $stmt = $this->mysqlConnection->prepare('insert into sensor (name, type, identifier, attached)
                                                   select :name, :type, :ident, device.did 
                                                   from device 
                                                   join user on (device.owner = user.uid)
                                                   where device.identifier = :devident and user.authToken = :token');
      // bind variables and execute										
      $result = $stmt->execute(array(':token' => $authToken, ':name' => $devName, ':type' => $devType, ':ident' => $sensorId,':devident' => $devIdent ));
      
      // if execution was successfull we return true
      if($result){
         return true;
      }	
     
      // otherwise return false
      return false;
   }
   
   
   // function that creates a new device based on the arguments
   function createNewDevice($devName, $devIdent, $authToken){
      
      // prepare SQL statement
      $stmt = $this->mysqlConnection->prepare('insert into device (name, identifier, owner) 
                                                select :name, :identifier, uid from user where authToken = :token');
                                                      
      // bind variables and execute
      $result = $stmt->execute(array(':token' => $authToken, ':name' => $devName, ':identifier' => $devIdent ));
      
      // if execution was successfull we return true								
      if($result){
         return true;
      }
      
      // otherwise return false
      return false;
   }
   
   

   
    
   // get data for sensorid and year
   function getDataBySensorYear($sensorId, $year){
	    
      $start = \Carbon\Carbon::create($year)->startOfYear();
      $end = \Carbon\Carbon::create($year)->endOfYear();

      // call function to generate json and return array back to user
      return $this->getJSONDataPointsAsync($sensorId, $start, $end);
   }
   
   // get data for sensorid and month
   function getDataBySensorMonth($sensorId, $year, $month){
	    
      // define start and end of the time window
      $start = \Carbon\Carbon::create($year,$month)->startOfMonth();
      $end = \Carbon\Carbon::create($year,$month)->endOfMonth();

      // call function to generate json and return array back to user
      return $this->getJSONDataPointsAsync($sensorId, $start, $end);
   }

   // get data for sensorid and specific day
   function getDataBySensorDay($sensorId, $year, $month, $day){
	   
	   // define start and end of the time window 
      $start = \Carbon\Carbon::create($year,$month,$day)->startOfDay();
      $end = \Carbon\Carbon::create($year,$month,$day)->endOfDay();
      
      // call function to generate json and return array back to user
      return $this->getJSONDataPointsAsync($sensorId, $start, $end);
   }

   
   
   // this function queries cassandra DB and returns a json
   function getJSONDataPointsAsync($sensorId, $starttime, $endtime){
		
	$data = array();	
      // prepare the SQL statement
      $statement = $this->cassandraConnection->prepare("SELECT probe_time, probe_value FROM sensordata WHERE sensor_id = ? and day = ? and probe_time >= ? and probe_time <= ?");
		
      // save original endtime  
      $original_endtime = clone $endtime;  
		
      // loop as long as starttime is lower than endtime  
      while($starttime->lt($original_endtime)){
         
         // if the endtime is on the same day as the new starttime we use the original time
         if( $original_endtime->isSameDay($starttime) ){
            $endtime = $original_endtime;
         }else{
            $endtime = clone $starttime;
            $endtime = $endtime->endOfDay();
         }
         
         
         // prepare values for cassandra execution option object
         $curday = $starttime->toDateString(); 
         $startvalue = new \Cassandra\Timestamp(strtotime($starttime->toDateTimeString()));
         $endvalue = new \Cassandra\Timestamp(strtotime($endtime->toDateTimeString()));
         
         // execute statement async and with exec option and hold result in array
         $futures[]= $this->cassandraConnection->executeAsync($statement, new \Cassandra\ExecutionOptions(array('arguments' => array($sensorId, $curday, $startvalue, $endvalue))));
         
         // set new start time
         $starttime = $starttime->addDay()->startOfDay();
      }
      
      // wait for all statements to complete
      foreach ($futures as $future) {
         
         // we will not wait for each result for more than 5 seconds
         $result = $future->get(5);
         
         // we loop over each result we get and store it in a data array
         foreach ($result as $row){
            // we need time in miliseconds and the value with 3 decimal precision
            $data[] = [$row['probe_time']->time() * 1000 , round($row['probe_value']->value(),3)];
         }
      }
	
      return array('sensor' => $this->getSensorNameById($sensorId),$this->getSensorTypeById($sensorId) => $data );

   }
		   
	      
   // get a sensor name by a given sensor id 
   function getSensorNameById($sensorId){
       
      // prepare SQL statement
      $stmt = $this->mysqlConnection->prepare('select name from sensor where identifier = :sensor ');
		
      // bind variables and execute										
      $stmt->execute(array(':sensor' => $sensorId ));										
		
      // get the result
      $row = $stmt->fetch();
      
      return $row[0]; 
   }
    
    
   // get the sensor type by a given sensor id 
   function getSensorTypeById($sensorId){
       
      // prepare SQL statement
      $stmt = $this->mysqlConnection->prepare('select type from sensor where identifier = :sensor ');
		
      // bind variables and execute										
      $stmt->execute(array(':sensor' => $sensorId ));										
		
      // get the result
      $row = $stmt->fetch();

      return $row[0];   
   }
   
   
    // get all data from all sensor for the plot that has the given name
   function getDataByGraphName($graphName){
	    
	   // prepare SQL statement
      $stmt = $this->mysqlConnection->prepare('select s.identifier, g.dataSinceDays from sensor s 
                                                   JOIN sensor2graph s2g on (s.sid = s2g.sensor)
                                                   JOIN graph g on (s2g.graph = g.gid)
                                                   where g.name = :name ');
      
      // bind variables and execute										
      $stmt->execute(array(':name' => $graphName ));										
		
		// create data array to hold results
		$data = array();
		       
      // loop over every returned row
      foreach ($stmt as $row) {		 
                  
         // the endtime for the query is now
         $endtime = \Carbon\Carbon::now();
         
         // the starttime for the query is the endtime minus the dataSinceDays from DB
         $starttime = clone $endtime;
         $starttime = $starttime->subDay($row[1]); 
         
         array_push($data, $this->getJSONDataPointsAsync($row[0], $starttime, $endtime));
         
      }
      return $data;    
   }
   
  
  
   // function to get all users from mysql database
   function getAllUsers(){
     
      // prepare SQL statement, execute it, fetch results into an array and return that
      $stmt = $this->mysqlConnection->prepare('SELECT u.username, 
                                                   (SELECT COUNT(*) FROM device d WHERE d.owner=u.uid) AS deviceCount,
                                                   (SELECT COUNT(*) FROM sensor s join device d on (s.attached = d.did) WHERE d.owner=u.uid) AS sensorCount,
                                                   (SELECT COUNT(*) FROM cockpitview v where v.owner=u.uid) AS viewCount,
                                                   (SELECT COUNT(*) FROM graph g WHERE g.owner=u.uid) AS graphCount
                                                FROM user AS u');
      $stmt->execute();
      $result = $stmt->fetchAll();	
      return $result;
     
   }
  
   // function to get all sensors from mysql database
   function getAllSensors(){
     
      // prepare SQL statement, execute it, fetch results into an array and return that
      $stmt = $this->mysqlConnection->prepare('select s.sid,s.name,s.type,s.identifier,d.name as deviceName, d.identifier as devIdent 
                                                from sensor s join device d on (s.attached = d.did)');
      $stmt->execute();
      $result = $stmt->fetchAll();	
      return $result;
   }
  
   // function to get all devices from mysql database
   function getAllDevices(){
     
      // prepare SQL statement, execute it, fetch results into an array and return that
      $stmt = $this->mysqlConnection->prepare('SELECT d.did, d.name, d.identifier, u.username
                                                FROM device d JOIN user u on (d.owner = u.uid)');
      $stmt->execute();
      $result = $stmt->fetchAll();	
      return $result;
   }  
   
   // function to get all graphs from mysql database
   function getAllGraphs(){
     
      // prepare SQL statement, execute it, fetch results into an array and return that
      $stmt = $this->mysqlConnection->prepare('SELECT gid, name, dataSinceDays FROM graph');
      $stmt->execute();
      $result = $stmt->fetchAll();	
      return $result;
   }  
   
   // function to get all views from mysql database
   function getAllViews(){
     
      // prepare SQL statement, execute it, fetch results into an array and return that
      $stmt = $this->mysqlConnection->prepare('SELECT v.name, u.username, 
                                                   (SELECT count(*) from graph2view g2v where g2v.view=v.cvid) as assignedGraphs
                                                FROM cockpitview v
                                                JOIN user u on (v.owner = u.uid)');
      $stmt->execute();
      $result = $stmt->fetchAll();	
      return $result;
   }  
  
   // get detailed user information
   function getUserInfo($username){
      
      // prepare SQL statement, execute it, fetch results into an array and return that
      $stmt = $this->mysqlConnection->prepare('SELECT u.username,
                                                   (SELECT COUNT(*) FROM device d WHERE d.owner=u.uid) AS deviceCount,
                                                   (SELECT COUNT(*) FROM sensor s join device d on (s.attached = d.did) WHERE d.owner=u.uid) AS sensorCount,
                                                   (SELECT COUNT(*) FROM cockpitview v where v.owner=u.uid) AS viewCount,
                                                   (SELECT COUNT(*) FROM graph g WHERE g.owner=u.uid) AS graphCount
                                                FROM user u
                                                WHERE u.username = :username');
      $stmt->execute(array(':username' => $username ));
      $result = $stmt->fetch();	
      return $result;
   }
   
   // get detailed device information
   function getDeviceInfo($deviceId){
      
      // prepare SQL statement, execute it, fetch results into an array and return that
      $stmt = $this->mysqlConnection->prepare('SELECT d.name, d.identifier,u.username
                                                   FROM device d
                                                   JOIN user u on (d.owner = u.uid)
                                                   WHERE d.identifier = :deviceId');
      $stmt->execute(array(':deviceId' => $deviceId ));
      $result = $stmt->fetch();	
      return $result;
   }
   
   // get detailed sensor information
   function getSensorInfo($sensorId){
      
      // prepare SQL statement, execute it, fetch results into an array and return that
      $stmt = $this->mysqlConnection->prepare('SELECT s.name, s.type, s.identifier, u.username, d.name as deviceName, d.identifier as deviceIdent
                                                FROM sensor s 
                                                join device d on (d.did = s.attached) 
                                                join user u on (d.owner = u.uid)
                                                WHERE s.identifier = :sensorId');
      $stmt->execute(array(':sensorId' => $sensorId ));
      $result = $stmt->fetch();	
      return $result;
   }
   
   // get detailed graph information
   function getGraphInfo($graphId){
      
      // prepare SQL statement, execute it, fetch results into an array and return that
      $stmt = $this->mysqlConnection->prepare('SELECT name, dataSinceDays*24 as since from graph where gid = :graphId');
      $stmt->execute(array(':graphId' => $graphId ));
      $result = $stmt->fetch();	
      return $result;
   }
   
   // get detailed dashboard information
   function getDashboardInfo($dashboardName){
      
      // prepare SQL statement, execute it, fetch results into an array and return that
      $stmt = $this->mysqlConnection->prepare('SELECT g.name, g.dataSinceDays*24 as sinceHours 
                                                FROM cockpitview v 
                                                JOIN graph2view g2v ON (g2v.view = v.cvid) 
                                                JOIN graph g ON (g2v.graph = g.gid) 
                                                WHERE v.name = :viewName');
                                                
      $stmt->execute(array(':viewName' => $dashboardName ));
      $result = $stmt->fetchAll();	
      return $result;
   }
   
   // get all connected sensors for a specific device
   function getSensorsForDevice($deviceId){
   
      // prepare SQL statement, execute it, fetch results into an array and return that
      $stmt = $this->mysqlConnection->prepare('SELECT s.identifier, s.name, s.type FROM sensor s
                                                   JOIN device d on ( s.attached = d.did)
                                                   WHERE d.identifier = :device ');
      $stmt->execute(array(':device' => $deviceId ));
      $result = $stmt->fetchAll();	
      return $result; 
   }
   
   // get all graphs containing information from a specific sensors
   function getGraphsForSensor($sensorId){
   
      // prepare SQL statement, execute it, fetch results into an array and return that
      $stmt = $this->mysqlConnection->prepare('SELECT g.gid, g.name, g.dataSinceDays*24 as sinceDays FROM graph g
                                                   JOIN sensor2graph s2g on (s2g.graph = g.gid) 
                                                   JOIN sensor s on (s2g.sensor = s.sid)
                                                   WHERE s.identifier = :sensorId ');
      $stmt->execute(array(':sensorId' => $sensorId ));
      $result = $stmt->fetchAll();	
      return $result; 
   }

   // get detailed sensor information for a specific graph
   function getSensorsForGraph($graphId){
      
      // prepare SQL statement, execute it, fetch results into an array and return that
      $stmt = $this->mysqlConnection->prepare('SELECT s.identifier as sid, s.name as sensorName, d.identifier as did, d.name as deviceName
                                                   FROM sensor s join sensor2graph s2g on (s.sid = s2g.sensor) 
                                                   JOIN device d on (d.did = s.attached) 
                                                   WHERE s2g.graph = :graphID ');
      $stmt->execute(array(':graphID' => $graphId ));
      $result = $stmt->fetchAll();	
      return $result;
      
   }

   
   // save new user into the database
   function createNewUser($username,$password){

      $pwdhash = password_hash($password, PASSWORD_DEFAULT);
      $md5user = md5($username);

      // prepare SQL statement
      $stmt = $this->mysqlConnection->prepare('INSERT INTO user ( username, authtoken, role, password )
                                                   VALUES ( :username, md5(:authtoken), :role, :password )');

      // execute the insert statement
      $stmt->execute(array(':username' => $username, 
                           ':password' => $pwdhash, 
                           ':role' => 'member', 
                           ':authtoken' => $md5user));

      // return true if we come up here
      return $stmt; 
   }
   
   // function to get all graphs from mysql database for a specific user
   function getAllSensorsForUser($username){
     
      // prepare SQL statement
      $stmt = $this->mysqlConnection->prepare('SELECT s.sid, s.name, d.name as devName from sensor s 
                                                join device d on (s.attached = d.did) 
                                                join user u on (d.owner = u.uid) 
                                                where u.username = :user');
     
      // execute the select statmenet and bind variables
      $stmt->execute(array(':user' => $username));
      
      // fetch all rows and return an array
      $result = $stmt->fetchAll();	
      return $result;
   }  
   
   // function to create a new graph for a user
   function createNewGraph($username, $graphname, $timeframe, $sensors){
       
      // prepare SQL statement
      $stmt = $this->mysqlConnection->prepare('INSERT INTO graph (name,dataSinceDays,owner) SELECT :name, :time, uid FROM user WHERE username = :user');
                    
      // execute the select statmenet and bind variables
      $stmt->execute(array(':name' => $graphname, ':time' => $timeframe, ':user' => $username));
      
      // get the last inserted id
      $id = $this->mysqlConnection->lastInsertId();
      
      // only go ahead if that is a number higher than 0
      if($id > 0){
        
            // prepare query for sensor inserts
            $stmt = $this->mysqlConnection->prepare('INSERT INTO sensor2graph ( sensor, graph ) VALUES ( :sensor, :graph )');
      
            // loop over all sensors
            foreach($sensors as $sensor){
                $stmt->execute(array(':sensor' => $sensor, ':graph' => $id));
            }
            
            return true;

      }else{
          // something went wrong return false
          return false;
      } 
         
   }

 
}

?>
