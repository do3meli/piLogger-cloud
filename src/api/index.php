<?php

//***************************************************************
// Setup: Load Libraries and Create DB Objects
//***************************************************************
	
// require all composer libraries
require '../../vendor/autoload.php';

// parse the config file
$config = parse_ini_file("../../config/config.ini");

// create the cassandra database
$cassandradb   = Cassandra::cluster()
                            ->withContactPoints(implode(', ',$config['contactpoint']))
                            ->withPort((int)$config['portnumb'])
                            ->build()
                            ->connect($config['keyspace']);		

// create mysql connection
$mysqldb = new PDO('mysql:dbname='.$config['database'].';host='.$config['hostname'].';charset=utf8', $config['username'] , $config['password']);

// tells PDO to disable emulated prepared statements and use real prepared statements
$mysqldb->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

// create the picloud handler and set the db's
$picloud = new piLoggerCloud\piCloudHandler();
$picloud->setCassandraConnection($cassandradb);
$picloud->setMysqlConnection($mysqldb);

// create monolog handler array
$monologHandlers = array();

// add monolog slack handler if the config has been enabled 
if(!empty($config['slack-enable'])){
    $slackHandler = new \Monolog\Handler\SlackHandler($config['slack-token'], $config['slack-channel'], 'piLogger-cloud:'.$config['env']);
    $slackHandler->setLevel(\Monolog\Logger::INFO);
    array_push($monologHandlers, $slackHandler);
}

// create cassandra monolog handler and add it to the handler stack
$cassandraHandler = new \CassandraHandler\CassandraHandler($cassandradb);
array_push($monologHandlers, $cassandraHandler);

// integrate monolog into Slim via SlimMonolog package
$logger = new \Flynsarmy\SlimMonolog\Log\MonologWriter(array(
    'name' => 'api',
    'handlers' => $monologHandlers
));


//***************************************************************
// Slim Framework: Setup
//***************************************************************

// create new slim app and pass picloud object
$app = new \Slim\Slim(array(
    'picloud' => $picloud,
    'log.writer' => $logger,
    'log.level' => \Slim\Log::INFO,
));

// using json middleware
$app->add(new \SlimJson\Middleware());

//***************************************************************
// PUT route for /api/sensor/:id
//***************************************************************

// HTTP PUT route for saving new data points
$app->put('/sensor/:id', function ($id) use ($app) {
	
	//parse the request body as json object
	$json = $app->request()->getBody();
	$data = json_decode($json, true);
	
	// check if sensor is authenticated to store a new value
	if( $app->config('picloud')->isSensorAuthenticated($id,$data['authToken'])){
		
		// save the new data piont
		$app->config('picloud')->saveNewDataPoint($id,strtotime($data['probeTime']),$data['probeValue']);
		
		// return HTTP 200
		$app->render(200);
		
	}else{
		
		// sensor is not authenticated - send HTTP 403 message
		$app->render(403,array(
         'error' => TRUE,
         'msg'   => 'this sensor is not allowed to store any new data points with the given authToken',
        ));
	}
});

//***************************************************************
// POST route for /api/sensor/:id
//***************************************************************
$app->post('/sensor/:id', function ($id) use ($app) {

   //parse the request body as json object
	$json = $app->request()->getBody();
	$data = json_decode($json, true);
	
	// create new sensor and save return value
	$returnValue = $app->config('picloud')->createNewSensor($id, $data['deviceIdentifier'], $data['sensorName'], $data['sensorType'], $data['authToken']);

	// if return is true then everything was successfull, 
	// otherwise send an HTTP error code
	if($returnValue){
        $app->log->info('successfully created new sensor with ID '.$id);
        $app->render(201);
	}else{
        $app->log->error('could not create new sensor with ID '.$id);
        $app->render(500, array(
            'error' => TRUE,
            'msg'   => 'could not generate a new sensor...',
        ));
	}
});

//***************************************************************
// POST route for /api/device/:id
//***************************************************************
$app->post('/device/:id', function ($id) use ($app) {

   //parse the request body as json object
	$json = $app->request()->getBody();
	$data = json_decode($json, true);
	
	// create new sensor and save return value
	$returnValue = $app->config('picloud')->createNewDevice($data['deviceName'], $id, $data['authToken']);

	// if return is true then everything was successfull, 
	// otherwise send an HTTP error code
	if($returnValue){
        $app->log->info('successfully created new device with name '.$data['deviceName']);
        $app->render(201);
	}else{
    	$app->log->error('could not create new device with name '.$data['deviceName']);
    	$app->render(500, array(
            'error' => TRUE,
            'msg'   => 'could not generate a new device...',
        ));
	}
});

//***************************************************************
// GET route for /api/sensor/:id/:year
//***************************************************************

$app->get('/sensor/:id/:year', function ($id, $year) use ($app) {
	$app->render(200,$app->config('picloud')->getDataBySensorYear($id,$year));
});

//***************************************************************
// GET route for /api/sensor/:id/:year/:month
//***************************************************************

$app->get('/sensor/:id/:year/:month', function ($id, $year, $month) use ($app) {
	$app->render(200,$app->config('picloud')->getDataBySensorMonth($id,$year,$month));
});

//***************************************************************
// GET route for /api/sensor/:id/:year/:month/:day
//***************************************************************

$app->get('/sensor/:id/:year/:month/:day', function ($id, $year, $month, $day) use ($app) {
	$app->render(200,$app->config('picloud')->getDataBySensorDay($id,$year,$month, $day));
});

//***************************************************************
// GET route for /api/plotdata/:name
//***************************************************************

$app->get('/plotdata/:name', function ($name) use ($app) {
    $app->render(200,$app->config('picloud')->getDataByGraphName($name));
});	


// finally lets run the SLIM app
$app->run();


?>
