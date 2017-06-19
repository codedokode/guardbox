<?php

namespace Codebot\Worker;

use Codebot\LoggerTrait;
use React\EventLoop\LoopInterface;

class WorkerSupervisorPool
{
    use LoggerTrait;

    private $maxWorkers = 5;

    /** @var Supervisor[] */
    private $workers = [];

    private $usedSlots = [];

    /** @var LoopInterface */
    private $loop;

    public function __construct(LoopInterface $loop)
    {
        $this->loop = $loop;   
    }

    public function start()
    {
        $this->createWorkersIfNeeded();
    }    

    private function createWorkersIfNeeded()
    {
        if (count($this->workers) >= $this->maxWorkers) {
            return;
        }

        $needWorkers = $this->maxWorkers - count($this->workers);
        $this->getLogger()->info('Have {running} workers, going to start {needWorkers}', [
            'running'       =>  count($this->workers),
            'needWorkers'   =>  $needWorkers
        ]);

        for ($i=0; $i < $needWorkers; $i++) { 
            $slot = $this->getFreeSlot();
            if (!$slot) {
                throw new \Exception("Failed to get a free slot");
            }

            $worker = new Supervisor($this->loop, $slot);
            $worker->setLogger($this->getLogger());
            $worker->getProgressPromise()->done(function () use ($worker) {
                $this->removeFinishedWorker($worker);
            }, function ($error) {
                // Make a pause before starting new one
                $this->loop->addTimer($this->config->pauseOnFailure, function () use ($worker, $error) {
                    $this->removeFinishedWorker($worker, $error);    
                })                
            });

            $this->workers[] = $worker;
            $this->usedSlots[$slot] = true;
            $this->getLogger()->info('Started worker on slot {slot}', ['slot' => $slot]);
            $worker->start();  

        }
    }

    private function getFreeSlot()
    {
        for ($i=1; $i <= $this->maxWorkers; $i++) { 
            if (!array_key_exists($i, $this->usedSlots)) {
                return $i;
            }
        }

        return null;
    }

    private function removeFinishedWorker(Worker $worker, $error = null)
    {
        assert($worker->isFinished());

        // Remove from list 
        $key = array_search($worker, $this->workers, true);
        if (false === $key) {
            throw new \Exception(
                "Terminated worker on slot {$worker->getSlot()} was not found in the pool");
        }

        unset($this->workers[$key]);

        // Free worker's slot 
        $slot = $worker->getSlot();
        assert(array_key_exists($slot, $this->usedSlots));
        unset($this->usedSlots[$slot]);

        $this->loop->nextTick(function () {
            $this->createWorkersIfNeeded();
        });
    }

    public function findReadyWorker()
    {
        foreach ($this->workers as $worker) {
            if ($worker->isReady()) {
                return $worker;
            }
        }
    }
}