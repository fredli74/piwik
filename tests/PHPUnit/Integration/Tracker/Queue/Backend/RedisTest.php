<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Tests\Integration\Tracker;

use Piwik\Tracker\Queue\Backend\Redis;
use Piwik\Translate;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group Redis
 * @group RedisTest
 * @group Queue
 * @group Tracker
 */
class RedisTest extends IntegrationTestCase
{
    /**
     * @var Redis
     */
    private $redis;
    private $emptyListKey = 'testMyEmptyListTestKey';
    private $listKey = 'testMyListTestKey';
    private $key = 'testKeyValueKey';

    public function setUp()
    {
        parent::setUp();

        Redis::enableTestMode();
        $this->redis = new Redis();

        if (!$this->hasDependencies()) {
            $this->redis->delete($this->emptyListKey);
            $this->redis->delete($this->listKey);
            $this->redis->delete($this->key);
            $this->redis->appendValuesToList($this->listKey, array(10, 299, '34'));
        }
    }

    public static function tearDownAfterClass()
    {
        Redis::clearDatabase();
        parent::tearDownAfterClass();
    }

    public function test_makeSureRedisIsInstalled_shouldNotThrowAnException()
    {
        $this->redis->checkIsInstalled();
    }

    public function test_appendValuesToList_shouldNotAddAnything_IfNoValuesAreGiven()
    {
        $this->redis->appendValuesToList($this->emptyListKey, array());

        $this->assertNumberOfItemsInList($this->emptyListKey, 0);

        $verify = $this->redis->getFirstXValuesFromList($this->emptyListKey, 1);
        $this->assertEquals(array(), $verify);
    }

    public function test_appendValuesToList_shouldAddOneValue_IfOneValueIsGiven()
    {
        $this->redis->appendValuesToList($this->emptyListKey, array(4));

        $verify = $this->redis->getFirstXValuesFromList($this->emptyListKey, 1);

        $this->assertEquals(array(4), $verify);
    }

    public function test_appendValuesToList_shouldBeAbleToAddMultipleValues()
    {
        $this->redis->appendValuesToList($this->emptyListKey, array(10, 299, '34'));
        $this->assertFirstValuesInList($this->emptyListKey, array(10, 299, '34'));
    }

    public function test_getFirstXValuesFromList_shouldReturnAnEmptyArray_IfListIsEmpty()
    {
        $this->assertFirstValuesInList($this->emptyListKey, array());
    }

    public function test_getFirstXValuesFromList_shouldReturnOnlyValuesFromTheBeginningOfTheList()
    {
        $this->assertFirstValuesInList($this->listKey, array(), 0);
        $this->assertFirstValuesInList($this->listKey, array(10), 1);
        $this->assertFirstValuesInList($this->listKey, array(10, 299), 2);
        $this->assertFirstValuesInList($this->listKey, array(10, 299, '34'), 3);
        $this->assertFirstValuesInList($this->listKey, array(10, 299, '34'));
    }

    public function test_removeFirstXValuesFromList_shouldNotReturnAnything_IfNumValueToRemoveIsZero()
    {
        $this->redis->removeFirstXValuesFromList($this->listKey, 0);
        $this->assertFirstValuesInList($this->listKey, array(10, 299, '34'));
    }

    public function test_removeFirstXValuesFromList_shouldBeAbleToRemoveOneValueFromTheBeginningOfTheList()
    {
        $this->redis->removeFirstXValuesFromList($this->listKey, 1);
        $this->assertFirstValuesInList($this->listKey, array(299, '34'));
    }

    public function test_removeFirstXValuesFromList_shouldBeAbleToRemoveMultipleValuesFromTheBeginningOfTheList()
    {
        $this->redis->removeFirstXValuesFromList($this->listKey, 2);
        $this->assertFirstValuesInList($this->listKey, array('34'));

        // remove one more
        $this->redis->removeFirstXValuesFromList($this->listKey, 1);
        $this->assertFirstValuesInList($this->listKey, array());
    }

    public function test_removeFirstXValuesFromList_ShouldNotFail_IfListIsEmpty()
    {
        $this->redis->removeFirstXValuesFromList($this->emptyListKey, 1);
        $this->assertFirstValuesInList($this->emptyListKey, array());
    }

    public function test_getNumValuesInList_shouldReturnZero_IfListIsEmpty()
    {
        $this->assertNumberOfItemsInList($this->emptyListKey, 0);
    }

    public function test_getNumValuesInList_shouldReturnNumberOfEntries_WhenListIsNotEmpty()
    {
        $this->redis->appendValuesToList($this->emptyListKey, array(12));
        $this->assertNumberOfItemsInList($this->emptyListKey, 1);

        $this->redis->appendValuesToList($this->emptyListKey, array(3, 99, '488'));
        $this->assertNumberOfItemsInList($this->emptyListKey, 4);
    }

    public function test_delete_ShouldNotWork_IfKeyDoesNotExist()
    {
        $success = $this->redis->delete('inVaLidKeyTest');
        $this->assertFalse($success);
    }

    public function test_delete_ShouldNotWork_ShouldBeAbleToDeleteAList()
    {
        $success = $this->redis->delete($this->listKey);
        $this->assertTrue($success);

        // verify
        $this->assertNumberOfItemsInList($this->listKey, 0);
    }

    public function test_delete_ShouldNotWork_ShouldBeAbleToDeleteARegularKey()
    {
        $this->redis->setIfNotExists($this->key, 'test');

        $success = $this->redis->delete($this->key);
        $this->assertTrue($success);
    }

    public function test_setIfNotExists_ShouldWork_IfNoValueIsSetYet()
    {
        $success = $this->redis->setIfNotExists($this->key, 'value');
        $this->assertTrue($success);
    }

    /**
     * @depends test_setIfNotExists_ShouldWork_IfNoValueIsSetYet
     */
    public function test_setIfNotExists_ShouldNotWork_IfValueIsAlreadySet()
    {
        $success = $this->redis->setIfNotExists($this->key, 'value');
        $this->assertFalse($success);
    }

    /**
     * @depends test_setIfNotExists_ShouldNotWork_IfValueIsAlreadySet
     */
    public function test_setIfNotExists_ShouldAlsoNotWork_IfTryingToSetDifferentValue()
    {
        $success = $this->redis->setIfNotExists($this->key, 'another val');
        $this->assertFalse($success);
    }

    /**
     * @depends test_setIfNotExists_ShouldAlsoNotWork_IfTryingToSetDifferentValue
     */
    public function test_setIfNotExists_ShouldWork_AsSoonAsKeyWasDeleted()
    {
        $this->redis->delete($this->key);
        $success = $this->redis->setIfNotExists($this->key, 'another val');
        $this->assertTrue($success);
    }

    public function test_expire_ShouldWork()
    {
        $success = $this->redis->setIfNotExists($this->key, 'test');
        $this->assertTrue($success);

        $success = $this->redis->expire($this->key, $seconds = 1);
        $this->assertTrue($success);

        // should not work as value still saved and not expired yet
        $success = $this->redis->setIfNotExists($this->key, 'test');
        $this->assertFalse($success);

        sleep($seconds + 1);

        // value is expired and should work now!
        $success = $this->redis->setIfNotExists($this->key, 'test');
        $this->assertTrue($success);
    }

    private function assertNumberOfItemsInList($key, $expectedNumber)
    {
        $numValus = $this->redis->getNumValuesInList($key);

        $this->assertSame($expectedNumber, $numValus);
    }

    private function assertFirstValuesInList($key, $expectedValues, $numValues = 999)
    {
        $verify = $this->redis->getFirstXValuesFromList($key, $numValues);

        $this->assertEquals($expectedValues, $verify);
    }

}
