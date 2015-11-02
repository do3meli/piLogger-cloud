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
$mysqldb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);

// create the picloud handler and set the db's
$picloud = new piLoggerCloud\piCloudHandler();
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
    'name' => 'www',
    'handlers' => $monologHandlers
));


//***************************************************************
// Slim Framework: Setup
//***************************************************************

// create new slim app object
$app = new \Slim\Slim(array(
   'templates.path' => '../../templates',
   'picloud' => $picloud,
   'log.writer' => $logger,
   'log.level' => \Slim\Log::INFO,
));

// define the engine used for the view
$app->view(new \Slim\Views\Twig());

// configure Twig template engine
$app->view->parserOptions = array(
   'charset' => 'utf-8',
   'cache' => realpath('../../templates/cache'),
   'auto_reload' => true,
   'strict_variables' => false,
   'autoescape' => true
);

// add parser Extenstion to Twig
$app->view->parserExtensions = array(new \Slim\Views\TwigExtension());

// setup slim-auth components
$validator = new \JeremyKendall\Password\PasswordValidator();
$adapter = new \JeremyKendall\Slim\Auth\Adapter\Db\PdoAdapter($mysqldb, 'user', 'username', 'password', $validator);
$acl = new piLoggerCloud\Acl();

// configure Zend sessions
$sessionConfig = new \Zend\Session\Config\SessionConfig();
$sessionConfig->setOptions(array(
    'remember_me_seconds' => 60 * 60 * 24 * 7,
    'name' => 'piLogger-net',
));

// setup session manager
$sessionManager = new \Zend\Session\SessionManager($sessionConfig);
$sessionManager->rememberMe();

// bootstrap the slim-auth component with session storage
$storage = new \Zend\Authentication\Storage\Session(null, null, $sessionManager);
$authBootstrap = new \JeremyKendall\Slim\Auth\Bootstrap($app, $adapter, $acl);
$authBootstrap->setStorage($storage);
$authBootstrap->bootstrap();

// define hook that runs before every view is rendered
$app->hook('slim.before.dispatch', function () use ($app) {
    $hasIdentity = $app->auth->hasIdentity();
    $identity = $app->auth->getIdentity();
    $role = ($hasIdentity) ? $identity['role'] : 'guest';
    $memberClass = ($role == 'guest') ? 'danger' : 'success';
    $adminClass = ($role != 'admin') ? 'danger' : 'success';
    $data = array(
        'hasIdentity' => $hasIdentity,
        'role' =>  $role,
        'identity' => $identity,
        'memberClass' => $memberClass,
        'adminClass' => $adminClass,
    );
    $app->view->appendData($data);
});

//***************************************************************
// GET route
//***************************************************************
$app->get('/', function () use ($app) {
    $app->render('index.html');
});

$app->get('/dashboards', function () use ($app) {
    $app->render('dashboards.html',['dashboards' => $app->config('picloud')->getAllViews()]);
});

$app->get('/dashboards/:id', function ($id) use ($app) {
   $app->render('dashboards_detail.html',[ 
      'graphs' => $app->config('picloud')->getDashboardInfo($id),
      'dashboard' => $id
   ]);
});

$app->map('/graphs/new', function () use ($app) {
    
    $username = $app->auth->getIdentity()['username'];
    
    // if a post has been submitted call the db insert function
    if ($app->request()->isPost()) {
       
        // get post values
        $graphname = $app->request->post('graphname');
        $timeframe = $app->request->post('timeframe');
        $sensors = $app->request->post('sensors');
        
        // save new graph and keep return value
        $result = $app->config('picloud')->createNewGraph($username, $graphname, $timeframe, $sensors);
        
        if($result){
            $app->flashNow('success', 'Graph successfully created');
            $app->log->info('successfully created new graph "'.$graphname.'" for '.$username);
        }else{
            $app->flashNow('error', 'Error - Could not create your graph'); 
            $app->log->error('failed to created new graph "'.$graphname.'" for '.$username);
        }        
    }
    
    // render the page
    $app->render('graphs_add.html',['usergraphs' => $app->config('picloud')->getAllSensorsForUser($username)]);
    
})->via('GET', 'POST');

$app->get('/graphs', function () use ($app) {
    $app->render('graphs.html',['graphs' => $app->config('picloud')->getAllGraphs()]);
});

$app->get('/graphs/:id', function ($id) use ($app) {
    $app->render('graphs_detail.html',[
         'graph' => $app->config('picloud')->getGraphInfo($id),
         'infos' => $app->config('picloud')->getSensorsForGraph($id)
   ]);
});

$app->get('/devices', function () use ($app) {
    $app->render('devices.html',['devices' => $app->config('picloud')->getAllDevices()]);
});

$app->get('/devices/:id', function ($id) use ($app) {
    $app->render('devices_detail.html',[
         'device' => $app->config('picloud')->getDeviceInfo($id),
         'sensor' => $app->config('picloud')->getSensorsForDevice($id)
    ]);
});

$app->get('/sensors', function () use ($app) {
    $app->render('sensors.html',['sensors' => $app->config('picloud')->getAllSensors()]);
});

$app->get('/sensors/:id', function ($id) use ($app) {
    $app->render('sensors_detail.html',[
         'sensor' => $app->config('picloud')->getSensorInfo($id),
         'graphs' => $app->config('picloud')->getGraphsForSensor($id)        
    ]);
});

$app->get('/users', function () use ($app) {
    $app->render('users.html',['users' => $app->config('picloud')->getAllUsers()]);
});

$app->get('/users/:username', function ($username) use ($app) {
    $app->render('users_detail.html',['user' => $app->config('picloud')->getUserInfo($username)]);
});

$app->get('/logout', function () use ($app) {
    if ($app->auth->hasIdentity()) {
        $app->auth->clearIdentity();
    }
    $app->redirect('/');
});

$app->map('/login', function () use ($app) {
    $username = null;
    if ($app->request()->isPost()) {
        $username = $app->request->post('username');
        $password = $app->request->post('password');
        $result = $app->authenticator->authenticate($username, $password);
        if ($result->isValid()) {
            $app->log->info('user '.$username.' successfully logged in');
            $app->redirect('/');
        } else {
            $messages = $result->getMessages();
            $app->flashNow('error', $messages[0]);
            $app->log->info('user '.$username.' failed to log in');
        }
    }
    $app->render('login.html', array('username' => $username));
})->via('GET', 'POST')->name('login');

$app->map('/register', function () use ($app) {
    if ($app->request()->isPost()) {
       $username = $app->request->post('username');
       $password = $app->request->post('password');
       $result = $app->config('picloud')->createNewUser($username,$password);
       if($result){
         $app->flashNow('success', 'Account successfully created');
         $app->log->info('successfully created new account for '.$username);
       }else{
         $app->flashNow('error', 'Error - Could not create your account'); 
         $app->log->error('failed to register a new account');
       }
    }
    $app->render('register.html');
})->via('GET', 'POST')->name('register');


$app->run();
?>
