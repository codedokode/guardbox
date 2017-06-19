<?php 

namespace Tests;

use Codebot\Util\DeferredWithThrow;
use Codebot\Util\PromiseUtil;
use Codebot\Util\PromiseWithThrow;
use React\EventLoop\StreamSelectLoop;
use React\Promise\Deferred;
use React\Promise\FulfilledPromise;
use React\Promise\RejectedPromise;
use Tests\Helper\FileHelper;
use Tests\Helper\TestContainer;
use Tests\Helper\TestPromiseUtil;

class PromiseUtilTest extends \PHPUnit\Framework\TestCase
{
    private $tmpDir;
    private $loop;

    public function setUp()
    {
        $this->tmpDir = FileHelper::createTmpDir();
        $this->loop = new StreamSelectLoop;
    }

    public function tearDown()
    {
        TestContainer::getTestFilesystem()->remove($this->tmpDir);   
        $this->loop = null;
    }

    public function testTestUtils()
    {
        $success = new FulfilledPromise(1);
        $error = new RejectedPromise(new \Exception('Test'));
        $incompleteDef = new Deferred;
        $incomplete = $incompleteDef->promise();

        $this->assertTrue(PromiseUtil::isCompleted($success));
        $this->assertTrue(PromiseUtil::isCompleted($error));
        $this->assertFalse(PromiseUtil::isCompleted($incomplete));

        $this->assertTrue(PromiseUtil::isResolved($success));
        $this->assertFalse(PromiseUtil::isResolved($error));
        $this->assertFalse(PromiseUtil::isResolved($incomplete));

        $this->assertTrue(PromiseUtil::isRejected($error));
        $this->assertFalse(PromiseUtil::isRejected($success));
        $this->assertFalse(PromiseUtil::isRejected($incomplete));

        $this->assertEquals(1, PromiseUtil::peekValue($success));
    }

    /**
     * @dataProvider getCasesForFirst
     */
    public function testFirst($expectResolve, $expectReject, callable $action)
    {
        $a = new Deferred;
        $b = new Deferred;

        $first = PromiseUtil::first([$a->promise(), $b->promise()]);
        $action($a, $b);

        if ($expectResolve !== null) {
            $this->assertSame($expectResolve, PromiseUtil::peekValue($first));
        }

        if ($expectReject !== null) {
            $this->assertSame($expectReject, PromiseUtil::getRejectReason($first));
        }
    }

    public function getCasesForFirst()
    {
        $rejectValue = new \Exception('test');

        return [
            'a is resolved' => [1, null, function ($a, $b) { $a->resolve(1); } ],
            'b is resolved' => [2, null, function ($a, $b) { $b->resolve(2); } ],
            'b is rejected' => [null, $rejectValue, function ($a, $b) use ($rejectValue) {
                $b->reject($rejectValue);
            }],
            'a and b are resolved' => [1, null, function ($a, $b) {
                $a->resolve(1);
                $b->resolve(2);
            }],
            'resolve + reject'  =>  [1, null, function ($a, $b) use ($rejectValue) {
                $a->resolve(1);
                $b->reject($rejectValue);
            }]
        ];
    }

    public function testAllCompleted()
    {
        $a = new Deferred();
        $b = new Deferred();
        $c = new Deferred();

        $all = PromiseUtil::allCompleted([$a->promise(), $b->promise(), $c->promise()]);

        $this->assertFalse(PromiseUtil::isCompleted($all));

        $a->resolve(1);
        $this->assertFalse(PromiseUtil::isCompleted($all));

        $b->reject(new \Exception('test'));
        $this->assertFalse(PromiseUtil::isCompleted($all));

        $c->resolve(2);
        $this->assertTrue(PromiseUtil::isResolved($all));
    }

    public function testWriteFileAsync()
    {
        $path = $this->tmpDir . '/test-write.txt';
        $contents = str_repeat("test file\n", 5000);

        $promise = PromiseUtil::writeFileAsync($path, $contents, $this->loop);
        TestPromiseUtil::waitForPromise($this->loop, 20, $promise);

        $this->assertTrue(PromiseUtil::isResolved($promise));
        $readContents = file_get_contents($path);
        $this->assertTrue($contents === $readContents);
    }

    public function testThrowIfRejected()
    {
        $p = new RejectedPromise(new \LogicException('Test'));        
        $this->setExpectedException('\LogicException');
        PromiseUtil::throwIfRejected($p);
    }

    public function testWrapperPromiseRejected()
    {
        $d = new Deferred;
        $p = $d->promise();
        $wrapped = PromiseWithThrow::wrap($p);

        $this->setExpectedException('Codebot\Util\RejectNotHandledException');
        $d->reject(new \LogicException("Test"));
    }
    
    public function testWrapperDeferredRejected()
    {
        $d = new DeferredWithThrow;
        $d->promise()->then(function () {});

        $this->setExpectedException('\Codebot\Util\RejectNotHandledException');
        $d->reject(new \LogicException("Test"));
    }

    public function testAllSuccess()
    {
        $a = new Deferred;
        $b = new Deferred;

        $all = PromiseUtil::allSuccess([$a->promise(), $b->promise()]);
        $a->resolve(1);
        $b->resolve(2);

        $this->assertEquals([1, 2], PromiseUtil::peekValue($all));

        $c = new Deferred;
        $d = new Deferred;
        $all2 = PromiseUtil::allSuccess([$c->promise(), $d->promise()]);
        $c->reject(new \LogicException('Test'));

        $this->assertTrue(PromiseUtil::isRejected($all2));
    }

    public function testRejectPropagation()
    {
        $d = new Deferred;
        $base = $d->promise();
        $p1 = $base->then(function ($v) { return $v; });

        PromiseUtil::debugPromise($base, '$base');
        PromiseUtil::debugPromise($p1, '$p1');

        $d->reject(new \LogicException("Test"));

        $this->assertTrue(PromiseUtil::isRejected($p1));
    }
}