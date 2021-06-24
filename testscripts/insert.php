<?php
ini_set('display_errors', true);
error_reporting(E_ALL);

use Resqu\Client\JobDescriptor;

require_once __DIR__ . '/../scripts/bootstrap.php';
require_once 'testjobs.php';


class Descriptor extends JobDescriptor {

    private $args;
    private $class;

    public function __construct($class, $args) {
        $this->args = $args;
        $this->class = $class;
    }

    public function getArgs() {
        return $this->args;
    }

    public function getClass() {
        return $this->class;
    }

    public function getSourceId() {
        return 'test';
    }

    public function getName() {
        return $this->class;
    }

    public function getUid() {
        return new \Resqu\Client\JobUid('whatever', 0);
    }
}

foreach ([1, 2, 3] as $i) {
    \Resqu\Client::enqueue(new Descriptor(__SleepJob::class, [
        'sleep' => 10,
        'message' => "Test message$i"
    ]));
    sleep(3);
}
