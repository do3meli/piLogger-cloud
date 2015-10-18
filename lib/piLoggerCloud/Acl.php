<?php

namespace piLoggerCloud;

use Zend\Permissions\Acl\Acl as ZendAcl;

class Acl extends ZendAcl {
    
    public function __construct(){
        
        // APPLICATION ROLES
        $this->addRole('guest');
        // member role "extends" guest, meaning the member role will get all of 
        // the guest role permissions by default
        $this->addRole('member', 'guest');
        $this->addRole('admin');

        // APPLICATION RESOURCES
        // Application resources == Slim route patterns
        $this->addResource('/');
        $this->addResource('/login');
        $this->addResource('/logout');
        $this->addResource('/dashboards');
        $this->addResource('/dashboards/:id');
        $this->addResource('/graphs');
        $this->addResource('/graphs/:id');
        $this->addResource('/devices');
        $this->addResource('/devices/:id');
        $this->addResource('/sensors');
        $this->addResource('/sensors/:id');
        $this->addResource('/users');
        $this->addResource('/users/:username');
        $this->addResource('/register');

        // APPLICATION PERMISSIONS
        // Now we allow or deny a role's access to resources. The third argument
        // is 'privilege'. We're using HTTP method as 'privilege'.
        $this->allow('guest', '/', 'GET');
        $this->allow('guest', '/login', array('GET', 'POST'));
        $this->allow('guest', '/logout', 'GET');
        $this->allow('guest', '/dashboards', 'GET');
        $this->allow('guest', '/dashboards/:id', 'GET');
        $this->allow('guest', '/graphs', 'GET');
        $this->allow('guest', '/graphs/:id', 'GET');
        $this->allow('guest', '/devices', 'GET');
        $this->allow('guest', '/devices/:id', 'GET');
        $this->allow('guest', '/sensors', 'GET');
        $this->allow('guest', '/sensors/:id', 'GET');
        $this->allow('guest', '/users', 'GET');
        $this->allow('guest', '/users/:username', 'GET');
        $this->allow('guest', '/register', array('GET', 'POST'));

        // This allows admin access to everything
        $this->allow('admin');
    }
}

?>
